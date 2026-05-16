# Filename:    ingest/vision_worker.py
# Description: Claude Vision worker for the Safari Media OS ingest pipeline.
#              Sends each image to Claude Haiku and asks for a strict JSON
#              tagging object: subjects, named_subjects, tone, technical
#              and composition quality scores, and an unreleased-people flag.
#              No rights/licence enum — rights model is "just attribute"
#              per the 2026-05-15 decision; photographer is captured at the
#              folder level by the operator, not inferred from pixels.
# Project:     media (Safari Media OS, Build #123)
# Version:     1.0
# Created:     2026-05-16 12:29 SAST
# Modified:    2026-05-16 12:29 SAST
# Changes:     v1.0 initial — Phase 1 scaffold.

from __future__ import annotations

import base64
import json
import logging
import time
from dataclasses import dataclass
from pathlib import Path

import anthropic

from .config import Config


log = logging.getLogger(__name__)


SYSTEM_PROMPT = """You are a media-tagging assistant for a safari-lodge website builder.
For each image, return ONE JSON object and nothing else. No prose, no code fences.
Fields:
  subjects: array of short concrete nouns (e.g. ["elephant","river","sunset"])
  named_subjects: array of named individuals visible if any (people OR named animals).
                  Empty array if none. Do NOT guess identities — names must be
                  visually unambiguous from context (signage, prior knowledge,
                  obvious features). Otherwise empty.
  tone: one of "warm","calm","wild","dramatic","clinical","editorial","documentary","candid"
  technical_quality: float 0.0-10.0 (focus, exposure, noise, sharpness)
  composition_quality: float 0.0-10.0 (framing, balance, story, light direction)
  has_unreleased_people: true if recognisable human faces are visible AND no obvious
                         signed-release context (silhouette, back-turned, distant = false)
Score conservatively. If unsure, return lower numbers, not higher.
"""

USER_PROMPT = "Tag this image. Return the JSON object only."


@dataclass
class VisionTags:
    subjects: list[str]
    named_subjects: list[str]
    tone: str
    technical_quality: float
    composition_quality: float
    has_unreleased_people: bool

    @classmethod
    def from_dict(cls, data: dict) -> "VisionTags":
        return cls(
            subjects=list(data.get("subjects") or []),
            named_subjects=list(data.get("named_subjects") or []),
            tone=str(data.get("tone") or ""),
            technical_quality=float(data.get("technical_quality") or 0.0),
            composition_quality=float(data.get("composition_quality") or 0.0),
            has_unreleased_people=bool(data.get("has_unreleased_people")),
        )


class VisionWorker:
    def __init__(self, cfg: Config) -> None:
        self._cfg = cfg
        self._client = anthropic.Anthropic(api_key=cfg.anthropic_api_key)

    def tag(self, image_path: Path, mime_type: str) -> VisionTags:
        b64 = base64.standard_b64encode(image_path.read_bytes()).decode("ascii")
        last_err: Exception | None = None
        for attempt in range(self._cfg.vision_max_retries):
            try:
                resp = self._client.messages.create(
                    model=self._cfg.vision_model,
                    max_tokens=512,
                    system=SYSTEM_PROMPT,
                    messages=[{
                        "role": "user",
                        "content": [
                            {"type": "image", "source": {
                                "type": "base64", "media_type": mime_type, "data": b64
                            }},
                            {"type": "text", "text": USER_PROMPT},
                        ],
                    }],
                )
                text = "".join(
                    block.text for block in resp.content if getattr(block, "type", "") == "text"
                ).strip()
                if text.startswith("```"):
                    text = text.strip("`").lstrip("json").strip()
                data = json.loads(text)
                return VisionTags.from_dict(data)
            except (anthropic.RateLimitError, anthropic.APIStatusError) as e:
                last_err = e
                backoff = 2 ** attempt
                log.warning("Vision retry %d after %ds: %s", attempt + 1, backoff, e)
                time.sleep(backoff)
            except json.JSONDecodeError as e:
                last_err = e
                log.warning("Vision returned non-JSON on attempt %d: %s", attempt + 1, e)
                time.sleep(1)
        raise RuntimeError(f"Vision tagging failed after retries: {last_err}")


def self_test(image_path: Path, mime_type: str) -> None:
    from .config import load

    worker = VisionWorker(load())
    tags = worker.tag(image_path, mime_type)
    print(json.dumps(tags.__dict__, indent=2))


if __name__ == "__main__":
    import sys
    if len(sys.argv) not in (2, 3):
        print("usage: python -m ingest.vision_worker <image-path> [mime-type]")
        raise SystemExit(2)
    path = Path(sys.argv[1])
    mime = sys.argv[2] if len(sys.argv) == 3 else "image/jpeg"
    self_test(path, mime)
