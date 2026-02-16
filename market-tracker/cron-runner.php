<?php
/**
 * Filename: cron-runner.php
 * Description: cPanel cron entry point. Schedule this to run daily at 02:00 SAST
 * Version: 1.0.0
 * Created: 2026-02-16 09:45:00 SAST
 * Modified: 2026-02-16 09:45:00 SAST
 *
 * Cron command for cPanel:
 * 0 2 * * * /usr/bin/php /home/username/public_html/market-tracker/cron-runner.php >> /home/username/logs/market-tracker-cron.log 2>&1
 */

// Set timezone
date_default_timezone_set('Africa/Johannesburg');

// Log start
echo "[" . date('Y-m-d H:i:s') . "] Market Tracker Cron Started\n";

// Check if config exists
if (!file_exists(__DIR__ . '/config.php')) {
    echo "[ERROR] config.php not found. Please run install.php first.\n";
    exit(1);
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api/fetch-prices.php';

try {
    $fetcher = new PriceFetcher();
    $result = $fetcher->run();

    if ($result['success']) {
        echo "[SUCCESS] Collected {$result['snapshots']} snapshots\n";
        echo "[INFO] API Calls: {$result['api_calls']}\n";
        echo "[INFO] Estimated Cost: $" . number_format($result['cost'], 4) . "\n";

        if (!empty($result['errors'])) {
            echo "[WARNING] " . count($result['errors']) . " errors occurred:\n";
            foreach ($result['errors'] as $error) {
                echo "  - {$error}\n";
            }
        }
    } else {
        echo "[ERROR] Cron run failed: {$result['error']}\n";
        exit(1);
    }

} catch (Exception $e) {
    echo "[ERROR] Exception: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

echo "[" . date('Y-m-d H:i:s') . "] Market Tracker Cron Completed\n";
exit(0);
