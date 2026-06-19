# Facts to verify before publishing

Three sources describe Aerotel slightly differently — the **live site** (crawled 2026-06-19), the **internal KB** (`aerotel-kb.json` v1.0, 2026-03-10), and the **original brief**. Confirm each below against current reality before pushing copy or schema live. Where they conflict, the **internal KB** is treated as authoritative unless the live site is clearly more current.

| # | Item | Live site says | Internal KB says | Action |
|---|---|---|---|---|
| 1 | Boeing 727 model | 727-300 | 727-100 | Confirm exact variant; use consistently everywhere. |
| 2 | 727 audience | "welcomes families" | property is **adults-only (12+)**; 727 is **exclusive-use, 4–6 guests** | Confirm whether children are allowed in the 727. This changes FAQ + schema `petsAllowed`/age copy. |
| 3 | Nearest airport distance | ~20 min (HDS) | **5 km / 10 min** (Eastgate HDS) | Brief said 45 min. Confirm true drive time; used in FAQ, "how to get here", schema. |
| 4 | Kruger gate distance | 45 min | **60 km / 1 hour** (Orpen Gate) | Confirm. |
| 5 | Total suites | "six cabins" emphasized | **9 suites** (6× 737 + 727 VIP) | Confirm total bookable units for schema `numberOfRooms`. |
| 6 | Cabin product names | "BIL First Class", "SAL VIP Presidential" | BIL = "Business Class Cabins"; SAL = "Exclusive-use VIP Suite" | Use the **public-facing** names guests see when booking. |
| 7 | AggregateRating values | Reviews shown from Airbnb/TripAdvisor/Google | n/a | **Never fabricate.** Only publish `aggregateRating` built from your own verifiable review data; do not scrape OTA values. |
| 8 | Coordinates | n/a | -24.354530, 30.958450 | Confirm the Google Maps pin sits on the aircraft, not the R527 roadside. |
| 9 | `sameAs` social/OTA URLs | placeholders used in schema | n/a | Replace every placeholder with the real, live profile URL before publishing. |

> Rule of thumb: anything a guest could fact-check (distance, price, who can stay) must be correct on the live page **and** in schema, or it erodes trust and risks Google ignoring the markup.
