<?php
/**
 * File: app/jobs/TechSEOMonitor/fixers/HTTPStatusFixer.php
 * Description: Fixer for the HTTPStatus check. Diagnostic-only — 4xx/5xx on homepage/sitemap/robots.txt almost always indicates a server or application fault that Pulse cannot repair blindly.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 14:23 SAST
 * Modified: 2026-11-03 14:23 SAST
 * Changes:
 *   1.0.0 (2026-11-03 14:23) — initial creation
 */

class HTTPStatusFixer extends FixerBase
{
    public function handles(): string
    {
        return 'HTTPStatus';
    }

    public function generate(array $finding): ?array
    {
        return null;
    }
}
