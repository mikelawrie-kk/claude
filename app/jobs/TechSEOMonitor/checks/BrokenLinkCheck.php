<?php
/**
 * File: app/jobs/TechSEOMonitor/checks/BrokenLinkCheck.php
 * Description: Fetches the homepage, extracts all unique internal links, bounds the crawl at config.max_internal_links (default 100), HEADs each. Reports 4xx/5xx URLs. Keeps the budget small so a single run cannot thrash the site.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 09:45 SAST
 * Modified: 2026-11-03 09:45 SAST
 * Changes:
 *   1.0.0 (2026-11-03 09:45) — initial creation
 */

class BrokenLinkCheck extends CheckBase
{
    public function checkName(): string
    {
        return 'BrokenLink';
    }

    public function run(): array
    {
        $home = $this->siteUrl('/');
        $res = $this->fetchUrl($home, 'GET', 20);
        if ($res === null || $res['status'] >= 400) {
            return $this->stats(); // HTTPStatusCheck has already flagged it
        }

        $dom = $this->parseHtml($res['body']);
        if ($dom === null) {
            return $this->stats();
        }

        $max = (int) ($this->jobConfig['max_internal_links'] ?? 100);
        $links = [];
        foreach ($dom->getElementsByTagName('a') as $a) {
            $href = trim((string) $a->getAttribute('href'));
            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
                continue;
            }
            $abs = $this->absolutise($home, $href);
            if ($abs === null) {
                continue;
            }
            if (!$this->isSameHost($abs)) {
                continue;
            }
            $abs = strtok($abs, '#'); // drop fragment
            $links[$abs] = true;
            if (count($links) >= $max) {
                break;
            }
        }

        foreach (array_keys($links) as $link) {
            if ($link === $home) {
                continue;
            }
            $r = $this->fetchUrl($link, 'HEAD', 10);
            if ($r === null) {
                $this->upsertFinding([
                    'url' => $link,
                    'severity' => 'medium',
                    'issue' => 'Internal link unreachable',
                    'recommendation' => "Linked from homepage: $link cannot be reached (DNS, TLS or timeout). Either fix the server or remove/update the link.",
                    'fix_estimated_seconds' => 300,
                ]);
                continue;
            }
            // Some servers return 405 for HEAD; retry with GET before flagging
            if ($r['status'] === 405) {
                $r = $this->fetchUrl($link, 'GET', 10);
                if ($r === null) {
                    continue;
                }
            }
            if ($r['status'] >= 400 && $r['status'] < 500) {
                $this->upsertFinding([
                    'url' => $link,
                    'severity' => $r['status'] === 404 ? 'high' : 'medium',
                    'issue' => "Internal link returns HTTP {$r['status']}",
                    'recommendation' => "Homepage links to $link but it returns HTTP {$r['status']}. Either restore the page, change the link target, or add a 301 redirect.",
                    'fix_estimated_seconds' => 300,
                ]);
            } elseif ($r['status'] >= 500) {
                $this->upsertFinding([
                    'url' => $link,
                    'severity' => 'high',
                    'issue' => "Internal link returns HTTP {$r['status']}",
                    'recommendation' => "$link returned HTTP {$r['status']}. Investigate server config or application error.",
                    'fix_estimated_seconds' => 600,
                ]);
            }
        }

        return $this->stats();
    }

    private function absolutise(string $base, string $href): ?string
    {
        if (preg_match('#^https?://#i', $href)) {
            return $href;
        }
        $parts = parse_url($base);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';
        if ($host === '') {
            return null;
        }
        if (str_starts_with($href, '//')) {
            return $scheme . ':' . $href;
        }
        if (str_starts_with($href, '/')) {
            return $scheme . '://' . $host . $href;
        }
        // relative path
        $basePath = $parts['path'] ?? '/';
        $dir = rtrim(dirname($basePath), '/');
        return $scheme . '://' . $host . $dir . '/' . $href;
    }
}
