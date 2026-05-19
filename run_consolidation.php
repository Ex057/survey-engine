<?php
/**
 * Run Database Consolidation Script
 * Executes the consolidation SQL and reports results
 */

$socket = "/Applications/MAMP/tmp/mysql/mysql.sock";

try {
    $pdo = new PDO("mysql:unix_socket=$socket;dbname=fbtv3;charset=utf8", "root", "root");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "=== RUNNING DATABASE CONSOLIDATION ===\n\n";

    // Read SQL file
    $sqlFile = __DIR__ . '/db/consolidate_survey_settings.sql';
    if (!file_exists($sqlFile)) {
        die("ERROR: SQL file not found: $sqlFile\n");
    }

    $sql = file_get_contents($sqlFile);

    // Split into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) &&
                   !preg_match('/^--/', $stmt) &&
                   !preg_match('/^\/\*/', $stmt);
        }
    );

    echo "Found " . count($statements) . " SQL statements to execute\n\n";

    $successCount = 0;
    $errorCount = 0;
    $results = [];

    foreach ($statements as $index => $statement) {
        $statement = trim($statement);
        if (empty($statement)) continue;

        // Show what we're executing
        $preview = substr($statement, 0, 100);
        if (strlen($statement) > 100) $preview .= '...';
        echo "[$index] Executing: $preview\n";

        try {
            $result = $pdo->query($statement);

            // If it's a SELECT statement, show results
            if (stripos($statement, 'SELECT') === 0) {
                while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
                    foreach ($row as $key => $value) {
                        echo "    $key: $value\n";
                    }
                }
                echo "\n";
            } else {
                $affected = $result->rowCount();
                echo "    ✅ Success (affected rows: $affected)\n\n";
            }

            $successCount++;

        } catch (PDOException $e) {
            // Check if error is about column already existing
            if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
                echo "    ⚠️  Column already exists (skipping)\n\n";
                $successCount++;
            } else {
                echo "    ❌ Error: " . $e->getMessage() . "\n\n";
                $errorCount++;

                // Stop on critical errors
                if (strpos($e->getMessage(), 'syntax error') !== false) {
                    echo "CRITICAL ERROR - Stopping execution\n";
                    break;
                }
            }
        }
    }

    echo "\n=== CONSOLIDATION SUMMARY ===\n";
    echo "Successful: $successCount\n";
    echo "Errors: $errorCount\n\n";

    if ($errorCount === 0) {
        echo "✅ CONSOLIDATION COMPLETED SUCCESSFULLY!\n\n";

        // Show final verification
        echo "=== FINAL VERIFICATION ===\n";

        $surveySettingsCount = $pdo->query("SELECT COUNT(*) FROM survey_settings")->fetchColumn();
        echo "Total survey_settings rows: $surveySettingsCount\n";

        $orphanCount = $pdo->query("
            SELECT COUNT(*) FROM survey s
            LEFT JOIN survey_settings ss ON s.id = ss.survey_id
            WHERE ss.id IS NULL
        ")->fetchColumn();
        echo "Surveys without settings: $orphanCount\n";

        if ($orphanCount == 0) {
            echo "✅ All surveys now have settings!\n";
        }

        // Check if old tables were renamed
        $tables = $pdo->query("SHOW TABLES LIKE '%_OLD'")->fetchAll(PDO::FETCH_COLUMN);
        if (count($tables) > 0) {
            echo "\nRenamed tables (old):\n";
            foreach ($tables as $table) {
                echo "  - $table\n";
            }
            echo "\nThese will be dropped after testing.\n";
        }

    } else {
        echo "⚠️  CONSOLIDATION COMPLETED WITH ERRORS\n";
        echo "Review errors above before proceeding.\n";
    }

} catch (PDOException $e) {
    echo "❌ DATABASE CONNECTION ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
