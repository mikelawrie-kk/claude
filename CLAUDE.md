# Safari Traveller - African Property Harvester & Entity Auditor

## Project Overview
- **Project:** safari-traveller.com — Africa's AI-ready safari discovery platform
- **Owner:** Mike Lawrie, Safari Web Online (Hoedspruit, South Africa)
- **Stack:** PHP 8.x, MySQL, HTML/CSS/JS on cPanel shared hosting
- **Pattern:** One-file installer (install.php creates DB, config, admin user, cron jobs)

Safari Traveller is a safari-focused OTA/directory that lists African safari lodges, private guides, and tour operators. Each listing gets an info page, an entity authority audit/review, and an enquiry form that routes to the property for permission-based listing.

The Harvester is the data collection engine that systematically discovers, scrapes, audits, and enriches every safari property across all African countries.

## What We're Building

### Three Verticals
1. **Safari Lodges & Camps** — Hotels, lodges, tented camps, bush camps, villas, guesthouses in/near game reserves and national parks across Africa
2. **Private Safari Guides** — Independent FGASA/THETA-qualified guides offering freelance guiding services
3. **Tour Operators & DMCs** — Companies packaging and selling safari experiences

### Per-Property Pipeline
```
DISCOVER → SCRAPE → ENRICH → AUDIT → PUBLISH → OUTREACH
```

## Architecture

### Database Tables (MySQL)
```
properties          — Master property record (all three verticals share this)
property_contacts   — Multiple contacts per property
property_sources    — Where we found them (Google, SafariBookings, etc.) with source_url
property_scrapes    — Raw scrape data per source (JSON blob, timestamp, status)
property_enrichment — Deep website scrape data (schema found, CMS, rooms, activities)
property_audit      — Entity authority audit results (scores, findings, recommendations)
property_images     — Harvested image URLs with alt text
property_reviews    — Aggregated review data from multiple platforms
countries           — African countries with regions/provinces
reserves_parks      — Game reserves, national parks, conservancies with country_id
scrape_queue        — Job queue: what to scrape next, status, retries, last_attempt
scrape_log          — Detailed log of every API call, scrape attempt, cost tracking
outreach_queue      — Email outreach queue with status tracking
listings            — Published safari-traveller.com listing pages
enquiries           — Visitor enquiries from property pages
admin_users         — Admin user accounts
settings            — Application settings (key-value pairs)
discovery_queue     — Country + search term combinations for discovery
cron_schedule       — Cron job schedule and status tracking
rate_limits         — Per-domain rate limiting data
api_costs           — API call cost tracking
```

### Key Files Structure
```
/safari-traveller/
├── install.php                    # One-file installer
├── config.php                     # DB credentials, API keys, settings
├── CLAUDE.md                      # Project documentation
├── cron/
│   ├── discover.php               # Runs discovery searches
│   ├── enrich.php                 # Deep website scraping + audit
│   └── outreach.php               # Sends permission emails
├── lib/
│   ├── db.php                     # Database wrapper (PDO)
│   ├── logger.php                 # Logging to scrape_log + file fallback
│   ├── rate-limiter.php           # Per-domain rate limiting
│   ├── cost-tracker.php           # API call cost tracking
│   ├── website-scraper.php        # Generic website content scraper
│   ├── schema-detector.php        # Extracts and parses Schema.org markup
│   ├── entity-auditor.php         # AI readiness scoring engine
│   ├── platform-detector.php      # CMS/booking engine detection
│   └── email-sender.php           # Outreach email sending
├── sources/
│   └── google-places.php          # Google Places discovery
├── admin/
│   ├── index.php                  # Dashboard
│   ├── properties.php             # Property management
│   ├── queue.php                  # Queue management
│   ├── audits.php                 # Entity audit browser
│   ├── outreach.php               # Outreach tracking
│   └── settings.php               # Application settings
├── public/
│   ├── index.php                  # Homepage
│   ├── property.php               # Individual property listing
│   ├── audit-report.php           # Public entity authority report
│   ├── enquiry.php                # Enquiry form handler
│   ├── claim.php                  # Claim listing page
│   ├── search.php                 # Search/browse properties
│   └── country.php                # Country landing page
├── assets/
│   ├── css/
│   │   └── style.css              # Main stylesheet
│   └── js/
│       └── app.js                 # Main JavaScript
├── cache/                         # File-based HTML cache
└── templates/
    └── layout.php                 # Shared HTML layout
```

