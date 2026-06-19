# Database

Two schema files:
- **`schema.sqlite.sql` — AS DEPLOYED.** SQLite is the live engine.
- **`schema.sql` — MySQL reference.** Kept as the portable definition; not used live (see below).

## Why SQLite, not MySQL (deployment reality)
The original plan assumed a central MySQL "hub" DB. On inspection the server has **no such DB** — the only MySQL databases are `information_schema` and the **production PMS** (`safariweb_pms`), which we do **not** co-locate SEO tables into. The environment's existing convention is **SQLite-per-project** (e.g. the existing SEO platform's `strategist/data/seo_strategist.db`), so the Growth Engine follows suit.

## As deployed (2026-06-19)
- **Location:** `seo` project → `growth-engine/data/growth_engine.db`
  (`/home/safariweb/public_html/seo.safariweb.online/growth-engine/data/growth_engine.db`)
- **Bridge access:** `project=seo`, `engine=sqlite`, `database=growth-engine/data/growth_engine.db` via `db_query` / `db_execute`.
- **Protection:** `data/.htaccess` denies all web access to the DB directory.
- **State:** 14 tables created; `property` seeded with Aerotel (`id=1`, valid `config_json`).
- Created via a one-shot, token-guarded `init.php` (PDO, prepared insert to avoid escaping issues), **deleted immediately after**.

## Adding a property later
Upsert into `property` from `config/properties/<slug>.json`:
```sql
INSERT INTO property (slug,name,domain,config_json) VALUES (?,?,?,?)
ON CONFLICT(slug) DO UPDATE SET name=excluded.name, domain=excluded.domain,
  config_json=excluded.config_json, updated_at=CURRENT_TIMESTAMP;
```

## ⚠️ Overlap to reconcile
There is already a **"Unified SEO Intelligence Platform" (Build #109)** in the `seo` project (its own ~20-table SQLite design: SERP/DataForSEO, GA4, GSC, Clarity, A/B testing). The Growth Engine is intentionally a **separate, lighter, property-agnostic** system and does not touch it. Decide later whether to integrate the two or keep them distinct — flagged, not assumed.

## Design notes
- **Everything is keyed by `property_id`** → one hub serves every property; reports/analyses filter by it.
- **Raw per-source daily tables** keep ingestion dumb and auditable; analysis reads across them.
- **`ingest_run`** gives provenance + makes the weekly loop idempotent (skip a period already loaded `ok`).
- **`recommendation` → `content_item`** linkage means every piece of content traces back to the data signal that justified it.
- **`horizon_band`** on `content_item` (`committed` / `shaped` / `themed`) is how the rolling-90-day ratchet is enforced in data (see `ARCHITECTURE.md` §2).
- JSON columns (`evidence_json`, `metrics_json`, `kpis_json`) need MySQL 5.7+/MariaDB 10.2+. If the hub is older, switch them to `LONGTEXT`.
