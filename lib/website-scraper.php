<?php
/**
 * Filename: website-scraper.php
 * Description: Website scraper with cURL fetching, HTML parsing, and subpage discovery
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/logger.php';
require_once __DIR__ . '/rate-limiter.php';

class WebsiteScraper
{
    /**
     * @var string User-Agent string for all requests
     */
    private const USER_AGENT = 'SafariTravellerBot/1.0 (+https://safari-traveller.com/bot)';

    /**
     * @var int Connect timeout in seconds
     */
    private const CONNECT_TIMEOUT = 30;

    /**
     * @var int Total timeout in seconds
     */
    private const TOTAL_TIMEOUT = 60;

    /**
     * @var int Maximum number of redirects to follow
     */
    private const MAX_REDIRECTS = 5;

    /**
     * @var int Maximum number of subpages to scrape per property
     */
    private const MAX_SUBPAGES = 8;

    /**
     * @var int Rate limit delay between requests in seconds
     */
    private const RATE_LIMIT_DELAY_SECONDS = 3;

    /**
     * @var int Maximum body text length in characters
     */
    private const MAX_BODY_TEXT_LENGTH = 50000;

    /**
     * @var array Keywords to look for when discovering subpages
     */
    private const SUBPAGE_KEYWORDS = [
        'room'          => 'rooms',
        'accommodation' => 'rooms',
        'suite'         => 'rooms',
        'tent'          => 'rooms',
        'activity'      => 'activities',
        'activities'    => 'activities',
        'safari'        => 'activities',
        'dining'        => 'dining',
        'restaurant'    => 'dining',
        'contact'       => 'contact',
        'about'         => 'about',
        'rates'         => 'rates',
        'prices'        => 'rates',
        'gallery'       => 'gallery',
        'faq'           => 'faq',
    ];

    /**
     * @var array Priority order for subpage types when selecting which to scrape
     */
    private const SUBPAGE_PRIORITY = [
        'rooms',
        'activities',
        'dining',
        'contact',
        'about',
        'rates',
        'faq',
        'gallery',
    ];

    /**
     * Constructor — dependencies are loaded via require_once above.
     * Database, Logger, and RateLimiter are all available as static classes.
     */
    public function __construct()
    {
        // Dependencies initialized via require_once:
        // - Database (db.php)
        // - Logger (logger.php)
        // - RateLimiter (rate-limiter.php)
    }

    /**
     * Scrape a URL using cURL.
     *
     * @param string $url The URL to scrape
     * @return array{html: string, final_url: string, status_code: int, content_type: string, response_time_ms: int}|null
     *               Returns scrape result array on success, or null on failure
     */
    public function scrape(string $url): ?array
    {
        $startTime = microtime(true);

        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => self::MAX_REDIRECTS,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT        => self::TOTAL_TIMEOUT,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: en-US,en;q=0.5',
            ],
        ]);

        $html = curl_exec($ch);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        // If SSL verification failed, retry with verification disabled
        if ($curlErrno === CURLE_SSL_CONNECT_ERROR
            || $curlErrno === CURLE_SSL_CERTPROBLEM
            || $curlErrno === CURLE_SSL_CIPHER
            || $curlErrno === CURLE_SSL_CACERT
            || $curlErrno === CURLE_SSL_PEER_CERTIFICATE
        ) {
            Logger::info('WebsiteScraper', "SSL error for {$url}, retrying without verification: {$curlError}", [
                'url' => $url,
            ]);

            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            $html = curl_exec($ch);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);
        }

        $endTime = microtime(true);
        $responseTimeMs = (int) round(($endTime - $startTime) * 1000);

        if ($curlErrno !== 0) {
            Logger::error('WebsiteScraper', "cURL error scraping {$url}: [{$curlErrno}] {$curlError}", [
                'url'         => $url,
                'duration_ms' => $responseTimeMs,
            ]);
            curl_close($ch);
            return null;
        }

        $statusCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $finalUrl    = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '';

        curl_close($ch);

        $result = [
            'html'             => $html ?: '',
            'final_url'        => $finalUrl,
            'status_code'      => $statusCode,
            'content_type'     => $contentType,
            'response_time_ms' => $responseTimeMs,
        ];

        Logger::log('WebsiteScraper', 'scrape', [
            'url'           => $url,
            'status_code'   => $statusCode,
            'response_size' => strlen($html ?: ''),
            'duration_ms'   => $responseTimeMs,
            'metadata'      => [
                'final_url'    => $finalUrl,
                'content_type' => $contentType,
            ],
        ]);

        return $result;
    }

    /**
     * Extract structured page data from raw HTML using DOMDocument.
     *
     * @param string $html The raw HTML content
     * @param string $url  The page URL (used for resolving relative links)
     * @return array       Structured page data
     */
    public function extractPageData(string $html, string $url): array
    {
        $data = [
            'title'            => '',
            'meta_description' => '',
            'h1_headings'      => [],
            'h2_headings'      => [],
            'body_text'        => '',
            'word_count'       => 0,
            'links'            => [],
            'images'           => [],
            'canonical_url'    => '',
            'og_data'          => [],
        ];

        if (empty($html)) {
            return $data;
        }

        // Suppress DOM parsing warnings for malformed HTML
        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Title
        $titleNodes = $dom->getElementsByTagName('title');
        if ($titleNodes->length > 0) {
            $data['title'] = trim($titleNodes->item(0)->textContent);
        }

        // Meta description
        $metaNodes = $xpath->query('//meta[@name="description"]');
        if ($metaNodes->length > 0) {
            $data['meta_description'] = trim($metaNodes->item(0)->getAttribute('content'));
        }

        // H1 headings
        $h1Nodes = $dom->getElementsByTagName('h1');
        for ($i = 0; $i < $h1Nodes->length; $i++) {
            $text = trim($h1Nodes->item($i)->textContent);
            if ($text !== '') {
                $data['h1_headings'][] = $text;
            }
        }

        // H2 headings
        $h2Nodes = $dom->getElementsByTagName('h2');
        for ($i = 0; $i < $h2Nodes->length; $i++) {
            $text = trim($h2Nodes->item($i)->textContent);
            if ($text !== '') {
                $data['h2_headings'][] = $text;
            }
        }

        // Body text — strip tags and limit to MAX_BODY_TEXT_LENGTH characters
        $bodyNodes = $dom->getElementsByTagName('body');
        if ($bodyNodes->length > 0) {
            $bodyHtml = $dom->saveHTML($bodyNodes->item(0));
            // Remove script and style content before stripping tags
            $bodyHtml = preg_replace('/<script[^>]*>.*?<\/script>/is', '', $bodyHtml);
            $bodyHtml = preg_replace('/<style[^>]*>.*?<\/style>/is', '', $bodyHtml);
            $bodyText = strip_tags($bodyHtml);
            // Collapse whitespace
            $bodyText = preg_replace('/\s+/', ' ', $bodyText);
            $bodyText = trim($bodyText);
            $data['body_text']  = mb_substr($bodyText, 0, self::MAX_BODY_TEXT_LENGTH);
            $data['word_count'] = str_word_count($data['body_text']);
        }

        // Links
        $linkNodes = $dom->getElementsByTagName('a');
        for ($i = 0; $i < $linkNodes->length; $i++) {
            $node = $linkNodes->item($i);
            $href = trim($node->getAttribute('href'));
            $text = trim($node->textContent);

            if ($href === '' || strpos($href, '#') === 0 || strpos($href, 'javascript:') === 0) {
                continue;
            }

            $resolvedHref = $this->normalizeUrl($href, $url);

            $data['links'][] = [
                'href'        => $resolvedHref,
                'text'        => $text,
                'is_internal' => $this->isInternalLink($resolvedHref, $url),
            ];
        }

        // Images
        $imgNodes = $dom->getElementsByTagName('img');
        for ($i = 0; $i < $imgNodes->length; $i++) {
            $node = $imgNodes->item($i);
            $src  = trim($node->getAttribute('src'));
            $alt  = trim($node->getAttribute('alt'));

            if ($src === '') {
                continue;
            }

            $resolvedSrc = $this->normalizeUrl($src, $url);

            $data['images'][] = [
                'src'         => $resolvedSrc,
                'alt'         => $alt,
                'is_external' => !$this->isInternalLink($resolvedSrc, $url),
            ];
        }

        // Canonical URL
        $canonicalNodes = $xpath->query('//link[@rel="canonical"]');
        if ($canonicalNodes->length > 0) {
            $data['canonical_url'] = trim($canonicalNodes->item(0)->getAttribute('href'));
        }

        // Open Graph meta tags
        $ogNodes = $xpath->query('//meta[starts-with(@property, "og:")]');
        for ($i = 0; $i < $ogNodes->length; $i++) {
            $node     = $ogNodes->item($i);
            $property = $node->getAttribute('property');
            $content  = $node->getAttribute('content');
            // Strip the "og:" prefix for the key
            $key = substr($property, 3);
            $data['og_data'][$key] = $content;
        }

        return $data;
    }

    /**
     * Discover key subpages from the HTML of a property's main page.
     * Looks for internal links containing lodging-relevant keywords.
     *
     * @param string $html    The raw HTML content of the main page
     * @param string $baseUrl The base URL of the property website
     * @return array          Array of [url, page_type] for discovered subpages
     */
    public function discoverSubpages(string $html, string $baseUrl): array
    {
        $subpages = [];
        $seen     = [];

        if (empty($html)) {
            return $subpages;
        }

        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();

        $linkNodes = $dom->getElementsByTagName('a');

        for ($i = 0; $i < $linkNodes->length; $i++) {
            $node = $linkNodes->item($i);
            $href = trim($node->getAttribute('href'));
            $text = trim($node->textContent);

            if ($href === '' || strpos($href, '#') === 0 || strpos($href, 'javascript:') === 0) {
                continue;
            }

            $resolvedUrl = $this->normalizeUrl($href, $baseUrl);

            // Only consider internal links
            if (!$this->isInternalLink($resolvedUrl, $baseUrl)) {
                continue;
            }

            // Skip if we've already seen this URL
            $normalizedCheck = rtrim(strtolower($resolvedUrl), '/');
            if (isset($seen[$normalizedCheck])) {
                continue;
            }

            // Check the href and link text against our keywords
            $hrefLower = strtolower($href);
            $textLower = strtolower($text);
            $combined  = $hrefLower . ' ' . $textLower;

            foreach (self::SUBPAGE_KEYWORDS as $keyword => $pageType) {
                if (strpos($combined, $keyword) !== false) {
                    $subpages[] = [
                        'url'       => $resolvedUrl,
                        'page_type' => $pageType,
                    ];
                    $seen[$normalizedCheck] = true;
                    break; // One match per link is sufficient
                }
            }
        }

        return $subpages;
    }

    /**
     * Perform a full scrape of a property: main page, discover subpages, scrape subpages.
     *
     * @param array $property The property record from the database (must include 'id' and 'website')
     * @return array          Combined scrape data with 'main_page' and 'subpages' keys
     */
    public function scrapeProperty(array $property): array
    {
        $result = [
            'main_page' => null,
            'subpages'  => [],
        ];

        $websiteUrl = $property['website'] ?? '';

        if (empty($websiteUrl)) {
            Logger::error('WebsiteScraper', 'No website URL for property', [
                'metadata' => ['property_id' => $property['id'] ?? null],
            ]);
            return $result;
        }

        // Extract domain for rate limiting
        $domain = parse_url($websiteUrl, PHP_URL_HOST) ?: $websiteUrl;

        // Rate-limit before the main page request
        RateLimiter::waitForDomain($domain);

        // Scrape the main page
        $mainScrape = $this->scrape($websiteUrl);

        if ($mainScrape === null) {
            return $result;
        }

        $mainPageData = $this->extractPageData($mainScrape['html'], $mainScrape['final_url']);

        $result['main_page'] = [
            'url'            => $mainScrape['final_url'],
            'status_code'    => $mainScrape['status_code'],
            'content_type'   => $mainScrape['content_type'],
            'response_time_ms' => $mainScrape['response_time_ms'],
            'page_data'      => $mainPageData,
        ];

        // Save main page scrape to property_scrapes table
        $this->saveScrape($property['id'], $mainScrape, 'main');

        // Discover subpages from the main page
        $discoveredSubpages = $this->discoverSubpages($mainScrape['html'], $mainScrape['final_url']);

        // Prioritize and limit subpages
        $prioritizedSubpages = $this->prioritizeSubpages($discoveredSubpages);
        $subpagesToScrape    = array_slice($prioritizedSubpages, 0, self::MAX_SUBPAGES);

        // Scrape each subpage with rate limiting
        foreach ($subpagesToScrape as $subpage) {
            // Rate-limit between each request (3 seconds)
            sleep(self::RATE_LIMIT_DELAY_SECONDS);
            RateLimiter::recordRequest($domain);

            $subScrape = $this->scrape($subpage['url']);

            if ($subScrape === null) {
                continue;
            }

            $subPageData = $this->extractPageData($subScrape['html'], $subScrape['final_url']);

            $result['subpages'][] = [
                'url'              => $subScrape['final_url'],
                'page_type'        => $subpage['page_type'],
                'status_code'      => $subScrape['status_code'],
                'content_type'     => $subScrape['content_type'],
                'response_time_ms' => $subScrape['response_time_ms'],
                'page_data'        => $subPageData,
            ];

            // Save subpage scrape to property_scrapes table
            $this->saveScrape($property['id'], $subScrape, $subpage['page_type']);
        }

        return $result;
    }

    /**
     * Check for an LLM.txt file at the property's website.
     * Tries {baseUrl}/llm.txt and {baseUrl}/.well-known/llm.txt.
     *
     * @param string $baseUrl The base URL of the property website
     * @return string|null    The content of the LLM.txt file, or null if not found
     */
    public function checkLlmTxt(string $baseUrl): ?string
    {
        $baseUrl = rtrim($baseUrl, '/');

        // Try /llm.txt first
        $urls = [
            $baseUrl . '/llm.txt',
            $baseUrl . '/.well-known/llm.txt',
        ];

        foreach ($urls as $url) {
            $result = $this->scrape($url);

            if ($result !== null
                && $result['status_code'] === 200
                && !empty($result['html'])
                && strpos($result['content_type'], 'text/') !== false
            ) {
                return $result['html'];
            }
        }

        return null;
    }

    /**
     * Fetch and return the robots.txt content for a website.
     *
     * @param string $baseUrl The base URL of the property website
     * @return string|null    The robots.txt content, or null if not found
     */
    public function checkRobotsTxt(string $baseUrl): ?string
    {
        $baseUrl = rtrim($baseUrl, '/');
        $url     = $baseUrl . '/robots.txt';

        $result = $this->scrape($url);

        if ($result !== null
            && $result['status_code'] === 200
            && !empty($result['html'])
        ) {
            return $result['html'];
        }

        return null;
    }

    /**
     * Check for a sitemap.xml and return its URL if found.
     *
     * @param string $baseUrl The base URL of the property website
     * @return string|null    The sitemap URL if found, or null
     */
    public function checkSitemapXml(string $baseUrl): ?string
    {
        $baseUrl    = rtrim($baseUrl, '/');
        $sitemapUrl = $baseUrl . '/sitemap.xml';

        $result = $this->scrape($sitemapUrl);

        if ($result !== null
            && $result['status_code'] === 200
            && !empty($result['html'])
            && (strpos($result['content_type'], 'xml') !== false
                || strpos($result['html'], '<?xml') !== false
                || strpos($result['html'], '<urlset') !== false
                || strpos($result['html'], '<sitemapindex') !== false)
        ) {
            return $result['final_url'];
        }

        return null;
    }

    /**
     * Resolve a relative URL against a base URL.
     *
     * @param string $url     The URL to normalize (may be relative)
     * @param string $baseUrl The base URL to resolve against
     * @return string         The fully resolved absolute URL
     */
    public function normalizeUrl(string $url, string $baseUrl): string
    {
        $url = trim($url);

        // Already absolute
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        // Protocol-relative
        if (strpos($url, '//') === 0) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $url;
        }

        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host   = $parsed['host'] ?? '';
        $port   = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        $baseOrigin = $scheme . '://' . $host . $port;

        // Absolute path
        if (strpos($url, '/') === 0) {
            return $baseOrigin . $url;
        }

        // Relative path — resolve against the directory of the base URL
        $basePath = $parsed['path'] ?? '/';
        $baseDir  = rtrim(dirname($basePath), '/');

        return $baseOrigin . $baseDir . '/' . $url;
    }

    /**
     * Check if a URL is internal (same domain as the base URL).
     *
     * @param string $url     The URL to check
     * @param string $baseUrl The base URL to compare against
     * @return bool           True if the URL is internal
     */
    public function isInternalLink(string $url, string $baseUrl): bool
    {
        $urlHost  = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        $baseHost = strtolower(parse_url($baseUrl, PHP_URL_HOST) ?: '');

        if ($urlHost === '' || $baseHost === '') {
            return true; // Relative URL is considered internal
        }

        // Strip www. for comparison
        $urlHost  = preg_replace('/^www\./', '', $urlHost);
        $baseHost = preg_replace('/^www\./', '', $baseHost);

        return $urlHost === $baseHost;
    }

    /**
     * Prioritize subpages by type according to SUBPAGE_PRIORITY order.
     *
     * @param array $subpages Array of [url, page_type] entries
     * @return array          Sorted subpages with higher-priority types first
     */
    private function prioritizeSubpages(array $subpages): array
    {
        $priorityMap = array_flip(self::SUBPAGE_PRIORITY);

        usort($subpages, function ($a, $b) use ($priorityMap) {
            $priorityA = $priorityMap[$a['page_type']] ?? 999;
            $priorityB = $priorityMap[$b['page_type']] ?? 999;
            return $priorityA - $priorityB;
        });

        // Deduplicate by page_type — keep only the first URL for each type
        $seenTypes = [];
        $unique    = [];

        foreach ($subpages as $subpage) {
            if (!isset($seenTypes[$subpage['page_type']])) {
                $unique[] = $subpage;
                $seenTypes[$subpage['page_type']] = true;
            }
        }

        return $unique;
    }

    /**
     * Save a scrape result to the property_scrapes table.
     *
     * @param int    $propertyId The property ID
     * @param array  $scrapeData The scrape result from scrape()
     * @param string $pageType   The page type (e.g. 'main', 'rooms', 'activities')
     * @return void
     */
    private function saveScrape(int $propertyId, array $scrapeData, string $pageType): void
    {
        try {
            Database::insert('property_scrapes', [
                'property_id'      => $propertyId,
                'url'              => $scrapeData['final_url'],
                'page_type'        => $pageType,
                'status_code'      => $scrapeData['status_code'],
                'content_type'     => $scrapeData['content_type'],
                'html'             => $scrapeData['html'],
                'response_time_ms' => $scrapeData['response_time_ms'],
                'scraped_at'       => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Logger::error('WebsiteScraper', "Failed to save scrape for property {$propertyId}: " . $e->getMessage(), [
                'url'      => $scrapeData['final_url'],
                'metadata' => ['property_id' => $propertyId, 'page_type' => $pageType],
            ]);
        }
    }
}
