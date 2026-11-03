<?php
/**
 * File: app/jobs/TechSEOMonitor/fixers/RedirectFixer.php
 * Description: Fixer for the Redirect check. Handles http→https-missing by appending a conditional 301 rule to .htaccess. The .htaccess block is marked with # BEGIN/END Pulse-Redirects so later runs or human edits can be located. Chains and loops are diagnostic — they need human intent to resolve.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 14:10 SAST
 * Modified: 2026-11-03 14:10 SAST
 * Changes:
 *   1.0.0 (2026-11-03 14:10) — initial creation
 */

class RedirectFixer extends FixerBase
{
    public function handles(): string
    {
        return 'Redirect';
    }

    public function generate(array $finding): ?array
    {
        $issue = (string) $finding['issue'];

        if ($issue === 'HTTP does not redirect to HTTPS') {
            return $this->buildHttpsRedirectPayload();
        }

        // 302, chain, loop → diagnostic only. Each needs human review of what the correct canonical should be.
        return null;
    }

    private function buildHttpsRedirectPayload(): ?array
    {
        $current = $this->readSiteFile('.htaccess') ?? '';

        $marker = '# BEGIN Pulse-Redirects';
        if (str_contains($current, $marker)) {
            // Already managed by Pulse; nothing to do.
            return null;
        }

        $block = "\n# BEGIN Pulse-Redirects\n"
               . "<IfModule mod_rewrite.c>\n"
               . "  RewriteEngine On\n"
               . "  RewriteCond %{HTTPS} !=on\n"
               . "  RewriteRule ^(.*)$ https://%{HTTP_HOST}/\$1 [R=301,L]\n"
               . "</IfModule>\n"
               . "# END Pulse-Redirects\n";

        $newContent = rtrim($current, "\n") . $block;
        return $this->writeFilePayload('.htaccess', $newContent);
    }
}
