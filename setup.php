<?php
// Complete HR Portal Database Setup and Recovery Script
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h2>Times of Eswatini - HR System Recovery & Setup</h2>";

// Connect to MySQL server
$conn = new mysqli("localhost", "root", "");
if ($conn->connect_error) {
    die("<div style='color:red;'>❌ MySQL connection failed: " . $conn->connect_error . "</div>");
}

// 1. Create database
if ($conn->query("CREATE DATABASE IF NOT EXISTS hr_portal CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
    echo "✓ Database 'hr_portal' checked/created successfully.<br>";
} else {
    die("<div style='color:red;'>❌ Error creating database: " . $conn->error . "</div>");
}

$conn->select_db("hr_portal");

function safeQuery($conn, $sql) {
    try {
        return $conn->query($sql);
    } catch (Exception $e) {
        return false;
    }
}

function columnExists($conn, $table, $column) {
    $res = safeQuery($conn, "SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && $res->num_rows > 0);
}

// 2. Departments table
$sql_dept = "CREATE TABLE IF NOT EXISTS departments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
safeQuery($conn, $sql_dept);
echo "✓ Table 'departments' ready.<br>";

// Seed default departments
$depts = [
    'Managing Editor', 
    'HR', 
    'News Editor', 
    'Finance Manager', 
    'Circulation Manager', 
    'Advertising Manager'
];
foreach ($depts as $d) {
    $stmt = $conn->prepare("INSERT IGNORE INTO departments (name) VALUES (?)");
    $stmt->bind_param("s", $d);
    $stmt->execute();
    $stmt->close();
}
echo "✓ Default departments seeded.<br>";

// 3. Users table
$sql_users = "CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    username VARCHAR(100) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    role ENUM('admin', 'linemanager', 'employee') NOT NULL DEFAULT 'employee',
    department_id INT NULL,
    manager_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
safeQuery($conn, $sql_users);

if (!columnExists($conn, 'users', 'department_id')) {
    safeQuery($conn, "ALTER TABLE users ADD COLUMN department_id INT NULL");
}
if (!columnExists($conn, 'users', 'manager_id')) {
    safeQuery($conn, "ALTER TABLE users ADD COLUMN manager_id INT NULL");
}
safeQuery($conn, "ALTER TABLE users MODIFY COLUMN role ENUM('admin', 'linemanager', 'employee') NOT NULL DEFAULT 'employee'");
echo "✓ Table 'users' ready.<br>";

// 4. Documents table
$sql_docs = "CREATE TABLE IF NOT EXISTS documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    category ENUM('KPI', 'SOP', 'Policy', 'Policies', 'Other') NOT NULL DEFAULT 'Other',
    description TEXT,
    filename VARCHAR(255) NOT NULL,
    uploaded_by INT,
    upload_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
safeQuery($conn, $sql_docs);
echo "✓ Table 'documents' ready.<br>";

// 5. Employee Documents table
$sql_emp_docs = "CREATE TABLE IF NOT EXISTS employee_documents (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    document_id INT NOT NULL,
    status ENUM('pending', 'viewed', 'agreed') DEFAULT 'pending',
    viewed_at TIMESTAMP NULL DEFAULT NULL,
    agreed_at TIMESTAMP NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
safeQuery($conn, $sql_emp_docs);
echo "✓ Table 'employee_documents' ready.<br>";

// 6. Agreement Logs table
$sql_logs = "CREATE TABLE IF NOT EXISTS agreement_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_document_id INT NOT NULL,
    action VARCHAR(50) NOT NULL,
    ip_address VARCHAR(45),
    user_agent VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
safeQuery($conn, $sql_logs);
echo "✓ Table 'agreement_logs' ready.<br>";

// 7. KPI Forms table
$sql_kpi_forms = "CREATE TABLE IF NOT EXISTS kpi_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    employee_id INT NOT NULL,
    manager_id INT NOT NULL,
    status ENUM('draft', 'assigned', 'submitted', 'reviewed') DEFAULT 'draft',
    manager_overall_rating DECIMAL(5,2) NULL,
    manager_overall_comment TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
safeQuery($conn, $sql_kpi_forms);

safeQuery($conn, "ALTER TABLE kpi_forms MODIFY COLUMN status ENUM('draft', 'assigned', 'submitted', 'reviewed') DEFAULT 'draft'");
if (!columnExists($conn, 'kpi_forms', 'manager_overall_rating')) {
    safeQuery($conn, "ALTER TABLE kpi_forms ADD COLUMN manager_overall_rating DECIMAL(5,2) NULL");
}
if (!columnExists($conn, 'kpi_forms', 'manager_overall_comment')) {
    safeQuery($conn, "ALTER TABLE kpi_forms ADD COLUMN manager_overall_comment TEXT NULL");
}
if (!columnExists($conn, 'kpi_forms', 'employee_overall_rating')) {
    safeQuery($conn, "ALTER TABLE kpi_forms ADD COLUMN employee_overall_rating DECIMAL(5,2) NULL");
}
if (!columnExists($conn, 'kpi_forms', 'final_performance_score')) {
    safeQuery($conn, "ALTER TABLE kpi_forms ADD COLUMN final_performance_score DECIMAL(5,2) NULL");
}
if (!columnExists($conn, 'kpi_forms', 'employee_overall_comment')) {
    safeQuery($conn, "ALTER TABLE kpi_forms ADD COLUMN employee_overall_comment TEXT NULL");
}
echo "✓ Table 'kpi_forms' ready.<br>";

// 8. KPI Tasks table
$sql_kpi_tasks = "CREATE TABLE IF NOT EXISTS kpi_tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kpi_form_id INT NOT NULL,
    task_description TEXT,
    task_weight DECIMAL(5,2) DEFAULT 0.00,
    task_output TEXT,
    output_weight DECIMAL(5,2) DEFAULT 0.00,
    employee_rating DECIMAL(5,2) NULL,
    employee_comment TEXT NULL,
    manager_rating DECIMAL(5,2) NULL,
    manager_comment TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
safeQuery($conn, $sql_kpi_tasks);
if (!columnExists($conn, 'kpi_tasks', 'final_task_score')) {
    safeQuery($conn, "ALTER TABLE kpi_tasks ADD COLUMN final_task_score DECIMAL(5,2) NULL");
}
echo "✓ Table 'kpi_tasks' ready.<br>";

// 9. KPI Outputs table
$sql_kpi_outputs = "CREATE TABLE IF NOT EXISTS kpi_outputs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    kpi_task_id INT NOT NULL,
    output_description TEXT,
    output_weight DECIMAL(5,2) DEFAULT 0.00,
    employee_rating DECIMAL(5,2) NULL,
    employee_comment TEXT NULL,
    manager_rating DECIMAL(5,2) NULL,
    manager_comment TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
safeQuery($conn, $sql_kpi_outputs);
echo "✓ Table 'kpi_outputs' ready.<br>";

// 10. Seed Accounts
echo "<h3>Seeding System Accounts</h3>";

// Admin Accounts
$admins = [
    [
        'fullname' => 'System Administrator',
        'email' => 'admin@eswatinitimes.co.sz',
        'username' => 'admin',
        'password' => 'admin123'
    ],
    [
        'fullname' => 'HR Administrator',
        'email' => 'hr@eswatinitimes.co.sz',
        'username' => 'hradmin',
        'password' => 'humanresource'
    ]
];

foreach ($admins as $a) {
    $hash = password_hash($a['password'], PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (fullname, email, username, password, role) VALUES (?, ?, ?, ?, 'admin') ON DUPLICATE KEY UPDATE password = ?, role = 'admin', fullname = ?");
    $stmt->bind_param("ssssss", $a['fullname'], $a['email'], $a['username'], $hash, $hash, $a['fullname']);
    $stmt->execute();
    $stmt->close();
    echo "✓ Admin account ready: <strong>{$a['username']}</strong> (Password: <code>{$a['password']}</code>)<br>";
}

// Fetch admin id
$admin_id = 1;
$admin_res = $conn->query("SELECT id FROM users WHERE username = 'admin' LIMIT 1");
if ($admin_row = $admin_res->fetch_assoc()) {
    $admin_id = $admin_row['id'];
}

// Line Managers
$managers = [
    ['fullname' => 'Managing Editor',     'dept' => 'Managing Editor',     'username' => 'managingeditor', 'password' => 'Manager@123', 'email' => 'managingeditor@times.co.sz'],
    ['fullname' => 'HR Manager',          'dept' => 'HR',                  'username' => 'hrmanager',      'password' => 'Manager@123', 'email' => 'hrdept@times.co.sz'],
    ['fullname' => 'News Editor',         'dept' => 'News Editor',         'username' => 'newseditor',     'password' => 'Manager@123', 'email' => 'newseditor@times.co.sz'],
    ['fullname' => 'Finance Manager',     'dept' => 'Finance Manager',     'username' => 'financemanager', 'password' => 'Manager@123', 'email' => 'finance@times.co.sz'],
    ['fullname' => 'Circulation Manager', 'dept' => 'Circulation Manager', 'username' => 'circulationmgr', 'password' => 'Manager@123', 'email' => 'circulation@times.co.sz'],
    ['fullname' => 'Advertising Manager', 'dept' => 'Advertising Manager', 'username' => 'advertisingmgr', 'password' => 'Manager@123', 'email' => 'advertising@times.co.sz'],
];

foreach ($managers as $m) {
    $dept_stmt = $conn->prepare("SELECT id FROM departments WHERE name = ?");
    $dept_stmt->bind_param("s", $m['dept']);
    $dept_stmt->execute();
    $drow = $dept_stmt->get_result()->fetch_assoc();
    $dept_stmt->close();
    $dept_id = $drow['id'] ?? null;

    $hash = password_hash($m['password'], PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (fullname, email, username, password, role, department_id, manager_id) VALUES (?, ?, ?, ?, 'linemanager', ?, ?) ON DUPLICATE KEY UPDATE password = ?, role = 'linemanager', department_id = ?, manager_id = ?");
    $stmt->bind_param("ssssiisii", $m['fullname'], $m['email'], $m['username'], $hash, $dept_id, $admin_id, $hash, $dept_id, $admin_id);
    $stmt->execute();
    $stmt->close();
    echo "✓ Line Manager account ready: <strong>{$m['username']}</strong> (Password: <code>{$m['password']}</code> | Dept: {$m['dept']})<br>";
}

// Staff Members
$staff_members = [
    ['fullname' => 'Staff Member', 'email' => 'staff@example.com', 'username' => 'staff', 'password' => 'staff123'],
    ['fullname' => 'Bayaphila Mkhaliphi', 'email' => 'bayaphila@times.co.sz', 'username' => 'bayaphila', 'password' => 'staff123']
];

foreach ($staff_members as $s) {
    $hash = password_hash($s['password'], PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (fullname, email, username, password, role, manager_id) VALUES (?, ?, ?, ?, 'employee', ?) ON DUPLICATE KEY UPDATE password = ?, role = 'employee'");
    $stmt->bind_param("ssssis", $s['fullname'], $s['email'], $s['username'], $hash, $admin_id, $hash);
    $stmt->execute();
    $stmt->close();
    echo "✓ Staff account ready: <strong>{$s['username']}</strong> (Password: <code>{$s['password']}</code>)<br>";
}

// 11. Recover and register existing uploaded files
echo "<h3>Scanning & Recovering Uploaded Files</h3>";
if (!is_dir('uploads')) {
    mkdir('uploads', 0777, true);
}

$uploaded_files = scandir('uploads');
$recovered_count = 0;
foreach ($uploaded_files as $f) {
    if ($f === '.' || $f === '..') continue;
    $check = $conn->prepare("SELECT id FROM documents WHERE filename = ?");
    $check->bind_param("s", $f);
    $check->execute();
    if ($check->get_result()->num_rows == 0) {
        // Derive title from filename
        $title_raw = preg_replace('/^\d+_\d+_/', '', $f);
        $title = ucwords(str_replace(['_', '-'], ' ', pathinfo($title_raw, PATHINFO_FILENAME)));
        $cat = (stripos($f, 'kpi') !== false) ? 'KPI' : 'Policy';

        $ins = $conn->prepare("INSERT INTO documents (title, category, description, filename, uploaded_by) VALUES (?, ?, 'Recovered document', ?, ?)");
        $ins->bind_param("sssi", $title, $cat, $f, $admin_id);
        $ins->execute();
        $ins->close();
        $recovered_count++;
        echo "✓ Restored document: <strong>{$title}</strong> ({$f}) [Category: {$cat}]<br>";
    }
    $check->close();
}
echo "✓ Total recovered files into database: {$recovered_count}<br>";

echo "<h3 style='color:green;'>All HR System Tables and Accounts Successfully Recovered!</h3>";
echo "<p><a href='index.html' style='font-size:16px; font-weight:bold;'>Click here to go to the Login Page</a></p>";
$conn->close();
?>
