<?php
/**
 * File: app/jobs/TechSEOMonitor/checks/IndexingCheck.php
 * Description: Checks indexability signals. Scans homepage for noindex/nofollow meta tags, inspects X-Robots-Tag header, verifies a sitemap exists and is non-empty, and spot-checks that each URL in the sitemap resolves 2xx. GSC API integration lands in a later version — v1 relies on direct inspection.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 09:50 SAST
 * Modified: 2026-11-03 09:50 SAST
 * Changes:
 *   1.0.0 (2026-11-03 09:50) — initial creation
 */

class IndexingCheck extends CheckBase
{
    private const MAX_SITEMAP_URLS = 20;

    public function checkName(): string
    {
        return 'Indexing';
    }

    public function run(): array
    {
        $this->checkHomepageMeta();
        $this->checkSitemap();
        return $this->stats();
    }

    private function checkHomepageMeta(): void
    {
        $url = $this->siteUrl('/');
        $res = $this->fetchUrl($url, 'GET', 15);
        if ($res === null) {
            return;
        }

        // X-Robots-Tag header
        $xRobots = strtolower((string) ($res['headers']['x-robots-tag'] ?? ''));
        if (str_contains($xRobots, 'noindex')) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'critical',
                'issue' => 'Homepage sends X-Robots-Tag: noindex',
                'recommendation' => "The homepage HTTP response includes X-Robots-Tag: $xRobots. Remove the noindex directive from server/application config.",
                'fix_estimated_seconds' => 600,
            ]);
        }

        if ($res['status'] >= 400) {
            return;
        }
        $dom = $this->parseHtml($res['body']);
        if ($dom === null) {
            return;
        }

        foreach ($dom->getElementsByTagName('meta') as $m) {
            $name = strtolower((string) $m->getAttribute('name'));
            if ($name !== 'robots' && $name !== 'googlebot') {
                continue;
            }
            $content = strtolower((string) $m->getAttribute('content'));
            if (str_contains($content, 'noindex')) {
                $this->upsertFinding([
                    'url' => $url,
                    'severity' => 'critical',
                    'issue' => 'Homepage has <meta name="' . $name . '" content="noindex">',
                    'recommendation' => "Homepage declares <meta name=\"$name\" content=\"$content\">. Remove the noindex token from the meta tag in the template.",
                    'fix_target_path' => '/',
                    'fix_estimated_seconds' => 300,
                ]);
            }
            if (str_contains($content, 'nofollow') && $name === 'robots') {
                $this->upsertFinding([
                    'url' => $url,
                    'severity' => 'high',
                    'issue' => 'Homepage declares <meta name="robots" content="nofollow">',
                    'recommendation' => "Homepage tells crawlers not to follow any links. Remove nofollow from the robots meta tag.",
                    'fix_target_path' => '/',
                    'fix_estimated_seconds' => 300,
                ]);
            }
        }

        // Canonical sanity
        $canonicalFound = false;
        foreach ($dom->getElementsByTagName('link') as $l) {
            if (strtolower((string) $l->getAttribute('rel')) === 'canonical') {
                $canonicalFound = true;
                $href = trim((string) $l->getAttribute('href'));
                if ($href === '') {
                    $this->upsertFinding([
                        'url' => $url,
                        'severity' => 'medium',
                        'issue' => 'Empty canonical href on homepage',
                        'recommendation' => 'The homepage has <link rel="canonical"> with no href. Either remove it or set it to ' . $url . '.',
                        'fix_estimated_seconds' => 180,
                    ]);
                }
                break;
            }
        }
        if (!$canonicalFound) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'low',
                'issue' => 'Homepage missing <link rel="canonical">',
                'recommendation' => 'Add <link rel="canonical" href="' . $url . '"> to the homepage <head> to lock in the preferred URL for indexing.',
                'fix_target_path' => '/',
                'fix_estimated_seconds' => 180,
            ]);
        }
    }

    private function checkSitemap(): void
    {
        $url = $this->siteUrl('/sitemap.xml');
        $res = $this->fetchUrl($url, 'GET', 20);
        if ($res === null || $res['status'] === 404) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'high',
                'issue' => 'sitemap.xml missing',
                'recommendation' => 'Generate /sitemap.xml listing every indexable URL. Reference it from robots.txt with a Sitemap: directive.',
                'fix_target_path' => '/sitemap.xml',
                'fix_estimated_seconds' => 900,
            ]);
            return;
        }
        if ($res['status'] >= 400) {
            return; // HTTPStatusCheck handles this
        }

        $body = $res['body'];
        $urls = [];
        if (preg_match_all('#<loc>\s*(.*?)\s*</loc>#i', $body, $m)) {
            $urls = array_unique($m[1]);
        }
        if (empty($urls)) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'medium',
                'issue' => 'sitemap.xml has no <loc> entries',
                'recommendation' => 'The sitemap XML parses but contains no URLs. Regenerate it from the live site.',
                'fix_estimated_seconds' => 900,
            ]);
            return;
        }

        $sample = array_slice($urls, 0, self::MAX_SITEMAP_URLS);
        foreach ($sample as $u) {
            $r = $this->fetchUrl($u, 'HEAD', 10);
            if ($r === null) {
                continue;
            }
            if ($r['status'] >= 400) {
                $this->upsertFinding([
                    'url' => $u,
                    'severity' => $r['status'] === 404 ? 'high' : 'medium',
                    'issue' => "Sitemap URL returns HTTP {$r['status']}",
                    'recommendation' => "sitemap.xml lists $u but it returns HTTP {$r['status']}. Either remove it from the sitemap or restore the resource.",
                    'fix_estimated_seconds' => 300,
                ]);
            }
        }
    }
}
