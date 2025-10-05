<?php
// public/logout_clean.php - Clean logout
require_once __DIR__ . '/../includes/simple_auth.php';

auth_logout();

header('Location: login_clean.php?message=' . urlencode('You have been logged out successfully'));
exit;
?>