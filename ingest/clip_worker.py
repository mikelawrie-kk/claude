# Filename:    ingest/clip_worker.py
# Description: CLIP embedding worker for the Safari Media OS ingest pipeline.
#              Uses open_clip ViT-B/32 (openai weights) — 512-dim output to
#              match the 2048-byte BLOB slot in clip_embeddings. Loads the
#              model once per process; encode_image and encode_text share
#              the same projection space (cosine-similarity comparable).
#              CPU-first; set MEDIAOS_CLIP_DEVICE=cuda on a GPU VPS.
# Project:     media (Safari Media OS, Build #123)
# Version:     1.0
# Created:     2026-05-16 12:29 SAST
# Modified:    2026-05-16 12:29 SAST
# Changes:     v1.0 initial — Phase 1 scaffold.

from __future__ import annotations

import logging
from pathlib import Path
from threading import Lock
from typing import Iterable

import open_clip
import torch
from PIL import Image

from .config import Config


log = logging.getLogger(__name__)


class ClipWorker:
    """Single-process CLIP encoder. Thread-safe for concurrent encodes."""

    def __init__(self, cfg: Config) -> None:
        self._cfg = cfg
        self._lock = Lock()
        device = torch.device(cfg.clip_device)
        log.info("Loading CLIP model %s (%s) on %s", cfg.clip_model, cfg.clip_pretrained, device)
        self._model, _, self._preprocess = open_clip.create_model_and_transforms(
            cfg.clip_model, pretrained=cfg.clip_pretrained, device=device
        )
        self._tokenizer = open_clip.get_tokenizer(cfg.clip_model)
        self._model.eval()
        self._device = device

    def encode_image(self, image_path: Path) -> list[float]:
        img = Image.open(image_path).convert("RGB")
        tensor = self._preprocess(img).unsqueeze(0).to(self._device)
        with self._lock, torch.no_grad():
            features = self._model.encode_image(tensor)
            features = features / features.norm(dim=-1, keepdim=True)
        return features.squeeze(0).cpu().tolist()

    def encode_text(self, texts: Iterable[str]) -> list[list[float]]:
        tokens = self._tokenizer(list(texts)).to(self._device)
        with self._lock, torch.no_grad():
            features = self._model.encode_text(tokens)
            features = features / features.norm(dim=-1, keepdim=True)
        return features.cpu().tolist()


def self_test(image_path: Path) -> None:
    """Smoke test: print embedding dimension and first three values for one image."""
    import os

    os.environ.setdefault("MEDIAOS_CLIENT_SLUG", "selftest")
    os.environ.setdefault("MEDIAOS_DRIVE_FOLDER_ID", "selftest")
    os.environ.setdefault("ANTHROPIC_API_KEY", "selftest")
    os.environ.setdefault("MEDIAOS_MYSQL_HOST", "localhost")
    os.environ.setdefault("MEDIAOS_MYSQL_USER", "selftest")
    os.environ.setdefault("MEDIAOS_MYSQL_PASSWORD", "selftest")
    from .config import load

    worker = ClipWorker(load())
    vec = worker.encode_image(image_path)
    print(f"dim={len(vec)} first3={vec[:3]}")


if __name__ == "__main__":
    import sys
    if len(sys.argv) != 2:
        print("usage: python -m ingest.clip_worker <image-path>")
        raise SystemExit(2)
    self_test(Path(sys.argv[1]))
