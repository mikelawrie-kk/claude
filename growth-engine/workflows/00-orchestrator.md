# Workflow 00 — Orchestrator (the weekly heartbeat)

**Run:** weekly (default Monday), per property, or on demand. Idempotent — safe to re-run.
**Inputs:** `config/properties/<slug>.json`, the hub DB.
**Does:** drives the whole flywheel by calling workflows 01→05 in order, then ratchets the horizon.

## Preconditions
- `db/schema.sql` applied; property row exists (upsert from config if not).
- A bridge connection that can write the property's site (for the produce step) — `deploy.bridge_ref` in config.

## Steps
1. **Load context.** Read the property config. Pull last week's `weekly_report`, open `recommendation`s, and the current `content_item` plan from the DB.
2. **Ingest** → run `01-ingest.md`. Skip any source whose period is already loaded `ok` in `ingest_run`.
3. **Analyse** → run `02-analyse.md`. Writes/updates scored `recommendation` rows.
4. **Re-plan + ratchet** → run `03-plan-90day.md`:
   - mark elapsed items done/slipped,
   - adapt the committed + shaped windows from new recommendations,
   - **extend the far edge so the horizon is again 90 days out**,
   - set/refresh the month theme entering the window.
5. **Produce** → run `04-produce.md` for everything entering the **committed** band (briefs, drafts, social) in brand voice. Respect `automation.publish`.
6. **Report** → run `05-weekly-report.md`; store in `weekly_report` and deliver.
7. **Persist + log.** Update statuses; write an `ingest_run`/audit note. End.

## The ratchet, precisely
Let `today = T`. Target horizon end = `T + 90`. After a week elapses the plan's far edge sits at `T + 83`; step 4 adds content slots for `T+84 … T+90` so the runway is restored to a full 90 days **every week**. Near-term bands are re-derived from fresh data; far band stays directional. See `ARCHITECTURE.md` §2.

## Multi-property
Loop this over every `active` property, or run per-property on its own `report_day`. State is fully isolated by `property_id`, so properties never interfere.

## Failure handling
- A failed source ingest → mark `ingest_run.status='failed'`, continue with the rest, note the gap in the report. Never block the whole loop on one source.
- If no new data at all → still run analyse/plan on existing data, note "no fresh data" in the report, do not fabricate movement.
