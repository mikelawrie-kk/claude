<?php
/**
 * Filename: dataforseo.php
 * Description: DataForSEO API wrapper class for hotel searches and hotel info requests
 * Version: 1.0.0
 * Created: 2026-02-16 09:15:00 SAST
 * Modified: 2026-02-16 09:15:00 SAST
 */

class DataForSEO {
    private $username;
    private $password;
    private $api_calls_count = 0;
    private $last_call_time = 0;
    private $min_delay_ms = 500; // 500ms between calls

    public function __construct($username, $password) {
        $this->username = $username;
        $this->password = $password;
    }

    /**
     * Search for hotels in a specific location
     * Returns array of hotels with position, price, rating, and hotel_identifier
     */
    public function hotelSearch($keyword, $location_name, $check_in, $check_out, $adults = 2, $currency = 'ZAR', $language = 'English') {
        $this->enforceRateLimit();

        $url = 'https://api.dataforseo.com/v3/business_data/google/hotel_searches/live/advanced';

        $payload = [[
            'keyword' => $keyword,
            'location_name' => $location_name,
            'language_name' => $language,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'adults' => $adults,
            'currency' => $currency
        ]];

        $result = $this->makeRequest($url, $payload);
        $this->api_calls_count++;

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Unknown error'
            ];
        }

        return [
            'success' => true,
            'hotels' => $this->parseHotelSearchResults($result['data'])
        ];
    }

    /**
     * Get detailed price info for a specific hotel
     * Returns array of OTA prices
     */
    public function hotelInfo($hotel_identifier, $location_name, $check_in, $check_out, $adults = 2, $currency = 'ZAR', $language = 'English') {
        $this->enforceRateLimit();

        $url = 'https://api.dataforseo.com/v3/business_data/google/hotel_info/live/advanced';

        $payload = [[
            'hotel_identifier' => $hotel_identifier,
            'location_name' => $location_name,
            'language_name' => $language,
            'check_in' => $check_in,
            'check_out' => $check_out,
            'adults' => $adults,
            'currency' => $currency
        ]];

        $result = $this->makeRequest($url, $payload);
        $this->api_calls_count++;

        if (!$result['success']) {
            return [
                'success' => false,
                'error' => $result['error'] ?? 'Unknown error'
            ];
        }

        return [
            'success' => true,
            'prices' => $this->parseHotelInfoResults($result['data'])
        ];
    }

    /**
     * Test API credentials
     */
    public function testConnection() {
        // Use a simple search with minimal cost
        $result = $this->hotelSearch(
            'hotels in Cape Town',
            'South Africa',
            date('Y-m-d', strtotime('+30 days')),
            date('Y-m-d', strtotime('+31 days')),
            2,
            'ZAR',
            'English'
        );

        return [
            'success' => $result['success'],
            'message' => $result['success'] ? 'API connection successful' : ($result['error'] ?? 'Connection failed')
        ];
    }

    /**
     * Get total API calls made in this session
     */
    public function getApiCallsCount() {
        return $this->api_calls_count;
    }

    /**
     * Estimate cost based on calls made ($0.003 per call)
     */
    public function getEstimatedCost() {
        return $this->api_calls_count * 0.003;
    }

    /**
     * Make HTTP request to DataForSEO API
     */
    private function makeRequest($url, $payload) {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);

        curl_close($ch);

        if ($curl_error) {
            return [
                'success' => false,
                'error' => 'CURL error: ' . $curl_error
            ];
        }

        if ($http_code !== 200) {
            return [
                'success' => false,
                'error' => 'HTTP error: ' . $http_code
            ];
        }

        $data = json_decode($response, true);

        if (!$data) {
            return [
                'success' => false,
                'error' => 'Invalid JSON response'
            ];
        }

        if (isset($data['status_code']) && $data['status_code'] !== 20000) {
            return [
                'success' => false,
                'error' => 'API error: ' . ($data['status_message'] ?? 'Unknown error')
            ];
        }

        return [
            'success' => true,
            'data' => $data
        ];
    }

    /**
     * Parse hotel search results into standardized format
     */
    private function parseHotelSearchResults($data) {
        $hotels = [];

        if (!isset($data['tasks']) || !is_array($data['tasks'])) {
            return $hotels;
        }

        foreach ($data['tasks'] as $task) {
            if (!isset($task['result']) || !is_array($task['result'])) {
                continue;
            }

            foreach ($task['result'] as $result) {
                if (!isset($result['items']) || !is_array($result['items'])) {
                    continue;
                }

                $position = 1;
                foreach ($result['items'] as $item) {
                    if ($item['type'] === 'hotel_search_item') {
                        $hotels[] = [
                            'name' => $item['title'] ?? null,
                            'hotel_identifier' => $item['hotel_identifier'] ?? null,
                            'position' => $position,
                            'price' => $item['price']['current'] ?? null,
                            'currency' => $item['price']['currency'] ?? null,
                            'rating' => $item['rating']['value'] ?? null,
                            'reviews_count' => $item['rating']['votes_count'] ?? null,
                            'total_results' => count($result['items'])
                        ];
                        $position++;
                    }
                }
            }
        }

        return $hotels;
    }

    /**
     * Parse hotel info results into standardized price array
     */
    private function parseHotelInfoResults($data) {
        $prices = [];

        if (!isset($data['tasks']) || !is_array($data['tasks'])) {
            return $prices;
        }

        foreach ($data['tasks'] as $task) {
            if (!isset($task['result']) || !is_array($task['result'])) {
                continue;
            }

            foreach ($task['result'] as $result) {
                if (!isset($result['prices']) || !is_array($result['prices'])) {
                    continue;
                }

                foreach ($result['prices'] as $price_item) {
                    if (isset($price_item['price']) && $price_item['price'] > 0) {
                        $prices[] = [
                            'source' => $price_item['type'] ?? 'Unknown',
                            'price' => $price_item['price'],
                            'currency' => $price_item['currency'] ?? 'ZAR',
                            'room_type' => $price_item['offers'][0]['title'] ?? null,
                            'link' => $price_item['offers'][0]['url'] ?? null
                        ];
                    }
                }
            }
        }

        return $prices;
    }

    /**
     * Enforce 500ms delay between API calls
     */
    private function enforceRateLimit() {
        if ($this->last_call_time > 0) {
            $elapsed_ms = (microtime(true) - $this->last_call_time) * 1000;
            $sleep_ms = $this->min_delay_ms - $elapsed_ms;

            if ($sleep_ms > 0) {
                usleep($sleep_ms * 1000);
            }
        }

        $this->last_call_time = microtime(true);
    }
}
