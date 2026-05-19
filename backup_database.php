<?php
/**
 * Database Backup Script
 * Creates a full backup before consolidation changes
 */

$socket = "/Applications/MAMP/tmp/mysql/mysql.sock";
$dbname = "fbtv3";
$username = "root";
$password = "root";

$backupDir = __DIR__ . "/db/backups";
$timestamp = date('Y-m-d_H-i-s');
$backupFile = $backupDir . "/fbtv3_before_consolidation_{$timestamp}.sql";

// Create backups directory if it doesn't exist
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

echo "=== DATABASE BACKUP SCRIPT ===\n\n";
echo "Database: $dbname\n";
echo "Backup file: $backupFile\n\n";

// Use mysqldump to create backup
$mysqldumpPath = "/Applications/MAMP/Library/bin/mysql80/bin/mysqldump";

$command = sprintf(
    "%s --user=%s --password=%s --socket=%s --single-transaction --routines --triggers %s > %s",
    escapeshellarg($mysqldumpPath),
    escapeshellarg($username),
    escapeshellarg($password),
    escapeshellarg($socket),
    escapeshellarg($dbname),
    escapeshellarg($backupFile)
);

echo "Running backup...\n";
exec($command, $output, $returnCode);

if ($returnCode === 0 && file_exists($backupFile)) {
    $fileSize = filesize($backupFile);
    $fileSizeMB = round($fileSize / 1024 / 1024, 2);

    echo "\n✅ SUCCESS!\n";
    echo "Backup created: $backupFile\n";
    echo "File size: {$fileSizeMB} MB\n\n";

    // Also create a compressed version
    $gzipFile = $backupFile . ".gz";
    exec("gzip -c " . escapeshellarg($backupFile) . " > " . escapeshellarg($gzipFile), $gzOutput, $gzReturn);

    if ($gzReturn === 0 && file_exists($gzipFile)) {
        $gzSize = filesize($gzipFile);
        $gzSizeMB = round($gzSize / 1024 / 1024, 2);
        echo "Compressed backup: $gzipFile\n";
        echo "Compressed size: {$gzSizeMB} MB\n\n";
    }

    // Verify backup
    echo "Verifying backup integrity...\n";
    exec("head -20 " . escapeshellarg($backupFile), $headOutput);
    if (count($headOutput) > 0 && strpos(implode("\n", $headOutput), 'MySQL dump') !== false) {
        echo "✅ Backup file appears valid\n\n";
    }

    echo "=== BACKUP COMPLETE ===\n";
    echo "You can restore this backup with:\n";
    echo "mysql -u root -proot fbtv3 < $backupFile\n\n";

} else {
    echo "\n❌ BACKUP FAILED!\n";
    echo "Return code: $returnCode\n";
    echo "Output: " . implode("\n", $output) . "\n";
    exit(1);
}
?>
