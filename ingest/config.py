# Filename:    ingest/config.py
# Description: Runtime configuration for the Safari Media OS ingest worker.
#              Loads from /etc/media-os/anthropic.env (Anthropic key) and
#              environment variables for everything else. No secrets in code.
# Project:     media (Safari Media OS, Build #123)
# Version:     1.0
# Created:     2026-05-16 12:29 SAST
# Modified:    2026-05-16 12:29 SAST
# Changes:     v1.0 initial — Phase 1 scaffold.

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path


ANTHROPIC_ENV_FILE = Path("/etc/media-os/anthropic.env")
DRIVE_SA_FILE = Path("/etc/media-os/drive-sa.json")


def _load_env_file(path: Path) -> None:
    """Load KEY=VALUE lines from a file into os.environ. Missing file is a no-op."""
    if not path.exists():
        return
    for line in path.read_text(encoding="utf-8").splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        key = key.strip()
        value = value.strip().strip('"').strip("'")
        if key and key not in os.environ:
            os.environ[key] = value


_load_env_file(ANTHROPIC_ENV_FILE)


@dataclass(frozen=True)
class Config:
    # Client identity ---------------------------------------------------------
    client_slug: str
    drive_folder_id: str

    # Google Drive ------------------------------------------------------------
    drive_sa_path: Path

    # Anthropic ---------------------------------------------------------------
    anthropic_api_key: str
    vision_model: str

    # MySQL on cPanel ---------------------------------------------------------
    mysql_host: str
    mysql_port: int
    mysql_user: str
    mysql_password: str
    mysql_database: str

    # Local cache for downloaded originals during processing ------------------
    cache_dir: Path

    # CLIP --------------------------------------------------------------------
    clip_model: str
    clip_pretrained: str
    clip_device: str

    # Run behaviour -----------------------------------------------------------
    batch_limit: int       # max new files processed per run, 0 = unlimited
    vision_max_retries: int


def _require(name: str) -> str:
    value = os.environ.get(name)
    if not value:
        raise RuntimeError(f"Missing required environment variable: {name}")
    return value


def load() -> Config:
    return Config(
        client_slug=_require("MEDIAOS_CLIENT_SLUG"),
        drive_folder_id=_require("MEDIAOS_DRIVE_FOLDER_ID"),
        drive_sa_path=Path(os.environ.get("MEDIAOS_DRIVE_SA_PATH", str(DRIVE_SA_FILE))),
        anthropic_api_key=_require("ANTHROPIC_API_KEY"),
        vision_model=os.environ.get("MEDIAOS_VISION_MODEL", "claude-haiku-4-5-20251001"),
        mysql_host=_require("MEDIAOS_MYSQL_HOST"),
        mysql_port=int(os.environ.get("MEDIAOS_MYSQL_PORT", "3306")),
        mysql_user=_require("MEDIAOS_MYSQL_USER"),
        mysql_password=_require("MEDIAOS_MYSQL_PASSWORD"),
        mysql_database=os.environ.get("MEDIAOS_MYSQL_DATABASE", "safariwe_media_os"),
        cache_dir=Path(os.environ.get("MEDIAOS_CACHE_DIR", "/var/lib/media-os/cache")),
        clip_model=os.environ.get("MEDIAOS_CLIP_MODEL", "ViT-B-32"),
        clip_pretrained=os.environ.get("MEDIAOS_CLIP_PRETRAINED", "openai"),
        clip_device=os.environ.get("MEDIAOS_CLIP_DEVICE", "cpu"),
        batch_limit=int(os.environ.get("MEDIAOS_BATCH_LIMIT", "200")),
        vision_max_retries=int(os.environ.get("MEDIAOS_VISION_MAX_RETRIES", "4")),
    )
