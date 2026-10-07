<?php
require_once 'db.php';

// ---- EDIT THESE VALUES BEFORE RUNNING ----
$fullname     = 'HR Manager';
$email        = 'hr@times.co.sz';
$username     = 'hrmanager';
$password     = password_hash('Manager@123', PASSWORD_DEFAULT);
$role         = 'linemanager';        // <-- same field administrators get 'admin' in
$dept_name    = 'HR';                 // must match a row in the departments table
// -------------------------------------------

// Look up department id by name
$dept_stmt = $conn->prepare("SELECT id FROM departments WHERE name = ?");
$dept_stmt->bind_param("s", $dept_name);
$dept_stmt->execute();
$dept = $dept_stmt->get_result()->fetch_assoc();
$dept_stmt->close();

if (!$dept) {
    die("Department '$dept_name' not found. Check spelling or run the departments migration first.");
}
$department_id = $dept['id'];

// Line managers report to an admin (the MD) — grab the first admin account found
$admin_stmt = $conn->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$admin_stmt->execute();
$admin = $admin_stmt->get_result()->fetch_assoc();
$admin_stmt->close();

if (!$admin) {
    die("No admin account exists yet. Create one first (e.g. via create_admin.php).");
}
$manager_id = $admin['id']; // the admin this line manager reports to

$stmt = $conn->prepare("INSERT INTO users (fullname, email, username, password, role, department_id, manager_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
$stmt->bind_param("sssssii", $fullname, $email, $username, $password, $role, $department_id, $manager_id);

if ($stmt->execute()) {
    echo "Line Manager account created successfully!<br>";
    echo "Role saved as: <strong>$role</strong><br>";
    echo "Username: <strong>$username</strong><br>";
    echo "Password: <strong>Manager@123</strong><br>";
    echo "Department: <strong>$dept_name</strong><br>";
} else {
    echo "Error: " . $stmt->error;
}
$stmt->close();
$conn->close();
?>
