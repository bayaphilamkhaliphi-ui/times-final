<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['linemanager', 'admin'])) {
    header("Location: index.html");
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$employee_id = intval($_GET['user_id'] ?? 0);

if (!$employee_id) {
    die("Invalid employee selected.");
}

// Ensure the employee is managed by this user or user is admin
// Ensure proper hierarchy: Admin manages Line Managers/all; Line Managers manage Staff in their team
$is_admin = ($_SESSION['role'] === 'admin');
if ($is_admin) {
    $emp_q = $conn->prepare("SELECT id, fullname, username, role, manager_id FROM users WHERE id = ?");
    $emp_q->bind_param("i", $employee_id);
} else {
    $dept_id = intval($_SESSION['department_id'] ?? 0);
    // Line managers can only manage staff (role = 'employee') in their department or reporting to them
    $emp_q = $conn->prepare("SELECT id, fullname, username, role, manager_id FROM users WHERE id = ? AND role = 'employee' AND (manager_id = ? OR department_id = ?)");
    $emp_q->bind_param("iii", $employee_id, $user_id, $dept_id);
}
$emp_q->execute();
$employee = $emp_q->get_result()->fetch_assoc();
$emp_q->close();

if (!$employee) {
    die("You are not authorized to manage this user's KPI.");
}

// Reviewing manager is always the user creating/managing this workbook
$assigned_manager_id = $user_id;

// If a Line Manager is setting up a staff member, link the staff member to this manager
if (!$is_admin && $employee['role'] === 'employee') {
    $conn->query("UPDATE users SET manager_id = $user_id, department_id = " . intval($_SESSION['department_id'] ?? 0) . " WHERE id = $employee_id");
}

// Find existing draft/assigned form
$form_q = $conn->query("SELECT * FROM kpi_forms WHERE employee_id = $employee_id ORDER BY id DESC LIMIT 1");
$form = $form_q->fetch_assoc();

if (!$form || in_array($form['status'], ['reviewed'])) {
    $conn->query("INSERT INTO kpi_forms (employee_id, manager_id, status) VALUES ($employee_id, $assigned_manager_id, 'assigned')");
    $form_id = $conn->insert_id;
} else {
    $form_id = $form['id'];
}

// Handle Add Task
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_task'])) {
    $task = trim($_POST['task']);
    $task_weight = floatval($_POST['task_weight']);

    if (!empty($task)) {
        $stmt = $conn->prepare("INSERT INTO kpi_tasks (kpi_form_id, task_description, task_weight, task_output, output_weight) VALUES (?, ?, ?, '', 0)");
        $stmt->bind_param("isd", $form_id, $task, $task_weight);
        $stmt->execute();
    }
    header("Location: create_kpi_workbook.php?user_id=" . $employee_id);
    exit;
}

// Handle Add Output
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_output'])) {
    $task_id = intval($_POST['task_id']);
    $output = trim($_POST['output_desc']);
    $output_weight = floatval($_POST['output_weight']);

    if (!empty($output) && $task_id > 0) {
        $stmt = $conn->prepare("INSERT INTO kpi_outputs (kpi_task_id, output_description, output_weight) VALUES (?, ?, ?)");
        $stmt->bind_param("isd", $task_id, $output, $output_weight);
        $stmt->execute();
    }
    header("Location: create_kpi_workbook.php?user_id=" . $employee_id);
    exit;
}

// Handle Delete Task
if (isset($_GET['delete_task'])) {
    $task_id = intval($_GET['delete_task']);
    $conn->query("DELETE FROM kpi_tasks WHERE id = $task_id AND kpi_form_id = $form_id");
    header("Location: create_kpi_workbook.php?user_id=" . $employee_id);
    exit;
}

