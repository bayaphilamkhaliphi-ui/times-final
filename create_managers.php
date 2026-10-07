<?php
require_once 'db.php';

// One row per Line Manager account to create.
// Change these usernames/passwords/emails before running on a real system.
$managers = [
    ['fullname' => 'Managing Editor',       'dept' => 'Managing Editor',       'username' => 'managingeditor',  'password' => 'Manager@123', 'email' => 'managingeditor@times.co.sz'],
    ['fullname' => 'HR Manager',            'dept' => 'HR',                    'username' => 'hrmanager',       'password' => 'Manager@123', 'email' => 'hr@times.co.sz'],
    ['fullname' => 'News Editor',           'dept' => 'News Editor',           'username' => 'newseditor',      'password' => 'Manager@123', 'email' => 'newseditor@times.co.sz'],
    ['fullname' => 'Finance Manager',       'dept' => 'Finance Manager',       'username' => 'financemanager',  'password' => 'Manager@123', 'email' => 'finance@times.co.sz'],
    ['fullname' => 'Circulation Manager',   'dept' => 'Circulation Manager',   'username' => 'circulationmgr',  'password' => 'Manager@123', 'email' => 'circulation@times.co.sz'],
    ['fullname' => 'Advertising Manager',   'dept' => 'Advertising Manager',   'username' => 'advertisingmgr',  'password' => 'Manager@123', 'email' => 'advertising@times.co.sz'],
];

// The admin account these managers will report to (adjust username if different)
$admin_stmt = $conn->prepare("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
$admin_stmt->execute();
$admin = $admin_stmt->get_result()->fetch_assoc();
$admin_stmt->close();

if (!$admin) {
    die("No admin account found. Create one first (e.g. via create_admin.php).");
}
$admin_id = $admin['id'];

echo "<h2>Creating Line Manager Accounts</h2>";

foreach ($managers as $m) {
    // Look up the department id by name
    $dept_stmt = $conn->prepare("SELECT id FROM departments WHERE name = ?");
    $dept_stmt->bind_param("s", $m['dept']);
    $dept_stmt->execute();
    $dept = $dept_stmt->get_result()->fetch_assoc();
    $dept_stmt->close();

    if (!$dept) {
        echo "❌ Department '{$m['dept']}' not found. Run the departments migration first.<br>";
        continue;
    }
    $department_id = $dept['id'];

    // Skip if username already exists
    $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
    $check->bind_param("s", $m['username']);
    $check->execute();
    if ($check->get_result()->fetch_assoc()) {
        echo "⚠️ Username '{$m['username']}' already exists — skipped.<br>";
        $check->close();
        continue;
    }
    $check->close();

    $hash = password_hash($m['password'], PASSWORD_DEFAULT);
    $role = 'linemanager';

    $stmt = $conn->prepare("INSERT INTO users (fullname, email, username, password, role, department_id, manager_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssii", $m['fullname'], $m['email'], $m['username'], $hash, $role, $department_id, $admin_id);

    if ($stmt->execute()) {
        echo "✓ Created <strong>{$m['fullname']}</strong> — username: <strong>{$m['username']}</strong> | password: <strong>{$m['password']}</strong> | dept: {$m['dept']}<br>";
    } else {
        echo "❌ Failed to create {$m['fullname']}: " . $stmt->error . "<br>";
    }
    $stmt->close();
}

echo "<h3>Done. Log in at index.html with role 'Line Manager' using any of the credentials above.</h3>";
$conn->close();
?>
