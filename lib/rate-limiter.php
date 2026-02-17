<?php
/**
 * Filename: rate-limiter.php
 * Description: Per-domain rate limiting using the rate_limits table
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

require_once __DIR__ . '/db.php';

class RateLimiter
{
    /**
     * @var int Default minimum delay between requests to the same domain (milliseconds)
     */
    private const DEFAULT_DELAY_MS = 2000;

    /**
     * Check whether enough time has passed since the last request to this domain.
     *
     * @param string $domain The domain to check (e.g. "example.com")
     * @return bool          True if a request can be made now, false otherwise
     */
    public static function canRequest(string $domain): bool
    {
        $delayMs = self::getDelay($domain);

        try {
            $row = Database::fetch(
                "SELECT `last_request_at` FROM `rate_limits` WHERE `domain` = ?",
                [$domain]
            );
        } catch (\Throwable $e) {
            // If we can't check the DB, allow the request but be cautious
            return true;
        }

        if (!$row || empty($row['last_request_at'])) {
            // No previous request recorded — safe to proceed
            return true;
        }

        $lastRequestTime = strtotime($row['last_request_at']);
        $nowMs           = (int) (microtime(true) * 1000);
        $lastMs          = $lastRequestTime * 1000;
        $elapsed         = $nowMs - $lastMs;

        return $elapsed >= $delayMs;
    }

    /**
     * Record that a request was made to a domain.
     * Inserts or updates the rate_limits table.
     *
     * @param string $domain The domain that was requested
     * @return void
     */
    public static function recordRequest(string $domain): void
    {
        $now = date('Y-m-d H:i:s');

        try {
            $existing = Database::fetch(
                "SELECT `id` FROM `rate_limits` WHERE `domain` = ?",
                [$domain]
            );

            if ($existing) {
                Database::update(
                    'rate_limits',
                    ['last_request_at' => $now],
                    '`domain` = ?',
                    [$domain]
                );
            } else {
                Database::insert('rate_limits', [
                    'domain'          => $domain,
                    'delay_ms'        => self::DEFAULT_DELAY_MS,
                    'last_request_at' => $now,
                ]);
            }
        } catch (\Throwable $e) {
            // Silently fail — rate limiting is best-effort
        }
    }

    /**
     * Blocking wait until the domain is available, then record the request.
     * This method will sleep in small intervals until the required delay has elapsed.
     *
     * @param string $domain The domain to wait for
     * @return void
     */
    public static function waitForDomain(string $domain): void
    {
        $delayMs = self::getDelay($domain);

        try {
            $row = Database::fetch(
                "SELECT `last_request_at` FROM `rate_limits` WHERE `domain` = ?",
                [$domain]
            );
        } catch (\Throwable $e) {
            $row = null;
        }

        if ($row && !empty($row['last_request_at'])) {
            $lastRequestTime = strtotime($row['last_request_at']);
            $nowMs           = (int) (microtime(true) * 1000);
            $lastMs          = $lastRequestTime * 1000;
            $elapsed         = $nowMs - $lastMs;
            $remaining       = $delayMs - $elapsed;

            if ($remaining > 0) {
                usleep($remaining * 1000); // usleep takes microseconds
            }
        }

        self::recordRequest($domain);
    }

    /**
     * Get the configured delay for a domain.
     * Returns the domain-specific delay from the rate_limits table, or the default.
     *
     * @param string $domain The domain to look up
     * @return int           Delay in milliseconds
     */
    public static function getDelay(string $domain): int
    {
        try {
            $row = Database::fetch(
                "SELECT `delay_ms` FROM `rate_limits` WHERE `domain` = ?",
                [$domain]
            );

            if ($row && isset($row['delay_ms'])) {
                return (int) $row['delay_ms'];
            }
        } catch (\Throwable $e) {
            // Fall through to default
        }

        return self::DEFAULT_DELAY_MS;
    }

    /**
     * Set a custom delay for a specific domain.
     *
     * @param string $domain  The domain to configure
     * @param int    $delayMs Delay in milliseconds
     * @return void
     */
    public static function setDelay(string $domain, int $delayMs): void
    {
        try {
            $existing = Database::fetch(
                "SELECT `id` FROM `rate_limits` WHERE `domain` = ?",
                [$domain]
            );

            if ($existing) {
                Database::update(
                    'rate_limits',
                    ['delay_ms' => $delayMs],
                    '`domain` = ?',
                    [$domain]
                );
            } else {
                Database::insert('rate_limits', [
                    'domain'   => $domain,
                    'delay_ms' => $delayMs,
                ]);
            }
        } catch (\Throwable $e) {
            // Silently fail
        }
    }
}
