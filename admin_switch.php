<?php
session_start();
require_once 'db.php';

// Check if user is an admin or currently impersonating
$is_admin = (isset($_SESSION['role']) && $_SESSION['role'] === 'admin');
$is_impersonating = isset($_SESSION['admin_impersonator_id']);

if (!$is_admin && !$is_impersonating) {
    header("Location: index.html");
    exit;
}

$action = $_GET['action'] ?? '';

// 1. Revert to original admin
if ($action === 'revert' && $is_impersonating) {
    $admin_id = intval($_SESSION['admin_impersonator_id']);
    $stmt = $conn->prepare("SELECT id, fullname, username, role, department_id, manager_id FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $admin_id);
    $stmt->execute();
    $admin = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($admin && $admin['role'] === 'admin') {
        $_SESSION['user_id'] = $admin['id'];
        $_SESSION['username'] = $admin['username'];
        $_SESSION['fullname'] = $admin['fullname'];
        $_SESSION['role'] = $admin['role'];
        $_SESSION['department_id'] = $admin['department_id'];
        $_SESSION['manager_id'] = $admin['manager_id'];
        unset($_SESSION['admin_impersonator_id']);
        unset($_SESSION['admin_impersonator_name']);
        unset($_SESSION['impersonated_user_id']);
        header("Location: admindash.php?msg=" . urlencode("Switched back to your Administrator account."));
        exit;
    } else {
        session_destroy();
        header("Location: index.html");
        exit;
    }
}

// 2. Switch/Impersonate user
if ($action === 'switch') {
    $target_id = intval($_GET['user_id'] ?? 0);
    if ($target_id <= 0) {
        header("Location: admindash.php?error=" . urlencode("Invalid user selected."));
        exit;
    }

    // Fetch target user
    $stmt = $conn->prepare("SELECT id, fullname, username, role, department_id, manager_id FROM users WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $target_id);
    $stmt->execute();
    $target = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$target) {
        header("Location: admindash.php?error=" . urlencode("Target user not found."));
        exit;
    }

    // Preserve original admin ID if not already impersonating
    if (!$is_impersonating) {
        $_SESSION['admin_impersonator_id'] = $_SESSION['user_id'];
        $_SESSION['admin_impersonator_name'] = $_SESSION['fullname'];
    }

    // Set target user's session
    $_SESSION['impersonated_user_id'] = $target['id'];
    $_SESSION['user_id'] = $target['id'];
    $_SESSION['username'] = $target['username'];
    $_SESSION['fullname'] = $target['fullname'];
    $_SESSION['role'] = $target['role'];
    $_SESSION['department_id'] = $target['department_id'];
    $_SESSION['manager_id'] = $target['manager_id'];

    // Redirect to the target user's dashboard
    if ($target['role'] === 'admin') {
        header("Location: admindash.php?msg=" . urlencode("Now viewing as Administrator: " . $target['fullname']));
    } elseif ($target['role'] === 'linemanager') {
        header("Location: managerdash.php?msg=" . urlencode("Now viewing as Line Manager: " . $target['fullname']));
    } else {
        header("Location: dashboard.php?msg=" . urlencode("Now viewing as Staff: " . $target['fullname']));
    }
    exit;
}

header("Location: admindash.php");
exit;
