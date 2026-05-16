-- Filename:    sql/schema.sql
-- Description: MySQL schema for Safari Media OS Phase 1 (ingest pipeline).
--              Deploys into cPanel database `safariwe_media_os`. Rights model
--              simplified to "just attribute" per 2026-05-15 decision:
--              single `photographer` field, no licence enum, no expiry,
--              no release workflow. Footer credit only.
-- Project:     media (Safari Media OS, Build #123)
-- Version:     1.0
-- Created:     2026-05-16 12:29 SAST
-- Modified:    2026-05-16 12:29 SAST
-- Changes:     v1.0 initial — schema verbatim from CLAUDE-CODE-BRIEF.md v1.0
--              with charset/collation pinned and FOREIGN KEYs explicit.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- assets: one row per ingested original. drive_file_id is the natural key
-- against Google Drive; id is the surrogate used by every other table.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS assets (
  id                    BIGINT PRIMARY KEY AUTO_INCREMENT,
  client_slug           VARCHAR(64)  NOT NULL,
  source_path           VARCHAR(512) NOT NULL,
  drive_file_id         VARCHAR(128) NOT NULL,
  filename              VARCHAR(256),
  mime_type             VARCHAR(64),
  bytes                 BIGINT,
  width                 INT,
  height                INT,
  exif_captured_at      DATETIME,
  captured_season       ENUM('wet','dry','shoulder'),
  ingested_at           DATETIME DEFAULT CURRENT_TIMESTAMP,
  subjects              JSON,
  named_subjects        JSON,
  tone                  VARCHAR(64),
  technical_quality     DECIMAL(3,1),
  composition_quality   DECIMAL(3,1),
  has_unreleased_people BOOLEAN DEFAULT FALSE,
  photographer          VARCHAR(128),
  status                ENUM('ingested','approved','rejected','published','archived') DEFAULT 'ingested',
  UNIQUE KEY uq_drive_file (drive_file_id),
  KEY idx_client (client_slug),
  KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- clip_embeddings: 512-dim float32 vector packed as BLOB (2048 bytes).
-- One-to-one with assets. Cosine search runs in Python (db.py) on a small
-- candidate set after a coarse SQL filter on client_slug + status.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS clip_embeddings (
  asset_id  BIGINT PRIMARY KEY,
  embedding BLOB NOT NULL,
  CONSTRAINT fk_clip_asset FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- slot_briefs: developer-authored briefs that drive candidate selection.
-- brief JSON mirrors the SAMPLE-SLOT-BRIEFS.md structure.
-- clip_query is the text encoded by CLIP for retrieval.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS slot_briefs (
  id          BIGINT PRIMARY KEY AUTO_INCREMENT,
  client_slug VARCHAR(64) NOT NULL,
  slot_name   VARCHAR(128) NOT NULL,
  brief       JSON NOT NULL,
  clip_query  VARCHAR(1024),
  created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
  KEY idx_client_slot (client_slug, slot_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- ingest_runs: one row per hourly poll. Counts feed the admin panel
-- "last run" badge and alerting in Phase 2.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ingest_runs (
  id           BIGINT PRIMARY KEY AUTO_INCREMENT,
  started_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
  finished_at  DATETIME NULL,
  client_slug  VARCHAR(64),
  new_files    INT DEFAULT 0,
  skipped      INT DEFAULT 0,
  errors       INT DEFAULT 0,
  notes        TEXT,
  KEY idx_client_started (client_slug, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
