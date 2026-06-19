-- Growth Engine — SQLite schema (AS DEPLOYED)
-- This is the schema actually applied to the live hub, chosen over MySQL because the
-- only MySQL database on the server is the production PMS (safariweb_pms), which we do
-- not co-locate into. SQLite-per-project matches the environment's existing convention.
--
-- Deployed at: seo project → growth-engine/data/growth_engine.db
--   (/home/safariweb/public_html/seo.safariweb.online/growth-engine/data/growth_engine.db)
-- Bridge access: project=seo, engine=sqlite, database=growth-engine/data/growth_engine.db
-- The data/ dir is protected from web access by data/.htaccess (deny all).
-- Run PRAGMA foreign_keys=ON; per connection (SQLite default is OFF).
-- schema.sql (MySQL) is retained as the reference/portable definition.

CREATE TABLE IF NOT EXISTS property (
  id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT NOT NULL UNIQUE, name TEXT NOT NULL,
  domain TEXT NOT NULL, config_json TEXT NOT NULL, active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP);

CREATE TABLE IF NOT EXISTS ingest_run (
  id INTEGER PRIMARY KEY AUTOINCREMENT, property_id INTEGER NOT NULL,
  source TEXT NOT NULL CHECK(source IN ('gsc','ga4','clarity','bing_wmt')),
  method TEXT NOT NULL DEFAULT 'csv' CHECK(method IN ('csv','api')),
  period_start TEXT NOT NULL, period_end TEXT NOT NULL, rows_loaded INTEGER NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'ok' CHECK(status IN ('ok','partial','failed')),
  notes TEXT, ran_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX IF NOT EXISTS ix_ingest ON ingest_run(property_id, source, period_end);

CREATE TABLE IF NOT EXISTS gsc_query_daily (
  property_id INTEGER NOT NULL, date TEXT NOT NULL, query TEXT NOT NULL, page TEXT NOT NULL DEFAULT '',
  country TEXT NOT NULL DEFAULT 'zaf', device TEXT NOT NULL DEFAULT 'MOBILE',
  clicks INTEGER NOT NULL DEFAULT 0, impressions INTEGER NOT NULL DEFAULT 0,
  ctr REAL NOT NULL DEFAULT 0, position REAL NOT NULL DEFAULT 0,
  PRIMARY KEY (property_id, date, query, page, country, device));
CREATE INDEX IF NOT EXISTS ix_gsc_q ON gsc_query_daily(property_id, date, impressions);

CREATE TABLE IF NOT EXISTS gsc_page_daily (
  property_id INTEGER NOT NULL, date TEXT NOT NULL, page TEXT NOT NULL,
  clicks INTEGER NOT NULL DEFAULT 0, impressions INTEGER NOT NULL DEFAULT 0,
  ctr REAL NOT NULL DEFAULT 0, position REAL NOT NULL DEFAULT 0,
  PRIMARY KEY (property_id, date, page));

CREATE TABLE IF NOT EXISTS ga4_page_daily (
  property_id INTEGER NOT NULL, date TEXT NOT NULL, page_path TEXT NOT NULL,
  sessions INTEGER NOT NULL DEFAULT 0, engaged_sessions INTEGER NOT NULL DEFAULT 0,
  engagement_rate REAL NOT NULL DEFAULT 0, avg_engagement_time REAL NOT NULL DEFAULT 0,
  views INTEGER NOT NULL DEFAULT 0, conversions REAL NOT NULL DEFAULT 0,
  PRIMARY KEY (property_id, date, page_path));

CREATE TABLE IF NOT EXISTS ga4_channel_daily (
  property_id INTEGER NOT NULL, date TEXT NOT NULL, channel TEXT NOT NULL,
  country TEXT NOT NULL DEFAULT 'zaf', sessions INTEGER NOT NULL DEFAULT 0,
  conversions REAL NOT NULL DEFAULT 0, PRIMARY KEY (property_id, date, channel, country));

CREATE TABLE IF NOT EXISTS clarity_page_daily (
  property_id INTEGER NOT NULL, date TEXT NOT NULL, page TEXT NOT NULL,
  sessions INTEGER NOT NULL DEFAULT 0, rage_clicks INTEGER NOT NULL DEFAULT 0,
  dead_clicks INTEGER NOT NULL DEFAULT 0, excessive_scroll INTEGER NOT NULL DEFAULT 0,
  quickbacks INTEGER NOT NULL DEFAULT 0, avg_scroll_depth REAL NOT NULL DEFAULT 0,
  js_errors INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (property_id, date, page));

CREATE TABLE IF NOT EXISTS bing_query_daily (
  property_id INTEGER NOT NULL, date TEXT NOT NULL, query TEXT NOT NULL,
  clicks INTEGER NOT NULL DEFAULT 0, impressions INTEGER NOT NULL DEFAULT 0,
  ctr REAL NOT NULL DEFAULT 0, position REAL NOT NULL DEFAULT 0,
  PRIMARY KEY (property_id, date, query));

CREATE TABLE IF NOT EXISTS bing_crawl_daily (
  property_id INTEGER NOT NULL, date TEXT NOT NULL, crawled INTEGER NOT NULL DEFAULT 0,
  indexed INTEGER NOT NULL DEFAULT 0, crawl_errors INTEGER NOT NULL DEFAULT 0,
  blocked INTEGER NOT NULL DEFAULT 0, PRIMARY KEY (property_id, date));

CREATE TABLE IF NOT EXISTS recommendation (
  id INTEGER PRIMARY KEY AUTOINCREMENT, property_id INTEGER NOT NULL,
  type TEXT NOT NULL CHECK(type IN ('optimise_onpage','new_content','refresh','cro','ux_fix','channel','technical','seasonal')),
  target TEXT NOT NULL DEFAULT '', title TEXT NOT NULL, score REAL NOT NULL DEFAULT 0,
  rationale TEXT, evidence_json TEXT,
  status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','planned','done','dismissed')),
  created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX IF NOT EXISTS ix_reco ON recommendation(property_id, status, score);

CREATE TABLE IF NOT EXISTS content_theme (
  id INTEGER PRIMARY KEY AUTOINCREMENT, property_id INTEGER NOT NULL, period TEXT NOT NULL,
  title TEXT NOT NULL, rationale TEXT, clusters TEXT,
  status TEXT NOT NULL DEFAULT 'themed' CHECK(status IN ('themed','active','closed')),
  UNIQUE (property_id, period));

CREATE TABLE IF NOT EXISTS content_item (
  id INTEGER PRIMARY KEY AUTOINCREMENT, property_id INTEGER NOT NULL, theme_id INTEGER,
  recommendation_id INTEGER, type TEXT NOT NULL DEFAULT 'blog' CHECK(type IN ('blog','landing','refresh','pillar')),
  title TEXT NOT NULL, primary_keyword TEXT, target_url TEXT, brief_md TEXT, due_date TEXT NOT NULL,
  horizon_band TEXT NOT NULL DEFAULT 'themed' CHECK(horizon_band IN ('committed','shaped','themed')),
  status TEXT NOT NULL DEFAULT 'idea' CHECK(status IN ('idea','briefed','drafted','scheduled','published','measuring','done')),
  metrics_json TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
CREATE INDEX IF NOT EXISTS ix_item ON content_item(property_id, due_date, status);

CREATE TABLE IF NOT EXISTS social_post (
  id INTEGER PRIMARY KEY AUTOINCREMENT, property_id INTEGER NOT NULL, content_item_id INTEGER,
  channel TEXT NOT NULL, scheduled_date TEXT NOT NULL, hook TEXT, body TEXT, asset_ref TEXT,
  status TEXT NOT NULL DEFAULT 'idea' CHECK(status IN ('idea','drafted','scheduled','published')));
CREATE INDEX IF NOT EXISTS ix_social ON social_post(property_id, scheduled_date, channel);

CREATE TABLE IF NOT EXISTS weekly_report (
  id INTEGER PRIMARY KEY AUTOINCREMENT, property_id INTEGER NOT NULL, week_start TEXT NOT NULL,
  summary_md TEXT, kpis_json TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP,
  UNIQUE (property_id, week_start));
