<?php
// Direct connection for this script
$socket = "/Applications/MAMP/tmp/mysql/mysql.sock";
$pdo = new PDO("mysql:unix_socket=$socket;dbname=fbtv3;charset=utf8", "root", "root");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== DATABASE ANALYSIS FOR SURVEY ENGINE ===\n\n";

// Get all tables
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
echo "Total tables in database: " . count($tables) . "\n\n";

// Categorize tables
$categories = [
    'settings' => [],
    'dataset' => [],
    'tracker' => [],
    'survey' => [],
    'submission' => [],
    'dhis2' => [],
    'user' => [],
    'other' => []
];

foreach ($tables as $table) {
    if (stripos($table, 'settings') !== false) {
        $categories['settings'][] = $table;
    } elseif (stripos($table, 'dataset') !== false) {
        $categories['dataset'][] = $table;
    } elseif (stripos($table, 'tracker') !== false) {
        $categories['tracker'][] = $table;
    } elseif (stripos($table, 'survey') !== false) {
        $categories['survey'][] = $table;
    } elseif (stripos($table, 'submission') !== false || stripos($table, 'response') !== false) {
        $categories['submission'][] = $table;
    } elseif (stripos($table, 'dhis2') !== false) {
        $categories['dhis2'][] = $table;
    } elseif (stripos($table, 'user') !== false || stripos($table, 'admin') !== false) {
        $categories['user'][] = $table;
    } else {
        $categories['other'][] = $table;
    }
}

echo "=== SETTINGS-RELATED TABLES ===\n";
foreach ($categories['settings'] as $table) {
    $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    echo "  $table: $count rows\n";
}

echo "\n=== DATASET-RELATED TABLES ===\n";
foreach ($categories['dataset'] as $table) {
    $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    echo "  $table: $count rows\n";
}

echo "\n=== TRACKER-RELATED TABLES ===\n";
foreach ($categories['tracker'] as $table) {
    $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    echo "  $table: $count rows\n";
}

echo "\n=== CURRENT SETTINGS TABLES COMPARISON ===\n";

// Check survey_settings
if (in_array('survey_settings', $tables)) {
    echo "\n1. survey_settings:\n";
    $cols = $pdo->query("DESCRIBE survey_settings")->fetchAll(PDO::FETCH_ASSOC);
    echo "   Columns: " . count($cols) . "\n";
    $rows = $pdo->query("SELECT COUNT(*) FROM survey_settings")->fetchColumn();
    echo "   Rows: $rows\n";
    echo "   Columns list:\n";
    foreach ($cols as $col) {
        echo "      - {$col['Field']} ({$col['Type']})\n";
    }
}

// Check dataset_layout_settings
if (in_array('dataset_layout_settings', $tables)) {
    echo "\n2. dataset_layout_settings:\n";
    $cols = $pdo->query("DESCRIBE dataset_layout_settings")->fetchAll(PDO::FETCH_ASSOC);
    echo "   Columns: " . count($cols) . "\n";
    $rows = $pdo->query("SELECT COUNT(*) FROM dataset_layout_settings")->fetchColumn();
    echo "   Rows: $rows\n";
    echo "   Columns list:\n";
    foreach ($cols as $col) {
        echo "      - {$col['Field']} ({$col['Type']})\n";
    }
}

// Check for any other settings tables
echo "\n=== RECOMMENDATION: CONSOLIDATE SETTINGS ===\n";
echo "1. Move dataset_layout_settings columns INTO survey_settings\n";
echo "2. Use survey_settings for ALL form types (survey, tracker, dataset)\n";
echo "3. Drop dataset_layout_settings after migration\n";

echo "\n=== CHECKING TABLE USAGE ===\n";
echo "Identifying potentially unused tables...\n\n";

$unusedTables = [];
foreach ($tables as $table) {
    $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    if ($count == 0) {
        $unusedTables[] = $table;
    }
}

if (count($unusedTables) > 0) {
    echo "EMPTY TABLES (potentially unused):\n";
    foreach ($unusedTables as $table) {
        echo "  - $table\n";
    }
} else {
    echo "All tables have data.\n";
}

echo "\n=== CHECKING FOR ORPHANED DATA ===\n";

// Check for surveys without settings
$orphanedSurveys = $pdo->query("
    SELECT s.id, s.name, s.type, s.program_type
    FROM survey s
    LEFT JOIN survey_settings ss ON s.id = ss.survey_id
    WHERE ss.id IS NULL
")->fetchAll(PDO::FETCH_ASSOC);

if (count($orphanedSurveys) > 0) {
    echo "Surveys WITHOUT survey_settings:\n";
    foreach ($orphanedSurveys as $survey) {
        echo sprintf("  ID %d: %s (type: %s, program_type: %s)\n",
            $survey['id'], $survey['name'], $survey['type'], $survey['program_type']);
    }
} else {
    echo "All surveys have settings.\n";
}

// Check for settings without surveys
$orphanedSettings = $pdo->query("
    SELECT ss.id, ss.survey_id, ss.title_text
    FROM survey_settings ss
    LEFT JOIN survey s ON ss.survey_id = s.id
    WHERE s.id IS NULL
")->fetchAll(PDO::FETCH_ASSOC);

if (count($orphanedSettings) > 0) {
    echo "\nSettings WITHOUT surveys (orphaned):\n";
    foreach ($orphanedSettings as $setting) {
        echo sprintf("  Settings ID %d for survey_id %d: %s\n",
            $setting['id'], $setting['survey_id'], $setting['title_text']);
    }
}

echo "\n=== MIGRATION PLAN ===\n";
echo "1. Merge dataset_layout_settings columns into survey_settings\n";
echo "2. Create survey_settings rows for orphaned surveys\n";
echo "3. Add new text label columns to survey_settings\n";
echo "4. Drop dataset_layout_settings table\n";
echo "5. Update all forms to use survey_settings exclusively\n";

?>
