<?php
/**
 * Filename: entity-auditor.php
 * Description: THE CROWN JEWEL - Scores AI readiness out of 100 across schema, entity authority, AI discoverability, technical, and booking dimensions
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';

class EntityAuditor
{
    /**
     * @var array AI crawler user-agent tokens to check in robots.txt
     */
    private const AI_CRAWLERS = [
        'ChatGPT-User',
        'GPTBot',
        'Anthropic',
        'PerplexityBot',
        'Google-Extended',
    ];

    /**
     * Constructor — dependencies are loaded via require_once above.
     */
    public function __construct()
    {
        // Dependencies initialized via require_once:
        // - Database (db.php)
        // - Logger (logger.php)
    }

    /**
     * Main audit method — scores a property's AI readiness out of 100.
     *
     * @param array $property       The property record from the database
     * @param array $scrapeData     Combined scrape data (main_page + subpages)
     * @param array $schemaAnalysis Schema quality analysis from SchemaDetector::analyzeSchemaQuality()
     * @param array $enrichmentData Additional enrichment data (llm_txt, robots_txt, sitemap, platform, etc.)
     * @return array                Complete audit result object
     */
    public function audit(array $property, array $scrapeData, array $schemaAnalysis, array $enrichmentData): array
    {
        $schemaScore          = $this->scoreSchemaPresence($schemaAnalysis);
        $entityAuthorityScore = $this->scoreEntityAuthority($schemaAnalysis);
        $aiDiscoverScore      = $this->scoreAiDiscoverability($enrichmentData);
        $technicalScore       = $this->scoreTechnicalFoundation($enrichmentData, $property);
        $bookingScore         = $this->scoreBookingAuthority($enrichmentData, $schemaAnalysis);

        $totalScore = $schemaScore['score']
            + $entityAuthorityScore['score']
            + $aiDiscoverScore['score']
            + $technicalScore['score']
            + $bookingScore['score'];

        $scoreBreakdown = [
            'schema'           => $schemaScore,
            'entity_authority' => $entityAuthorityScore,
            'ai_discoverability' => $aiDiscoverScore,
            'technical'        => $technicalScore,
            'booking_authority' => $bookingScore,
        ];

        $findings        = $this->generateFindings($scoreBreakdown);
        $recommendations = $this->generateRecommendations($scoreBreakdown, $property);

        return [
            'total_score'              => $totalScore,
            'schema_score'             => $schemaScore['score'],
            'entity_authority_score'   => $entityAuthorityScore['score'],
            'ai_discoverability_score' => $aiDiscoverScore['score'],
            'technical_score'          => $technicalScore['score'],
            'booking_authority_score'  => $bookingScore['score'],
            'score_breakdown'          => $scoreBreakdown,
            'findings'                 => $findings,
            'recommendations'          => $recommendations,
            'interpretation'           => $this->getScoreInterpretation($totalScore),
        ];
    }

    /**
     * Score Schema.org presence and completeness (30 points max).
     *
     * @param array $schemaAnalysis Schema quality analysis
     * @return array                [score, max, details[]]
     */
    public function scoreSchemaPresence(array $schemaAnalysis): array
    {
        $details = [];
        $score   = 0;
        $max     = 30;

        // Has ANY structured data: 5pts
        $hasData = $schemaAnalysis['has_lodging_type']
            || $schemaAnalysis['has_json_ld']
            || $schemaAnalysis['has_address']
            || $schemaAnalysis['has_rating']
            || $schemaAnalysis['has_offers']
            || $schemaAnalysis['has_faq_page']
            || $schemaAnalysis['has_breadcrumb']
            || $schemaAnalysis['has_room_markup']
            || $schemaAnalysis['has_same_as']
            || $schemaAnalysis['has_main_entity'];

        $earned    = $hasData ? 5 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'Has any structured data',
            'points'    => 5,
            'earned'    => $earned,
            'present'   => $hasData,
        ];

        // Uses JSON-LD format: 3pts
        $earned    = $schemaAnalysis['has_json_ld'] ? 3 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'Uses JSON-LD format',
            'points'    => 3,
            'earned'    => $earned,
            'present'   => $schemaAnalysis['has_json_ld'],
        ];

        // Has LodgingBusiness/Hotel/Resort type: 5pts
        $earned    = $schemaAnalysis['has_lodging_type'] ? 5 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'Has LodgingBusiness/Hotel/Resort type',
            'points'    => 5,
            'earned'    => $earned,
            'present'   => $schemaAnalysis['has_lodging_type'],
        ];

        // Has individual room/accommodation markup: 4pts
        $earned    = $schemaAnalysis['has_room_markup'] ? 4 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'Has individual room/accommodation markup',
            'points'    => 4,
            'earned'    => $earned,
            'present'   => $schemaAnalysis['has_room_markup'],
        ];

        // Has address + geo coordinates in schema: 3pts
        $hasBoth   = $schemaAnalysis['has_address'] && $schemaAnalysis['has_geo'];
        $earned    = $hasBoth ? 3 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'Has address + geo coordinates in schema',
            'points'    => 3,
            'earned'    => $earned,
            'present'   => $hasBoth,
        ];

        // Has aggregateRating in schema: 3pts
        $earned    = $schemaAnalysis['has_rating'] ? 3 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'Has aggregateRating in schema',
            'points'    => 3,
            'earned'    => $earned,
            'present'   => $schemaAnalysis['has_rating'],
        ];

        // Has Offer/priceRange markup: 3pts
        $earned    = $schemaAnalysis['has_offers'] ? 3 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'Has Offer/priceRange markup',
            'points'    => 3,
            'earned'    => $earned,
            'present'   => $schemaAnalysis['has_offers'],
        ];

        // Has FAQPage schema: 2pts
        $earned    = $schemaAnalysis['has_faq_page'] ? 2 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'Has FAQPage schema',
            'points'    => 2,
            'earned'    => $earned,
            'present'   => $schemaAnalysis['has_faq_page'],
        ];

        // Has BreadcrumbList: 2pts
        $earned    = $schemaAnalysis['has_breadcrumb'] ? 2 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'Has BreadcrumbList',
            'points'    => 2,
            'earned'    => $earned,
            'present'   => $schemaAnalysis['has_breadcrumb'],
        ];

        return [
            'score'   => $score,
            'max'     => $max,
            'details' => $details,
        ];
    }

    /**
     * Score Entity Authority signals (25 points max).
     *
     * @param array $schemaAnalysis Schema quality analysis
     * @return array                [score, max, details[]]
     */
    public function scoreEntityAuthority(array $schemaAnalysis): array
    {
        $details = [];
        $score   = 0;
        $max     = 25;

        // @id uses canonical URL: 5pts
        $idCanonical = $schemaAnalysis['id_is_canonical'] === true;
        $earned      = $idCanonical ? 5 : 0;
        $score      += $earned;
        $details[]   = [
            'criterion' => '@id uses canonical URL',
            'points'    => 5,
            'earned'    => $earned,
            'present'   => $idCanonical,
        ];

        // mainEntityOfPage declared: 5pts
        $earned    = $schemaAnalysis['has_main_entity'] ? 5 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'mainEntityOfPage declared',
            'points'    => 5,
            'earned'    => $earned,
            'present'   => $schemaAnalysis['has_main_entity'],
        ];

        // sameAs includes social profiles: 3pts
        $earned    = $schemaAnalysis['same_as_has_social'] ? 3 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'sameAs includes social profiles',
            'points'    => 3,
            'earned'    => $earned,
            'present'   => $schemaAnalysis['same_as_has_social'],
        ];

        // sameAs includes OTA listings: 4pts
        $earned    = $schemaAnalysis['same_as_has_ota'] ? 4 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'sameAs includes OTA listings',
            'points'    => 4,
            'earned'    => $earned,
            'present'   => $schemaAnalysis['same_as_has_ota'],
        ];

        // sameAs includes Wikipedia/Wikidata: 5pts
        $earned    = $schemaAnalysis['same_as_has_wiki'] ? 5 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'sameAs includes Wikipedia/Wikidata',
            'points'    => 5,
            'earned'    => $earned,
            'present'   => $schemaAnalysis['same_as_has_wiki'],
        ];

        // potentialAction with ReserveAction: 3pts
        $earned    = $schemaAnalysis['has_reserve_action'] ? 3 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'potentialAction with ReserveAction',
            'points'    => 3,
            'earned'    => $earned,
            'present'   => $schemaAnalysis['has_reserve_action'],
        ];

        return [
            'score'   => $score,
            'max'     => $max,
            'details' => $details,
        ];
    }

    /**
     * Score AI Discoverability signals (20 points max).
     *
     * @param array $enrichmentData Enrichment data including llm_txt, robots_txt, content stats
     * @return array                [score, max, details[]]
     */
    public function scoreAiDiscoverability(array $enrichmentData): array
    {
        $details = [];
        $score   = 0;
        $max     = 20;

        // Has LLM.txt file: 5pts
        $hasLlmTxt = !empty($enrichmentData['llm_txt']);
        $earned    = $hasLlmTxt ? 5 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'Has LLM.txt file',
            'points'    => 5,
            'earned'    => $earned,
            'present'   => $hasLlmTxt,
        ];

        // robots.txt allows AI crawlers: 3pts
        $allowsAiCrawlers = $this->checkRobotsAllowsAi($enrichmentData['robots_txt'] ?? null);
        $earned           = $allowsAiCrawlers ? 3 : 0;
        $score           += $earned;
        $details[]        = [
            'criterion' => 'robots.txt allows AI crawlers',
            'points'    => 3,
            'earned'    => $earned,
            'present'   => $allowsAiCrawlers,
        ];

        // Content length >2,000 words on main pages: 4pts
        $wordCount     = (int) ($enrichmentData['main_page_word_count'] ?? 0);
        $hasLongContent = $wordCount > 2000;
        $earned        = $hasLongContent ? 4 : 0;
        $score        += $earned;
        $details[]     = [
            'criterion' => 'Content length >2,000 words on main pages',
            'points'    => 4,
            'earned'    => $earned,
            'present'   => $hasLongContent,
        ];

        // FAQ content present: 4pts
        $hasFaq    = !empty($enrichmentData['has_faq_content']);
        $earned    = $hasFaq ? 4 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'FAQ content present',
            'points'    => 4,
            'earned'    => $earned,
            'present'   => $hasFaq,
        ];

        // Blog/content hub with regular updates: 4pts
        $hasBlog   = !empty($enrichmentData['has_blog']);
        $earned    = $hasBlog ? 4 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'Blog/content hub with regular updates',
            'points'    => 4,
            'earned'    => $earned,
            'present'   => $hasBlog,
        ];

        return [
            'score'   => $score,
            'max'     => $max,
            'details' => $details,
        ];
    }

    /**
     * Score Technical Foundation (15 points max).
     *
     * @param array $enrichmentData Enrichment data
     * @param array $property       The property record
     * @return array                [score, max, details[]]
     */
    public function scoreTechnicalFoundation(array $enrichmentData, array $property): array
    {
        $details = [];
        $score   = 0;
        $max     = 15;

        // HTTPS: 2pts
        $websiteUrl = $property['website'] ?? '';
        $isHttps    = stripos($websiteUrl, 'https://') === 0;
        $earned     = $isHttps ? 2 : 0;
        $score     += $earned;
        $details[]  = [
            'criterion' => 'HTTPS',
            'points'    => 2,
            'earned'    => $earned,
            'present'   => $isHttps,
        ];

        // Mobile responsive: 3pts
        $isMobileResponsive = !empty($enrichmentData['has_viewport_meta'])
            || !empty($enrichmentData['has_responsive_css']);
        $earned    = $isMobileResponsive ? 3 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'Mobile responsive (viewport meta / responsive CSS)',
            'points'    => 3,
            'earned'    => $earned,
            'present'   => $isMobileResponsive,
        ];

        // Page speed <3 seconds: 3pts
        $responseTimeMs = (int) ($enrichmentData['response_time_ms'] ?? 0);
        $isFast         = $responseTimeMs > 0 && $responseTimeMs < 3000;
        $earned         = $isFast ? 3 : 0;
        $score         += $earned;
        $details[]      = [
            'criterion' => 'Page speed <3 seconds',
            'points'    => 3,
            'earned'    => $earned,
            'present'   => $isFast,
        ];

        // Has sitemap.xml: 2pts
        $hasSitemap = !empty($enrichmentData['sitemap_url']);
        $earned     = $hasSitemap ? 2 : 0;
        $score     += $earned;
        $details[]  = [
            'criterion' => 'Has sitemap.xml',
            'points'    => 2,
            'earned'    => $earned,
            'present'   => $hasSitemap,
        ];

        // Google Business Profile exists: 3pts
        $hasGbp    = !empty($enrichmentData['google_place_id']) || !empty($enrichmentData['has_google_place']);
        $earned    = $hasGbp ? 3 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'Google Business Profile exists',
            'points'    => 3,
            'earned'    => $earned,
            'present'   => $hasGbp,
        ];

        // Google Business Profile has >10 reviews: 2pts
        $reviewCount   = (int) ($enrichmentData['google_review_count'] ?? 0);
        $hasEnoughReviews = $reviewCount > 10;
        $earned        = $hasEnoughReviews ? 2 : 0;
        $score        += $earned;
        $details[]     = [
            'criterion' => 'Google Business Profile has >10 reviews',
            'points'    => 2,
            'earned'    => $earned,
            'present'   => $hasEnoughReviews,
        ];

        return [
            'score'   => $score,
            'max'     => $max,
            'details' => $details,
        ];
    }

    /**
     * Score Booking Authority (10 points max).
     *
     * @param array $enrichmentData Enrichment data (platform detection results, etc.)
     * @param array $schemaAnalysis Schema quality analysis
     * @return array                [score, max, details[]]
     */
    public function scoreBookingAuthority(array $enrichmentData, array $schemaAnalysis): array
    {
        $details = [];
        $score   = 0;
        $max     = 10;

        // Has own booking engine: 5pts
        $hasBookingEngine = !empty($enrichmentData['has_booking_engine']);
        $earned           = $hasBookingEngine ? 5 : 0;
        $score           += $earned;
        $details[]        = [
            'criterion' => 'Has own booking engine',
            'points'    => 5,
            'earned'    => $earned,
            'present'   => $hasBookingEngine,
        ];

        // Direct booking CTA visible: 3pts
        $hasDirectCta = !empty($enrichmentData['has_direct_booking_cta']);
        $earned       = $hasDirectCta ? 3 : 0;
        $score       += $earned;
        $details[]    = [
            'criterion' => 'Direct booking CTA visible',
            'points'    => 3,
            'earned'    => $earned,
            'present'   => $hasDirectCta,
        ];

        // tourBookingPage property used: 2pts
        $earned    = $schemaAnalysis['has_tour_booking_page'] ? 2 : 0;
        $score    += $earned;
        $details[] = [
            'criterion' => 'tourBookingPage property used',
            'points'    => 2,
            'earned'    => $earned,
            'present'   => $schemaAnalysis['has_tour_booking_page'],
        ];

        return [
            'score'   => $score,
            'max'     => $max,
            'details' => $details,
        ];
    }

    /**
     * Generate human-readable findings from the score breakdown.
     * Lists what was found and what's missing for each category, prioritized by impact.
     *
     * @param array $scoreBreakdown The full score breakdown from audit()
     * @return array                Array of finding strings
     */
    public function generateFindings(array $scoreBreakdown): array
    {
        $findings = [];

        $categoryLabels = [
            'schema'             => 'Schema.org Markup',
            'entity_authority'   => 'Entity Authority',
            'ai_discoverability' => 'AI Discoverability',
            'technical'          => 'Technical Foundation',
            'booking_authority'  => 'Booking Authority',
        ];

        // Process categories in order of max points (highest impact first)
        $orderedCategories = ['schema', 'entity_authority', 'ai_discoverability', 'technical', 'booking_authority'];

        foreach ($orderedCategories as $category) {
            if (!isset($scoreBreakdown[$category])) {
                continue;
            }

            $catData  = $scoreBreakdown[$category];
            $label    = $categoryLabels[$category];
            $catScore = $catData['score'];
            $catMax   = $catData['max'];

            $findings[] = "--- {$label} ({$catScore}/{$catMax}) ---";

            $found   = [];
            $missing = [];

            foreach ($catData['details'] as $detail) {
                if ($detail['present']) {
                    $found[] = "[PASS] {$detail['criterion']} (+{$detail['earned']}pts)";
                } else {
                    $missing[] = "[MISS] {$detail['criterion']} (0/{$detail['points']}pts)";
                }
            }

            // Show found items first, then missing
            foreach ($found as $item) {
                $findings[] = $item;
            }
            foreach ($missing as $item) {
                $findings[] = $item;
            }
        }

        return $findings;
    }

    /**
     * Generate actionable, prioritized recommendations based on the score breakdown.
     * Each recommendation includes priority, category, title, description, and impact_points.
     *
     * @param array $scoreBreakdown The full score breakdown from audit()
     * @param array $property       The property record
     * @return array                Array of recommendation objects, sorted by highest impact first
     */
    public function generateRecommendations(array $scoreBreakdown, array $property): array
    {
        $recommendations = [];

        $categoryMap = [
            'schema'             => 'Schema.org Markup',
            'entity_authority'   => 'Entity Authority',
            'ai_discoverability' => 'AI Discoverability',
            'technical'          => 'Technical Foundation',
            'booking_authority'  => 'Booking Authority',
        ];

        // Collect all missed criteria across all categories
        foreach ($scoreBreakdown as $categoryKey => $catData) {
            $categoryLabel = $categoryMap[$categoryKey] ?? $categoryKey;

            foreach ($catData['details'] as $detail) {
                if ($detail['present']) {
                    continue; // Already achieved
                }

                $impactPoints = $detail['points'];

                // Assign priority based on impact points
                if ($impactPoints >= 5) {
                    $priority = 1;
                } elseif ($impactPoints >= 4) {
                    $priority = 2;
                } elseif ($impactPoints >= 3) {
                    $priority = 3;
                } elseif ($impactPoints >= 2) {
                    $priority = 4;
                } else {
                    $priority = 5;
                }

                $description = $this->getRecommendationDescription($detail['criterion'], $property);

                $recommendations[] = [
                    'priority'      => $priority,
                    'category'      => $categoryLabel,
                    'title'         => $detail['criterion'],
                    'description'   => $description,
                    'impact_points' => $impactPoints,
                ];
            }
        }

        // Sort by impact_points descending (highest impact first), then by priority ascending
        usort($recommendations, function ($a, $b) {
            if ($a['impact_points'] !== $b['impact_points']) {
                return $b['impact_points'] - $a['impact_points'];
            }
            return $a['priority'] - $b['priority'];
        });

        return $recommendations;
    }

    /**
     * Generate a complete JSON-LD code block the property should add to their website.
     * Builds a LodgingBusiness schema using the property's data.
     *
     * @param array $property       The property record from the database
     * @param array $schemaAnalysis Schema quality analysis
     * @return string               Formatted JSON-LD string
     */
    public function generateSchemaRecommendation(array $property, array $schemaAnalysis): string
    {
        $schema = [
            '@context' => 'https://schema.org',
            '@type'    => 'LodgingBusiness',
        ];

        // @id — use the canonical website URL
        $websiteUrl = $property['website'] ?? '';
        if (!empty($websiteUrl)) {
            $schema['@id']  = rtrim($websiteUrl, '/') . '/#lodging';
            $schema['url']  = $websiteUrl;
        }

        // Name
        if (!empty($property['name'])) {
            $schema['name'] = $property['name'];
        }

        // Description
        if (!empty($property['description'])) {
            $schema['description'] = $property['description'];
        }

        // Address
        $address = [];
        if (!empty($property['street_address'])) {
            $address['streetAddress'] = $property['street_address'];
        }
        if (!empty($property['city'])) {
            $address['addressLocality'] = $property['city'];
        }
        if (!empty($property['region'])) {
            $address['addressRegion'] = $property['region'];
        }
        if (!empty($property['postal_code'])) {
            $address['postalCode'] = $property['postal_code'];
        }
        if (!empty($property['country'])) {
            $address['addressCountry'] = $property['country'];
        }
        if (!empty($address)) {
            $address['@type'] = 'PostalAddress';
            $schema['address'] = $address;
        }

        // Geo coordinates
        if (!empty($property['latitude']) && !empty($property['longitude'])) {
            $schema['geo'] = [
                '@type'     => 'GeoCoordinates',
                'latitude'  => (float) $property['latitude'],
                'longitude' => (float) $property['longitude'],
            ];
        }

        // Telephone
        if (!empty($property['phone'])) {
            $schema['telephone'] = $property['phone'];
        }

        // Image
        if (!empty($property['image_url'])) {
            $schema['image'] = $property['image_url'];
        }

        // sameAs — build from available profiles
        $sameAs = [];
        if (!empty($property['facebook_url'])) {
            $sameAs[] = $property['facebook_url'];
        }
        if (!empty($property['instagram_url'])) {
            $sameAs[] = $property['instagram_url'];
        }
        if (!empty($property['twitter_url'])) {
            $sameAs[] = $property['twitter_url'];
        }
        if (!empty($property['tripadvisor_url'])) {
            $sameAs[] = $property['tripadvisor_url'];
        }
        if (!empty($property['booking_com_url'])) {
            $sameAs[] = $property['booking_com_url'];
        }
        if (!empty($property['wikipedia_url'])) {
            $sameAs[] = $property['wikipedia_url'];
        }
        if (!empty($sameAs)) {
            $schema['sameAs'] = $sameAs;
        }

        // mainEntityOfPage
        if (!empty($websiteUrl)) {
            $schema['mainEntityOfPage'] = [
                '@type' => 'WebPage',
                '@id'   => $websiteUrl,
            ];
        }

        // potentialAction — ReserveAction
        $bookingUrl = $property['booking_url'] ?? $websiteUrl;
        if (!empty($bookingUrl)) {
            $schema['potentialAction'] = [
                '@type'  => 'ReserveAction',
                'target' => [
                    '@type'     => 'EntryPoint',
                    'urlTemplate' => $bookingUrl,
                ],
                'result' => [
                    '@type' => 'LodgingReservation',
                ],
            ];
        }

        // tourBookingPage
        if (!empty($property['booking_url'])) {
            $schema['tourBookingPage'] = $property['booking_url'];
        }

        $jsonString = json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return '<script type="application/ld+json">' . "\n" . $jsonString . "\n" . '</script>';
    }

    /**
     * Return a human-readable text interpretation of the total score.
     *
     * @param int $score The total audit score (0-100)
     * @return string    The interpretation text
     */
    public function getScoreInterpretation(int $score): string
    {
        if ($score <= 20) {
            return 'Critical — Virtually invisible to AI search';
        }
        if ($score <= 40) {
            return 'Poor — Major gaps in AI discoverability';
        }
        if ($score <= 60) {
            return 'Fair — Some presence but significant improvements needed';
        }
        if ($score <= 80) {
            return 'Good — Solid foundation with room for optimization';
        }
        return 'Excellent — Well-optimized for AI discovery';
    }

    /**
     * Save the audit result to the property_audit table.
     *
     * @param int   $propertyId  The property ID
     * @param array $auditResult The complete audit result from audit()
     * @return void
     */
    public function saveAudit(int $propertyId, array $auditResult): void
    {
        try {
            Database::insert('property_audit', [
                'property_id'              => $propertyId,
                'total_score'              => $auditResult['total_score'],
                'schema_score'             => $auditResult['schema_score'],
                'entity_authority_score'   => $auditResult['entity_authority_score'],
                'ai_discoverability_score' => $auditResult['ai_discoverability_score'],
                'technical_score'          => $auditResult['technical_score'],
                'booking_authority_score'  => $auditResult['booking_authority_score'],
                'score_breakdown'          => json_encode($auditResult['score_breakdown'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'findings'                 => json_encode($auditResult['findings'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'recommendations'          => json_encode($auditResult['recommendations'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'interpretation'           => $auditResult['interpretation'],
                'audited_at'               => date('Y-m-d H:i:s'),
            ]);

            Logger::info('EntityAuditor', "Audit saved for property {$propertyId}: score {$auditResult['total_score']}/100", [
                'metadata' => [
                    'property_id' => $propertyId,
                    'total_score' => $auditResult['total_score'],
                ],
            ]);
        } catch (\Throwable $e) {
            Logger::error('EntityAuditor', "Failed to save audit for property {$propertyId}: " . $e->getMessage(), [
                'metadata' => ['property_id' => $propertyId],
            ]);
        }
    }

    /**
     * Check if robots.txt allows AI crawlers (none of the AI crawler tokens are disallowed).
     *
     * @param string|null $robotsTxt The robots.txt content
     * @return bool                  True if AI crawlers are allowed (not blocked)
     */
    private function checkRobotsAllowsAi(?string $robotsTxt): bool
    {
        // If no robots.txt exists, crawlers are allowed by default
        if ($robotsTxt === null || trim($robotsTxt) === '') {
            return true;
        }

        $robotsLower = strtolower($robotsTxt);

        // Parse robots.txt — check for Disallow rules targeting AI crawlers
        foreach (self::AI_CRAWLERS as $crawler) {
            $crawlerLower = strtolower($crawler);

            // Check if there's a user-agent block for this crawler with a Disallow
            // Simple check: look for the crawler name followed by a disallow directive
            $pattern = '/user-agent\s*:\s*' . preg_quote($crawlerLower, '/') . '.*?disallow\s*:\s*\//is';

            if (preg_match($pattern, $robotsLower)) {
                return false; // At least one AI crawler is blocked
            }
        }

        // Also check if there's a blanket "Disallow: /" for all bots that would block AI crawlers
        // Look for User-agent: * with Disallow: / (and no specific Allow for AI crawlers)
        if (preg_match('/user-agent\s*:\s*\*.*?disallow\s*:\s*\/\s*$/im', $robotsLower)) {
            // Blanket block — but check if there are specific allows for AI crawlers
            $hasSpecificAllow = false;
            foreach (self::AI_CRAWLERS as $crawler) {
                $crawlerLower = strtolower($crawler);
                if (preg_match('/user-agent\s*:\s*' . preg_quote($crawlerLower, '/') . '.*?allow\s*:\s*\//is', $robotsLower)) {
                    $hasSpecificAllow = true;
                    break;
                }
            }
            if (!$hasSpecificAllow) {
                return false;
            }
        }

        return true;
    }

    /**
     * Generate a human-readable description for a specific recommendation criterion.
     *
     * @param string $criterion The criterion name
     * @param array  $property  The property record
     * @return string           Actionable description text
     */
    private function getRecommendationDescription(string $criterion, array $property): string
    {
        $propertyName = $property['name'] ?? 'your property';

        $descriptions = [
            'Has any structured data' =>
                "Add Schema.org structured data to {$propertyName}'s website. Start with a JSON-LD block in the <head> section declaring the property as a LodgingBusiness with basic details like name, address, and contact information.",

            'Uses JSON-LD format' =>
                "Convert existing structured data to JSON-LD format. JSON-LD is Google's recommended format and is easier for AI systems to parse. Add a <script type=\"application/ld+json\"> block in the page head.",

            'Has LodgingBusiness/Hotel/Resort type' =>
                "Declare the property's Schema.org @type as LodgingBusiness, Hotel, Resort, or BedAndBreakfast. This tells search engines and AI systems exactly what kind of entity {$propertyName} is.",

            'Has individual room/accommodation markup' =>
                "Add individual HotelRoom or Accommodation schema markup for each room type. This allows AI systems to understand and recommend specific room options to potential guests.",

            'Has address + geo coordinates in schema' =>
                "Include both a full PostalAddress and GeoCoordinates (latitude/longitude) in the schema. This is critical for location-based AI recommendations and map integrations.",

            'Has aggregateRating in schema' =>
                "Add aggregateRating to the schema with ratingValue and reviewCount. If you have reviews on Google, TripAdvisor, or your own site, reflect this rating in the structured data.",

            'Has Offer/priceRange markup' =>
                "Add priceRange or Offer markup to indicate pricing. Even a general range like \"\$\$\$\" or \"From ZAR 2,500 per night\" helps AI systems answer pricing questions.",

            'Has FAQPage schema' =>
                "Create an FAQ section on the website and mark it up with FAQPage schema. This makes your content eligible for FAQ rich results and helps AI systems answer specific questions about {$propertyName}.",

            'Has BreadcrumbList' =>
                "Add BreadcrumbList schema markup to help search engines and AI systems understand the site's page hierarchy.",

            '@id uses canonical URL' =>
                "Set the @id property in your JSON-LD to the canonical URL of the page (e.g., \"https://yourdomain.com/#lodging\"). This establishes a unique, persistent identifier for the entity.",

            'mainEntityOfPage declared' =>
                "Add mainEntityOfPage to the JSON-LD schema, pointing to the WebPage. This explicitly tells AI systems that this page is THE authoritative page about {$propertyName}.",

            'sameAs includes social profiles' =>
                "Add sameAs links to your social media profiles (Facebook, Instagram, Twitter/X, YouTube, etc.). This helps AI systems connect your entity across platforms.",

            'sameAs includes OTA listings' =>
                "Add sameAs links to your OTA listings (Booking.com, TripAdvisor, Expedia, etc.). This creates authoritative cross-references that strengthen your entity identity.",

            'sameAs includes Wikipedia/Wikidata' =>
                "If {$propertyName} has a Wikipedia or Wikidata entry, add it to sameAs. This is one of the strongest entity authority signals. If no entry exists, consider creating a Wikidata item for the property.",

            'potentialAction with ReserveAction' =>
                "Add a potentialAction of type ReserveAction to the schema, pointing to your booking page. This tells AI systems how users can take action to book {$propertyName} directly.",

            'Has LLM.txt file' =>
                "Create an llm.txt file at the root of your website (or at /.well-known/llm.txt). This emerging standard helps AI models understand how to interact with and represent your property.",

            'robots.txt allows AI crawlers' =>
                "Review your robots.txt to ensure AI crawlers (GPTBot, ChatGPT-User, Anthropic, PerplexityBot, Google-Extended) are not blocked. Allowing these crawlers is essential for AI discoverability.",

            'Content length >2,000 words on main pages' =>
                "Expand the content on your main pages to at least 2,000 words. Rich, detailed descriptions of {$propertyName}, its surroundings, activities, and unique features give AI systems more to work with.",

            'FAQ content present' =>
                "Add a comprehensive FAQ section addressing common guest questions: check-in times, nearby attractions, dining options, family friendliness, accessibility, and more.",

            'Blog/content hub with regular updates' =>
                "Create a blog or content hub with regular updates about {$propertyName}, local wildlife, seasonal activities, and travel tips. Fresh content signals an active, authoritative entity.",

            'HTTPS' =>
                "Ensure the website is served over HTTPS. This is a basic trust signal for both search engines and AI systems.",

            'Mobile responsive (viewport meta / responsive CSS)' =>
                "Ensure the website is mobile-responsive with a proper viewport meta tag. Mobile-friendliness is a ranking factor and affects how AI systems evaluate technical quality.",

            'Page speed <3 seconds' =>
                "Optimize page load speed to under 3 seconds. Slow sites are penalized by search engines and may be deprioritized by AI systems when recommending properties.",

            'Has sitemap.xml' =>
                "Add a sitemap.xml file to help search engines and AI crawlers discover all important pages on the site.",

            'Google Business Profile exists' =>
                "Ensure {$propertyName} has a Google Business Profile. This is a critical entity authority signal and the primary source for many AI systems' knowledge.",

            'Google Business Profile has >10 reviews' =>
                "Encourage guests to leave Google reviews. Properties with more than 10 reviews appear more established and trustworthy to AI systems.",

            'Has own booking engine' =>
                "Integrate a booking engine into the website. Direct booking capability signals to AI that {$propertyName} is an active, bookable property — not just informational.",

            'Direct booking CTA visible' =>
                "Add a prominent 'Book Now' or 'Reserve' call-to-action button on the homepage that links to your direct booking system rather than an OTA.",

            'tourBookingPage property used' =>
                "Add the tourBookingPage property to your JSON-LD schema, pointing to the direct booking URL. This is a tourism-specific Schema.org property that signals booking authority.",
        ];

        return $descriptions[$criterion]
            ?? "Implement '{$criterion}' to improve the AI readiness score for {$propertyName}.";
    }
}
