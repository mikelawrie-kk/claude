<?php
/**
 * Filename: schema-detector.php
 * Description: Detects and analyzes Schema.org structured data (JSON-LD, Microdata, RDFa)
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

class SchemaDetector
{
    /**
     * @var array Schema.org types that represent lodging businesses
     */
    private const LODGING_TYPES = [
        'LodgingBusiness',
        'Hotel',
        'Resort',
        'BedAndBreakfast',
        'Motel',
        'Hostel',
        'Campground',
        'VacationRental',
        'Lodge',
    ];

    /**
     * Main detection method — runs all detectors and returns a structured audit object.
     *
     * @param string $html The raw HTML content to analyze
     * @return array       Structured detection result with formats_found, schemas, has_structured_data, primary_type
     */
    public function detect(string $html): array
    {
        $jsonLdSchemas    = $this->detectJsonLd($html);
        $microdataSchemas = $this->detectMicrodata($html);
        $rdfaSchemas      = $this->detectRdfa($html);

        $allSchemas   = array_merge($jsonLdSchemas, $microdataSchemas, $rdfaSchemas);
        $formatsFound = [];

        if (!empty($jsonLdSchemas)) {
            $formatsFound[] = 'json-ld';
        }
        if (!empty($microdataSchemas)) {
            $formatsFound[] = 'microdata';
        }
        if (!empty($rdfaSchemas)) {
            $formatsFound[] = 'rdfa';
        }

        // Determine the primary type — prefer lodging types
        $primaryType = $this->determinePrimaryType($allSchemas);

        return [
            'formats_found'       => $formatsFound,
            'schemas'             => $allSchemas,
            'has_structured_data' => !empty($allSchemas),
            'primary_type'        => $primaryType,
        ];
    }

    /**
     * Extract all JSON-LD structured data from script tags.
     *
     * @param string $html The raw HTML content
     * @return array       Array of parsed schema objects
     */
    public function detectJsonLd(string $html): array
    {
        $schemas = [];

        if (empty($html)) {
            return $schemas;
        }

        // Extract all <script type="application/ld+json"> blocks
        $pattern = '/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is';

        if (!preg_match_all($pattern, $html, $matches)) {
            return $schemas;
        }

        foreach ($matches[1] as $jsonString) {
            $jsonString = trim($jsonString);

            if (empty($jsonString)) {
                continue;
            }

            $decoded = json_decode($jsonString, true);

            if ($decoded === null) {
                continue;
            }

            // Handle @graph arrays
            if (isset($decoded['@graph']) && is_array($decoded['@graph'])) {
                foreach ($decoded['@graph'] as $graphItem) {
                    if (is_array($graphItem)) {
                        $schemas[] = $this->extractSchemaProperties($graphItem, 'json-ld');
                    }
                }
            } else {
                $schemas[] = $this->extractSchemaProperties($decoded, 'json-ld');
            }
        }

        return $schemas;
    }

    /**
     * Extract Microdata structured data from HTML elements with itemscope/itemtype.
     *
     * @param string $html The raw HTML content
     * @return array       Array of parsed schema objects
     */
    public function detectMicrodata(string $html): array
    {
        $schemas = [];

        if (empty($html)) {
            return $schemas;
        }

        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Find all top-level elements with itemscope (not nested inside another itemscope)
        $itemscopeNodes = $xpath->query('//*[@itemscope and @itemtype]');

        for ($i = 0; $i < $itemscopeNodes->length; $i++) {
            $node     = $itemscopeNodes->item($i);
            $itemType = $node->getAttribute('itemtype');

            // Extract the type name from the full URL
            $typeName = $itemType;
            if (preg_match('/schema\.org\/(.+)$/', $itemType, $typeMatch)) {
                $typeName = $typeMatch[1];
            }

            $properties = $this->extractMicrodataProperties($node, $xpath);

            $schema = [
                '_format'  => 'microdata',
                '@type'    => $typeName,
                '@context' => 'https://schema.org',
            ];

            $schema = array_merge($schema, $properties);
            $schemas[] = $schema;
        }

        return $schemas;
    }

    /**
     * Extract RDFa structured data from HTML elements with typeof/property attributes.
     *
     * @param string $html The raw HTML content
     * @return array       Array of parsed schema objects
     */
    public function detectRdfa(string $html): array
    {
        $schemas = [];

        if (empty($html)) {
            return $schemas;
        }

        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Find elements with typeof attribute (RDFa type declaration)
        $typeofNodes = $xpath->query('//*[@typeof]');

        for ($i = 0; $i < $typeofNodes->length; $i++) {
            $node    = $typeofNodes->item($i);
            $typeOf  = $node->getAttribute('typeof');
            $vocab   = $node->getAttribute('vocab') ?: '';
            $about   = $node->getAttribute('about') ?: '';

            $properties = $this->extractRdfaProperties($node, $xpath);

            $schema = [
                '_format'  => 'rdfa',
                '@type'    => $typeOf,
                '@context' => $vocab ?: 'https://schema.org',
            ];

            if (!empty($about)) {
                $schema['@id'] = $about;
            }

            $schema = array_merge($schema, $properties);
            $schemas[] = $schema;
        }

        return $schemas;
    }

    /**
     * Analyze the quality of detected schemas, reporting what's present and missing.
     *
     * @param array $schemas The array of detected schema objects (from detect())
     * @return array         Quality analysis with boolean flags and issue lists
     */
    public function analyzeSchemaQuality(array $schemas): array
    {
        $analysis = [
            'has_lodging_type'       => false,
            'has_json_ld'            => false,
            'has_address'            => false,
            'has_geo'                => false,
            'has_rating'             => false,
            'has_offers'             => false,
            'has_faq_page'           => false,
            'has_breadcrumb'         => false,
            'has_room_markup'        => false,
            'id_is_canonical'        => null,
            'has_main_entity'        => false,
            'has_same_as'            => false,
            'same_as_has_social'     => false,
            'same_as_has_ota'        => false,
            'same_as_has_wiki'       => false,
            'has_reserve_action'     => false,
            'has_tour_booking_page'  => false,
            'missing_properties'     => [],
            'quality_issues'         => [],
        ];

        if (empty($schemas)) {
            $analysis['missing_properties'][] = 'No structured data found at all';
            $analysis['quality_issues'][]     = 'No Schema.org markup detected on this page';
            return $analysis;
        }

        $allTypes = [];

        foreach ($schemas as $schema) {
            $format = $schema['_format'] ?? '';
            $type   = $schema['@type'] ?? '';

            // Normalize type — can be an array in JSON-LD
            $types = is_array($type) ? $type : [$type];
            $allTypes = array_merge($allTypes, $types);

            // Check format
            if ($format === 'json-ld') {
                $analysis['has_json_ld'] = true;
            }

            // Check lodging type
            foreach ($types as $t) {
                if ($this->isLodgingType($t)) {
                    $analysis['has_lodging_type'] = true;
                }
            }

            // Check FAQPage
            foreach ($types as $t) {
                if (stripos($t, 'FAQPage') !== false) {
                    $analysis['has_faq_page'] = true;
                }
            }

            // Check BreadcrumbList
            foreach ($types as $t) {
                if (stripos($t, 'BreadcrumbList') !== false) {
                    $analysis['has_breadcrumb'] = true;
                }
            }

            // Check for room/accommodation types
            foreach ($types as $t) {
                if (stripos($t, 'HotelRoom') !== false
                    || stripos($t, 'Room') !== false
                    || stripos($t, 'Accommodation') !== false
                    || stripos($t, 'Suite') !== false
                ) {
                    $analysis['has_room_markup'] = true;
                }
            }

            // Check address
            if (isset($schema['address']) && !empty($schema['address'])) {
                $analysis['has_address'] = true;
            }

            // Check geo
            if (isset($schema['geo']) && !empty($schema['geo'])) {
                $analysis['has_geo'] = true;
            }

            // Check aggregateRating
            if (isset($schema['aggregateRating']) && !empty($schema['aggregateRating'])) {
                $analysis['has_rating'] = true;
            }

            // Check offers / priceRange
            if (isset($schema['offers']) || isset($schema['priceRange'])) {
                $analysis['has_offers'] = true;
            }

            // Check @id canonical
            if (isset($schema['@id']) && !empty($schema['@id'])) {
                $url = $schema['url'] ?? '';
                $analysis['id_is_canonical'] = $this->isCanonicalId($schema['@id'], $url);
            }

            // Check mainEntityOfPage
            if (isset($schema['mainEntityOfPage']) && !empty($schema['mainEntityOfPage'])) {
                $analysis['has_main_entity'] = true;
            }

            // Check sameAs
            if (isset($schema['sameAs']) && !empty($schema['sameAs'])) {
                $analysis['has_same_as'] = true;
                $sameAsLinks = is_array($schema['sameAs']) ? $schema['sameAs'] : [$schema['sameAs']];

                foreach ($sameAsLinks as $link) {
                    $linkLower = strtolower($link);

                    // Social profiles
                    if (strpos($linkLower, 'facebook.com') !== false
                        || strpos($linkLower, 'instagram.com') !== false
                        || strpos($linkLower, 'twitter.com') !== false
                        || strpos($linkLower, 'x.com') !== false
                        || strpos($linkLower, 'linkedin.com') !== false
                        || strpos($linkLower, 'youtube.com') !== false
                        || strpos($linkLower, 'tiktok.com') !== false
                        || strpos($linkLower, 'pinterest.com') !== false
                    ) {
                        $analysis['same_as_has_social'] = true;
                    }

                    // OTA listings
                    if (strpos($linkLower, 'booking.com') !== false
                        || strpos($linkLower, 'tripadvisor.com') !== false
                        || strpos($linkLower, 'expedia.com') !== false
                        || strpos($linkLower, 'hotels.com') !== false
                        || strpos($linkLower, 'agoda.com') !== false
                        || strpos($linkLower, 'safari.com') !== false
                        || strpos($linkLower, 'safaribookings.com') !== false
                    ) {
                        $analysis['same_as_has_ota'] = true;
                    }

                    // Wikipedia / Wikidata
                    if (strpos($linkLower, 'wikipedia.org') !== false
                        || strpos($linkLower, 'wikidata.org') !== false
                    ) {
                        $analysis['same_as_has_wiki'] = true;
                    }
                }
            }

            // Check potentialAction with ReserveAction
            if (isset($schema['potentialAction'])) {
                $actions = is_array($schema['potentialAction'])
                    ? (isset($schema['potentialAction']['@type']) ? [$schema['potentialAction']] : $schema['potentialAction'])
                    : [];

                foreach ($actions as $action) {
                    if (isset($action['@type']) && stripos($action['@type'], 'ReserveAction') !== false) {
                        $analysis['has_reserve_action'] = true;
                    }
                }
            }

            // Check tourBookingPage
            if (isset($schema['tourBookingPage']) && !empty($schema['tourBookingPage'])) {
                $analysis['has_tour_booking_page'] = true;
            }
        }

        // Build missing properties list
        if (!$analysis['has_json_ld']) {
            $analysis['missing_properties'][] = 'JSON-LD format not used';
        }
        if (!$analysis['has_lodging_type']) {
            $analysis['missing_properties'][] = 'No LodgingBusiness/Hotel/Resort type found';
        }
        if (!$analysis['has_address']) {
            $analysis['missing_properties'][] = 'No address property in schema';
        }
        if (!$analysis['has_geo']) {
            $analysis['missing_properties'][] = 'No geo coordinates in schema';
        }
        if (!$analysis['has_rating']) {
            $analysis['missing_properties'][] = 'No aggregateRating in schema';
        }
        if (!$analysis['has_offers']) {
            $analysis['missing_properties'][] = 'No offers or priceRange in schema';
        }
        if (!$analysis['has_faq_page']) {
            $analysis['missing_properties'][] = 'No FAQPage schema found';
        }
        if (!$analysis['has_breadcrumb']) {
            $analysis['missing_properties'][] = 'No BreadcrumbList schema found';
        }
        if (!$analysis['has_room_markup']) {
            $analysis['missing_properties'][] = 'No individual room/accommodation markup';
        }
        if (!$analysis['has_main_entity']) {
            $analysis['missing_properties'][] = 'No mainEntityOfPage declared';
        }
        if (!$analysis['has_same_as']) {
            $analysis['missing_properties'][] = 'No sameAs links found';
        }
        if (!$analysis['has_reserve_action']) {
            $analysis['missing_properties'][] = 'No ReserveAction potentialAction found';
        }
        if (!$analysis['has_tour_booking_page']) {
            $analysis['missing_properties'][] = 'No tourBookingPage property found';
        }

        // Build quality issues
        if ($analysis['has_json_ld'] && $analysis['id_is_canonical'] === false) {
            $analysis['quality_issues'][] = '@id does not use a canonical URL';
        }
        if ($analysis['has_same_as'] && !$analysis['same_as_has_social']) {
            $analysis['quality_issues'][] = 'sameAs present but missing social media profiles';
        }
        if ($analysis['has_same_as'] && !$analysis['same_as_has_ota']) {
            $analysis['quality_issues'][] = 'sameAs present but missing OTA listing links';
        }
        if ($analysis['has_address'] && !$analysis['has_geo']) {
            $analysis['quality_issues'][] = 'Address found but no geo coordinates — add latitude/longitude';
        }

        return $analysis;
    }

    /**
     * Check if a Schema.org type represents a lodging business.
     *
     * @param string $type The @type value to check
     * @return bool        True if the type is lodging-related
     */
    public function isLodgingType(string $type): bool
    {
        foreach (self::LODGING_TYPES as $lodgingType) {
            if (stripos($type, $lodgingType) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a JSON-LD @id is a canonical URL (starts with http and matches the site domain).
     *
     * @param string $id  The @id value to check
     * @param string $url The website URL to compare against
     * @return bool       True if the @id appears to be a canonical URL
     */
    public function isCanonicalId(string $id, string $url): bool
    {
        // Must start with http
        if (stripos($id, 'http') !== 0) {
            return false;
        }

        // If we have a URL to compare against, check that the domains match
        if (!empty($url)) {
            $idHost  = strtolower(parse_url($id, PHP_URL_HOST) ?: '');
            $urlHost = strtolower(parse_url($url, PHP_URL_HOST) ?: '');

            // Strip www. for comparison
            $idHost  = preg_replace('/^www\./', '', $idHost);
            $urlHost = preg_replace('/^www\./', '', $urlHost);

            if ($idHost !== '' && $urlHost !== '' && $idHost !== $urlHost) {
                return false;
            }
        }

        return true;
    }

    /**
     * Extract properties from a JSON-LD schema object into a standardized format.
     *
     * @param array  $data   The decoded JSON-LD object
     * @param string $format The format identifier ('json-ld')
     * @return array         Standardized schema properties
     */
    private function extractSchemaProperties(array $data, string $format): array
    {
        $schema = [
            '_format' => $format,
        ];

        // Core JSON-LD fields
        $coreFields = [
            '@type', '@id', '@context', 'name', 'description', 'url',
            'telephone', 'image', 'mainEntityOfPage', 'sameAs',
            'priceRange', 'tourBookingPage',
        ];

        foreach ($coreFields as $field) {
            if (isset($data[$field])) {
                $schema[$field] = $data[$field];
            }
        }

        // Address — full breakdown
        if (isset($data['address'])) {
            if (is_array($data['address'])) {
                $schema['address'] = [
                    '@type'           => $data['address']['@type'] ?? 'PostalAddress',
                    'streetAddress'   => $data['address']['streetAddress'] ?? '',
                    'addressLocality' => $data['address']['addressLocality'] ?? '',
                    'addressRegion'   => $data['address']['addressRegion'] ?? '',
                    'postalCode'      => $data['address']['postalCode'] ?? '',
                    'addressCountry'  => $data['address']['addressCountry'] ?? '',
                ];
            } else {
                $schema['address'] = $data['address'];
            }
        }

        // Geo coordinates
        if (isset($data['geo'])) {
            if (is_array($data['geo'])) {
                $schema['geo'] = [
                    '@type'     => $data['geo']['@type'] ?? 'GeoCoordinates',
                    'latitude'  => $data['geo']['latitude'] ?? '',
                    'longitude' => $data['geo']['longitude'] ?? '',
                ];
            } else {
                $schema['geo'] = $data['geo'];
            }
        }

        // Aggregate rating
        if (isset($data['aggregateRating'])) {
            if (is_array($data['aggregateRating'])) {
                $schema['aggregateRating'] = [
                    '@type'       => $data['aggregateRating']['@type'] ?? 'AggregateRating',
                    'ratingValue' => $data['aggregateRating']['ratingValue'] ?? '',
                    'reviewCount' => $data['aggregateRating']['reviewCount'] ?? '',
                ];
            } else {
                $schema['aggregateRating'] = $data['aggregateRating'];
            }
        }

        // Offers / priceRange
        if (isset($data['offers'])) {
            $schema['offers'] = $data['offers'];
        }

        // potentialAction
        if (isset($data['potentialAction'])) {
            $schema['potentialAction'] = $data['potentialAction'];
        }

        // amenityFeature
        if (isset($data['amenityFeature'])) {
            $schema['amenityFeature'] = $data['amenityFeature'];
        }

        // Capture all remaining properties not yet extracted
        $handledKeys = array_merge($coreFields, [
            'address', 'geo', 'aggregateRating', 'offers',
            'potentialAction', 'amenityFeature', '_format',
        ]);

        foreach ($data as $key => $value) {
            if (!in_array($key, $handledKeys, true) && !isset($schema[$key])) {
                $schema[$key] = $value;
            }
        }

        return $schema;
    }

    /**
     * Extract itemprop values from a Microdata itemscope element.
     *
     * @param DOMNode  $node  The itemscope element
     * @param DOMXPath $xpath The XPath instance for querying
     * @return array          Associative array of property => value
     */
    private function extractMicrodataProperties(DOMNode $node, DOMXPath $xpath): array
    {
        $properties = [];

        $propNodes = $xpath->query('.//*[@itemprop]', $node);

        for ($i = 0; $i < $propNodes->length; $i++) {
            $propNode = $propNodes->item($i);
            $propName = $propNode->getAttribute('itemprop');

            if (empty($propName)) {
                continue;
            }

            // If the element has itemscope, it's a nested schema — extract its type and props
            if ($propNode->hasAttribute('itemscope')) {
                $nestedType = '';
                if ($propNode->hasAttribute('itemtype')) {
                    $nestedType = $propNode->getAttribute('itemtype');
                    if (preg_match('/schema\.org\/(.+)$/', $nestedType, $match)) {
                        $nestedType = $match[1];
                    }
                }
                $nestedProps = $this->extractMicrodataProperties($propNode, $xpath);
                $nestedProps['@type'] = $nestedType;
                $properties[$propName] = $nestedProps;
                continue;
            }

            // Extract value based on element type
            $tagName = strtolower($propNode->nodeName);
            $value   = '';

            if ($tagName === 'meta') {
                $value = $propNode->getAttribute('content');
            } elseif ($tagName === 'a' || $tagName === 'link') {
                $value = $propNode->getAttribute('href');
            } elseif ($tagName === 'img') {
                $value = $propNode->getAttribute('src');
            } elseif ($tagName === 'time') {
                $value = $propNode->getAttribute('datetime') ?: trim($propNode->textContent);
            } else {
                $value = trim($propNode->textContent);
            }

            // Handle multiple values for the same property
            if (isset($properties[$propName])) {
                if (!is_array($properties[$propName]) || !isset($properties[$propName][0])) {
                    $properties[$propName] = [$properties[$propName]];
                }
                $properties[$propName][] = $value;
            } else {
                $properties[$propName] = $value;
            }
        }

        return $properties;
    }

    /**
     * Extract property/content pairs from RDFa elements within a typeof container.
     *
     * @param DOMNode  $node  The typeof element
     * @param DOMXPath $xpath The XPath instance for querying
     * @return array          Associative array of property => value
     */
    private function extractRdfaProperties(DOMNode $node, DOMXPath $xpath): array
    {
        $properties = [];

        $propNodes = $xpath->query('.//*[@property]', $node);

        for ($i = 0; $i < $propNodes->length; $i++) {
            $propNode = $propNodes->item($i);
            $propName = $propNode->getAttribute('property');

            if (empty($propName)) {
                continue;
            }

            // If the element itself has typeof, it's a nested schema
            if ($propNode->hasAttribute('typeof')) {
                $nestedType  = $propNode->getAttribute('typeof');
                $nestedProps = $this->extractRdfaProperties($propNode, $xpath);
                $nestedProps['@type'] = $nestedType;
                $properties[$propName] = $nestedProps;
                continue;
            }

            // Extract value — prefer content attribute, then href, then text
            $value = $propNode->getAttribute('content');

            if ($value === '') {
                $value = $propNode->getAttribute('href');
            }
            if ($value === '') {
                $value = $propNode->getAttribute('src');
            }
            if ($value === '') {
                $value = trim($propNode->textContent);
            }

            // Handle multiple values for the same property
            if (isset($properties[$propName])) {
                if (!is_array($properties[$propName]) || !isset($properties[$propName][0])) {
                    $properties[$propName] = [$properties[$propName]];
                }
                $properties[$propName][] = $value;
            } else {
                $properties[$propName] = $value;
            }
        }

        return $properties;
    }

    /**
     * Determine the primary Schema.org type from all detected schemas.
     * Lodging-related types are preferred.
     *
     * @param array $schemas All detected schema objects
     * @return string        The primary type, or empty string if none found
     */
    private function determinePrimaryType(array $schemas): string
    {
        $primaryType = '';

        foreach ($schemas as $schema) {
            $type  = $schema['@type'] ?? '';
            $types = is_array($type) ? $type : [$type];

            foreach ($types as $t) {
                if ($this->isLodgingType($t)) {
                    return $t; // Lodging types take top priority
                }
                if ($primaryType === '' && !empty($t)) {
                    $primaryType = $t; // First non-empty type as fallback
                }
            }
        }

        return $primaryType;
    }
}
