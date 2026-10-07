<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.html");
    exit;
}
require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $department_id = intval($_POST['department_id'] ?? 0);
    
    if ($fullname && $email && $username && $password && $department_id) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $role = 'linemanager';
        
        $stmt = $conn->prepare("INSERT INTO users (fullname, email, username, password, role, department_id, manager_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $admin_id = $_SESSION['user_id'];
        $stmt->bind_param("sssssii", $fullname, $email, $username, $hash, $role, $department_id, $admin_id);
        
        if ($stmt->execute()) {
            header("Location: admindash.php?msg=" . urlencode("Line Manager created successfully."));
            exit;
        } else {
            header("Location: admindash.php?error=" . urlencode("Error creating manager: " . $stmt->error));
            exit;
        }
    } else {
        header("Location: admindash.php?error=" . urlencode("All fields are required."));
        exit;
    }
}
header("Location: admindash.php");
exit;
