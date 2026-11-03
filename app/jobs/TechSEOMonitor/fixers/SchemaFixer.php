<?php
/**
 * File: app/jobs/TechSEOMonitor/fixers/SchemaFixer.php
 * Description: The headline fixer. Takes schema findings (any of the 12 sub-checks produced by SchemaCheck) and generates a single whole-file replacement for the homepage that merges every pending fix into the existing JSON-LD block. One run can resolve multiple schema findings in one approval. Strategy: read current homepage, extract existing JSON-LD, deep-walk, apply every pending patch, serialise back, splice into HTML, emit cpanel_write_file payload. If no JSON-LD exists at all, emit a brand-new block built entirely from site_entity.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 14:25 SAST
 * Modified: 2026-11-03 14:25 SAST
 * Changes:
 *   1.0.0 (2026-11-03 14:25) — initial creation
 */

class SchemaFixer extends FixerBase
{
    private ?string $homepageFile = null;
    private ?string $homepageHtml = null;
    private ?array $existingBlock = null;
    private ?int $blockStart = null;
    private ?int $blockEnd = null;

    public function handles(): string
    {
        return 'Schema';
    }

    /**
     * SchemaFixer coalesces all open schema findings into ONE payload. The first call generates the payload and
     * attaches it to that finding; subsequent calls for the same site mark their findings as fixed-by sibling and
     * skip. Caller (FixGenerator) is responsible for passing findings in a consistent order.
     */
    public function generate(array $finding): ?array
    {
        if (!$this->loadHomepage()) {
            return null;
        }

        $pending = $this->loadAllOpenSchemaFindings();
        if (empty($pending)) {
            return null;
        }

        if ($this->existingBlock === null) {
            $block = $this->buildBlockFromEntity();
            if ($block === null) {
                return null;
            }
        } else {
            $block = $this->existingBlock;
            foreach ($pending as $f) {
                $block = $this->applyFinding($block, $f);
            }
        }

        $jsonLd = "<script type=\"application/ld+json\">\n" .
            json_encode($block, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) .
            "\n</script>";

        if ($this->blockStart !== null && $this->blockEnd !== null) {
            $newHtml = substr($this->homepageHtml, 0, $this->blockStart) .
                $jsonLd .
                substr($this->homepageHtml, $this->blockEnd);
        } else {
            // Insert before </head>
            $pos = stripos($this->homepageHtml, '</head>');
            if ($pos === false) {
                return null;
            }
            $newHtml = substr($this->homepageHtml, 0, $pos) .
                "  $jsonLd\n" .
                substr($this->homepageHtml, $pos);
        }

        return $this->writeFilePayload($this->homepageFile, $newHtml);
    }

    private function loadHomepage(): bool
    {
        if ($this->homepageHtml !== null) {
            return true;
        }
        foreach (['index.html', 'index.htm', 'index.php'] as $candidate) {
            $content = $this->readSiteFile($candidate);
            if ($content !== null && $content !== '') {
                $this->homepageFile = $candidate;
                $this->homepageHtml = $content;
                $this->extractExistingBlock();
                return true;
            }
        }
        return false;
    }

