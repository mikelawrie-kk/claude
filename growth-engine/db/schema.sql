-- Growth Engine — MySQL schema (property-agnostic, multi-property)
-- Apply to the SWO hub DB via the bridge `db_execute`. Idempotent-ish: uses CREATE TABLE IF NOT EXISTS.
-- Charset: utf8mb4. Every table is keyed by property_id so one hub serves all properties.

-- ---------- Registry ----------
CREATE TABLE IF NOT EXISTS property (
  id            INT AUTO_INCREMENT PRIMARY KEY,
  slug          VARCHAR(64) NOT NULL UNIQUE,
  name          VARCHAR(160) NOT NULL,
  domain        VARCHAR(190) NOT NULL,
  config_json   JSON NOT NULL,
  active        TINYINT(1) NOT NULL DEFAULT 1,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Ingestion provenance ----------
CREATE TABLE IF NOT EXISTS ingest_run (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  property_id   INT NOT NULL,
  source        ENUM('gsc','ga4','clarity','bing_wmt') NOT NULL,
  method        ENUM('csv','api') NOT NULL DEFAULT 'csv',
  period_start  DATE NOT NULL,
  period_end    DATE NOT NULL,
  rows_loaded   INT NOT NULL DEFAULT 0,
  status        ENUM('ok','partial','failed') NOT NULL DEFAULT 'ok',
  notes         TEXT,
  ran_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_ingest (property_id, source, period_end),
  FOREIGN KEY (property_id) REFERENCES property(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Raw metrics: Google Search Console ----------
CREATE TABLE IF NOT EXISTS gsc_query_daily (
  property_id INT NOT NULL,
  date        DATE NOT NULL,
  query       VARCHAR(512) NOT NULL,
  page        VARCHAR(512) NOT NULL DEFAULT '',
  country     CHAR(3) NOT NULL DEFAULT 'zaf',
  device      ENUM('DESKTOP','MOBILE','TABLET') NOT NULL DEFAULT 'MOBILE',
  clicks      INT NOT NULL DEFAULT 0,
  impressions INT NOT NULL DEFAULT 0,
  ctr         DECIMAL(6,4) NOT NULL DEFAULT 0,
  position    DECIMAL(6,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (property_id, date, query(190), page(190), country, device),
  INDEX ix_gsc_q (property_id, date, impressions)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gsc_page_daily (
  property_id INT NOT NULL,
  date        DATE NOT NULL,
  page        VARCHAR(512) NOT NULL,
  clicks      INT NOT NULL DEFAULT 0,
  impressions INT NOT NULL DEFAULT 0,
  ctr         DECIMAL(6,4) NOT NULL DEFAULT 0,
  position    DECIMAL(6,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (property_id, date, page(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Raw metrics: GA4 ----------
CREATE TABLE IF NOT EXISTS ga4_page_daily (
  property_id      INT NOT NULL,
  date             DATE NOT NULL,
  page_path        VARCHAR(512) NOT NULL,
  sessions         INT NOT NULL DEFAULT 0,
  engaged_sessions INT NOT NULL DEFAULT 0,
  engagement_rate  DECIMAL(6,4) NOT NULL DEFAULT 0,
  avg_engagement_time DECIMAL(8,2) NOT NULL DEFAULT 0,
  views            INT NOT NULL DEFAULT 0,
  conversions      DECIMAL(10,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (property_id, date, page_path(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS ga4_channel_daily (
  property_id INT NOT NULL,
  date        DATE NOT NULL,
  channel     VARCHAR(64) NOT NULL,
  country     CHAR(3) NOT NULL DEFAULT 'zaf',
  sessions    INT NOT NULL DEFAULT 0,
  conversions DECIMAL(10,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (property_id, date, channel, country)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Raw metrics: Microsoft Clarity ----------
CREATE TABLE IF NOT EXISTS clarity_page_daily (
  property_id      INT NOT NULL,
  date             DATE NOT NULL,
  page             VARCHAR(512) NOT NULL,
  sessions         INT NOT NULL DEFAULT 0,
  rage_clicks      INT NOT NULL DEFAULT 0,
  dead_clicks      INT NOT NULL DEFAULT 0,
  excessive_scroll INT NOT NULL DEFAULT 0,
  quickbacks       INT NOT NULL DEFAULT 0,
  avg_scroll_depth DECIMAL(5,2) NOT NULL DEFAULT 0,
  js_errors        INT NOT NULL DEFAULT 0,
  PRIMARY KEY (property_id, date, page(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Raw metrics: Bing Webmaster Tools ----------
CREATE TABLE IF NOT EXISTS bing_query_daily (
  property_id INT NOT NULL,
  date        DATE NOT NULL,
  query       VARCHAR(512) NOT NULL,
  clicks      INT NOT NULL DEFAULT 0,
  impressions INT NOT NULL DEFAULT 0,
  ctr         DECIMAL(6,4) NOT NULL DEFAULT 0,
  position    DECIMAL(6,2) NOT NULL DEFAULT 0,
  PRIMARY KEY (property_id, date, query(190))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS bing_crawl_daily (
  property_id   INT NOT NULL,
  date          DATE NOT NULL,
  crawled       INT NOT NULL DEFAULT 0,
  indexed       INT NOT NULL DEFAULT 0,
  crawl_errors  INT NOT NULL DEFAULT 0,
  blocked       INT NOT NULL DEFAULT 0,
  PRIMARY KEY (property_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Analysis output ----------
CREATE TABLE IF NOT EXISTS recommendation (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  property_id  INT NOT NULL,
  type         ENUM('optimise_onpage','new_content','refresh','cro','ux_fix','channel','technical','seasonal') NOT NULL,
  target       VARCHAR(512) NOT NULL DEFAULT '',     -- page path or query
  title        VARCHAR(255) NOT NULL,
  score        DECIMAL(6,2) NOT NULL DEFAULT 0,       -- Opportunity Score 0-100
  rationale    TEXT,
  evidence_json JSON,                                  -- the metrics that triggered it
  status       ENUM('open','planned','done','dismissed') NOT NULL DEFAULT 'open',
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_reco (property_id, status, score),
  FOREIGN KEY (property_id) REFERENCES property(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- The rolling plan ----------
CREATE TABLE IF NOT EXISTS content_theme (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  property_id  INT NOT NULL,
  period       CHAR(7) NOT NULL,                       -- 'YYYY-MM'
  title        VARCHAR(255) NOT NULL,
  rationale    TEXT,
  clusters     JSON,                                   -- which keyword_clusters it serves
  status       ENUM('themed','active','closed') NOT NULL DEFAULT 'themed',
  UNIQUE KEY uq_theme (property_id, period),
  FOREIGN KEY (property_id) REFERENCES property(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS content_item (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  property_id   INT NOT NULL,
  theme_id      BIGINT,
  recommendation_id BIGINT,
  type          ENUM('blog','landing','refresh','pillar') NOT NULL DEFAULT 'blog',
  title         VARCHAR(255) NOT NULL,
  primary_keyword VARCHAR(255),
  target_url    VARCHAR(512),
  brief_md      MEDIUMTEXT,
  due_date      DATE NOT NULL,
  horizon_band  ENUM('committed','shaped','themed') NOT NULL DEFAULT 'themed',
  status        ENUM('idea','briefed','drafted','scheduled','published','measuring','done') NOT NULL DEFAULT 'idea',
  metrics_json  JSON,                                   -- post-publish performance snapshot
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX ix_item (property_id, due_date, status),
  FOREIGN KEY (property_id) REFERENCES property(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS social_post (
  id              BIGINT AUTO_INCREMENT PRIMARY KEY,
  property_id     INT NOT NULL,
  content_item_id BIGINT,
  channel         VARCHAR(32) NOT NULL,
  scheduled_date  DATE NOT NULL,
  hook            VARCHAR(280),
  body            TEXT,
  asset_ref       VARCHAR(512),
  status          ENUM('idea','drafted','scheduled','published') NOT NULL DEFAULT 'idea',
  INDEX ix_social (property_id, scheduled_date, channel),
  FOREIGN KEY (property_id) REFERENCES property(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ---------- Weekly reporting ----------
CREATE TABLE IF NOT EXISTS weekly_report (
  id           BIGINT AUTO_INCREMENT PRIMARY KEY,
  property_id  INT NOT NULL,
  week_start   DATE NOT NULL,
  summary_md   MEDIUMTEXT,
  kpis_json    JSON,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_report (property_id, week_start),
  FOREIGN KEY (property_id) REFERENCES property(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
