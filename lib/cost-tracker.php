<?php
/**
 * Filename: cost-tracker.php
 * Description: Tracks API call costs against daily budgets
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

require_once __DIR__ . '/db.php';

class CostTracker
{
    /**
     * Record an API call cost.
     *
     * @param string      $apiName   Name of the API (e.g. "openai", "google_maps")
     * @param string      $endpoint  The specific endpoint called
     * @param float       $cost      Cost of the call in dollars (or your chosen currency unit)
     * @param int|null    $countryId Optional country ID this call relates to
     * @return string                The inserted record ID
     */
    public static function trackCost(string $apiName, string $endpoint, float $cost, ?int $countryId = null): string
    {
        $data = [
            'api_name'   => $apiName,
            'endpoint'   => $endpoint,
            'cost'       => $cost,
            'called_at'  => date('Y-m-d H:i:s'),
        ];

        if ($countryId !== null) {
            $data['country_id'] = $countryId;
        }

        return Database::insert('api_costs', $data);
    }

    /**
     * Get the total cost for today, optionally filtered by API name.
     *
     * @param string|null $apiName Optional API name filter
     * @return float               Total cost for today
     */
    public static function getTodayCost(?string $apiName = null): float
    {
        $sql    = "SELECT COALESCE(SUM(`cost`), 0) AS total FROM `api_costs` WHERE DATE(`called_at`) = CURDATE()";
        $params = [];

        if ($apiName !== null) {
            $sql     .= " AND `api_name` = ?";
            $params[] = $apiName;
        }

        $row = Database::fetch($sql, $params);

        return (float) ($row['total'] ?? 0.0);
    }

    /**
     * Get the total cost for the current week (Monday to Sunday), optionally filtered by API name.
     *
     * @param string|null $apiName Optional API name filter
     * @return float               Total cost for this week
     */
    public static function getWeekCost(?string $apiName = null): float
    {
        $sql    = "SELECT COALESCE(SUM(`cost`), 0) AS total FROM `api_costs`
                   WHERE YEARWEEK(`called_at`, 1) = YEARWEEK(CURDATE(), 1)";
        $params = [];

        if ($apiName !== null) {
            $sql     .= " AND `api_name` = ?";
            $params[] = $apiName;
        }

        $row = Database::fetch($sql, $params);

        return (float) ($row['total'] ?? 0.0);
    }

    /**
     * Get the total cost for the current month, optionally filtered by API name.
     *
     * @param string|null $apiName Optional API name filter
     * @return float               Total cost for this month
     */
    public static function getMonthCost(?string $apiName = null): float
    {
        $sql    = "SELECT COALESCE(SUM(`cost`), 0) AS total FROM `api_costs`
                   WHERE YEAR(`called_at`) = YEAR(CURDATE()) AND MONTH(`called_at`) = MONTH(CURDATE())";
        $params = [];

        if ($apiName !== null) {
            $sql     .= " AND `api_name` = ?";
            $params[] = $apiName;
        }

        $row = Database::fetch($sql, $params);

        return (float) ($row['total'] ?? 0.0);
    }

    /**
     * Get the total cost for a specific country.
     *
     * @param int $countryId The country ID to look up
     * @return float         Total cost for that country
     */
    public static function getCostByCountry(int $countryId): float
    {
        $row = Database::fetch(
            "SELECT COALESCE(SUM(`cost`), 0) AS total FROM `api_costs` WHERE `country_id` = ?",
            [$countryId]
        );

        return (float) ($row['total'] ?? 0.0);
    }

    /**
     * Check if today's spending is within the configured daily budget.
     *
     * @return bool True if within budget, false if budget exceeded
     */
    public static function isWithinBudget(): bool
    {
        $budget   = self::getDailyBudget();
        $todayCost = self::getTodayCost();

        return $todayCost < $budget;
    }

    /**
     * Read the daily_api_budget value from the settings table.
     *
     * @return float The daily budget amount (defaults to 0.0 if not found)
     */
    public static function getDailyBudget(): float
    {
        try {
            $row = Database::fetch(
                "SELECT `value` FROM `settings` WHERE `key` = ?",
                ['daily_api_budget']
            );

            if ($row && isset($row['value'])) {
                return (float) $row['value'];
            }
        } catch (\Throwable $e) {
            // If settings table is unavailable, return 0
        }

        return 0.0;
    }

    /**
     * Get the remaining budget for today.
     *
     * @return float Remaining budget (can be negative if over budget)
     */
    public static function getBudgetRemaining(): float
    {
        $budget    = self::getDailyBudget();
        $todayCost = self::getTodayCost();

        return $budget - $todayCost;
    }
}
