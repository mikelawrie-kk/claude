<?php
/**
 * File: app/core/ApplyRouter.php
 * Description: Routes an approved finding's fix_payload_json to the correct MCP bridge for that site, records the application, schedules +1h/+24h/+7d verification rows.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-05-14 09:42 SAST
 * Modified: 2026-05-14 09:42 SAST
 * Changes:
 *   1.0.0 (2026-05-14 09:42) — initial creation (spine; fixer modules wire in Week 3)
 */

class ApplyRouter
{
    /**
     * Apply an approved finding via its site's MCP bridge.
     * Expects findings.fix_payload_json to be a JSON object with:
     *   { "mcp_tool": "<tool_name>", "mcp_arguments": { ... } }
     * Returns true on success, false on failure (failure detail in fix_applications row).
     */
    public static function apply(int $findingId): bool
    {
        $db = DB::getInstance();
        $finding = $db->fetchOne('SELECT * FROM findings WHERE id = ?', [$findingId]);
        if ($finding === null) {
            throw new RuntimeException("ApplyRouter: finding $findingId not found");
        }
        if ($finding['fix_payload_json'] === null || $finding['fix_payload_json'] === '') {
            throw new RuntimeException("ApplyRouter: finding $findingId has no fix_payload_json (A2 violation)");
        }
        if ($finding['status'] === 'applied' || $finding['status'] === 'verified') {
            Logger::info('ApplyRouter skip already applied', ['finding_id' => $findingId]);
            return true;
        }
        if ($finding['approved_at'] === null) {
            throw new RuntimeException("ApplyRouter: finding $findingId not approved");
        }

        $site = $db->fetchOne('SELECT * FROM sites WHERE id = ?', [$finding['site_id']]);
        if ($site === null) {
            throw new RuntimeException("ApplyRouter: site {$finding['site_id']} not found");
        }
        if (empty($site['mcp_bridge_url'])) {
            throw new RuntimeException("ApplyRouter: site {$site['domain']} has no mcp_bridge_url");
        }

        $payload = json_decode($finding['fix_payload_json'], true);
        if (!is_array($payload) || empty($payload['mcp_tool'])) {
            throw new RuntimeException("ApplyRouter: finding $findingId fix_payload_json missing mcp_tool");
        }
        $toolName = (string) $payload['mcp_tool'];
        $arguments = $payload['mcp_arguments'] ?? [];

        $db->execute(
            'INSERT INTO fix_applications (finding_id, mcp_bridge, mcp_tool, request_payload_json, status) VALUES (?, ?, ?, ?, ?)',
            [$findingId, $site['mcp_bridge_url'], $toolName, json_encode($arguments, JSON_UNESCAPED_SLASHES), 'pending']
        );
        $appId = $db->lastInsertId();

        try {
            $result = MCPClient::call(
                (string) $site['mcp_bridge_url'],
                (string) ($site['mcp_bridge_key'] ?? ''),
                $toolName,
                is_array($arguments) ? $arguments : []
            );

            $db->execute(
                'UPDATE fix_applications SET status = ?, response_payload_json = ? WHERE id = ?',
                ['success', json_encode($result, JSON_UNESCAPED_SLASHES), $appId]
            );
            $db->execute(
                'UPDATE findings SET status = ?, applied_at = CURRENT_TIMESTAMP WHERE id = ?',
                ['applied', $findingId]
            );
            self::scheduleVerifications($findingId);

            Logger::info('ApplyRouter success', [
                'finding_id' => $findingId,
                'site' => $site['domain'],
                'tool' => $toolName,
            ]);
            return true;
        } catch (Throwable $e) {
            $db->execute(
                'UPDATE fix_applications SET status = ?, error_message = ? WHERE id = ?',
                ['failed', $e->getMessage(), $appId]
            );
            Logger::error('ApplyRouter failed', [
                'finding_id' => $findingId,
                'tool' => $toolName,
                'err' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private static function scheduleVerifications(int $findingId): void
    {
        $db = DB::getInstance();
        $now = time();
        $offsets = [
            '+1h' => 3600,
            '+24h' => 86400,
            '+7d' => 86400 * 7,
        ];
        foreach ($offsets as $label => $sec) {
            $when = date('Y-m-d H:i:s', $now + $sec);
            $db->execute(
                'INSERT INTO verifications (finding_id, scheduled_for, notes) VALUES (?, ?, ?)',
                [$findingId, $when, "scheduled $label"]
            );
        }
    }
}
