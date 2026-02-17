<?php
/**
 * Filename: google-places.php
 * Description: Google Places API discovery source
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/logger.php';
require_once __DIR__ . '/../lib/rate-limiter.php';
require_once __DIR__ . '/../lib/cost-tracker.php';

class GooglePlacesSource
{
    /**
     * @var string Source identifier used for logging
     */
    private const SOURCE_NAME = 'google_places';

    /**
     * @var string Google Places Text Search API endpoint
     */
    private const TEXT_SEARCH_URL = 'https://maps.googleapis.com/maps/api/place/textsearch/json';

    /**
     * @var string Google Places Details API endpoint
     */
    private const PLACE_DETAILS_URL = 'https://maps.googleapis.com/maps/api/place/details/json';

    /**
     * @var float Cost per Text Search API request in USD
     */
    private const COST_TEXT_SEARCH = 0.032;

    /**
     * @var float Cost per Place Details API request in USD (Contact + Basic fields)
     */
    private const COST_PLACE_DETAILS = 0.020;

    /**
     * @var int Seconds to wait before using a next_page_token (Google requirement)
     */
    private const PAGINATION_DELAY_SECONDS = 2;

    /**
     * @var int cURL timeout in seconds
     */
    private const REQUEST_TIMEOUT = 30;

    /**
     * @var string Google API key
     */
    private string $apiKey;

    /**
     * @var RateLimiter Rate limiter instance
     */
    private RateLimiter $rateLimiter;

    /**
     * @var CostTracker Cost tracker instance
     */
    private CostTracker $costTracker;

    /**
     * Constructor.
     * Loads configuration, initializes the database connection, logger,
     * rate limiter, and cost tracker.
     */
    public function __construct()
    {
        // Ensure config is loaded (db.php already requires it, but be explicit)
        if (!defined('GOOGLE_PLACES_API_KEY')) {
            throw new \RuntimeException('GOOGLE_PLACES_API_KEY is not defined in config.php');
        }

        $this->apiKey      = GOOGLE_PLACES_API_KEY;
        $this->rateLimiter = new RateLimiter(self::SOURCE_NAME);
        $this->costTracker = new CostTracker(self::SOURCE_NAME);

        // Ensure database connection is available
        Database::getInstance();

        Logger::info(self::SOURCE_NAME, 'GooglePlacesSource initialized');
    }

    /**
     * Discover lodging properties via the Google Places Text Search API.
     *
     * Builds a search query from the given search term and country name,
     * paginates through all results, and returns an array of discovered places.
     *
     * @param string $countryName The country to search within (e.g. "Kenya")
     * @param string $searchTerm  The search term (e.g. "safari lodge")
     * @return array              Array of place result arrays
     */
    public function discover(string $countryName, string $searchTerm): array
    {
        $query  = "{$searchTerm} in {$countryName}";
        $places = [];
        $nextPageToken = null;

        Logger::info(self::SOURCE_NAME, "Starting discovery: \"{$query}\"");

        do {
            // Build request parameters
            $params = [
                'query' => $query,
                'type'  => 'lodging',
                'key'   => $this->apiKey,
            ];

            if ($nextPageToken !== null) {
                // Google requires a short delay before the next_page_token becomes valid
                sleep(self::PAGINATION_DELAY_SECONDS);
                $params['pagetoken'] = $nextPageToken;
            }

            // Respect rate limits
            $this->rateLimiter->wait();

            // Track cost for this Text Search request
            $this->costTracker->addCost(self::COST_TEXT_SEARCH, 'text_search');

            // Make the API request
            $response = $this->makeApiRequest(self::TEXT_SEARCH_URL, $params);

            // Log the API call
            Logger::log(self::SOURCE_NAME, 'api_call', [
                'url'           => self::TEXT_SEARCH_URL,
                'cost_estimate' => self::COST_TEXT_SEARCH,
                'metadata'      => [
                    'query'      => $query,
                    'status'     => $response['status'] ?? 'UNKNOWN',
                    'results'    => count($response['results'] ?? []),
                    'page_token' => $nextPageToken !== null ? 'yes' : 'no',
                ],
            ]);

            // Check for API errors
            if (!isset($response['status']) || $response['status'] !== 'OK') {
                $errorMsg = $response['error_message'] ?? ($response['status'] ?? 'Unknown error');

                // ZERO_RESULTS is not an error, just means no more results
                if (($response['status'] ?? '') === 'ZERO_RESULTS') {
                    Logger::info(self::SOURCE_NAME, "No results for query: \"{$query}\"");
                    break;
                }

                Logger::error(self::SOURCE_NAME, "Text Search API error: {$errorMsg}", [
                    'url'      => self::TEXT_SEARCH_URL,
                    'metadata' => ['query' => $query, 'response_status' => $response['status'] ?? null],
                ]);
                break;
            }

            // Extract place data from results
            foreach ($response['results'] as $result) {
                $places[] = [
                    'place_id'           => $result['place_id'] ?? null,
                    'name'               => $result['name'] ?? null,
                    'formatted_address'  => $result['formatted_address'] ?? null,
                    'latitude'           => $result['geometry']['location']['lat'] ?? null,
                    'longitude'          => $result['geometry']['location']['lng'] ?? null,
                    'rating'             => $result['rating'] ?? null,
                    'user_ratings_total' => $result['user_ratings_total'] ?? null,
                    'types'              => $result['types'] ?? [],
                    'business_status'    => $result['business_status'] ?? null,
                ];
            }

            // Check for pagination
            $nextPageToken = $response['next_page_token'] ?? null;

        } while ($nextPageToken !== null);

        Logger::info(self::SOURCE_NAME, "Discovery complete: found " . count($places) . " places for \"{$query}\"");

        return $places;
    }

    /**
     * Retrieve additional details for a specific place from the Place Details API.
     *
     * Uses a restricted field mask to minimize cost:
     * - Contact fields ($0.003): formatted_phone_number, international_phone_number, opening_hours, website
     * - Basic fields ($0.017): name, formatted_address, url
     *
     * @param string $placeId The Google Place ID
     * @return array          Associative array of place details
     */
    public function getPlaceDetails(string $placeId): array
    {
        $fields = implode(',', [
            'website',
            'formatted_phone_number',
            'international_phone_number',
            'opening_hours',
            'url',
            'name',
            'formatted_address',
        ]);

        $params = [
            'place_id' => $placeId,
            'fields'   => $fields,
            'key'      => $this->apiKey,
        ];

        // Respect rate limits
        $this->rateLimiter->wait();

        // Track cost for this Place Details request
        $this->costTracker->addCost(self::COST_PLACE_DETAILS, 'place_details');

        // Make the API request
        $response = $this->makeApiRequest(self::PLACE_DETAILS_URL, $params);

        // Log the API call
        Logger::log(self::SOURCE_NAME, 'api_call', [
            'url'           => self::PLACE_DETAILS_URL,
            'cost_estimate' => self::COST_PLACE_DETAILS,
            'metadata'      => [
                'place_id' => $placeId,
                'fields'   => $fields,
                'status'   => $response['status'] ?? 'UNKNOWN',
            ],
        ]);

        // Check for API errors
        if (!isset($response['status']) || $response['status'] !== 'OK') {
            $errorMsg = $response['error_message'] ?? ($response['status'] ?? 'Unknown error');
            Logger::error(self::SOURCE_NAME, "Place Details API error: {$errorMsg}", [
                'url'      => self::PLACE_DETAILS_URL,
                'metadata' => ['place_id' => $placeId],
            ]);
            return [];
        }

        $result  = $response['result'] ?? [];
        $details = [
            'website'                      => $result['website'] ?? null,
            'formatted_phone_number'       => $result['formatted_phone_number'] ?? null,
            'international_phone_number'   => $result['international_phone_number'] ?? null,
            'opening_hours'                => $result['opening_hours'] ?? null,
            'url'                          => $result['url'] ?? null,
            'name'                         => $result['name'] ?? null,
            'formatted_address'            => $result['formatted_address'] ?? null,
        ];

        return $details;
    }

    /**
     * Save a discovered property to the database.
     *
     * Deduplicates on place_id using INSERT ... ON DUPLICATE KEY UPDATE.
     * Also creates or updates an entry in the property_sources table.
     *
     * @param array $placeData   Place data from discover()
     * @param array $detailsData Details data from getPlaceDetails()
     * @param int   $countryId   The ID of the country in our database
     * @return int               The property ID (inserted or existing)
     */
    public function saveProperty(array $placeData, array $detailsData, int $countryId): int
    {
        $name = $placeData['name'] ?? 'Unknown Property';
        $slug = $this->generateSlug($name);

        $propertyType = $this->mapPropertyType($placeData['types'] ?? []);

        // Merge place data with detail data (details take precedence for overlapping fields)
        $website        = $detailsData['website'] ?? null;
        $phone          = $detailsData['international_phone_number']
                          ?? $detailsData['formatted_phone_number']
                          ?? null;
        $googleMapsUrl  = $detailsData['url'] ?? null;

        // Use the address from details if available, otherwise from discovery
        $address = $detailsData['formatted_address']
                   ?? $placeData['formatted_address']
                   ?? null;

        // Use the name from details if available, otherwise from discovery
        $resolvedName = $detailsData['name'] ?? $name;
        $slug         = $this->generateSlug($resolvedName);

        $now = date('Y-m-d H:i:s');

        // Build the SQL for upsert
        $sql = "INSERT INTO `properties` (
                    `place_id`,
                    `country_id`,
                    `name`,
                    `slug`,
                    `formatted_address`,
                    `latitude`,
                    `longitude`,
                    `rating`,
                    `user_ratings_total`,
                    `property_type`,
                    `business_status`,
                    `website`,
                    `phone`,
                    `google_maps_url`,
                    `created_at`,
                    `updated_at`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    `name`                = VALUES(`name`),
                    `slug`                = VALUES(`slug`),
                    `formatted_address`   = VALUES(`formatted_address`),
                    `latitude`            = VALUES(`latitude`),
                    `longitude`           = VALUES(`longitude`),
                    `rating`              = VALUES(`rating`),
                    `user_ratings_total`  = VALUES(`user_ratings_total`),
                    `property_type`       = VALUES(`property_type`),
                    `business_status`     = VALUES(`business_status`),
                    `website`             = VALUES(`website`),
                    `phone`               = VALUES(`phone`),
                    `google_maps_url`     = VALUES(`google_maps_url`),
                    `updated_at`          = VALUES(`updated_at`)";

        $params = [
            $placeData['place_id'],
            $countryId,
            $resolvedName,
            $slug,
            $address,
            $placeData['latitude'] ?? null,
            $placeData['longitude'] ?? null,
            $placeData['rating'] ?? null,
            $placeData['user_ratings_total'] ?? null,
            $propertyType,
            $placeData['business_status'] ?? null,
            $website,
            $phone,
            $googleMapsUrl,
            $now,
            $now,
        ];

        Database::query($sql, $params);

        // Retrieve the property ID (works for both insert and update)
        $property = Database::fetch(
            "SELECT `id` FROM `properties` WHERE `place_id` = ?",
            [$placeData['place_id']]
        );

        $propertyId = (int) ($property['id'] ?? 0);

        // Save to property_sources table for provenance tracking
        $rawData = json_encode(
            ['place' => $placeData, 'details' => $detailsData],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        $sourceSql = "INSERT INTO `property_sources` (
                          `property_id`,
                          `source_name`,
                          `source_id`,
                          `raw_data`,
                          `created_at`,
                          `updated_at`
                      ) VALUES (?, ?, ?, ?, ?, ?)
                      ON DUPLICATE KEY UPDATE
                          `raw_data`   = VALUES(`raw_data`),
                          `updated_at` = VALUES(`updated_at`)";

        $sourceParams = [
            $propertyId,
            self::SOURCE_NAME,
            $placeData['place_id'],
            $rawData,
            $now,
            $now,
        ];

        Database::query($sourceSql, $sourceParams);

        Logger::info(self::SOURCE_NAME, "Saved property: {$resolvedName} (ID: {$propertyId})", [
            'metadata' => [
                'property_id' => $propertyId,
                'place_id'    => $placeData['place_id'],
                'slug'        => $slug,
            ],
        ]);

        return $propertyId;
    }

    /**
     * Discover properties, fetch their details, and save them to the database.
     *
     * Combines discover(), getPlaceDetails(), and saveProperty() into a single
     * workflow. Respects rate limits and budget constraints throughout.
     *
     * @param string $countryName The country to search within
     * @param int    $countryId   The country's database ID
     * @param string $searchTerm  The search term for discovery
     * @return int                Number of properties saved
     */
    public function discoverAndSave(string $countryName, int $countryId, string $searchTerm): int
    {
        Logger::info(self::SOURCE_NAME, "Starting discoverAndSave for \"{$searchTerm}\" in {$countryName}");

        // Check budget before starting discovery
        if (!$this->costTracker->isWithinBudget()) {
            Logger::error(self::SOURCE_NAME, 'Budget exceeded before discovery could begin', [
                'metadata' => [
                    'country'     => $countryName,
                    'search_term' => $searchTerm,
                ],
            ]);
            return 0;
        }

        // Discover places via Text Search API
        $places = $this->discover($countryName, $searchTerm);

        if (empty($places)) {
            Logger::info(self::SOURCE_NAME, "No places discovered for \"{$searchTerm}\" in {$countryName}");
            return 0;
        }

        $savedCount = 0;

        foreach ($places as $placeData) {
            // Check budget before each API call
            if (!$this->costTracker->isWithinBudget()) {
                Logger::info(self::SOURCE_NAME, "Budget exceeded after saving {$savedCount} properties. Stopping.", [
                    'metadata' => [
                        'country'       => $countryName,
                        'search_term'   => $searchTerm,
                        'saved_count'   => $savedCount,
                        'total_found'   => count($places),
                    ],
                ]);
                break;
            }

            // Skip if place_id is missing
            if (empty($placeData['place_id'])) {
                Logger::error(self::SOURCE_NAME, 'Skipping place with missing place_id', [
                    'metadata' => ['name' => $placeData['name'] ?? 'unknown'],
                ]);
                continue;
            }

            try {
                // Fetch additional details for this place
                $detailsData = $this->getPlaceDetails($placeData['place_id']);

                // Save the property to the database
                $this->saveProperty($placeData, $detailsData, $countryId);
                $savedCount++;
            } catch (\Throwable $e) {
                Logger::error(self::SOURCE_NAME, "Error processing place: {$e->getMessage()}", [
                    'metadata' => [
                        'place_id' => $placeData['place_id'],
                        'name'     => $placeData['name'] ?? 'unknown',
                    ],
                ]);
                // Continue with next place
                continue;
            }
        }

        Logger::info(self::SOURCE_NAME, "discoverAndSave complete: saved {$savedCount} of " . count($places) . " places", [
            'metadata' => [
                'country'     => $countryName,
                'search_term' => $searchTerm,
                'saved_count' => $savedCount,
                'total_found' => count($places),
            ],
        ]);

        return $savedCount;
    }

    /**
     * Make a cURL GET request to a Google API endpoint.
     *
     * @param string $url    The API endpoint URL
     * @param array  $params Query string parameters
     * @return array         Decoded JSON response
     * @throws \RuntimeException If the request fails
     */
    private function makeApiRequest(string $url, array $params): array
    {
        $fullUrl = $url . '?' . http_build_query($params);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $fullUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::REQUEST_TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/json',
            ],
        ]);

        $startTime    = microtime(true);
        $responseBody = curl_exec($ch);
        $durationMs   = (int) round((microtime(true) - $startTime) * 1000);

        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        curl_close($ch);

        // Handle cURL errors
        if ($curlErrno !== 0) {
            $errorMsg = "cURL error ({$curlErrno}): {$curlError}";
            Logger::error(self::SOURCE_NAME, $errorMsg, [
                'url'         => $url,
                'status_code' => $httpCode,
                'duration_ms' => $durationMs,
            ]);
            throw new \RuntimeException($errorMsg);
        }

        // Handle non-200 HTTP responses
        if ($httpCode !== 200) {
            $errorMsg = "HTTP error: received status code {$httpCode}";
            Logger::error(self::SOURCE_NAME, $errorMsg, [
                'url'           => $url,
                'status_code'   => $httpCode,
                'duration_ms'   => $durationMs,
                'response_size' => strlen($responseBody),
            ]);
            throw new \RuntimeException($errorMsg);
        }

        // Decode JSON response
        $decoded = json_decode($responseBody, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $errorMsg = 'Failed to decode JSON response: ' . json_last_error_msg();
            Logger::error(self::SOURCE_NAME, $errorMsg, [
                'url'           => $url,
                'status_code'   => $httpCode,
                'duration_ms'   => $durationMs,
                'response_size' => strlen($responseBody),
            ]);
            throw new \RuntimeException($errorMsg);
        }

        return $decoded;
    }

    /**
     * Generate a URL-friendly slug from a property name.
     *
     * Converts to lowercase, replaces non-alphanumeric characters with hyphens,
     * collapses multiple hyphens, and trims leading/trailing hyphens.
     *
     * @param string $name The property name
     * @return string      URL-friendly slug
     */
    private function generateSlug(string $name): string
    {
        // Convert to lowercase
        $slug = mb_strtolower($name, 'UTF-8');

        // Replace any character that is not alphanumeric or a hyphen with a hyphen
        $slug = preg_replace('/[^a-z0-9\-]/', '-', $slug);

        // Collapse multiple consecutive hyphens into one
        $slug = preg_replace('/-+/', '-', $slug);

        // Trim leading and trailing hyphens
        $slug = trim($slug, '-');

        return $slug;
    }

    /**
     * Map Google place types to our internal property_type enum.
     *
     * Google returns an array of type strings (e.g. "lodging", "campground",
     * "rv_park"). This method maps those to our property_type values.
     *
     * @param array $googleTypes Array of Google place type strings
     * @return string            Our internal property_type value
     */
    private function mapPropertyType(array $googleTypes): string
    {
        // Priority-ordered mapping from Google types to our property types
        $typeMap = [
            'campground'        => 'campsite',
            'rv_park'           => 'campsite',
            'lodging'           => 'lodge',
            'resort'            => 'resort',
            'spa'               => 'resort',
            'hotel'             => 'hotel',
            'motel'             => 'hotel',
            'bed_and_breakfast'  => 'guesthouse',
            'guest_house'       => 'guesthouse',
            'hostel'            => 'hostel',
            'apartment'         => 'self_catering',
            'vacation_rental'   => 'self_catering',
        ];

        // Check each Google type against our map in priority order
        foreach ($typeMap as $googleType => $ourType) {
            if (in_array($googleType, $googleTypes, true)) {
                return $ourType;
            }
        }

        // Default to "lodge" for unrecognized lodging types
        return 'lodge';
    }
}
