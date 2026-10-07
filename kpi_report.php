<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit;
}
require_once 'db.php';

$form_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$form_id) {
    die("Invalid KPI Report ID.");
}

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['role'] ?? 'employee';

// Fetch form with employee and manager details
$stmt = $conn->prepare("
    SELECT f.*, 
           u.fullname AS employee_name, u.username AS employee_username, u.email AS employee_email,
           m.fullname AS manager_name, m.email AS manager_email,
           d.name AS dept_name
    FROM kpi_forms f
    JOIN users u ON f.employee_id = u.id
    LEFT JOIN users m ON f.manager_id = m.id
    LEFT JOIN departments d ON u.department_id = d.id
    WHERE f.id = ?
");
$stmt->bind_param("i", $form_id);
$stmt->execute();
$form = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$form) {
    die("Report not found.");
}

// Access Control: Admin, the assigned manager, or the employee themselves
$can_view = false;
if ($user_role === 'admin' || isset($_SESSION['admin_impersonator_id'])) {
    $can_view = true;
} elseif ($user_id == $form['employee_id']) {
    $can_view = true;
} elseif ($user_id == $form['manager_id']) {
    $can_view = true;
}

if (!$can_view) {
    die("Access Denied: You do not have permission to view this performance report.");
}

// Fetch tasks and outputs
$tasks = [];
$t_q = $conn->query("SELECT * FROM kpi_tasks WHERE kpi_form_id = $form_id ORDER BY id ASC");
if ($t_q) {
    while ($r = $t_q->fetch_assoc()) {
        $r['outputs'] = [];
        $o_q = $conn->query("SELECT * FROM kpi_outputs WHERE kpi_task_id = " . $r['id'] . " ORDER BY id ASC");
        while ($o = $o_q->fetch_assoc()) {
            $r['outputs'][] = $o;
        }
        $tasks[] = $r;
    }
}

// Calculate final scores
$final_score = ($form['final_performance_score'] !== null) 
    ? floatval($form['final_performance_score']) 
    : floatval($form['manager_overall_rating'] ?? $form['employee_overall_rating'] ?? 0);

// Performance rating classification
function getPerformanceGrade($score) {
    if ($score >= 90) return ['grade' => 'Exemplary / Outstanding', 'color' => '#15803d', 'bg' => '#dcfce7'];
    if ($score >= 80) return ['grade' => 'Exceeds Expectations', 'color' => '#0369a1', 'bg' => '#e0f2fe'];
    if ($score >= 70) return ['grade' => 'Meets Expectations', 'color' => '#854d0e', 'bg' => '#fef9c3'];
    if ($score >= 60) return ['grade' => 'Satisfactory / Needs Improvement', 'color' => '#c2410c', 'bg' => '#ffedd5'];
    return ['grade' => 'Unsatisfactory', 'color' => '#b91c1c', 'bg' => '#fee2e2'];
}

