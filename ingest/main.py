# Filename:    ingest/main.py
# Description: One-shot ingest run for Safari Media OS Phase 1. Walks the
#              configured Drive folder, downloads new images to a local
#              cache, CLIP-embeds, Vision-tags, writes assets + embeddings
#              to MySQL, logs the run. Designed to be triggered hourly by
#              systemd timer (see install.sh) — not a long-running daemon.
# Project:     media (Safari Media OS, Build #123)
# Version:     1.0
# Created:     2026-05-16 12:29 SAST
# Modified:    2026-05-16 12:29 SAST
# Changes:     v1.0 initial — Phase 1 scaffold.

from __future__ import annotations

import argparse
import logging
import sys
from datetime import datetime
from pathlib import Path

import exifread
from PIL import Image

from .clip_worker import ClipWorker
from .config import load
from .db import Db
from .drive import DriveClient, DriveFile
from .vision_worker import VisionWorker


log = logging.getLogger("media-os.ingest")


def _read_exif_captured_at(path: Path) -> datetime | None:
    try:
        with path.open("rb") as fh:
            tags = exifread.process_file(fh, details=False, stop_tag="EXIF DateTimeOriginal")
        raw = tags.get("EXIF DateTimeOriginal") or tags.get("Image DateTime")
        if not raw:
            return None
        return datetime.strptime(str(raw), "%Y:%m:%d %H:%M:%S")
    except Exception as e:
        log.debug("exif read failed for %s: %s", path, e)
        return None


def _season_for(dt: datetime | None) -> str | None:
    """Southern-African seasons relative to Olifants West / Greater Kruger."""
    if dt is None:
        return None
    m = dt.month
    if m in (11, 12, 1, 2, 3):
        return "wet"
    if m in (5, 6, 7, 8, 9):
        return "dry"
    return "shoulder"


def _image_dims(path: Path) -> tuple[int | None, int | None]:
    try:
        with Image.open(path) as img:
            return img.width, img.height
    except Exception:
        return None, None


def process_one(
    df: DriveFile,
    *,
    cfg,
    drive: DriveClient,
    clip: ClipWorker,
    vision: VisionWorker,
    db: Db,
) -> None:
    cache_path = cfg.cache_dir / cfg.client_slug / df.id / df.name
    drive.download(df.id, cache_path)

    width, height = _image_dims(cache_path)
    captured_at = _read_exif_captured_at(cache_path)
    season = _season_for(captured_at)

    tags = vision.tag(cache_path, df.mime_type or "image/jpeg")
    embedding = clip.encode_image(cache_path)

    asset_id = db.insert_asset(
        client_slug=cfg.client_slug,
        drive_file_id=df.id,
        source_path=df.path,
        filename=df.name,
        mime_type=df.mime_type,
        bytes_=df.size,
        width=width,
        height=height,
        exif_captured_at=captured_at,
        captured_season=season,
        subjects=tags.subjects,
        named_subjects=tags.named_subjects,
        tone=tags.tone,
        technical_quality=tags.technical_quality,
        composition_quality=tags.composition_quality,
        has_unreleased_people=tags.has_unreleased_people,
        photographer=None,  # folder-level attribution, applied in Phase 2 admin
    )
    db.store_embedding(asset_id, embedding)


def run_once(limit: int | None = None) -> int:
    cfg = load()
    cfg.cache_dir.mkdir(parents=True, exist_ok=True)
    drive = DriveClient(cfg.drive_sa_path)
    db = Db(cfg)
    run_id = db.start_run(cfg.client_slug)

    new_files = 0
    skipped = 0
    errors = 0
    note_lines: list[str] = []

    try:
        existing = db.get_existing_drive_ids(cfg.client_slug)
        # Defer heavy model load until we know there's work.
        candidates: list[DriveFile] = []
        for df in drive.walk(cfg.drive_folder_id):
            if df.id in existing:
                skipped += 1
                continue
            candidates.append(df)
            cap = limit if limit is not None else cfg.batch_limit
            if cap and len(candidates) >= cap:
                break

        if not candidates:
            log.info("no new files (skipped=%d)", skipped)
            db.finish_run(run_id, new_files=0, skipped=skipped, errors=0,
                          notes="no new files")
            return 0

        clip = ClipWorker(cfg)
        vision = VisionWorker(cfg)
        for df in candidates:
            try:
                process_one(df, cfg=cfg, drive=drive, clip=clip, vision=vision, db=db)
                new_files += 1
            except Exception as e:
                errors += 1
                msg = f"{df.path} ({df.id}): {e}"
                log.exception("process_one failed: %s", msg)
                note_lines.append(msg)

        db.finish_run(
            run_id,
            new_files=new_files,
            skipped=skipped,
            errors=errors,
            notes="\n".join(note_lines)[:65535],
        )
        log.info("done new=%d skipped=%d errors=%d", new_files, skipped, errors)
        return 0 if errors == 0 else 1
    finally:
        db.close()


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Safari Media OS ingest run")
    parser.add_argument("--limit", type=int, default=None,
                        help="cap files processed this run (overrides MEDIAOS_BATCH_LIMIT)")
    parser.add_argument("--verbose", "-v", action="store_true")
    args = parser.parse_args(argv)

    logging.basicConfig(
        level=logging.DEBUG if args.verbose else logging.INFO,
        format="%(asctime)s %(name)s %(levelname)s %(message)s",
    )
    return run_once(limit=args.limit)


if __name__ == "__main__":
    sys.exit(main())
