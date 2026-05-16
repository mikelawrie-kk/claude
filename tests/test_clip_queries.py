# Filename:    tests/test_clip_queries.py
# Description: Phase 1.5 CLIP validation gate runner. Reads the 5 sample
#              briefs from SAMPLE-SLOT-BRIEFS.md (encoded inline below so
#              the script is self-contained on the VPS), executes each
#              clip_query against the embedded STSC pool in MySQL, and
#              writes a contact-sheet HTML report to disk for Mike's
#              eyeball judgement. Pass rule: each top-5 must contain at
#              least one obviously-correct match. 3+/5 failures = gate
#              fails — escalate before starting Phase 2.
# Project:     media (Safari Media OS, Build #123)
# Version:     1.0
# Created:     2026-05-16 12:29 SAST
# Modified:    2026-05-16 12:29 SAST
# Changes:     v1.0 initial.

from __future__ import annotations

import argparse
import html
import math
import sys
from dataclasses import dataclass
from pathlib import Path

from ingest.clip_worker import ClipWorker
from ingest.config import load
from ingest.db import Db, unpack_embedding


@dataclass
class Brief:
    slot_name: str
    category: str
    clip_query: str
    expected: str


BRIEFS: list[Brief] = [
    Brief(
        slot_name="accommodation_hero",
        category="hero",
        clip_query=(
            "luxury safari tent exterior at dusk with warm lamp lighting on the deck, "
            "golden hour, atmospheric, intimate not corporate"
        ),
        expected="STSC has multiple tent-at-dusk shots; CLIP must find at least one.",
    ),
    Brief(
        slot_name="accommodation_gallery_detail",
        category="detail",
        clip_query=(
            "close-up detail of safari tent interior, soft natural light, textured fabrics, "
            "lantern or bedside table, character-led not architectural"
        ),
        expected="Must distinguish detail crops from full-room wides.",
    ),
    Brief(
        slot_name="blog_meet_ezulwini_hero",
        category="wildlife",
        clip_query=(
            "large bull elephant drinking at a river at sunset, golden light reflecting "
            "on water, atmospheric wildlife photography"
        ),
        expected="Pass = top-5 includes >=1 large solo bull at the Olifants.",
    ),
    Brief(
        slot_name="accommodation_lifestyle",
        category="accommodation",
        clip_query=(
            "guests on a wooden deck at a luxury safari camp during sundowner, golden hour "
            "silhouettes, drinks in hand, intimate atmospheric scene"
        ),
        expected="Pass = top-5 includes >=1 silhouetted / back-turned guest shot.",
    ),
    Brief(
        slot_name="dining_pitso_kitchen",
        category="dining",
        clip_query=(
            "chef plating a course in a warm safari camp kitchen, character portrait "
            "with food, soft interior lighting, narrative not editorial"
        ),
        expected="Pass = top-5 includes >=1 STSC chef in a kitchen scene.",
    ),
]


def _cosine(a: list[float], b: list[float]) -> float:
    dot = sum(x * y for x, y in zip(a, b))
    # Embeddings are already L2-normalised in clip_worker, but compute defensively.
    na = math.sqrt(sum(x * x for x in a)) or 1.0
    nb = math.sqrt(sum(y * y for y in b)) or 1.0
    return dot / (na * nb)


def top_k_for_query(db: Db, query_vec: list[float], client_slug: str, k: int = 5) -> list[tuple[float, dict]]:
    """Linear scan. Fine for 5k-15k STSC pool; swap for vector index in Phase 2+."""
    with db._cursor() as cur:  # noqa: SLF001 — intentional, this is a test tool
        cur.execute(
            """
            SELECT a.id, a.filename, a.source_path, a.drive_file_id,
                   a.subjects, a.named_subjects, a.tone,
                   e.embedding
            FROM assets a
            JOIN clip_embeddings e ON e.asset_id = a.id
            WHERE a.client_slug = %s
            """,
            (client_slug,),
        )
        rows = cur.fetchall()
    scored: list[tuple[float, dict]] = []
    for row in rows:
        vec = unpack_embedding(row["embedding"])
        score = _cosine(query_vec, vec)
        meta = {k_: v for k_, v in row.items() if k_ != "embedding"}
        scored.append((score, meta))
    scored.sort(key=lambda t: t[0], reverse=True)
    return scored[:k]


def render_report(results: list[tuple[Brief, list[tuple[float, dict]]]], out_path: Path) -> None:
    parts: list[str] = [
        "<!doctype html><meta charset='utf-8'>",
        "<title>Safari Media OS — Phase 1.5 CLIP gate</title>",
        "<style>body{font-family:system-ui;margin:24px}h2{margin-top:32px}"
        "table{border-collapse:collapse;width:100%}td,th{border:1px solid #ddd;padding:8px;text-align:left;vertical-align:top}"
        "tr:nth-child(even){background:#fafafa}</style>",
        "<h1>Phase 1.5 CLIP validation gate</h1>",
    ]
    for brief, top in results:
        parts.append(f"<h2>{html.escape(brief.slot_name)} ({html.escape(brief.category)})</h2>")
        parts.append(f"<p><strong>Query:</strong> {html.escape(brief.clip_query)}</p>")
        parts.append(f"<p><strong>Expected:</strong> {html.escape(brief.expected)}</p>")
        parts.append("<table><tr><th>#</th><th>score</th><th>filename</th><th>source_path</th><th>tone</th><th>subjects</th></tr>")
        for i, (score, meta) in enumerate(top, start=1):
            parts.append(
                "<tr>"
                f"<td>{i}</td>"
                f"<td>{score:.4f}</td>"
                f"<td>{html.escape(meta.get('filename') or '')}</td>"
                f"<td>{html.escape(meta.get('source_path') or '')}</td>"
                f"<td>{html.escape(meta.get('tone') or '')}</td>"
                f"<td>{html.escape(str(meta.get('subjects') or ''))}</td>"
                "</tr>"
            )
        parts.append("</table>")
    out_path.write_text("\n".join(parts), encoding="utf-8")


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Phase 1.5 CLIP validation gate")
    parser.add_argument("--out", type=Path, default=Path("clip_gate_report.html"))
    args = parser.parse_args(argv)

    cfg = load()
    clip = ClipWorker(cfg)
    db = Db(cfg)
    try:
        text_vecs = clip.encode_text([b.clip_query for b in BRIEFS])
        results: list[tuple[Brief, list[tuple[float, dict]]]] = []
        for brief, qv in zip(BRIEFS, text_vecs):
            top = top_k_for_query(db, qv, cfg.client_slug, k=5)
            print(f"{brief.slot_name}: {len(top)} candidates, top score {top[0][0]:.4f}" if top else f"{brief.slot_name}: NO RESULTS")
            results.append((brief, top))
    finally:
        db.close()

    render_report(results, args.out)
    print(f"Report written: {args.out.resolve()}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
