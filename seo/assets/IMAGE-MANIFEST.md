# Image Manifest & Asset Spec

*The complete list of images the SEO + GBP + schema work needs, how to name them, what alt text to use, where each is used, and the technical targets. This is the spec — drop the actual files in `assets/originals/` (see that folder's README) and the optimised web versions get deployed to the site.*

## Naming & alt-text rules
- **Filename:** lowercase, hyphenated, descriptive, keyword-bearing — e.g. `aerotel-boeing-737-cabin-queen-suite-hoedspruit.webp`. Never `IMG_4821.jpg`.
- **Alt text:** describe the image *and* work in a natural keyword + location where honest. Don't keyword-stuff.
- **No fabricated context** — alt must match what's actually shown.

## Technical targets (apply to every web-deployed image)
| Property | Target |
|---|---|
| Format | WebP or AVIF (keep an original master: JPEG/PNG/TIFF) |
| Hero / LCP | preload, eager-load, sized to viewport; everything below the fold `loading="lazy"` |
| Dimensions | export at actual display size + 2× retina; never ship a 5000px file into a 1200px slot |
| Compression | visually lossless (~80–85 quality WebP) |
| Filename | descriptive, hyphenated (see above) |
| Alt | present and descriptive on every `<img>` |

## The shot list (priority order)

| # | Image | Used in | Filename (suggested) | Alt (draft) |
|---|---|---|---|---|
| 1 | Both aircraft at golden hour | OG/homepage hero, GBP, schema `image` | `aerotel-boeing-737-727-golden-hour-hoedspruit.webp` | Boeing 737 and 727 aircraft hotel at sunset, Hoedspruit, near Kruger |
| 2 | 737 cabin interior (queen, en-suite) | 737 page, schema, GBP | `aerotel-boeing-737-cabin-queen-ensuite.webp` | Queen bedroom inside a converted Boeing 737 cabin at Aerotel |
| 3 | 727 VIP suite interior | 727/Story page, GBP | `aerotel-boeing-727-vip-suite-interior.webp` | Interior of the exclusive-use Boeing 727 VIP suite |
| 4 | Cockpit | Story/Experiences, GBP | `aerotel-737-cockpit-access.webp` | Original cockpit guests can access at Aerotel |
| 5 | Wing-walk sundowners | Experiences, GBP, PR kit | `aerotel-wing-walk-sundowners-drakensberg.webp` | Sundowners on the aircraft wing overlooking the Drakensberg |
| 6 | Pool deck | homepage, GBP | `aerotel-pool-deck-bushveld.webp` | Pool deck with bushveld views at Aerotel |
| 7 | The Runway dining (Sky/Pool deck, Boma) | Eat page, GBP | `aerotel-the-runway-restaurant-sky-deck.webp` | Dining on the Sky Deck at The Runway restaurant |
| 8 | Night exterior of aircraft | homepage, PR kit | `aerotel-aircraft-hotel-night-exterior.webp` | Boeing aircraft hotel lit up at night, Hoedspruit |
| 9 | Road-journey / transport archival | PR media kit, Story | `aerotel-boeing-road-journey-transport.webp` | The Boeing being transported by road across South Africa |
| 10 | 360°/walkthrough video + interior tour | GBP, homepage | `aerotel-cabin-360-tour.mp4` | — (video) |

## Where images plug into the SEO work
- **Open Graph / Twitter:** image #1 (and per-page variants) → `og:image` / `twitter:image` (≥1200×630).
- **Schema:** `hotel-lodgingbusiness.jsonld` `image[]` → absolute URLs of #1–#3, #6.
- **GBP:** upload #1–#8 + the video, descriptively named, refreshed monthly (recency is a local ranking factor).
- **PR media kit:** high-res (non-WebP) masters of #1, #5, #8, #9 freely downloadable.

## Where the files physically live (decision)
See `assets/originals/README.md` and the "Images & Cloudflare" section of `README.md`. Short version: **keep originals on the cPanel origin under `/images/` (or `/assets/img/`) so the Aerotel Bridge can read/optimise/wire them**, and let Cloudflare optimise at the edge. Cloudflare Images/R2 is optional offload — if you use it, give the agent the public URL pattern, because it can't enumerate your Cloudflare account.
