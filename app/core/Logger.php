<?php
/**
 * File: app/core/Logger.php
 * Description: Append-only file logger with daily rotation. Writes to data/logs/pulse.log (and cron.log when in cron context).
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 09:08 SAST
 * Modified: 2026-05-14 09:08 SAST
 * Changes:
 *   1.0.0 (2026-05-14 09:08) — initial creation
 */

class Logger
{
    private static string $logFile = '';
    private const ROTATE_BYTES = 1048576; // 1 MB

    public static function configure(string $logFile): void
    {
        self::$logFile = $logFile;
        $dir = dirname($logFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
    }

    private static function resolve(): string
    {
        if (self::$logFile === '') {
            self::$logFile = dirname(__DIR__, 2) . '/data/logs/pulse.log';
            $dir = dirname(self::$logFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
        }
        return self::$logFile;
    }

    public static function info(string $msg, array $ctx = []): void
    {
        self::write('INFO', $msg, $ctx);
    }

    public static function warn(string $msg, array $ctx = []): void
    {
        self::write('WARN', $msg, $ctx);
    }

    public static function error(string $msg, array $ctx = []): void
    {
        self::write('ERROR', $msg, $ctx);
    }

    public static function debug(string $msg, array $ctx = []): void
    {
        self::write('DEBUG', $msg, $ctx);
    }

    private static function write(string $level, string $msg, array $ctx): void
    {
        $file = self::resolve();
        self::rotateIfNeeded($file);
        $ts = date('Y-m-d H:i:s P');
        $ctxStr = $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_SLASHES) : '';
        $line = "[$ts] [$level] $msg$ctxStr" . PHP_EOL;
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    private static function rotateIfNeeded(string $file): void
    {
        if (!is_file($file)) {
            return;
        }
        $size = @filesize($file);
        if ($size === false || $size < self::ROTATE_BYTES) {
            return;
        }
        $archive = $file . '.' . date('Ymd-His');
        @rename($file, $archive);
    }
}
