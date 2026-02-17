<?php
/**
 * Filename: enrich.php
 * Description: Enrichment cron job - scrapes websites, detects schema/CMS, runs entity audits
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
require_once __DIR__ . '/../lib/website-scraper.php';
require_once __DIR__ . '/../lib/schema-detector.php';
require_once __DIR__ . '/../lib/platform-detector.php';
require_once __DIR__ . '/../lib/entity-auditor.php';

// ─── Constants ──────────────────────────────────────────────────────────────
define('CRON_NAME', 'enrich');
define('CRON_SOURCE', 'cron:enrich');
define('BATCH_LIMIT', 10);
define('MIN_REQUEST_DELAY_SEC', 3);

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
    echo "[{$timestamp}] [enrich] {$message}\n";
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
 * @param string $status    The status of this run
 * @param float  $startTime The microtime(true) when the run started
 * @return void
 */
function updateCronSchedule(string $status, float $startTime): void
{
    $durationSec = round(microtime(true) - $startTime, 2);

    try {
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

/**
 * Extract the domain from a URL for rate limiting purposes.
 *
 * @param string $url The URL to parse
 * @return string     The domain (e.g. "example.com")
 */
function extractDomain(string $url): string
{
    $parsed = parse_url($url);
    return $parsed['host'] ?? 'unknown';
}

// ─── Register shutdown function to always release the lock ──────────────────
register_shutdown_function(function () use ($startTime) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $errorMsg = "{$error['message']} in {$error['file']}:{$error['line']}";
        output("FATAL ERROR: {$errorMsg}");
        Logger::error(CRON_SOURCE, 'Fatal error during enrichment run: ' . $errorMsg);
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

output("Enrichment cron started.");

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

// ─── Find properties needing enrichment ─────────────────────────────────────
// Properties with a website URL, status 'discovered' or 'scraped', and no
// existing property_enrichment record
try {
    $properties = Database::fetchAll(
        "SELECT p.`id`, p.`name`, p.`website`, p.`status`, p.`country_id`
         FROM `properties` p
         LEFT JOIN `property_enrichment` pe ON pe.`property_id` = p.`id`
         WHERE p.`website` IS NOT NULL
           AND p.`website` != ''
           AND p.`status` IN ('discovered', 'scraped')
           AND pe.`id` IS NULL
         ORDER BY p.`id` ASC
         LIMIT ?",
        [BATCH_LIMIT]
    );

    if (empty($properties)) {
        output("No properties found needing enrichment. Nothing to do.");
        Logger::info(CRON_SOURCE, 'No properties needing enrichment');
        updateCronSchedule('idle', $startTime);
        exit(0);
    }

    $totalProperties = count($properties);
    output("Found {$totalProperties} properties to enrich.");

} catch (\Throwable $e) {
    output("ERROR: Failed to fetch properties for enrichment: " . $e->getMessage());
    Logger::error(CRON_SOURCE, 'Failed to fetch properties: ' . $e->getMessage());
    updateCronSchedule('failed', $startTime);
    exit(1);
}

// ─── Process each property ──────────────────────────────────────────────────
$successCount = 0;
$failCount    = 0;

foreach ($properties as $index => $property) {
    $propertyId   = (int) $property['id'];
    $propertyName = $property['name'];
    $websiteUrl   = $property['website'];
    $countryId    = (int) $property['country_id'];
    $position     = $index + 1;

    output("─── Property {$position}/{$totalProperties}: #{$propertyId} \"{$propertyName}\" ───");
    output("Website: {$websiteUrl}");

    $domain = extractDomain($websiteUrl);

    // ─── Rate limiting: minimum 3 seconds between website requests ──────
    if ($index > 0) {
        // Ensure minimum delay between requests using rate limiter
        $delayMs = MIN_REQUEST_DELAY_SEC * 1000;
        RateLimiter::setDelay($domain, max($delayMs, RateLimiter::getDelay($domain)));
        RateLimiter::waitForDomain($domain);
    } else {
        // First request — still respect existing rate limits
        if (!RateLimiter::canRequest($domain)) {
            output("Rate limit active for {$domain}, waiting...");
            RateLimiter::waitForDomain($domain);
        } else {
            RateLimiter::recordRequest($domain);
        }
    }

    try {
        // ─── Step 1: Scrape the website ─────────────────────────────────
        output("  [1/4] Scraping website...");
        $scraper    = new WebsiteScraper();
        $scrapeData = $scraper->scrape($websiteUrl);

        if (empty($scrapeData) || !isset($scrapeData['html'])) {
            throw new \RuntimeException("Scraper returned no data for {$websiteUrl}");
        }

        output("  Scraped " . strlen($scrapeData['html'] ?? '') . " bytes from main page" .
               (isset($scrapeData['subpages']) ? " + " . count($scrapeData['subpages']) . " subpages" : ""));

        // ─── Step 2: Detect structured data / Schema.org ────────────────
        output("  [2/4] Detecting structured data...");
        $schemaDetector = new SchemaDetector();
        $schemaData     = $schemaDetector->detect($scrapeData);

        $schemaCount = 0;
        if (isset($schemaData['schemas']) && is_array($schemaData['schemas'])) {
            $schemaCount = count($schemaData['schemas']);
        }
        output("  Found {$schemaCount} schema(s)");

        // ─── Step 3: Detect CMS / booking engine platform ───────────────
        output("  [3/4] Detecting platform/CMS...");
        $platformDetector = new PlatformDetector();
        $platformData     = $platformDetector->detect($scrapeData);

        $cmsName = $platformData['cms'] ?? 'unknown';
        $bookingEngine = $platformData['booking_engine'] ?? 'none';
        output("  CMS: {$cmsName}, Booking engine: {$bookingEngine}");

        // ─── Step 4: Run entity authority audit ─────────────────────────
        output("  [4/4] Running entity audit...");
        $auditor   = new EntityAuditor();
        $auditData = $auditor->audit($propertyId, $scrapeData, $schemaData, $platformData);

        $aiScore = $auditData['ai_readiness_score'] ?? 0;
        output("  AI Readiness Score: {$aiScore}/100");

        // ─── Save enrichment data to property_enrichment ────────────────
        output("  Saving enrichment data...");
        $enrichmentRecord = [
            'property_id'    => $propertyId,
            'raw_html'       => $scrapeData['html'] ?? null,
            'subpages_json'  => isset($scrapeData['subpages']) ? json_encode($scrapeData['subpages'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'schema_json'    => json_encode($schemaData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'platform_json'  => json_encode($platformData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'cms_detected'   => $cmsName,
            'booking_engine' => $bookingEngine,
            'word_count'     => $scrapeData['word_count'] ?? null,
            'has_schema'     => !empty($schemaData['schemas']) ? 1 : 0,
            'enriched_at'    => date('Y-m-d H:i:s'),
        ];

        Database::insert('property_enrichment', $enrichmentRecord);

        // ─── Save audit data to property_audit ──────────────────────────
        output("  Saving audit data...");
        $auditRecord = [
            'property_id'        => $propertyId,
            'ai_readiness_score' => $aiScore,
            'schema_score'       => $auditData['schema_score'] ?? 0,
            'authority_score'    => $auditData['authority_score'] ?? 0,
            'discovery_score'    => $auditData['discovery_score'] ?? 0,
            'technical_score'    => $auditData['technical_score'] ?? 0,
            'booking_score'      => $auditData['booking_score'] ?? 0,
            'findings_json'      => json_encode($auditData['findings'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'recommendations_json' => json_encode($auditData['recommendations'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'audited_at'         => date('Y-m-d H:i:s'),
        ];

        Database::insert('property_audit', $auditRecord);

        // ─── Update property status and AI readiness score ──────────────
        Database::update(
            'properties',
            [
                'status'             => 'audited',
                'ai_readiness_score' => $aiScore,
                'updated_at'         => date('Y-m-d H:i:s'),
            ],
            '`id` = ?',
            [$propertyId]
        );

        // ─── Add to scrape_queue as 'publish' type ──────────────────────
        output("  Adding to scrape_queue for publishing...");
        Database::insert('scrape_queue', [
            'property_id' => $propertyId,
            'type'        => 'publish',
            'url'         => $websiteUrl,
            'status'      => 'pending',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        // ─── Log success ────────────────────────────────────────────────
        Logger::info(CRON_SOURCE, "Enriched property #{$propertyId}: {$propertyName}", [
            'metadata' => [
                'property_id'        => $propertyId,
                'cms'                => $cmsName,
                'booking_engine'     => $bookingEngine,
                'schema_count'       => $schemaCount,
                'ai_readiness_score' => $aiScore,
            ],
        ]);

        $successCount++;
        output("  Enrichment complete for #{$propertyId}.");

    } catch (\Throwable $e) {
        // ─── Handle individual property failure ─────────────────────────
        $errorMessage = $e->getMessage();
        $failCount++;

        output("  ERROR: Enrichment failed for #{$propertyId}: {$errorMessage}");

        Logger::error(CRON_SOURCE, "Enrichment failed for property #{$propertyId}: {$errorMessage}", [
            'url'      => $websiteUrl,
            'metadata' => [
                'property_id'   => $propertyId,
                'property_name' => $propertyName,
                'domain'        => $domain,
            ],
        ]);

        // Add to scrape_queue as failed so it can be retried or investigated
        try {
            Database::insert('scrape_queue', [
                'property_id'   => $propertyId,
                'type'          => 'enrich',
                'url'           => $websiteUrl,
                'status'        => 'failed',
                'error_message' => $errorMessage,
                'created_at'    => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $queueError) {
            output("  WARNING: Also failed to add to scrape_queue: " . $queueError->getMessage());
        }

        // Continue to next property instead of aborting the whole run
        continue;
    }
}

// ─── Summary ────────────────────────────────────────────────────────────────
$durationSec = round(microtime(true) - $startTime, 2);
output("═══════════════════════════════════════════════════════════════");
output("Enrichment run complete: {$successCount} succeeded, {$failCount} failed out of {$totalProperties}");
output("Total duration: {$durationSec}s");

// Determine final status
if ($failCount === 0) {
    $finalStatus = 'success';
} elseif ($successCount === 0) {
    $finalStatus = 'failed';
} else {
    $finalStatus = 'partial';
}

Logger::info(CRON_SOURCE, "Enrichment run complete: {$successCount}/{$totalProperties} succeeded", [
    'metadata' => [
        'success_count' => $successCount,
        'fail_count'    => $failCount,
        'total'         => $totalProperties,
        'duration_sec'  => $durationSec,
    ],
]);

updateCronSchedule($finalStatus, $startTime);

output("Enrichment cron finished with status: {$finalStatus}");
