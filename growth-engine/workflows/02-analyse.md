# Workflow 02 — Analyse

**Goal:** turn raw metrics into a ranked list of scored, evidenced `recommendation` rows. Comparison baseline = **last 28 days vs the prior 28 days** (smooths weekly noise).

## Detectors (each emits a recommendation with `evidence_json`)
Run these queries per property over the comparison windows:

1. **Striking distance** — `gsc_query_daily` where `position BETWEEN 5 AND 15` AND `impressions` high AND `ctr` below expected-for-position → `optimise_onpage` on the ranking page.
2. **Rising query, no home** — query with rising impressions whose top `page` is weak/generic or absent → `new_content`.
3. **Decay** — `gsc_page_daily` / `ga4_page_daily` where clicks or position dropped ≥ threshold vs prior 28d → `refresh`.
4. **Traffic-rich, engagement-poor** — `ga4_page_daily` with healthy sessions but low `engagement_rate` / `conversions` (esp. money pages) → `cro`.
5. **UX friction** — `clarity_page_daily` with high `rage_clicks`/`dead_clicks`/`quickbacks` or low `avg_scroll_depth` → `ux_fix`.
6. **Channel shift** — `ga4_channel_daily` mix moving materially → `channel`.
7. **Indexing/crawl** — `bing_crawl_daily` errors/blocked, or GSC coverage gaps → `technical`.
8. **Seasonal** — upcoming events/season from config + calendar with no planned content → `seasonal`.

## Scoring
`score = normalise( (Demand × PositionGap × Value) / Effort )` → 0–100.
- **Demand** = impressions (or search-volume proxy).
- **PositionGap** = upside from current position (peaks at pos 5–15; ~0 at pos 1 or > 30).
- **Value** = weight from `goals.money_pages` proximity (money page = 3, supporting = 2, top-of-funnel = 1).
- **Effort** = `refresh`/`ux_fix` = 1, `optimise_onpage`/`cro` = 2, `new_content`/`landing` = 3.

Write the formula inputs into `evidence_json` so every score is explainable in the weekly report.

## Housekeeping
- **Dedupe** against existing `open`/`planned` recommendations (same type+target) — update score, don't duplicate.
- **Auto-close** recommendations whose signal has resolved (e.g. page now ranks top-3) → `status='done'` with a note (a win to celebrate in the report).
- Cap output to the top N per type so the plan stays focused (default N=10).

## Output
A refreshed `recommendation` table: open items ranked by score, resolved items closed, each with evidence. This is the raw material the planner pulls from.
