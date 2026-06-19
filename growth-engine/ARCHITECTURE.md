# Architecture & Operating Model

This is the design rationale, the execution recommendation you asked for, the data model, the adaptation/scoring logic, and how the rolling 90-day ratchet actually works.

---

## 1. Recommended execution model — Hybrid, phased

You weren't sure on the engine and your data access is mixed, so this is the call:

**Start Claude-orchestrated, harden into coded ingestion. Keep Claude for the thinking.**

| Layer | Phase 1 (stand up now) | Phase 2 (as creds land) | Why |
|---|---|---|---|
| **Ingestion** | CSV import per source into MySQL | Live API adapters (GSC, GA4, Clarity, Bing WMT) — drop-in, same tables | Deterministic data eventually; zero blockage now |
| **Storage** | cPanel MySQL via SWO bridge | same | One hub DB for all properties; you already have it |
| **Analysis / planning / content / reporting** | Claude workflows in `workflows/` | unchanged | This is where reasoning + brand voice live; don't code it away |
| **Scheduling** | `/loop` skill or weekly cron kicking a Claude session | cron → coded ingest, then Claude session | Reliability grows without re-architecting |

**Why not pure-coded?** It's the most engineering for the least adaptivity; the value here is *judgement* (which gaps to chase, what to write, how to phrase it for the brand) — that's Claude's job, not a cron script's.

**Why not pure-Claude?** API data pulls want to be deterministic and cheap; once creds exist, a small adapter beats an agent paginating an API by hand. So we isolate ingestion behind an adapter contract and upgrade just that layer.

**The seam that makes this work:** every source writes the **same MySQL tables** (`db/schema.sql`) through the same adapter contract (`ingestion/README.md`). CSV-now and API-later are interchangeable; nothing downstream knows or cares which produced the rows.

---

## 2. The rolling 90-day ratchet

The horizon is **rolling, not fixed quarters**. At any moment the calendar holds ~90 days of planned work ahead of *today*. Each weekly run:

1. **Marks** last week's items done / slipped (with their early metrics).
2. **Adapts** the near-term (next ~2–3 weeks): double down on what data shows working, cut or rewrite what isn't, insert newly-discovered opportunities.
3. **Ratchets**: because a week just elapsed, the far edge is now only 83 days out — so it **adds a fresh week at day 84–90** to restore the full 90-day horizon. The plan never shrinks toward a deadline; it perpetually regenerates its own runway.
4. **Locks** granularity by distance:
   - **Days 0–14:** committed — briefs written, social drafted, dates fixed.
   - **Days 15–45:** shaped — titles + primary keyword + target page set, brief pending.
   - **Days 46–90:** themed — month theme + candidate angles only, flexible.

This is what "always 90 days ahead, constantly checks and adapts" means in practice: near-term is concrete and data-corrected; far-term is directional and cheap to change.

---

## 3. Data model (see `db/schema.sql`)

- **Raw, per-source daily tables** — `gsc_query_daily`, `gsc_page_daily`, `ga4_page_daily`, `ga4_channel_daily`, `clarity_page_daily`, `bing_query_daily`, `bing_crawl_daily`. Keyed by `property_id` + `date` so everything is multi-property and time-series from day one.
- **`ingest_run`** — provenance: which source, what period, row count, status. Makes the loop idempotent and auditable.
- **`recommendation`** — the analysis output: typed, scored, evidenced opportunities (`type`, `target`, `score`, `rationale`, `evidence_json`, `status`).
- **Plan tables** — `content_theme` (monthly), `content_item` (blog/landing/refresh), `social_post`. Items link back to the `recommendation` that spawned them, so every piece of content traces to data.
- **`weekly_report`** — the assembled report + KPI snapshot per week.

---

## 4. Adaptation logic — what the analyser looks for

Each signal maps to a recommendation type with a default action:

| Signal (source) | Detection | Recommendation type | Default action |
|---|---|---|---|
| **Striking distance** (GSC) | query at position 5–15, high impressions, low CTR | `optimise_onpage` | rewrite title/meta/H1; expand section |
| **Rising query, no home** (GSC) | query gaining impressions, no dedicated page | `new_content` | brief a new post/landing |
| **Decay** (GSC) | page losing clicks/position vs prior 28d | `refresh` | update + republish, add internal links |
| **High traffic, low engagement** (GA4) | sessions ok, engagement rate / conv low | `cro` | improve intro, CTA, intent match |
| **UX friction** (Clarity) | rage/dead clicks, quickbacks, shallow scroll | `ux_fix` | fix the element / layout / content order |
| **Channel shift** (GA4) | organic/social mix moving | `channel` | reallocate social or content effort |
| **Indexing/crawl** (Bing + GSC) | coverage errors, blocked, not indexed | `technical` | fix + resubmit |
| **Seasonal/news hook** (config + calendar) | upcoming season/event for the property | `seasonal` | time a theme/post to it |

### Scoring — Opportunity Score
Each recommendation gets `score = (Demand × PositionGap × Value) / Effort`, normalised 0–100.
- **Demand** — impressions / search-volume proxy.
- **PositionGap** — how far from page-1 top (most upside at pos 5–15).
- **Value** — closeness to a money page / conversion (`goals` in config).
- **Effort** — refresh < expand < net-new (1–3).

The planner pulls the highest-scoring open recommendations into the committed window first. Simple, transparent, tunable per property.

---

## 5. Scheduling

- **Phase 1:** the `/loop` skill runs `workflows/00-orchestrator.md` weekly, or a server cron hits a webhook that starts a Claude session on the bridge.
- **Phase 2:** cron runs coded ingestion first (fast, deterministic), then triggers the Claude session for analyse→plan→produce→report.
- **Manual:** the orchestrator is runnable on demand any time — it's idempotent (guarded by `ingest_run`).

---

## 6. Governance & safety

- **Brand voice + forbidden words** are enforced from each property's config on every generated asset.
- **Facts** flow from the property's `kb_facts` (and, for Aerotel, the `seo/FACTS-TO-VERIFY.md` gate) — nothing guest-checkable publishes unverified.
- **No fabricated metrics or reviews**, ever.
- **Human-in-the-loop** by default: the engine *drafts and schedules*; a person approves publish. Autonomy level is a config flag (`automation.publish: draft|review|auto`).
- **Quarterly** strategy review re-checks clusters, competitors, and goals so the flywheel doesn't optimise into a local maximum.
