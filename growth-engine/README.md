# Growth Engine — property-agnostic content & SEO flywheel

A single engine that runs the same growth loop for **every** property you own. You add a property by dropping in one config file; the engine does the rest: ingest analytics → analyse → plan a **rolling 90-day** content/social/theme calendar → produce briefs in the property's brand voice → measure → **report weekly** → adapt → ratchet the horizon forward so it's *always* 90 days ahead.

```
            ┌─────────────────────────────────────────────────────────┐
            │                    THE FLYWHEEL (weekly)                  │
            │                                                           │
   INGEST ──┤  GSC · GA4 · MS Clarity · Bing WMT  → MySQL              │
            │        │                                                  │
  ANALYSE ──┤        ▼  movers · decay · gaps · UX friction · indexing │
            │        │   → scored recommendations                      │
   PLAN  ───┤        ▼  rolling 90-day calendar (themes→blogs→social)  │
            │        │   ratchet: top horizon back up to 90 days       │
 PRODUCE ──┤        ▼  briefs & drafts in brand voice                  │
            │        │                                                  │
 MEASURE ──┤        ▼  attribute results back to items                 │
            │        │                                                  │
  REPORT ──┴────────▼  weekly progress report → back to INGEST ────────┘
```

## Why it's property-agnostic
The engine code/workflows never hard-code a property. Everything property-specific lives in **`config/properties/<slug>.json`** (domain, locale, brand voice, KB facts, analytics IDs, goals, cadence, keyword clusters). Aerotel is the first instance (`config/properties/aerotel.json`); adding "Property #2" is a new JSON file validated against `config/property.schema.json`.

## How it runs (the recommendation)
**Hybrid, phased** — see `ARCHITECTURE.md` for the full rationale:
- **Phase 1 (now):** Claude-orchestrated. Workflows in `workflows/` are executed by a Claude session on the SWO bridge; data lives in the cPanel MySQL (`db/schema.sql`); ingestion is **CSV import** (`ingestion/`).
- **Phase 2 (as creds arrive):** swap the CSV adapter for **live API adapters** per source (GSC, GA4, Clarity, Bing WMT) — no change to analysis/planning/reporting.

## Cadence
| Cadence | What happens | Workflow |
|---|---|---|
| **Weekly** (the heartbeat) | ingest → analyse → adapt near-term plan → ratchet horizon to 90 days → produce next briefs → weekly report | `workflows/00-orchestrator.md` |
| **Monthly** | set/refresh the content **theme** for the month entering the horizon | `workflows/03-plan-90day.md` |
| **Quarterly** | strategy review: clusters, competitors, goals | `ARCHITECTURE.md` §Governance |

## Repo layout
```
growth-engine/
  README.md                 ← you are here
  ARCHITECTURE.md           ← execution model, recommendation, scoring, governance
  config/
    property.schema.json    ← the property-agnostic contract (JSON Schema)
    properties/
      aerotel.json          ← reference instance (filled from the Aerotel KB)
      _template.json        ← copy this to add a property
  db/
    schema.sql              ← MySQL: metrics, recommendations, calendar, reports
    README.md
  workflows/                ← the SOPs the orchestrator executes
    00-orchestrator.md  01-ingest.md  02-analyse.md
    03-plan-90day.md    04-produce.md  05-weekly-report.md
  ingestion/
    README.md               ← adapter contract + CSV formats per source
    csv-templates/          ← expected headers for each export
  templates/
    content-brief.md  blog-post.md  social-post.md
    content-theme.md  90-day-calendar.md  weekly-report.md
```

## Quickstart (Aerotel)
1. Apply `db/schema.sql` to the hub MySQL (via the bridge `db_execute`). *(deploy step — see db/README.md)*
2. Confirm `config/properties/aerotel.json` (brand voice + analytics IDs).
3. Drop the first analytics exports into the CSV templates (`ingestion/`).
4. Run `workflows/00-orchestrator.md` — it seeds the 90-day plan and emits the first weekly report.
5. Schedule it weekly (the `/loop` skill or server cron — `ARCHITECTURE.md` §Scheduling).

> **Realistic expectations** carry over from the SEO package: ingestion + reporting are immediate; ranking/traffic movement compounds over weeks–months. The engine's job is to make the *right* compounding bets every week and prove progress.
