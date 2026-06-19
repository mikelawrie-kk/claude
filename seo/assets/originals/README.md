# assets/originals — drop your image masters here

Put the **original, full-resolution** image (and video) files here, named per `../IMAGE-MANIFEST.md`.

## Important
- **Don't commit large binaries to git casually.** A few hero JPEGs are fine; dozens of 10–40 MB masters will bloat the repo and slow every clone. If you have many/large files, keep them out of git (use the cPanel origin, Cloudflare R2, or a shared drive) and just keep this manifest + the deployed web copies authoritative. Git LFS is an option if you really want them versioned.
- The **web-optimised** versions (WebP/AVIF, sized) are what get deployed to the live site — not these masters.
- Keep one untouched master per asset so you can re-export at new sizes/formats later.

## What the agent can and can't reach
- **Aerotel Bridge session** → can read/write files on the cPanel origin (`/home/aerotelco/public_html/…`), so any image stored there it can see, rename, alt-tag, optimise, and wire into HTML/schema.
- **Cloudflare (Images / R2)** → no connector. The agent cannot browse or pull from your Cloudflare account. If images live there, paste the public URL pattern and it will reference them.
