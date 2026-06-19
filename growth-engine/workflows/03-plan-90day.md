# Workflow 03 — Plan & ratchet the rolling 90-day calendar

**Goal:** keep a living calendar that is **always ~90 days ahead**, near-term concrete and data-corrected, far-term directional and cheap to change.

## Horizon bands (enforced by `content_item.horizon_band`)
| Band | Window | Detail locked |
|---|---|---|
| **committed** | day 0–14 | brief written, social drafted, date + target page fixed |
| **shaped** | day 15–45 | title + primary keyword + target page; brief pending |
| **themed** | day 46–90 | month theme + candidate angles only |

## Steps (run every weekly cycle)
1. **Close the week behind.** Mark elapsed `content_item`s `published`/`done` (or `slipped` → reschedule into the committed band). Snapshot any early metrics into `metrics_json`.
2. **Refresh themes.** Ensure every month touching the 90-day window has a `content_theme` (intent + clusters from config). When a new month enters at the far edge, create its theme — tie it to upcoming seasonality and the strongest open clusters.
3. **Pull opportunities into slots.** Take the highest-scoring open `recommendation`s and bind them to `content_item`s:
   - `new_content` → a `blog`/`landing` item under the relevant theme.
   - `refresh` → a `refresh` item targeting the decaying URL.
   - `optimise_onpage`/`cro`/`ux_fix`/`technical` → tasks attached to the target page (not always a blog — can be an on-page edit item).
   Respect cadence caps (`blogs_per_week`, `social_per_week`).
4. **Adapt near-term.** In the committed + shaped bands: promote items whose supporting data strengthened, demote/cut items whose rationale weakened, and slot any urgent new high-score recommendation in (bump the lowest-value committed item to shaped if needed).
5. **RATCHET.** Because a week elapsed, add fresh `themed` slots at day 84–90 so the horizon is a full 90 days again. The plan regenerates its own runway — it never counts down to an end date.
6. **Derive social.** For each committed/shaped `content_item`, generate the `social_post` rows across the property's `channels` (repurpose the angle; don't just link-drop). Add standalone social for seasonal hooks.
7. **Promote bands.** Re-stamp `horizon_band` for every item by its distance from today (items naturally migrate themed→shaped→committed as time passes).

## Guardrails
- Don't over-plan the far band — themed items are deliberately loose.
- Keep a healthy mix per the config audiences (e.g. Aerotel: overseas viral magnets + local commercial), not all of one.
- Every item must link to a `recommendation` **or** a theme rationale — no content without a reason.

## Output
An updated `content_theme` / `content_item` / `social_post` set: a coherent, data-driven, brand-aligned 90-day rolling plan ready for the produce step.
