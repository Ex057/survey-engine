<?php
// Increase memory limit to prevent exhaustion errors
ini_set('memory_limit', '512M');

// Use East Africa Time for all PHP date/time operations in this app.
date_default_timezone_set('Africa/Nairobi');
$host = "localhost"; // Change if needed
$port = "3306"; // MySQL port (default)
$dbname = "fbtv3"; // Replace with your DB name
$username = "root"; // Replace with your DB username
$password = "root"; // Replace with your DB password

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // Keep DB session time aligned with EAT (UTC+03:00).
    $pdo->exec("SET time_zone = '+03:00'");
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
