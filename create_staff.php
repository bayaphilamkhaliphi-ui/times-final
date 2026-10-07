<?php
require_once 'db.php';

$fullname = 'Staff Member';
$email = 'staff@example.com';
$username = 'staff';
$password = password_hash('staff123', PASSWORD_DEFAULT);
$role = 'employee';

$stmt = $conn->prepare("INSERT INTO users (fullname, email, username, password, role) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("sssss", $fullname, $email, $username, $password, $role);

if ($stmt->execute()) {
    echo "Staff user created successfully!<br>";
    echo "Username: <strong>staff</strong><br>";
    echo "Password: <strong>staff123</strong><br>";
} else {
    echo "Error: " . $stmt->error;
}
$stmt->close();
$conn->close();
?>