    private function extractExistingBlock(): void
    {
        if (!preg_match(
            '#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is',
            $this->homepageHtml,
            $m,
            PREG_OFFSET_CAPTURE
        )) {
            return;
        }
        $this->blockStart = $m[0][1];
        $this->blockEnd = $m[0][1] + strlen($m[0][0]);
        $json = trim(html_entity_decode($m[1][0], ENT_QUOTES, 'UTF-8'));
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $this->existingBlock = $decoded;
        }
    }

    private function loadAllOpenSchemaFindings(): array
    {
        $db = DB::getInstance();
        return $db->fetchAll(
            "SELECT * FROM findings WHERE site_id = ? AND check_type = 'Schema' AND status = 'open'",
            [$this->site['id']]
        );
    }

    private function buildBlockFromEntity(): ?array
    {
        $home = $this->siteUrl('/');
        return $this->applyAllEntityFields([
            '@context' => 'https://schema.org',
            '@type' => $this->entity('entity_type') ?: 'LodgingBusiness',
            '@id' => rtrim($home, '/') . '/#hotel',
            'name' => $this->site['domain'],
            'url' => $home,
        ]);
    }

    /**
     * Apply a single finding's patch to the schema block. Each case here mirrors a check in SchemaCheck.
     */
    private function applyFinding(array $block, array $finding): array
    {
        $issue = (string) $finding['issue'];
        $home = $this->siteUrl('/');

        if (str_contains($issue, 'no @id anchor')) {
            $block['@id'] = rtrim($home, '/') . '/#hotel';
        }

        if (str_contains($issue, 'Generic @type "LodgingBusiness"')) {
            $block['@type'] = (string) ($this->entity('entity_type') ?: 'BoutiqueHotel');
        }

        if (str_contains($issue, 'legal declarations')) {
            if (empty($block['legalName']) && $this->entity('legal_name')) {
                $block['legalName'] = $this->entity('legal_name');
            }
            if (empty($block['foundingDate']) && $this->entity('founding_date')) {
                $block['foundingDate'] = (string) $this->entity('founding_date');
            }
            if (empty($block['alternateName']) && is_array($this->entityJson('alternate_names'))) {
                $block['alternateName'] = $this->entityJson('alternate_names');
            }
        }

        if (str_contains($issue, 'expertise fields')) {
            if (empty($block['knowsAbout']) && is_array($this->entityJson('knows_about'))) {
                $block['knowsAbout'] = $this->entityJson('knows_about');
            }
            if (empty($block['slogan']) && $this->entity('slogan')) {
                $block['slogan'] = $this->entity('slogan');
            }
            if (empty($block['keywords']) && is_array($this->entityJson('keywords'))) {
                $block['keywords'] = implode(', ', $this->entityJson('keywords'));
            }
        }

        if (str_contains($issue, 'sameAs') || str_contains($issue, 'external listings')) {
            $block['sameAs'] = $this->mergeSameAs($block['sameAs'] ?? []);
        }

        if (str_contains($issue, 'founder nested entity') && $this->entityJson('founder')) {
            $block['founder'] = $this->entityJson('founder');
        }
        if (str_contains($issue, 'operator / parentOrganization') && $this->entityJson('operator')) {
            $block['parentOrganization'] = $this->entityJson('operator');
        }

        if (str_contains($issue, 'areaServed') && $this->entityJson('area_served')) {
            $block['areaServed'] = $this->entityJson('area_served');
        }

        if (str_contains($issue, 'aggregateRating + review[]') || str_contains($issue, 'aggregateRating')) {
            if (empty($block['aggregateRating']) && $this->entity('rating_value') && $this->entity('rating_count')) {
                $block['aggregateRating'] = [
                    '@type' => 'AggregateRating',
                    'ratingValue' => (string) $this->entity('rating_value'),
                    'reviewCount' => (int) $this->entity('rating_count'),
                    'bestRating' => '5',
                    'worstRating' => '1',
                ];
            }
        }

        if (str_contains($issue, 'ReserveAction')) {
            $block['potentialAction'] = [
                '@type' => 'ReserveAction',
                'target' => [
                    '@type' => 'EntryPoint',
                    'urlTemplate' => rtrim($home, '/') . '/book?checkin={checkinDate}&checkout={checkoutDate}',
                    'inLanguage' => 'en',
                    'actionPlatform' => [
                        'http://schema.org/DesktopWebPlatform',
                        'http://schema.org/MobileWebPlatform',
                    ],
                ],
                'result' => [
                    '@type' => 'LodgingReservation',
                    'name' => 'Book direct at ' . $this->site['domain'],
                ],
            ];
        }

        if (str_contains($issue, 'llm.txt exists but is not declared')) {
            $llmUrl = $this->entity('llm_txt_url') ?: $this->siteUrl('/llm.txt');
            $block['additionalProperty'] = $this->mergeAdditionalProperty(
                $block['additionalProperty'] ?? [],
                ['@type' => 'PropertyValue', 'name' => 'llmInfo', 'value' => $llmUrl]
            );
        }

        if (str_contains($issue, 'validFrom / priceValidUntil')) {
            $block = $this->stampOfferValidity($block);
        }

        return $block;
    }

    private function applyAllEntityFields(array $block): array
    {
        if ($this->entity('legal_name')) {
            $block['legalName'] = $this->entity('legal_name');
        }
        if ($this->entity('founding_date')) {
            $block['foundingDate'] = (string) $this->entity('founding_date');
        }
        $alt = $this->entityJson('alternate_names');
        if (is_array($alt) && !empty($alt)) {
            $block['alternateName'] = $alt;
        }
        if ($this->entity('slogan')) {
            $block['slogan'] = $this->entity('slogan');
        }
        if ($this->entity('description')) {
            $block['description'] = $this->entity('description');
        }
        $kw = $this->entityJson('keywords');
        if (is_array($kw) && !empty($kw)) {
            $block['keywords'] = implode(', ', $kw);
        }
        $ka = $this->entityJson('knows_about');
        if (is_array($ka) && !empty($ka)) {
            $block['knowsAbout'] = $ka;
        }
        $sameAs = $this->mergeSameAs([]);
        if (!empty($sameAs)) {
            $block['sameAs'] = $sameAs;
        }
        $founder = $this->entityJson('founder');
        if (is_array($founder)) {
            $block['founder'] = $founder;
        }
        $operator = $this->entityJson('operator');
        if (is_array($operator)) {
            $block['parentOrganization'] = $operator;
        }
        $area = $this->entityJson('area_served');
        if (is_array($area)) {
            $block['areaServed'] = $area;
        }
        if ($this->entity('rating_value') && $this->entity('rating_count')) {
            $block['aggregateRating'] = [
                '@type' => 'AggregateRating',
                'ratingValue' => (string) $this->entity('rating_value'),
                'reviewCount' => (int) $this->entity('rating_count'),
                'bestRating' => '5',
                'worstRating' => '1',
            ];
        }
        $llm = $this->entity('llm_txt_url');
        if ($llm) {
            $block['additionalProperty'] = [[
                '@type' => 'PropertyValue', 'name' => 'llmInfo', 'value' => $llm,
            ]];
        }
        return $block;
    }

    private function mergeSameAs(array $current): array
    {
        if (!is_array($current)) {
            $current = (is_string($current) && $current !== '') ? [$current] : [];
        }
        $extra = $this->entityJson('ota_urls', []);
        $socials = $this->entityJson('social_urls', []);
        if (is_array($socials)) {
            $extra = array_merge($extra, $socials);
        }
        $gbp = $this->entity('gbp_url');
        if ($gbp) {
            $extra[] = $gbp;
        }

        $out = $current;
        foreach ($extra as $url) {
            $found = false;
            foreach ($out as $u) {
                if (is_string($u) && strcasecmp(rtrim($u, '/'), rtrim((string) $url, '/')) === 0) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $out[] = $url;
            }
        }
        return array_values(array_filter($out));
    }

    private function mergeAdditionalProperty($current, array $toAdd): array
    {
        if (!is_array($current)) {
            $current = [];
        }
        if (isset($current['@type'])) {
            $current = [$current];
        }
        foreach ($current as $p) {
            if (is_array($p) && strtolower((string) ($p['name'] ?? '')) === strtolower($toAdd['name'])) {
                return $current;
            }
        }
        $current[] = $toAdd;
        return $current;
    }

    private function stampOfferValidity(array $block): array
    {
        $today = date('Y-m-d');
        $yearEnd = date('Y') . '-12-31';
        if (isset($block['makesOffer']) && is_array($block['makesOffer'])) {
            $block['makesOffer'] = $this->stampOfferList($block['makesOffer'], $today, $yearEnd);
        }
        if (isset($block['offers']) && is_array($block['offers'])) {
            $block['offers'] = $this->stampOfferList($block['offers'], $today, $yearEnd);
        }
        return $block;
    }

    private function stampOfferList(array $offers, string $validFrom, string $validUntil): array
    {
        if (isset($offers['@type'])) {
            if (empty($offers['validFrom'])) {
                $offers['validFrom'] = $validFrom;
            }
            if (empty($offers['priceValidUntil'])) {
                $offers['priceValidUntil'] = $validUntil;
            }
            return $offers;
        }
        foreach ($offers as $i => $o) {
            if (!is_array($o)) {
                continue;
            }
            if (empty($o['validFrom'])) {
                $o['validFrom'] = $validFrom;
            }
            if (empty($o['priceValidUntil'])) {
                $o['priceValidUntil'] = $validUntil;
            }
            $offers[$i] = $o;
        }
        return $offers;
    }
}
