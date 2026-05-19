<?php
// Direct connection for this script
$host = "localhost";
$port = "3306";
$dbname = "fbtv3";
$username = "root";
$password = "root";
$socket = "/Applications/MAMP/tmp/mysql/mysql.sock";

try {
    $pdo = new PDO("mysql:unix_socket=$socket;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}

echo "=== CHECKING SURVEY TYPES AND SETTINGS ===\n\n";

// Check survey table structure
echo "1. Survey Table - Type Columns:\n";
$result = $pdo->query("DESCRIBE survey");
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    if (stripos($row['Field'], 'type') !== false || stripos($row['Field'], 'program') !== false) {
        echo "   {$row['Field']}: {$row['Type']} (Default: {$row['Default']})\n";
    }
}

echo "\n2. Sample Surveys with Types:\n";
$stmt = $pdo->query("
    SELECT id, name, type, program_type, domain_type, program_dataset
    FROM survey
    WHERE id IN (1,2,3,18,20,22)
    ORDER BY id
");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf("   ID %d: %s\n", $row['id'], $row['name']);
    echo sprintf("      type: %s | program_type: %s | domain_type: %s | program_dataset: %s\n",
        $row['type'], $row['program_type'], $row['domain_type'], $row['program_dataset']);
}

echo "\n3. Survey Settings Table Structure:\n";
$result = $pdo->query("DESCRIBE survey_settings");
$columns = [];
while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
    $columns[] = $row['Field'];
}
echo "   Total columns: " . count($columns) . "\n";
echo "   Columns: " . implode(", ", $columns) . "\n";

echo "\n4. Check if survey_settings can differentiate survey types:\n";
$stmt = $pdo->query("
    SELECT
        s.id,
        s.type,
        s.program_type,
        ss.survey_id,
        ss.title_text,
        ss.show_facility_section
    FROM survey s
    LEFT JOIN survey_settings ss ON s.id = ss.survey_id
    WHERE s.id IN (1,2,3,18,20,22)
    ORDER BY s.id
");

echo "   Survey ID | Type | Program Type | Has Settings | Title\n";
echo "   " . str_repeat("-", 80) . "\n";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf("   %9d | %-8s | %-12s | %-12s | %s\n",
        $row['id'],
        $row['type'] ?? 'NULL',
        $row['program_type'] ?? 'NULL',
        $row['survey_id'] ? 'YES' : 'NO',
        substr($row['title_text'] ?? 'N/A', 0, 30)
    );
}

echo "\n5. Checking if we need type-specific columns:\n";
echo "   Current approach: survey_settings has ONE row per survey\n";
echo "   Options:\n";
echo "   A) Add columns with defaults that work for all types\n";
echo "   B) Add type-specific columns (e.g., dataset_*, survey_*, tracker_*)\n";
echo "   C) Use conditional defaults based on survey.type\n\n";

echo "RECOMMENDATION: Use Option A - Add generic columns with smart defaults\n";
echo "The form code can check survey.type and adjust which labels to use.\n";
?>
