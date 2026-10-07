<?php
require_once 'db.php';

// Delete any existing admin accounts (by username) so we start fresh
$stmt = $conn->prepare("DELETE FROM users WHERE username = 'admin' OR username = 'hradmin'");
$stmt->execute();
$stmt->close();

// Insert the new admin account
$fullname = 'HR Administrator';
$email = 'hr@example.com';
$username = 'hradmin';
$password = password_hash('humanresource', PASSWORD_DEFAULT);
$role = 'admin';

$stmt = $conn->prepare("INSERT INTO users (fullname, email, username, password, role) VALUES (?, ?, ?, ?, ?)");
$stmt->bind_param("sssss", $fullname, $email, $username, $password, $role);

if ($stmt->execute()) {
    echo "✅ Admin user created successfully!<br>";
    echo "Username: <strong>hradmin</strong><br>";
    echo "Password: <strong>humanresource</strong><br>";
    echo "You can now log in.";
} else {
    echo "❌ Error: " . $stmt->error;
}
$stmt->close();
$conn->close();
?>