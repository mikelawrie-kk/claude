<?php
/**
 * File: app/jobs/TechSEOMonitor/checks/RedirectCheck.php
 * Description: Follows the redirect chain on homepage + www/non-www variants. Flags chains >1 hop, 302s where 301 is expected, loops, and http→https-missing.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 09:40 SAST
 * Modified: 2026-11-03 09:40 SAST
 * Changes:
 *   1.0.0 (2026-11-03 09:40) — initial creation
 */

class RedirectCheck extends CheckBase
{
    private const MAX_HOPS = 6;

    public function checkName(): string
    {
        return 'Redirect';
    }

    public function run(): array
    {
        $domain = (string) $this->site['domain'];
        $variants = [
            'http://' . $domain . '/',
            'http://www.' . $domain . '/',
            'https://www.' . $domain . '/',
        ];

        foreach ($variants as $start) {
            $this->traceChain($start);
        }
        return $this->stats();
    }

    private function traceChain(string $start): void
    {
        $chain = [];
        $url = $start;
        $hops = 0;
        $seen = [];

        while ($hops < self::MAX_HOPS) {
            if (isset($seen[$url])) {
                $this->upsertFinding([
                    'url' => $start,
                    'severity' => 'high',
                    'issue' => 'Redirect loop',
                    'recommendation' => "Starting at $start the redirects loop: " . implode(' → ', $chain) . " → $url. Break the loop in the server/.htaccess config.",
                    'fix_estimated_seconds' => 600,
                ]);
                return;
            }
            $seen[$url] = true;
            $res = $this->fetchUrl($url, 'GET', 15);
            if ($res === null) {
                return;
            }
            $status = $res['status'];
            $chain[] = "[$status] $url";

            if ($status < 300 || $status >= 400) {
                break;
            }
            $location = $res['headers']['location'] ?? '';
            if ($location === '') {
                $this->upsertFinding([
                    'url' => $url,
                    'severity' => 'medium',
                    'issue' => "Redirect $status with no Location header",
                    'recommendation' => "URL $url returned HTTP $status but no Location header. Fix the redirect config.",
                    'fix_estimated_seconds' => 600,
                ]);
                return;
            }
            if ($status === 302 && $hops === 0) {
                $this->upsertFinding([
                    'url' => $url,
                    'severity' => 'low',
                    'issue' => '302 (temporary) used instead of 301',
                    'recommendation' => "$url uses 302 (temporary redirect). For permanent canonicalisation (http→https or www→non-www), use 301 so the equity transfers.",
                    'fix_estimated_seconds' => 300,
                ]);
            }

            if (str_starts_with($location, '/')) {
                $parts = parse_url($url);
                $url = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . $location;
            } else {
                $url = $location;
            }
            $hops++;
        }

        if ($hops > 1) {
            $this->upsertFinding([
                'url' => $start,
                'severity' => 'medium',
                'issue' => "Redirect chain is $hops hops",
                'recommendation' => "Chain starting at $start: " . implode(' → ', $chain) . ". Collapse to a single 301 to the canonical URL.",
                'fix_estimated_seconds' => 600,
            ]);
        }

        if ($hops >= self::MAX_HOPS) {
            $this->upsertFinding([
                'url' => $start,
                'severity' => 'high',
                'issue' => "Redirect chain exceeded " . self::MAX_HOPS . ' hops',
                'recommendation' => "The chain from $start is too long. Collapse to a single 301 to the canonical URL.",
                'fix_estimated_seconds' => 900,
            ]);
        }

        if (str_starts_with($start, 'http://') && !isset($seen['https://' . $this->site['domain'] . '/']) && !isset($seen['https://www.' . $this->site['domain'] . '/'])) {
            // chain ended without reaching https — insecure
            $this->upsertFinding([
                'url' => $start,
                'severity' => 'high',
                'issue' => 'HTTP does not redirect to HTTPS',
                'recommendation' => "$start does not end at an HTTPS URL. Add a 301 from http://… to https://… to force TLS.",
                'fix_target_path' => '.htaccess',
                'fix_estimated_seconds' => 600,
            ]);
        }
    }
}
