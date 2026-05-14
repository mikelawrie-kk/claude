<?php
/**
 * File: app/bootstrap.php
 * Description: Loads Pulse config + all core classes. Single require point for every cron, public, or installer script.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 10:00 SAST
 * Modified: 2026-05-14 10:00 SAST
 * Changes:
 *   1.0.0 (2026-05-14 10:00) — initial creation
 */

date_default_timezone_set('Africa/Johannesburg');
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

define('PULSE_ROOT', dirname(__DIR__));
define('PULSE_DATA', PULSE_ROOT . '/data');
define('PULSE_LOGS', PULSE_DATA . '/logs');
define('PULSE_CONFIG', PULSE_ROOT . '/app/config/pulse.config.php');

if (!is_dir(PULSE_DATA)) {
    mkdir(PULSE_DATA, 0750, true);
}
if (!is_dir(PULSE_LOGS)) {
    mkdir(PULSE_LOGS, 0750, true);
}

require_once __DIR__ . '/core/Logger.php';
require_once __DIR__ . '/core/DB.php';
require_once __DIR__ . '/core/Guardrails.php';
require_once __DIR__ . '/core/APIClient.php';
require_once __DIR__ . '/core/Reporter.php';
require_once __DIR__ . '/core/MCPClient.php';
require_once __DIR__ . '/core/Dispatcher.php';
require_once __DIR__ . '/core/ApplyRouter.php';
require_once __DIR__ . '/core/Orchestrator.php';

Logger::configure(PULSE_LOGS . '/pulse.log');

if (is_file(PULSE_CONFIG)) {
    $pulseConfig = require PULSE_CONFIG;
    if (is_array($pulseConfig)) {
        $GLOBALS['pulse_config'] = $pulseConfig;
        if (!empty($pulseConfig['timezone'])) {
            date_default_timezone_set((string) $pulseConfig['timezone']);
        }
        if (!empty($pulseConfig['db_path'])) {
            DB::configure((string) $pulseConfig['db_path']);
        }
    }
} else {
    $GLOBALS['pulse_config'] = [];
}

function pulse_config(string $key, $default = null)
{
    $cfg = $GLOBALS['pulse_config'] ?? [];
    return $cfg[$key] ?? $default;
}

function pulse_installed(): bool
{
    if (!is_file(PULSE_CONFIG)) {
        return false;
    }
    try {
        $db = DB::getInstance();
        $row = $db->fetchOne("SELECT COUNT(*) AS c FROM sqlite_master WHERE type='table' AND name='users'");
        if ($row === null || (int) $row['c'] === 0) {
            return false;
        }
        $users = $db->fetchOne('SELECT COUNT(*) AS c FROM users');
        return $users !== null && (int) $users['c'] > 0;
    } catch (Throwable $_) {
        return false;
    }
}
