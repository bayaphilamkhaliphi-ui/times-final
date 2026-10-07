<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.html");
    exit;
}
require_once 'db.php';

// Handle assignment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign'])) {
    $document_id = intval($_POST['document_id']);
    $employee_ids = $_POST['employees'] ?? []; // array of employee IDs

    if ($document_id && !empty($employee_ids)) {
        foreach ($employee_ids as $emp_id) {
            // Check if already assigned
            $check = $conn->prepare("SELECT id FROM employee_documents WHERE employee_id = ? AND document_id = ?");
            $check->bind_param("ii", $emp_id, $document_id);
            $check->execute();
            if ($check->get_result()->num_rows == 0) {
                // Insert assignment
                $stmt = $conn->prepare("INSERT INTO employee_documents (employee_id, document_id, status) VALUES (?, ?, 'pending')");
                $stmt->bind_param("ii", $emp_id, $document_id);
                $stmt->execute();
                $assignment_id = $stmt->insert_id;
                $stmt->close();

                // Log assignment
                $log = $conn->prepare("INSERT INTO agreement_logs (employee_document_id, action, ip_address, user_agent) VALUES (?, 'assigned', ?, ?)");
                $ip = $_SERVER['REMOTE_ADDR'];
                $ua = $_SERVER['HTTP_USER_AGENT'];
                $log->bind_param("iss", $assignment_id, $ip, $ua);
                $log->execute();
                $log->close();
            }
            $check->close();
        }
        $success = "Assigned successfully!";
    } else {
        $error = "Please select a document and at least one employee.";
    }
}

// Fetch all documents and employees
$docs = $conn->query("SELECT id, title, category FROM documents ORDER BY upload_date DESC");
$emps = $conn->query("SELECT id, fullname, username FROM users WHERE role = 'employee' ORDER BY fullname");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Assign Documents</title>
    <style>
        body { font-family: 'Poppins', sans-serif; background: #f4f6f9; padding: 30px; }
        .container { max-width: 800px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); }
        h1 { color: #8B0000; }
        .form-group { margin-bottom: 20px; }
        label { font-weight: 600; display: block; margin-bottom: 6px; }
        select, select[multiple] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 6px; }
        .btn { background: #8B0000; color: #fff; border: none; padding: 12px 25px; border-radius: 6px; cursor: pointer; font-weight: 600; }
        .btn:hover { background: #600000; }
        .alert { padding: 15px; border-radius: 6px; margin-bottom: 20px; }
        .alert-success { background: #d4edda; color: #155724; }
        .alert-error { background: #f8d7da; color: #721c24; }
    </style>
</head>
<body>
    <div class="container">
        <h1>Assign Document to Employees</h1>
        <?php if (isset($success)) echo "<div class='alert alert-success'>$success</div>"; ?>
        <?php if (isset($error)) echo "<div class='alert alert-error'>$error</div>"; ?>
        <form method="POST">
            <div class="form-group">
                <label for="document_id">Select Document</label>
                <select name="document_id" id="document_id" required>
                    <option value="">-- Choose --</option>
                    <?php while ($doc = $docs->fetch_assoc()): ?>
                        <option value="<?= $doc['id'] ?>">[<?= $doc['category'] ?>] <?= htmlspecialchars($doc['title']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="employees">Select Employees (hold Ctrl to select multiple)</label>
                <select name="employees[]" id="employees" multiple required style="height:200px;">
                    <?php while ($emp = $emps->fetch_assoc()): ?>
                        <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['fullname']) ?> (<?= htmlspecialchars($emp['username']) ?>)</option>
                    <?php endwhile; ?>
                </select>
            </div>
            <button type="submit" name="assign" class="btn">Assign Document</button>
        </form>
        <p style="margin-top:20px;"><a href="admindash.php">← Back to Admin Dashboard</a></p>
    </div>
</body>
</html>