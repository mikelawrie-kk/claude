# Local SEO, International Targeting & Structured Data

*For Aerotel Hoedspruit (https://aerotel.co.za). The structured-data (Part C) lives as ready-to-paste files in `schema/`, corrected against the internal KB. Confirm everything in `FACTS-TO-VERIFY.md` first.*

---

## PART A — Local SEO Action Plan

### A1. Google Business Profile (GBP) — the fastest local lever

**Category**
- **Primary: `Hotel`** (overnight bookings, breakfast/dinner, graded room product — strongest hotel-pack and Google Travel signals). Do **not** use "Guest house" even if TripAdvisor classifies it that way.
- **Secondary:** `Resort hotel`, `Lodging`, `Tourist attraction`, `Bar`, `Restaurant` (The Runway). "Tourist attraction" legitimately captures day-visitor / "things to do" intent.

**Core profile**
- Exact NAP matching the site footer + schema (see A2). One phone (+27 87 655 6737) as primary; put WhatsApp in the description/attributes, not as primary call number.
- **Set the map pin precisely on the aircraft** inside Zandspruit Bush & Aero Estate — a misplaced pin in a remote estate is a common ranking/UX killer.
- Hours, check-in/out times, and the adults-only (12+) policy in the description.
- Booking link → your direct booking engine (`book-sa.html`), not an OTA.

**Attributes & services (tick all true):** Free Wi-Fi, free parking, swimming pool, bar on-site, restaurant on-site, breakfast included, air-conditioning, en-suite, non-smoking. Set wheelchair-accessible = **No** honestly. Highlight "Great view" / "Unique stays".

**Photos & video (this property's biggest organic lever):** upload high-res, descriptively-named files in priority order — both aircraft at golden hour, wing-walk, cockpit, 737 cabin, 727 VIP interior, the pool, sundowners over the Drakensberg, The Runway dining, night exterior. Add a short walkthrough video and a 360°/interior cabin tour. Refresh monthly — recency is a local ranking factor.

**Q&A seeding** (post from a separate account, answer from the business account): "Can you really sleep inside the plane?" / "Is breakfast included?" / "Is it suitable for children?" / "How far is Kruger / the nearest airport?" / "Do you allow day visitors?" / "Is there air-conditioning?" Pre-seeding the official answers prevents wrong crowd-sourced answers and feeds AI overviews.

**Posts** (weekly–fortnightly): seasonal rates/offers, Kruger Big-5 season, Panorama Route, sundowner events. Always CTA → direct booking.

**Review-generation strategy** (you have dispersed reviews on TripAdvisor, Airbnb, Booking.com, Agoda, Expedia, LekkeSlaap, SafariNow — Google reviews are the highest-leverage gap):
- Generate a short GBP review link (`g.page/r/…`); put it on an in-cabin card, in the WhatsApp check-out message, and the post-stay email.
- Ask at the emotional peak (after wing-walk/sundowner, or a delighted check-out). Aim for a steady drip (4–8/month), not bursts.
- **Respond to every review within 48h**, weaving in natural keywords ("…glad you enjoyed the Boeing 727 suite and the Drakensberg sunset").
- Never import/copy reviews from other platforms onto Google — against policy.

### A2. NAP consistency

Lock one canonical NAP and use it byte-for-byte everywhere (footer, schema, every citation):

```
Aerotel Hoedspruit
1406 Zandspruit Boulevard, R527, Zandspruit Bush & Aero Estate, Hoedspruit, 1380, Limpopo, South Africa
+27 87 655 6737
reservations@aerotel.co.za
```
> Verify the postal code and that the business name is consistent (fix any older "Aerotel Boutique Hotel" variant).

### A3. Citation / listing targets (claim or fix)

**South African:** SafariNow, LekkeSlaap, TravelGround, SA-Venues.com, Where to Stay (wheretostay.co.za), Tripadvisor (.com & .co.za), Hoedspruit/Kruger Lowveld tourism portals.

**International:** Booking.com, Expedia, Agoda, Airbnb, Google Travel/Hotels, **Unusual Hotels of the World** (high-priority niche fit), **Atlas Obscura Places** (US/UK editorial discovery), Trip.com, **Bing Places**, **Apple Business Connect** (iPhone-heavy US/UK travellers).

Priority order: fix the listings you already own (NAP + photos) first, then pursue Unusual Hotels of the World, Atlas Obscura, Bing Places, Apple Business Connect — high-fit and largely uncontested.

---

## PART B — International / Overseas Targeting

### B1. hreflang / geo — what to actually do (don't over-engineer)

You have **one English site** serving SA locals + US/UK/EU travellers.

- **Do NOT implement hreflang.** It disambiguates *multiple* language/region URL variants; with a single English URL there's nothing to map and it only adds risk. Skip it.
- Keep `<html lang="en">` and write content that reads naturally to both audiences (avoid SA-only slang in conversion copy).
- Rely on Google's automatic geo-detection. A `.co.za` ccTLD signals South Africa strongly — fine, because in-country bookings matter and the property *is* in SA. International users still find you via branded/niche search.
- Low-effort win: put the country in titles/H1s ("…in Hoedspruit, South Africa, near Kruger National Park") so overseas searchers instantly grasp location.

**Currency / price:** display rates in **ZAR (R)** as canonical (matches schema `priceCurrency: "ZAR"`), with an indicative "≈ USD/GBP/EUR" helper near the rate. The ZAR rate is attractive in hard currency — lean into it. Keep currency consistent across OTAs and schema.

**Timezone:** state **SAST (UTC+2, no DST)** wherever times appear (check-in/out, restaurant hours) — overseas guests booking transfers/flights need it explicit.

**"How to get here from overseas" page** (strong international SEO + AI-overview asset): nearest airports **Hoedspruit Eastgate (HDS)** and **Kruger Mpumalanga International (KMIA/MQP)**; two routings — (a) International → Johannesburg (JNB) → Airlink to HDS → short transfer; (b) International → JNB/CPT → KMIA → road transfer. Include transfer times, whether Aerotel arranges transfers, self-drive R527 directions + GPS, and Big-5/malaria-area notes. Verify all transfer times and airline routings against current schedules.

### B2. Google Search Console
- With a `.co.za` ccTLD, International Targeting → Country is effectively South Africa — acceptable, don't fight it.
- Submit one clean XML sitemap; ensure the "how to get here" and each cabin page are indexed.
- Monitor **Performance by Country** to see real US/UK/EU demand and tailor content (currency helpers, transfer info) to the top overseas markets.

---

## PART C — Structured Data

The ready-to-paste JSON-LD blocks are in **`schema/`**, grounded in the verified KB (real coordinates, rates, units, Wikidata links):

- `schema/hotel-lodgingbusiness.jsonld` — primary Hotel/LodgingBusiness entity (site-wide, in `<head>`).
- `schema/faqpage.jsonld` — FAQ markup (semantic/AI value; note Google deprecated the FAQ rich result, so don't expect a SERP feature).
- `schema/breadcrumb-cabin.jsonld` — example BreadcrumbList for a cabin page.
- `schema/aggregaterating.jsonld` — review snippet **with real-data-only caveat** (never fabricate ratings).

> Implementation notes: place each block in `<head>`; all can co-exist and share the `@id` `https://aerotel.co.za/#hotel` so they merge into one entity rather than duplicating. Validate with Google's Rich Results Test after publishing.
