# Filename:    ingest/drive.py
# Description: Google Drive client for the Safari Media OS ingest worker.
#              Service-account auth. Recursive folder walk yielding image
#              metadata; lazy download to a local cache directory. The
#              client's Drive is never written to — read + share state only.
# Project:     media (Safari Media OS, Build #123)
# Version:     1.0
# Created:     2026-05-16 12:29 SAST
# Modified:    2026-05-16 12:29 SAST
# Changes:     v1.0 initial — Phase 1 scaffold.

from __future__ import annotations

import io
import logging
from dataclasses import dataclass
from pathlib import Path
from typing import Iterator

from google.oauth2 import service_account
from googleapiclient.discovery import build
from googleapiclient.http import MediaIoBaseDownload


log = logging.getLogger(__name__)


SCOPES = ["https://www.googleapis.com/auth/drive.readonly"]

IMAGE_MIME_PREFIXES = ("image/",)
# Drive sometimes returns generic application/octet-stream for raw camera files.
# We treat anything in this set as ingestable regardless of MIME.
RAW_EXTENSIONS = {".cr2", ".cr3", ".nef", ".arw", ".raf", ".dng", ".orf", ".rw2"}


@dataclass
class DriveFile:
    id: str
    name: str
    mime_type: str
    size: int | None
    parents: list[str]
    path: str  # human-readable "<root>/sub/sub/file.jpg" assembled by walk()
    modified_time: str  # RFC3339


class DriveClient:
    def __init__(self, service_account_json: Path) -> None:
        creds = service_account.Credentials.from_service_account_file(
            str(service_account_json), scopes=SCOPES
        )
        self._svc = build("drive", "v3", credentials=creds, cache_discovery=False)

    def _is_image(self, name: str, mime: str) -> bool:
        if any(mime.startswith(p) for p in IMAGE_MIME_PREFIXES):
            return True
        suffix = Path(name).suffix.lower()
        return suffix in RAW_EXTENSIONS

    def list_folder(self, folder_id: str) -> Iterator[dict]:
        """Yield raw file dicts (id, name, mimeType, size, parents, modifiedTime) for a folder."""
        page_token: str | None = None
        while True:
            resp = self._svc.files().list(
                q=f"'{folder_id}' in parents and trashed = false",
                fields="nextPageToken, files(id, name, mimeType, size, parents, modifiedTime)",
                pageSize=1000,
                supportsAllDrives=True,
                includeItemsFromAllDrives=True,
                pageToken=page_token,
            ).execute()
            for f in resp.get("files", []):
                yield f
            page_token = resp.get("nextPageToken")
            if not page_token:
                return

    def walk(self, root_folder_id: str) -> Iterator[DriveFile]:
        """Recursively yield image DriveFiles below the root folder."""
        stack: list[tuple[str, str]] = [(root_folder_id, "")]
        while stack:
            folder_id, prefix = stack.pop()
            for f in self.list_folder(folder_id):
                name = f.get("name", "")
                mime = f.get("mimeType", "")
                path = f"{prefix}/{name}" if prefix else name
                if mime == "application/vnd.google-apps.folder":
                    stack.append((f["id"], path))
                    continue
                if not self._is_image(name, mime):
                    continue
                yield DriveFile(
                    id=f["id"],
                    name=name,
                    mime_type=mime,
                    size=int(f["size"]) if f.get("size") else None,
                    parents=f.get("parents", []),
                    path=path,
                    modified_time=f.get("modifiedTime", ""),
                )

    def list_sample(self, folder_id: str, limit: int = 10) -> list[dict]:
        """Smoke-test helper: return the first N children of a folder, files or subfolders."""
        items: list[dict] = []
        for f in self.list_folder(folder_id):
            items.append(f)
            if len(items) >= limit:
                break
        return items

    def download(self, file_id: str, dest: Path) -> Path:
        dest.parent.mkdir(parents=True, exist_ok=True)
        request = self._svc.files().get_media(fileId=file_id, supportsAllDrives=True)
        buf = io.BytesIO()
        downloader = MediaIoBaseDownload(buf, request, chunksize=1024 * 1024)
        done = False
        while not done:
            _status, done = downloader.next_chunk()
        dest.write_bytes(buf.getvalue())
        return dest
