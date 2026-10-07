<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: index.html");
    exit;
}

require_once 'db.php';

$category = $_GET['category'] ?? '';
$allowed = ['KPI', 'SOP', 'Policy', 'Policies'];
if (!in_array($category, $allowed)) {
    die("Invalid category.");
}

$user_id = $_SESSION['user_id'];

// Normalize query categories for 'Policy' and 'Policies'
if ($category === 'Policy' || $category === 'Policies') {
    $stmt = $conn->prepare("
        SELECT d.*, ed.id AS ed_id, ed.status, ed.agreed_at
        FROM documents d
        LEFT JOIN employee_documents ed ON ed.document_id = d.id AND ed.employee_id = ?
        WHERE d.category IN ('Policy', 'Policies')
        ORDER BY d.upload_date DESC
    ");
    $stmt->bind_param("i", $user_id);
} elseif ($category === 'KPI') {
    $stmt = $conn->prepare("
        SELECT d.*, ed.id AS ed_id, ed.status, ed.agreed_at
        FROM documents d
        INNER JOIN employee_documents ed ON ed.document_id = d.id AND ed.employee_id = ?
        WHERE d.category = 'KPI'
        ORDER BY d.upload_date DESC
    ");
    $stmt->bind_param("i", $user_id);
} else {
    $stmt = $conn->prepare("
        SELECT d.*, ed.id AS ed_id, ed.status, ed.agreed_at
        FROM documents d
        LEFT JOIN employee_documents ed ON ed.document_id = d.id AND ed.employee_id = ?
        WHERE d.category = ?
        ORDER BY d.upload_date DESC
    ");
    $stmt->bind_param("is", $user_id, $category);
}
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($category); ?> Documents - HR Portal</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #8B0000;
            --primary-hover: #600000;
            --text-dark: #333333;
            --text-light: #666666;
            --bg-light: #f4f6f9;
            --success-bg: #d4edda;
            --success-color: #155724;
            --success-border: #c3e6cb;
            --warning-bg: #fff3cd;
            --warning-color: #856404;
            --warning-border: #ffeeba;
            --info-bg: #d1ecf1;
            --info-color: #0c5460;
            --info-border: #bee5eb;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }
        body { background: var(--bg-light); color: var(--text-dark); }
        header { background: var(--primary-color); color: white; padding: 20px 40px; text-align: center; box-shadow: 0 4px 10px rgba(0,0,0,0.1); }
        header h1 { font-size: 24px; font-weight: 600; }
        .container { width: 90%; max-width: 1000px; margin: 40px auto; }
        .document { background: white; padding: 25px; margin-bottom: 25px; border-radius: 12px; box-shadow: 0 10px 25px rgba(0,0,0,.05); border-left: 5px solid var(--primary-color); transition: transform 0.3s ease; }
        .document:hover { transform: translateY(-3px); }
        .doc-header { display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 15px; margin-bottom: 15px; }
        h3 { color: var(--text-dark); font-size: 20px; font-weight: 600; }
        .doc-date { color: var(--text-light); font-size: 13px; margin-top: 3px; }

        /* Badges */
        .badge { display: inline-block; padding: 6px 14px; border-radius: 20px; font-size: 12px; font-weight: 500; text-align: center; }
        .badge-agreed { background-color: var(--success-bg); color: var(--success-color); border: 1px solid var(--success-border); }
        .badge-pending { background-color: var(--warning-bg); color: var(--warning-color); border: 1px solid var(--warning-border); }
        .badge-viewed { background-color: var(--info-bg); color: var(--info-color); border: 1px solid var(--info-border); }

        .description { color: #555; margin-bottom: 20px; font-size: 14px; line-height: 1.6; }

        .action-btn { display: inline-block; background: var(--primary-color); color: white; padding: 10px 22px; text-decoration: none; border-radius: 6px; font-size: 14px; font-weight: 500; transition: all 0.3s ease; box-shadow: 0 4px 6px rgba(139,0,0,0.15); }
        .action-btn:hover { background: var(--primary-hover); transform: translateY(-1px); box-shadow: 0 6px 12px rgba(139,0,0,0.25); }

        .back-link { display: inline-block; margin-top: 25px; color: var(--primary-color); text-decoration: none; font-weight: 500; font-size: 15px; transition: color 0.3s ease; }
        .back-link:hover { color: var(--primary-hover); text-decoration: underline; }
        .no-files { background: white; padding: 40px; border-radius: 12px; text-align: center; box-shadow: 0 10px 25px rgba(0,0,0,.05); border-left: 5px solid #ffc107; }
        .no-files h3 { color: #856404; margin-bottom: 10px; }
        .no-files p { color: #666; font-size: 14px; }
    </style>
</head>
<body>
    <header>
        <h1><?php echo htmlspecialchars($category); ?> Documents</h1>
    </header>
    <div class="container">
        <?php if ($result->num_rows > 0): ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <?php $status = $row['status'] ?? 'pending'; // no employee_documents row yet = pending ?>
                <div class="document">
                    <div class="doc-header">
                        <div>
                            <h3><?php echo htmlspecialchars($row['title']); ?></h3>
                            <div class="doc-date">Uploaded: <?php echo date('M d, Y', strtotime($row['upload_date'])); ?></div>
                        </div>
                        <div>
                            <?php if ($status === 'agreed'): ?>
                                <span class="badge badge-agreed">✓ Agreed on <?php echo date('M d, Y', strtotime($row['agreed_at'])); ?></span>
                            <?php elseif ($status === 'viewed'): ?>
                                <span class="badge badge-viewed">👁 Viewed</span>
                            <?php else: ?>
                                <span class="badge badge-pending">⏳ Pending Agreement</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php if (!empty($row['description'])): ?>
                        <div class="description"><?php echo nl2br(htmlspecialchars($row['description'])); ?></div>
                    <?php endif; ?>
                    <!-- Link now uses the document ID directly; view_document.php
                         creates the employee_documents tracking row on first view -->
                    <a href="view_document.php?id=<?php echo $row['id']; ?>" class="action-btn">
                        <?php echo $status === 'agreed' ? 'Review Document' : 'Read & Agree'; ?>
                    </a>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <div class="no-files">
                <h3>📄 No documents found</h3>
                <p>There are no <?php echo htmlspecialchars($category); ?> documents yet. Please check back later.</p>
            </div>
        <?php endif; ?>
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
    </div>
</body>
</html>
<?php
$stmt->close();
$conn->close();
?>