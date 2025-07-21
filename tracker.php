<?php
/**
 * Amul Product Stock Tracker - GitHub Actions Version
 * Monitors stock status and sends Telegram notifications on restocking
 */

class AmulStockTracker {
    private $apiUrl = 'https://shop.amul.com/api/1/entity/ms.products?fields[name]=1&fields[brand]=1&fields[categories]=1&fields[collections]=1&fields[alias]=1&fields[sku]=1&fields[price]=1&fields[compare_price]=1&fields[original_price]=1&fields[images]=1&fields[metafields]=1&fields[discounts]=1&fields[catalog_only]=1&fields[is_catalog]=1&fields[seller]=1&fields[available]=1&fields[inventory_quantity]=1&fields[net_quantity]=1&fields[num_reviews]=1&fields[avg_rating]=1&fields[inventory_low_stock_quantity]=1&fields[inventory_allow_out_of_stock]=1&fields[default_variant]=1&fields[variants]=1&fields[lp_seller_ids]=1&filters[0][field]=categories&filters[0][value][0]=protein&filters[0][operator]=in&filters[0][original]=1&facets=true&facetgroup=default_category_facet&limit=32&total=1&start=0&cdc=1m&substore=6650600024e61363e088c526';
    private $dataFile = 'stock_data.json';
    private $logFile = 'tracker.log';

    // Telegram Bot Configuration - Using environment variables
    private $telegramBotToken;
    private $telegramChatId;

    public function __construct() {
        // Get credentials from environment variables (GitHub Secrets)
        $this->telegramBotToken = getenv('TELEGRAM_BOT_TOKEN') ?: '';
        $this->telegramChatId = getenv('TELEGRAM_CHAT_ID') ?: '';

        // Ensure data file exists
        if (!file_exists($this->dataFile)) {
            file_put_contents($this->dataFile, json_encode([]));
        } else {
            $this->log($this->dataFile . 'file does not exist');
        }

        // GitHub Actions doesn't need complex log rotation. 
        // We'll keep it simple since artifacts are managed automatically
    }

    /**
     * Main execution method
     */
    public function run() {
        $this->log("Starting stock check...");
        $this->log("Running in GitHub Actions environment");

        // Verify Telegram setup
        $this->verifyTelegramSetup();

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

            // Test notification
            if (!empty($this->telegramBotToken) && !empty($this->telegramChatId)) {
                $this->sendTelegramMessage("Test notification from stock tracker at " . date('Y-m-d H:i:s'));
            }

        } catch (Exception $e) {
            $this->log("Error: " . $e->getMessage());
            // In GitHub Actions, exit with error code to mark workflow as failed
            exit(1);
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
                'user_agent' => 'Mozilla/5.0 (Linux; GitHub Actions) Stock Tracker/1.0'
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
        $this->log('Starting checkForRestocks...');
        $this->log('Current stock items: ' . count($currentStock));
        $this->log('Previous stock items: ' . count($previousStock));

        $restockedItems = [];
        $checkedItems = 0;
        $skippedItems = 0;

        foreach ($currentStock as $sku => $currentItem) {
            // Skip if product wasn't tracked before
            if (!isset($previousStock[$sku])) {
                $skippedItems++;
                $this->log("SKIPPED (new product): " . $currentItem['name'] . " (SKU: $sku)");
                continue;
            }

            $checkedItems++;
            $previousItem = $previousStock[$sku];

            $this->log("CHECKING: " . $currentItem['name'] . " | Previous: " . $previousItem['status'] . " | Current: " . $currentItem['status']);

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
                $this->log("🎉 RESTOCK DETECTED: " . $currentItem['name'] . " (SKU: $sku)");
            }
        }

        $this->log("Restock check summary - Checked: $checkedItems, Skipped: $skippedItems, Restocked: " . count($restockedItems));
        return $restockedItems;
    }

    private function verifyTelegramSetup() {
        $this->log("Verifying Telegram setup...");

        if (empty($this->telegramBotToken)) {
            $this->log("ERROR: TELEGRAM_BOT_TOKEN environment variable is empty or not set");
            return false;
        }

        if (empty($this->telegramChatId)) {
            $this->log("ERROR: TELEGRAM_CHAT_ID environment variable is empty or not set");
            return false;
        }

        $this->log("Telegram Bot Token: " . substr($this->telegramBotToken, 0, 10) . "...");
        $this->log("Telegram Chat ID: " . $this->telegramChatId);
        return true;
    }


    /**
     * Send Telegram notifications for restocked items
     */
    private function sendNotifications($restockedItems) {
        $this->log("Attempting to send notifications for " . count($restockedItems) . " items...");

        if (empty($this->telegramBotToken) || empty($this->telegramChatId)) {
            $this->log("Telegram credentials not configured. Skipping notifications.");
            return;
        }

        foreach ($restockedItems as $item) {
            $message = "🔔 RESTOCK ALERT 🔔\n\n";
            $message .= $item['name'] . "\n";
            $message .= "Units Available: " . ($item['inventory_quantity'] - $item['inventory_low_stock_quantity']) . "\n";
            $message .= "Price: " . $item['price'] . "\n";
            $message .= "URL: " . $item['url'] . "\n";
            $message .= "\n⏰ " . date('Y-m-d H:i:s T');

            $this->log("Sending notification: " . $item['name']);
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
            'parse_mode' => 'HTML',
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
     * Log messages with timestamp
     */
    private function log($message) {
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] $message\n";

        echo $logMessage; // For GitHub Actions console output
        file_put_contents($this->logFile, $logMessage, FILE_APPEND | LOCK_EX);
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
                substr($item['name'], 0, 47).(strlen($item['name']) > 47 ? '...' : ''),
                $status,
                $item['inventory_quantity'],
                $item['inventory_low_stock_quantity']
            );
        }
        echo "\nLast checked: " . ($stockData[array_keys($stockData)[0]]['last_checked'] ?? 'Never') . "\n";
    }
}

// For GitHub Actions, we always run the tracker
$tracker = new AmulStockTracker();

if (isset($argv[1]) && $argv[1] === 'status') {
    $tracker->getStockStatus();
} else {
    $tracker->run();
}

?>