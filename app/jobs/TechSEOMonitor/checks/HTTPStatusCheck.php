<?php
/**
 * File: app/jobs/TechSEOMonitor/checks/HTTPStatusCheck.php
 * Description: Status-code sweep. Fetches homepage + sitemap.xml and flags 4xx/5xx responses.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 09:30 SAST
 * Modified: 2026-11-03 09:30 SAST
 * Changes:
 *   1.0.0 (2026-11-03 09:30) — initial creation
 */

class HTTPStatusCheck extends CheckBase
{
    public function checkName(): string
    {
        return 'HTTPStatus';
    }

    public function run(): array
    {
        $targets = [
            ['path' => '/', 'critical' => true, 'label' => 'homepage'],
            ['path' => '/sitemap.xml', 'critical' => false, 'label' => 'sitemap'],
            ['path' => '/robots.txt', 'critical' => false, 'label' => 'robots.txt'],
        ];

        foreach ($targets as $t) {
            $url = $this->siteUrl($t['path']);
            $res = $this->fetchUrl($url, 'GET', 15);
            if ($res === null) {
                $this->upsertFinding([
                    'url' => $url,
                    'severity' => $t['critical'] ? 'critical' : 'medium',
                    'issue' => "Could not reach {$t['label']}",
                    'recommendation' => "The {$t['label']} at $url is unreachable (DNS, TLS or timeout). Verify the host responds.",
                    'fix_target_path' => $t['path'],
                    'fix_estimated_seconds' => 300,
                ]);
                continue;
            }
            $status = $res['status'];
            if ($status >= 200 && $status < 300) {
                continue;
            }
            if ($status >= 300 && $status < 400) {
                continue; // RedirectCheck handles redirect quality
            }
            $severity = $status >= 500 ? 'critical' : ($t['critical'] ? 'high' : 'medium');
            $this->upsertFinding([
                'url' => $url,
                'severity' => $severity,
                'issue' => "{$t['label']} returned HTTP $status",
                'recommendation' => "URL $url returned HTTP $status. Investigate server config or restore the resource.",
                'fix_target_path' => $t['path'],
                'fix_estimated_seconds' => 600,
            ]);
        }

        return $this->stats();
    }
}
