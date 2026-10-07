<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$is_impersonating = isset($_SESSION['admin_impersonator_id']);

// Get user info and manager
$user_q = $conn->query("SELECT fullname, username, manager_id FROM users WHERE id = $user_id");
$user_info = $user_q->fetch_assoc();
$manager_id = $user_info['manager_id'] ?? 0;

if (!$manager_id && $_SESSION['role'] === 'employee') {
    die("You do not have a line manager assigned. Please contact the administrator.");
}

// Get the latest KPI form
$form_q = $conn->query("SELECT * FROM kpi_forms WHERE employee_id = $user_id ORDER BY id DESC LIMIT 1");
$form = $form_q->fetch_assoc();

if (!$form) {
    $form_id = 0;
    $status = 'none';
} else {
    $form_id = $form['id'];
    $status = $form['status'];
}

// Fetch tasks and outputs
$tasks = [];
if ($form_id > 0) {
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
}

// Handle Submit Review (Employee self-rating per output)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_review']) && $form_id > 0) {
    $total_task_weighted_score = 0;
    $total_task_weight = 0;
    $simple_task_score_sum = 0;
    $task_count = 0;

    foreach ($tasks as $t) {
        $tid = $t['id'];
        $task_weight = floatval($t['task_weight']);
        $outputs = $t['outputs'];
        $task_emp_avg = 0;

        if (!empty($outputs)) {
            $out_weighted_sum = 0;
            $out_weight_sum = 0;
            $out_simple_sum = 0;
            $out_count = count($outputs);

            foreach ($outputs as $o) {
                $oid = $o['id'];
                $o_weight = floatval($o['output_weight']);
                $o_rating = isset($_POST['output_rating_' . $oid]) ? floatval($_POST['output_rating_' . $oid]) : 0;
                $o_comment = trim($_POST['output_comment_' . $oid] ?? '');

                // Clamp between 0 and 100
                $o_rating = max(0, min(100, $o_rating));

                // Update output record
                $stmt = $conn->prepare("UPDATE kpi_outputs SET employee_rating = ?, employee_comment = ? WHERE id = ?");
                $stmt->bind_param("dsi", $o_rating, $o_comment, $oid);
                $stmt->execute();
                $stmt->close();

                if ($o_weight > 0) {
                    $out_weighted_sum += ($o_rating * $o_weight);
                    $out_weight_sum += $o_weight;
                }
                $out_simple_sum += $o_rating;
            }

            if ($out_weight_sum > 0) {
                $task_emp_avg = $out_weighted_sum / $out_weight_sum;
            } else {
                $task_emp_avg = $out_simple_sum / max(1, $out_count);
            }
        } else {
            // If task has no defined outputs, read task-level fallback
            $task_emp_avg = floatval($_POST['rating_' . $tid] ?? 0);
            $task_emp_avg = max(0, min(100, $task_emp_avg));
            $task_comment = trim($_POST['comment_' . $tid] ?? '');
            
            $stmt = $conn->prepare("UPDATE kpi_tasks SET employee_comment = ? WHERE id = ?");
            $stmt->bind_param("si", $task_comment, $tid);
            $stmt->execute();
            $stmt->close();
        }

        // Round task average score
        $task_emp_avg = round($task_emp_avg, 2);

        // Update task employee average rating
        $stmt = $conn->prepare("UPDATE kpi_tasks SET employee_rating = ? WHERE id = ?");
        $stmt->bind_param("di", $task_emp_avg, $tid);
        $stmt->execute();
        $stmt->close();

        if ($task_weight > 0) {
            $total_task_weighted_score += ($task_emp_avg * $task_weight);
            $total_task_weight += $task_weight;
        }
        $simple_task_score_sum += $task_emp_avg;
        $task_count++;
    }

    // Calculate overall employee performance average: Average from the task averages calculated from the tasks
    $overall_emp_avg = ($task_count > 0) ? round($simple_task_score_sum / $task_count, 2) : 0;

    $emp_overall_comment = trim($_POST['employee_overall_comment'] ?? '');

    // Update form status to submitted
    $stmt = $conn->prepare("UPDATE kpi_forms SET status = 'submitted', employee_overall_rating = ?, employee_overall_comment = ? WHERE id = ?");
    $stmt->bind_param("dsi", $overall_emp_avg, $emp_overall_comment, $form_id);
    $stmt->execute();
    $stmt->close();

    header("Location: my_kpi.php?msg=submitted");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>My KPI Performance Review - Times of Eswatini</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root {
        --primary: #8B0000;
        --primary-dark: #600000;
        --bg-gray: #f4f6f9;
        --border-color: #e2e8f0;
    }
    * { box-sizing: border-box; }
    body { font-family: 'Poppins', sans-serif; background: linear-gradient(135deg, #8B0000, #D40000); min-height: 100vh; padding: 30px 20px; margin: 0; }
    
    .impersonation-bar { background: #ffc107; color: #000; padding: 10px 20px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; font-weight: 500; font-size: 13px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
    .impersonation-bar a { background: #000; color: #fff; padding: 5px 12px; border-radius: 4px; text-decoration: none; font-weight: 600; }

    .container { width: 100%; max-width: 1050px; margin: 0 auto; background: white; padding: 35px 40px; border-radius: 20px; box-shadow: 0 20px 50px rgba(0,0,0,0.25); }
    
    .header-bar { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 25px; border-bottom: 2px solid #f0f0f0; padding-bottom: 15px; }
    .header-bar h2 { color: var(--primary); margin: 0 0 5px 0; }
    .header-bar p { font-size: 13px; color: #666; margin: 0; }
    
    .primary-btn { padding: 10px 20px; background: var(--primary); color: white; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 6px; transition: background 0.2s; }
    .primary-btn:hover { background: var(--primary-dark); }
    .btn-secondary { background: #666; }
    .btn-secondary:hover { background: #444; }
    .btn-success { background: #28a745; }
    .btn-success:hover { background: #1e7e34; }

    .alert { padding: 12px 18px; background: #d4edda; color: #155724; margin-bottom: 20px; border-radius: 8px; font-size: 14px; border: 1px solid #c3e6cb; }
    
    /* Summary Scorecard */
    .summary-card { background: #fdfaf6; border: 2px solid #f3e5d8; border-radius: 12px; padding: 20px; margin-bottom: 25px; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
    .score-box { text-align: center; padding: 10px; border-right: 1px solid #eee; }
    .score-box:last-child { border-right: none; }
    .score-title { font-size: 12px; font-weight: 600; color: #666; text-transform: uppercase; margin-bottom: 6px; }
    .score-value { font-size: 26px; font-weight: 700; color: var(--primary); }
    .badge-status { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 600; }
    .status-assigned { background: #e2e8f0; color: #475569; }
    .status-submitted { background: #fef3c7; color: #92400e; }
    .status-reviewed { background: #dcfce7; color: #166534; }

    /* Task Card */
    .task-card { border: 1px solid #e2e8f0; border-radius: 12px; margin-bottom: 30px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.03); }
    .task-header { background: #f8fafc; padding: 16px 20px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
    .task-header h4 { margin: 0; color: #1e293b; font-size: 16px; font-weight: 600; }
    .task-meta { font-size: 13px; color: #64748b; }
    .task-badge { background: #ede9fe; color: #5b21b6; padding: 4px 10px; border-radius: 6px; font-weight: 600; font-size: 12px; }

    .task-body { padding: 20px; }
    
    /* Output Table */
    .output-item { background: #ffffff; border: 1px solid #edf2f7; border-radius: 10px; padding: 16px; margin-bottom: 15px; }
    .output-item:last-child { margin-bottom: 0; }
    .output-desc { font-weight: 500; font-size: 14px; color: #2d3748; margin-bottom: 10px; display: flex; justify-content: space-between; align-items: center; }
    .output-weight-badge { font-size: 11px; background: #e0f2fe; color: #0369a1; padding: 3px 8px; border-radius: 4px; font-weight: 600; }
    
    .ratings-row { display: grid; grid-template-columns: 140px 1fr; gap: 15px; margin-top: 10px; }
    .ratings-row label { display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 4px; }
    input[type=number], textarea { width: 100%; padding: 10px 12px; border-radius: 6px; border: 1px solid #cbd5e1; font-family: 'Poppins', sans-serif; font-size: 13px; box-sizing: border-box; }
    input[type=number]:focus, textarea:focus { border-color: var(--primary); outline: none; box-shadow: 0 0 0 2px rgba(139,0,0,0.1); }
    textarea { height: 50px; resize: vertical; }

    .task-avg-bar { margin-top: 15px; padding: 12px 16px; background: #f1f5f9; border-radius: 8px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; font-weight: 600; color: #334155; }

    .overall-comment-box { background: #fdfdfd; border: 1px solid #e2e8f0; border-radius: 10px; padding: 20px; margin-top: 25px; }
    .overall-comment-box h4 { margin-top: 0; margin-bottom: 10px; color: #333; }

    @media (max-width: 768px) {
        .container { padding: 20px; }
        .ratings-row { grid-template-columns: 1fr; }
        .header-bar { flex-direction: column; gap: 10px; }
        .summary-card { grid-template-columns: 1fr; }
        .score-box { border-right: none; border-bottom: 1px solid #eee; }
    }
</style>
</head>
<body>

<?php if ($is_impersonating): ?>
<div class="impersonation-bar">
    <span>⚠️ You are currently viewing this portal as Administrator viewing <strong><?= htmlspecialchars($_SESSION['fullname']) ?></strong>.</span>
    <a href="admin_switch.php?action=revert">← Return to Administrator Account</a>
</div>
<?php endif; ?>

<div class="container">
    <div class="header-bar">
        <div>
            <h2>🎯 My KPI Performance Review</h2>
            <p>Assess your outputs, rate yourself (0 - 100%), and view your performance average.</p>
        </div>
        <div style="display:flex; gap:10px;">
            <?php if ($status === 'reviewed'): ?>
                <a href="kpi_report.php?id=<?= $form_id ?>" class="primary-btn btn-success" target="_blank">📄 View Official Performance Report</a>
            <?php endif; ?>
            <?php if (isset($_SESSION['role']) && $_SESSION['role'] === 'linemanager'): ?>
                <a href="managerdash.php" class="primary-btn btn-secondary">← Back to Manager Portal</a>
            <?php else: ?>
                <a href="dashboard.php" class="primary-btn btn-secondary">← Back to Dashboard</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (isset($_GET['msg']) && $_GET['msg'] === 'submitted'): ?>
        <div class="alert">✓ Your performance evaluation has been successfully submitted to your Line Manager for review!</div>
    <?php endif; ?>

    <?php if ($status === 'none' || empty($tasks)): ?>
        <div style="text-align:center; padding: 60px 20px; color: #64748b;">
            <p style="font-size: 16px; font-weight: 500;">No active KPI workbook found.</p>
            <p style="font-size: 13px;">Your Line Manager has not assigned or published a KPI workbook for you yet.</p>
        </div>
    <?php else: ?>

        <!-- Performance Summary Card -->
        <div class="summary-card">
            <div class="score-box">
                <div class="score-title">Review Status</div>
                <div><span class="badge-status status-<?= $status ?>"><?= strtoupper($status) ?></span></div>
            </div>
            <div class="score-box">
                <div class="score-title">My Self-Rating Average</div>
                <div class="score-value" id="emp_overall_score_val"><?= ($form['employee_overall_rating'] !== null) ? number_format($form['employee_overall_rating'], 1) . '%' : 'Pending' ?></div>
            </div>
            <div class="score-box">
                <div class="score-title">Manager Evaluation Average</div>
                <div class="score-value" style="color: #28a745;">
                    <?= ($form['manager_overall_rating'] !== null) ? number_format($form['manager_overall_rating'], 1) . '%' : 'Pending Review' ?>
                </div>
            </div>
            <div class="score-box">
                <div class="score-title">Final Performance Average</div>
                <div class="score-value" style="color: #0284c7;">
                    <?= ($form['final_performance_score'] !== null) ? number_format($form['final_performance_score'], 1) . '%' : ($status === 'reviewed' ? number_format($form['manager_overall_rating'], 1) . '%' : 'Pending') ?>
                </div>
            </div>
        </div>

        <form action="my_kpi.php" method="POST">
            <?php foreach ($tasks as $idx => $t): ?>
            <div class="task-card">
                <div class="task-header">
                    <div>
                        <h4>Task <?= $idx + 1 ?>: <?= htmlspecialchars($t['task_description']) ?></h4>
                        <div class="task-meta">Task Weight: <strong><?= number_format($t['task_weight'], 1) ?>%</strong> of total evaluation</div>
                    </div>
                    <span class="task-badge" id="task_self_badge_<?= $t['id'] ?>">Task Self-Average: <?= ($t['employee_rating'] !== null) ? number_format($t['employee_rating'], 1) . '%' : 'Pending' ?></span>
                </div>

                <div class="task-body">
                    <?php if (!empty($t['outputs'])): ?>
                        <p style="font-size: 13px; font-weight: 600; color: #475569; margin: 0 0 12px 0;">Please rate your achievement per output (0 - 100%):</p>
                        
                        <?php foreach ($t['outputs'] as $oIdx => $o): ?>
                        <div class="output-item">
                            <div class="output-desc">
                                <span>Output <?= ($idx + 1) . '.' . ($oIdx + 1) ?>: <?= htmlspecialchars($o['output_description']) ?></span>
                                <span class="output-weight-badge">Weight: <?= number_format($o['output_weight'], 1) ?>%</span>
                            </div>

                            <div class="ratings-row">
                                <div>
                                    <label>Self-Rating (0 - 100%)</label>
                                    <?php if ($status !== 'reviewed'): ?>
                                        <input type="number" step="0.01" min="0" max="100" class="emp-output-rating" data-task="<?= $t['id'] ?>" name="output_rating_<?= $o['id'] ?>" value="<?= htmlspecialchars($o['employee_rating'] ?? '') ?>" required placeholder="e.g. 85">
                                    <?php else: ?>
                                        <div style="padding: 10px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 700; color: var(--primary);">
                                            <?= ($o['employee_rating'] !== null) ? number_format($o['employee_rating'], 1) . '%' : 'N/A' ?>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div>
                                    <label>Self Justification / Comment</label>
                                    <?php if ($status !== 'reviewed'): ?>
                                        <textarea name="output_comment_<?= $o['id'] ?>" placeholder="Brief notes on output achievement..."><?= htmlspecialchars($o['employee_comment'] ?? '') ?></textarea>
                                    <?php else: ?>
                                        <div style="padding: 8px 12px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; min-height: 40px; font-size: 13px;">
                                            <?= nl2br(htmlspecialchars($o['employee_comment'] ?: 'No comment provided.')) ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if ($status === 'reviewed' && $o['manager_rating'] !== null): ?>
                            <div style="margin-top: 10px; padding: 8px 12px; background: #f0fdf4; border-left: 3px solid #16a34a; border-radius: 4px; font-size: 12px;">
                                <strong>Line Manager Rating:</strong> <?= number_format($o['manager_rating'], 1) ?>% 
                                <?php if (!empty($o['manager_comment'])): ?>
                                    — <em>"<?= htmlspecialchars($o['manager_comment']) ?>"</em>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>

                    <?php else: ?>
                        <!-- Fallback if task has no separate outputs -->
                        <div class="ratings-row">
                            <div>
                                <label>Self-Rating (0 - 100%)</label>
                                <?php if ($status !== 'reviewed'): ?>
                                    <input type="number" step="0.01" min="0" max="100" class="emp-task-rating" data-task="<?= $t['id'] ?>" name="rating_<?= $t['id'] ?>" value="<?= htmlspecialchars($t['employee_rating'] ?? '') ?>" required placeholder="e.g. 80">
                                <?php else: ?>
                                    <div style="padding: 10px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; font-weight: 700;">
                                        <?= ($t['employee_rating'] !== null) ? number_format($t['employee_rating'], 1) . '%' : 'N/A' ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <label>Justification Comment</label>
                                <?php if ($status !== 'reviewed'): ?>
                                    <textarea name="comment_<?= $t['id'] ?>" placeholder="Explain your rating..."><?= htmlspecialchars($t['employee_comment'] ?? '') ?></textarea>
                                <?php else: ?>
                                    <div style="padding: 8px 12px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; min-height: 40px; font-size: 13px;">
                                        <?= nl2br(htmlspecialchars($t['employee_comment'] ?: 'No comment.')) ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($t['employee_rating'] !== null || $t['manager_rating'] !== null): ?>
                    <div class="task-avg-bar">
                        <span>Task #<?= $idx + 1 ?> Average Score:</span>
                        <span>
                            <?php if ($t['employee_rating'] !== null): ?>
                                Self-Average: <strong><?= number_format($t['employee_rating'], 1) ?>%</strong>
                            <?php endif; ?>
                            <?php if ($t['manager_rating'] !== null): ?>
                                &nbsp;|&nbsp; Manager Average: <strong style="color: #28a745;"><?= number_format($t['manager_rating'], 1) ?>%</strong>
                            <?php endif; ?>
                            <?php if ($t['final_task_score'] !== null): ?>
                                &nbsp;|&nbsp; Combined Task Score: <strong style="color: #0284c7;"><?= number_format($t['final_task_score'], 1) ?>%</strong>
                            <?php endif; ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="overall-comment-box">
                <h4>Overall Self-Assessment Feedback</h4>
                <?php if ($status !== 'reviewed'): ?>
                    <textarea name="employee_overall_comment" style="height: 70px;" placeholder="Optional summary or self-reflection on your overall performance period..."><?= htmlspecialchars($form['employee_overall_comment'] ?? '') ?></textarea>
                <?php else: ?>
                    <div style="padding: 10px; background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; font-size: 13px;">
                        <?= nl2br(htmlspecialchars($form['employee_overall_comment'] ?: 'No overall self-comments provided.')) ?>
                    </div>
                    <?php if (!empty($form['manager_overall_comment'])): ?>
                        <div style="margin-top: 15px; padding: 12px; background: #f0fdf4; border-left: 4px solid #16a34a; border-radius: 6px;">
                            <strong style="color: #166534;">Manager's Overall Evaluation Feedback:</strong><br>
                            <span style="font-size: 13px; color: #1e293b;"><?= nl2br(htmlspecialchars($form['manager_overall_comment'])) ?></span>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

            <?php if ($status !== 'reviewed'): ?>
                <div style="margin-top: 25px; display: flex; justify-content: flex-end; align-items: center; gap: 15px;">
                    <?php if ($status === 'submitted'): ?>
                        <span style="font-size: 13px; color: #d97706; font-weight: 500;">(You can update your output ratings until your Line Manager completes their review)</span>
                    <?php endif; ?>
                    <button type="submit" name="submit_review" class="primary-btn">
                        <?= ($status === 'submitted') ? 'Update My Performance Ratings' : 'Submit Performance Review to Manager' ?>
                    </button>
                </div>
            <?php else: ?>
                <div style="margin-top: 25px; display: flex; justify-content: space-between; align-items: center;">
                    <span class="badge-status status-reviewed" style="font-size: 14px; padding: 8px 16px;">✓ Performance Review Completed & Locked</span>
                    <a href="kpi_report.php?id=<?= $form_id ?>" class="primary-btn btn-success" target="_blank">📄 View Official Performance Report</a>
                </div>
            <?php endif; ?>
        </form>

    <?php endif; ?>
</div>

<script>
// Dynamic live calculation of task averages and overall self-rating score
function recalculateSelfScores() {
    const taskGroups = {};

    document.querySelectorAll('.emp-output-rating').forEach(input => {
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

    document.querySelectorAll('.emp-task-rating').forEach(input => {
        const taskId = input.dataset.task;
        const val = parseFloat(input.value);
        if (!taskGroups[taskId]) {
            taskGroups[taskId] = { sum: isNaN(val) ? 0 : val, count: 1 };
        }
    });

    let totalTaskAvgSum = 0;
    let taskCount = 0;

    for (const taskId in taskGroups) {
        const grp = taskGroups[taskId];
        let taskAvg = 0;
        if (grp.count > 0) {
            taskAvg = grp.sum / grp.count;
        }

        totalTaskAvgSum += taskAvg;
        taskCount++;

        const badgeEl = document.getElementById('task_self_badge_' + taskId);
        if (badgeEl) {
            badgeEl.innerHTML = `Task Self-Average: <strong>${taskAvg.toFixed(1)}%</strong>`;
        }
    }

    if (taskCount > 0) {
        const finalSelfAvg = totalTaskAvgSum / taskCount;
        const overallEl = document.getElementById('emp_overall_score_val');
        if (overallEl) {
            overallEl.innerHTML = `${finalSelfAvg.toFixed(1)}%`;
        }
    }
}

document.querySelectorAll('.emp-output-rating, .emp-task-rating').forEach(input => {
    input.addEventListener('input', recalculateSelfScores);
    input.addEventListener('change', recalculateSelfScores);
});

document.addEventListener('DOMContentLoaded', recalculateSelfScores);
window.addEventListener('load', recalculateSelfScores);
</script>

</body>
</html>
