# Aerotel Hoedspruit — Audit Report, Recommendations & Agent Execution Plan

**Prepared:** 2026-06-19 · **Site:** https://aerotel.co.za · **Branch:** `claude/upbeat-dirac-wd4nlg`

This document is the executive layer on top of the SEO package in this folder. It has three parts:

1. **Audit report** — the state of the site and what's holding it back.
2. **Recommendations** — prioritised, with realistic timelines.
3. **Agent execution plan** — what each agent would do if the work is run by a team of Claude agents through the Aerotel Bridge.

> Source detail lives in `01-technical-onpage-audit.md`, `02-keyword-content-strategy.md`, `03-local-international-structured-data.md`, the `schema/` files, and `FACTS-TO-VERIFY.md`. This is the summary that ties them together.

---

## PART 1 — AUDIT REPORT

### 1.1 Verdict

Aerotel has a **rare, genuinely viral product** ("the planes that broke the internet") and a solid technical baseline, but it is **under-optimised for search** in exactly the places that convert: structured data, keyword-targeted titles/metas, location signals, and local (map-pack) presence. The story is a marketing asset most lodges will never have; the site does not yet translate that story into machine-readable signals or keyword coverage. The gap is the opportunity.

### 1.2 What's already strong

| Area | Finding |
|---|---|
| **Hosting/perf** | HTTPS + LiteSpeed + HTTP/3, 24h cache-control. Good baseline. |
| **Crawler policy** | `robots.txt` welcomes search + AI crawlers (GPTBot, Claude-Web, PerplexityBot, Google-Extended), blocks aggressive scrapers, references `sitemap.xml` and `llm.txt`. Forward-looking. |
| **Content & voice** | Distinctive, well-written, a real viral hook, real testimonials, clear IA (Story · Stay · Venue · Eat · Experiences · Blog · Contact). |
| **Existing assets** | Blog head start (airplane-hotels roundup, Boeing transport story, Hoedspruit guides), 4-Star TGCSA badge, OTA integrations. |

### 1.3 What's holding it back (prioritised)

| Priority | Issue | Impact |
|---|---|---|
| **P0** | **Structured data (schema.org) missing or thin.** No strong `LodgingBusiness`/`Hotel` entity is the single biggest "understanding" gap for Google + AI assistants. | High — blocks rich understanding, hotel-pack eligibility, AI-overview inclusion. |
| **P0** | **Homepage H1 carries the hook but zero location/commercial keywords** ("Sleep in the Planes That Broke the Internet"). | High — wastes the strongest on-page signal. |
| **P0** | **Titles/metas not optimised for the dual local + overseas intent**; some too long (homepage ~78 chars). | High — directly affects CTR and relevance. |
| **P1** | Canonicals + Open Graph/Twitter tags unverified on a heavily-shared site. | Medium — risks duplicate-URL dilution and poor social unfurls. |
| **P1** | Sitemap completeness unverified (every cabin + blog post + `lastmod`). | Medium — indexing gaps. |
| **P1** | Image SEO / Core Web Vitals on a hero-heavy site (alt, filenames, WebP/AVIF, LCP preload). | Medium — mobile LCP + image-search. |
| **P1** | **No "how to get here from overseas" page** (airports, transfers, GPS). | Medium — misses a strong international + AI-overview asset. |
| **P2** | Internal links don't funnel blogs → money pages; commercial pages need above-the-fold CTA. | Medium — conversion + link equity flow. |
| **P2** | **Google Business Profile / local citations** — the fastest *local* lever — not yet optimised. | High locally — off-platform, so it sits outside the code work but matters most for map-pack. |

### 1.4 Data-integrity risk (must resolve before publishing)

Three sources (live site, internal KB v1.0, original brief) **disagree** on guest-checkable facts — 727 model (727-300 vs 727-100), whether children are allowed in the 727, airport/Kruger drive times, total suites (6 vs 9), and product names. These are catalogued in `FACTS-TO-VERIFY.md`. **Anything a guest can fact-check must match on the page and in schema, or it erodes trust and Google may ignore the markup.** This is a blocker, not a nicety.

