<?php
require_once 'db.php';

$result = $conn->query("SELECT * FROM documents ORDER BY id DESC");
echo "<pre>";
if ($result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        print_r($row);
    }
} else {
    echo "No documents found in the database.";
}
echo "</pre>";
?>