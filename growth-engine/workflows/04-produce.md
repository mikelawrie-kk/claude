# Workflow 04 — Produce (briefs, drafts, social) in brand voice

**Goal:** turn committed-band plan items into ready-to-ship assets, faithful to the property's voice and facts.
**Scope each cycle:** everything entering the **committed** band that isn't yet `drafted`.

## Voice & facts (non-negotiable)
- Pull `brand.voice`, `brand.power_phrases`, `brand.forbidden_words` from config and enforce them. Reject any draft containing a forbidden word.
- Assert only facts from `kb_facts`; anything behind a `facts_gate` (e.g. Aerotel's `FACTS-TO-VERIFY.md`) must be cleared before it appears in published copy.
- **Never fabricate** stats, reviews, or ratings.

## For each `content_item`
1. **Brief** (`templates/content-brief.md`): primary + secondary keywords, search intent, target page, the recommendation/evidence behind it, internal links (down to money pages, up to pillar), suggested title + meta, word-count guide, the angle, and the CTA.
2. **Draft** by type:
   - `blog`/`pillar` → `templates/blog-post.md`, voice-checked, schema-aware, internally linked.
   - `landing` → conversion-focused; align with the matched query intent.
   - `refresh` → produce a **diff plan** (what to add/cut/restructure) against the live page, not a from-scratch rewrite.
   - on-page/CRO/UX/technical task → produce the precise change instruction (the kind the SEO runbook expects: `file_patch`-ready), not prose.
3. **Social** (`templates/social-post.md`): per channel, repurpose the angle natively (hook + body + asset ref + CTA). Don't copy the same caption across channels.
4. **Stage by autonomy** (`automation.publish`):
   - `draft` → save the asset, status `drafted`, stop.
   - `review` → schedule it (status `scheduled`) pending human approval.
   - `auto` → publish via the property's `deploy.bridge_ref` (read-before-write, back up, validate), status `published`.

## Output
Each processed item: a brief + draft (and social posts) attached, status advanced, asset paths recorded. Ready for measurement next cycle.
