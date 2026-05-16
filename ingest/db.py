# Filename:    ingest/db.py
# Description: MySQL access layer for the Safari Media OS ingest worker.
#              Wraps mysql-connector-python. Handles asset upsert,
#              embedding storage as packed float32 BLOB, and ingest_runs
#              bookkeeping. Cosine-similarity ranking lives here too —
#              the embedding table is small enough (5k-15k STSC images)
#              for in-Python search; pgvector or FAISS can replace this
#              in Phase 2+ without touching the worker.
# Project:     media (Safari Media OS, Build #123)
# Version:     1.0
# Created:     2026-05-16 12:29 SAST
# Modified:    2026-05-16 12:29 SAST
# Changes:     v1.0 initial — Phase 1 scaffold.

from __future__ import annotations

import json
import struct
from contextlib import contextmanager
from dataclasses import dataclass
from datetime import datetime
from typing import Any, Iterator

import mysql.connector

from .config import Config


# 512-dim float32 = 2048 bytes. Pinned so a schema drift trips loudly.
EMBEDDING_DIM = 512
EMBEDDING_BYTES = EMBEDDING_DIM * 4


@dataclass
class AssetRow:
    id: int
    drive_file_id: str
    filename: str | None
    status: str


def pack_embedding(values: list[float]) -> bytes:
    if len(values) != EMBEDDING_DIM:
        raise ValueError(f"Expected {EMBEDDING_DIM}-dim embedding, got {len(values)}")
    return struct.pack(f"{EMBEDDING_DIM}f", *values)


def unpack_embedding(blob: bytes) -> list[float]:
    if len(blob) != EMBEDDING_BYTES:
        raise ValueError(f"Expected {EMBEDDING_BYTES} bytes, got {len(blob)}")
    return list(struct.unpack(f"{EMBEDDING_DIM}f", blob))


class Db:
    def __init__(self, cfg: Config) -> None:
        self._cfg = cfg
        self._conn = mysql.connector.connect(
            host=cfg.mysql_host,
            port=cfg.mysql_port,
            user=cfg.mysql_user,
            password=cfg.mysql_password,
            database=cfg.mysql_database,
            autocommit=False,
            charset="utf8mb4",
            collation="utf8mb4_unicode_ci",
        )

    def close(self) -> None:
        try:
            self._conn.close()
        except Exception:
            pass

    @contextmanager
    def _cursor(self) -> Iterator[Any]:
        cur = self._conn.cursor(dictionary=True)
        try:
            yield cur
        finally:
            cur.close()

    # -- assets ---------------------------------------------------------------

    def get_existing_drive_ids(self, client_slug: str) -> set[str]:
        """Return every drive_file_id already in assets for this client."""
        with self._cursor() as cur:
            cur.execute(
                "SELECT drive_file_id FROM assets WHERE client_slug = %s",
                (client_slug,),
            )
            return {row["drive_file_id"] for row in cur.fetchall()}

    def insert_asset(
        self,
        *,
        client_slug: str,
        drive_file_id: str,
        source_path: str,
        filename: str | None,
        mime_type: str | None,
        bytes_: int | None,
        width: int | None,
        height: int | None,
        exif_captured_at: datetime | None,
        captured_season: str | None,
        subjects: list[str] | None,
        named_subjects: list[str] | None,
        tone: str | None,
        technical_quality: float | None,
        composition_quality: float | None,
        has_unreleased_people: bool,
        photographer: str | None,
    ) -> int:
        sql = """
            INSERT INTO assets
              (client_slug, source_path, drive_file_id, filename, mime_type, bytes,
               width, height, exif_captured_at, captured_season,
               subjects, named_subjects, tone,
               technical_quality, composition_quality,
               has_unreleased_people, photographer)
            VALUES
              (%s, %s, %s, %s, %s, %s,
               %s, %s, %s, %s,
               %s, %s, %s,
               %s, %s,
               %s, %s)
        """
        params = (
            client_slug, source_path, drive_file_id, filename, mime_type, bytes_,
            width, height, exif_captured_at, captured_season,
            json.dumps(subjects) if subjects is not None else None,
            json.dumps(named_subjects) if named_subjects is not None else None,
            tone,
            technical_quality, composition_quality,
            has_unreleased_people, photographer,
        )
        with self._cursor() as cur:
            cur.execute(sql, params)
            asset_id = cur.lastrowid
        self._conn.commit()
        return int(asset_id)

    def store_embedding(self, asset_id: int, embedding: list[float]) -> None:
        blob = pack_embedding(embedding)
        with self._cursor() as cur:
            cur.execute(
                "INSERT INTO clip_embeddings (asset_id, embedding) VALUES (%s, %s) "
                "ON DUPLICATE KEY UPDATE embedding = VALUES(embedding)",
                (asset_id, blob),
            )
        self._conn.commit()

    # -- ingest_runs ----------------------------------------------------------

    def start_run(self, client_slug: str) -> int:
        with self._cursor() as cur:
            cur.execute(
                "INSERT INTO ingest_runs (client_slug) VALUES (%s)",
                (client_slug,),
            )
            run_id = cur.lastrowid
        self._conn.commit()
        return int(run_id)

    def finish_run(
        self,
        run_id: int,
        *,
        new_files: int,
        skipped: int,
        errors: int,
        notes: str = "",
    ) -> None:
        with self._cursor() as cur:
            cur.execute(
                "UPDATE ingest_runs "
                "SET finished_at = CURRENT_TIMESTAMP, "
                "    new_files = %s, skipped = %s, errors = %s, notes = %s "
                "WHERE id = %s",
                (new_files, skipped, errors, notes, run_id),
            )
        self._conn.commit()
