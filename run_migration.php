<?php
/**
 * Migration Runner - Add orgunit_last_sync column to survey_settings
 *
 * Run this file once by visiting: http://localhost:8888/survey-engine/run_migration.php
 * Then delete this file for security
 */

// Direct connection for MAMP
$host = "localhost";
$port = "8889"; // MAMP MySQL port
$dbname = "fbtv3";
$username = "root";
$password = "root";

try {
    $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    echo "<p style='color: green;'>✓ Database connection successful</p>";
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

echo "<h1>Running Database Migration</h1>";
echo "<h2>Adding orgunit_last_sync column to survey_settings</h2>";

try {
    // Check if column already exists
    $checkStmt = $pdo->query("
        SELECT COUNT(*) as count
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'survey_settings'
          AND COLUMN_NAME = 'orgunit_last_sync'
    ");
    $result = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($result['count'] > 0) {
        echo "<p style='color: orange;'>✓ Column 'orgunit_last_sync' already exists in survey_settings table.</p>";
        echo "<p>Migration not needed.</p>";
    } else {
        // Add the column
        $pdo->exec("
            ALTER TABLE survey_settings
            ADD COLUMN orgunit_last_sync DATETIME NULL
            COMMENT 'Timestamp of last org unit sync from DHIS2'
            AFTER selected_hierarchy_level
        ");

        echo "<p style='color: green;'>✓ Successfully added 'orgunit_last_sync' column to survey_settings table.</p>";
    }

    // Verify the column
    $verifyStmt = $pdo->query("
        SELECT
            COLUMN_NAME,
            COLUMN_TYPE,
            IS_NULLABLE,
            COLUMN_COMMENT
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'survey_settings'
          AND COLUMN_NAME = 'orgunit_last_sync'
    ");

    $column = $verifyStmt->fetch(PDO::FETCH_ASSOC);

    if ($column) {
        echo "<h3>Column Details:</h3>";
        echo "<ul>";
        echo "<li><strong>Column Name:</strong> {$column['COLUMN_NAME']}</li>";
        echo "<li><strong>Type:</strong> {$column['COLUMN_TYPE']}</li>";
        echo "<li><strong>Nullable:</strong> {$column['IS_NULLABLE']}</li>";
        echo "<li><strong>Comment:</strong> {$column['COLUMN_COMMENT']}</li>";
        echo "</ul>";

        echo "<p style='color: green; font-weight: bold;'>✓ Migration completed successfully!</p>";
        echo "<p><strong>Next Steps:</strong></p>";
        echo "<ol>";
        echo "<li>Delete this migration file (run_migration.php) for security</li>";
        echo "<li>Go to admin panel → Dataset Preview page</li>";
        echo "<li>Test the 'Sync Facilities from DHIS2' button</li>";
        echo "</ol>";
    } else {
        echo "<p style='color: red;'>✗ Column verification failed. Please check database.</p>";
    }

} catch (PDOException $e) {
    echo "<p style='color: red;'>✗ Migration Error: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p>Stack trace:</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>
