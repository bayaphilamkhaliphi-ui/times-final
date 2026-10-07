<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['linemanager', 'admin'])) {
    header("Location: index.html");
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$role = $_SESSION['role'];
$dept_id = $_SESSION['department_id'] ?? 0;

// Determine if user can export company-wide or department team only
$is_admin = ($role === 'admin' || isset($_SESSION['admin_impersonator_id']));
$is_hr_manager = false;

if (!$is_admin && $dept_id) {
    $dept_check = $conn->query("SELECT name FROM departments WHERE id = $dept_id");
    if ($dept_check && $d = $dept_check->fetch_assoc()) {
        if (stripos($d['name'], 'hr') !== false || stripos($d['name'], 'human resource') !== false) {
            $is_hr_manager = true;
        }
    }
}

// Build query
$where = "WHERE f.status IN ('submitted', 'reviewed')";
if (!$is_admin && !$is_hr_manager) {
    $where .= " AND (f.manager_id = $user_id OR u.department_id = $dept_id)";
}

$query = "
    SELECT 
        f.id AS form_id,
        u.fullname AS employee_name,
        u.username,
        COALESCE(d.name, 'Unassigned') AS department,
        COALESCE(m.fullname, 'System Administrator') AS manager_name,
        f.status,
        f.employee_overall_rating AS emp_overall_avg,
        COALESCE(f.final_performance_score, f.manager_overall_rating) AS final_perf_score,
        f.manager_overall_comment,
        f.updated_at,
        t.id AS task_id,
        t.task_description,
        t.task_weight,
        t.employee_rating AS task_emp_avg,
        t.manager_rating AS task_mgr_avg,
        t.final_task_score,
        o.output_description,
        o.output_weight,
        o.employee_rating AS output_emp_rating,
        o.employee_comment AS output_emp_comment,
        o.manager_rating AS output_mgr_rating,
        o.manager_comment AS output_mgr_comment
    FROM kpi_forms f
    JOIN users u ON f.employee_id = u.id
    LEFT JOIN users m ON f.manager_id = m.id
    LEFT JOIN departments d ON u.department_id = d.id
    LEFT JOIN kpi_tasks t ON t.kpi_form_id = f.id
    LEFT JOIN kpi_outputs o ON o.kpi_task_id = t.id
    $where
    ORDER BY u.fullname, f.id, t.id, o.id
";

$result = $conn->query($query);
if (!$result) {
    die("Database query error: " . $conn->error);
}

// Set CSV headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="times_hr_performance_reports_' . date('Y-m-d') . '.csv"');

$output = fopen('php://output', 'w');

// CSV Column Headers
fputcsv($output, [
    'Report ID',
    'Employee Name',
    'Username',
    'Department',
    'Line Manager',
    'Status',
    'Overall Self-Average (%)',
    'Overall Final Performance Score (%)',
    'Task Description',
    'Task Weight (%)',
    'Task Employee Average (%)',
    'Task Manager Average (%)',
    'Output Description',
    'Output Weight (%)',
    'Output Employee Rating (%)',
    'Output Employee Comment',
    'Output Manager Rating (%)',
    'Output Manager Comment',
    'Overall Manager Evaluation Comments',
    'Evaluation Date'
]);

while ($row = $result->fetch_assoc()) {
    fputcsv($output, [
        '#KPI-' . str_pad($row['form_id'], 4, '0', STR_PAD_LEFT),
        $row['employee_name'],
        $row['username'],
        $row['department'],
        $row['manager_name'],
        strtoupper($row['status']),
        $row['emp_overall_avg'] !== null ? $row['emp_overall_avg'] . '%' : 'N/A',
        $row['final_perf_score'] !== null ? $row['final_perf_score'] . '%' : 'N/A',
        $row['task_description'] ?? 'N/A',
        $row['task_weight'] !== null ? $row['task_weight'] . '%' : 'N/A',
        $row['task_emp_avg'] !== null ? $row['task_emp_avg'] . '%' : 'N/A',
        $row['task_mgr_avg'] !== null ? $row['task_mgr_avg'] . '%' : 'N/A',
        $row['output_description'] ?? 'N/A',
        $row['output_weight'] !== null ? $row['output_weight'] . '%' : 'N/A',
        $row['output_emp_rating'] !== null ? $row['output_emp_rating'] . '%' : 'N/A',
        $row['output_emp_comment'] ?? '',
        $row['output_mgr_rating'] !== null ? $row['output_mgr_rating'] . '%' : 'N/A',
        $row['output_mgr_comment'] ?? '',
        $row['manager_overall_comment'] ?? '',
        $row['updated_at']
    ]);
}

fclose($output);
exit;