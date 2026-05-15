<?php
/**
 * File: cron/heartbeat.php
 * Description: Daily 00:01 SAST liveness check. Emails the admin a short status summary so silence = problem.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 10:08 SAST
 * Modified: 2026-05-14 10:08 SAST
 * Changes:
 *   1.0.0 (2026-05-14 10:08) — initial creation
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (!pulse_installed()) {
    fwrite(STDERR, "Pulse not installed. Run /install.php first.\n");
    exit(2);
}

try {
    $db = DB::getInstance();
    $admin = $db->fetchOne("SELECT email FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
    if (!$admin) {
        fwrite(STDERR, "No admin user; heartbeat skipped.\n");
        exit(0);
    }

    $today = Guardrails::today();
    $lastRun = $db->fetchOne('SELECT started_at, status FROM runs ORDER BY id DESC LIMIT 1');
    $sites = $db->fetchOne('SELECT COUNT(*) AS c FROM sites WHERE enabled = 1');
    $jobs = $db->fetchOne('SELECT COUNT(*) AS c FROM jobs WHERE enabled = 1');
    $openFindings = $db->fetchOne("SELECT COUNT(*) AS c FROM findings WHERE status = 'open'");

    $lastRunLine = $lastRun
        ? htmlspecialchars($lastRun['started_at'] . ' (' . $lastRun['status'] . ')', ENT_QUOTES, 'UTF-8')
        : '<em>no runs yet</em>';
    $killSwitchLabel = ((int) $today['kill_switch'] === 1) ? '<strong style="color:#B33A3A;">ACTIVE</strong>' : 'off';
    $dryRunLabel = ((int) $today['dry_run'] === 1) ? '<strong style="color:#B33A3A;">ON</strong>' : 'off';

    $intro = "Pulse is alive on " . date('Y-m-d') . ". Short status below.";
    $body = '<table cellpadding="6" cellspacing="0" border="0" style="font-size:14px;">'
        . '<tr><td>Sites enabled</td><td><strong>' . (int) $sites['c'] . '</strong></td></tr>'
        . '<tr><td>Jobs enabled</td><td><strong>' . (int) $jobs['c'] . '</strong></td></tr>'
        . '<tr><td>Open findings</td><td><strong>' . (int) $openFindings['c'] . '</strong></td></tr>'
        . '<tr><td>Today cost used</td><td>$' . number_format((float) $today['cost_used_usd'], 2) . ' / $' . number_format((float) $today['cost_ceiling_usd'], 2) . '</td></tr>'
        . '<tr><td>Kill switch</td><td>' . $killSwitchLabel . '</td></tr>'
        . '<tr><td>Dry run</td><td>' . $dryRunLabel . '</td></tr>'
        . '<tr><td>Last run</td><td>' . $lastRunLine . '</td></tr>'
        . '</table>';

    $html = Reporter::template('Daily heartbeat', $intro, $body, 'If you do not receive this email on a future day, Pulse cron is down.');
    $ok = Reporter::send($admin['email'], '[Pulse] Daily heartbeat — ' . date('Y-m-d'), $html);
    Logger::info('heartbeat sent', ['to' => $admin['email'], 'ok' => $ok]);
    exit($ok ? 0 : 1);
} catch (Throwable $e) {
    $msg = '[' . date('Y-m-d H:i:s P') . "] heartbeat fatal: " . $e->getMessage() . "\n";
    @file_put_contents(PULSE_LOGS . '/cron.log', $msg, FILE_APPEND | LOCK_EX);
    fwrite(STDERR, $msg);
    exit(1);
}
