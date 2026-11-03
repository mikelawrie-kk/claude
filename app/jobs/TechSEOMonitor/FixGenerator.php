<?php
/**
 * File: app/jobs/TechSEOMonitor/FixGenerator.php
 * Description: After the detection pass runs, for every open finding, dispatch to the fixer matching its check_type and attach the generated payload. SchemaFixer coalesces: the first schema finding generates a payload that resolves all open schema findings on that site, siblings mark 'bundled' so Mike approves once per site, not twelve times.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 14:35 SAST
 * Modified: 2026-11-03 14:35 SAST
 * Changes:
 *   1.0.0 (2026-11-03 14:35) — initial creation
 */

require_once __DIR__ . '/fixers/FixerBase.php';
require_once __DIR__ . '/fixers/HTTPStatusFixer.php';
require_once __DIR__ . '/fixers/RobotsFixer.php';
require_once __DIR__ . '/fixers/RedirectFixer.php';
require_once __DIR__ . '/fixers/BrokenLinkFixer.php';
require_once __DIR__ . '/fixers/IndexingFixer.php';
require_once __DIR__ . '/fixers/CWVFixer.php';
require_once __DIR__ . '/fixers/SchemaFixer.php';

class FixGenerator
{
    private const FIXER_CLASSES = [
        'HTTPStatus' => 'HTTPStatusFixer',
        'Robots' => 'RobotsFixer',
        'Redirect' => 'RedirectFixer',
        'BrokenLink' => 'BrokenLinkFixer',
        'Indexing' => 'IndexingFixer',
        'CWV' => 'CWVFixer',
        'Schema' => 'SchemaFixer',
    ];

    public static function generate(array $site, ?array $entity, array $jobConfig = []): array
    {
        $db = DB::getInstance();
        $findings = $db->fetchAll(
            "SELECT * FROM findings
             WHERE site_id = ? AND status = 'open' AND fix_payload_json IS NULL",
            [$site['id']]
        );

        $instances = [];
        foreach (self::FIXER_CLASSES as $checkType => $class) {
            $instances[$checkType] = new $class($site, $entity, $jobConfig);
        }

        $schemaBundledPrimary = null;

        foreach ($findings as $f) {
            $checkType = (string) $f['check_type'];
            if (!isset($instances[$checkType])) {
                continue;
            }
            $fixer = $instances[$checkType];

            // Schema findings coalesce: only the first produces a payload; the rest get bundled.
            if ($checkType === 'Schema') {
                if ($schemaBundledPrimary === null) {
                    $payload = $fixer->generate($f);
                    if ($payload !== null) {
                        $fixer->savePayload((int) $f['id'], $payload, 1200);
                        $schemaBundledPrimary = (int) $f['id'];
                    } else {
                        $fixer->skip((int) $f['id'], 'SchemaFixer could not build a payload (no homepage? no entity?)');
                    }
                } else {
                    self::markBundled((int) $f['id'], $schemaBundledPrimary);
                }
                continue;
            }

            $payload = $fixer->generate($f);
            if ($payload === null) {
                $fixer->skip((int) $f['id'], 'diagnostic-only or auto-apply unsafe');
                continue;
            }
            $fixer->savePayload((int) $f['id'], $payload);
        }

        $stats = [];
        foreach ($instances as $checkType => $fixer) {
            $stats[$checkType] = $fixer->stats();
        }
        return $stats;
    }

    private static function markBundled(int $findingId, int $primaryId): void
    {
        $db = DB::getInstance();
        $db->execute(
            "UPDATE findings SET
                fix_payload_json = ?,
                recommendation = recommendation || ?,
                last_seen = CURRENT_TIMESTAMP
             WHERE id = ?",
            [
                json_encode([
                    'mcp_tool' => 'noop',
                    'mcp_arguments' => (object) [],
                    'bundled_with' => $primaryId,
                    'note' => 'This finding is fixed by approving finding #' . $primaryId . '. Approving this one is a no-op.',
                ], JSON_UNESCAPED_SLASHES),
                "\n\n(Bundled with finding #$primaryId — approving that fix resolves this one too.)",
                $findingId,
            ]
        );
    }
}
