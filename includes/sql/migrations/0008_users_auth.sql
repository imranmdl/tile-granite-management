-- 0008_users_auth.sql (SQLite)
-- Users table with roles and active flag
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT UNIQUE NOT NULL,
  password_hash TEXT NOT NULL,
  role TEXT NOT NULL CHECK(role IN ('admin','manager','sales')),
  active INTEGER NOT NULL DEFAULT 1,
  created_at TEXT DEFAULT CURRENT_TIMESTAMP
);
-- Seed default admin if not present
INSERT OR IGNORE INTO users(username, password_hash, role, active)
SELECT 'admin', '$2y$10$0y9cdbHqk1GtjM2y5eZqKuJ7W0N2hS3h4yKjU6Wlq3jZbXk9uY75C', 'admin', 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username='admin');

-- Ensure invoices has salesperson_user_id (nullable) for FK to users
ALTER TABLE invoices ADD COLUMN salesperson_user_id INTEGER NULL;
