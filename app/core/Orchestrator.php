<?php
/**
 * File: app/core/Orchestrator.php
 * Description: Cron tick entry. Acquires file lock, enforces guardrails (kill switch, daily cost ceiling), dispatches due jobs, logs the tick run.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 09:55 SAST
 * Modified: 2026-05-14 09:55 SAST
 * Changes:
 *   1.0.0 (2026-05-14 09:55) — initial creation
 */

class Orchestrator
{
    private const LOCK_FILE = __DIR__ . '/../../data/.tick.lock';

    public static function tick(): array
    {
        $started = microtime(true);
        $db = DB::getInstance();

        $lockPath = realpath(dirname(self::LOCK_FILE)) . '/.tick.lock';
        $fp = @fopen($lockPath, 'c');
        if (!$fp) {
            Logger::error('Orchestrator could not open lock file', ['path' => $lockPath]);
            return ['status' => 'lock_error'];
        }
        if (!flock($fp, LOCK_EX | LOCK_NB)) {
            fclose($fp);
            Logger::warn('Orchestrator tick already running, exiting');
            return ['status' => 'locked'];
        }

        $tickRunId = null;
        try {
            $db->execute(
                'INSERT INTO runs (job_id, site_id, status) VALUES (NULL, NULL, ?)',
                ['running']
            );
            $tickRunId = $db->lastInsertId();

            if (Guardrails::killSwitchActive()) {
                Logger::warn('Orchestrator kill switch active, skipping dispatch');
                $db->execute(
                    'UPDATE runs SET status = ?, ended_at = CURRENT_TIMESTAMP, error_message = ? WHERE id = ?',
                    ['skipped', 'kill_switch active', $tickRunId]
                );
                return ['status' => 'kill_switch', 'tick_run_id' => $tickRunId];
            }

            if (Guardrails::costExceeded()) {
                $row = Guardrails::today();
                Logger::warn('Orchestrator cost ceiling exceeded', [
                    'used' => $row['cost_used_usd'],
                    'ceiling' => $row['cost_ceiling_usd'],
                ]);
                $db->execute(
                    'UPDATE runs SET status = ?, ended_at = CURRENT_TIMESTAMP, error_message = ? WHERE id = ?',
                    ['skipped', 'cost_ceiling exceeded', $tickRunId]
                );
                return ['status' => 'cost_ceiling', 'tick_run_id' => $tickRunId];
            }

            if (Guardrails::costWarnCrossed()) {
                self::sendCostWarning();
            }

            $due = Dispatcher::dueJobs();
            $dispatched = 0;
            $perJobResults = [];
            foreach ($due as $job) {
                $perJobResults[$job['name']] = Dispatcher::dispatch($job);
                $dispatched++;
            }

            $duration = (int) round((microtime(true) - $started) * 1000);
            $db->execute(
                'UPDATE runs SET status = ?, ended_at = CURRENT_TIMESTAMP, rows_processed = ? WHERE id = ?',
                ['success', $dispatched, $tickRunId]
            );
            Logger::info('Orchestrator tick complete', [
                'tick_run_id' => $tickRunId,
                'jobs_dispatched' => $dispatched,
                'duration_ms' => $duration,
                'dry_run' => Guardrails::dryRun(),
            ]);

            return [
                'status' => 'success',
                'tick_run_id' => $tickRunId,
                'jobs_dispatched' => $dispatched,
                'dry_run' => Guardrails::dryRun(),
            ];
        } catch (Throwable $e) {
            Logger::error('Orchestrator tick failed', ['err' => $e->getMessage()]);
            if ($tickRunId !== null) {
                try {
                    $db->execute(
                        'UPDATE runs SET status = ?, ended_at = CURRENT_TIMESTAMP, error_message = ? WHERE id = ?',
                        ['failed', $e->getMessage(), $tickRunId]
                    );
                } catch (Throwable $_) {
                    // ignore secondary failure
                }
            }
            return ['status' => 'failed', 'error' => $e->getMessage()];
        } finally {
            flock($fp, LOCK_UN);
            fclose($fp);
        }
    }

    private static function sendCostWarning(): void
    {
        $row = Guardrails::today();
        $db = DB::getInstance();
        $admin = $db->fetchOne("SELECT email FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
        if (!$admin) {
            return;
        }
        $pct = round(((float) $row['cost_used_usd'] / max(0.01, (float) $row['cost_ceiling_usd'])) * 100);
        $intro = "Today's Pulse spend has crossed $pct% of the daily ceiling.";
        $body = '<p><strong>Used:</strong> $' . number_format((float) $row['cost_used_usd'], 2) . '<br>'
              . '<strong>Ceiling:</strong> $' . number_format((float) $row['cost_ceiling_usd'], 2) . '</p>'
              . '<p>Pulse will stop dispatching jobs once the ceiling is reached. Adjust in the admin dashboard if needed.</p>';
        $html = Reporter::template('Daily cost ceiling — 80% warning', $intro, $body);
        Reporter::send($admin['email'], '[Pulse] Daily cost at ' . $pct . '%', $html);
    }
}
