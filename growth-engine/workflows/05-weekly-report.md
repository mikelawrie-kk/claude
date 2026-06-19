# Workflow 05 — Weekly report

**Goal:** a short, honest, decision-useful progress report per property, stored in `weekly_report` and delivered.
**Run:** end of the weekly cycle.

## Assemble from the DB (no fabrication — if data is missing, say so)
1. **KPIs vs prior week + prior 28d** (from `goals.kpis`): organic clicks, impressions, avg position on tracked clusters, engaged sessions / conversions, branded vs non-branded share, overseas-market sessions, GBP actions if tracked. Show Δ and a tiny sparkline-style trend where possible.
2. **What moved & why** — top gains and drops, each tied to the recommendation/content that drove it (closed-the-loop wins are the headline).
3. **Shipped this week** — content_items published, social posted, on-page/technical fixes applied.
4. **Adaptations made** — what the engine changed in the plan based on data (promoted/cut/added), so the human sees the system thinking.
5. **Next 14 days (committed band)** — what's going out, with dates.
6. **Horizon health** — confirm the plan is a full 90 days again after the ratchet; flag any cadence shortfall.
7. **Flags / asks** — data gaps (a source that failed to ingest), facts awaiting verification, decisions needing a human.

## Style
- Lead with outcomes, not activity. One screen if possible; detail in appendices/links.
- Plain Δ numbers; no vanity metrics. State uncertainty (e.g. "GSC has a 2-day lag; last 2 days provisional").
- Reuse the property `brand.voice` lightly for readability, but the report is internal/operator-facing — clarity over flourish.

## Output
- `weekly_report` row (`summary_md` + `kpis_json`).
- Delivered to the operator (email/Slack/file per setup).
- Use `templates/weekly-report.md` as the structure.
