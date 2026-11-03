<?php
/**
 * File: scripts/seed-aerotel-entity.php
 * Description: One-shot seed script. Populates the site_entity row for aerotel.co.za with values pulled from Mike's recall + Aerotel's own homepage: legal entity, operator chain, OTAs (sameAs targets), area served, expertise domains, slogan. Run once after install so the first weekly audit's SchemaCheck recommendations include the exact strings to paste into the schema block. Idempotent (UPDATE, not INSERT).
 * Project: Pulse v1
 * Version: 1.0.0
 * Created: 2026-11-03 13:30 SAST
 * Modified: 2026-11-03 13:30 SAST
 * Changes:
 *   1.0.0 (2026-11-03 13:30) — initial creation
 */

require_once __DIR__ . '/../app/bootstrap.php';

if (!pulse_installed()) {
    fwrite(STDERR, "Pulse not installed. Run /install.php first.\n");
    exit(2);
}

$db = DB::getInstance();
$site = $db->fetchOne("SELECT id, domain FROM sites WHERE domain = ?", ['aerotel.co.za']);
if (!$site) {
    fwrite(STDERR, "No site row for aerotel.co.za. Add it via install.php or an INSERT.\n");
    exit(3);
}

// Set the MCP project key for Aerotel's bridge. The Aerotel bridge's cpanel_read_file / cpanel_write_file
// require 'project' (= which project to read/write in). For aerotel.co.za the project key is 'root'
// (= /home/aerotelco/public_html).
$db->execute("UPDATE sites SET mcp_project = ? WHERE id = ?", ['root', $site['id']]);

// Make sure a site_entity row exists; the installer seeds one, but migration doesn't guarantee it.
$existing = $db->fetchOne("SELECT id FROM site_entity WHERE site_id = ?", [$site['id']]);
if (!$existing) {
    $db->execute("INSERT INTO site_entity (site_id, entity_type) VALUES (?, ?)", [$site['id'], 'BoutiqueHotel']);
}

$db->execute(
    "UPDATE site_entity SET
        entity_type = ?,
        legal_name = ?,
        founding_date = ?,
        slogan = ?,
        description = ?,
        llm_txt_url = ?,
        alternate_names_json = ?,
        keywords_json = ?,
        knows_about_json = ?,
        ota_urls_json = ?,
        social_urls_json = ?,
        founder_json = ?,
        operator_json = ?,
        area_served_json = ?,
        updated_at = CURRENT_TIMESTAMP
     WHERE site_id = ?",
    [
        'BoutiqueHotel',
        'Aerotel Boutique Hotel (Pty) Ltd',
        '2019',
        "South Africa's Only Aircraft Hotel",
        "A retired Boeing 737-200 and former presidential Boeing 727 transformed into luxury cabins in Hoedspruit — the gateway to Kruger National Park.",
        'https://aerotel.co.za/llm.txt',
        json_encode(['Aerotel Hoedspruit', 'Aerotel Hotel', 'Aerotel'], JSON_UNESCAPED_SLASHES),
        json_encode([
            'aircraft hotel',
            'aviation hotel',
            'Hoedspruit accommodation',
            'Kruger boutique hotel',
            'converted Boeing 737',
            'Boeing 727 presidential suite',
            'Lowveld hotel',
        ], JSON_UNESCAPED_SLASHES),
        json_encode([
            'Safari Tourism',
            'Aviation History',
            'Kruger National Park',
            'Hoedspruit',
            'Boeing 737',
            'Luxury Hospitality',
            'Greater Kruger Region',
        ], JSON_UNESCAPED_SLASHES),
        json_encode([
            'https://www.booking.com/hotel/za/aerotel.html',
            'https://www.agoda.com/aerotel/hotel/hoedspruit-za.html',
            'https://www.expedia.com/Hoedspruit-Hotels-Aerotel.h77859680.Hotel-Information',
            'https://www.tripadvisor.com/Hotel_Review-g312616-d21339945-Reviews-Aerotel-Hoedspruit_Kruger_National_Park.html',
            'https://www.google.com/travel/hotels/aerotel%20hoedspruit',
            'https://www.airbnb.com/rooms/52744041',
            'https://www.lekkeslaap.co.za/akkommodasie/aerotel',
            'https://www.safarinow.com/destinations/hoedspruit/accommodation/aerotel.aspx',
        ], JSON_UNESCAPED_SLASHES),
        json_encode([], JSON_UNESCAPED_SLASHES),
        json_encode([
            '@type' => 'Person',
            'name' => 'Martin and Tracy den Dunnen',
        ], JSON_UNESCAPED_SLASHES),
        json_encode([
            '@type' => 'Organization',
            'name' => 'Bush Villa Collection (Pty) Ltd',
        ], JSON_UNESCAPED_SLASHES),
        json_encode([
            '@type' => 'Place',
            'name' => 'Greater Kruger Region',
            'containedInPlace' => [
                '@type' => 'AdministrativeArea',
                'name' => 'Limpopo Province, South Africa',
            ],
        ], JSON_UNESCAPED_SLASHES),
        $site['id'],
    ]
);

echo "✓ site_entity for aerotel.co.za seeded.\n";
echo "  entity_type:  BoutiqueHotel\n";
echo "  legal_name:   Aerotel Boutique Hotel (Pty) Ltd\n";
echo "  OTAs:         8 (Booking, Agoda, Expedia, TripAdvisor, Google Hotels, Airbnb, LekkeSlaap, SafariNow)\n";
echo "  founder:      Martin and Tracy den Dunnen\n";
echo "  operator:     Bush Villa Collection (Pty) Ltd\n";
echo "  area_served:  Greater Kruger Region, Limpopo Province\n";
echo "\nNext audit (Monday 06:00 SAST) will cross-reference these against the live schema.\n";
echo "Social URLs (Facebook, Instagram, YouTube) are empty — fill them in via the admin UI (Week 4) or UPDATE site_entity SET social_urls_json = ... .\n";
exit(0);
