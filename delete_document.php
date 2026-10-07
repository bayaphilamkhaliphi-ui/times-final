<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Access denied.");
}

require_once 'db.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id > 0) {
    // 1. Fetch filename
    $stmt = $conn->prepare("SELECT filename FROM documents WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $doc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($doc) {
        // Delete physical file
        $file_path = "uploads/" . $doc['filename'];
        if (file_exists($file_path)) {
            @unlink($file_path);
        }

        // Delete associated records
        $del_logs = $conn->prepare("DELETE FROM agreement_logs WHERE employee_document_id IN (SELECT id FROM employee_documents WHERE document_id = ?)");
        $del_logs->bind_param("i", $id);
        $del_logs->execute();
        $del_logs->close();

        $del_emp = $conn->prepare("DELETE FROM employee_documents WHERE document_id = ?");
        $del_emp->bind_param("i", $id);
        $del_emp->execute();
        $del_emp->close();

        $del_doc = $conn->prepare("DELETE FROM documents WHERE id = ?");
        $del_doc->bind_param("i", $id);
        $del_doc->execute();
        $del_doc->close();

        header("Location: admindash.php?status=deleted");
        exit;
    }
}

header("Location: admindash.php?status=error&msg=" . urlencode("Document not found."));
exit;
?>
