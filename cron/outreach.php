<?php
/**
 * Filename: outreach.php
 * Description: Outreach email cron job - sends templated emails to properties with AI readiness scores
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
require_once __DIR__ . '/../lib/email-sender.php';

// ─── Constants ──────────────────────────────────────────────────────────────
define('CRON_NAME', 'outreach');
define('CRON_SOURCE', 'cron:outreach');
define('EMAIL_DELAY_SEC', 2);

// ─── Default email template ─────────────────────────────────────────────────
define('DEFAULT_EMAIL_SUBJECT', 'Your Safari Lodge\'s AI Visibility Score: {score}/100');

define('DEFAULT_EMAIL_TEMPLATE', <<<'TEMPLATE'
Hi {contact_name},

We've been researching how AI search engines like ChatGPT, Perplexity, and Google AI recommend safari properties to travelers — and we've audited {property_name}.

Your AI Readiness Score: {score}/100

This means {score_interpretation}.

We've created a free listing for {property_name} on safari-traveller.com with your complete audit report:
{listing_url}

Key findings:
- {finding_1}
- {finding_2}
- {finding_3}

You can claim your listing and update your property details here:
{claim_url}

This is a free service. We believe every safari lodge deserves to be visible to AI travelers.

Best regards,
The Safari Traveller Team
safari-traveller.com
TEMPLATE);

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
    echo "[{$timestamp}] [outreach] {$message}\n";
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
 * Read a setting value from the settings table.
 *
 * @param string $key     The setting_key to look up
 * @param mixed  $default Default value if not found
 * @return mixed          The setting value or default
 */
function getSetting(string $key, mixed $default = null): mixed
{
    try {
        $row = Database::fetch(
            "SELECT `setting_value` FROM `settings` WHERE `setting_key` = ?",
            [$key]
        );

        if ($row && isset($row['setting_value'])) {
            return $row['setting_value'];
        }
    } catch (\Throwable $e) {
        // If settings table is unavailable, return default
    }

    return $default;
}

/**
 * Get the human-readable score interpretation text based on the AI readiness score.
 *
 * @param int $score The AI readiness score (0-100)
 * @return string    The interpretation text
 */
function getScoreInterpretation(int $score): string
{
    if ($score >= 81) {
        return 'your property is well positioned for AI discovery. AI search engines can find and recommend you effectively, though there may still be room for improvement';
    }

    if ($score >= 61) {
        return 'your property has a good foundation for AI visibility, but there are meaningful improvements that could boost your discoverability in AI-powered search results';
    }

    if ($score >= 31) {
        return 'your property has some visibility to AI search engines, but significant gaps exist in your structured data and digital presence that are likely causing you to be overlooked';
    }

    return 'your property is largely invisible to AI search engines. Without structured data and a stronger digital footprint, AI tools like ChatGPT and Perplexity are unlikely to recommend you to travellers';
}

/**
 * Extract the top N findings from a property audit.
 * Returns generic findings if the audit data is unavailable or empty.
 *
 * @param array|null $audit       The property_audit row (with findings JSON)
 * @param string     $propertyName The property name for contextual messages
 * @param int        $count       Number of findings to return
 * @return array                  Array of finding strings
 */
function getTopFindings(?array $audit, string $propertyName, int $count = 3): array
{
    $findings = [];

    // Try to decode findings from the audit record
    if ($audit && !empty($audit['findings'])) {
        $decoded = is_string($audit['findings'])
            ? json_decode($audit['findings'], true)
            : $audit['findings'];

        if (is_array($decoded)) {
            foreach ($decoded as $finding) {
                if (is_string($finding)) {
                    $findings[] = $finding;
                } elseif (is_array($finding) && isset($finding['message'])) {
                    $findings[] = $finding['message'];
                } elseif (is_array($finding) && isset($finding['description'])) {
                    $findings[] = $finding['description'];
                } elseif (is_array($finding) && isset($finding['text'])) {
                    $findings[] = $finding['text'];
                }

                if (count($findings) >= $count) {
                    break;
                }
            }
        }
    }

    // Pad with generic findings if we don't have enough
    $genericFindings = [
        "Your property's structured data (Schema.org markup) could be improved to help AI engines understand your offering",
        "Strengthening your digital footprint across review platforms and directories would increase AI recommendation likelihood",
        "Adding detailed, well-structured content about rooms, activities, and location helps AI assistants provide accurate recommendations",
    ];

    $i = 0;
    while (count($findings) < $count && $i < count($genericFindings)) {
        $findings[] = $genericFindings[$i];
        $i++;
    }

    return array_slice($findings, 0, $count);
}

