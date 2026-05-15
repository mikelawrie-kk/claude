<?php
/**
 * File: app/core/Guardrails.php
 * Description: Daily cost ceiling, kill switch, dry-run mode. Today's row is auto-seeded on first read.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 09:12 SAST
 * Modified: 2026-05-14 09:12 SAST
 * Changes:
 *   1.0.0 (2026-05-14 09:12) — initial creation
 */

class Guardrails
{
    public const DEFAULT_CEILING_USD = 5.00;
    public const WARN_THRESHOLD_PCT = 0.80;

    public static function today(): array
    {
        $today = date('Y-m-d');
        $db = DB::getInstance();
        $row = $db->fetchOne('SELECT * FROM guardrails WHERE date = ?', [$today]);
        if ($row === null) {
            $previous = $db->fetchOne('SELECT cost_ceiling_usd, kill_switch, dry_run FROM guardrails ORDER BY date DESC LIMIT 1');
            $ceiling = $previous['cost_ceiling_usd'] ?? self::DEFAULT_CEILING_USD;
            $killSwitch = $previous['kill_switch'] ?? 0;
            $dryRun = $previous['dry_run'] ?? 0;
            $db->execute(
                'INSERT INTO guardrails (date, cost_ceiling_usd, cost_used_usd, kill_switch, dry_run) VALUES (?, ?, 0, ?, ?)',
                [$today, $ceiling, $killSwitch, $dryRun]
            );
            $row = $db->fetchOne('SELECT * FROM guardrails WHERE date = ?', [$today]);
        }
        return $row;
    }

    public static function killSwitchActive(): bool
    {
        return (int) self::today()['kill_switch'] === 1;
    }

    public static function costExceeded(): bool
    {
        $row = self::today();
        return (float) $row['cost_used_usd'] >= (float) $row['cost_ceiling_usd'];
    }

    public static function costWarnCrossed(): bool
    {
        $row = self::today();
        if ((float) $row['cost_ceiling_usd'] <= 0) {
            return false;
        }
        $pct = (float) $row['cost_used_usd'] / (float) $row['cost_ceiling_usd'];
        return $pct >= self::WARN_THRESHOLD_PCT && $pct < 1.0;
    }

    public static function dryRun(): bool
    {
        return (int) self::today()['dry_run'] === 1;
    }

    public static function addCost(float $usd): void
    {
        if ($usd <= 0) {
            return;
        }
        $today = date('Y-m-d');
        $db = DB::getInstance();
        self::today();
        $db->execute(
            'UPDATE guardrails SET cost_used_usd = cost_used_usd + ? WHERE date = ?',
            [$usd, $today]
        );
    }

    public static function setKillSwitch(bool $on): void
    {
        $today = date('Y-m-d');
        $db = DB::getInstance();
        self::today();
        $db->execute(
            'UPDATE guardrails SET kill_switch = ? WHERE date = ?',
            [$on ? 1 : 0, $today]
        );
    }

    public static function setDryRun(bool $on): void
    {
        $today = date('Y-m-d');
        $db = DB::getInstance();
        self::today();
        $db->execute(
            'UPDATE guardrails SET dry_run = ? WHERE date = ?',
            [$on ? 1 : 0, $today]
        );
    }
}
