<?php
/**
 * File: app/jobs/TechSEOMonitor/fixers/CWVFixer.php
 * Description: Fixer for the Core Web Vitals check. v1 scope is deliberately narrow: PSI-identified problems need per-page analysis to fix properly (which LCP element, which script is blocking INP, which image is shifting). This fixer only emits structured guidance in the payload — a human still has to look at the PSI report and apply the right change. Marked as diagnostic.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 14:20 SAST
 * Modified: 2026-11-03 14:20 SAST
 * Changes:
 *   1.0.0 (2026-11-03 14:20) — initial creation (diagnostic-only in v1; auto-apply arrives when a dedicated PerfOptimizer module ships)
 */

class CWVFixer extends FixerBase
{
    public function handles(): string
    {
        return 'CWV';
    }

    public function generate(array $finding): ?array
    {
        // CWV fixes require per-page HTML analysis (which img is the LCP element,
        // which script is blocking main thread). Auto-apply is unsafe in v1.
        return null;
    }
}
