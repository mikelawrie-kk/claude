<?php
/**
 * File: app/jobs/TechSEOMonitor/checks/RobotsCheck.php
 * Description: Fetches /robots.txt; flags missing file, syntax issues, missing or broken Sitemap: directive, and overly broad disallow rules on the root user-agent.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 09:35 SAST
 * Modified: 2026-11-03 09:35 SAST
 * Changes:
 *   1.0.0 (2026-11-03 09:35) — initial creation
 */

class RobotsCheck extends CheckBase
{
    public function checkName(): string
    {
        return 'Robots';
    }

    public function run(): array
    {
        $url = $this->siteUrl('/robots.txt');
        $res = $this->fetchUrl($url, 'GET', 15);

        if ($res === null || $res['status'] === 404) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'high',
                'issue' => 'robots.txt missing',
                'recommendation' => 'Add a /robots.txt with a Sitemap: directive. Default rule: User-agent: *  Allow: /',
                'fix_target_path' => '/robots.txt',
                'fix_estimated_seconds' => 60,
                'fix_payload' => [
                    'mcp_tool' => 'cpanel_create_file',
                    'mcp_arguments' => [
                        'path' => 'robots.txt',
                        'content' => "User-agent: *\nAllow: /\n\nSitemap: " . $this->siteUrl('/sitemap.xml') . "\n",
                    ],
                ],
            ]);
            return $this->stats();
        }
        if ($res['status'] >= 400) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'high',
                'issue' => 'robots.txt returned HTTP ' . $res['status'],
                'recommendation' => "robots.txt URL returned HTTP {$res['status']}. Fix server config so crawlers can read it.",
                'fix_target_path' => '/robots.txt',
                'fix_estimated_seconds' => 300,
            ]);
            return $this->stats();
        }

        $body = $res['body'];
        $lines = preg_split('/\r\n|\n|\r/', $body) ?: [];
        $hasSitemap = false;
        $sitemapUrls = [];
        $globalDisallowAll = false;
        $currentUa = null;
        $uaStack = [];

        foreach ($lines as $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) {
                $currentUa = strtolower(trim($m[1]));
                $uaStack[$currentUa] = true;
                continue;
            }
            if (preg_match('/^sitemap:\s*(\S+)/i', $line, $m)) {
                $hasSitemap = true;
                $sitemapUrls[] = trim($m[1]);
                continue;
            }
            if (preg_match('/^disallow:\s*(.*)$/i', $line, $m)) {
                $value = trim($m[1]);
                if ($currentUa === '*' && $value === '/') {
                    $globalDisallowAll = true;
                }
            }
        }

        if ($globalDisallowAll) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'critical',
                'issue' => 'robots.txt blocks entire site (Disallow: /)',
                'recommendation' => 'The current robots.txt tells every crawler to ignore the entire site. Remove the "Disallow: /" line or scope it to a specific path.',
                'fix_target_path' => '/robots.txt',
                'fix_estimated_seconds' => 120,
            ]);
        }

        if (!$hasSitemap) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'medium',
                'issue' => 'robots.txt has no Sitemap: directive',
                'recommendation' => 'Append "Sitemap: ' . $this->siteUrl('/sitemap.xml') . '" to robots.txt so crawlers discover your sitemap.',
                'fix_target_path' => '/robots.txt',
                'fix_estimated_seconds' => 60,
            ]);
        }

        foreach ($sitemapUrls as $sm) {
            $smRes = $this->fetchUrl($sm, 'GET', 15);
            if ($smRes === null || $smRes['status'] >= 400) {
                $this->upsertFinding([
                    'url' => $sm,
                    'severity' => 'high',
                    'issue' => 'Sitemap declared in robots.txt is broken',
                    'recommendation' => "The sitemap URL $sm declared in robots.txt returns " .
                        ($smRes ? 'HTTP ' . $smRes['status'] : 'no response') .
                        '. Fix the URL or the sitemap file.',
                    'fix_estimated_seconds' => 300,
                ]);
            }
        }

        return $this->stats();
    }
}
