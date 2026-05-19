<?php
/**
 * AJAX endpoint to sync dataset metadata to database
 * Triggered when dataset survey is created or updated
 */

session_start();
require_once 'connect.php';
require_once 'dhis2/dataset_storage_service.php';

header('Content-Type: application/json');

// Check if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get parameters
$surveyId = $_POST['survey_id'] ?? null;

if (!$surveyId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Survey ID is required']);
    exit;
}

try {
    // Get survey details
    $stmt = $pdo->prepare("
        SELECT id, name, program_dataset, dhis2_instance, program_type
        FROM survey
        WHERE id = ? AND program_type = 'aggregate'
    ");
    $stmt->execute([$surveyId]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$survey) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Dataset survey not found']);
        exit;
    }

    $datasetUid = $survey['program_dataset'];
    $instanceKey = $survey['dhis2_instance'];

    if (empty($datasetUid) || empty($instanceKey)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Dataset UID or DHIS2 instance not configured for this survey'
        ]);
        exit;
    }

    // Store metadata
    $result = DatasetStorageService::storeDatasetMetadata($surveyId, $datasetUid, $instanceKey);

    if ($result['success']) {
        echo json_encode($result);
    } else {
        http_response_code(500);
        echo json_encode($result);
    }

} catch (Exception $e) {
    error_log("[AJAX SYNC] Error: " . $e->getMessage());
    error_log("[AJAX SYNC] Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while syncing dataset metadata',
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
?>
