<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.html");
    exit;
}
require_once 'db.php';

$status = $_GET['status'] ?? '';
$count  = $_GET['count'] ?? 0;
$msg    = $_GET['msg'] ?? '';
$error  = $_GET['error'] ?? '';
$is_impersonating = isset($_SESSION['admin_impersonator_id']);

// Metric Summaries
$metric_docs = $conn->query("SELECT COUNT(*) AS total FROM documents")->fetch_assoc()['total'] ?? 0;
$metric_agreed = $conn->query("SELECT COUNT(*) AS total FROM employee_documents WHERE status = 'agreed'")->fetch_assoc()['total'] ?? 0;
$metric_viewed = $conn->query("SELECT COUNT(*) AS total FROM employee_documents WHERE status = 'viewed'")->fetch_assoc()['total'] ?? 0;
$metric_users = $conn->query("SELECT COUNT(*) AS total FROM users")->fetch_assoc()['total'] ?? 0;

// Fetch all uploaded documents
$existing_docs = $conn->query("
    SELECT d.*, u.fullname AS uploader_name,
    (SELECT COUNT(*) FROM employee_documents ed WHERE ed.document_id = d.id AND ed.status = 'agreed') AS agreed_count,
    (SELECT COUNT(*) FROM employee_documents ed WHERE ed.document_id = d.id AND ed.status = 'viewed') AS viewed_count
    FROM documents d 
    LEFT JOIN users u ON d.uploaded_by = u.id 
    ORDER BY d.upload_date DESC
");

// Fetch detailed staff agreement reports
$staff_reports = $conn->query("
    SELECT ed.*, u.fullname AS staff_name, u.username AS staff_username, d.title AS doc_title, d.category AS doc_category
    FROM employee_documents ed
    JOIN users u ON ed.employee_id = u.id
    JOIN documents d ON ed.document_id = d.id
    ORDER BY ed.agreed_at DESC, ed.viewed_at DESC
");

// Fetch all departments
$dept_q = $conn->query("SELECT * FROM departments ORDER BY name ASC");
$departments = [];
if ($dept_q) {
    while ($row = $dept_q->fetch_assoc()) $departments[] = $row;
}

// Fetch all system users for Admin account management
$all_users_q = $conn->query("
    SELECT u.*, d.name AS dept_name, m.fullname AS manager_name 
    FROM users u 
    LEFT JOIN departments d ON u.department_id = d.id 
    LEFT JOIN users m ON u.manager_id = m.id 
    ORDER BY FIELD(u.role, 'admin', 'linemanager', 'employee'), u.fullname ASC
");
$all_users = [];
if ($all_users_q) {
    while ($r = $all_users_q->fetch_assoc()) {
        $all_users[] = $r;
    }
}

// Fetch all line managers
$all_managers = array_filter($all_users, function($u) {
    return in_array($u['role'], ['linemanager', 'admin']);
});

// Fetch company-wide performance reports
$company_kpis_q = $conn->query("
    SELECT f.*, u.fullname AS employee_name, u.username AS employee_username,
           m.fullname AS manager_name, d.name AS dept_name 
    FROM kpi_forms f 
    JOIN users u ON f.employee_id = u.id 
    LEFT JOIN users m ON f.manager_id = m.id 
    LEFT JOIN departments d ON u.department_id = d.id 
    WHERE f.status != 'draft'
    ORDER BY f.updated_at DESC
");
$company_kpis = [];
if ($company_kpis_q) {
    while ($r = $company_kpis_q->fetch_assoc()) {
        $company_kpis[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>Times of Eswatini - Admin HR Dashboard & Compliance Portal</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<style>
    :root { 
        --primary-color: #8B0000; 
        --primary-hover: #600000; 
        --text-dark: #1e293b; 
        --text-light: #64748b; 
        --border-color: #e2e8f0; 
        --bg-light: #f4f6f9; 
        --success-bg: #dcfce7;
        --success-color: #15803d;
        --info-bg: #e0f2fe;
        --info-color: #0369a1;
        --warning-bg: #fef9c3;
        --warning-color: #854d0e;
    }
    * { margin:0; padding:0; box-sizing:border-box; font-family:'Poppins',sans-serif; }
    body { background: var(--bg-light); color: var(--text-dark); min-height:100vh; display:flex; flex-direction:column; }
    
    .impersonation-bar { background: #ffc107; color: #000; padding: 12px 40px; display: flex; justify-content: space-between; align-items: center; font-weight: 500; font-size: 13px; }
    .impersonation-bar a { background: #000; color: #fff; padding: 6px 14px; border-radius: 4px; text-decoration: none; font-weight: 600; }

    header { width:100%; background: var(--primary-color); padding:15px 40px; display:flex; justify-content:space-between; align-items:center; color:white; box-shadow:0 4px 10px rgba(0,0,0,0.1); }
    header .brand { font-size:18px; font-weight:700; display:flex; align-items:center; gap:10px; }
    header nav { display:flex; align-items:center; gap:15px; }
    header nav a { color:white; text-decoration:none; font-size:13px; font-weight:500; transition:opacity 0.2s; }
    header nav a:hover { opacity:0.8; }
    header .user-info { font-size:13px; background:rgba(255,255,255,0.15); padding:6px 14px; border-radius:20px; }

    .main-wrapper { max-width:1250px; width:92%; margin:30px auto; display:flex; flex-direction:column; gap:30px; }
    
    /* Summary Cards */
    .metrics-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:20px; }
    .metric-card { background:white; padding:20px 25px; border-radius:12px; box-shadow:0 4px 15px rgba(0,0,0,0.04); border-left:5px solid var(--primary-color); display:flex; justify-content:space-between; align-items:center; }
    .metric-card.users { border-left-color: #6366f1; }
    .metric-card.agreed { border-left-color: #22c55e; }
    .metric-card.viewed { border-left-color: #0ea5e9; }
    .metric-title { font-size:12px; color:var(--text-light); font-weight:600; text-transform:uppercase; letter-spacing:0.5px; }
    .metric-value { font-size:28px; font-weight:700; color:var(--text-dark); margin-top:4px; }

    .card { background:white; border-radius:14px; box-shadow:0 6px 20px rgba(0,0,0,0.04); padding:28px; }
    .portal-header { border-bottom:2px solid #f0f0f0; padding-bottom:12px; margin-bottom:20px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; }
    .portal-header h2 { color:var(--primary-color); font-size:20px; font-weight:700; margin:0; }
    .portal-header span { font-size:12px; color:var(--text-light); background:var(--bg-light); padding:4px 12px; border-radius:20px; }

    .alert { padding:12px 18px; border-radius:8px; margin-bottom:15px; font-size:14px; font-weight:500; text-align:center; }
    .alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .alert-error { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }
    .alert-info { background:#d1ecf1; color:#0c5460; border:1px solid #bee5eb; }

    /* Form Styling */
    .form-row { display:grid; grid-template-columns:1fr 1fr; gap:18px; }
    .form-group { margin-bottom:15px; }
    label { display:block; font-weight:500; color:var(--text-dark); margin-bottom:5px; font-size:13px; }
    input[type=text], input[type=email], input[type=password], textarea, select { width:100%; padding:10px 14px; border:1px solid var(--border-color); border-radius:6px; font-size:13px; color:var(--text-dark); background:#fff; font-family:'Poppins',sans-serif; }
    input:focus, textarea:focus, select:focus { border-color:var(--primary-color); outline:none; box-shadow:0 0 0 3px rgba(139,0,0,0.1); }
    textarea { resize:none; height:60px; }
    
    .drop-zone { border:2px dashed var(--border-color); border-radius:8px; padding:15px; text-align:center; background:var(--bg-light); cursor:pointer; }
    .drop-zone:hover { border-color:var(--primary-color); }
    .drop-zone input[type="file"] { display:none; }
    .drop-zone-icon svg { width:28px; height:28px; stroke:var(--primary-color); margin-bottom:4px; }
    .drop-zone-text { font-size:13px; color:var(--text-dark); font-weight:500; }
    .drop-zone-subtext { font-size:11px; color:var(--text-light); }
    .file-list-container { margin-top:8px; max-height:85px; overflow-y:auto; }

    .submit-btn { padding:10px 20px; background:var(--primary-color); color:white; border:none; border-radius:6px; cursor:pointer; font-size:13px; font-weight:600; display:inline-flex; align-items:center; justify-content:center; gap:8px; text-decoration:none; }
    .submit-btn:hover { background:var(--primary-hover); }
    .btn-secondary { background:#64748b; }
    .btn-secondary:hover { background:#475569; }
    .btn-success { background:#16a34a; }
    .btn-success:hover { background:#15803d; }
    .btn-outline { background:transparent; border:1px solid var(--primary-color); color:var(--primary-color); }
    .btn-outline:hover { background:var(--primary-color); color:white; }

    /* Tables */
    .custom-table { width:100%; border-collapse:collapse; margin-top:15px; font-size:13px; }
    .custom-table th { background:#f8fafc; text-align:left; padding:12px 14px; font-weight:600; color:var(--text-dark); border-bottom:2px solid #e2e8f0; }
    .custom-table td { padding:12px 14px; border-bottom:1px solid #eee; color:var(--text-dark); vertical-align:middle; }
    .custom-table tr:hover { background:#fafafa; }
    
    /* Badges */
    .badge { display:inline-block; padding:4px 10px; border-radius:12px; font-size:11px; font-weight:600; text-align:center; }
    .badge-admin { background:#fee2e2; color:#b91c1c; }
    .badge-linemanager { background:#e0e7ff; color:#3730a3; }
    .badge-employee { background:#f1f5f9; color:#475569; }
    .badge-agreed, .badge-reviewed { background:var(--success-bg); color:var(--success-color); }
    .badge-viewed, .badge-assigned { background:var(--info-bg); color:var(--info-color); }
    .badge-pending, .badge-submitted { background:var(--warning-bg); color:var(--warning-color); }

    .action-btn { padding:5px 10px; border-radius:4px; font-size:11px; font-weight:600; text-decoration:none; display:inline-block; margin-right:4px; }
    .btn-access { background:#6366f1; color:white; }
    .btn-access:hover { background:#4f46e5; }
    .btn-edit { background:#0ea5e9; color:white; }
    .btn-edit:hover { background:#0284c7; }
    .btn-delete { background:#dc2626; color:white; }
    .btn-delete:hover { background:#b91c1c; }
    
    @media(max-width:768px) { 
        .form-row { grid-template-columns:1fr; gap:0; } 
        header { flex-direction:column; gap:10px; padding:15px; } 
        .custom-table { display:block; overflow-x:auto; } 
    }
</style>
</head>
<body>

<?php if ($is_impersonating): ?>
<div class="impersonation-bar">
    <span>⚠️ You are currently impersonating an account. Original Administrator: <strong><?= htmlspecialchars($_SESSION['admin_impersonator_name'] ?? 'Admin') ?></strong></span>
    <a href="admin_switch.php?action=revert">← Return to Administrator Account</a>
</div>
<?php endif; ?>

<header>
    <div class="brand">
        <span>Times of Eswatini HR Administration</span>
    </div>
    <nav>
        <span class="user-info">Logged in: <strong><?= htmlspecialchars($_SESSION['fullname'] ?? 'Admin') ?></strong></span>
        <a href="#accounts" style="background:rgba(255,255,255,0.2); padding:5px 10px; border-radius:4px;">All Accounts</a>
        <a href="#performance-reports" style="background:rgba(255,255,255,0.2); padding:5px 10px; border-radius:4px;">Performance Reports</a>
        <a href="dashboard.php" target="_blank" style="background:rgba(255,255,255,0.2); padding:5px 10px; border-radius:4px;">Staff View ↗</a>
        <a href="logout.php" style="background:#600000; padding:5px 12px; border-radius:4px;">Logout</a>
    </nav>
</header>

<div class="main-wrapper">

    <!-- Metrics Row -->
    <div class="metrics-grid">
        <div class="metric-card users">
            <div>
                <div class="metric-title">System Accounts</div>
                <div class="metric-value"><?= intval($metric_users) ?></div>
            </div>
            <div style="font-size:32px;"></div>
        </div>
        <div class="metric-card">
            <div>
                <div class="metric-title">Active Documents</div>
                <div class="metric-value"><?= intval($metric_docs) ?></div>
            </div>
            <div style="font-size:32px;"></div>
        </div>
        <div class="metric-card agreed">
            <div>
                <div class="metric-title">Staff Agreements</div>
                <div class="metric-value"><?= intval($metric_agreed) ?></div>
            </div>
            <div style="font-size:32px;"></div>
        </div>
        <div class="metric-card viewed">
            <div>
                <div class="metric-title">KPI Forms</div>
                <div class="metric-value"><?= count($company_kpis) ?></div>
            </div>
            <div style="font-size:32px;"></div>
        </div>
    </div>

    <?php if ($msg): ?>
        <div class="alert alert-success">✓ <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-error">❌ <?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- SECTION 1: ALL USER ACCOUNTS MANAGEMENT (ADMIN ACCESS TO ALL ACCOUNTS) -->
    <div class="card" id="accounts">
        <div class="portal-header">
            <div>
                <h2>All System Accounts & User Access</h2>
                <span style="margin-top:4px; display:inline-block;">Full administrative control over all Administrator, Line Manager, and Staff accounts</span>
            </div>
            <div>
                <button onclick="document.getElementById('addUserForm').style.display = (document.getElementById('addUserForm').style.display === 'none' ? 'block' : 'none')" class="submit-btn">
                    + Add New Account
                </button>
            </div>
        </div>

        <!-- Add User Form (Toggleable) -->
        <div id="addUserForm" style="display:none; background:#f8fafc; border:1px solid var(--border-color); border-radius:8px; padding:20px; margin-bottom:25px;">
            <h3 style="color:var(--primary-color); margin-top:0; margin-bottom:15px; font-size:16px;">Create New User Account</h3>
            <form action="manage_user.php" method="POST">
                <input type="hidden" name="action" value="add">
                <div class="form-row">
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="fullname" required placeholder="e.g. Jane Doe">
                    </div>
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" required placeholder="e.g. janedoe">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Email Address</label>
                        <input type="email" name="email" required placeholder="e.g. jane@times.co.sz">
                    </div>
                    <div class="form-group">
                        <label>Password</label>
                        <input type="text" name="password" required placeholder="Temporary password">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Account Role</label>
                        <select name="role" required>
                            <option value="employee" selected>Employee / Staff</option>
                            <option value="linemanager">Line Manager</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Department</label>
                        <select name="department_id">
                            <option value="">-- Select Department (Optional) --</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Reporting Manager</label>
                    <select name="manager_id">
                        <option value="">-- Select Manager (Optional) --</option>
                        <?php foreach ($all_managers as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['fullname']) ?> (<?= ucfirst($m['role']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="submit-btn" style="width:auto;">Create Account</button>
            </form>
        </div>

        <!-- Filter bar -->
        <div style="margin-bottom:15px; display:flex; justify-content:space-between; align-items:center; gap:15px;">
            <input type="text" id="userSearch" placeholder=" Search accounts by name, username, email, department..." style="max-width:350px;">
            <div style="font-size:12px; color:#666;">
                Showing <strong><?= count($all_users) ?></strong> registered account(s)
            </div>
        </div>

        <table class="custom-table" id="usersTable">
            <thead>
                <tr>
                    <th>Full Name</th>
                    <th>Username</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Department</th>
                    <th>Reports To</th>
                    <th>Actions & Access</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($all_users as $u): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($u['fullname']) ?></strong></td>
                    <td>@<?= htmlspecialchars($u['username']) ?></td>
                    <td><?= htmlspecialchars($u['email']) ?></td>
                    <td>
                        <span class="badge badge-<?= $u['role'] ?>"><?= strtoupper($u['role']) ?></span>
                    </td>
                    <td><?= htmlspecialchars($u['dept_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($u['manager_name'] ?? '—') ?></td>
                    <td>
                        <!-- Access Account (Impersonate / Switch) -->
                        <a href="admin_switch.php?action=switch&user_id=<?= $u['id'] ?>" class="action-btn btn-access" title="Log in as this user to view their portal">
                            Access Account
                        </a>
                        <!-- Edit user -->
                        <a href="manage_user.php?action=edit_view&id=<?= $u['id'] ?>" class="action-btn btn-edit" title="Edit user details">
                             Edit
                        </a>
                       <!-- Delete user (cannot delete self) -->
                        <?php if ($u['id'] != $_SESSION['user_id']): ?>
                            <a href="manage_user.php?action=delete&id=<?= $u['id'] ?>" class="action-btn btn-delete" onclick="return confirm('Are you sure you want to permanently delete this account?');" title="Delete account">
                                 

                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>


    <!-- SECTION 2: COMPANY-WIDE PERFORMANCE REPORTS (STORED & ACCESSIBLE TO ADMIN) -->
    <div class="card" id="performance-reports">
        <div class="portal-header">
            <div>
                <h2> Company-Wide Performance Reports</h2>
                <span style="margin-top:4px; display:inline-block;">Archive of all staff and manager performance reviews with task and overall averages</span>
            </div>
            <div>
                <a href="export_reports.php" class="submit-btn btn-success" style="font-size:12px;">
                    Export All Reports (CSV)
                </a>
            </div>
        </div>

        <?php if (!empty($company_kpis)): ?>
        <table class="custom-table">
            <thead>
                <tr>
                    <th>Report ID</th>
                    <th>Employee Name</th>
                    <th>Department</th>
                    <th>Line Manager</th>
                    <th>Status</th>
                    <th>Self-Average</th>
                    <th>Manager Score</th>
                    <th>Final Performance Score</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($company_kpis as $kpi): ?>
                <tr>
                    <td><strong>#KPI-<?= str_pad($kpi['id'], 4, '0', STR_PAD_LEFT) ?></strong></td>
                    <td>
                        <strong><?= htmlspecialchars($kpi['employee_name']) ?></strong><br>
                        <small style="color:#777;">@<?= htmlspecialchars($kpi['employee_username']) ?></small>
                    </td>
                    <td><?= htmlspecialchars($kpi['dept_name'] ?? '—') ?></td>
                    <td><?= htmlspecialchars($kpi['manager_name'] ?? 'System Administrator') ?></td>
                    <td>
                        <span class="badge badge-<?= $kpi['status'] ?>"><?= strtoupper($kpi['status']) ?></span>
                    </td>
                    <td><?= ($kpi['employee_overall_rating'] !== null) ? number_format($kpi['employee_overall_rating'], 1) . '%' : '—' ?></td>
                    <td><?= ($kpi['manager_overall_rating'] !== null) ? number_format($kpi['manager_overall_rating'], 1) . '%' : '—' ?></td>
                    <td>
                        <?php if ($kpi['status'] === 'reviewed'): ?>
                            <strong style="color: #15803d; font-size: 14px;">
                                <?= number_format($kpi['final_performance_score'] ?? $kpi['manager_overall_rating'] ?? 0, 1) ?>%
                            </strong>
                        <?php else: ?>
                            <span style="color: #94a3b8;">Pending Review</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="kpi_report.php?id=<?= $kpi['id'] ?>" class="action-btn btn-edit" target="_blank" style="background:#16a34a;">
                             View Report
                        </a>
                        <a href="review_kpi.php?id=<?= $kpi['id'] ?>" class="action-btn btn-secondary">
                            Review / Edit
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php else: ?>
            <p style="color:#777; font-size:14px; text-align:center; padding:20px;">No submitted or reviewed KPI evaluations found yet.</p>
        <?php endif; ?>
    </div>


    <!-- SECTION 3: ASSIGN KPI WORKBOOK TO MANAGERS -->
    <div class="card">
        <div class="portal-header">
            <h2> Assign KPI to Line Manager or Staff</h2>
            <span>Create and structure tasks and output expectations</span>
        </div>
        
        <form action="create_kpi_workbook.php" method="GET">
            <div class="form-row">
                <div class="form-group">
                    <label>Select User to Assign/Edit KPI</label>
                    <select name="user_id" required>
                        <option value="" disabled selected>Select User</option>
                        <?php foreach($all_users as $usr): ?>
                            <?php if ($usr['id'] != $_SESSION['user_id']): ?>
                                <option value="<?= $usr['id'] ?>"><?= htmlspecialchars($usr['fullname']) ?> (@<?= htmlspecialchars($usr['username']) ?> - <?= ucfirst($usr['role']) ?>)</option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <button type="submit" class="submit-btn">Create / Edit KPI Workbook</button>
        </form>
    </div>


    <!-- SECTION 4: DOCUMENT UPLOAD & COMPLIANCE -->
    <div class="card" id="documents">
        <div class="portal-header">
            <h2> Admin Document Upload</h2>
            <span>Upload SOPs, Policies, and General HR Documents</span>
        </div>

        <?php if ($status === 'success'): ?>
            <div class="alert alert-success">✓ Successfully uploaded <?= intval($count) ?> document(s) to the system!</div>
        <?php elseif ($status === 'deleted'): ?>
            <div class="alert alert-info"> Document successfully removed from the system.</div>
        <?php endif; ?>

        <form action="upload.php" method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="form-row">
                <div class="form-group">
                    <label>Document Category</label>
                    <select name="category" required>
                        <option value="" disabled selected>Select Category</option>
                        <option value="SOP">SOP (Standard Operating Procedure)</option>
                        <option value="Policy">Policy (Company Policies)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Document Title</label>
                    <input type="text" name="title" placeholder="e.g. Q3 Performance Metrics / IT Security Policy" required>
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <textarea name="description" placeholder="Brief outline of the document content..."></textarea>
            </div>
            <div class="form-group">
                <label>Select Document File(s)</label>
                <div class="drop-zone" id="dropZone">
                    <div class="drop-zone-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z" />
                        </svg>
                    </div>
                    <div class="drop-zone-text">Drag & Drop files here or click to browse</div>
                    <div class="drop-zone-subtext">PDF, DOCX, XLSX allowed (max 10MB each)</div>
                    <input type="file" id="fileInput" name="documents[]" multiple required>
                </div>
                <div class="file-list-container" id="fileListContainer"></div>
            </div>
            <button type="submit" class="submit-btn" id="submitBtn">
                <span>Upload Documents</span>
            </button>
        </form>
    </div>


    <!-- SECTION 5: STAFF READING COMPLIANCE REPORT -->
    <div class="card">
        <div class="portal-header">
            <h2> Staff Reading & Agreement Compliance Tracking</h2>
            <span>Real-time tracking of staff document acknowledgments</span>
        </div>

        <?php if ($staff_reports && $staff_reports->num_rows > 0): ?>
            <table class="custom-table">
                <thead>
                    <tr>
                        <th>Staff Member</th>
                        <th>Document Title</th>
                        <th>Category</th>
                        <th>Agreement Status</th>
                        <th>Date & Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while ($rep = $staff_reports->fetch_assoc()): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($rep['staff_name']) ?></strong><br>
                                <small style="color:#777;">@<?= htmlspecialchars($rep['staff_username']) ?></small>
                            </td>
                            <td><strong><?= htmlspecialchars($rep['doc_title']) ?></strong></td>
                            <td><span class="badge badge-linemanager"><?= htmlspecialchars($rep['doc_category']) ?></span></td>
                            <td>
                                <?php if ($rep['status'] === 'agreed'): ?>
                                    <span class="badge badge-agreed">✓ Agreed</span>
                                <?php elseif ($rep['status'] === 'viewed'): ?>
                                    <span class="badge badge-viewed">Viewed</span>
                                <?php else: ?>
                                    <span class="badge badge-pending">Pending</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php 
                                if ($rep['status'] === 'agreed' && $rep['agreed_at']) {
                                    echo date('M d, Y \a\t h:i A', strtotime($rep['agreed_at']));
                                } elseif ($rep['viewed_at']) {
                                    echo date('M d, Y \a\t h:i A', strtotime($rep['viewed_at']));
                                } else {
                                    echo "—";
                                }
                                ?>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p style="color:#777; font-size:14px; text-align:center; padding:20px;">No staff reading or agreement logs recorded yet.</p>
        <?php endif; ?>
    </div>

</div>

<script>
    // Live Account Search Filter
    const userSearch = document.getElementById('userSearch');
    userSearch.addEventListener('input', function() {
        const query = this.value.toLowerCase();
        const rows = document.querySelectorAll('#usersTable tbody tr');
        rows.forEach(row => {
            const text = row.innerText.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    });

    // Drag and Drop Upload
    const dropZone = document.getElementById('dropZone');
    const fileInput = document.getElementById('fileInput');
    const fileListContainer = document.getElementById('fileListContainer');
    let dataTransfer = new DataTransfer();

    dropZone.addEventListener('click', () => fileInput.click());
    ['dragenter','dragover','dragleave','drop'].forEach(eventName => {
        dropZone.addEventListener(eventName, (e) => { e.preventDefault(); e.stopPropagation(); }, false);
    });
    dropZone.addEventListener('drop', (e) => { 
        dropZone.classList.remove('dragover'); 
        for (let i = 0; i < e.dataTransfer.files.length; i++) dataTransfer.items.add(e.dataTransfer.files[i]);
        fileInput.files = dataTransfer.files;
        renderFileList();
    });

    fileInput.addEventListener('change', function() { 
        for (let i = 0; i < this.files.length; i++) dataTransfer.items.add(this.files[i]);
        fileInput.files = dataTransfer.files;
        renderFileList();
    });

    function renderFileList() {
        fileListContainer.innerHTML = '';
        if (dataTransfer.files.length > 0) fileInput.removeAttribute('required');
        Array.from(dataTransfer.files).forEach((file, index) => {
            const fileItem = document.createElement('div');
            fileItem.style.cssText = 'display:flex; justify-content:space-between; align-items:center; background:#fff; border:1px solid #eee; padding:6px 10px; border-radius:6px; margin-bottom:4px; font-size:11px;';
            fileItem.innerHTML = `<span><strong>${file.name}</strong> (${(file.size / 1024).toFixed(1)} KB)</span>
            <button type="button" style="color:red; background:none; border:none; cursor:pointer;" onclick="removeFile(${index})">×</button>`;
            fileListContainer.appendChild(fileItem);
        });
    }

    window.removeFile = function(index) {
        const dt = new DataTransfer();
        for (let i = 0; i < dataTransfer.files.length; i++) {
            if (i !== index) dt.items.add(dataTransfer.files[i]);
        }
        dataTransfer = dt;
        fileInput.files = dataTransfer.files;
        renderFileList();
    };
</script>
</body>
</html>