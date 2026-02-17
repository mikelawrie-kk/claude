<?php
/**
 * Filename: platform-detector.php
 * Description: Detects CMS platforms, booking engines, and analytics tools from HTML
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */

class PlatformDetector
{
    /**
     * @var array CMS detection signatures — each entry is [pattern => cms_name]
     */
    private const CMS_SIGNATURES = [
        'wordpress' => [
            'wp-content',
            'wp-includes',
            'wp-json',
        ],
        'drupal' => [
            'sites/default',
            'drupal.js',
            'Drupal.settings',
        ],
        'joomla' => [
            '/media/jui',
            'Joomla!',
        ],
        'wix' => [
            'wix.com',
            '_wix',
        ],
        'squarespace' => [
            'squarespace.com',
            'sqsp',
        ],
        'webflow' => [
            'webflow.com',
            'w-nav',
        ],
        'springnest' => [
            'springnest.com',
        ],
    ];

    /**
     * @var array Booking engine detection signatures
     */
    private const BOOKING_SIGNATURES = [
        'beds24' => [
            'beds24.com',
        ],
        'siteminder' => [
            'siteminder',
            'thechannel',
        ],
        'nightsbridge' => [
            'nightsbridge.co.za',
            'nightsbridge.com',
        ],
        'semper' => [
            'semper.travel',
        ],
        'rezgateway' => [
            'rezgateway',
        ],
        'bookingbutton' => [
            'bookingbutton',
        ],
        'booking.com_widget' => [
            'booking.com/hotel',
        ],
        'netaffinity' => [
            'netaffinity',
        ],
        'simplotel' => [
            'simplotel',
        ],
    ];

    /**
     * @var array OTA domains used to detect OTA-only booking patterns
     */
    private const OTA_DOMAINS = [
        'booking.com',
        'expedia.com',
        'hotels.com',
        'agoda.com',
        'tripadvisor.com',
        'safaribookings.com',
        'safari.com',
    ];

    /**
     * Main detection method — returns a complete platform profile.
     *
     * @param string $html    The raw HTML content
     * @param array  $headers Optional HTTP response headers
     * @return array          Platform profile with cms, booking_engine, analytics keys
     */
    public function detect(string $html, array $headers = []): array
    {
        return [
            'cms'            => $this->detectCms($html, $headers),
            'booking_engine' => $this->detectBookingEngine($html),
            'analytics'      => $this->detectAnalytics($html),
        ];
    }

    /**
     * Detect the CMS / website platform used.
     *
     * @param string $html    The raw HTML content
     * @param array  $headers Optional HTTP response headers
     * @return string         The detected CMS identifier
     */
    public function detectCms(string $html, array $headers = []): string
    {
        if (empty($html)) {
            return 'unknown';
        }

        $htmlLower = strtolower($html);

        // Check WordPress meta generator tag specifically
        if (preg_match('/meta[^>]+generator[^>]+wordpress/i', $html)) {
            return 'wordpress';
        }

        // Check each CMS signature set
        foreach (self::CMS_SIGNATURES as $cms => $signatures) {
            foreach ($signatures as $signature) {
                if (strpos($htmlLower, strtolower($signature)) !== false) {
                    return $cms;
                }
            }
        }

        // Check headers for CMS hints
        $headerString = strtolower(implode(' ', $headers));

        if (strpos($headerString, 'x-powered-by: wp') !== false
            || strpos($headerString, 'x-wordpress') !== false
        ) {
            return 'wordpress';
        }

        if (strpos($headerString, 'x-drupal') !== false
            || strpos($headerString, 'x-generator: drupal') !== false
        ) {
            return 'drupal';
        }

        // If none matched, check if the HTML has enough structure to be "custom"
        // vs truly unknown (e.g. a parking page)
        if (strlen($html) > 500
            && (strpos($htmlLower, '<nav') !== false
                || strpos($htmlLower, '<header') !== false
                || strpos($htmlLower, '<footer') !== false)
        ) {
            return 'custom';
        }

        return 'unknown';
    }

    /**
     * Detect the booking engine / reservation system used.
     *
     * @param string $html The raw HTML content
     * @return string      The detected booking engine identifier
     */
    public function detectBookingEngine(string $html): string
    {
        if (empty($html)) {
            return 'none_detected';
        }

        $htmlLower = strtolower($html);

        // Check each booking engine signature set
        foreach (self::BOOKING_SIGNATURES as $engine => $signatures) {
            foreach ($signatures as $signature) {
                if (strpos($htmlLower, strtolower($signature)) !== false) {
                    return $engine;
                }
            }
        }

        // Check for a direct/custom booking form
        if ($this->hasBookingForm($htmlLower)) {
            return 'direct_custom';
        }

        // Check if the site only links to OTAs for booking
        if ($this->hasOtaOnlyBooking($htmlLower)) {
            return 'ota_only';
        }

        return 'none_detected';
    }

