# Aerotel Hoedspruit — SEO Optimization Package

**Prepared:** 2026-06-19 · **Site:** https://aerotel.co.za · **Business:** Africa's only aircraft hotel (Boeing 737-200 + Boeing 727 VIP), Hoedspruit, Limpopo, South Africa — gateway to Kruger National Park.

This package is everything needed to optimize aerotel.co.za for both **local (South African)** and **overseas/international** organic search. It is ready to apply directly through the **Aerotel Bridge** in a Claude session that has that connector loaded.

---

## ⚠️ Read this first — realistic expectations

There is no "instant" ranking. Google must re-crawl and re-evaluate changed pages, which takes **days to weeks**, and competitive terms take **months** of compounding. What this package front-loads are the genuinely *fast-impact* levers:

1. **Technical + on-page fixes** (titles, meta, headings, canonicals, internal links) — fastest to implement, first to move.
2. **Structured data (schema.org JSON-LD)** — helps Google and AI assistants understand and feature the property.
3. **Google Business Profile + local citations** — the fastest route to *local* (Hoedspruit/Kruger map-pack) visibility.
4. **Correct local + international targeting** — so the right pages serve the right audiences.
5. **Content + digital-PR** leveraging the viral aircraft story — the long-game that builds authority.

Treat 1–4 as the quick wins (weeks) and 5 as the 6–12 month compounding program.

---

## How to apply (you chose: add the Aerotel connector)

These changes live on a **separate cPanel account** from the SWO hub:

| Thing | Value |
|---|---|
| Live site | https://aerotel.co.za |
| Server path | `/home/aerotelco/public_html/` |
| cPanel user | `aerotelco` |
| Aerotel MCP bridge | `https://aerotel.co.za/mcp/?key=…` (Pattern A: `MCP_URL_SECRET`) |
| Booking page | https://aerotel.co.za/book-sa.html |

**Steps:**
1. Start a fresh Claude Code session with the **Aerotel Bridge** connector enabled (so calls originate from Anthropic's whitelisted IPs and aren't firewall-blocked).
2. Point it at this `seo/` folder and run **`IMPLEMENTATION-RUNBOOK.md`** — it lists the exact files to edit and the verification steps.
3. The runbook reads each page's *real* current `<head>` from the live server before changing it, so the title/meta edits are exact (no guessing).

> Note: this session (the SWO hub) is scoped to the `safariweb` account and cannot write to `/home/aerotelco`, which is why the live edits happen in the Aerotel-connected session.

---

## Contents

| File | What it is |
|---|---|
| `00-FACTS-AND-KB.md` | **Consolidated authoritative facts** (NAP, product, rates, location, voice) — makes this folder self-contained; no external KB needed. |
| `IMPLEMENTATION-RUNBOOK.md` | Step-by-step apply guide for the Aerotel-connected session (the action plan). |
| `AUDIT-REPORT-AND-AGENT-PLAN.md` | Executive audit summary, recommendations, and the per-agent execution plan. |
| `01-technical-onpage-audit.md` | Technical & on-page audit + title/meta rewrite tables. |
| `02-keyword-content-strategy.md` | Keyword clusters (local + overseas), page→keyword map, 12-post content plan, digital-PR/link angles. |
| `03-local-international-structured-data.md` | Google Business Profile plan, citation targets, hreflang/geo guidance, structured-data notes. |
| `schema/*.jsonld` | Ready-to-paste schema.org JSON-LD, grounded in the verified Aerotel KB (real coordinates, rates, units). |
| `assets/IMAGE-MANIFEST.md` | The shot list: every image needed, naming, alt text, where used, technical targets. |
| `assets/originals/` | Drop-folder for original image/video masters (see its README — don't bloat git). |
| `FACTS-TO-VERIFY.md` | Conflicting facts between the live site, the internal KB, and the brief — confirm these before publishing. |

> **This folder is the complete, self-contained package.** Everything the implementation needs is here except the binary image masters (which you place in `assets/originals/` or host on the origin/Cloudflare — see below).

## Images & Cloudflare

- **Where to store masters:** simplest and most agent-friendly is the **cPanel origin** (e.g. `/home/aerotelco/public_html/images/`). The Aerotel Bridge can then read, rename, alt-tag, optimise, and wire them into HTML + schema. Put **Cloudflare in front as a proxy (orange-cloud)** and enable Polish / auto WebP-AVIF so the edge handles format + performance automatically — you get the CDN win without moving the files off-origin.
- **Cloudflare Images / R2 (optional offload):** fine for taking weight off the origin, but the agent has **no Cloudflare connector** — it can't browse or pull from your Cloudflare account. If you store images there, paste the **public URL pattern** (e.g. `https://imagedelivery.net/<acct>/<id>/<variant>` or your R2/custom domain) and the agent will reference those URLs in `<img>`, `og:image`, and schema.
- **Bottom line on "can you find them there?":** on the **origin via the bridge — yes**; on **Cloudflare directly — no**, give me the URLs. Either way the SEO essentials (descriptive filenames, alt text, right dimensions, WebP/AVIF, lazy-load, LCP preload) are specified in `assets/IMAGE-MANIFEST.md`.

---

## Authoritative business facts (from internal Aerotel KB v1.0)

- **Coordinates:** -24.354530, 30.958450
- **Address:** 1406 Zandspruit Boulevard, R527, Zandspruit Bush & Aero Estate, Hoedspruit, Limpopo, South Africa
- **Phone:** +27 87 655 6737 · **WhatsApp:** +27 78 275 6605 · **Email:** reservations@aerotel.co.za
- **Units:** 9 suites total — Boeing 737-200 (6 "BIL" cabins, queen, en-suite, kitchenette, cockpit access) + Boeing 727 "SAL" VIP suite (exclusive-use, 4–6 guests, ex-government aircraft)
- **Rates 2026 (B&B, ZAR):** 737 = R2,250 pp sharing/night · 727 = R3,250 pp/night (min 4 guests)
- **Policies:** adults-only (12+), no pets, not wheelchair accessible
- **Restaurant:** The Runway (Sky Deck, Pool Deck, Boma)
- **Wikidata for entity linking:** Hoedspruit Q1022089 · Kruger Q205727 · Limpopo Q131703 · South Africa Q258
- **Brand voice:** sophisticated/adventurous/warm. Power phrases: "where legends land", "sleep in a Boeing", "wake to the bushveld", "aviation meets Africa". **Forbidden:** nestled, hidden gem, best-kept secret, world-class, luxurious oasis.
