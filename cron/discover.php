<?php
/**
 * Filename: discover.php
 * Description: Discovery cron job - processes pending discovery_queue items via Google Places API
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

// ─── Ensure CLI execution only ──────────────────────────────────────────────
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from the command line.\n";
    exit(1);
}

// ─── Dependencies ───────────────────────────────────────────────────────────
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/rate-limiter.php';
require_once __DIR__ . '/../lib/cost-tracker.php';
require_once __DIR__ . '/../sources/google-places.php';

// ─── Constants ──────────────────────────────────────────────────────────────
define('CRON_NAME', 'discover');
define('CRON_SOURCE', 'cron:discover');

// ─── Track start time for duration calculation ──────────────────────────────
$startTime = microtime(true);

/**
 * Output a timestamped status message to stdout.
 *
 * @param string $message The message to output
 * @return void
 */
function output(string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] [discover] {$message}\n";
}

/**
 * Release the cron lock in the cron_schedule table.
 *
 * @return void
 */
function releaseCronLock(): void
{
    try {
        Database::update(
            'cron_schedule',
            ['is_running' => 0],
            '`job_name` = ?',
            [CRON_NAME]
        );
    } catch (\Throwable $e) {
        // Best effort — nothing more we can do here
    }
}

/**
 * Update the cron_schedule table with run statistics.
 *
 * @param string $status      The status of this run (e.g. "success", "failed", "budget_exceeded")
 * @param float  $startTime   The microtime(true) when the run started
 * @return void
 */
function updateCronSchedule(string $status, float $startTime): void
{
    $durationSec = round(microtime(true) - $startTime, 2);

    try {
        // Check if a cron_schedule row exists
        $row = Database::fetch(
            "SELECT `id`, `run_count` FROM `cron_schedule` WHERE `job_name` = ?",
            [CRON_NAME]
        );

        if ($row) {
            Database::update(
                'cron_schedule',
                [
                    'last_run'          => date('Y-m-d H:i:s'),
                    'last_duration_sec' => $durationSec,
                    'last_status'       => $status,
                    'run_count'         => (int) $row['run_count'] + 1,
                    'is_running'        => 0,
                ],
                '`job_name` = ?',
                [CRON_NAME]
            );
        } else {
            Database::insert('cron_schedule', [
                'job_name'          => CRON_NAME,
                'last_run'          => date('Y-m-d H:i:s'),
                'last_duration_sec' => $durationSec,
                'last_status'       => $status,
                'run_count'         => 1,
                'is_running'        => 0,
            ]);
        }
    } catch (\Throwable $e) {
        Logger::error(CRON_SOURCE, 'Failed to update cron_schedule: ' . $e->getMessage());
    }
}

// ─── Register shutdown function to always release the lock ──────────────────
register_shutdown_function(function () use ($startTime) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $errorMsg = "{$error['message']} in {$error['file']}:{$error['line']}";
        output("FATAL ERROR: {$errorMsg}");
        Logger::error(CRON_SOURCE, 'Fatal error during discovery run: ' . $errorMsg);
        updateCronSchedule('fatal_error', $startTime);
    }
    releaseCronLock();
});

// ─── Prevent concurrent runs ────────────────────────────────────────────────
try {
    $schedule = Database::fetch(
        "SELECT `is_running` FROM `cron_schedule` WHERE `job_name` = ?",
        [CRON_NAME]
    );

    if ($schedule && (int) $schedule['is_running'] === 1) {
        output("Another instance is already running. Exiting.");
        exit(0);
    }

    // Set the running flag
    if ($schedule) {
        Database::update(
            'cron_schedule',
            ['is_running' => 1],
            '`job_name` = ?',
            [CRON_NAME]
        );
    } else {
        Database::insert('cron_schedule', [
            'job_name'   => CRON_NAME,
            'is_running' => 1,
        ]);
    }
} catch (\Throwable $e) {
    output("ERROR: Failed to check/set cron lock: " . $e->getMessage());
    Logger::error(CRON_SOURCE, 'Failed to check/set cron lock: ' . $e->getMessage());
    exit(1);
}

output("Discovery cron started.");

// ─── Check daily API budget ─────────────────────────────────────────────────
try {
    if (!CostTracker::isWithinBudget()) {
        $todayCost = CostTracker::getTodayCost();
        $budget    = CostTracker::getDailyBudget();
        output("Daily API budget exceeded (\${$todayCost}/\${$budget}). Exiting.");
        Logger::info(CRON_SOURCE, "Budget exceeded: \${$todayCost} of \${$budget} daily limit");
        updateCronSchedule('budget_exceeded', $startTime);
        exit(0);
    }
} catch (\Throwable $e) {
    output("ERROR: Failed to check budget: " . $e->getMessage());
    Logger::error(CRON_SOURCE, 'Failed to check budget: ' . $e->getMessage());
    updateCronSchedule('failed', $startTime);
    exit(1);
}

// ─── Pick the next pending discovery_queue item ─────────────────────────────
// Order by country tier ASC (highest priority first), then by id ASC
try {
    $queueItem = Database::fetch(
        "SELECT dq.*, c.`tier` AS country_tier
         FROM `discovery_queue` dq
         JOIN `countries` c ON c.`id` = dq.`country_id`
         WHERE dq.`status` = 'pending'
         ORDER BY c.`tier` ASC, dq.`id` ASC
         LIMIT 1"
    );

    if (!$queueItem) {
        output("No pending discovery queue items found. Nothing to do.");
        Logger::info(CRON_SOURCE, 'No pending discovery queue items');
        updateCronSchedule('idle', $startTime);
        exit(0);
    }
} catch (\Throwable $e) {
    output("ERROR: Failed to fetch discovery queue item: " . $e->getMessage());
    Logger::error(CRON_SOURCE, 'Failed to fetch discovery queue: ' . $e->getMessage());
    updateCronSchedule('failed', $startTime);
    exit(1);
}

