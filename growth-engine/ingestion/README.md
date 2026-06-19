# Ingestion — adapter contract & CSV formats

The engine reads all analytics through a single **adapter contract** so the rest of the system is source- and method-agnostic. Today: CSV import. As credentials land per source: a live API adapter that writes the *same* tables. Nothing downstream changes.

## The contract
An adapter (CSV or API) for a source must:
1. Accept `property_id` + a date window.
2. Produce rows matching the target table columns in `db/schema.sql` (ISO dates, CTR as 0–1, ISO-3 country, lowercased paths).
3. Upsert with `INSERT … ON DUPLICATE KEY UPDATE` (idempotent).
4. Write one `ingest_run` row (source, method, period, rows, status).

That's it. CSV-now and API-later are swappable because both honour this.

## CSV mode (Phase 1)
Drop each source's export at the agreed location (per property, e.g. `growth-engine/data/<slug>/<source>-<YYYYMMDD>.csv`) using the headers in `csv-templates/`. The headers are normalised names, **not** the raw vendor export labels — map the vendor export to these on the way in:

| Source | Vendor export → normalised | Notes |
|---|---|---|
| **GSC** | Performance report → `gsc.csv` | Export the **Queries** + **Pages** tabs, or the combined query×page if available. Dates in the "Dates" filter. |
| **GA4** | Explore/Reports export → `ga4.csv` | Build a free-form report: pagePath × date with the listed metrics. |
| **Clarity** | Dashboard export → `clarity.csv` | Per-page metrics; if only project-level is available, load that and leave page blank. |
| **Bing WMT** | Search Performance + Crawl export → `bing-wmt.csv` (+ crawl rows) | Two shapes share the file via a `kind` column, or keep crawl in `bing-crawl.csv`. |

## API mode (Phase 2, per source as creds arrive)
Flip `automation.ingest: api` and set the source's `auth_ref`. Implement the adapter as a small script (Python/Node) or a bridge-callable routine:
- **GSC** — Search Console API `searchanalytics.query` (service account with the property added).
- **GA4** — Data API v1 `runReport` (service account with Viewer on the GA4 property).
- **Clarity** — Data Export API (project token).
- **Bing WMT** — Webmaster API key (`GetQueryStats`, `GetCrawlStats`).

Each writes the identical tables; the orchestrator doesn't care which mode produced the data.

## Credentials
Never commit secrets. `auth_ref` in the property config points to where the secret lives (env var, secrets manager, or a server-side path the adapter reads). The repo holds only references.
