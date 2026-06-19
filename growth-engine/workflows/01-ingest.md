# Workflow 01 — Ingest

**Goal:** land the latest GSC / GA4 / Clarity / Bing WMT data into the raw daily tables, with provenance.
**Adapter contract:** every source — whether CSV-now or API-later — must normalise to the columns in `db/schema.sql` and write an `ingest_run` row. Downstream never knows which method produced the rows. See `ingestion/README.md`.

## For each enabled source (`analytics.<source>.enabled`)
1. **Determine the window.** Pull from the day after the last `ok` `ingest_run.period_end` for this property+source, up to yesterday. First run: last 90 days (or as far back as the export allows).
2. **Acquire the data:**
   - **CSV mode** (`automation.ingest: csv`): read the operator-provided export from the agreed drop location, matching the header spec in `ingestion/csv-templates/<source>.csv`.
   - **API mode** (`automation.ingest: api`): call the source API with the stored `auth_ref` credentials (see per-source notes below).
3. **Normalise** to the target table columns (lowercase paths, ISO dates, CTR as 0–1, country as ISO-3).
4. **Upsert** into the raw table (`INSERT … ON DUPLICATE KEY UPDATE`) so re-runs are safe.
5. **Log** an `ingest_run` row (source, method, period, rows, status).

## Per-source notes
- **GSC** — Search Analytics: dimensions `query`, `page`, `country`, `device`, metrics clicks/impressions/ctr/position. Mind the ~16-month retention and data freshness lag (~2–3 days). API: Search Console API `searchanalytics.query`.
- **GA4** — Data API: dimensions `pagePath`, `sessionDefaultChannelGroup`, `country`, `date`; metrics `sessions`, `engagedSessions`, `engagementRate`, `averageSessionDuration`, `screenPageViews`, `conversions`. Watch sampling/thresholding on small segments.
- **MS Clarity** — Data Export API (project-scoped token) for rage/dead clicks, quickbacks, scroll depth, JS errors per page; or dashboard CSV. Clarity history is shorter — ingest promptly.
- **Bing WMT** — Webmaster API (apikey): Search Performance (query clicks/impr/ctr/position) + Crawl Information (crawled/indexed/errors/blocked).

## Output
Raw tables current to yesterday; one `ingest_run` per source; a one-line freshness summary for the report ("GSC→2026-06-17, GA4→2026-06-18, Clarity gap, Bing→2026-06-18").
