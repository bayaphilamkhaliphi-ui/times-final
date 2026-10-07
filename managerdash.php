<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['linemanager', 'admin'])) {
    header("Location: index.html");
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$department_id = $_SESSION['department_id'] ?? null;
$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';
$is_impersonating = isset($_SESSION['admin_impersonator_id']);

// Check if HR
$is_hr = false;
if ($department_id) {
    $dept_q = $conn->query("SELECT name FROM departments WHERE id = $department_id");
    if ($dept_q && $row = $dept_q->fetch_assoc()) {
        if (stripos($row['name'], 'hr') !== false || stripos($row['name'], 'human resource') !== false) {
            $is_hr = true;
        }
    }
}

// Fetch team employees (including employees who have submitted forms to this manager)
$team_employees = [];
$emp_dept_clause = $department_id ? "OR u.department_id = $department_id" : "";
$emp_sql = "
    SELECT u.id, u.fullname, u.username, u.email,
           f.id AS kpi_form_id, f.status AS kpi_status, f.employee_overall_rating, f.manager_overall_rating
    FROM users u 
    LEFT JOIN kpi_forms f ON f.id = (
        SELECT id FROM kpi_forms WHERE employee_id = u.id ORDER BY id DESC LIMIT 1
    )
    WHERE u.role = 'employee' AND (
        u.manager_id = $user_id 
        $emp_dept_clause
        OR u.id IN (SELECT employee_id FROM kpi_forms WHERE manager_id = $user_id)
    )
    ORDER BY u.fullname ASC
";
$emp_q = $conn->query($emp_sql);
if ($emp_q) {
    while ($r = $emp_q->fetch_assoc()) {
        $team_employees[] = $r;
    }
}

// Fetch pending reviews
$pending_reviews = [];
$p_q = $conn->query("
    SELECT k.*, u.fullname, u.username, u.email 
    FROM kpi_forms k 
    JOIN users u ON k.employee_id = u.id 
    WHERE k.manager_id = $user_id AND k.status = 'submitted'
    ORDER BY k.updated_at DESC
");
if ($p_q) {
    while ($r = $p_q->fetch_assoc()) {
        $pending_reviews[] = $r;
    }
}

// Fetch completed team performance reports
$completed_reports = [];
$c_q = $conn->query("
    SELECT k.*, u.fullname, u.username, u.email 
    FROM kpi_forms k 
    JOIN users u ON k.employee_id = u.id 
    WHERE k.manager_id = $user_id AND k.status = 'reviewed'
    ORDER BY k.updated_at DESC
");
if ($c_q) {
    while ($r = $c_q->fetch_assoc()) {
        $completed_reports[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Line Manager Portal - HR Management</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    body { font-family: 'Poppins', sans-serif; background: #f4f6f9; margin: 0; padding: 0; }
    
    .impersonation-bar { background: #600000; color: #000; padding: 12px 40px; display: flex; justify-content: space-between; align-items: center; font-weight: 500; font-size: 13px; }
    .impersonation-bar a { background: #000; color: #fff; padding: 6px 14px; border-radius: 4px; text-decoration: none; font-weight: 600; }

    header { background: #8B0000; color: white; padding: 15px 40px; display: flex; justify-content: space-between; align-items: center; }
    header a { color: white; text-decoration: none; margin-left: 15px; background: rgba(255,255,255,0.2); padding: 6px 12px; border-radius: 4px; font-size: 13px; }
    header a:hover { background: rgba(255,255,255,0.3); }

    .container { max-width: 1200px; margin: 30px auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.06); }
    h2 { color: #8B0000; margin-top: 0; margin-bottom: 15px; font-size: 20px; }
    
    table { width: 100%; border-collapse: collapse; margin-top: 15px; font-size: 13px; }
    th, td { padding: 12px 14px; border: 1px solid #eee; text-align: left; }
    th { background: #f8fafc; font-weight: 600; color: #334155; }
    tr:hover { background: #fdfdfd; }

    .btn { padding: 8px 16px; background: #8B0000; color: white; border: none; border-radius: 6px; cursor: pointer; text-decoration: none; display: inline-block; font-size: 13px; font-weight: 500; transition: background 0.2s; }
    .btn:hover { background: #600000; }
    .btn-secondary { background: #64748b; }
    .btn-secondary:hover { background: #475569; }
    .btn-success { background: #475569; }
    .btn-success:hover { background: #475569; }
    
    .badge { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
    .badge-reviewed { background: #dcfce7; color: #475569; }
    .badge-submitted { background: #fef9c3; color: #854d0e; }
    .badge-assigned { background: #e0f2fe; color: #0369a1; }
    .badge-draft { background: #f1f5f9; color: #475569; }

    .card-section { margin-bottom: 40px; padding-bottom: 25px; border-bottom: 1px solid #edf2f7; }
    .card-section:last-child { border-bottom: none; }
</style>
</head>
<body>

<?php if ($is_impersonating): ?>
<div class="impersonation-bar">
    <span> Viewing Line Manager Portal as Administrator viewing <strong><?= htmlspecialchars($_SESSION['fullname']) ?></strong>.</span>
    <a href="admin_switch.php?action=revert">← Return to Admin Account</a>
</div>
<?php endif; ?>

<header>
    <div style="font-size:18px; font-weight:700;">Times of Eswatini — Line Manager Portal</div>
    <div>
        <span>Welcome, <strong><?= htmlspecialchars($_SESSION['fullname'] ?? '') ?></strong></span>
        <a href="my_kpi.php" style="background:#ffc107; color:#000; font-weight:600;"> My Own KPIs</a>
        <a href="dashboard.php"> My Documents & Policies</a>
        <a href="export_reports.php" style="background:#28a745;">Export Reports (CSV)</a>
        <a href="logout.php" style="background:#600000;">Logout</a>
    </div>
</header>

<div class="container">
    <?php if ($msg): ?>
        <div style="padding: 12px 18px; background: #d4edda; color: #600000; margin-bottom: 20px; border-radius: 6px; border: 1px solid #c3e6cb; font-size: 14px;">
            ✓ <?= htmlspecialchars($msg) ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div style="padding: 12px 18px; background: #f8d7da; color: #721c24; margin-bottom: 20px; border-radius: 6px; border: 1px solid #f5c6cb; font-size: 14px;">
            ❌ <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <!-- Quick Access to Line Manager's Own KPIs -->
    <div style="background:linear-gradient(135deg, #fff5f5, #ffffff); border:1px solid #fed7d7; border-left:5px solid #8B0000; padding:18px 24px; border-radius:10px; margin-bottom:28px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 2px 8px rgba(0,0,0,0.03);">
        <div>
            <div style="font-size:12px; font-weight:700; color:#8B0000; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:4px;">Personal Performance</div>
            <h3 style="margin:0 0 6px 0; color:#1a202c; font-size:17px;"> My Own KPIs & Self-Evaluation</h3>
            <p style="margin:0; font-size:13px; color:#4a5568;">Rate your achievement and review your personal KPI outputs assigned to you by the Managing Director / Admin.</p>
        </div>
        <div style="display:flex; gap:12px; flex-shrink:0;">
            <a href="my_kpi.php" class="btn" style="background:#8B0000; padding:10px 18px; font-weight:600;">Fill / View My KPIs →</a>
            <a href="dashboard.php" class="btn btn-secondary" style="padding:10px 16px;">My Staff View / Docs</a>
        </div>
    </div>

    <!-- Section 1: Team Members & KPI Assignment -->
    <div class="card-section">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 15px;">
            <h2>My Department Team</h2>
            <a href="add_employee.php" class="btn">+ Add Staff Member</a>
        </div>
        <table>
            <thead>
                <tr>
                    <th>Staff Name</th>
                    <th>Username</th>
                    <th>Email Address</th>
                    <th>KPI Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($team_employees as $emp): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($emp['fullname']) ?></strong></td>
                    <td>@<?= htmlspecialchars($emp['username']) ?></td>
                    <td><?= htmlspecialchars($emp['email']) ?></td>
                    <td>
                        <?php if (!empty($emp['kpi_form_id'])): ?>
                            <span class="badge badge-<?= htmlspecialchars($emp['kpi_status']) ?>">
                                <?= strtoupper($emp['kpi_status']) ?>
                            </span>
                        <?php else: ?>
                            <span style="color:#94a3b8; font-size:12px;">No Workbook Yet</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div style="display:flex; gap:8px; align-items:center;">
                            <a href="create_kpi_workbook.php?user_id=<?= $emp['id'] ?>" class="btn btn-secondary" style="font-size:12px; padding:6px 12px;">Workbook Setup</a>
                            <?php if (!empty($emp['kpi_form_id'])): ?>
                                <a href="review_kpi.php?id=<?= $emp['kpi_form_id'] ?>" class="btn" style="font-size:12px; padding:6px 14px; background:#8B0000;">📝 Review & Rate Outputs</a>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($team_employees)): ?>
                <tr><td colspan="5" style="text-align:center; color:#777; padding:20px;">No staff members assigned to your team yet. Use the "+ Add Staff Member" button above to register members to your department.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Section 2: KPIs Waiting for Review -->
    <div class="card-section">
        <h2> Submissions Awaiting Manager Review</h2>
        <p style="font-size:13px; color:#666; margin-top:-10px;">Employee self-evaluations submitted and waiting for your appraisal.</p>
        <table>
            <thead>
                <tr>
                    <th>Employee Name</th>
                    <th>Status</th>
                    <th>Staff Self-Rating</th>
                    <th>Submitted On</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pending_reviews as $kpi): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($kpi['fullname']) ?></strong> (@<?= htmlspecialchars($kpi['username']) ?>)</td>
                    <td><span class="badge badge-submitted">SUBMITTED</span></td>
                    <td><strong><?= ($kpi['employee_overall_rating'] !== null) ? number_format($kpi['employee_overall_rating'], 1) . '%' : '—' ?></strong></td>
                    <td><?= date('M d, Y \a\t h:i A', strtotime($kpi['updated_at'])) ?></td>
                    <td>
                        <a href="review_kpi.php?id=<?= $kpi['id'] ?>" class="btn" style="padding:6px 14px;">Review & Rate Outputs</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($pending_reviews)): ?>
                <tr><td colspan="5" style="text-align:center; color:#777; padding:20px;">No pending evaluations awaiting review right now.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Section 3: Completed Team Performance Reports -->
    <div class="card-section">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 5px;">
            <h2>Team Performance Reports & Appraisals</h2>
            <a href="export_reports.php" class="btn btn-success" style="font-size:12px;">Export Team Reports (CSV)</a>
        </div>
        <p style="font-size:13px; color:#666; margin-top:0;">Access and print completed official performance appraisals for your team members.</p>
        
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Self-Rating Avg</th>
                    <th>Manager Score</th>
                    <th>Final Performance Score</th>
                    <th>Evaluation Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($completed_reports as $rep): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($rep['fullname']) ?></strong> (@<?= htmlspecialchars($rep['username']) ?>)</td>
                    <td><?= ($rep['employee_overall_rating'] !== null) ? number_format($rep['employee_overall_rating'], 1) . '%' : '—' ?></td>
                    <td><?= ($rep['manager_overall_rating'] !== null) ? number_format($rep['manager_overall_rating'], 1) . '%' : '—' ?></td>
                    <td>
                        <strong style="color: #600000; font-size:14px;"><?= number_format($rep['final_performance_score'] ?? $rep['manager_overall_rating'] ?? 0, 1) ?>%</strong>
                    </td>
                    <td><?= date('M d, Y', strtotime($rep['updated_at'])) ?></td>
                    <td>
                        <a href="kpi_report.php?id=<?= $rep['id'] ?>" class="btn btn-secondary" target="_blank" style="padding:5px 12px; font-size:12px;">View Report</a>
                        <a href="review_kpi.php?id=<?= $rep['id'] ?>" class="btn" style="padding:5px 12px; font-size:12px; background:#475569;">Edit Review</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($completed_reports)): ?>
                <tr><td colspan="6" style="text-align:center; color:#777; padding:20px;">No completed reviews yet. Once you evaluate your team members, reports will be archived here.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>
