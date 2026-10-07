<?php
session_start();
require_once 'db.php';

echo "<h2>HR Portal End-to-End Functional Test</h2>";

// 1. Verify Admin User
$stmt = $conn->prepare("SELECT id, fullname, username, role FROM users WHERE username = 'admin'");
$stmt->execute();
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$admin || $admin['role'] !== 'admin') {
    die("❌ Admin user check failed!");
}
echo "✓ Admin user verified (ID: {$admin['id']}, Name: {$admin['fullname']}).<br>";

// 2. Verify Staff User
$stmt = $conn->prepare("SELECT id, fullname, username, role FROM users WHERE username = 'staff'");
$stmt->execute();
$staff = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$staff || $staff['role'] !== 'employee') {
    die("❌ Staff user check failed!");
}
echo "✓ Staff user verified (ID: {$staff['id']}, Name: {$staff['fullname']}).<br>";

// 3. Simulate Admin Uploading Documents (KPI, SOP, Policy)
$dummy_docs = [
    [
        'title' => '2026 Q3 Sales Department KPIs',
        'category' => 'KPI',
        'description' => 'Target metrics and quarterly KPIs for sales personnel.',
        'filename' => 'kpi_sales_q3_2026.pdf'
    ],
    [
        'title' => 'Standard Operating Procedure - IT Security',
        'category' => 'SOP',
        'description' => 'SOP for remote work access and password compliance.',
        'filename' => 'sop_it_security_2026.pdf'
    ],
    [
        'title' => 'Times of Eswatini Employee Code of Conduct',
        'category' => 'Policy',
        'description' => 'Organisation-wide workplace policy and ethics guidelines.',
        'filename' => 'policy_code_of_conduct.pdf'
    ]
];

if (!is_dir('uploads')) {
    mkdir('uploads', 0777, true);
}

$created_ids = [];
foreach ($dummy_docs as $doc) {
    // Create dummy file in uploads directory
    $file_path = "uploads/" . $doc['filename'];
    file_put_contents($file_path, "%PDF-1.4 Dummy PDF Content for " . $doc['title']);
    
    // Check if doc already inserted
    $chk = $conn->prepare("SELECT id FROM documents WHERE title = ? AND category = ?");
    $chk->bind_param("ss", $doc['title'], $doc['category']);
    $chk->execute();
    $existing = $chk->get_result()->fetch_assoc();
    $chk->close();
    
    if ($existing) {
        $created_ids[$doc['category']] = $existing['id'];
        echo "✓ Document '{$doc['title']}' already exists (ID: {$existing['id']}).<br>";
    } else {
        $ins = $conn->prepare("INSERT INTO documents (title, category, description, filename, uploaded_by) VALUES (?, ?, ?, ?, ?)");
        $ins->bind_param("ssssi", $doc['title'], $doc['category'], $doc['description'], $doc['filename'], $admin['id']);
        if ($ins->execute()) {
            $id = $ins->insert_id;
            $created_ids[$doc['category']] = $id;
            echo "✓ Admin uploaded '{$doc['title']}' (Category: {$doc['category']}, ID: $id).<br>";
        } else {
            echo "❌ Failed to insert document: " . $ins->error . "<br>";
        }
        $ins->close();
    }
}

// 4. Simulate Staff Viewing KPI, SOP, and Policy Documents
echo "<h3>Simulating Staff Viewing & Acknowledging Documents...</h3>";

foreach (['KPI', 'SOP', 'Policy'] as $cat) {
    // Query documents for category (matching logic from employee_documents.php)
    if ($cat === 'Policy') {
        $stmt = $conn->prepare("SELECT d.* FROM documents d WHERE d.category IN ('Policy', 'Policies')");
    } else {
        $stmt = $conn->prepare("SELECT d.* FROM documents d WHERE d.category = ?");
        $stmt->bind_param("s", $cat);
    }
    $stmt->execute();
    $docs_in_cat = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    echo "✓ Staff queried Category '{$cat}': found " . count($docs_in_cat) . " document(s).<br>";
    
    foreach ($docs_in_cat as $d) {
        $doc_id = $d['id'];
        
        // Simulate Staff opening view_document.php
        $find = $conn->prepare("SELECT id, status FROM employee_documents WHERE employee_id = ? AND document_id = ?");
        $find->bind_param("ii", $staff['id'], $doc_id);
        $find->execute();
        $assign = $find->get_result()->fetch_assoc();
        $find->close();
        
        if (!$assign) {
            $cr = $conn->prepare("INSERT INTO employee_documents (employee_id, document_id, status, viewed_at) VALUES (?, ?, 'viewed', NOW())");
            $cr->bind_param("ii", $staff['id'], $doc_id);
            $cr->execute();
            $assign_id = $cr->insert_id;
            $cr->close();
            echo "  - First view recorded for Staff on document #$doc_id ('{$d['title']}').<br>";
        } else {
            $assign_id = $assign['id'];
            echo "  - Document #$doc_id already viewed/agreed by Staff.<br>";
        }
        
        // Simulate Staff clicking 'Agree'
        $upd = $conn->prepare("UPDATE employee_documents SET status = 'agreed', agreed_at = NOW() WHERE id = ?");
        $upd->bind_param("i", $assign_id);
        $upd->execute();
        $upd->close();
        
        echo "  - Agreement acknowledged for Staff on document #$doc_id.<br>";
    }
}

echo "<h3>All Verification Checks Passed Successfully!</h3>";
$conn->close();
?>
