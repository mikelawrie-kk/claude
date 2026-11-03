<?php
/**
 * File: app/jobs/TechSEOMonitor/Scorer.php
 * Description: Scores findings for the weekly digest. Ordering: critical > high > medium > low > info; within a severity, lower-risk-first (shorter fix_estimated_seconds).
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 09:20 SAST
 * Modified: 2026-11-03 09:20 SAST
 * Changes:
 *   1.0.0 (2026-11-03 09:20) — initial creation
 */

class Scorer
{
    public const SEVERITY_RANK = [
        'critical' => 100,
        'high' => 80,
        'medium' => 50,
        'low' => 20,
        'info' => 5,
    ];

    public static function priority(array $finding): int
    {
        $sev = strtolower((string) ($finding['severity'] ?? 'info'));
        $base = self::SEVERITY_RANK[$sev] ?? 1;
        $hasFix = !empty($finding['fix_payload_json']);
        if ($hasFix) {
            $base += 5;
        }
        $est = (int) ($finding['fix_estimated_seconds'] ?? 0);
        if ($est > 0 && $est < 30) {
            $base += 3;
        }
        return $base;
    }

    public static function sortDigest(array $findings): array
    {
        usort($findings, static function (array $a, array $b): int {
            $pa = self::priority($a);
            $pb = self::priority($b);
            if ($pa === $pb) {
                return ((int) ($a['fix_estimated_seconds'] ?? 999)) <=> ((int) ($b['fix_estimated_seconds'] ?? 999));
            }
            return $pb <=> $pa;
        });
        return $findings;
    }

    public static function groupByType(array $findings): array
    {
        $grouped = [];
        foreach ($findings as $f) {
            $t = (string) ($f['check_type'] ?? 'Other');
            $grouped[$t][] = $f;
        }
        return $grouped;
    }

    public static function minutesOfClicks(array $findings): float
    {
        $seconds = 0;
        foreach ($findings as $f) {
            $seconds += (int) ($f['fix_estimated_seconds'] ?? 15);
        }
        return round($seconds / 60, 1);
    }
}
