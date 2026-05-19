<?php
/**
 * AJAX endpoint to get organization unit sync status
 *
 * Returns:
 * - Last sync timestamp
 * - Count of org units in local database
 */
session_start();
require_once 'connect.php';

header('Content-Type: application/json');

try {
    $surveyId = $_GET['survey_id'] ?? null;

    if (!$surveyId) {
        throw new Exception('Survey ID required');
    }

    // Get last sync timestamp from survey_settings
    $stmt = $pdo->prepare("
        SELECT orgunit_last_sync
        FROM survey_settings
        WHERE survey_id = ?
    ");
    $stmt->execute([$surveyId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    $lastSync = null;
    if ($row && $row['orgunit_last_sync']) {
        $datetime = new DateTime($row['orgunit_last_sync']);
        $lastSync = $datetime->format('Y-m-d H:i:s');
    }

    // Get count of org units in local database
    $countStmt = $pdo->prepare("
        SELECT COUNT(*) as count
        FROM dataset_org_units
        WHERE survey_id = ?
    ");
    $countStmt->execute([$surveyId]);
    $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
    $count = (int)($countRow['count'] ?? 0);

    echo json_encode([
        'success' => true,
        'last_sync' => $lastSync,
        'count' => $count
    ]);

} catch (Exception $e) {
    error_log("Error getting orgunit sync status: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
