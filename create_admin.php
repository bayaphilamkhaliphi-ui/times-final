<?php
require_once 'db.php';

$fullname = 'Administrator';
$email = 'admin@example.com';
$username = 'admin';
$password = password_hash('admin123', PASSWORD_DEFAULT);
$role = 'admin';

$stmt = $conn->prepare("INSERT INTO users (fullname, email, username, password, role) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("sssss", $fullname, $email, $username, $password, $role);

if ($stmt->execute()) {
    echo "Admin user created successfully!<br>";
    echo "Username: <strong>admin</strong><br>";
    echo "Password: <strong>admin123</strong><br>";
    echo "You can now log in as admin.";
} else {
    echo "Error: " . $stmt->error;
}
$stmt->close();
$conn->close();
?>