---

## PART 2 — RECOMMENDATIONS

### 2.1 Strategic recommendation

Don't try to out-muscle the Kruger-accommodation aggregators (Radisson Safari, krugerpark.co.za, booking.com) on generic "Kruger accommodation." **Own the low-competition, high-distinctiveness "aircraft / unusual stay" space** (where the product *is* the search and there's almost no SA competition), use the viral road-transport story to earn authority and links, then ride that domain strength into the more competitive local "Hoedspruit / Kruger gateway" terms. The 2019/2021 road-journey saga is a repeatable PR asset most lodges simply don't have — lean on it.

### 2.2 Sequenced plan & realistic timelines

| When | Do | Expected movement |
|---|---|---|
| **Same day (P0)** | Resolve `FACTS-TO-VERIFY.md`; inject `LodgingBusiness` + FAQ + Breadcrumb schema; rewrite titles/metas; fix H1s; confirm canonicals + OG/Twitter. | Fastest to implement, first to move (days–weeks). |
| **Week 1 (P1)** | Complete + submit sitemap (GSC + Bing); image SEO + LCP; internal linking + CTAs; build "how to get here from overseas" page. | Indexing + CWV + intl asset live within weeks. |
| **Week 1 in parallel (local)** | **Google Business Profile** full optimisation (category = Hotel, precise pin on the aircraft, photos, Q&A seeding, review-generation link); fix citations with byte-identical NAP. | Fastest *local* / map-pack win. |
| **Months 1–12 (compounding)** | Publish the 12-post content plan (start #1/#2/#5 viral magnets + #4/#6 commercial); digital-PR the road-journey story to aviation/travel media. | The long game — authority + links. |

### 2.3 Hard guardrails

- **No instant rankings.** Google must re-crawl and re-evaluate; competitive terms take months of compounding. Items 1–4 above are the genuine quick wins; content/PR is the 6–12 month program.
- **Never fabricate** `aggregateRating`/review data — only publish from your own verifiable reviews.
- **Do NOT add hreflang** — single English site; it adds risk with no benefit. Rely on `.co.za` ccTLD + geo-detection, and put the country in titles/H1s.
- **Brand voice:** use "where legends land", "sleep in a Boeing", "wake to the bushveld". **Forbidden:** nestled, hidden gem, best-kept secret, world-class, luxurious oasis.
- **Read before you write, back up, prefer surgical `file_patch`, validate each change** (Rich Results Test / view-source).

---

## PART 3 — AGENT EXECUTION PLAN ("what each agent would do")

The implementation must run in a **Claude session with the Aerotel Bridge connector loaded** (this SWO-hub session is scoped to the `safariweb` account and cannot write to `/home/aerotelco`). Inside that session, the work decomposes cleanly into the agents below. They are ordered by dependency; **Agent 0 and Agent 1 are blocking gates** for the rest. Agents 2–5 can largely run in parallel once the gates pass; Agents 6–8 are off-platform and run on their own cadence.

| # | Agent (role) | Mandate | Primary bridge tools | Inputs | Output / done-when |
|---|---|---|---|---|---|
| **0** | **Recon agent** | Map the live site: flat HTML vs PHP includes vs CMS; find the shared `<head>` template; record real current `<title>`/meta/H1/canonical/JSON-LD on the homepage + one of each page type; read `sitemap.xml`, `robots.txt`, `llm.txt`. | `dir_list`, `file_read` | Runbook Phase 0 | A structure map + the **actual** current tags, so every later edit is exact, not assumed. **Gate for all.** |
| **1** | **Fact-verification agent** | Resolve every row of `FACTS-TO-VERIFY.md` against current reality (727 model, child policy, drive times, suite count, product names, coordinates, real `sameAs` URLs). | `file_read`, plus owner confirmation | `FACTS-TO-VERIFY.md` | One locked, canonical fact set. **Blocking gate** — nothing guest-checkable publishes until this passes. |
| **2** | **Structured-data agent** | Inject `hotel-lodgingbusiness.jsonld` sitewide in `<head>`; add FAQPage to FAQ page, Breadcrumb to each cabin page; share `@id` `…/#hotel` so blocks merge into one entity. Only add `aggregaterating.jsonld` if real review data exists. Replace all `REPLACE_WITH_REAL_*` placeholders. | `file_read`, `file_patch`/`file_write` | `schema/*`, Agent 1 facts | All schema validates in Google Rich Results Test; no fabricated data. |
| **3** | **On-page agent** | Apply the per-page title/meta rewrites (`01-…`); ensure one keyword-bearing H1 per page (keep the hero hook visually); confirm self-referencing canonicals + OG/Twitter on every page. | `file_read`, `file_patch` | `01-…` rewrite table, Agent 0 real tags | Every key page: unique ≤60-char title, ≤155-char meta, one H1, canonical + social tags. |
| **4** | **Technical / indexing / perf agent** | Complete `sitemap.xml` (every page + `lastmod`), submit to GSC + Bing; image SEO (alt, filenames, WebP/AVIF, lazy-load); preload LCP hero; enable LiteSpeed cache/image opt. | `file_read`, `file_patch`/`file_write`, `dir_list` | Runbook Phase 2 | Sitemap complete + submitted; no accidental `noindex`; hero images optimised; reasonable mobile LCP. |
| **5** | **International-asset agent** | Build the "how to get here from overseas" page (airports HDS + KMIA, two routings, transfer times, GPS, self-drive R527, malaria/Big-5 notes); add ZAR-canonical pricing with ≈USD/GBP/EUR helper + SAST timezone notes. | `file_create`, `file_patch` | `03-…` Part B, Agent 1 distances | New page live, internally linked, distances verified. |
| **6** | **Content agent** *(off-platform)* | Draft the 12-post plan in brand voice, KB-accurate, each linking down to a money page and up to a pillar. Start #1/#2/#5 (viral) + #4/#6 (commercial). | Repo drafts now; bridge `file_create` to publish | `02-…` blog plan | Ready-to-publish drafts; published on a sustainable cadence. |
| **7** | **Local-SEO agent** *(off-platform, manual)* | Drive Google Business Profile (category Hotel, precise pin, photos, Q&A seeding, review link + response SLA) and fix citations with byte-identical NAP. | Owner-driven; agent supplies copy/checklists | `03-…` Part A | GBP optimised; citations consistent; review drip running. |
| **8** | **Digital-PR agent** *(off-platform)* | Build the "two Boeings, two road journeys" media kit; pitch aviation/travel outlets and unusual-stay roundups; run HARO/creator outreach. | Agent produces kit + pitch copy | `02-…` Digital PR | Media kit live; pitches out; placements tracked. |

### 3.1 Orchestration

- **Gates first:** Agent 0 (recon) and Agent 1 (facts) run before anything is written. They are cheap and prevent every downstream error.
- **Parallel core:** once gates pass, Agents 2, 3, 4, 5 operate on largely different files and can run concurrently — coordinate only on the shared `<head>` template (Agents 2 & 3 both touch it; serialise those two edits or use `file_multi_patch`).
- **Continuous:** Agents 6, 7, 8 run on their own cadence and don't block the code work.
- **Every writing agent obeys the golden rules:** read before write, keep backups on, prefer `file_patch`, validate each change, and never publish an unverified guest-checkable fact.

### 3.2 If you'd rather not run a team

A single agent in the Aerotel-connected session can execute the same work by simply following `IMPLEMENTATION-RUNBOOK.md` top to bottom — the phases map 1:1 to the agents above (Phase 0 → Agent 0, Phase 1 → Agents 2/3, Phase 2 → Agents 4/5, Phase 3 → Agents 6/7/8). The multi-agent split only buys you parallelism and focus; it isn't required.

---

## One-line handoff

Open a fresh Claude Code session with the **Aerotel Bridge** connector enabled, point it at this `seo/` folder, and say *"run `IMPLEMENTATION-RUNBOOK.md`"* — clearing `FACTS-TO-VERIFY.md` first.
