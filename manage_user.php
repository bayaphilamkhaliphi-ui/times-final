<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.html");
    exit;
}
require_once 'db.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// 1. Delete user
if ($action === 'delete') {
    $target_id = intval($_GET['id'] ?? 0);
    $current_id = intval($_SESSION['user_id']);

    if ($target_id === $current_id) {
        header("Location: admindash.php?error=" . urlencode("You cannot delete your own logged-in administrator account."));
        exit;
    }

    if ($target_id > 0) {
        $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
        $stmt->bind_param("i", $target_id);
        if ($stmt->execute()) {
            header("Location: admindash.php?msg=" . urlencode("User account deleted successfully."));
        } else {
            header("Location: admindash.php?error=" . urlencode("Error deleting user: " . $stmt->error));
        }
        $stmt->close();
        exit;
    }
}

// 2. Add user
if ($action === 'add' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = trim($_POST['role'] ?? 'employee');
    $department_id = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
    $manager_id = !empty($_POST['manager_id']) ? intval($_POST['manager_id']) : null;

    if (!$fullname || !$email || !$username || !$password) {
        header("Location: admindash.php?error=" . urlencode("All required fields must be filled."));
        exit;
    }

    if (!in_array($role, ['admin', 'linemanager', 'employee'])) {
        $role = 'employee';
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $conn->prepare("INSERT INTO users (fullname, email, username, password, role, department_id, manager_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("sssssii", $fullname, $email, $username, $hash, $role, $department_id, $manager_id);
    
    if ($stmt->execute()) {
        header("Location: admindash.php?msg=" . urlencode("User account '$username' created successfully."));
    } else {
        header("Location: admindash.php?error=" . urlencode("Error adding user: " . $stmt->error));
    }
    $stmt->close();
    exit;
}

// 3. Edit user (POST submit)
if ($action === 'edit' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $target_id = intval($_POST['user_id'] ?? 0);
    $fullname = trim($_POST['fullname'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = trim($_POST['role'] ?? 'employee');
    $department_id = !empty($_POST['department_id']) ? intval($_POST['department_id']) : null;
    $manager_id = !empty($_POST['manager_id']) ? intval($_POST['manager_id']) : null;

    if ($target_id <= 0 || !$fullname || !$email || !$username) {
        header("Location: admindash.php?error=" . urlencode("Full name, email, and username are required."));
        exit;
    }

    if (!in_array($role, ['admin', 'linemanager', 'employee'])) {
        $role = 'employee';
    }

    if (!empty($password)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $conn->prepare("UPDATE users SET fullname = ?, email = ?, username = ?, password = ?, role = ?, department_id = ?, manager_id = ? WHERE id = ?");
        $stmt->bind_param("sssssiii", $fullname, $email, $username, $hash, $role, $department_id, $manager_id, $target_id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET fullname = ?, email = ?, username = ?, role = ?, department_id = ?, manager_id = ? WHERE id = ?");
        $stmt->bind_param("ssssiii", $fullname, $email, $username, $role, $department_id, $manager_id, $target_id);
    }

    if ($stmt->execute()) {
        header("Location: admindash.php?msg=" . urlencode("User account '$username' updated successfully."));
    } else {
        header("Location: admindash.php?error=" . urlencode("Error updating user: " . $stmt->error));
    }
    $stmt->close();
    exit;
}

// 4. Edit User View (GET request)
if ($action === 'edit_view') {
    $target_id = intval($_GET['id'] ?? 0);
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->bind_param("i", $target_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$user) {
        header("Location: admindash.php?error=" . urlencode("User not found."));
        exit;
    }

    $departments = $conn->query("SELECT * FROM departments ORDER BY name ASC")->fetch_all(MYSQLI_ASSOC);
    $managers = $conn->query("SELECT id, fullname, username, role FROM users WHERE role IN ('admin', 'linemanager') AND id != $target_id ORDER BY fullname ASC")->fetch_all(MYSQLI_ASSOC);
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
    <meta charset="UTF-8">
    <title>Edit User Account - Times of Eswatini</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Poppins', sans-serif; background: #f4f6f9; padding: 40px 20px; }
        .card { background: white; max-width: 600px; margin: 0 auto; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.08); }
        h2 { color: #8B0000; margin-top: 0; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: 500; margin-bottom: 5px; font-size: 13px; }
        input, select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 6px; box-sizing: border-box; font-size: 14px; }
        .btn { padding: 12px 20px; background: #8B0000; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 14px; font-weight: 600; width: 100%; }
        .btn:hover { background: #600000; }
        a { display: block; text-align: center; margin-top: 15px; color: #666; text-decoration: none; font-size: 14px; }
    </style>
    </head>
    <body>
    <div class="card">
        <h2>Edit User: <?= htmlspecialchars($user['fullname']) ?></h2>
        <form action="manage_user.php" method="POST">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">

            <div class="form-row">
                <div class="form-group">
                    <label>Full Name</label>
                    <input type="text" name="fullname" value="<?= htmlspecialchars($user['fullname']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Email</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
                </div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="role" required>
                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option>
                        <option value="linemanager" <?= $user['role'] === 'linemanager' ? 'selected' : '' ?>>Line Manager</option>
                        <option value="employee" <?= $user['role'] === 'employee' ? 'selected' : '' ?>>Employee / Staff</option>
                    </select>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Department</label>
                    <select name="department_id">
                        <option value="">-- No Department --</option>
                        <?php foreach ($departments as $d): ?>
                            <option value="<?= $d['id'] ?>" <?= $user['department_id'] == $d['id'] ? 'selected' : '' ?>><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Reports to Manager</label>
                    <select name="manager_id">
                        <option value="">-- No Manager --</option>
                        <?php foreach ($managers as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= $user['manager_id'] == $m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['fullname']) ?> (<?= ucfirst($m['role']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Reset Password (leave blank to keep current)</label>
                <input type="text" name="password" placeholder="Enter new password to reset">
            </div>

            <button type="submit" class="btn">Save Changes</button>
            <a href="admindash.php">Cancel and return</a>
        </form>
    </div>
    </body>
    </html>
    <?php
    exit;
}

header("Location: admindash.php");
exit;
