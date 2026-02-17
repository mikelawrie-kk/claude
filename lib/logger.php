<?php
/**
 * Filename: logger.php
 * Description: Logging to scrape_log table with file-based fallback
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

require_once __DIR__ . '/db.php';

class Logger
{
    /**
     * @var string Path to the fallback log file
     */
    private const FALLBACK_LOG_PATH = '/home/user/claude/cache/safari-traveller.log';

    /**
     * Log an entry to the scrape_log table.
     *
     * @param string $source The source identifier (e.g. scraper name, module)
     * @param string $action The action being logged (e.g. "fetch", "parse", "error")
     * @param array  $data   Optional data array which may contain:
     *                       - url (string)
     *                       - status_code (int)
     *                       - response_size (int)
     *                       - cost_estimate (float)
     *                       - duration_ms (int)
     *                       - error_message (string)
     *                       - metadata (array — will be JSON-encoded)
     * @return void
     */
    public static function log(string $source, string $action, array $data = []): void
    {
        // Prepare the record for the scrape_log table
        $record = [
            'source'    => $source,
            'action'    => $action,
            'logged_at' => date('Y-m-d H:i:s'),
        ];

        // Map optional data fields
        if (isset($data['url'])) {
            $record['url'] = $data['url'];
        }
        if (isset($data['status_code'])) {
            $record['status_code'] = (int) $data['status_code'];
        }
        if (isset($data['response_size'])) {
            $record['response_size'] = (int) $data['response_size'];
        }
        if (isset($data['cost_estimate'])) {
            $record['cost_estimate'] = (float) $data['cost_estimate'];
        }
        if (isset($data['duration_ms'])) {
            $record['duration_ms'] = (int) $data['duration_ms'];
        }
        if (isset($data['error_message'])) {
            $record['error_message'] = $data['error_message'];
        }
        if (isset($data['metadata'])) {
            $record['metadata'] = is_string($data['metadata'])
                ? $data['metadata']
                : json_encode($data['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        try {
            Database::insert('scrape_log', $record);
        } catch (\Throwable $e) {
            // DB logging failed — fall back to file
            self::writeToFile($source, $action, $data, $e->getMessage());
        }
    }

    /**
     * Convenience method for logging an error.
     *
     * @param string $source  The source identifier
     * @param string $message The error message
     * @param array  $data    Optional additional data
     * @return void
     */
    public static function error(string $source, string $message, array $data = []): void
    {
        $data['error_message'] = $message;
        self::log($source, 'error', $data);
    }

    /**
     * Convenience method for logging informational messages.
     *
     * @param string $source  The source identifier
     * @param string $message The info message
     * @param array  $data    Optional additional data
     * @return void
     */
    public static function info(string $source, string $message, array $data = []): void
    {
        if (!isset($data['metadata'])) {
            $data['metadata'] = ['message' => $message];
        } else {
            if (is_array($data['metadata'])) {
                $data['metadata']['message'] = $message;
            }
        }
        self::log($source, 'info', $data);
    }

    /**
     * Retrieve recent log entries from the scrape_log table.
     *
     * @param int         $limit  Maximum number of entries to return
     * @param string|null $source Optional filter by source
     * @return array              Array of log entry rows
     */
    public static function getRecentLogs(int $limit = 50, ?string $source = null): array
    {
        $sql    = "SELECT * FROM `scrape_log`";
        $params = [];

        if ($source !== null) {
            $sql     .= " WHERE `source` = ?";
            $params[] = $source;
        }

        $sql .= " ORDER BY `id` DESC LIMIT ?";
        $params[] = $limit;

        try {
            return Database::fetchAll($sql, $params);
        } catch (\Throwable $e) {
            // If DB read fails, return empty array
            return [];
        }
    }

    /**
     * Write a log entry to the fallback file when DB logging fails.
     *
     * @param string $source   The source identifier
     * @param string $action   The action being logged
     * @param array  $data     The data that was being logged
     * @param string $dbError  The database error message
     * @return void
     */
    private static function writeToFile(string $source, string $action, array $data, string $dbError): void
    {
        $timestamp = date('Y-m-d H:i:s');
        $dataJson  = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $logLine = "[{$timestamp}] [{$source}] [{$action}] {$dataJson} | DB_ERROR: {$dbError}" . PHP_EOL;

        $dir = dirname(self::FALLBACK_LOG_PATH);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents(self::FALLBACK_LOG_PATH, $logLine, FILE_APPEND | LOCK_EX);
    }
}
