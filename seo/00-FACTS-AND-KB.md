# 00 — Authoritative Facts & KB (self-contained)

*This file makes the `seo/` package self-contained: every fact the schema, titles, and copy depend on is here, so no external `aerotel-kb.json` is needed. Source: internal Aerotel KB v1.0 (2026-03-10). Anything a guest can fact-check must also clear `FACTS-TO-VERIFY.md` before publishing.*

## Business identity (canonical NAP — use byte-for-byte everywhere)
```
Aerotel Hoedspruit
1406 Zandspruit Boulevard, R527, Zandspruit Bush & Aero Estate, Hoedspruit, 1380, Limpopo, South Africa
+27 87 655 6737
reservations@aerotel.co.za
```
- **WhatsApp:** +27 78 275 6605 (not the primary call number)
- **Site:** https://aerotel.co.za · **Booking:** https://aerotel.co.za/book-sa.html
- **Coordinates:** -24.354530, 30.958450 *(confirm the map pin sits on the aircraft, not the R527 roadside)*

## The product
- **9 suites total** *(confirm — live site emphasises "six cabins")*:
  - **Boeing 737-200** — 6 "BIL" cabins: queen bed, en-suite, kitchenette, cockpit access.
  - **Boeing 727 "SAL" VIP suite** — exclusive-use, 4–6 guests, ex-government aircraft. *(727 variant 727-100 vs -300 unconfirmed — see FACTS-TO-VERIFY #1.)*
- **Rates 2026 (B&B, ZAR):** 737 = R2,250 pp sharing/night · 727 = R3,250 pp/night (min 4 guests).
- **Policies:** adults-only (12+), no pets, not wheelchair accessible. *(Child policy on the 727 unconfirmed — FACTS-TO-VERIFY #2.)*
- **Restaurant:** The Runway (Sky Deck, Pool Deck, Boma).

## Location signals
- **Nearest airports:** Hoedspruit Eastgate (HDS) ~5 km / 10 min *(confirm)*; Kruger Mpumalanga International (KMIA/MQP).
- **Kruger:** Orpen Gate ~60 km / 1 hour *(confirm)*. Near the Panorama Route / Blyde River Canyon.
- **Timezone:** SAST (UTC+2, no DST).
- **Currency:** ZAR canonical (schema `priceCurrency: "ZAR"`), with indicative ≈USD/GBP/EUR helper.

## Entity linking (Wikidata)
Hoedspruit Q1022089 · Kruger Q205727 · Limpopo Q131703 · South Africa Q258

## Brand voice
- **Power phrases:** "where legends land", "sleep in a Boeing", "wake to the bushveld", "aviation meets Africa".
- **Forbidden:** nestled, hidden gem, best-kept secret, world-class, luxurious oasis.

## Still-open items
All unconfirmed facts (727 model, child policy, drive times, suite count, product names, coordinates pin, real `sameAs` URLs) are tracked in **`FACTS-TO-VERIFY.md`** — clear them before anything guest-checkable goes live.
