<?php
// includes/admin_functions.php - Admin utility functions

function require_admin() {
    auth_require_login();
    if (!auth_is_admin()) {
        $_SESSION['error'] = 'Admin access required';
        safe_redirect('index.php');
    }
}

function check_admin_permission($permission = '') {
    if (!auth_is_admin()) {
        return false;
    }
    
    // If specific permission provided, check it
    if ($permission && function_exists('auth_has_permission')) {
        return auth_has_permission($permission);
    }
    
    return true;
}

function admin_log_action($action, $details = '') {
    // Log admin actions for audit trail
    $user_id = $_SESSION['user_id'] ?? 0;
    $username = $_SESSION['username'] ?? 'unknown';
    
    error_log("[ADMIN_ACTION] User: $username (ID: $user_id) - Action: $action - Details: $details");
}
?>