/**
 * Populate the outreach queue with properties that have been audited but
 * not yet added to the queue.
 *
 * Finds properties where:
 * - The property has an email (in properties.email) OR at least one property_contacts entry
 * - ai_readiness_score > 0 (has been audited)
 * - NOT already in outreach_queue
 * - status is 'audited' or 'published'
 *
 * @return int Number of new outreach_queue entries created
 */
function populateOutreachQueue(): int
{
    $added = 0;

    try {
        // Find properties with a direct email that are not yet in the outreach queue
        $propertiesWithEmail = Database::fetchAll(
            "SELECT p.`id`, p.`name`, p.`email`
             FROM `properties` p
             WHERE p.`ai_readiness_score` > 0
               AND p.`status` IN ('audited', 'published')
               AND p.`email` IS NOT NULL
               AND p.`email` != ''
               AND p.`id` NOT IN (SELECT `property_id` FROM `outreach_queue`)
             ORDER BY p.`ai_readiness_score` DESC, p.`id` ASC"
        );

        foreach ($propertiesWithEmail as $prop) {
            try {
                Database::insert('outreach_queue', [
                    'property_id'   => (int) $prop['id'],
                    'contact_email' => $prop['email'],
                    'contact_name'  => null,
                    'status'        => 'pending',
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
                $added++;
            } catch (\Throwable $e) {
                Logger::error(CRON_SOURCE, "Failed to add property #{$prop['id']} to outreach queue: " . $e->getMessage());
            }
        }

        // Find properties that have contacts but no direct email (and not already queued)
        $propertiesWithContacts = Database::fetchAll(
            "SELECT p.`id`, p.`name`, pc.`contact_email`, pc.`contact_name`
             FROM `properties` p
             JOIN `property_contacts` pc ON pc.`property_id` = p.`id`
             WHERE p.`ai_readiness_score` > 0
               AND p.`status` IN ('audited', 'published')
               AND pc.`contact_email` IS NOT NULL
               AND pc.`contact_email` != ''
               AND p.`id` NOT IN (SELECT `property_id` FROM `outreach_queue`)
             ORDER BY pc.`is_primary` DESC, pc.`id` ASC"
        );

        // Group by property_id and take only the first (best) contact per property
        $seenPropertyIds = [];
        foreach ($propertiesWithContacts as $row) {
            $propId = (int) $row['id'];

            if (in_array($propId, $seenPropertyIds, true)) {
                continue;
            }
            $seenPropertyIds[] = $propId;

            try {
                Database::insert('outreach_queue', [
                    'property_id'   => $propId,
                    'contact_email' => $row['contact_email'],
                    'contact_name'  => $row['contact_name'] ?? null,
                    'status'        => 'pending',
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
                $added++;
            } catch (\Throwable $e) {
                Logger::error(CRON_SOURCE, "Failed to add property #{$propId} (contact) to outreach queue: " . $e->getMessage());
            }
        }
    } catch (\Throwable $e) {
        output("ERROR: Failed to populate outreach queue: " . $e->getMessage());
        Logger::error(CRON_SOURCE, 'Failed to populate outreach queue: ' . $e->getMessage());
    }

    return $added;
}

// ─── Register shutdown function to always release the lock ──────────────────
register_shutdown_function(function () use ($startTime) {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $errorMsg = "{$error['message']} in {$error['file']}:{$error['line']}";
        output("FATAL ERROR: {$errorMsg}");
        Logger::error(CRON_SOURCE, 'Fatal error during outreach run: ' . $errorMsg);
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

output("Outreach cron started.");

// ─── Check if outreach is enabled ───────────────────────────────────────────
$outreachEnabled = getSetting('outreach_enabled', 'true');

if ($outreachEnabled === 'false' || $outreachEnabled === '0') {
    output("Outreach is disabled (outreach_enabled = {$outreachEnabled}). Exiting.");
    Logger::info(CRON_SOURCE, 'Outreach disabled via settings');
    updateCronSchedule('disabled', $startTime);
    exit(0);
}

// ─── Get max emails per day ─────────────────────────────────────────────────
$maxPerDay = (int) getSetting('max_outreach_per_day', '50');
output("Max outreach emails per day: {$maxPerDay}");

// ─── Count emails already sent today ────────────────────────────────────────
try {
    $todayStart = date('Y-m-d') . ' 00:00:00';

    $sentRow = Database::fetch(
        "SELECT COUNT(*) AS `cnt` FROM `outreach_queue`
         WHERE `status` = 'sent'
           AND `sent_at` >= ?",
        [$todayStart]
    );

    $sentToday = (int) ($sentRow['cnt'] ?? 0);
    output("Emails sent today: {$sentToday}/{$maxPerDay}");

    if ($sentToday >= $maxPerDay) {
        output("Daily outreach limit reached ({$sentToday}/{$maxPerDay}). Exiting.");
        Logger::info(CRON_SOURCE, "Daily outreach limit reached: {$sentToday}/{$maxPerDay}");
        updateCronSchedule('limit_reached', $startTime);
        exit(0);
    }
} catch (\Throwable $e) {
    output("ERROR: Failed to count sent emails: " . $e->getMessage());
    Logger::error(CRON_SOURCE, 'Failed to count sent emails: ' . $e->getMessage());
    updateCronSchedule('failed', $startTime);
    exit(1);
}

$remainingToday = $maxPerDay - $sentToday;
output("Remaining emails for today: {$remainingToday}");

// ─── Populate outreach queue with new eligible properties ───────────────────
output("Populating outreach queue with new eligible properties...");
$newlyQueued = populateOutreachQueue();
output("Added {$newlyQueued} new properties to the outreach queue.");

// ─── Fetch pending outreach queue items ─────────────────────────────────────
try {
    $queueItems = Database::fetchAll(
        "SELECT * FROM `outreach_queue`
         WHERE `status` = 'pending'
         ORDER BY `created_at` ASC
         LIMIT ?",
        [$remainingToday]
    );

    if (empty($queueItems)) {
        output("No pending outreach queue items found. Nothing to do.");
        Logger::info(CRON_SOURCE, 'No pending outreach items in queue');
        updateCronSchedule('idle', $startTime);
        exit(0);
    }

    $totalItems = count($queueItems);
    output("Found {$totalItems} pending outreach items to process.");
} catch (\Throwable $e) {
    output("ERROR: Failed to fetch outreach queue: " . $e->getMessage());
    Logger::error(CRON_SOURCE, 'Failed to fetch outreach queue: ' . $e->getMessage());
    updateCronSchedule('failed', $startTime);
    exit(1);
}

// ─── Load email template and subject from settings ──────────────────────────
$emailTemplate = getSetting('outreach_email_template', DEFAULT_EMAIL_TEMPLATE);
$emailSubject  = getSetting('outreach_email_subject', DEFAULT_EMAIL_SUBJECT);

output("Email template loaded (" . strlen($emailTemplate) . " chars).");

// ─── Process each outreach queue item ───────────────────────────────────────
$successCount = 0;
$failCount    = 0;
$skipCount    = 0;

foreach ($queueItems as $index => $queueItem) {
    $queueId      = (int) $queueItem['id'];
    $propertyId   = (int) $queueItem['property_id'];
    $contactEmail = $queueItem['contact_email'];
    $contactName  = $queueItem['contact_name'] ?? '';
    $position     = $index + 1;

    output("─── Item {$position}/{$totalItems}: queue #{$queueId}, property #{$propertyId} ───");
    output("  To: {$contactEmail}" . ($contactName ? " ({$contactName})" : ''));

    // ─── Sleep between emails to avoid spam flags ───────────────────────
    if ($index > 0) {
        output("  Sleeping " . EMAIL_DELAY_SEC . "s between emails...");
        sleep(EMAIL_DELAY_SEC);
    }

    // ─── Load property data ─────────────────────────────────────────────
    try {
        $property = Database::fetch(
            "SELECT * FROM `properties` WHERE `id` = ?",
            [$propertyId]
        );

        if (!$property) {
            output("  WARNING: Property #{$propertyId} not found. Skipping.");
            Logger::error(CRON_SOURCE, "Property #{$propertyId} not found for outreach queue #{$queueId}");
            $skipCount++;
            continue;
        }
    } catch (\Throwable $e) {
        output("  ERROR: Failed to load property #{$propertyId}: " . $e->getMessage());
        Logger::error(CRON_SOURCE, "Failed to load property #{$propertyId}: " . $e->getMessage());
        $failCount++;
        continue;
    }

    $propertyName = $property['name'];
    $propertySlug = $property['slug'] ?? '';
    $aiScore      = (int) ($property['ai_readiness_score'] ?? 0);

    output("  Property: {$propertyName} (score: {$aiScore}/100)");

    // ─── Load latest audit data ─────────────────────────────────────────
    $audit = null;
    try {
        $audit = Database::fetch(
            "SELECT * FROM `property_audit`
             WHERE `property_id` = ?
             ORDER BY `audited_at` DESC
             LIMIT 1",
            [$propertyId]
        );
    } catch (\Throwable $e) {
        output("  WARNING: Failed to load audit for property #{$propertyId}: " . $e->getMessage());
        // Continue without audit data — we will use generic findings
    }

    // ─── Build template variables ───────────────────────────────────────
    $displayContactName = !empty($contactName) ? $contactName : 'there';
    $scoreInterpretation = getScoreInterpretation($aiScore);

    // Build listing and claim URLs
    $listingUrl = 'https://safari-traveller.com/property.php?slug=' . urlencode($propertySlug);
    if (empty($propertySlug)) {
        $listingUrl = 'https://safari-traveller.com/property.php?id=' . $propertyId;
    }
    $claimUrl = 'https://safari-traveller.com/claim.php?id=' . $propertyId;

    // Get top 3 findings
    $topFindings = getTopFindings($audit, $propertyName, 3);

    // ─── Replace template variables ─────────────────────────────────────
    $replacements = [
        '{property_name}'        => $propertyName,
        '{contact_name}'         => $displayContactName,
        '{score}'                => (string) $aiScore,
        '{score_interpretation}' => $scoreInterpretation,
        '{listing_url}'          => $listingUrl,
        '{claim_url}'            => $claimUrl,
        '{finding_1}'            => $topFindings[0] ?? '',
        '{finding_2}'            => $topFindings[1] ?? '',
        '{finding_3}'            => $topFindings[2] ?? '',
    ];

    $finalBody    = str_replace(array_keys($replacements), array_values($replacements), $emailTemplate);
    $finalSubject = str_replace(array_keys($replacements), array_values($replacements), $emailSubject);

    output("  Subject: {$finalSubject}");

    // ─── Send the email ─────────────────────────────────────────────────
    try {
        $sendResult = EmailSender::send(
            $contactEmail,
            $finalSubject,
            $finalBody
        );

        if ($sendResult) {
            // ─── Success: update queue status to 'sent' ─────────────
            Database::update(
                'outreach_queue',
                [
                    'status'  => 'sent',
                    'sent_at' => date('Y-m-d H:i:s'),
                ],
                '`id` = ?',
                [$queueId]
            );

            $successCount++;
            output("  Email sent successfully.");

            Logger::info(CRON_SOURCE, "Outreach email sent for property #{$propertyId}", [
                'metadata' => [
                    'queue_id'       => $queueId,
                    'property_id'    => $propertyId,
                    'property_name'  => $propertyName,
                    'contact_email'  => $contactEmail,
                    'ai_score'       => $aiScore,
                ],
            ]);
        } else {
            // EmailSender returned false — treat as failure, keep pending for retry
            $failCount++;
            output("  WARNING: EmailSender::send() returned false. Will retry next run.");

            Logger::error(CRON_SOURCE, "EmailSender returned false for property #{$propertyId}", [
                'metadata' => [
                    'queue_id'      => $queueId,
                    'property_id'   => $propertyId,
                    'contact_email' => $contactEmail,
                ],
            ]);
        }
    } catch (\Throwable $e) {
        // ─── Failure: log error, keep as pending for retry ──────────
        $errorMessage = $e->getMessage();
        $failCount++;

        output("  ERROR: Failed to send email: {$errorMessage}");

        Logger::error(CRON_SOURCE, "Failed to send outreach email for property #{$propertyId}: {$errorMessage}", [
            'metadata' => [
                'queue_id'      => $queueId,
                'property_id'   => $propertyId,
                'contact_email' => $contactEmail,
            ],
        ]);

        // Keep status as 'pending' so it will be retried on the next run
    }
}

// ─── Summary ────────────────────────────────────────────────────────────────
$durationSec = round(microtime(true) - $startTime, 2);

output("═══════════════════════════════════════════════════════════════");
output("Outreach run complete: {$successCount} sent, {$failCount} failed, {$skipCount} skipped out of {$totalItems}");
output("Total emails sent today: " . ($sentToday + $successCount) . "/{$maxPerDay}");
output("Total duration: {$durationSec}s");

// Determine final status
if ($failCount === 0 && $skipCount === 0) {
    $finalStatus = 'success';
} elseif ($successCount === 0 && $failCount > 0) {
    $finalStatus = 'failed';
} elseif ($failCount > 0 || $skipCount > 0) {
    $finalStatus = 'partial';
} else {
    $finalStatus = 'success';
}

Logger::info(CRON_SOURCE, "Outreach run complete: {$successCount}/{$totalItems} sent", [
    'metadata' => [
        'success_count'    => $successCount,
        'fail_count'       => $failCount,
        'skip_count'       => $skipCount,
        'total'            => $totalItems,
        'sent_today_total' => $sentToday + $successCount,
        'max_per_day'      => $maxPerDay,
        'newly_queued'     => $newlyQueued,
        'duration_sec'     => $durationSec,
    ],
]);

updateCronSchedule($finalStatus, $startTime);

output("Outreach cron finished with status: {$finalStatus}");
