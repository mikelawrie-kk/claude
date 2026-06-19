# Technical & On-Page SEO Audit

*Based on a live crawl of aerotel.co.za (2026-06-19) + the internal KB. The Aerotel-connected session must `file_read` each page to confirm the **current** tags before patching — treat the "current" column as indicative where noted.*

## What's already strong
- **HTTPS + LiteSpeed + HTTP/3**, sensible `cache-control` (24h) — good baseline performance posture.
- **Thoughtful `robots.txt`**: welcomes major search + AI crawlers (GPTBot, Claude-Web, PerplexityBot, Google-Extended), blocks aggressive scrapers, and references both `sitemap.xml` and an `llm.txt`. Forward-looking.
- **Distinctive, well-written content** and a genuine viral hook ("planes that broke the internet"). Strong brand voice and real testimonials (Airbnb/TripAdvisor/Google).
- **Clear nav/IA**: The Story · Stay (Cabins, FAQ) · Venue · Eat · Experiences · Blog · Contact.
- Existing blog assets (airplane-hotels roundup, Boeing transport story, Hoedspruit guides) — a real head start.
- 4-Star TGCSA badge and OTA integrations present.

## Prioritised issues

| Priority | Page/Scope | Issue | Concrete fix |
|---|---|---|---|
| **P0** | Sitewide | Confirm/expand **schema.org JSON-LD**. A LodgingBusiness/Hotel entity is the single biggest "understanding" signal for Google + AI and may be missing or thin. | Add `schema/hotel-lodgingbusiness.jsonld` to the global head; add FAQPage + Breadcrumb where relevant. |
| **P0** | Homepage `<h1>` | H1 is "Sleep in the Planes That Broke the Internet" — great hook, **zero location/commercial keywords**. | Keep the hook visually, but ensure an H1/early-H2 carries "Africa's only aircraft hotel near Kruger, Hoedspruit". |
| **P0** | All key pages | Titles/metas likely not optimised for the **dual local + overseas** intent. | Apply the rewrite table below; make every title unique and ≤60 chars. |
| **P1** | Sitewide | Verify **self-referencing canonicals** + **Open Graph/Twitter** tags on every page (this site is shared heavily on social/press). | Add/confirm `canonical`, `og:*`, `twitter:card`. |
| **P1** | `sitemap.xml` | Verify it contains **all** pages incl. every cabin + blog post with `lastmod`. | Regenerate/complete; resubmit in GSC + Bing. |
| **P1** | Images | Hero-heavy site; check `alt`, filenames, WebP/AVIF, lazy-load, LCP preload. | Optimise per Phase 2.2 of the runbook. |
| **P1** | International | No dedicated **"how to get here from overseas"** page (airports/transfers). | Create it (strong intl + AI-overview asset). |
| **P2** | Internal links | Blogs should funnel to money pages; commercial pages need an above-the-fold CTA. | Add contextual links + "Check availability" → `book-sa.html`. |
| **P2** | hreflang | Do **not** add hreflang (single English site) — see `03-…` Part B1. | Leave as-is; rely on `.co.za` + geo-detection. |

## Per-page title & meta rewrites

> Lengths target ≤60 chars (title) / ≤155 chars (meta). Confirm each page's real current tag with `file_read` first; the homepage "current" below was observed live, the rest are indicative.

| Page | Current (verify live) | New title (≤60) | New meta description (≤155) |
|---|---|---|---|
| **Home** | `Aerotel Hoedspruit \| South Africa's Only Aircraft Hotel \| Sleep in a Boeing 737` (78 — too long) | `Aerotel \| Sleep in a Boeing — Aircraft Hotel near Kruger` | Africa's only aircraft hotel. Sleep inside a Boeing 737 or exclusive 727 VIP jet in Hoedspruit, gateway to Kruger National Park. Book your stay. |
| **The Story** | _verify_ | `The Boeings That Broke the Internet \| Aerotel Story` | How two retired Boeings became Africa's only aircraft hotel near Kruger — the viral road journey and the ex-government 727. Read the story. |
| **Stay (overview)** | _verify_ | `Stay at Aerotel \| Aircraft Cabins near Kruger, Hoedspruit` | Spend the night inside a real aircraft near Kruger. Boeing 737 cabins from R2,250pp and an exclusive 727 VIP suite. Bed & breakfast included. |
| **Boeing 737 Cabins** | _verify_ | `Boeing 737 Cabins \| Sleep in a Plane \| Aerotel` | Sleep in a private cabin inside a converted Boeing 737-200 — queen bed, en-suite, kitchenette, cockpit access. From R2,250pp near Kruger. |
| **Boeing 727 VIP Suite** | _verify_ | `Boeing 727 VIP Suite \| Exclusive-Use Jet \| Aerotel` | Book the entire ex-government Boeing 727 for 4–6 guests — bedrooms, full kitchen, living area. A private aircraft suite near Kruger, Hoedspruit. |
| **Venue** | _verify_ | `Aircraft Event Venue near Kruger \| Aerotel Hoedspruit` | Host an unforgettable wedding, group stay or event at Africa's only aircraft hotel near Kruger National Park, Hoedspruit. Enquire today. |
| **Eat (The Runway)** | _verify_ | `The Runway Restaurant \| Dining at Aerotel, Hoedspruit` | Dine on the Sky Deck, Pool Deck and Boma at The Runway — bushveld dining at Africa's only aircraft hotel near Kruger, Hoedspruit. |
| **Experiences** | _verify_ | `Things to Do in Hoedspruit & Kruger \| Aerotel` | Kruger safaris, the Panorama Route, wing-walk sundowners and cockpit tours. Plan your Hoedspruit experiences from Aerotel aircraft hotel. |
| **Location / Getting here** | _verify_ | `Getting to Aerotel \| Hoedspruit Airport & Kruger Access` | How to reach Aerotel near Kruger: Hoedspruit Eastgate (HDS) and KMIA airports, transfers, self-drive directions and GPS. Plan your trip. |
| **FAQ** | _verify_ | `Aerotel FAQ \| Rates, Cabins & Your Stay near Kruger` | Rates, what's included, who can stay, check-in times and how to get here. Everything you need to know about staying at Aerotel, Hoedspruit. |
| **Contact / Book** | _verify_ | `Book Aerotel Hoedspruit \| Aircraft Hotel near Kruger` | Check availability and book your stay inside a real Boeing near Kruger National Park. Call +27 87 655 6737 or reserve online. |
| **Blog** | _verify_ | `Aerotel Blog \| Aircraft Hotel, Hoedspruit & Kruger Guides` | Stories from Africa's only aircraft hotel plus guides to Hoedspruit, Kruger and the Panorama Route. Plan your unique South African stay. |

> Pipe characters shown as `\|` are for this Markdown table only — use a literal `|` in the actual tags.
