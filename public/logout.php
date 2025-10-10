<?php
// public/logout.php - Universal logout for both auth systems
require_once __DIR__ . '/../includes/auth.php';

// Handle logout for both systems
if (class_exists('AuthSystem')) {
    // Enhanced auth system logout
    AuthSystem::logout();
} else {
    // Basic auth system logout
    auth_logout();
}

// Redirect to login page
header('Location: login.php?message=' . urlencode('You have been logged out successfully'));
exit;
?> 