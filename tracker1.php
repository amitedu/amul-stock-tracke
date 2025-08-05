<?php
/**
 * Amul Product Stock Tracker
 * Monitors stock status and sends Telegram notifications on restocking
 * Enhanced with log rotation and management
 */

class AmulStockTracker {
    private $apiUrl = 'https://shop.amul.com/api/1/entity/ms.products?fields[name]=1&fields[brand]=1&fields[categories]=1&fields[collections]=1&fields[alias]=1&fields[sku]=1&fields[price]=1&fields[compare_price]=1&fields[original_price]=1&fields[images]=1&fields[metafields]=1&fields[discounts]=1&fields[catalog_only]=1&fields[is_catalog]=1&fields[seller]=1&fields[available]=1&fields[inventory_quantity]=1&fields[net_quantity]=1&fields[num_reviews]=1&fields[avg_rating]=1&fields[inventory_low_stock_quantity]=1&fields[inventory_allow_out_of_stock]=1&fields[default_variant]=1&fields[variants]=1&fields[lp_seller_ids]=1&filters[0][field]=categories&filters[0][value][0]=protein&filters[0][operator]=in&filters[0][original]=1&facets=true&facetgroup=default_category_facet&limit=32&total=1&start=0&cdc=1m&substore=6650600024e61363e088c526';
    private $dataFile = 'stock_data.json';
    private $logFile = 'tracker.log';

    // Log management settings
    private $maxLogSizeMB = 2;        // Maximum log file size in MB
    private $maxLogFiles = 5;          // Number of rotated log files to keep
    private $logRetentionDays = 2;    // Days to keep logs

    // Telegram Bot Configuration
    private $telegramBotToken = ''; // Replace it with your bot token
    private $telegramChatId = '';     // Replace it with your chat ID

    public function __construct() {
        // Ensure data file exists
        if (!file_exists($this->dataFile)) {
            file_put_contents($this->dataFile, json_encode([]));
        }

        // Manage log files on startup
        $this->manageLogFiles();
    }

    /**
     * Main execution method
     */
    public function run() {
        $this->log("Starting stock check...");

        try {
            // Fetch current stock data
            $currentStock = $this->fetchStockData();

            if (empty($currentStock)) {
                $this->log("No stock data retrieved. Exiting.");
                return;
            }

            // Load previous stock data
            $previousStock = $this->loadPreviousStock();

            // Check for restocking
            $restockedItems = $this->checkForRestocks($currentStock, $previousStock);

            // Send notifications for restocked items
            if (!empty($restockedItems)) {
                $this->sendNotifications($restockedItems);
            }

            // Save current stock data
            $this->savePreviousStock($currentStock);

            $this->log("Stock check completed. Found " . count($restockedItems) . " restocked items." . "\n");

        } catch (Exception $e) {
            $this->log("Error: " . $e->getMessage());
        }
    }

    /**
     * Manage log files - rotate if too large, clean up old files
     */
    private function manageLogFiles() {
        // Check if the current log file is too large
        if (file_exists($this->logFile)) {
            $logSizeMB = filesize($this->logFile) / (1024 * 1024);

            if ($logSizeMB > $this->maxLogSizeMB) {
                $this->rotateLogFile();
            }
        }

        // Clean up old log files
        $this->cleanOldLogFiles();
    }

    /**
     * Rotate a log file when it gets too large
     */
    private function rotateLogFile() {
        $timestamp = date('Y-m-d_H-i-s');
        $rotatedFile = str_replace('.log', "_$timestamp.log", $this->logFile);

        // Move the current log to a rotated file
        if (rename($this->logFile, $rotatedFile)) {
            // Compress the rotated file to save space (optional)
            if (function_exists('gzencode')) {
                $content = file_get_contents($rotatedFile);
                $compressed = gzencode($content);
                file_put_contents($rotatedFile . '.gz', $compressed);
                unlink($rotatedFile); // Remove uncompressed version
            }

            echo "Log file rotated to: " . ($rotatedFile . (function_exists('gzencode') ? '.gz' : '')) . "\n";
        }
    }

