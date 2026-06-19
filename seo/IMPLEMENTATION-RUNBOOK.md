# Implementation Runbook — apply via the Aerotel Bridge

**Audience:** a Claude session with the **Aerotel Bridge** connector loaded (calls originate from whitelisted IPs and can read/write `/home/aerotelco/public_html/`).

**Golden rules**
1. **Read before you write.** For every file, `file_read` the current content first and confirm the real markup — never assume from this doc.
2. **Back up.** The bridge `file_write`/`file_patch` create backups by default; keep that on.
3. **Prefer `file_patch`** (surgical find/replace) over full `file_write` for templates.
4. **Verify each change** with `view-source` / Google Rich Results Test before moving on.
5. **Confirm `FACTS-TO-VERIFY.md`** before publishing any guest-checkable fact (distances, prices, who can stay, ratings).

---

## Phase 0 — Recon (do first)
1. `dir_list` the Aerotel project root to learn the structure (is it flat HTML, PHP includes, or a templated CMS?). Identify the shared `<head>` include/template — that's where global schema and meta defaults go.
2. `file_read` the homepage and 1 page of each type (cabin/stay, FAQ, blog post, contact). Record the **actual** current `<title>`, `<meta name="description">`, `<h1>`, `<link rel="canonical">`, and any existing `application/ld+json`.
3. `file_read` `sitemap.xml`, `robots.txt`, `llm.txt` and confirm the live URL list.

---

## Phase 1 — P0 quick wins (highest impact, do same day)

### 1.1 Structured data (schema.org)
- Inject **`schema/hotel-lodgingbusiness.jsonld`** into the global `<head>` (sitewide). Replace the `REPLACE_WITH_REAL_*` `sameAs` URLs and confirm coordinates/postcode.
- Add **`schema/faqpage.jsonld`** to the FAQ page (resolve every `VERIFY:` note first).
- Add **`schema/breadcrumb-cabin.jsonld`** pattern to each cabin/stay page (fix slugs to match real URLs).
- Only add **`schema/aggregaterating.jsonld`** if you have genuine aggregated review data. Otherwise skip — do not fabricate.
- Validate each with Google Rich Results Test.

### 1.2 Title tags & meta descriptions
Apply the per-page rewrites in **`01-technical-onpage-audit.md`** (titles ≤ ~60 chars, meta ≤ ~155). For each: `file_read` the current tag → `file_patch` to the new value. Ensure **every page is unique** and includes both a local anchor ("Hoedspruit"/"Kruger") and the distinctive hook ("Boeing"/"aircraft hotel").

### 1.3 One H1 per page
Confirm each page has exactly one `<h1>` containing its primary keyword. The homepage hero "Sleep in the Planes That Broke the Internet" is great for branding — ensure the H1 (or an early H2) also contains a searchable phrase like "Aerotel — Africa's only aircraft hotel near Kruger, Hoedspruit".

### 1.4 Canonicals & social tags
Confirm a self-referencing `<link rel="canonical">` on every page, plus Open Graph (`og:title/description/image/url`) and `twitter:card` — critical because this site gets shared on social and in press.

---

## Phase 2 — P1 (this week)

### 2.1 Sitemap & indexing
- Ensure `sitemap.xml` lists **every** real page (homepage, story, stay, each cabin, venue, eat, experiences, location, FAQ, contact, every blog post) with `lastmod`. Phase-0 recon will show gaps.
- Submit the sitemap in Google Search Console and Bing Webmaster Tools; request indexing for changed key pages.

### 2.2 Image SEO & performance (Core Web Vitals)
- This is an image-heavy, hero-driven site. For each major image: descriptive `alt`, descriptive filename, correct dimensions, modern format (WebP/AVIF), and lazy-loading for below-the-fold.
- Ensure the LCP hero is preloaded and not render-blocked. LiteSpeed is in front — enable its cache/image optimization if available.

### 2.3 Internal linking
- Add contextual links from blog posts **down** to money pages (Cabins, Stay, Book Now) and **up** to pillars. Add a clear primary CTA ("Check availability" → `book-sa.html`) above the fold on commercial pages.

### 2.4 "How to get here from overseas" page
- Create it (see `03-…` Part B): airports HDS/KMIA, two routings, transfer times, GPS, self-drive R527 directions. Strong international + AI-overview asset. Verify distances first.

---

## Phase 3 — P2 (ongoing, off-platform)
These are **not** code changes — they're the local + content + PR program:
1. **Google Business Profile** — full optimization per `03-…` Part A1 (category, pin, photos, Q&A, reviews). This is the fastest *local* win; do it in week 1 even though it's not a code change.
2. **Citations** — claim/fix the listings in `03-…` Part A3 with identical NAP.
3. **Content** — publish the 12 posts in `02-…` on the cadence you can sustain; start with #1, #2, #5 (viral magnets) and #4, #6 (commercial).
4. **Digital PR** — build the press/media kit and pitch the road-journey story (`02-…` Digital PR section).

---

## Verification checklist (run after Phase 1–2)
- [ ] Rich Results Test passes for Hotel, FAQ, Breadcrumb on representative pages.
- [ ] Every key page has a unique, ≤60-char title and ≤155-char meta description.
- [ ] One H1 per page; canonical present; OG/Twitter tags present.
- [ ] Sitemap complete and submitted; no key page `noindex` by accident.
- [ ] All hero images have alt text + are optimized; LCP reasonable on mobile.
- [ ] No fabricated review/rating data anywhere.
- [ ] All `FACTS-TO-VERIFY.md` items confirmed and consistent across page copy + schema.
