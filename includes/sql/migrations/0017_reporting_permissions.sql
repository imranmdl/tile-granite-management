-- 0017_reporting_permissions.sql (SQLite)
-- Add reporting permissions to users

-- Add reporting permission fields to users table
ALTER TABLE users_simple ADD COLUMN can_view_pl INTEGER DEFAULT 0;
ALTER TABLE users_simple ADD COLUMN can_view_reports INTEGER DEFAULT 0;
ALTER TABLE users_simple ADD COLUMN can_export_data INTEGER DEFAULT 0;

-- Update admin users with full permissions
UPDATE users_simple SET can_view_pl = 1, can_view_reports = 1, can_export_data = 1 WHERE role = 'admin';

-- Create reporting preferences table
CREATE TABLE IF NOT EXISTS user_report_preferences (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    report_type TEXT NOT NULL,
    preferences TEXT NOT NULL, -- JSON string of preferences
    created_at TEXT DEFAULT (datetime('now')),
    updated_at TEXT DEFAULT (datetime('now')),
    FOREIGN KEY (user_id) REFERENCES users_simple(id),
    UNIQUE(user_id, report_type)
);

-- Create report cache table for performance
CREATE TABLE IF NOT EXISTS report_cache (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    cache_key TEXT NOT NULL UNIQUE,
    report_data TEXT NOT NULL, -- JSON string of cached data
    expires_at TEXT NOT NULL,
    created_at TEXT DEFAULT (datetime('now'))
);

CREATE INDEX IF NOT EXISTS idx_report_cache_key ON report_cache(cache_key);
CREATE INDEX IF NOT EXISTS idx_report_cache_expires ON report_cache(expires_at);