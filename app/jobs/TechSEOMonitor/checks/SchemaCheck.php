<?php
/**
 * File: app/jobs/TechSEOMonitor/checks/SchemaCheck.php
 * Description: The anti-OTA-usurpation check. 12 sub-checks grouped by what they defend against:
 *   A. Entity anchoring:        @id, specific @type, legal declarations, expertise domains
 *   B. sameAs inversion:        OTA URLs declared in sameAs so property becomes primary node
 *   C. Nested entity graph:     founder, operator, parentOrganization, areaServed
 *   D. Ownership of commercial signals: aggregateRating + review[], potentialAction ReserveAction
 *   E. AI-era:                  llm.txt declaration, temporal validity (datePublished / validFrom)
 * Reads JSON-LD blocks from the homepage, deep-walks the graph, and compares against site_entity data.
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 10:00 SAST
 * Modified: 2026-11-03 10:00 SAST
 * Changes:
 *   1.0.0 (2026-11-03 10:00) — initial creation
 */

class SchemaCheck extends CheckBase
{
    private const SPECIFIC_LODGING_TYPES = [
        'Hotel', 'BoutiqueHotel', 'BedAndBreakfast', 'Resort', 'LodgingBusiness',
        'Hostel', 'Motel', 'VacationRental', 'Campground', 'Lodge',
    ];

    private array $blocks = [];
    private array $nodes = [];
    private ?array $primary = null;

    public function checkName(): string
    {
        return 'Schema';
    }

    public function run(): array
    {
        $home = $this->siteUrl('/');
        $res = $this->fetchUrl($home, 'GET', 20);
        if ($res === null || $res['status'] >= 400) {
            return $this->stats();
        }
        $this->extractJsonLd($res['body']);

        if (empty($this->blocks)) {
            $this->upsertFinding([
                'url' => $home,
                'severity' => 'critical',
                'issue' => 'No JSON-LD schema on homepage',
                'recommendation' => 'Add a <script type="application/ld+json"> block to the homepage declaring the business entity with @id, @type, name, url, legalName, address, telephone, and sameAs. Without schema, OTAs own your knowledge-graph entity by default.',
                'fix_target_path' => '/',
                'fix_estimated_seconds' => 1800,
            ]);
            return $this->stats();
        }

        $this->primary = $this->findPrimaryEntity();

        // Group A — Entity anchoring
        $this->checkAtId($home);
        $this->checkSpecificType($home);
        $this->checkLegalDeclarations($home);
        $this->checkExpertiseDomains($home);

        // Group B — sameAs inversion
        $this->checkSameAs($home);

        // Group C — Nested entity graph
        $this->checkFounderAndOperator($home);
        $this->checkAreaServed($home);

        // Group D — Commercial signal ownership
        $this->checkAggregateRating($home);
        $this->checkReserveAction($home);

        // Group E — AI-era
        $this->checkLlmTxt($home);
        $this->checkTemporalValidity($home);
        $this->checkNoCompetingPrimary($home);

        return $this->stats();
    }

    // ============================================================
    // Sub-checks
    // ============================================================