$queueId    = (int) $queueItem['id'];
$countryId  = (int) $queueItem['country_id'];
$searchTerm = $queueItem['search_term'];
$nextPageToken = $queueItem['next_page_token'] ?? null;

output("Processing queue item #{$queueId}: country_id={$countryId}, term=\"{$searchTerm}\"" .
       ($nextPageToken ? " (pagination continuation)" : ""));

// ─── Mark the queue item as in_progress ─────────────────────────────────────
try {
    Database::update(
        'discovery_queue',
        [
            'status'     => 'in_progress',
            'started_at' => date('Y-m-d H:i:s'),
        ],
        '`id` = ?',
        [$queueId]
    );
} catch (\Throwable $e) {
    output("ERROR: Failed to mark queue item as in_progress: " . $e->getMessage());
    Logger::error(CRON_SOURCE, 'Failed to update queue status: ' . $e->getMessage());
    updateCronSchedule('failed', $startTime);
    exit(1);
}

// ─── Look up the country name ───────────────────────────────────────────────
try {
    $country = Database::fetch(
        "SELECT `name` FROM `countries` WHERE `id` = ?",
        [$countryId]
    );

    if (!$country) {
        throw new \RuntimeException("Country not found for id={$countryId}");
    }

    $countryName = $country['name'];
    output("Country: {$countryName} (tier {$queueItem['country_tier']})");
} catch (\Throwable $e) {
    output("ERROR: Failed to look up country: " . $e->getMessage());
    Logger::error(CRON_SOURCE, 'Country lookup failed: ' . $e->getMessage());

    Database::update(
        'discovery_queue',
        [
            'status'        => 'failed',
            'error_message' => 'Country lookup failed: ' . $e->getMessage(),
            'attempts'      => (int) ($queueItem['attempts'] ?? 0) + 1,
            'completed_at'  => date('Y-m-d H:i:s'),
        ],
        '`id` = ?',
        [$queueId]
    );

    updateCronSchedule('failed', $startTime);
    exit(1);
}

// ─── Run discovery via Google Places source ─────────────────────────────────
try {
    $source = new GooglePlacesSource();

    output("Running discoverAndSave(\"{$countryName}\", {$countryId}, \"{$searchTerm}\")...");

    $result = $source->discoverAndSave($countryName, $countryId, $searchTerm);

    // Extract results from the source response
    $resultsCount = $result['results_count'] ?? 0;
    $apiCost      = $result['api_cost'] ?? 0.0;
    $newPageToken = $result['next_page_token'] ?? null;

    output("Discovery complete: {$resultsCount} results found, API cost: \${$apiCost}");

    // ─── Mark queue item as complete ────────────────────────────────────
    Database::update(
        'discovery_queue',
        [
            'status'        => 'complete',
            'results_count' => $resultsCount,
            'api_cost'      => $apiCost,
            'completed_at'  => date('Y-m-d H:i:s'),
        ],
        '`id` = ?',
        [$queueId]
    );

    Logger::info(CRON_SOURCE, "Discovery complete for queue #{$queueId}", [
        'metadata' => [
            'country'       => $countryName,
            'search_term'   => $searchTerm,
            'results_count' => $resultsCount,
            'api_cost'      => $apiCost,
        ],
        'cost_estimate' => $apiCost,
    ]);

    // ─── Handle pagination: create follow-up queue item ─────────────────
    if (!empty($newPageToken)) {
        output("Pagination token received. Creating follow-up queue item...");

        try {
            $followUpId = Database::insert('discovery_queue', [
                'country_id'      => $countryId,
                'search_term'     => $searchTerm,
                'next_page_token' => $newPageToken,
                'status'          => 'pending',
                'parent_id'       => $queueId,
                'created_at'      => date('Y-m-d H:i:s'),
            ]);

            output("Follow-up queue item created: #{$followUpId}");
            Logger::info(CRON_SOURCE, "Pagination follow-up created: #{$followUpId} for parent #{$queueId}");
        } catch (\Throwable $e) {
            output("WARNING: Failed to create follow-up queue item: " . $e->getMessage());
            Logger::error(CRON_SOURCE, 'Failed to create pagination follow-up: ' . $e->getMessage(), [
                'metadata' => [
                    'parent_queue_id' => $queueId,
                    'next_page_token' => $newPageToken,
                ],
            ]);
        }
    }

    // ─── Update cron schedule with success ──────────────────────────────
    updateCronSchedule('success', $startTime);
    output("Discovery cron completed successfully.");

} catch (\Throwable $e) {
    // ─── Handle discovery failure ───────────────────────────────────────
    $errorMessage = $e->getMessage();
    $attempts     = (int) ($queueItem['attempts'] ?? 0) + 1;

    output("ERROR: Discovery failed: {$errorMessage}");

    try {
        Database::update(
            'discovery_queue',
            [
                'status'        => 'failed',
                'error_message' => $errorMessage,
                'attempts'      => $attempts,
                'completed_at'  => date('Y-m-d H:i:s'),
            ],
            '`id` = ?',
            [$queueId]
        );
    } catch (\Throwable $updateError) {
        output("ERROR: Also failed to update queue item status: " . $updateError->getMessage());
    }

    Logger::error(CRON_SOURCE, "Discovery failed for queue #{$queueId}: {$errorMessage}", [
        'metadata' => [
            'country'     => $countryName ?? 'unknown',
            'search_term' => $searchTerm,
            'attempts'    => $attempts,
        ],
    ]);

    updateCronSchedule('failed', $startTime);
    exit(1);
}
