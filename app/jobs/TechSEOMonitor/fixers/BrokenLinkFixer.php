<?php
/**
 * File: app/jobs/TechSEOMonitor/fixers/BrokenLinkFixer.php
 * Description: Fixer for the BrokenLink check. Diagnostic-only — a 404 link needs the human to decide the correct target: remove the link, change it to a new URL, or add a 301 redirect from the old URL. Pulse cannot guess intent, so no payload.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 14:22 SAST
 * Modified: 2026-11-03 14:22 SAST
 * Changes:
 *   1.0.0 (2026-11-03 14:22) — initial creation
 */

class BrokenLinkFixer extends FixerBase
{
    public function handles(): string
    {
        return 'BrokenLink';
    }

    public function generate(array $finding): ?array
    {
        return null;
    }
}
