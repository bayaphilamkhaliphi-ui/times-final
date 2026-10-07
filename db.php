<?php
// Database configuration with support for cloud MySQL (TiDB, Aiven, Railway, etc.) and local XAMPP
$db_host = getenv('DB_HOST') ?: (getenv('MYSQLHOST') ?: 'localhost');
$db_user = getenv('DB_USER') ?: (getenv('MYSQLUSER') ?: 'root');
$db_pass = getenv('DB_PASS') !== false ? getenv('DB_PASS') : (getenv('MYSQLPASSWORD') !== false ? getenv('MYSQLPASSWORD') : '');
$db_name = getenv('DB_NAME') ?: (getenv('MYSQLDATABASE') ?: 'hr_portal');
$db_port = getenv('DB_PORT') ? intval(getenv('DB_PORT')) : (getenv('MYSQLPORT') ? intval(getenv('MYSQLPORT')) : 3306);

// Initialize MySQLi
$conn = mysqli_init();

// Support SSL connection for cloud providers like TiDB Cloud or Aiven
$use_ssl = (getenv('DB_SSL') === 'true' || strpos($db_host, 'tidbcloud.com') !== false || strpos($db_host, 'aivencloud.com') !== false);
if ($use_ssl) {
    $conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
}

// Attempt connection
$flags = $use_ssl ? MYSQLI_CLIENT_SSL : 0;
$connected = @$conn->real_connect($db_host, $db_user, $db_pass, $db_name, $db_port, NULL, $flags);

if (!$connected) {
    // Fallback for local XAMPP setup: connect without database and create it
    $conn = new mysqli($db_host, $db_user, $db_pass, "", $db_port);
    if ($conn->connect_error) {
        die("Database connection failed: " . $conn->connect_error . " (Host: $db_host, Port: $db_port, User: $db_user)");
    }
    $conn->query("CREATE DATABASE IF NOT EXISTS `$db_name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $conn->select_db($db_name);
}
?>