<?php
class Database {
  private static $pdo = null;

  public static function pdo() {
    if (self::$pdo) return self::$pdo;

    $dbFile = __DIR__ . '/../data/app.sqlite';
    $dir = dirname($dbFile);
    if (!is_dir($dir)) { mkdir($dir, 0777, true); }

    $dsn = 'sqlite:' . $dbFile;
    self::$pdo = new PDO($dsn);
    self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    self::run_migrations(self::$pdo);
    return self::$pdo;
  }

  /* ---------- SQLite helpers ---------- */

  /** Return true if a table exists (SQLite). */
  private static function table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  }

  /** Return true if a column exists on a table (SQLite). */
  private static function column_exists(PDO $pdo, string $table, string $column): bool {
    if (!self::table_exists($pdo, $table)) return false;
    $st = $pdo->query("PRAGMA table_info($table)");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
      if (strcasecmp($row['name'], $column) === 0) return true;
    }
    return false;
  }

  /**
   * Remove/skip ALTER ADD COLUMN statements that would fail because the column already exists.
   * Handles simple forms like:  ALTER TABLE invoices ADD COLUMN sales_user TEXT;
   */
  private static function strip_existing_add_columns(PDO $pdo, string $sql): string {
    // We process each "ALTER TABLE ... ADD COLUMN ..." line individually.
    $pattern = '/^\s*ALTER\s+TABLE\s+([A-Za-z0-9_]+)\s+ADD\s+COLUMN\s+([A-Za-z0-9_]+)\b[^;]*;/im';
    return preg_replace_callback($pattern, function($m) use ($pdo) {
      $table = $m[1];
      $column = $m[2];
      if (self::column_exists($pdo, $table, $column)) {
        // Replace the statement with a comment so it won't execute again.
        return "-- skipped: $table.$column already exists;\n";
      }
      return $m[0]; // keep original statement
    }, $sql);
  }

  /* ---------- migrations ---------- */

  private static function run_migrations(PDO $pdo) {
    $pdo->exec("PRAGMA foreign_keys=ON");

    $dir = __DIR__ . '/sql/migrations';
    if (!is_dir($dir)) {
      // Nothing to run
      return;
    }

    $doneFileDir = __DIR__ . '/sql';
    if (!is_dir($doneFileDir)) { mkdir($doneFileDir, 0777, true); }

    $doneFile = $doneFileDir . '/.migrated';
    $done = [];
    if (file_exists($doneFile)) {
      $done = array_filter(array_map('trim', file($doneFile)));
    }

    // Get .sql files in deterministic order
    $files = array_filter(glob($dir.'/*.sql') ?: []);
    sort($files, SORT_NATURAL | SORT_FLAG_CASE);

    foreach ($files as $f) {
      $name = basename($f);
      if (in_array($name, $done, true)) continue;

      $sql = file_get_contents($f);

      // Skip MySQL-specific files
      if (preg_match('/\bENGINE=|\bAUTO_INCREMENT\b|\bENUM\(|\bSET\s+FOREIGN_KEY_CHECKS\b|\bCHARSET=|\bCOLLATE=/', $sql)) {
        // Consider them "done" so we don't check them on every boot
        $done[] = $name;
        continue;
      }

      // Make ALTER ADD COLUMN idempotent for SQLite
      $sql = self::strip_existing_add_columns($pdo, $sql);

      // If the file ends up empty after stripping, just mark done.
      if (trim($sql) === '') {
        $done[] = $name;
        continue;
      }

      // Execute inside a transaction
      $pdo->beginTransaction();
      try {
        $pdo->exec($sql);
        $pdo->commit();
        $done[] = $name;
      } catch (Throwable $e) {
        $pdo->rollBack();

        // If the error is a harmless "duplicate column" missed by the stripper, swallow and mark done.
        $msg = $e->getMessage();
        if (stripos($msg, 'duplicate column') !== false || stripos($msg, 'already exists') !== false) {
          $done[] = $name;
        } else {
          throw $e;
        }
      }
    }

    // Save the migrated list
    file_put_contents($doneFile, implode(PHP_EOL, $done) . PHP_EOL);
  }
}
