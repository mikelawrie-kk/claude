<?php
/**
 * File: app/core/Dispatcher.php
 * Description: Discovers job modules under app/jobs/, computes due jobs, dispatches per-site runs. Week 1 = scaffold; jobs land in Week 2.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 09:48 SAST
 * Modified: 2026-05-14 09:48 SAST
 * Changes:
 *   1.0.0 (2026-05-14 09:48) — initial creation
 */

class Dispatcher
{
    public static function dueJobs(): array
    {
        $db = DB::getInstance();
        $now = date('Y-m-d H:i:s');
        return $db->fetchAll(
            'SELECT * FROM jobs WHERE enabled = 1 AND (next_run_at IS NULL OR next_run_at <= ?) ORDER BY id ASC',
            [$now]
        );
    }

    public static function enabledSites(): array
    {
        $db = DB::getInstance();
        return $db->fetchAll('SELECT * FROM sites WHERE enabled = 1 ORDER BY id ASC');
    }

    public static function dispatch(array $job): array
    {
        $db = DB::getInstance();
        $sites = self::enabledSites();
        $results = [];

        foreach ($sites as $site) {
            $results[] = self::dispatchOne($job, $site);
        }

        $nextRun = self::computeNextRun((string) ($job['cron_schedule'] ?? ''));
        $db->execute(
            'UPDATE jobs SET last_run_at = CURRENT_TIMESTAMP, next_run_at = ? WHERE id = ?',
            [$nextRun, $job['id']]
        );

        return $results;
    }

    public static function dispatchOne(array $job, array $site): array
    {
        $db = DB::getInstance();
        $db->execute(
            'INSERT INTO runs (job_id, site_id, status) VALUES (?, ?, ?)',
            [$job['id'], $site['id'], 'running']
        );
        $runId = $db->lastInsertId();

        $module = (string) ($job['module'] ?? '');
        $jobFile = dirname(__DIR__) . '/jobs/' . $module . '/Job.php';
        if ($module === '' || !is_file($jobFile)) {
            $db->execute(
                'UPDATE runs SET status = ?, ended_at = CURRENT_TIMESTAMP, error_message = ? WHERE id = ?',
                ['skipped', "module not found: $module", $runId]
            );
            Logger::warn('Dispatcher module missing', ['job' => $job['name'], 'module' => $module]);
            return ['run_id' => $runId, 'status' => 'skipped'];
        }

        require_once $jobFile;
        if (!class_exists($module)) {
            $db->execute(
                'UPDATE runs SET status = ?, ended_at = CURRENT_TIMESTAMP, error_message = ? WHERE id = ?',
                ['skipped', "class not found: $module", $runId]
            );
            Logger::warn('Dispatcher class missing', ['module' => $module]);
            return ['run_id' => $runId, 'status' => 'skipped'];
        }

        try {
            $instance = new $module();
            $result = $instance->run($runId, (int) $site['id']);
            $rowsProcessed = (int) ($result['rows_processed'] ?? 0);
            $rowsFailed = (int) ($result['rows_failed'] ?? 0);
            $costUsd = (float) ($result['cost_usd'] ?? 0);
            $reportPath = (string) ($result['report_path'] ?? '');
            $db->execute(
                'UPDATE runs SET status = ?, ended_at = CURRENT_TIMESTAMP, rows_processed = ?, rows_failed = ?, cost_usd = ?, report_path = ? WHERE id = ?',
                ['success', $rowsProcessed, $rowsFailed, $costUsd, $reportPath, $runId]
            );
            Logger::info('Dispatcher run success', [
                'job' => $job['name'],
                'site' => $site['domain'],
                'run_id' => $runId,
                'rows' => $rowsProcessed,
            ]);
            return ['run_id' => $runId, 'status' => 'success'];
        } catch (Throwable $e) {
            $db->execute(
                'UPDATE runs SET status = ?, ended_at = CURRENT_TIMESTAMP, error_message = ? WHERE id = ?',
                ['failed', $e->getMessage(), $runId]
            );
            Logger::error('Dispatcher run failed', [
                'job' => $job['name'],
                'site' => $site['domain'],
                'run_id' => $runId,
                'err' => $e->getMessage(),
            ]);
            return ['run_id' => $runId, 'status' => 'failed'];
        }
    }

    /**
     * Minimal cron-expression "next run" calculator.
     * Supports common patterns: "@daily", "@hourly", "@weekly", "*\/N * * * *" (every N minutes),
     * "0 H * * *" (daily at H), "0 H * * D" (weekly on day D at H).
     * Falls back to +1 hour for unrecognised expressions.
     */
    public static function computeNextRun(string $cron): string
    {
        $cron = trim($cron);
        $now = time();
        if ($cron === '' || $cron === '@hourly') {
            return date('Y-m-d H:i:s', $now + 3600);
        }
        if ($cron === '@daily') {
            return date('Y-m-d H:i:s', strtotime('tomorrow 00:00'));
        }
        if ($cron === '@weekly') {
            return date('Y-m-d H:i:s', strtotime('next monday 00:00'));
        }
        $parts = preg_split('/\s+/', $cron);
        if (is_array($parts) && count($parts) === 5) {
            [$min, $hr, $dom, $mon, $dow] = $parts;
            if (preg_match('/^\*\/(\d+)$/', $min, $m) && $hr === '*' && $dom === '*' && $mon === '*' && $dow === '*') {
                return date('Y-m-d H:i:s', $now + 60 * (int) $m[1]);
            }
            if (ctype_digit($min) && ctype_digit($hr) && $dom === '*' && $mon === '*' && $dow === '*') {
                $t = strtotime(sprintf('today %02d:%02d', (int) $hr, (int) $min));
                if ($t <= $now) {
                    $t = strtotime('tomorrow ' . sprintf('%02d:%02d', (int) $hr, (int) $min));
                }
                return date('Y-m-d H:i:s', $t);
            }
            if (ctype_digit($min) && ctype_digit($hr) && $dom === '*' && $mon === '*' && ctype_digit($dow)) {
                $days = ['sunday', 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'];
                $dayName = $days[((int) $dow) % 7];
                $t = strtotime("next $dayName " . sprintf('%02d:%02d', (int) $hr, (int) $min));
                return date('Y-m-d H:i:s', $t);
            }
        }
        return date('Y-m-d H:i:s', $now + 3600);
    }
}
