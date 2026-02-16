# Market Demand Tracker

**Version:** 1.0.0
**Created:** 2026-02-16
**License:** Proprietary

## Overview

Market Demand Tracker is a PHP/SQLite hotel price intelligence platform designed for cPanel hosting environments. It tracks competitor hotel pricing using the DataForSEO Google Hotels API, calculates demand scores, and provides a public-facing price comparison widget.

## Features

- **Automated Price Tracking**: Scheduled cron jobs collect competitor pricing data
- **Position Monitoring**: Track your hotel's position in Google Hotel search results
- **Demand Forecasting**: AI-powered demand scoring (1-10 scale) based on pricing signals
- **OTA Price Comparison**: Compare prices across Booking.com, Expedia, and other platforms
- **Public Widget**: Embeddable "Book Direct & Save" widget for your website
- **Admin Dashboard**: Comprehensive tabbed interface with analytics and trends
- **Cost Efficient**: Estimated R500/month API budget with smart batching

## Tech Stack

- PHP 8.x
- SQLite3
- Vanilla JavaScript
- Chart.js (CDN)
- Poppins Font
- No frameworks, no Composer, no npm

## Installation

### 1. Upload Files

Upload the entire `market-tracker` directory to your cPanel hosting account (e.g., `/public_html/market-tracker/`)

### 2. Set Permissions

```bash
chmod 755 market-tracker
chmod 755 market-tracker/data
chmod 644 market-tracker/*.php
```

### 3. Run Installer

Navigate to: `https://yourdomain.com/market-tracker/install.php`

