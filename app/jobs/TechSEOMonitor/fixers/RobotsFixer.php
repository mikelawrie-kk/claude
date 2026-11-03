<?php
/**
 * File: app/jobs/TechSEOMonitor/fixers/RobotsFixer.php
 * Description: Fixer for the Robots check. Handles: missing robots.txt (writes a sensible default), missing Sitemap: directive (appends), and global Disallow: / (rewrites by stripping that line).
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 14:05 SAST
 * Modified: 2026-11-03 14:05 SAST
 * Changes:
 *   1.0.0 (2026-11-03 14:05) — initial creation
 */

class RobotsFixer extends FixerBase
{
    public function handles(): string
    {
        return 'Robots';
    }

    public function generate(array $finding): ?array
    {
        $issue = (string) $finding['issue'];
        $sitemapLine = 'Sitemap: ' . $this->siteUrl('/sitemap.xml');

        if (str_contains($issue, 'missing')) {
            // Full replacement content
            $content = "User-agent: *\nAllow: /\n\n$sitemapLine\n";
            return $this->writeFilePayload('robots.txt', $content);
        }

        if (str_contains($issue, 'no Sitemap: directive')) {
            // Read current robots.txt and append the Sitemap: line
            $current = $this->readSiteFile('robots.txt');
            if ($current === null) {
                return null;
            }
            $newContent = rtrim($current, "\n") . "\n\n" . $sitemapLine . "\n";
            return $this->writeFilePayload('robots.txt', $newContent);
        }

        if (str_contains($issue, 'blocks entire site')) {
            $current = $this->readSiteFile('robots.txt');
            if ($current === null) {
                return null;
            }
            // Strip "Disallow: /" from the user-agent:* block only
            $lines = preg_split('/\r\n|\n|\r/', $current) ?: [];
            $inStarUa = false;
            $out = [];
            foreach ($lines as $line) {
                $trim = trim($line);
                if (preg_match('/^user-agent:\s*\*/i', $trim)) {
                    $inStarUa = true;
                    $out[] = $line;
                    continue;
                }
                if (preg_match('/^user-agent:/i', $trim)) {
                    $inStarUa = false;
                    $out[] = $line;
                    continue;
                }
                if ($inStarUa && preg_match('/^disallow:\s*\/\s*$/i', $trim)) {
                    // skip the line that blocks everything
                    continue;
                }
                $out[] = $line;
            }
            return $this->writeFilePayload('robots.txt', implode("\n", $out));
        }

        // Sitemap-declared-broken: can't auto-fix, needs human to produce a valid sitemap
        return null;
    }
}