    /**
     * Clean up old log files based on retention policy
     */
    private function cleanOldLogFiles() {
        $logDir = dirname($this->logFile);
        $logBaseName = pathinfo($this->logFile, PATHINFO_FILENAME);

        // Get all log files
        $logFiles = glob($logDir . '/' . $logBaseName . '_*.log*');

        // Sort by modification time (oldest first)
        usort($logFiles, function($a, $b) {
            return filemtime($a) - filemtime($b);
        });

        // Remove files older than a retention period
        $cutoffTime = time() - ($this->logRetentionDays * 24 * 60 * 60);
        foreach ($logFiles as $file) {
            if (filemtime($file) < $cutoffTime) {
                unlink($file);
                echo "Deleted old log file: " . basename($file) . "\n";
            }
        }

        // Keep only the specified number of most recent files
        if (count($logFiles) > $this->maxLogFiles) {
            $filesToDelete = array_slice($logFiles, 0, count($logFiles) - $this->maxLogFiles);
            foreach ($filesToDelete as $file) {
                if (file_exists($file)) {
                    unlink($file);
                    echo "Deleted excess log file: " . basename($file) . "\n";
                }
            }
        }
    }

    /**
     * Fetch stock data from Amul API
     */
    private function fetchStockData() {
        $this->log("Fetching data from API...");

        $context = stream_context_create([
            'http' => [
                'timeout' => 30,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
            ]
        ]);

        $response = @file_get_contents($this->apiUrl, false, $context);

        if ($response === false) {
            throw new Exception("Failed to fetch API data");
        }

        $data = json_decode($response, true);

        if (!isset($data['data']) || !is_array($data['data'])) {
            throw new Exception("Invalid API response format");
        }

        $stockData = [];

        foreach ($data['data'] as $item) {
            if (!isset($item['sku']) || !isset($item['inventory_quantity']) || !isset($item['inventory_low_stock_quantity'])) {
                continue;
            }

            $sku = $item['sku'];
            $inventoryQty = (int)$item['inventory_quantity'];
            $lowStockQty = (int)$item['inventory_low_stock_quantity'];
            $productName = $item['name'] ?? 'Unknown Product';
            $productUrl = "https://shop.amul.com/en/product/" . $item['alias'];
            $productPrice = $item['price'] ?? 'NA';

            $isInStock = $inventoryQty > $lowStockQty;

            $stockData[$sku] = [
                'name' => $productName,
                'url' => $productUrl,
                'price' => $productPrice,
                'inventory_quantity' => $inventoryQty,
                'inventory_low_stock_quantity' => $lowStockQty,
                'status' => $isInStock ? 'in_stock' : 'out_of_stock',
                'last_checked' => date('Y-m-d H:i:s')
            ];
        }

        $this->log("Fetched " . count($stockData) . " products");
        return $stockData;
    }

    /**
     * Load previous stock data
     */
    private function loadPreviousStock() {
        if (!file_exists($this->dataFile)) {
            return [];
        }

        $data = file_get_contents($this->dataFile);
        return json_decode($data, true) ?: [];
    }

    /**
     * Save current stock data
     */
    private function savePreviousStock($stockData) {
        file_put_contents($this->dataFile, json_encode($stockData, JSON_PRETTY_PRINT));
    }

    /**
     * Check for products that have been restocked
     */
    private function checkForRestocks($currentStock, $previousStock) {
        $restockedItems = [];

        foreach ($currentStock as $sku => $currentItem) {
            // Skip if product wasn't tracked before
            if (!isset($previousStock[$sku])) {
                continue;
            }

            $previousItem = $previousStock[$sku];

            // Check if status changed from out_of_stock to in_stock
            if ($previousItem['status'] === 'out_of_stock' && $currentItem['status'] === 'in_stock') {
                $restockedItems[] = [
                    'sku' => $sku,
                    'name' => $currentItem['name'],
                    'price' => $currentItem['price'],
                    'url' => $currentItem['url'],
                    'inventory_quantity' => $currentItem['inventory_quantity'],
                    'inventory_low_stock_quantity' => $currentItem['inventory_low_stock_quantity']
                ];

                $this->log("RESTOCK DETECTED: " . $currentItem['name'] . " (SKU: $sku)");
            }
        }

        return $restockedItems;
    }

