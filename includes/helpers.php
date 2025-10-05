<?php
/* =========================
   Generic helpers (safe)
   ========================= */

if (!function_exists('h')) {
  function h($s){ return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('n2')) {
  function n2($n){ return number_format((float)$n, 2, '.', ''); }
}
if (!function_exists('n3')) {
  function n3($n){ return number_format((float)$n, 3, '.', ''); }
}
if (!function_exists('active')) {
  function active($file, $path){ return (strpos($path, $file)!==false)?'active':''; }
}
if (!function_exists('post')) {
  function post($k,$d=null){ return $_POST[$k]??$d; }
}
if (!function_exists('get')) {
  function get($k,$d=null){ return $_GET[$k]??$d; }
}
if (!function_exists('today')) {
  function today(){ return date('Y-m-d'); }
}

/* =========================
   File upload helper
   ========================= */
if (!function_exists('upload_image')) {
  function upload_image($field){
    if (!isset($_FILES[$field]) || $_FILES[$field]['error']!==UPLOAD_ERR_OK) return null;
    $name = basename($_FILES[$field]['name']);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION) ?: 'bin');
    $safe = uniqid('img_', true).'.'.$ext;
    $dir = __DIR__.'/../uploads';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $dest = $dir.'/'.$safe;
    if (move_uploaded_file($_FILES[$field]['tmp_name'], $dest)) {
      return '/uploads/'.$safe;
    }
    return null;
  }
}

/* =========================
   Redirect helper
   ========================= */
if (!function_exists('safe_redirect')) {
  /**
   * Redirect safely without "headers already sent" warnings.
   * Usage: safe_redirect('misc_items.php');
   */
  function safe_redirect(string $url): void {
    if (!headers_sent()) {
      header("Location: $url");
      exit;
    }
    // Fallback if headers already sent
    echo "<script>location.replace(" . json_encode($url) . ");</script>";
    echo '<noscript><meta http-equiv="refresh" content="0;url=' .
          htmlspecialchars($url, ENT_QUOTES) . '"></noscript>';
    exit;
  }
}

/* =========================
   DB schema helpers (SQLite-safe)
   ========================= */

if (!function_exists('table_exists')) {
  function table_exists(PDO $pdo, string $table): bool {
    try {
      // SQLite: look in sqlite_master
      $st = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?");
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('column_exists')) {
  function column_exists(PDO $pdo, string $table, string $column): bool {
    try {
      // SQLite: PRAGMA table_info
      $safeTable = preg_replace('/[^A-Za-z0-9_]/','',$table);
      $st = $pdo->query("PRAGMA table_info($safeTable)");
      if (!$st) return false;
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $name = $c['name'] ?? $c['NAME'] ?? null;
        if ($name && strcasecmp($name, $column) === 0) return true;
      }
      return false;
    } catch (Throwable $e) {
      return false;
    }
  }
}
