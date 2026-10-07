<?php
require_once 'db.php';
$res = $conn->query("SELECT id, username, email, role, department_id, manager_id FROM users");
if (!$res) die("Error: " . $conn->error);
echo "USERS IN DATABASE:\n";
while ($row = $res->fetch_assoc()) {
    print_r($row);
}
?>
