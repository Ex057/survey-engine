<?php
/**
 * Drop Old Tables Script
 * Removes tables renamed with _OLD suffix after consolidation
 */

$socket = "/Applications/MAMP/tmp/mysql/mysql.sock";
$pdo = new PDO("mysql:unix_socket=$socket;dbname=fbtv3;charset=utf8", "root", "root");
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== DROPPING OLD TABLES ===\n\n";

// Get all tables with _OLD suffix
$oldTables = $pdo->query('SHOW TABLES LIKE "%_OLD"')->fetchAll(PDO::FETCH_COLUMN);

if (count($oldTables) === 0) {
    echo "No old tables found.\n";
    exit(0);
}

echo "Found " . count($oldTables) . " old tables to drop:\n";
foreach ($oldTables as $table) {
    $count = $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    echo "  - $table ($count rows)\n";
}

echo "\nAre you sure you want to drop these tables? (y/n): ";
$handle = fopen("php://stdin", "r");
$line = trim(fgets($handle));

if (strtolower($line) !== 'y') {
    echo "Cancelled.\n";
    exit(0);
}

echo "\nDropping tables...\n";

$dropped = 0;
$errors = 0;

foreach ($oldTables as $table) {
    try {
        $pdo->exec("DROP TABLE IF EXISTS `$table`");
        echo "  ✅ Dropped: $table\n";
        $dropped++;
    } catch (PDOException $e) {
        echo "  ❌ Error dropping $table: " . $e->getMessage() . "\n";
        $errors++;
    }
}

echo "\n=== SUMMARY ===\n";
echo "Dropped: $dropped tables\n";
echo "Errors: $errors\n";

if ($errors === 0) {
    echo "\n✅ All old tables successfully removed!\n";
} else {
    echo "\n⚠️  Some tables could not be dropped. Check errors above.\n";
}
?>