// Handle Delete Output
if (isset($_GET['delete_output'])) {
    $output_id = intval($_GET['delete_output']);
    // Ensure it belongs to this form
    $conn->query("DELETE o FROM kpi_outputs o JOIN kpi_tasks t ON o.kpi_task_id = t.id WHERE o.id = $output_id AND t.kpi_form_id = $form_id");
    header("Location: create_kpi_workbook.php?user_id=" . $employee_id);
    exit;
}

// Finalize Workbook
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['finalize'])) {
    $conn->query("UPDATE kpi_forms SET status = 'assigned' WHERE id = $form_id");
    $redir = ($is_admin) ? 'admindash.php' : 'managerdash.php';
    header("Location: " . $redir . "?msg=" . urlencode("KPI Workbook assigned successfully"));
    exit;
}

// Fetch tasks and outputs
$tasks = [];
$t_q = $conn->query("SELECT * FROM kpi_tasks WHERE kpi_form_id = $form_id");
if ($t_q) {
    while ($r = $t_q->fetch_assoc()) {
        $r['outputs'] = [];
        $o_q = $conn->query("SELECT * FROM kpi_outputs WHERE kpi_task_id = " . $r['id']);
        while ($o = $o_q->fetch_assoc()) {
            $r['outputs'][] = $o;
        }
        $tasks[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>KPI Records Workbook</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #8B0000, #D40000); min-height: 100vh; padding: 40px 20px; margin: 0; }
    .container { width: 100%; max-width: 1000px; margin: 0 auto; background: white; padding: 30px 40px; border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.25); }
    h2 { color: #8B0000; margin-bottom: 5px; }
    .csv-input-grid { display: grid; grid-template-columns: 3fr 1fr; gap: 15px; margin-bottom: 15px; background: #f9f9f9; padding: 15px; border-radius: 10px; border: 1px solid #e0e0e0; }
    .form-group { margin-bottom: 0; }
    label { display: block; font-weight: 500; color: #333; margin-bottom: 5px; font-size: 13px; }
    input[type=text], textarea, input[type=number] { width: 100%; padding: 10px 14px; border: 1px solid #e0e0e0; border-radius: 8px; font-size: 14px; box-sizing: border-box; }
    .primary-btn { padding: 10px 18px; background: #8B0000; color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 13px; font-weight: 600; display: inline-flex; justify-content: center; align-items: center; text-decoration: none;}
    .primary-btn:hover { background: #600000; }
    .delete-btn { background: #d32f2f; color: white; border: none; padding: 4px 8px; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 12px; }
    
    .task-card { border: 1px solid #e0e0e0; border-radius: 10px; margin-bottom: 20px; overflow: hidden; }
    .task-header { background: #f4f6f9; padding: 15px; border-bottom: 1px solid #e0e0e0; display: flex; justify-content: space-between; align-items: center; }
    .task-header h4 { margin: 0; color: #333; font-size: 15px; }
    .task-body { padding: 15px; }
    
    .output-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-bottom: 15px; }
    .output-table th, .output-table td { padding: 8px 10px; border-bottom: 1px solid #eee; text-align: left; }
    .output-table th { background: #fafafa; font-weight: 500; color: #666; }
    
    .add-output-form { display: flex; gap: 10px; align-items: center; background: #fafafa; padding: 10px; border-radius: 8px; border: 1px dashed #ccc; }
    .add-output-form input[type=text] { flex: 2; }
    .add-output-form input[type=number] { flex: 1; }
</style>
</head>
<body>
<div class="container">
    <h2>KPI Workbook Editor</h2>
    <p style="font-size: 13px; color: #666; margin-bottom: 20px;">Staff: <strong><?= htmlspecialchars($employee['fullname']) ?></strong></p>
    
    <div style="background: #fdfdfd; padding: 20px; border-radius: 10px; border: 1px solid #eee; margin-bottom: 30px;">
        <h3 style="margin-top:0; font-size: 15px; color:#333;">Add New Task</h3>
        <form action="create_kpi_workbook.php?user_id=<?= $employee_id ?>" method="POST">
            <div class="csv-input-grid">
                <div class="form-group">
                    <label>Task Description</label>
                    <textarea name="task" placeholder="Enter main task..." required style="height: 42px; resize: none;"></textarea>
                </div>
                <div class="form-group">
                    <label>Task Weight (%)</label>
                    <input type="number" step="0.01" name="task_weight" required placeholder="e.g. 50">
                </div>
            </div>
            <button type="submit" name="add_task" class="primary-btn">Save Task</button>
        </form>
    </div>

    <h3 style="margin-top:0; font-size: 16px; color:#333; margin-bottom:15px;">Assigned Tasks & Expected Outputs</h3>
    <?php if (empty($tasks)): ?>
        <p style="color:#777; font-size: 13px;">No tasks added yet.</p>
    <?php endif; ?>

    <?php foreach ($tasks as $i => $t): ?>
    <?php 
        $total_out_wt = 0;
        foreach ($t['outputs'] as $o) $total_out_wt += $o['output_weight'];
        $wt_color = ($total_out_wt == $t['task_weight']) ? 'green' : (($total_out_wt > $t['task_weight']) ? 'red' : '#e67e22');
    ?>
    <div class="task-card">
        <div class="task-header">
            <div>
                <h4>Task <?= $i+1 ?>: <?= htmlspecialchars($t['task_description']) ?></h4>
                <div style="font-size: 12px; color: #666; margin-top: 5px;">
                    Task Weight: <strong><?= $t['task_weight'] ?>%</strong> | 
                    Outputs Sum: <strong style="color:<?= $wt_color ?>"><?= $total_out_wt ?>%</strong>
                    <?php if($total_out_wt != $t['task_weight']): ?>
                        <span style="color:red; margin-left: 10px;">(Output weights should sum up to <?= $t['task_weight'] ?>%)</span>
                    <?php endif; ?>
                </div>
            </div>
            <a href="create_kpi_workbook.php?user_id=<?= $employee_id ?>&delete_task=<?= $t['id'] ?>" class="delete-btn" onclick="return confirm('Delete this task and all its outputs?');">Delete Task</a>
        </div>
        <div class="task-body">
            <?php if (!empty($t['outputs'])): ?>
            <table class="output-table">
                <thead>
                    <tr>
                        <th>Expected Output</th>
                        <th style="width: 120px;">Output Weight</th>
                        <th style="width: 80px;">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($t['outputs'] as $o): ?>
                    <tr>
                        <td><?= htmlspecialchars($o['output_description']) ?></td>
                        <td><?= $o['output_weight'] ?>%</td>
                        <td><a href="create_kpi_workbook.php?user_id=<?= $employee_id ?>&delete_output=<?= $o['id'] ?>" class="delete-btn" style="padding: 2px 6px;">Remove</a></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p style="font-size: 12px; color: #999; margin-top: 0;">No outputs added yet.</p>
            <?php endif; ?>

            <form action="create_kpi_workbook.php?user_id=<?= $employee_id ?>" method="POST" class="add-output-form">
                <input type="hidden" name="task_id" value="<?= $t['id'] ?>">
                <input type="text" name="output_desc" placeholder="Add expected output..." required>
                <input type="number" step="0.01" name="output_weight" placeholder="Weight (%)" required>
                <button type="submit" name="add_output" class="primary-btn" style="padding: 8px 15px;">Add</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>

    <div style="display: flex; justify-content: flex-end; gap: 10px; margin-top: 30px;">
        <a href="managerdash.php" class="primary-btn" style="background:#666;">Back to Dashboard</a>
        <form action="create_kpi_workbook.php?user_id=<?= $employee_id ?>" method="POST" style="margin:0;">
            <button type="submit" name="finalize" class="primary-btn">Finalize & Assign Workbook</button>
        </form>
    </div>
</div>
</body>
</html>
