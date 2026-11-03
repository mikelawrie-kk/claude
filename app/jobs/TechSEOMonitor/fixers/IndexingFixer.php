<?php
/**
 * File: app/jobs/TechSEOMonitor/fixers/IndexingFixer.php
 * Description: Fixer for the Indexing check. Handles the two cases Pulse can auto-fix: canonical missing (inserts <link rel="canonical"> into homepage <head>) and empty canonical href (fills it in). The X-Robots-Tag and meta-noindex cases are diagnostic — removing a noindex is a semantic decision the human must own. sitemap.xml missing is also skipped; auto-generating a sitemap needs to walk the site, which belongs in a dedicated SitemapGenerator (v1.2).
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 14:15 SAST
 * Modified: 2026-11-03 14:15 SAST
 * Changes:
 *   1.0.0 (2026-11-03 14:15) — initial creation
 */

class IndexingFixer extends FixerBase
{
    public function handles(): string
    {
        return 'Indexing';
    }

    public function generate(array $finding): ?array
    {
        $issue = (string) $finding['issue'];

        if ($issue === 'Homepage missing <link rel="canonical">') {
            return $this->insertCanonical();
        }
        if ($issue === 'Empty canonical href on homepage') {
            return $this->fillEmptyCanonical();
        }

        // noindex / nofollow / X-Robots-Tag / sitemap missing / sitemap URL 404 → diagnostic only
        return null;
    }

    private function homepageFile(): ?string
    {
        // cPanel default indexes: index.html, index.htm, index.php (in that order).
        // Try them in order until one is found.
        foreach (['index.html', 'index.htm', 'index.php'] as $candidate) {
            $content = $this->readSiteFile($candidate);
            if ($content !== null && $content !== '') {
                return $candidate;
            }
        }
        return null;
    }

    private function insertCanonical(): ?array
    {
        $file = $this->homepageFile();
        if ($file === null) {
            return null;
        }
        $current = $this->readSiteFile($file);
        if ($current === null) {
            return null;
        }
        $home = $this->siteUrl('/');
        $tag = '<link rel="canonical" href="' . htmlspecialchars($home, ENT_QUOTES, 'UTF-8') . '">';

        // Insert before </head> (case-insensitive, first occurrence).
        $pos = stripos($current, '</head>');
        if ($pos === false) {
            return null;
        }
        $new = substr($current, 0, $pos) . "  $tag\n" . substr($current, $pos);
        return $this->writeFilePayload($file, $new);
    }

    private function fillEmptyCanonical(): ?array
    {
        $file = $this->homepageFile();
        if ($file === null) {
            return null;
        }
        $current = $this->readSiteFile($file);
        if ($current === null) {
            return null;
        }
        $home = $this->siteUrl('/');
        $new = preg_replace(
            '#(<link[^>]*\brel\s*=\s*["\']canonical["\'][^>]*\bhref\s*=\s*["\'])\s*(["\'])#i',
            '$1' . htmlspecialchars($home, ENT_QUOTES, 'UTF-8') . '$2',
            $current,
            1,
            $count
        );
        if ($count === 0 || $new === null) {
            return null;
        }
        return $this->writeFilePayload($file, $new);
    }
}
