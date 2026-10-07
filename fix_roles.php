<?php
require_once 'db.php';

echo "<h2>Fixing Database Roles...</h2>";

// 1. Update the database structure to officially support 'linemanager'
$sql = "ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'linemanager', 'employee') NOT NULL DEFAULT 'employee'";
if ($conn->query($sql)) {
    echo "✓ Database structure updated successfully.<br>";
} else {
    echo "❌ Error updating database: " . $conn->error . "<br>";
}

// 2. Fix the roles for any line managers that were accidentally saved as 'employee' or blank
$managers = ['managingeditor', 'hrmanager', 'newseditor', 'financemanager', 'circulationmgr', 'advertisingmgr'];

foreach ($managers as $username) {
    $stmt = $conn->prepare("UPDATE users SET role = 'linemanager' WHERE username = ?");
    $stmt->bind_param("s", $username);
    $stmt->execute();
    $stmt->close();
}

// Also try to fix any custom line managers created through the dashboard recently
// (We know they are line managers if they have a department assigned and report to the admin)
$admin_stmt = $conn->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1");
if ($admin = $admin_stmt->fetch_assoc()) {
    $admin_id = $admin['id'];
    $fix_custom = $conn->prepare("UPDATE users SET role = 'linemanager' WHERE manager_id = ? AND department_id IS NOT NULL AND role != 'admin'");
    $fix_custom->bind_param("i", $admin_id);
    $fix_custom->execute();
    $fix_custom->close();
}

echo "<h3>✓ All Line Manager accounts have been repaired!</h3>";
echo "<a href='index.html'>Click here to go to the Login Page</a>";
?>
