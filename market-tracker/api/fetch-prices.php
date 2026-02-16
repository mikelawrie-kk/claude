<?php
/**
 * Filename: fetch-prices.php
 * Description: Main price fetching script called by cron. Collects hotel positions and OTA prices.
 * Version: 1.0.0
 * Created: 2026-02-16 09:30:00 SAST
 * Modified: 2026-02-16 09:30:00 SAST
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/dataforseo.php';

class PriceFetcher {
    private $db;
    private $api;
    private $settings;
    private $cron_log_id;

    public function __construct() {
        $this->db = new PDO('sqlite:' . DB_PATH);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->api = new DataForSEO(DATAFORSEO_USERNAME, DATAFORSEO_PASSWORD);
        $this->loadSettings();
    }

    /**
     * Main entry point for price fetching
     */
    public function run($manual_check_dates = null) {
        $this->startCronLog();

        try {
            // Get active properties
            $properties = $this->getActiveProperties();

            if (empty($properties)) {
                $this->completeCronLog('completed', 'No active properties to track');
                return ['success' => true, 'message' => 'No properties to track'];
            }

            // Get check dates to process
            $check_dates = $manual_check_dates ?? $this->generateCheckDates();

            $total_snapshots = 0;
            $errors = [];

            foreach ($check_dates as $date_range) {
                $check_in = $date_range['check_in'];
                $check_out = $date_range['check_out'];

                // Run hotel search to get positions
                $search_result = $this->api->hotelSearch(
                    $this->settings['search_keyword'],
                    $this->settings['location_name'],
                    $check_in,
                    $check_out,
                    (int)$this->settings['default_adults'],
                    $this->settings['currency'],
                    $this->settings['language_name']
                );

                if (!$search_result['success']) {
                    $errors[] = "Search failed for {$check_in}: " . $search_result['error'];
                    continue;
                }

                // Process position data
                foreach ($search_result['hotels'] as $hotel) {
                    $property = $this->findPropertyByName($properties, $hotel['name']);

                    if ($property) {
                        $this->savePositionSnapshot(
                            $property['id'],
                            $check_in,
                            $hotel['position'],
                            $hotel['total_results'],
                            $hotel['price'],
                            $hotel['rating'],
                            $hotel['reviews_count']
                        );
                        $total_snapshots++;

                        // Update hotel_identifier if not set
                        if (empty($property['hotel_identifier']) && !empty($hotel['hotel_identifier'])) {
                            $this->updateHotelIdentifier($property['id'], $hotel['hotel_identifier']);
                            $property['hotel_identifier'] = $hotel['hotel_identifier'];
                        }

                        // Fetch OTA prices if we have hotel_identifier
                        if (!empty($property['hotel_identifier'])) {
                            $info_result = $this->api->hotelInfo(
                                $property['hotel_identifier'],
                                $this->settings['location_name'],
                                $check_in,
                                $check_out,
                                (int)$this->settings['default_adults'],
                                $this->settings['currency'],
                                $this->settings['language_name']
                            );

                            if ($info_result['success']) {
                                foreach ($info_result['prices'] as $price) {
                                    $this->savePriceSnapshot(
                                        $property['id'],
                                        $check_in,
                                        $check_out,
                                        $price['source'],
                                        $price['price'],
                                        $price['room_type'],
                                        $price['link']
                                    );
                                    $total_snapshots++;
                                }
                            } else {
                                $errors[] = "Price fetch failed for {$property['name']} on {$check_in}: " . $info_result['error'];
                            }
                        }
                    }
                }
            }

            // Calculate demand scores after data collection
            $this->calculateDemandScores($check_dates);

            $status_message = "Collected {$total_snapshots} snapshots";
            if (!empty($errors)) {
                $status_message .= " with " . count($errors) . " errors";
            }

            $this->completeCronLog(
                'completed',
                $status_message,
                !empty($errors) ? implode("\n", $errors) : null
            );

            return [
                'success' => true,
                'snapshots' => $total_snapshots,
                'api_calls' => $this->api->getApiCallsCount(),
                'cost' => $this->api->getEstimatedCost(),
                'errors' => $errors
            ];

        } catch (Exception $e) {
            $this->completeCronLog('failed', $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate check dates based on settings
     */
    private function generateCheckDates() {
        $dates = [];
        $check_days = array_map('intval', explode(',', $this->settings['check_dates']));
        $months_ahead = (int)$this->settings['months_ahead'];

        $current_date = new DateTime();
        $end_date = (clone $current_date)->modify("+{$months_ahead} months");

        while ($current_date <= $end_date) {
            $year = $current_date->format('Y');
            $month = $current_date->format('m');

            foreach ($check_days as $day) {
                $check_in_date = DateTime::createFromFormat('Y-m-d', "{$year}-{$month}-{$day}");

                if ($check_in_date && $check_in_date >= new DateTime() && $check_in_date <= $end_date) {
                    $check_out_date = clone $check_in_date;
                    $check_out_date->modify('+1 day');

                    // Apply smart batching
                    $days_until = $check_in_date->diff(new DateTime())->days;

                    if ($days_until < 30) {
                        // Less than 30 days: daily (always include)
                        $dates[] = [
                            'check_in' => $check_in_date->format('Y-m-d'),
                            'check_out' => $check_out_date->format('Y-m-d')
                        ];
                    } elseif ($days_until < 90) {
                        // 30-90 days: every 3 days
                        if ($day % 3 === 1) {
                            $dates[] = [
                                'check_in' => $check_in_date->format('Y-m-d'),
                                'check_out' => $check_out_date->format('Y-m-d')
                            ];
                        }
                    } else {
                        // 90-180 days: weekly
                        if ($day === 1 || $day === 7 || $day === 14 || $day === 21) {
                            $dates[] = [
                                'check_in' => $check_in_date->format('Y-m-d'),
                                'check_out' => $check_out_date->format('Y-m-d')
                            ];
                        }
                    }
                }
            }

            $current_date->modify('+1 month');
        }

        return $dates;
    }

    /**
     * Calculate demand scores for check dates
     */
    private function calculateDemandScores($check_dates) {
        foreach ($check_dates as $date_range) {
            $check_in = $date_range['check_in'];

            // Get average market price
            $stmt = $this->db->prepare("
                SELECT AVG(ps.price_zar) as avg_price
                FROM price_snapshots ps
                JOIN properties p ON ps.property_id = p.id
                WHERE ps.check_in = ? AND p.is_ours = 0
                AND ps.scraped_at > datetime('now', '-24 hours')
            ");
            $stmt->execute([$check_in]);
            $market_data = $stmt->fetch(PDO::FETCH_ASSOC);

            // Get our price
            $stmt = $this->db->prepare("
                SELECT ps.price_zar
                FROM price_snapshots ps
                JOIN properties p ON ps.property_id = p.id
                WHERE ps.check_in = ? AND p.is_ours = 1
                AND ps.scraped_at > datetime('now', '-24 hours')
                ORDER BY ps.scraped_at DESC
                LIMIT 1
            ");
            $stmt->execute([$check_in]);
            $our_data = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$market_data || !$market_data['avg_price']) {
                continue;
            }

            $avg_market_price = $market_data['avg_price'];
            $our_price = $our_data['price_zar'] ?? $avg_market_price;

            // Calculate baseline (30 days ago average for same date)
            $baseline_date = date('Y-m-d', strtotime($check_in . ' -30 days'));
            $stmt = $this->db->prepare("
                SELECT AVG(ps.price_zar) as baseline_price
                FROM price_snapshots ps
                WHERE ps.check_in = ?
            ");
            $stmt->execute([$baseline_date]);
            $baseline_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $baseline_price = $baseline_data['baseline_price'] ?? $avg_market_price;

            // Calculate price change percentage
            $price_change_pct = 0;
            if ($baseline_price > 0) {
                $price_change_pct = (($avg_market_price - $baseline_price) / $baseline_price) * 100;
            }

            // Calculate demand score (1-10 scale)
            $demand_score = 5; // Base score

            // Price change impact
            if ($price_change_pct > 30) {
                $demand_score += 3;
            } elseif ($price_change_pct > 15) {
                $demand_score += 2;
            } elseif ($price_change_pct > 5) {
                $demand_score += 1;
            } elseif ($price_change_pct < -5) {
                $demand_score -= 1;
            }

            // Check sold out competitors
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT p.id) as sold_out_count
                FROM properties p
                LEFT JOIN price_snapshots ps ON p.id = ps.property_id
                    AND ps.check_in = ?
                    AND ps.scraped_at > datetime('now', '-24 hours')
                WHERE p.is_ours = 0 AND p.active = 1
                AND ps.id IS NULL
            ");
            $stmt->execute([$check_in]);
            $sold_out_data = $stmt->fetch(PDO::FETCH_ASSOC);
            $sold_out_count = $sold_out_data['sold_out_count'] ?? 0;

            if ($sold_out_count >= 3) {
                $demand_score += 2;
            } elseif ($sold_out_count >= 1) {
                $demand_score += 1;
            }

            // Day of week bonus (weekend)
            $day_of_week = date('N', strtotime($check_in));
            if ($day_of_week >= 5) { // Friday, Saturday, Sunday
                $demand_score += 1;
            }

            // Days until check-in bonus
            $days_until = (strtotime($check_in) - time()) / 86400;
            if ($days_until < 7) {
                $demand_score += 1;
            }

            // Cap score at 1-10
            $demand_score = max(1, min(10, $demand_score));

            // Determine demand signal
            $demand_signal = 'normal';
            if ($demand_score >= 9) {
                $demand_signal = 'peak';
            } elseif ($demand_score >= 7) {
                $demand_signal = 'high';
            } elseif ($demand_score >= 5) {
                $demand_signal = 'elevated';
            } else {
                $demand_signal = 'low';
            }

            // Save demand score
            $stmt = $this->db->prepare("
                INSERT OR REPLACE INTO demand_scores
                (check_date, avg_market_price, our_price, demand_score, demand_signal, price_change_pct, calculated_at)
                VALUES (?, ?, ?, ?, ?, ?, datetime('now'))
            ");
            $stmt->execute([
                $check_in,
                $avg_market_price,
                $our_price,
                $demand_score,
                $demand_signal,
                $price_change_pct
            ]);
        }
    }

    /**
     * Helper methods
     */
    private function loadSettings() {
        $stmt = $this->db->query("SELECT key, value FROM settings");
        $this->settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $this->settings[$row['key']] = $row['value'];
        }
    }

    private function getActiveProperties() {
        $stmt = $this->db->query("SELECT * FROM properties WHERE active = 1 ORDER BY is_ours DESC, name");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function findPropertyByName($properties, $search_name) {
        foreach ($properties as $property) {
            if (stripos($search_name, $property['name']) !== false || stripos($property['name'], $search_name) !== false) {
                return $property;
            }
        }
        return null;
    }

    private function updateHotelIdentifier($property_id, $hotel_identifier) {
        $stmt = $this->db->prepare("UPDATE properties SET hotel_identifier = ? WHERE id = ?");
        $stmt->execute([$hotel_identifier, $property_id]);
    }

    private function savePositionSnapshot($property_id, $check_in, $position, $total_results, $price, $rating, $reviews_count) {
        $stmt = $this->db->prepare("
            INSERT INTO position_snapshots
            (property_id, search_keyword, position, total_results, displayed_price, rating, reviews_count, scraped_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ");
        $stmt->execute([
            $property_id,
            $this->settings['search_keyword'],
            $position,
            $total_results,
            $price,
            $rating,
            $reviews_count
        ]);
    }

    private function savePriceSnapshot($property_id, $check_in, $check_out, $source, $price, $room_type, $link) {
        $stmt = $this->db->prepare("
            INSERT INTO price_snapshots
            (property_id, check_in, check_out, ota_source, price_zar, room_type, link, scraped_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, datetime('now'))
        ");
        $stmt->execute([
            $property_id,
            $check_in,
            $check_out,
            $source,
            $price,
            $room_type,
            $link
        ]);
    }

    private function startCronLog() {
        $stmt = $this->db->prepare("
            INSERT INTO cron_log (run_type, status, started_at)
            VALUES ('scheduled', 'running', datetime('now'))
        ");
        $stmt->execute();
        $this->cron_log_id = $this->db->lastInsertId();
    }

    private function completeCronLog($status, $message = null, $errors = null) {
        $stmt = $this->db->prepare("
            UPDATE cron_log
            SET status = ?, api_calls_made = ?, api_cost_estimate = ?, errors = ?, completed_at = datetime('now')
            WHERE id = ?
        ");
        $stmt->execute([
            $status,
            $this->api->getApiCallsCount(),
            $this->api->getEstimatedCost(),
            $errors,
            $this->cron_log_id
        ]);
    }
}

// Allow direct execution for testing
if (php_sapi_name() === 'cli' || basename($_SERVER['PHP_SELF']) === 'fetch-prices.php') {
    $fetcher = new PriceFetcher();
    $result = $fetcher->run();

    if (php_sapi_name() === 'cli') {
        echo json_encode($result, JSON_PRETTY_PRINT) . "\n";
    }
}
