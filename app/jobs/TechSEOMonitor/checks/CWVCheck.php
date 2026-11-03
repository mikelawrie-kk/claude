<?php
/**
 * File: app/jobs/TechSEOMonitor/checks/CWVCheck.php
 * Description: Core Web Vitals via Google PageSpeed Insights API. Needs an API key in jobs.config_json.pagespeed_api_key or site.gsc_property env. Skips gracefully if no key. Thresholds follow Google's "good" bands: LCP ≤ 2500ms, INP ≤ 200ms, CLS ≤ 0.1.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 09:55 SAST
 * Modified: 2026-11-03 09:55 SAST
 * Changes:
 *   1.0.0 (2026-11-03 09:55) — initial creation
 */

class CWVCheck extends CheckBase
{
    private const PSI_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';
    private const COST_PER_CALL = 0.0; // free tier

    public function checkName(): string
    {
        return 'CWV';
    }

    public function run(): array
    {
        $apiKey = (string) ($this->jobConfig['pagespeed_api_key'] ?? '');
        if ($apiKey === '') {
            Logger::info('CWVCheck skipped — no pagespeed_api_key configured', [
                'site' => $this->site['domain'] ?? '',
            ]);
            return $this->stats();
        }

        foreach (['mobile', 'desktop'] as $strategy) {
            $this->auditStrategy($strategy, $apiKey);
        }
        return $this->stats();
    }

    private function auditStrategy(string $strategy, string $apiKey): void
    {
        $home = $this->siteUrl('/');
        $url = self::PSI_ENDPOINT . '?' . http_build_query([
            'url' => $home,
            'strategy' => $strategy,
            'category' => 'performance',
            'key' => $apiKey,
        ]);
        try {
            $data = APIClient::getJson($url, ['Accept' => 'application/json'], self::COST_PER_CALL);
        } catch (Throwable $e) {
            Logger::warn('CWVCheck PSI call failed', [
                'strategy' => $strategy,
                'err' => $e->getMessage(),
            ]);
            return;
        }

        $loading = $data['loadingExperience']['metrics'] ?? [];
        $labMetrics = $data['lighthouseResult']['audits'] ?? [];

        // Prefer field data (loadingExperience) over lab if present
        $lcp = $this->fieldOrLab($loading, 'LARGEST_CONTENTFUL_PAINT_MS', $labMetrics['largest-contentful-paint']['numericValue'] ?? null);
        $inp = $this->fieldOrLab($loading, 'INTERACTION_TO_NEXT_PAINT', null);
        $cls = $this->fieldOrLab($loading, 'CUMULATIVE_LAYOUT_SHIFT_SCORE', $labMetrics['cumulative-layout-shift']['numericValue'] ?? null, true);

        if ($lcp !== null && $lcp > 2500) {
            $this->upsertFinding([
                'url' => $home,
                'severity' => $lcp > 4000 ? 'high' : 'medium',
                'issue' => "Largest Contentful Paint on $strategy is " . round($lcp) . 'ms (target ≤ 2500ms)',
                'recommendation' => 'Optimise above-the-fold images, preload the LCP element, serve WebP, and defer non-critical CSS. PageSpeed Insights report: https://pagespeed.web.dev/report?url=' . urlencode($home),
                'fix_estimated_seconds' => 3600,
            ]);
        }
        if ($inp !== null && $inp > 200) {
            $this->upsertFinding([
                'url' => $home,
                'severity' => $inp > 500 ? 'high' : 'medium',
                'issue' => "Interaction to Next Paint on $strategy is " . round($inp) . 'ms (target ≤ 200ms)',
                'recommendation' => 'Break long JavaScript tasks, defer third-party scripts, and audit input handlers. Check PageSpeed Insights for the slowest interaction.',
                'fix_estimated_seconds' => 3600,
            ]);
        }
        if ($cls !== null && $cls > 0.1) {
            $this->upsertFinding([
                'url' => $home,
                'severity' => $cls > 0.25 ? 'high' : 'medium',
                'issue' => "Cumulative Layout Shift on $strategy is " . round((float) $cls, 3) . ' (target ≤ 0.1)',
                'recommendation' => 'Set explicit width/height on images, reserve space for ads and embeds, and avoid injecting content above existing content.',
                'fix_estimated_seconds' => 1800,
            ]);
        }
    }

    private function fieldOrLab(array $loading, string $key, $labValue, bool $divideBy100 = false)
    {
        $field = $loading[$key]['percentile'] ?? null;
        if (is_numeric($field)) {
            return $divideBy100 ? ((float) $field / 100) : (float) $field;
        }
        return is_numeric($labValue) ? (float) $labValue : null;
    }
}