## Entity Authority Audit Scoring

The audit engine scores each property's AI readiness out of 100 points:

### Schema.org Presence (30 points)
- Has ANY structured data: 5pts
- Uses JSON-LD format: 3pts
- Has LodgingBusiness/Hotel/Resort type: 5pts
- Has individual room/accommodation markup: 4pts
- Has address + geo coordinates in schema: 3pts
- Has aggregateRating in schema: 3pts
- Has Offer/priceRange markup: 3pts
- Has FAQPage schema: 2pts
- Has BreadcrumbList: 2pts

### Entity Authority (25 points)
- @id uses canonical URL (not relative): 5pts
- mainEntityOfPage declared: 5pts
- sameAs includes social profiles: 3pts
- sameAs includes OTA listings: 4pts
- sameAs includes Wikipedia/Wikidata: 5pts
- potentialAction with ReserveAction: 3pts

### AI Discoverability (20 points)
- Has LLM.txt file: 5pts
- robots.txt allows AI crawlers: 3pts
- Content length >2,000 words on main pages: 4pts
- FAQ content present (structured or unstructured): 4pts
- Blog/content hub with regular updates: 4pts

### Technical Foundation (15 points)
- HTTPS: 2pts
- Mobile responsive: 3pts
- Page speed <3 seconds: 3pts
- Has sitemap.xml: 2pts
- Google Business Profile exists: 3pts
- Google Business Profile has >10 reviews: 2pts

### Booking Authority (10 points)
- Has own booking engine (not just OTA links): 5pts
- Direct booking CTA visible: 3pts
- tourBookingPage property used: 2pts

## Design Standards
- Golden amber accent: `#D4A84B`
- White/cream backgrounds: `#FAFAF5`
- Poppins font family
- Clean, elegant — NO cartoon icons, NO generic safari clipart
- Professional photography focus
- Mobile-first responsive
- Entity authority score displayed as a clean radial gauge

## Code Standards (NON-NEGOTIABLE)

Every file must have header:
```php
<?php
/**
 * Filename: example.php
 * Description: What this file does
 * Project: Safari Traveller - African Property Harvester
 * Version: 1.0.0
 * Created: 2026-02-17 12:00 SAST
 * Modified: 2026-02-17 12:00 SAST
 * Changes: Initial creation
 */
```

- Update version + timestamp on EVERY edit
- PDO for all database operations
- Prepared statements for ALL queries — no raw string concatenation
- All user input sanitized and validated
- Rate limiting on all external API calls with cost tracking
- Comprehensive error logging

## Scraping Ethics
- Respect robots.txt on every domain
- Rate limit: minimum 2 seconds between requests to same domain
- Identify as: `SafariTravellerBot/1.0 (+https://safari-traveller.com/bot)`
- Cache responses: don't re-scrape same URL within 7 days unless forced
- Store raw HTML/JSON responses for reprocessing without re-scraping
- Never scrape behind login walls
- Personal email addresses: only collect if publicly displayed on website contact page

## Google Places API Cost Management
- Text Search: $32 per 1,000 requests
- Place Details (Basic): $17 per 1,000 requests
- Place Details (Contact): $3 per 1,000 requests
- Place Details (Atmosphere): $5 per 1,000 requests
- Daily budget cap with automatic pause
- Cost tracking per country, per session

## Country Priority Queue
- **Tier 1** (highest volume): South Africa, Kenya, Tanzania, Botswana, Namibia
- **Tier 2** (high volume): Zimbabwe, Zambia, Uganda, Rwanda, Mozambique
- **Tier 3** (medium): Malawi, Madagascar, Ethiopia, DRC, Gabon
- **Tier 4** (lower): Cameroon, Ghana, Senegal, Gambia, Morocco, Tunisia, Egypt
- **Tier 5** (complete coverage): All remaining African countries

## Session Protocol
- Start every session with: "R or B?"
- Check this CLAUDE.md first
- Before complex requests: think full scope, confirm understanding
- PATCH existing files surgically — never rebuild from scratch
- End every response with: Session:[R/B] | KB:[Y/N] | Task:[desc] | Protocol:[following/drifting]