    private function checkAtId(string $url): void
    {
        if ($this->primary === null) {
            return;
        }
        if (empty($this->primary['@id'])) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'critical',
                'issue' => 'Primary entity has no @id anchor',
                'recommendation' => "Add an \"@id\" to the primary entity, e.g. \"@id\": \"" . rtrim($url, '/') . "/#hotel\". The @id becomes your permanent address in the Knowledge Graph — without it the entity is just \"a page\", with it it is THE entity.",
                'fix_target_path' => '/',
                'fix_estimated_seconds' => 300,
            ]);
        }
    }

    private function checkSpecificType(string $url): void
    {
        if ($this->primary === null) {
            return;
        }
        $type = $this->primary['@type'] ?? '';
        if (is_array($type)) {
            $type = $type[0] ?? '';
        }
        if ($type === 'LodgingBusiness') {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'medium',
                'issue' => 'Generic @type "LodgingBusiness" — a more specific type is available',
                'recommendation' => 'Change @type from "LodgingBusiness" to the most specific match: Hotel, BoutiqueHotel, BedAndBreakfast, Resort, Hostel or VacationRental. OTAs can only tag listings as "Hotel"; using the specific type differentiates you.',
                'fix_target_path' => '/',
                'fix_estimated_seconds' => 120,
            ]);
        }
    }

    private function checkLegalDeclarations(string $url): void
    {
        if ($this->primary === null) {
            return;
        }
        $missing = [];
        foreach (['legalName', 'foundingDate', 'alternateName'] as $field) {
            if (empty($this->primary[$field])) {
                $missing[] = $field;
            }
        }
        if (!empty($missing)) {
            $entityLegalName = $this->entity('legal_name');
            $entityFounding = $this->entity('founding_date');
            $hints = [];
            if ($entityLegalName) {
                $hints[] = "legalName: \"$entityLegalName\"";
            }
            if ($entityFounding) {
                $hints[] = "foundingDate: \"$entityFounding\"";
            }
            $hint = $hints ? ' Known values from site_entity: ' . implode(', ', $hints) . '.' : '';
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'high',
                'issue' => 'Primary entity missing legal declarations: ' . implode(', ', $missing),
                'recommendation' => 'Add ' . implode(', ', $missing) . ' to the primary entity. These prove you are a registered business entity with history, which OTAs cannot claim.' . $hint,
                'fix_target_path' => '/',
                'fix_estimated_seconds' => 300,
            ]);
        }
    }

    private function checkExpertiseDomains(string $url): void
    {
        if ($this->primary === null) {
            return;
        }
        $hasKnows = !empty($this->primary['knowsAbout']);
        $hasSlogan = !empty($this->primary['slogan']);
        $hasKeywords = !empty($this->primary['keywords']);
        if ($hasKnows && $hasSlogan && $hasKeywords) {
            return;
        }
        $missing = array_filter([
            $hasKnows ? null : 'knowsAbout',
            $hasSlogan ? null : 'slogan',
            $hasKeywords ? null : 'keywords',
        ]);
        $this->upsertFinding([
            'url' => $url,
            'severity' => 'medium',
            'issue' => 'Primary entity missing expertise fields: ' . implode(', ', $missing),
            'recommendation' => 'Add ' . implode(', ', $missing) . ' to the primary entity so AI systems associate your domain expertise with you specifically. OTAs cannot claim your knowsAbout.',
            'fix_target_path' => '/',
            'fix_estimated_seconds' => 240,
        ]);
    }

    private function checkSameAs(string $url): void
    {
        if ($this->primary === null) {
            return;
        }
        $sameAs = $this->primary['sameAs'] ?? [];
        if (is_string($sameAs)) {
            $sameAs = [$sameAs];
        }
        if (!is_array($sameAs)) {
            $sameAs = [];
        }

        $expected = $this->entityJson('ota_urls', []);
        if (!is_array($expected)) {
            $expected = [];
        }
        $socials = $this->entityJson('social_urls', []);
        if (is_array($socials)) {
            $expected = array_merge($expected, $socials);
        }
        $gbp = $this->entity('gbp_url');
        if ($gbp) {
            $expected[] = $gbp;
        }
        $expected = array_values(array_filter(array_unique($expected)));

        if (empty($sameAs)) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'critical',
                'issue' => 'No sameAs on primary entity (OTA sameAs inversion missing)',
                'recommendation' => 'Add a "sameAs" array to the primary entity listing every OTA listing (Booking, Expedia, Agoda, TripAdvisor, Airbnb, Google Hotels, LekkeSlaap, SafariNow), the Google Business Profile, and major social URLs. This flips the relationship: OTAs become citations of YOU, not vice versa.' .
                    (empty($expected) ? '' : ' Candidates in site_entity: ' . implode(', ', $expected)),
                'fix_target_path' => '/',
                'fix_estimated_seconds' => 600,
            ]);
            return;
        }

        $missing = [];
        foreach ($expected as $candidate) {
            $found = false;
            foreach ($sameAs as $actual) {
                if (is_string($actual) && strcasecmp(rtrim($actual, '/'), rtrim($candidate, '/')) === 0) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $missing[] = $candidate;
            }
        }
        if (!empty($missing)) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'high',
                'issue' => count($missing) . ' known external listings missing from sameAs',
                'recommendation' => 'Append these to the primary entity sameAs array: ' . implode(', ', $missing) . '. Every missing one is a knowledge-graph citation you are leaving on the table.',
                'fix_target_path' => '/',
                'fix_estimated_seconds' => 180,
            ]);
        }
    }

    private function checkFounderAndOperator(string $url): void
    {
        if ($this->primary === null) {
            return;
        }
        $hasFounder = !empty($this->primary['founder']);
        $hasOperator = !empty($this->primary['operator']) || !empty($this->primary['employee']) || !empty($this->primary['parentOrganization']);
        $expectedFounder = $this->entityJson('founder', null);
        $expectedOperator = $this->entityJson('operator', null);

        if (!$hasFounder && $expectedFounder) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'high',
                'issue' => 'Missing founder nested entity',
                'recommendation' => 'Add a nested "founder" object: {"@type": "Person", "name": "' . ($expectedFounder['name'] ?? '') . '"}. OTAs do not know who founded your business — this relationship cannot be usurped.',
                'fix_target_path' => '/',
                'fix_estimated_seconds' => 180,
            ]);
        }
        if (!$hasOperator && $expectedOperator) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'high',
                'issue' => 'Missing operator / parentOrganization nested entity',
                'recommendation' => 'Add a nested "parentOrganization" or "employee" object naming the operator (e.g. ' . ($expectedOperator['name'] ?? '') . '). This encodes the operating chain OTAs cannot reproduce.',
                'fix_target_path' => '/',
                'fix_estimated_seconds' => 240,
            ]);
        }
    }

    private function checkAreaServed(string $url): void
    {
        if ($this->primary === null) {
            return;
        }
        if (!empty($this->primary['areaServed'])) {
            return;
        }
        $area = $this->entityJson('area_served', null);
        $hint = $area && isset($area['name']) ? " Suggested: areaServed: {\"@type\": \"Place\", \"name\": \"{$area['name']}\"}." : '';
        $this->upsertFinding([
            'url' => $url,
            'severity' => 'medium',
            'issue' => 'Primary entity missing areaServed',
            'recommendation' => 'Add an areaServed nested Place with containedInPlace for the region/province. Lets AI answer "where is X?" authoritatively.' . $hint,
            'fix_target_path' => '/',
            'fix_estimated_seconds' => 180,
        ]);
    }

    private function checkAggregateRating(string $url): void
    {
        if ($this->primary === null) {
            return;
        }
        $hasRating = !empty($this->primary['aggregateRating']);
        $hasReviews = !empty($this->primary['review']);
        if ($hasRating && $hasReviews) {
            return;
        }
        $ratingValue = $this->entity('rating_value');
        $ratingCount = $this->entity('rating_count');
        $hint = $ratingValue && $ratingCount
            ? " Known values: aggregateRating ratingValue=$ratingValue, ratingCount=$ratingCount."
            : '';
        $missing = array_filter([
            $hasRating ? null : 'aggregateRating',
            $hasReviews ? null : 'review[]',
        ]);
        $this->upsertFinding([
            'url' => $url,
            'severity' => 'medium',
            'issue' => 'Primary entity missing ' . implode(' + ', $missing) . ' (OTAs own your review signal)',
            'recommendation' => 'Declare your aggregateRating and a sample of recent reviews on the primary entity with an own-domain @id. This makes your reviews primary source and OTA reviews secondary.' . $hint,
            'fix_target_path' => '/',
            'fix_estimated_seconds' => 1200,
        ]);
    }

    private function checkReserveAction(string $url): void
    {
        if ($this->primary === null) {
            return;
        }
        $actions = $this->primary['potentialAction'] ?? [];
        if (is_array($actions) && isset($actions['@type'])) {
            $actions = [$actions]; // normalise single-action form
        }
        $hasReserve = false;
        foreach ((array) $actions as $a) {
            $t = $a['@type'] ?? '';
            if (is_array($t)) {
                $t = $t[0] ?? '';
            }
            if ($t === 'ReserveAction') {
                $hasReserve = true;
                break;
            }
        }
        if ($hasReserve) {
            return;
        }
        $this->upsertFinding([
            'url' => $url,
            'severity' => 'high',
            'issue' => 'No potentialAction/ReserveAction (OTA owns your booking button)',
            'recommendation' => 'Add a potentialAction with @type ReserveAction and a target EntryPoint urlTemplate pointing to your own booking URL. This tells Google bookings happen on YOUR site, can trigger "Book Direct" buttons in SERPs, and demotes OTA booking widgets to "also available on".',
            'fix_target_path' => '/',
            'fix_estimated_seconds' => 900,
        ]);
    }

    private function checkLlmTxt(string $url): void
    {
        if ($this->primary === null) {
            return;
        }
        $hasLlmProp = false;
        foreach ((array) ($this->primary['additionalProperty'] ?? []) as $prop) {
            if (!is_array($prop)) {
                continue;
            }
            $name = strtolower((string) ($prop['name'] ?? ''));
            if ($name === 'llminfo' || $name === 'llm_info' || str_contains((string) ($prop['value'] ?? ''), 'llm.txt')) {
                $hasLlmProp = true;
                break;
            }
        }
        $llmTxtUrl = $this->siteUrl('/llm.txt');
        $llmRes = $this->fetchUrl($llmTxtUrl, 'HEAD', 10);
        $llmTxtExists = $llmRes !== null && $llmRes['status'] >= 200 && $llmRes['status'] < 400;

        if (!$llmTxtExists) {
            $this->upsertFinding([
                'url' => $llmTxtUrl,
                'severity' => 'low',
                'issue' => '/llm.txt missing',
                'recommendation' => 'Publish an /llm.txt listing the canonical facts you want AI assistants to cite (property name, location, offers, rates, policy summary, FAQ links). Then declare it in schema via additionalProperty.',
                'fix_target_path' => '/llm.txt',
                'fix_estimated_seconds' => 1800,
            ]);
        }
        if ($llmTxtExists && !$hasLlmProp) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'low',
                'issue' => 'llm.txt exists but is not declared in schema',
                'recommendation' => 'Add additionalProperty: {"@type": "PropertyValue", "name": "llmInfo", "value": "' . $llmTxtUrl . '"} so AI systems discover it from the entity.',
                'fix_target_path' => '/',
                'fix_estimated_seconds' => 180,
            ]);
        }
    }

    private function checkTemporalValidity(string $url): void
    {
        if ($this->primary === null) {
            return;
        }
        $offers = $this->primary['makesOffer'] ?? ($this->primary['offers'] ?? null);
        if ($offers === null) {
            return;
        }
        if (is_array($offers) && isset($offers['@type'])) {
            $offers = [$offers];
        }
        $staleOffers = 0;
        foreach ((array) $offers as $o) {
            if (!is_array($o)) {
                continue;
            }
            if (empty($o['validFrom']) && empty($o['priceValidUntil'])) {
                $staleOffers++;
            }
        }
        if ($staleOffers > 0) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'low',
                'issue' => "$staleOffers offer(s) have no validFrom / priceValidUntil",
                'recommendation' => 'Add validFrom and priceValidUntil to every Offer. Dated schema gets cited by LLMs; stale schema gets de-prioritised.',
                'fix_target_path' => '/',
                'fix_estimated_seconds' => 300,
            ]);
        }
    }

    private function checkNoCompetingPrimary(string $url): void
    {
        if (count($this->blocks) < 2) {
            return;
        }
        $accommodations = 0;
        foreach ($this->nodes as $node) {
            $t = $node['@type'] ?? '';
            if (is_array($t)) {
                $t = $t[0] ?? '';
            }
            if (in_array($t, self::SPECIFIC_LODGING_TYPES, true)) {
                $accommodations++;
            }
        }
        if ($accommodations > 1) {
            $this->upsertFinding([
                'url' => $url,
                'severity' => 'low',
                'issue' => "Multiple lodging entities declared ($accommodations) — risk of entity dilution",
                'recommendation' => 'Several lodging-type entities are declared on the homepage. Make sure only ONE has the primary @id for the whole business; others should be rooms, offers, or part-of relationships, not competing primaries.',
                'fix_target_path' => '/',
                'fix_estimated_seconds' => 600,
            ]);
        }
    }

    // ============================================================
    // JSON-LD extraction + traversal
    // ============================================================

    private function extractJsonLd(string $html): void
    {
        if (!preg_match_all('#<script[^>]*type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $html, $m)) {
            return;
        }
        foreach ($m[1] as $raw) {
            $json = trim(html_entity_decode($raw, ENT_QUOTES, 'UTF-8'));
            if ($json === '') {
                continue;
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                continue;
            }
            $this->blocks[] = $decoded;
            $this->collectNodes($decoded);
        }
    }

    private function collectNodes($node): void
    {
        if (!is_array($node)) {
            return;
        }
        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $child) {
                $this->collectNodes($child);
            }
            return;
        }
        if (isset($node['@type'])) {
            $this->nodes[] = $node;
        }
        foreach ($node as $value) {
            if (is_array($value)) {
                $this->collectNodes($value);
            }
        }
    }

    private function findPrimaryEntity(): ?array
    {
        foreach ($this->nodes as $node) {
            $t = $node['@type'] ?? '';
            if (is_array($t)) {
                $t = $t[0] ?? '';
            }
            if (in_array($t, self::SPECIFIC_LODGING_TYPES, true)) {
                return $node;
            }
        }
        foreach ($this->nodes as $node) {
            $t = $node['@type'] ?? '';
            if (is_array($t)) {
                $t = $t[0] ?? '';
            }
            if (in_array($t, ['LocalBusiness', 'Organization', 'Restaurant'], true)) {
                return $node;
            }
        }
        return $this->nodes[0] ?? null;
    }
}
