<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    die("Access denied.");
}

require_once 'db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title       = trim($_POST['title'] ?? '');
    $category    = trim($_POST['category'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $uploaded_by = $_SESSION['user_id'];

    if (empty($title) || empty($category)) {
        header("Location: admindash.php?status=error&msg=" . urlencode("Title and category are required."));
        exit;
    }

    if (!is_dir('uploads')) {
        mkdir('uploads', 0777, true);
    }

    $allowed_ext = ['pdf', 'docx', 'doc', 'xlsx', 'xls'];
    $max_size    = 10 * 1024 * 1024; // 10MB
    $uploaded_files = 0;

    if (isset($_FILES['documents']) && is_array($_FILES['documents']['name'])) {
        foreach ($_FILES['documents']['name'] as $i => $name) {
            if (empty($name)) continue;
            
            if ($_FILES['documents']['error'][$i] === UPLOAD_ERR_OK) {
                $tmp = $_FILES['documents']['tmp_name'][$i];
                $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
                if (!in_array($ext, $allowed_ext)) continue;
                if ($_FILES['documents']['size'][$i] > $max_size) continue;

                $new_name = time() . '_' . rand(1000, 9999) . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($name));
                if (move_uploaded_file($tmp, "uploads/$new_name")) {
                    $stmt = $conn->prepare("INSERT INTO documents (title, category, description, filename, uploaded_by) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("ssssi", $title, $category, $description, $new_name, $uploaded_by);
                    if ($stmt->execute()) {
                        $uploaded_files++;
                    }
                    $stmt->close();
                }
            }
        }
    }

    if ($uploaded_files > 0) {
        header("Location: admindash.php?status=success&count=" . $uploaded_files);
    } else {
        header("Location: admindash.php?status=error&msg=" . urlencode("No valid files were uploaded. Please check file type (PDF, DOCX, XLSX) and size."));
    }
    exit;
} else {
    header("Location: admindash.php");
    exit;
}
?>