    /**
     * Detect analytics and tracking tools embedded in the HTML.
     *
     * @param string $html The raw HTML content
     * @return array       Array of detected analytics tool identifiers
     */
    public function detectAnalytics(string $html): array
    {
        $analytics = [];

        if (empty($html)) {
            return $analytics;
        }

        $htmlLower = strtolower($html);

        // GA4 — Google Analytics 4
        if (preg_match('/gtag\s*\(/i', $html)
            || preg_match('/G-[A-Z0-9]{6,}/i', $html)
            || strpos($htmlLower, 'googletagmanager.com/gtag') !== false
        ) {
            $analytics[] = 'ga4';
        }

        // GTM — Google Tag Manager (container)
        if (preg_match('/GTM-[A-Z0-9]{4,}/i', $html)
            || strpos($htmlLower, 'googletagmanager.com/gtm') !== false
        ) {
            $analytics[] = 'gtm';
        }

        // Facebook Pixel
        if (strpos($htmlLower, 'fbq(') !== false
            || strpos($htmlLower, 'facebook.com/tr') !== false
        ) {
            $analytics[] = 'facebook_pixel';
        }

        // Hotjar
        if (strpos($htmlLower, 'hotjar.com') !== false
            || preg_match('/\bhj\s*\(/i', $html)
        ) {
            $analytics[] = 'hotjar';
        }

        return $analytics;
    }

    /**
     * Check if the property has its own direct booking capability.
     * Looks for booking forms and "Book Now" / "Reserve" buttons/links that stay on-site.
     *
     * @param string $html The raw HTML content
     * @return bool        True if direct booking capability is detected
     */
    public function hasDirectBooking(string $html): bool
    {
        if (empty($html)) {
            return false;
        }

        $htmlLower = strtolower($html);

        // Check for booking-related forms
        if ($this->hasBookingForm($htmlLower)) {
            return true;
        }

        // Check for known booking engine signatures
        foreach (self::BOOKING_SIGNATURES as $engine => $signatures) {
            foreach ($signatures as $signature) {
                if (strpos($htmlLower, strtolower($signature)) !== false) {
                    return true;
                }
            }
        }

        // Check for "Book Now" / "Reserve" buttons/links
        // Look for anchor tags or buttons with booking-related text
        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING);

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Check buttons and links for booking-related text
        $bookingPatterns = [
            'book now',
            'book online',
            'reserve now',
            'make a reservation',
            'make a booking',
            'check availability',
            'book your stay',
            'reserve your',
            'book direct',
            'book a room',
        ];

        // Check <a> tags
        $links = $dom->getElementsByTagName('a');
        for ($i = 0; $i < $links->length; $i++) {
            $node     = $links->item($i);
            $text     = strtolower(trim($node->textContent));
            $href     = strtolower($node->getAttribute('href'));
            $cssClass = strtolower($node->getAttribute('class'));

            foreach ($bookingPatterns as $pattern) {
                if (strpos($text, $pattern) !== false) {
                    // Check that the link doesn't go to an external OTA
                    $isOta = false;
                    foreach (self::OTA_DOMAINS as $otaDomain) {
                        if (strpos($href, $otaDomain) !== false) {
                            $isOta = true;
                            break;
                        }
                    }
                    if (!$isOta) {
                        return true;
                    }
                }
            }

            // Check CSS class for booking indicators
            if (strpos($cssClass, 'book') !== false
                || strpos($cssClass, 'reserv') !== false
            ) {
                $isOta = false;
                foreach (self::OTA_DOMAINS as $otaDomain) {
                    if (strpos($href, $otaDomain) !== false) {
                        $isOta = true;
                        break;
                    }
                }
                if (!$isOta) {
                    return true;
                }
            }
        }

        // Check <button> elements
        $buttons = $dom->getElementsByTagName('button');
        for ($i = 0; $i < $buttons->length; $i++) {
            $text = strtolower(trim($buttons->item($i)->textContent));
            foreach ($bookingPatterns as $pattern) {
                if (strpos($text, $pattern) !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if the HTML contains a booking form (check-in/check-out date fields, guest selectors).
     *
     * @param string $htmlLower Lowercased HTML content
     * @return bool             True if a booking form pattern is detected
     */
    private function hasBookingForm(string $htmlLower): bool
    {
        // Look for form fields commonly associated with booking forms
        $formIndicators = [
            'check-in',
            'checkin',
            'check_in',
            'check-out',
            'checkout',
            'check_out',
            'arrival',
            'departure',
            'name="guests"',
            'name="adults"',
            'name="rooms"',
            'id="booking',
            'class="booking',
            'data-booking',
            'booking-form',
            'reservation-form',
        ];

        $matchCount = 0;

        foreach ($formIndicators as $indicator) {
            if (strpos($htmlLower, $indicator) !== false) {
                $matchCount++;
            }
        }

        // Need at least 2 matching indicators to be confident this is a booking form
        return $matchCount >= 2;
    }

    /**
     * Check if the property only links to OTAs for booking (no direct booking capability).
     *
     * @param string $htmlLower Lowercased HTML content
     * @return bool             True if booking links only point to OTAs
     */
    private function hasOtaOnlyBooking(string $htmlLower): bool
    {
        $bookingKeywords = ['book now', 'book online', 'reserve now', 'make a reservation', 'check availability'];
        $hasBookingText  = false;

        foreach ($bookingKeywords as $keyword) {
            if (strpos($htmlLower, $keyword) !== false) {
                $hasBookingText = true;
                break;
            }
        }

        if (!$hasBookingText) {
            return false;
        }

        // Check if there are OTA links present
        $hasOtaLink = false;
        foreach (self::OTA_DOMAINS as $otaDomain) {
            if (strpos($htmlLower, $otaDomain) !== false) {
                $hasOtaLink = true;
                break;
            }
        }

        return $hasOtaLink;
    }
}