You'll need:
- Admin username and password (for dashboard access)
- DataForSEO API credentials (get from https://dataforseo.com)
- Your property name

The installer will:
- Create SQLite database
- Generate configuration file
- Set up admin account
- Seed default competitors
- Lock itself after installation

### 4. Configure Cron Job

In cPanel > Cron Jobs, add:

```
0 2 * * * /usr/bin/php /home/username/public_html/market-tracker/cron-runner.php >> /home/username/logs/market-tracker.log 2>&1
```

This runs daily at 02:00 SAST.

### 5. Access Dashboard

Navigate to: `https://yourdomain.com/market-tracker/`

Login with credentials created during installation.

## Dashboard Features

### 1. Dashboard Tab

- **Quick Stats**: Current position, high demand days, price comparison
- **Demand Calendar**: 6-month forecast with color-coded demand levels
  - Green: Low demand
  - Yellow: Normal demand
  - Orange: Elevated demand
  - Red: High/Peak demand

### 2. Competitors Tab

- Add/remove tracked properties
- Activate/deactivate competitors
- View hotel identifiers
- Automatic hotel ID lookup via DataForSEO

### 3. Price Explorer

- Matrix view of all properties vs OTAs
- Date-specific price comparison
- Rate parity flag detection
- Direct links to OTA listings

### 4. Trends Tab

- **Position Trend**: Your ranking over time
- **Price Trend**: Your pricing vs market average
- **Demand Forecast**: 60-day demand projection

### 5. Run Audit Tab

- Manual price audits for specific dates
- Real-time progress tracking
- API cost estimation
- Recent cron run logs

### 6. Settings Tab

- API connection testing
- Check date configuration (default: 1st, 7th, 14th, 21st)
- Months ahead setting
- Search keyword customization
- CSV data export
- Cron command reference

## Widget Integration

### Embed Code

Add to your website's booking page:

```html
<div id="market-tracker-widget" data-check-in="2026-04-01"></div>
<script src="https://yourdomain.com/market-tracker/widget/widget.js"></script>
```

### Widget Features

- Only displays when direct booking is cheaper
- Shows savings amount and percentage
- Responsive design
- Rate limited (60 req/min per IP)
- CORS-protected for specified domains

### Customization

Edit `config.php`:
```php
define('ALLOWED_WIDGET_DOMAINS', 'aerotel.co.za,otherdomain.com');
```

## Demand Score Algorithm

**Base Score:** 5/10

**Price Change Impact:**
- +30%: +3 points
- +15-30%: +2 points
- +5-15%: +1 point
- -5% or less: -1 point

**Sold Out Competitors:**
- 3+ sold out: +2 points
- 1-3 sold out: +1 point

**Date Factors:**
- Weekend (Fri-Sun): +1 point
- <7 days away: +1 point
- Known holidays: +2 points

**Demand Signals:**
- 1-3: Low
- 4-5: Normal
- 6-7: Elevated
- 8-9: High
- 10: Peak

## Smart Batching

To optimize API costs:
- **<30 days away**: Daily checks
- **30-90 days**: Every 3 days
- **90-180 days**: Weekly checks

## File Structure

```
market-tracker/
├── install.php              # Installer (locks after first run)
├── config.php               # Generated config (gitignored)
├── index.php                # Admin dashboard
├── cron-runner.php          # Cron entry point
├── api/
│   ├── dataforseo.php       # API wrapper class
│   ├── fetch-prices.php     # Price collection script
│   └── ajax-handlers.php    # Dashboard AJAX handlers
├── widget/
│   ├── embed.php            # JSON API endpoint
│   └── widget.js            # Embeddable widget
├── data/
│   ├── market_tracker.db    # SQLite database
│   └── .htaccess            # Directory protection
└── assets/
    ├── style.css            # Golden amber theme
    └── dashboard.js         # Frontend JS
```

## Database Schema

### Tables

1. **properties**: Hotel properties being tracked
2. **price_snapshots**: OTA price history
3. **position_snapshots**: Search result position history
4. **demand_scores**: Calculated demand forecasts
5. **cron_log**: Automated run history
6. **settings**: Configuration key-value pairs

## Security

- ✅ Session-based authentication
- ✅ CSRF token validation
- ✅ PDO prepared statements (SQL injection protection)
- ✅ Input sanitization
- ✅ Rate limiting on widget API
- ✅ CORS protection
- ✅ Data directory .htaccess protection
- ✅ Config file exclusion from git

## API Cost Estimation

DataForSEO pricing: ~$0.003 per API call

**Monthly estimates:**
- 10 properties tracked
- 4 check dates per month (1st, 7th, 14th, 21st)
- 6 months ahead
- Daily cron runs

**Approximate calls:** 240-300/month
**Cost:** $0.72 - $0.90/month (~R13-R17)

## Troubleshooting

### Database locked error
```bash
chmod 666 data/market_tracker.db
chmod 777 data/
```

### Cron not running
Check cron logs:
```bash
tail -f ~/logs/market-tracker.log
```

### Widget not displaying
- Check CORS allowed domains in config.php
- Verify data exists for check-in date
- Check browser console for errors

### API errors
Test connection in Settings > Test API Connection

## Default Competitors

Pre-seeded properties for Hoedspruit area:
- Aerotel Hoedspruit (your property)
- Phelwana
- Camp Jabulani
- Ezulwini
- Blyde Canyon Forever Resort
- Khaya Ndlovu
- Tremisana
- Raptors Lodge
- Perry's Bridge Hollow
- Blue Cottages

## Code Conventions

Every file includes:
```php
/**
 * Filename: example.php
 * Description: Purpose of this file
 * Version: 1.0.0
 * Created: 2026-02-16 09:00:00 SAST
 * Modified: 2026-02-16 09:00:00 SAST
 */
```

**Update version + timestamp on EVERY edit.**

## Design Theme

- **Primary Color**: Golden Amber (#D4A843)
- **Background**: Cream (#FFFDF7)
- **Font**: Poppins (Google Fonts)
- **Style**: Soft curves, clean lines, no emojis in production

## Support

For issues or feature requests, contact the development team.

## Version History

### 1.0.0 (2026-02-16)
- Initial release
- Full dashboard with 6 tabs
- DataForSEO API integration
- Automated cron scheduling
- Public widget
- Demand score algorithm
- Smart batching
- Rate limiting
- CORS protection

---

**Built for Aerotel Hoedspruit** | Market Intelligence Platform
