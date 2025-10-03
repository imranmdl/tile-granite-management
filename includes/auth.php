<?php
// includes/auth.php — DB-backed auth with roles (admin, manager, sales)
require_once __DIR__ . '/Database.php';
session_start();

function auth_user(): ?array { return $_SESSION['user'] ?? null; }
function auth_user_id(): ?int { return $_SESSION['user']['id'] ?? null; }
function auth_username(): string { return $_SESSION['user']['username'] ?? ''; }
function auth_role(): string { return $_SESSION['user']['role'] ?? 'sales'; }
function auth_is_admin(): bool { return in_array(auth_role(), ['admin','manager'], true); } // managers treated as elevated
function require_login($roles = null){
  if (!auth_user()) { header('Location: /public/login.php'); exit; }
  if ($roles) {
    $u = auth_user();
    if (!in_array($u['role'], (array)$roles, true)) {
      http_response_code(403);
      echo "<div style='margin:2rem;font-family:sans-serif'><h3>Forbidden</h3><p>You do not have access.</p><p><a href='/public/index.php'>Back</a></p></div>";
      exit;
    }
  }
}

function auth_login_password(string $username, string $password): bool {
  $pdo = Database::pdo();
  // Ensure users table exists (migration runner executes on first PDO init)
  $st = $pdo->prepare("SELECT id, username, password_hash, role, active FROM users WHERE username=?");
  $st->execute([$username]);
  $u = $st->fetch();
  if (!$u || !(int)$u['active']) return false;
  if (!password_verify($password, $u['password_hash'])) return false;
  $_SESSION['user'] = ['id'=>(int)$u['id'], 'username'=>$u['username'], 'role'=>$u['role'], 'active'=>(int)$u['active']];
  return true;
}

function auth_logout(){
  $_SESSION = [];
  if (session_id()) session_destroy();
}
