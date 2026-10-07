<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
session_start();

define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 15 * 60);

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
    $_SESSION['last_login_attempt'] = 0;
}

if ($_SESSION['login_attempts'] >= MAX_LOGIN_ATTEMPTS) {
    $since = time() - intval($_SESSION['last_login_attempt']);
    if ($since < LOCKOUT_TIME) {
        $remaining = LOCKOUT_TIME - $since;
        echo "<script>alert('Too many login attempts. Please try again after " . ceil($remaining/60) . " minute(s).'); window.location.href='index.html';</script>";
        exit();
    } else {
        $_SESSION['login_attempts'] = 0;
        $_SESSION['last_login_attempt'] = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login_btn'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $postedRole = trim($_POST['role'] ?? '');

    if ($username === '' || $password === '') {
        echo "<script>alert('Please provide username and password.'); window.location.href='index.html';</script>";
        exit();
    }

    require_once 'db.php';

    // Fetch user including department_id and manager_id
    $stmt = $conn->prepare("SELECT id, fullname, username, password, role, department_id, manager_id FROM users WHERE username = ? LIMIT 1");
    if (!$stmt) {
        die("Database error: " . $conn->error . ". Please run <a href='setup.php'>setup.php</a> to repair.");
    }
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $_SESSION['login_attempts'] += 1;
        $_SESSION['last_login_attempt'] = time();
        echo "<script>alert('Invalid username or password.'); window.location.href='index.html';</script>";
        exit();
    }

    // Verify password
    if (!password_verify($password, $user['password'])) {
        $_SESSION['login_attempts'] += 1;
        $_SESSION['last_login_attempt'] = time();
        echo "<script>alert('Invalid username or password.'); window.location.href='index.html';</script>";
        exit();
    }

    // Normalize both values: remove whitespace, underscores, dashes
    // and map synonyms so 'Line Manager', 'line_manager', and 'linemanager' match
    $normalize = function ($value) {
        $value = strtolower(trim($value));
        $value = str_replace([' ', '-', '_'], '', $value);
        if ($value === 'administrator') $value = 'admin';
        if ($value === 'staff') $value = 'employee';
        if ($value === 'linemanager' || $value === 'manager') $value = 'linemanager';
        return $value;
    };

    $normalizedPosted = $normalize($postedRole);
    $normalizedDbRole = $normalize($user['role']);

    // Check if selected role matches the database role
    if (!empty($normalizedPosted) && $normalizedPosted !== $normalizedDbRole) {
        $_SESSION['login_attempts'] += 1;
        $_SESSION['last_login_attempt'] = time();
        echo "<script>alert('Selected user type does not match account role.'); window.location.href='index.html';</script>";
        exit();
    }

    // Successful login – reset attempts
    $_SESSION['login_attempts'] = 0;
    session_regenerate_id(true);

    // Store user data in session (using canonical role for flawless permissions across pages)
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['fullname'] = $user['fullname'];
    $_SESSION['role'] = $normalizedDbRole;
    $_SESSION['department_id'] = $user['department_id'];
    $_SESSION['manager_id'] = $user['manager_id'];

    // Redirect based on role
    if ($normalizedDbRole === 'admin') {
        header("Location: admindash.php");
    } elseif ($normalizedDbRole === 'linemanager') {
        header("Location: managerdash.php");
    } else {
        // employee or default
        header("Location: dashboard.php");
    }
    exit();

} else {
    header("Location: index.html");
    exit();
}