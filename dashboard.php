<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit;
}
require_once 'db.php';

$user_id = $_SESSION['user_id'];
$is_impersonating = isset($_SESSION['admin_impersonator_id']);

// Fetch user's performance reports
$reports = [];
$r_q = $conn->query("
    SELECT f.*, u.fullname AS manager_name 
    FROM kpi_forms f 
    LEFT JOIN users u ON f.manager_id = u.id 
    WHERE f.employee_id = $user_id 
    ORDER BY f.id DESC
");
if ($r_q) {
    while ($r = $r_q->fetch_assoc()) {
        $reports[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Times of Eswatini HR Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins',sans-serif; }
        body { background:#f8f8f8; }
        
        .impersonation-bar { background: #ffc107; color: #000; padding: 12px 40px; display: flex; justify-content: space-between; align-items: center; font-weight: 500; font-size: 13px; }
        .impersonation-bar a { background: #000; color: #fff; padding: 6px 14px; border-radius: 4px; text-decoration: none; font-weight: 600; }

        header { background:#8B0000; color:white; padding:20px 40px; display:grid; grid-template-columns:1fr auto 1fr; align-items:center; }
        .header-title { grid-column:2; text-align:center; font-size:22px; font-weight: 600; }
        nav { grid-column:3; justify-self:end; display:flex; align-items:center; gap:20px; }
        nav a { color:white; text-decoration:none; font-size:14px; }
        nav a:hover { color:#ffd6d6; }
        .container { width:90%; max-width: 1200px; margin:35px auto; }
        .welcome { margin-bottom:30px; }
        .welcome h1 { color:#8B0000; margin-bottom:10px; font-size: 28px; }
        .welcome p { color:#555; margin-bottom:5px; font-size: 14px; }
        .welcome a { color:#8B0000; font-weight:600; text-decoration:underline; }
        .welcome a:hover { color:#600000; }
        
        .repositories { display:grid; grid-template-columns:repeat(auto-fit, minmax(260px,1fr)); gap:25px; margin-bottom: 35px; }
        .repository { background:white; padding:25px; border-radius:12px; border-top:5px solid #8B0000; box-shadow:0 5px 20px rgba(0,0,0,.06); display: flex; flex-direction: column; justify-content: space-between; }
        .repository h2 { color:#8B0000; margin-bottom:10px; font-size: 18px; }
        .repository p { color:#666; margin-bottom:18px; font-size: 13px; flex-grow: 1; }
        button { width:100%; padding:11px; border:none; border-radius:6px; background:#8B0000; color:white; cursor:pointer; font-size:14px; font-weight: 500; transition: background 0.2s; }
        button:hover { background:#600000; }
        
        /* Reports Section */
        .reports-card { background: white; padding: 25px; border-radius: 12px; box-shadow: 0 5px 20px rgba(0,0,0,.06); margin-bottom: 35px; }
        .reports-card h2 { color: #8B0000; font-size: 20px; margin-bottom: 15px; }
        .reports-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 10px; }
        .reports-table th, .reports-table td { padding: 12px 14px; text-align: left; border-bottom: 1px solid #eee; }
        .reports-table th { background: #f8fafc; font-weight: 600; color: #334155; }
        
        .badge { display: inline-block; padding: 3px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; }
        .badge-reviewed { background: #dcfce7; color: #15803d; }
        .badge-submitted { background: #fef9c3; color: #854d0e; }
        .badge-assigned { background: #e0f2fe; color: #0369a1; }
        
        .btn-view-report { padding: 6px 14px; background: #8B0000; color: white; border-radius: 4px; text-decoration: none; font-size: 12px; font-weight: 600; display: inline-block; }
        .btn-view-report:hover { background: #600000; }

        footer { text-align:center; margin:40px; color:#777; font-size: 13px; }
    </style>
</head>
<body>

    <?php if ($is_impersonating): ?>
    <div class="impersonation-bar">
        <span>Administrator Impersonation Mode: Viewing staff portal for <strong><?= htmlspecialchars($_SESSION['fullname']) ?></strong>.</span>
        <a href="admin_switch.php?action=revert">← Return to Admin Account</a>
    </div>
    <?php endif; ?>

    <header>
        <div></div>
        <h2 class="header-title">Times of Eswatini HR Portal</h2>
        <nav>
            <?php if (in_array($_SESSION['role'], ['admin', 'linemanager'])): ?>
                <a href="<?= ($_SESSION['role'] === 'admin') ? 'admindash.php' : 'managerdash.php' ?>" style="background:rgba(255,255,255,0.2); padding:5px 10px; border-radius:4px;">Management View</a>
            <?php endif; ?>
            <a href="logout.php">Logout</a>
        </nav>
    </header>

    <div class="container">
        <div class="welcome">
            <h1>Welcome, <?php echo htmlspecialchars($_SESSION['fullname']); ?></h1>
            <p>Access your KPIs, SOPs, Policies and Performance Reports.</p>
            <p><a href="https://secure.payspace.com/" target="_blank" rel="noopener noreferrer">Click here to visit Payspace.</a></p>
        </div>

        <div class="repositories">
            <div class="repository" style="border-top-color: #8B0000;">
                <h2>My KPI Evaluation</h2>
                <p>Rate your achievement per output and submit your performance review.</p>
                <a href="my_kpi.php">
                    <button style="background: #8B0000;">Fill / View Evaluation</button>
                </a>
            </div>

            <div class="repository">
                <h2>KPIs</h2>
                <p>Departmental KPIs and job specifications for all departments.</p>
                <a href="employee_documents.php?category=KPI">
                    <button>View KPIs</button>
                </a>
            </div>

            <div class="repository">
                <h2>SOPs</h2>
                <p>Standard Operating Procedures for all departments.</p>
                <a href="employee_documents.php?category=SOP">
                    <button>View SOPs</button>
                </a>
            </div>

            <div class="repository">
                <h2>Policies</h2>
                <p>Organisation-wide policies and guidelines.</p>
                <a href="employee_documents.php?category=Policy">
                    <button>View Policies</button>
                </a>
            </div>
        </div>

        <!-- Performance Reports Section for Employee -->
        <div class="reports-card">
            <h2>📄 My Performance Reports</h2>
            <p style="font-size:13px; color:#666;">Track your historical and active performance review evaluations.</p>
            
            <?php if (!empty($reports)): ?>
            <table class="reports-table">
                <thead>
                    <tr>
                        <th>Report ID</th>
                        <th>Reviewing Manager</th>
                        <th>Status</th>
                        <th>Self-Rating Average</th>
                        <th>Final Performance Average</th>
                        <th>Last Updated</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reports as $rep): ?>
                    <tr>
                        <td><strong>#KPI-<?= str_pad($rep['id'], 4, '0', STR_PAD_LEFT) ?></strong></td>
                        <td><?= htmlspecialchars($rep['manager_name'] ?? 'System Administrator') ?></td>
                        <td>
                            <span class="badge badge-<?= $rep['status'] ?>"><?= strtoupper($rep['status']) ?></span>
                        </td>
                        <td><?= ($rep['employee_overall_rating'] !== null) ? number_format($rep['employee_overall_rating'], 1) . '%' : '—' ?></td>
                        <td>
                            <?php if ($rep['status'] === 'reviewed'): ?>
                                <strong style="color: #15803d; font-size:14px;"><?= number_format($rep['final_performance_score'] ?? $rep['manager_overall_rating'] ?? 0, 1) ?>%</strong>
                            <?php else: ?>
                                <span style="color: #94a3b8;">Pending Review</span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('M d, Y', strtotime($rep['updated_at'])) ?></td>
                        <td>
                            <?php if ($rep['status'] === 'reviewed'): ?>
                                <a href="kpi_report.php?id=<?= $rep['id'] ?>" class="btn-view-report" target="_blank">View Official Report</a>
                            <?php else: ?>
                                <a href="my_kpi.php" class="btn-view-report" style="background:#64748b;">Open Review</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
                <p style="color:#777; font-size:13px; margin-top:15px;">No performance evaluations found yet. Your Line Manager will assign your KPI workbook.</p>
            <?php endif; ?>
        </div>

        <footer>&copy; 2026 Times of Eswatini. All rights reserved.</footer>
    </div>
</body>
</html>