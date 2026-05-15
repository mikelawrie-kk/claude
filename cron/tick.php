<?php
/**
 * File: cron/tick.php
 * Description: Cron entry — runs every 5 minutes via cPanel cron. Calls Orchestrator::tick(). Logs to data/logs/cron.log on errors.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 10:05 SAST
 * Modified: 2026-05-14 10:05 SAST
 * Changes:
 *   1.0.0 (2026-05-14 10:05) — initial creation
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (!pulse_installed()) {
    fwrite(STDERR, "Pulse not installed. Run /install.php first.\n");
    exit(2);
}

try {
    $result = Orchestrator::tick();
    fwrite(STDOUT, json_encode($result, JSON_UNESCAPED_SLASHES) . "\n");
    exit(($result['status'] ?? 'failed') === 'failed' ? 1 : 0);
} catch (Throwable $e) {
    $msg = '[' . date('Y-m-d H:i:s P') . "] tick fatal: " . $e->getMessage() . "\n";
    @file_put_contents(PULSE_LOGS . '/cron.log', $msg, FILE_APPEND | LOCK_EX);
    fwrite(STDERR, $msg);
    exit(1);
}