    /**
     * Send Telegram notifications for restocked items
     */
    private function sendNotifications($restockedItems) {
        if (empty($this->telegramBotToken) || empty($this->telegramChatId)) {
            $this->log("Telegram credentials not configured. Skipping notifications.");
            return;
        }

        foreach ($restockedItems as $item) {
            $message = $item['name']." || Unit: ".($item['inventory_quantity'] - $item['inventory_low_stock_quantity']) . " || Price: " . $item['price'] . " || URL: ".$item['url'];

            $this->log($message);
            $this->sendTelegramMessage($message);
        }
    }

    /**
     * Send message via Telegram Bot API
     */
    public function sendTelegramMessage($message) {
        $url = "https://api.telegram.org/bot{$this->telegramBotToken}/sendMessage";

        $data = [
            'chat_id' => $this->telegramChatId,
            'text' => $message,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/x-www-form-urlencoded',
                'content' => http_build_query($data),
                'timeout' => 30
            ]
        ]);

        $result = @file_get_contents($url, false, $context);

        if ($result === false) {
            $this->log("Failed to send Telegram message");
        } else {
            $response = json_decode($result, true);
            if ($response['ok']) {
                $this->log("Telegram notification sent successfully");
            } else {
                $this->log("Telegram API error: " . ($response['description'] ?? 'Unknown error'));
            }
        }
    }

    /**
     * Log messages with timestamp and manage log rotation
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";

        echo $logMessage; // For console output
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX); // For file logging with locking

        // Check if a log file needs rotation after each writing
        if (file_exists($this->logFile)) {
            $logSizeMB = filesize($this->logFile) / (1024 * 1024);
            if ($logSizeMB > $this->maxLogSizeMB) {
                $this->rotateLogFile();
            }
        }
    }

    /**
     * Get current stock status (for testing/debugging)
     */
    public function getStockStatus() {
        $stockData = $this->loadPreviousStock();

        if (empty($stockData)) {
            echo "No stock data available. Run the tracker first.\n";
            return;
        }

        echo "\n=== CURRENT STOCK STATUS ===\n";
        foreach ($stockData as $sku => $item) {
            $status = $item['status'] === 'in_stock' ? '✅ IN STOCK' : '❌ OUT OF STOCK';
            echo sprintf("%-50s | %s | Qty: %d/%d\n",
                substr($item['name'], 0, 47) . (strlen($item['name']) > 47 ? '...' : ''),
                $status,
                $item['inventory_quantity'],
                $item['inventory_low_stock_quantity']
            );
        }
        echo "\nLast checked: " . ($stockData[array_keys($stockData)[0]]['last_checked'] ?? 'Never') . "\n";
    }

    /**
     * Manually clean logs (useful for maintenance)
     */
    public function cleanLogs() {
        $this->cleanOldLogFiles();
        echo "Log cleanup completed.\n";
    }

    /**
     * Get log management statistics
     */
    public function getLogStats() {
        $logDir = dirname($this->logFile);
        $logBaseName = pathinfo($this->logFile, PATHINFO_FILENAME);
        $logFiles = glob($logDir . '/' . $logBaseName . '_*.log*');

        echo "\n=== LOG FILE STATISTICS ===\n";
        echo "Current log file: " . $this->logFile . "\n";

        if (file_exists($this->logFile)) {
            $currentSize = filesize($this->logFile) / (1024 * 1024);
            echo "Current log size: " . round($currentSize, 2) . " MB\n";
        }

        echo "Rotated log files: " . count($logFiles) . "\n";
        echo "Max log size: " . $this->maxLogSizeMB . " MB\n";
        echo "Max log files: " . $this->maxLogFiles . "\n";
        echo "Retention days: " . $this->logRetentionDays . "\n";

        $totalSize = 0;
        foreach ($logFiles as $file) {
            $totalSize += filesize($file);
        }
        if (file_exists($this->logFile)) {
            $totalSize += filesize($this->logFile);
        }

        echo "Total log storage: " . round($totalSize / (1024 * 1024), 2) . " MB\n";
    }
}

// Command line interface
$tracker = new AmulStockTracker();
if ((PHP_SAPI === 'cli') && isset($argv[1])) {
    switch ($argv[1]) {
        case 'status':
            $tracker->getStockStatus();
            break;
        case 'clean-logs':
            $tracker->cleanLogs();
            break;
        case 'log-stats':
            $tracker->getLogStats();
            break;
        default:
            $tracker->run();
    }
} else {
    // Web interface
    $tracker->run();
}

