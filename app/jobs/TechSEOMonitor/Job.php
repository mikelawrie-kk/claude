<?php
/**
 * File: app/jobs/TechSEOMonitor/Job.php
 * Description: Entry point for Dispatcher. Loads the 7 TechSEOMonitor check modules, runs each against the given site, aggregates stats. Any check that throws is caught and recorded; the run continues.
 * Project: Pulse v1
 * Version: 1.1.0
 * Created: 2026-11-03 09:25 SAST
 * Modified: 2026-11-03 14:40 SAST
 * Changes:
 *   1.1.0 (2026-11-03 14:40) — invoke FixGenerator after every check pass so open findings carry a fix_payload_json before Mike sees them
 *   1.0.0 (2026-11-03 09:25) — initial creation
 */

require_once __DIR__ . '/Scorer.php';
require_once __DIR__ . '/FixGenerator.php';
require_once __DIR__ . '/checks/CheckBase.php';
require_once __DIR__ . '/checks/HTTPStatusCheck.php';
require_once __DIR__ . '/checks/RobotsCheck.php';
require_once __DIR__ . '/checks/RedirectCheck.php';
require_once __DIR__ . '/checks/BrokenLinkCheck.php';
require_once __DIR__ . '/checks/IndexingCheck.php';
require_once __DIR__ . '/checks/CWVCheck.php';
require_once __DIR__ . '/checks/SchemaCheck.php';

class TechSEOMonitor
{
    private const CHECK_CLASSES = [
        'HTTPStatus' => 'HTTPStatusCheck',
        'Robots' => 'RobotsCheck',
        'Redirect' => 'RedirectCheck',
        'BrokenLink' => 'BrokenLinkCheck',
        'Indexing' => 'IndexingCheck',
        'CWV' => 'CWVCheck',
        'Schema' => 'SchemaCheck',
    ];

    public function run(int $runId, int $siteId): array
    {
        $db = DB::getInstance();
        $site = $db->fetchOne('SELECT * FROM sites WHERE id = ?', [$siteId]);
        if (!$site) {
            throw new RuntimeException("TechSEOMonitor: site $siteId not found");
        }
        $entity = $db->fetchOne('SELECT * FROM site_entity WHERE site_id = ?', [$siteId]);

        $job = $db->fetchOne("SELECT config_json FROM jobs WHERE name = 'TechSEOMonitor'");
        $config = json_decode((string) ($job['config_json'] ?? '{}'), true) ?: [];
        $enabledChecks = $config['checks'] ?? array_keys(self::CHECK_CLASSES);

        $totalNew = 0;
        $totalUpdated = 0;
        $totalFailed = 0;
        $totalCost = 0.0;
        $perCheck = [];

        foreach ($enabledChecks as $checkKey) {
            if (!isset(self::CHECK_CLASSES[$checkKey])) {
                Logger::warn('TechSEOMonitor unknown check', ['check' => $checkKey]);
                continue;
            }
            $class = self::CHECK_CLASSES[$checkKey];
            $started = microtime(true);
            try {
                $check = new $class($runId, $siteId, $site, $entity, $config);
                $check->run();
                $stats = $check->stats();
                $totalNew += (int) $stats['findings_new'];
                $totalUpdated += (int) $stats['findings_updated'];
                $totalFailed += (int) $stats['findings_failed'];
                $totalCost += (float) $stats['cost_usd'];
                $stats['duration_ms'] = (int) round((microtime(true) - $started) * 1000);
                $perCheck[$checkKey] = $stats;
                Logger::info('TechSEOMonitor check complete', $stats + ['site' => $site['domain']]);
            } catch (Throwable $e) {
                $perCheck[$checkKey] = ['check' => $checkKey, 'error' => $e->getMessage()];
                $totalFailed++;
                Logger::error('TechSEOMonitor check failed', [
                    'check' => $checkKey,
                    'site' => $site['domain'],
                    'err' => $e->getMessage(),
                ]);
            }
        }

        $this->autoResolveStale($runId, $siteId);

        $fixerStats = [];
        try {
            $fixerStats = FixGenerator::generate($site, $entity, $config);
            Logger::info('TechSEOMonitor fix generation complete', [
                'site' => $site['domain'],
                'per_fixer' => $fixerStats,
            ]);
        } catch (Throwable $e) {
            Logger::error('TechSEOMonitor fix generation failed', [
                'site' => $site['domain'],
                'err' => $e->getMessage(),
            ]);
        }

        return [
            'rows_processed' => $totalNew + $totalUpdated,
            'rows_failed' => $totalFailed,
            'cost_usd' => $totalCost,
            'per_check' => $perCheck,
            'per_fixer' => $fixerStats,
        ];
    }

    /**
     * Any open finding for this site that was NOT touched by this run → the issue is gone, mark resolved.
     */
    private function autoResolveStale(int $runId, int $siteId): void
    {
        $db = DB::getInstance();
        $run = $db->fetchOne('SELECT started_at FROM runs WHERE id = ?', [$runId]);
        if (!$run) {
            return;
        }
        $resolved = $db->execute(
            "UPDATE findings SET status = 'resolved', last_seen = CURRENT_TIMESTAMP
             WHERE site_id = ? AND status = 'open' AND run_id != ? AND last_seen < ?",
            [$siteId, $runId, $run['started_at']]
        );
        if ($resolved > 0) {
            Logger::info('TechSEOMonitor auto-resolved stale findings', [
                'site_id' => $siteId,
                'count' => $resolved,
            ]);
        }
    }
}
