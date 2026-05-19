<?php
session_start();

echo "<h1>Session Debug</h1>";
echo "<p><strong>Session ID:</strong> " . session_id() . "</p>";
echo "<p><strong>Admin ID in session:</strong> " . (isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : 'NOT SET') . "</p>";
echo "<p><strong>Username:</strong> " . (isset($_SESSION['admin_username']) ? $_SESSION['admin_username'] : 'NOT SET') . "</p>";
echo "<h2>All Session Data:</h2>";
echo "<pre>";
print_r($_SESSION);
echo "</pre>";

if (!isset($_SESSION['admin_id'])) {
    echo "<p style='color: red;'><strong>You are NOT logged in!</strong></p>";
    echo "<p><a href='login.php'>Go to Login Page</a></p>";
} else {
    echo "<p style='color: green;'><strong>You ARE logged in as " . htmlspecialchars($_SESSION['admin_username']) . "!</strong></p>";
    echo "<p><a href='sync_dataset_metadata.php'>Go to Sync Page</a></p>";
    echo "<p><a href='main.php'>Go to Dashboard</a></p>";
}
?>
