# Database

`schema.sql` is the multi-property MySQL schema for the hub DB (the same DB the SWO bridge already exposes via `db_query` / `db_execute`).

## Deploy
This is a **write to your live hub DB**, so it's a deliberate step — run it from a bridge-connected session:

1. `db_tables` — confirm none of these table names collide with existing ones.
2. Execute `schema.sql` (it uses `CREATE TABLE IF NOT EXISTS`, so re-running is safe).
3. Upsert each property into `property` from its `config/properties/<slug>.json` (store the whole file in `config_json`).

## Design notes
- **Everything is keyed by `property_id`** → one hub serves every property; reports/analyses filter by it.
- **Raw per-source daily tables** keep ingestion dumb and auditable; analysis reads across them.
- **`ingest_run`** gives provenance + makes the weekly loop idempotent (skip a period already loaded `ok`).
- **`recommendation` → `content_item`** linkage means every piece of content traces back to the data signal that justified it.
- **`horizon_band`** on `content_item` (`committed` / `shaped` / `themed`) is how the rolling-90-day ratchet is enforced in data (see `ARCHITECTURE.md` §2).
- JSON columns (`evidence_json`, `metrics_json`, `kpis_json`) need MySQL 5.7+/MariaDB 10.2+. If the hub is older, switch them to `LONGTEXT`.
