<?php
session_start();
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'linemanager'])) {
    header("Location: index.html");
    exit;
}

require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $document_id = intval($_POST['document_id'] ?? 0);
    $target_user_id = intval($_POST['user_id'] ?? 0);
    $redirect_url = $_POST['redirect_url'] ?? 'dashboard.php';

    if ($document_id > 0 && $target_user_id > 0) {
        // Verify document is a KPI
        $doc_q = $conn->prepare("SELECT id FROM documents WHERE id = ? AND category = 'KPI'");
        $doc_q->bind_param("i", $document_id);
        $doc_q->execute();
        $is_kpi = $doc_q->get_result()->fetch_assoc();
        $doc_q->close();

        if (!$is_kpi) {
            header("Location: " . $redirect_url . "?error=" . urlencode("Invalid document. Only KPIs can be assigned."));
            exit;
        }

        // Check if already assigned
        $check = $conn->prepare("SELECT id FROM employee_documents WHERE employee_id = ? AND document_id = ?");
        $check->bind_param("ii", $target_user_id, $document_id);
        $check->execute();
        $exists = $check->get_result()->fetch_assoc();
        $check->close();

        if ($exists) {
            header("Location: " . $redirect_url . "?error=" . urlencode("This KPI is already assigned to the selected user."));
            exit;
        }

        // Assign KPI
        $stmt = $conn->prepare("INSERT INTO employee_documents (employee_id, document_id, status) VALUES (?, ?, 'pending')");
        $stmt->bind_param("ii", $target_user_id, $document_id);
        
        if ($stmt->execute()) {
            header("Location: " . $redirect_url . "?msg=" . urlencode("KPI assigned successfully."));
        } else {
            header("Location: " . $redirect_url . "?error=" . urlencode("Database error: " . $stmt->error));
        }
        $stmt->close();
    } else {
        header("Location: " . $redirect_url . "?error=" . urlencode("Please select both a KPI and a user."));
    }
} else {
    header("Location: dashboard.php");
}
?>
