<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['linemanager', 'admin'])) {
    header("Location: index.html");
    exit;
}
require_once 'db.php';

$form_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if (!$form_id) {
    die("Invalid KPI form ID.");
}

$user_id = $_SESSION['user_id'];
$is_admin = ($_SESSION['role'] === 'admin');
$is_impersonating = isset($_SESSION['admin_impersonator_id']);

// Fetch the form and verify authorization
if ($is_admin) {
    $form_query = $conn->prepare("
        SELECT f.*, u.fullname AS employee_name, u.username AS employee_username, u.email AS employee_email,
               m.fullname AS manager_name, d.name AS dept_name
        FROM kpi_forms f
        JOIN users u ON f.employee_id = u.id
        LEFT JOIN users m ON f.manager_id = m.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE f.id = ?
    ");
    $form_query->bind_param("i", $form_id);
} else {
    // Line Managers: authorized to review Staff (role = 'employee') in their team or department
    $dept_id = intval($_SESSION['department_id'] ?? 0);
    $form_query = $conn->prepare("
        SELECT f.*, u.fullname AS employee_name, u.username AS employee_username, u.email AS employee_email,
               m.fullname AS manager_name, d.name AS dept_name
        FROM kpi_forms f
        JOIN users u ON f.employee_id = u.id
        LEFT JOIN users m ON f.manager_id = m.id
        LEFT JOIN departments d ON u.department_id = d.id
        WHERE f.id = ? AND u.role = 'employee' AND (f.manager_id = ? OR u.manager_id = ? OR u.department_id = ?)
    ");
    $form_query->bind_param("iiii", $form_id, $user_id, $user_id, $dept_id);
}

$form_query->execute();
$form = $form_query->get_result()->fetch_assoc();
$form_query->close();

if (!$form) {
    die("You are not authorized to review this KPI form.");
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

// Handle Form Submission (Manager ratings per output and finalization)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_review'])) {
    $total_mgr_weighted = 0;
    $total_task_weight = 0;
    $simple_mgr_sum = 0;
    $task_count = 0;

    foreach ($tasks as $t) {
        $tid = $t['id'];
        $task_weight = floatval($t['task_weight']);
        $outputs = $t['outputs'];
        $task_mgr_avg = 0;

        if (!empty($outputs)) {
            $out_weighted_sum = 0;
            $out_weight_sum = 0;
            $out_simple_sum = 0;
            $out_count = count($outputs);

            foreach ($outputs as $o) {
                $oid = $o['id'];
                $o_weight = floatval($o['output_weight']);
                $m_rating = isset($_POST['manager_output_rating_' . $oid]) ? floatval($_POST['manager_output_rating_' . $oid]) : 0;
                $m_comment = trim($_POST['manager_output_comment_' . $oid] ?? '');

                // Clamp rating between 0 and 100
                $m_rating = max(0, min(100, $m_rating));

                // Save manager rating and feedback per output
                $stmt = $conn->prepare("UPDATE kpi_outputs SET manager_rating = ?, manager_comment = ? WHERE id = ?");
                $stmt->bind_param("dsi", $m_rating, $m_comment, $oid);
                $stmt->execute();
                $stmt->close();

                if ($o_weight > 0) {
                    $out_weighted_sum += ($m_rating * $o_weight);
                    $out_weight_sum += $o_weight;
                }
                $out_simple_sum += $m_rating;
            }

            if ($out_weight_sum > 0) {
                $task_mgr_avg = $out_weighted_sum / $out_weight_sum;
            } else {
                $task_mgr_avg = $out_simple_sum / max(1, $out_count);
            }
        } else {
            $task_mgr_avg = floatval($_POST['manager_task_rating_' . $tid] ?? 0);
            $task_mgr_avg = max(0, min(100, $task_mgr_avg));
            $m_task_comm = trim($_POST['manager_task_comment_' . $tid] ?? '');

            $stmt = $conn->prepare("UPDATE kpi_tasks SET manager_comment = ? WHERE id = ?");
            $stmt->bind_param("si", $m_task_comm, $tid);
            $stmt->execute();
            $stmt->close();
        }

        $task_mgr_avg = round($task_mgr_avg, 2);
        $emp_task_score = floatval($t['employee_rating'] ?? 0);
        
        // Final task score: authoritative manager average (or combined average)
        $final_task_score = $task_mgr_avg;

        // Update task manager average and final task score
        $stmt = $conn->prepare("UPDATE kpi_tasks SET manager_rating = ?, final_task_score = ? WHERE id = ?");
        $stmt->bind_param("ddi", $task_mgr_avg, $final_task_score, $tid);
        $stmt->execute();
        $stmt->close();

        if ($task_weight > 0) {
            $total_mgr_weighted += ($task_mgr_avg * $task_weight);
            $total_task_weight += $task_weight;
        }
        $simple_mgr_sum += $task_mgr_avg;
        $task_count++;
    }

    // Calculate overall performance average: Average from the task averages calculated from the tasks
    $overall_perf_avg = ($task_count > 0) ? round($simple_mgr_sum / $task_count, 2) : 0;

    // Allow manager to accept computed score or use manual input if specified
    if (isset($_POST['manager_overall_rating']) && $_POST['manager_overall_rating'] !== '') {
        $overall_perf_avg = round(floatval($_POST['manager_overall_rating']), 2);
    }

    $overall_comment = trim($_POST['manager_overall_comment'] ?? '');
    $status = 'reviewed';

    // Update kpi_forms
    $status_update = $conn->prepare("
        UPDATE kpi_forms 
        SET status = ?, manager_overall_rating = ?, final_performance_score = ?, manager_overall_comment = ?, manager_id = ?
        WHERE id = ?
    ");
    $status_update->bind_param("sddsii", $status, $overall_perf_avg, $overall_perf_avg, $overall_comment, $user_id, $form_id);
    $status_update->execute();
    $status_update->close();

    $redirect = ($is_admin) ? 'admindash.php' : 'managerdash.php';
    header("Location: kpi_report.php?id=" . $form_id . "&msg=" . urlencode("Performance review completed successfully."));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Manager KPI Review – <?= htmlspecialchars($form['employee_name']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --primary: #8B0000;
        --primary-dark: #600000;
        --border: #e2e8f0;
    }
    * { box-sizing: border-box; }
    body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #8B0000, #D40000); min-height: 100vh; padding: 30px 20px; margin: 0; }
    
    .impersonation-bar { background: #ffc107; color: #000; padding: 10px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; font-weight: 500; font-size: 13px; }
    .impersonation-bar a { background: #000; color: #fff; padding: 5px 12px; border-radius: 4px; text-decoration: none; font-weight: 600; }

    .container { width: 100%; max-width: 1100px; margin: 0 auto; background: white; padding: 35px 40px; border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.25); }
    
    .header-bar { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; border-bottom: 2px solid #f0f0f0; padding-bottom: 15px; }
    .header-bar h2 { color: var(--primary); margin: 0 0 5px 0; }
    .header-bar p { font-size: 13px; color: #666; margin: 0; }

    .primary-btn { padding: 10px 20px; background: var(--primary); color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: background 0.2s; }
    .primary-btn:hover { background: var(--primary-dark); }
    .btn-secondary { background: #666; }
    .btn-secondary:hover { background: #444; }

    /* Summary Bar */
    .emp-meta-box { background: #f8fafc; border: 1px solid var(--border); border-radius: 10px; padding: 18px 24px; margin-bottom: 25px; display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px; font-size: 13px; }
    .emp-meta-box div strong { display: block; color: #475569; font-size: 11px; text-transform: uppercase; margin-bottom: 2px; }
    .emp-meta-box div span { font-weight: 600; color: #0f172a; font-size: 15px; }

    /* Task Card */
    .task-card { border: 1px solid var(--border); border-radius: 12px; margin-bottom: 30px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
    .task-header { background: #f1f5f9; padding: 16px 20px; border-bottom: 1px solid var(--border); display: flex; justify-content: space-between; align-items: center; }
    .task-header h4 { margin: 0; color: #1e293b; font-size: 16px; font-weight: 600; }
    .task-weight-tag { background: #e0e7ff; color: #3730a3; padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }

    .task-body { padding: 20px; }
    
    /* Output Evaluation Item */
    .output-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 18px; margin-bottom: 18px; }
    .output-title { font-weight: 600; font-size: 14px; color: #1e293b; margin-bottom: 12px; display: flex; justify-content: space-between; }
    .output-weight { font-size: 11px; background: #e0f2fe; color: #0369a1; padding: 2px 8px; border-radius: 4px; }
    
    .evaluation-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    
    .employee-pane { background: #f8fafc; border-left: 3px solid #64748b; padding: 12px 16px; border-radius: 6px; }
    .employee-pane .label { font-size: 11px; font-weight: 600; color: #64748b; text-transform: uppercase; margin-bottom: 4px; }
    .employee-pane .score { font-size: 18px; font-weight: 700; color: var(--primary); margin-bottom: 6px; }
    .employee-pane .comment { font-size: 13px; color: #334155; font-style: italic; }

    .manager-pane { background: #fff8f8; border-left: 3px solid var(--primary); padding: 12px 16px; border-radius: 6px; }
    .manager-pane label { display: block; font-size: 12px; font-weight: 600; color: #333; margin-bottom: 4px; }
    
    input[type=number], textarea { width: 100%; padding: 10px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-family: 'Poppins', sans-serif; font-size: 13px; box-sizing: border-box; background: white; }
    input[type=number]:focus, textarea:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 2px rgba(139,0,0,0.1); }
    textarea { height: 60px; resize: vertical; }

    .task-average-footer { margin-top: 15px; padding: 12px 16px; background: #e2e8f0; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; font-weight: 600; color: #1e293b; }

    /* Overall Review Section */
    .overall-review-section { background: #fff8f8; border: 2px solid var(--primary); border-radius: 12px; padding: 25px; margin-top: 30px; }
    .overall-review-section h3 { color: var(--primary); margin-top: 0; margin-bottom: 15px; }

    @media (max-width: 768px) {
        .container { padding: 20px; }
        .evaluation-grid { grid-template-columns: 1fr; }
        .emp-meta-box { grid-template-columns: 1fr 1fr; }
    }
</style>
</head>
<body>

<?php if ($is_impersonating): ?>
<div class="impersonation-bar">
    <span>⚠️ You are currently operating under Administrator Impersonation mode.</span>
    <a href="admin_switch.php?action=revert">← Return to Administrator Account</a>
</div>
<?php endif; ?>

<div class="container">
    <div class="header-bar">
        <div>
            <h2>📝 Line Manager Performance Evaluation</h2>
            <p>Assess employee output achievements, provide manager ratings and feedback, and generate the final performance report.</p>
        </div>
        <div style="display:flex; gap:10px;">
            <?php if ($form['status'] === 'reviewed'): ?>
                <a href="kpi_report.php?id=<?= $form_id ?>" class="primary-btn" style="background:#28a745;" target="_blank">📄 View Official Report</a>
            <?php endif; ?>
            <a href="<?= $is_admin ? 'admindash.php' : 'managerdash.php' ?>" class="primary-btn btn-secondary">← Back to Dashboard</a>
        </div>
    </div>

    <!-- Employee Information Header -->
    <div class="emp-meta-box">
        <div>
            <strong>Staff Member</strong>
            <span><?= htmlspecialchars($form['employee_name']) ?></span>
        </div>
        <div>
            <strong>Department</strong>
            <span><?= htmlspecialchars($form['dept_name'] ?? 'N/A') ?></span>
        </div>
        <div>
            <strong>Current Status</strong>
            <span style="color: var(--primary);"><?= strtoupper($form['status']) ?></span>
        </div>
        <div>
            <strong>Staff Self-Rating Average</strong>
            <span style="color: #0284c7;"><?= ($form['employee_overall_rating'] !== null) ? number_format($form['employee_overall_rating'], 1) . '%' : 'Pending' ?></span>
        </div>
    </div>

    <?php if (!empty($form['employee_overall_comment'])): ?>
    <div style="margin-bottom: 25px; padding: 14px 18px; background: #eff6ff; border-left: 4px solid #3b82f6; border-radius: 8px; font-size: 13px;">
        <strong style="color: #1e40af;">Employee's Overall Reflection / Comments:</strong><br>
        <span style="color: #1e293b;"><?= nl2br(htmlspecialchars($form['employee_overall_comment'])) ?></span>
    </div>
    <?php endif; ?>

    <?php if (empty($tasks)): ?>
        <p style="text-align:center; color:#666; margin: 40px 0;">No tasks found in this workbook.</p>
    <?php else: ?>
        <form method="POST" action="review_kpi.php?id=<?= $form_id ?>" id="reviewForm">
            <?php foreach ($tasks as $i => $t): ?>
            <div class="task-card">
                <div class="task-header">
                    <div>
                        <h4>Task <?= $i + 1 ?>: <?= htmlspecialchars($t['task_description']) ?></h4>
                    </div>
                    <span class="task-weight-tag">Weight: <?= number_format($t['task_weight'], 1) ?>%</span>
                </div>

                <div class="task-body">
                    <?php if (!empty($t['outputs'])): ?>
                        <?php foreach ($t['outputs'] as $j => $o): ?>
                        <div class="output-card">
                            <div class="output-title">
                                <span>Output <?= ($i + 1) . '.' . ($j + 1) ?>: <?= htmlspecialchars($o['output_description']) ?></span>
                                <span class="output-weight">Weight: <?= number_format($o['output_weight'], 1) ?>%</span>
                            </div>

                            <div class="evaluation-grid">
                                <!-- Employee Evaluation View -->
                                <div class="employee-pane">
                                    <div class="label">Employee Self-Rating</div>
                                    <div class="score"><?= ($o['employee_rating'] !== null) ? number_format($o['employee_rating'], 1) . '%' : 'Not rated' ?></div>
                                    <div class="label" style="margin-top:8px;">Employee Notes:</div>
                                    <div class="comment"><?= htmlspecialchars($o['employee_comment'] ?: 'No notes provided.') ?></div>
                                </div>

                                <!-- Manager Rating & Comment Input -->
                                <div class="manager-pane">
                                    <div style="margin-bottom:10px;">
                                        <label>Manager Rating per Output (0 - 100%)</label>
                                        <input type="number" step="0.01" min="0" max="100" 
                                               name="manager_output_rating_<?= $o['id'] ?>" 
                                               value="<?= htmlspecialchars($o['manager_rating'] ?? $o['employee_rating'] ?? '') ?>" 
                                               required 
                                               class="mgr-output-rating" 
                                               data-task="<?= $t['id'] ?>" 
                                               data-weight="<?= $o['output_weight'] ?>"
                                               placeholder="e.g. 85">
                                    </div>
                                    <div>
                                        <label>Manager Feedback / Justification</label>
                                        <textarea name="manager_output_comment_<?= $o['id'] ?>" placeholder="Specific feedback on this output..."><?= htmlspecialchars($o['manager_comment'] ?? '') ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <!-- Fallback for task without separate outputs -->
                        <div class="evaluation-grid">
                            <div class="employee-pane">
                                <div class="label">Employee Self-Rating</div>
                                <div class="score"><?= ($t['employee_rating'] !== null) ? number_format($t['employee_rating'], 1) . '%' : 'Not rated' ?></div>
                                <div class="comment"><?= htmlspecialchars($t['employee_comment'] ?: 'No comment.') ?></div>
                            </div>
                            <div class="manager-pane">
                                <div style="margin-bottom:10px;">
                                    <label>Manager Rating (0 - 100%)</label>
                                    <input type="number" step="0.01" min="0" max="100" name="manager_task_rating_<?= $t['id'] ?>" value="<?= htmlspecialchars($t['manager_rating'] ?? $t['employee_rating'] ?? '') ?>" required>
                                </div>
                                <div>
                                    <label>Manager Feedback</label>
                                    <textarea name="manager_task_comment_<?= $t['id'] ?>"><?= htmlspecialchars($t['manager_comment'] ?? '') ?></textarea>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="task-average-footer">
                        <span>Task #<?= $i + 1 ?> Employee Self-Average: <strong><?= ($t['employee_rating'] !== null) ? number_format($t['employee_rating'], 1) . '%' : 'N/A' ?></strong></span>
                        <span id="task_avg_display_<?= $t['id'] ?>">
                            Current Manager Task Average: <strong style="color: var(--primary);"><?= ($t['manager_rating'] !== null) ? number_format($t['manager_rating'], 1) . '%' : 'Pending calculation' ?></strong>
                        </span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>

            <!-- Overall Evaluation Section -->
            <div class="overall-review-section">
                <h3>Overall Performance Assessment & Final Score</h3>
                <p style="font-size:13px; color:#555; margin-bottom:14px;">
                    The system finds the average score for each task, and then calculates the final performance score from the task averages.
                </p>

                <!-- Live calculation breakdown badge -->
                <div id="final_score_breakdown" style="background:#fef2f2; border:1px solid #fecaca; border-left:4px solid #8B0000; padding:12px 18px; border-radius:8px; margin-bottom:18px; font-size:13px; color:#991b1b; display:flex; justify-content:space-between; align-items:center;">
                    <div>
                        <span id="breakdown_tasks_text">Calculating task averages...</span>
                    </div>
                    <div style="font-size:15px; font-weight:700;" id="breakdown_final_score">
                        Final Score: <?= ($form['manager_overall_rating'] !== null) ? number_format($form['manager_overall_rating'], 2) . '%' : 'Pending calculation' ?>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 220px 1fr; gap: 20px;">
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; margin-bottom:5px;">Final Overall Average (%)</label>
                        <input type="number" step="0.01" min="0" max="100" id="overall_rating_input" name="manager_overall_rating" required 
                               value="<?= htmlspecialchars($form['manager_overall_rating'] ?? '') ?>" 
                               placeholder="Computed from task avgs">
                        <small style="color:#666; font-size:11px; display:block; margin-top:4px;">Average of the task averages.</small>
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; margin-bottom:5px;">Overall Manager Appraisal & Development Comments</label>
                        <textarea name="manager_overall_comment" required style="height:90px;" placeholder="Provide comprehensive feedback on accomplishments, areas of improvement, and recommendations..."><?= htmlspecialchars($form['manager_overall_comment'] ?? '') ?></textarea>
                    </div>
                </div>

                <div style="margin-top: 25px; display: flex; justify-content: space-between; align-items: center;">
                    <a href="<?= $is_admin ? 'admindash.php' : 'managerdash.php' ?>" class="primary-btn btn-secondary">Cancel</a>
                    <button type="submit" name="update_review" class="primary-btn" style="padding: 12px 28px; font-size: 15px;">
                        ✓ Save Evaluation & Publish Performance Report
                    </button>
                </div>
            </div>
        </form>
    <?php endif; ?>
</div>

<script>
// Dynamic calculation of task averages and the average final score
function recalculateScores() {
    const taskGroups = {};

    document.querySelectorAll('.mgr-output-rating').forEach(input => {
        const taskId = input.dataset.task;
        const val = parseFloat(input.value);

        if (!taskGroups[taskId]) {
            taskGroups[taskId] = { sum: 0, count: 0 };
        }
        if (!isNaN(val)) {
            taskGroups[taskId].sum += val;
            taskGroups[taskId].count += 1;
        }
    });

    // Also support any direct task ratings without outputs
    document.querySelectorAll('input[name^="manager_task_rating_"]').forEach(input => {
        const match = input.name.match(/manager_task_rating_(\d+)/);
        if (match) {
            const taskId = match[1];
            if (!taskGroups[taskId]) {
                const val = parseFloat(input.value) || 0;
                taskGroups[taskId] = { sum: val, count: 1 };
            }
        }
    });

    let totalTaskAvgSum = 0;
    let taskCount = 0;
    const taskAveragesText = [];

    let taskIdx = 1;
    for (const taskId in taskGroups) {
        const grp = taskGroups[taskId];
        let taskAvg = 0;
        if (grp.count > 0) {
            taskAvg = grp.sum / grp.count;
        }

        totalTaskAvgSum += taskAvg;
        taskCount++;
        taskAveragesText.push(`Task ${taskIdx}: <strong>${taskAvg.toFixed(2)}%</strong>`);

        const displayEl = document.getElementById('task_avg_display_' + taskId);
        if (displayEl) {
            displayEl.innerHTML = `Calculated Manager Task Average: <strong style="color:#8B0000;">${taskAvg.toFixed(2)}%</strong>`;
        }
        taskIdx++;
    }

    // Calculate final overall score from the task averages
    if (taskCount > 0) {
        const finalAvg = totalTaskAvgSum / taskCount;
        const overallInput = document.getElementById('overall_rating_input');
        if (overallInput) {
            overallInput.value = finalAvg.toFixed(2);
        }

        const breakdownTasksText = document.getElementById('breakdown_tasks_text');
        if (breakdownTasksText) {
            breakdownTasksText.innerHTML = `Average of Tasks: ${taskAveragesText.join(' &nbsp;+&nbsp; ')} &nbsp;÷&nbsp; ${taskCount}`;
        }

        const breakdownFinalScore = document.getElementById('breakdown_final_score');
        if (breakdownFinalScore) {
            breakdownFinalScore.innerHTML = `Final Score: ${finalAvg.toFixed(2)}%`;
        }
    }
}

document.querySelectorAll('.mgr-output-rating, input[name^="manager_task_rating_"]').forEach(input => {
    input.addEventListener('input', recalculateScores);
    input.addEventListener('change', recalculateScores);
});

// Run calculation immediately on page load
document.addEventListener('DOMContentLoaded', recalculateScores);
window.addEventListener('load', recalculateScores);
</script>

</body>
</html>