$perf_badge = getPerformanceGrade($final_score);
$back_url = ($user_role === 'admin') ? 'admindash.php' : (($user_role === 'linemanager') ? 'managerdash.php' : 'dashboard.php');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Official Performance Report – <?= htmlspecialchars($form['employee_name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --primary: #8B0000;
        --border: #cbd5e1;
        --text-main: #1e293b;
    }
    * { box-sizing: border-box; }
    body { font-family: 'Poppins', sans-serif; background: #f1f5f9; color: var(--text-main); margin: 0; padding: 30px 15px; }

    .report-container { max-width: 950px; margin: 0 auto; background: white; padding: 45px 50px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.08); }

    .action-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
    .btn { padding: 9px 18px; border-radius: 6px; font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; border: none; }
    .btn-primary { background: var(--primary); color: white; }
    .btn-secondary { background: #64748b; color: white; }

    /* Report Header */
    .report-header { display: flex; justify-content: space-between; align-items: center; border-bottom: 3px solid var(--primary); padding-bottom: 20px; margin-bottom: 25px; }
    .company-title { font-size: 24px; font-weight: 700; color: var(--primary); margin: 0; }
    .report-sub { font-size: 13px; color: #64748b; margin-top: 4px; }
    .report-meta { text-align: right; font-size: 12px; color: #64748b; }

    /* Metadata Grid */
    .info-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 15px; background: #f8fafc; padding: 18px; border-radius: 8px; border: 1px solid #e2e8f0; margin-bottom: 25px; font-size: 13px; }
    .info-item strong { display: block; font-size: 11px; text-transform: uppercase; color: #64748b; margin-bottom: 3px; }
    .info-item span { font-weight: 600; color: #0f172a; }

    /* Scorecards */
    .scorecards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 30px; }
    .score-card { border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px 20px; text-align: center; background: #fff; }
    .score-card.highlight { border-color: var(--primary); background: #fffdfd; border-width: 2px; }
    .score-label { font-size: 11px; font-weight: 600; text-transform: uppercase; color: #64748b; margin-bottom: 5px; }
    .score-num { font-size: 28px; font-weight: 700; color: var(--primary); }
    .score-grade { display: inline-block; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 700; margin-top: 6px; }

    /* Tasks and Outputs Matrix */
    h3.section-title { font-size: 16px; color: var(--primary); border-left: 4px solid var(--primary); padding-left: 10px; margin: 25px 0 15px 0; }
    .report-table { width: 100%; border-collapse: collapse; margin-bottom: 25px; font-size: 12px; }
    .report-table th { background: #f1f5f9; padding: 10px 12px; text-align: left; font-weight: 600; color: #334155; border: 1px solid var(--border); }
    .report-table td { padding: 10px 12px; border: 1px solid var(--border); vertical-align: top; }
    .task-row-header { background: #f8fafc; font-weight: 600; color: #0f172a; }
    .task-summary-row { background: #fef2f2; font-weight: 600; }

    /* Qualitative Feedback */
    .feedback-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 15px 20px; margin-bottom: 18px; font-size: 13px; }
    .feedback-box h4 { margin: 0 0 8px 0; color: #334155; font-size: 13px; }
    .feedback-content { color: #1e293b; line-height: 1.5; }

    /* Sign-off section */
    .signatures { display: grid; grid-template-columns: repeat(3, 1fr); gap: 30px; margin-top: 45px; padding-top: 20px; border-top: 1px dashed #cbd5e1; }
    .sig-block { text-align: center; font-size: 12px; }
    .sig-line { border-bottom: 1px solid #334155; height: 40px; margin-bottom: 8px; }
    .sig-title { font-weight: 600; color: #0f172a; }
    .sig-date { color: #64748b; font-size: 11px; margin-top: 2px; }

    /* Print Styles */
    @media print {
        body { background: white; padding: 0; }
        .action-bar { display: none !important; }
        .report-container { box-shadow: none; border-radius: 0; padding: 20px 0; max-width: 100%; }
        .report-table th, .report-table td { border-color: #94a3b8; }
        .info-grid { border-color: #cbd5e1; background: #fff; }
    }
</style>
</head>
<body>

<div class="report-container">
    <div class="action-bar">
        <a href="<?= $back_url ?>" class="btn btn-secondary">← Back to Portal</a>
        <button onclick="window.print()" class="btn btn-primary">🖨️ Print / Download PDF</button>
    </div>

    <!-- Official Header -->
    <div class="report-header">
        <div>
            <h1 class="company-title">Times of Eswatini</h1>
            <div class="report-sub">Performance Appraisal & KPI Assessment Report</div>
        </div>
        <div class="report-meta">
            <div>Report Ref: <strong>#KPI-<?= str_pad($form['id'], 5, '0', STR_PAD_LEFT) ?></strong></div>
            <div>Date Generated: <?= date('F d, Y') ?></div>
            <div>Status: <strong><?= strtoupper($form['status']) ?></strong></div>
        </div>
    </div>

    <!-- Employee Metadata Grid -->
    <div class="info-grid">
        <div class="info-item">
            <strong>Employee Name</strong>
            <span><?= htmlspecialchars($form['employee_name']) ?></span>
        </div>
        <div class="info-item">
            <strong>Username / ID</strong>
            <span>@<?= htmlspecialchars($form['employee_username']) ?> (#<?= $form['employee_id'] ?>)</span>
        </div>
        <div class="info-item">
            <strong>Department</strong>
            <span><?= htmlspecialchars($form['dept_name'] ?? 'Not Assigned') ?></span>
        </div>
        <div class="info-item">
            <strong>Reporting Line Manager</strong>
            <span><?= htmlspecialchars($form['manager_name'] ?? 'System Administrator') ?></span>
        </div>
    </div>

    <!-- Scorecards Summary -->
    <div class="scorecards">
        <div class="score-card">
            <div class="score-label">Self-Assessment Average</div>
            <div class="score-num" style="color: #64748b;">
                <?= ($form['employee_overall_rating'] !== null) ? number_format($form['employee_overall_rating'], 1) . '%' : 'N/A' ?>
            </div>
            <div style="font-size:11px; color:#94a3b8; margin-top:4px;">Employee Output Rating</div>
        </div>

        <div class="score-card">
            <div class="score-label">Line Manager Score</div>
            <div class="score-num" style="color: #0369a1;">
                <?= ($form['manager_overall_rating'] !== null) ? number_format($form['manager_overall_rating'], 1) . '%' : 'Pending' ?>
            </div>
            <div style="font-size:11px; color:#94a3b8; margin-top:4px;">Evaluated Manager Rating</div>
        </div>

        <div class="score-card highlight">
            <div class="score-label">Final Performance Average</div>
            <div class="score-num">
                <?= number_format($final_score, 1) ?>%
            </div>
            <span class="score-grade" style="background: <?= $perf_badge['bg'] ?>; color: <?= $perf_badge['color'] ?>;">
                <?= $perf_badge['grade'] ?>
            </span>
        </div>
    </div>

    <!-- Detailed Performance Matrix -->
    <h3 class="section-title">Performance Matrix: Tasks, Outputs & Evaluations</h3>

    <table class="report-table">
        <thead>
            <tr>
                <th style="width: 35%;">Task / Output Description</th>
                <th style="width: 10%;">Weight</th>
                <th style="width: 25%;">Employee Self-Evaluation</th>
                <th style="width: 30%;">Line Manager Assessment</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($tasks as $i => $t): ?>
                <tr class="task-row-header">
                    <td colspan="4">
                        Task <?= $i + 1 ?>: <?= htmlspecialchars($t['task_description']) ?> 
                        <span style="font-weight: normal; color: #64748b; font-size: 11px;">(Weight: <?= number_format($t['task_weight'], 1) ?>%)</span>
                    </td>
                </tr>

                <?php if (!empty($t['outputs'])): ?>
                    <?php foreach ($t['outputs'] as $j => $o): ?>
                    <tr>
                        <td style="padding-left: 24px;">
                            • <?= htmlspecialchars($o['output_description']) ?>
                        </td>
                        <td><?= number_format($o['output_weight'], 1) ?>%</td>
                        <td>
                            <strong><?= ($o['employee_rating'] !== null) ? number_format($o['employee_rating'], 1) . '%' : '—' ?></strong>
                            <?php if (!empty($o['employee_comment'])): ?>
                                <div style="font-size: 11px; color: #555; margin-top: 2px;"><em>"<?= htmlspecialchars($o['employee_comment']) ?>"</em></div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong style="color: #0369a1;"><?= ($o['manager_rating'] !== null) ? number_format($o['manager_rating'], 1) . '%' : '—' ?></strong>
                            <?php if (!empty($o['manager_comment'])): ?>
                                <div style="font-size: 11px; color: #555; margin-top: 2px;"><em>"<?= htmlspecialchars($o['manager_comment']) ?>"</em></div>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>

                <!-- Task Average Row -->
                <tr class="task-summary-row">
                    <td colspan="2" style="text-align: right;">Task #<?= $i + 1 ?> Average Scores:</td>
                    <td>
                        Self-Average: <strong><?= ($t['employee_rating'] !== null) ? number_format($t['employee_rating'], 1) . '%' : '—' ?></strong>
                    </td>
                    <td>
                        Manager Task Average: <strong style="color: var(--primary);"><?= ($t['manager_rating'] !== null) ? number_format($t['manager_rating'], 1) . '%' : '—' ?></strong>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Qualitative Feedback -->
    <h3 class="section-title">Appraisal Feedback & Comments</h3>
    
    <div class="feedback-box">
        <h4>Employee Self-Assessment Reflection:</h4>
        <div class="feedback-content">
            <?= (!empty($form['employee_overall_comment'])) ? nl2br(htmlspecialchars($form['employee_overall_comment'])) : '<em>No overall comments provided.</em>' ?>
        </div>
    </div>

    <div class="feedback-box" style="background: #fff8f8; border-color: #fecaca;">
        <h4 style="color: var(--primary);">Line Manager Evaluation Feedback & Recommendations:</h4>
        <div class="feedback-content">
            <?= (!empty($form['manager_overall_comment'])) ? nl2br(htmlspecialchars($form['manager_overall_comment'])) : '<em>No overall manager comments recorded.</em>' ?>
        </div>
    </div>

    <!-- Signatures Section -->
    <div class="signatures">
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-title"><?= htmlspecialchars($form['employee_name']) ?></div>
            <div class="sig-date">Employee Signature / Date</div>
        </div>
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-title"><?= htmlspecialchars($form['manager_name'] ?? 'Line Manager') ?></div>
            <div class="sig-date">Line Manager Signature / Date</div>
        </div>
        <div class="sig-block">
            <div class="sig-line"></div>
            <div class="sig-title">HR Management / Executive</div>
            <div class="sig-date">Authorised Sign-off / Date</div>
        </div>
    </div>
</div>

</body>
</html>
