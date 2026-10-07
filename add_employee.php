<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'linemanager') {
    header("Location: index.html");
    exit;
}
require_once 'db.php';

$msg = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $manager_id = $_SESSION['user_id'];
    $department_id = $_SESSION['department_id'];

    if ($fullname && $email && $username && $password) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $role = 'employee';
        
        $stmt = $conn->prepare("INSERT INTO users (fullname, email, username, password, role, department_id, manager_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("sssssii", $fullname, $email, $username, $hash, $role, $department_id, $manager_id);
        
        if ($stmt->execute()) {
            $msg = "Employee added successfully.";
        } else {
            $error = "Error adding employee: " . $stmt->error;
        }
    } else {
        $error = "All fields are required.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Add Employee - HR System</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Poppins', sans-serif; background: #f4f6f9; padding: 40px; }
    .card { background: white; max-width: 500px; margin: 0 auto; padding: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    h2 { color: #8B0000; margin-top:0; }
    .form-group { margin-bottom: 15px; }
    label { display: block; font-weight: 500; margin-bottom: 5px; font-size: 14px;}
    input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
    .btn { padding: 10px 15px; background: #8B0000; color: white; border: none; border-radius: 4px; cursor: pointer; width: 100%; font-size: 16px;}
    .alert { padding: 10px; border-radius: 4px; margin-bottom: 15px; }
    .alert-success { background: #d4edda; color: #155724; }
    .alert-error { background: #f8d7da; color: #721c24; }
    a { display: block; text-align: center; margin-top: 15px; color: #666; text-decoration: none; }
</style>
</head>
<body>
<div class="card">
    <h2>Add Employee</h2>
    <?php if ($msg) echo "<div class='alert alert-success'>$msg</div>"; ?>
    <?php if ($error) echo "<div class='alert alert-error'>$error</div>"; ?>
    
    <form action="add_employee.php" method="POST">
        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="fullname" required>
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required>
        </div>
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" required>
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="text" name="password" required>
        </div>
        <button type="submit" class="btn">Add Employee</button>
    </form>
    <a href="managerdash.php">← Back to Dashboard</a>
</div>
</body>
</html>
