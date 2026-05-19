<?php
session_start();

header('Content-Type: application/json');

require_once '../admin/connect.php';
require_once '../admin/dhis2/dhis2_shared.php';

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Get JSON input
$input = file_get_contents('php://input');
$requestData = json_decode($input, true);

if (!$requestData) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit;
}

try {
    $surveyId = $requestData['survey_id'] ?? null;
    $datasetUid = $requestData['dataset_uid'] ?? null;
    $orgUnit = $requestData['orgunit'] ?? null;
    $period = $requestData['period'] ?? null;
    $dataValues = $requestData['data_values'] ?? [];
    $attributeOptionCombo = $requestData['attributeOptionCombo'] ?? null;
    $clientIsUpdate = $requestData['is_update'] ?? null;
    $clientMarkComplete = $requestData['mark_complete'] ?? null;
    $clientAsyncSubmit = $requestData['async_submit'] ?? null;

    // Validate required fields
    if (!$surveyId || !$datasetUid || !$orgUnit || !$period) {
        throw new Exception('Missing required fields: survey_id, dataset_uid, orgunit, or period');
    }

    if (empty($dataValues)) {
        throw new Exception('No data values provided');
    }

    // Get survey details
    $stmt = $pdo->prepare("SELECT * FROM survey WHERE id = ?");
    $stmt->execute([$surveyId]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$survey) {
        throw new Exception('Survey not found');
    }

    // Verify this is an aggregate dataset survey
    if ($survey['program_type'] !== 'aggregate') {
        throw new Exception('This survey is not configured for aggregate data');
    }

    // Get DHIS2 instance configuration
    $instanceKey = $survey['dhis2_instance'];
    if (empty($instanceKey)) {
        throw new Exception('No DHIS2 instance configured for this survey');
    }

    $dhis2Config = getDhis2Config($instanceKey);
    if (!$dhis2Config) {
        throw new Exception('DHIS2 instance configuration not found or inactive: ' . $instanceKey);
    }

    // Check if data already exists for this period and org unit (unless client told us)
    if ($clientIsUpdate !== null) {
        $isUpdate = (bool)$clientIsUpdate;
    } else {
        $existingData = checkExistingDataValues($datasetUid, $period, $orgUnit, $instanceKey);
        $isUpdate = !empty($existingData);
    }

    // Submit to DHIS2
    $markComplete = $clientMarkComplete !== null ? (bool)$clientMarkComplete : !$isUpdate;
    $asyncSubmit = $clientAsyncSubmit !== null ? (bool)$clientAsyncSubmit : false;
    $dhis2Response = submitToDHIS2Aggregate(
        $datasetUid,
        $orgUnit,
        $period,
        $dataValues,
        $instanceKey,
        $isUpdate,
        $attributeOptionCombo,
        $markComplete,
        $asyncSubmit
    );

    // Generate unique submission ID
    $submissionUID = generateUID();

    // Save to local database
    $submissionId = saveDatasetSubmissionLocally(
        $surveyId,
        $datasetUid,
        $orgUnit,
        $period,
        $dataValues,
        $dhis2Response,
        $submissionUID,
        $isUpdate
    );

    // Log to payload checker for retry capability
    logToPayloadChecker($submissionId, 'SUCCESS', [
        'dataset' => $datasetUid,
        'orgUnit' => $orgUnit,
        'period' => $period,
        'dataValues' => $dataValues
    ], $dhis2Response, 'Dataset submission successful');

    echo json_encode([
        'success' => true,
        'message' => $isUpdate ? 'Data updated successfully in DHIS2' : 'Data submitted successfully to DHIS2',
        'submission_id' => $submissionId,
        'submission_uid' => $submissionUID,
        'is_update' => $isUpdate,
        'dhis2_response' => $dhis2Response
    ]);

} catch (Exception $e) {
    error_log("Dataset submission error: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());

    // Log error
    try {
        logDHIS2Error($surveyId ?? 0, $requestData, [], $e->getMessage(), 'aggregate');
    } catch (Exception $logError) {
        error_log("Failed to log DHIS2 error: " . $logError->getMessage());
    }

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * Check if data already exists for this dataset, period, and org unit
 */
function checkExistingDataValues($datasetUid, $period, $orgUnit, $instanceKey) {
    try {
        $endpoint = "dataValueSets?dataSet={$datasetUid}&period={$period}&orgUnit={$orgUnit}";
        $response = dhis2_get($endpoint, $instanceKey);

        if ($response && isset($response['dataValues']) && !empty($response['dataValues'])) {
            error_log("Found existing data values for dataset {$datasetUid}, period {$period}, orgUnit {$orgUnit}");
            return $response['dataValues'];
        }
    } catch (Exception $e) {
        error_log("Error checking existing data: " . $e->getMessage());
    }

    return [];
}

/**
 * Submit data to DHIS2 aggregate API
 */
function submitToDHIS2Aggregate($datasetUid, $orgUnit, $period, $dataValues, $instanceKey, $isUpdate = false, $attributeOptionCombo = null, $markComplete = true, $asyncSubmit = false) {
    // Prepare data value set payload
    $payload = [
        'dataSet' => $datasetUid,
        'completeDate' => date('Y-m-d'),
        'period' => $period,
        'orgUnit' => $orgUnit,
        'dataValues' => []
    ];
    if (!empty($attributeOptionCombo)) {
        $payload['attributeOptionCombo'] = $attributeOptionCombo;
    }

    // Format data values for DHIS2 API
    foreach ($dataValues as $dv) {
        $dataValue = [
            'dataElement' => $dv['dataElement'],
            'value' => $dv['value']
        ];

        // Add category option combo if provided
        if (isset($dv['categoryOptionCombo']) && !empty($dv['categoryOptionCombo'])) {
            $dataValue['categoryOptionCombo'] = $dv['categoryOptionCombo'];
        }

        $payload['dataValues'][] = $dataValue;
    }

    error_log("Submitting to DHIS2 aggregate API (" . ($isUpdate ? "UPDATE" : "CREATE") . ")");
    error_log("Payload: " . json_encode($payload, JSON_PRETTY_PRINT));

    // Submit to DHIS2
    $endpoint = $asyncSubmit ? 'dataValueSets?async=true' : 'dataValueSets';
    $response = dhis2_post($endpoint, $payload, $instanceKey);

    if (!$response) {
        throw new Exception('Failed to submit data to DHIS2: No response received');
    }

    error_log("DHIS2 Response: " . json_encode($response, JSON_PRETTY_PRINT));

    // Check for errors in response
    if (isset($response['status']) && $response['status'] === 'ERROR') {
        $errorMsg = 'DHIS2 reported errors: ';
        if (isset($response['description'])) {
            $errorMsg .= $response['description'];
        }
        if (isset($response['conflicts'])) {
            $errorMsg .= ' Conflicts: ' . json_encode($response['conflicts']);
        }
        throw new Exception($errorMsg);
    }

    // Check import counts
    if (isset($response['importCount'])) {
        $imported = $response['importCount']['imported'] ?? 0;
        $updated = $response['importCount']['updated'] ?? 0;
        $ignored = $response['importCount']['ignored'] ?? 0;
        $deleted = $response['importCount']['deleted'] ?? 0;

        error_log("Import summary - Imported: $imported, Updated: $updated, Ignored: $ignored, Deleted: $deleted");

        if ($imported == 0 && $updated == 0) {
            error_log("Warning: No data values were imported or updated");
        }
    }

    // Optionally mark dataset as complete
    if ($markComplete) {
        try {
            markDatasetComplete($datasetUid, $period, $orgUnit, $instanceKey);
        } catch (Exception $e) {
            error_log("Warning: Could not mark dataset as complete: " . $e->getMessage());
            // Don't fail the whole submission if marking complete fails
        }
    }

    return $response;
}

/**
 * Mark dataset as complete in DHIS2
 */
function markDatasetComplete($datasetUid, $period, $orgUnit, $instanceKey) {
    $payload = [
        'completeDataSetRegistrations' => [
            [
                'dataSet' => $datasetUid,
                'period' => $period,
                'organisationUnit' => $orgUnit,
                'completed' => true,
                'date' => date('Y-m-d')
            ]
        ]
    ];

    error_log("Marking dataset as complete");
    $response = dhis2_post('completeDataSetRegistrations', $payload, $instanceKey);

    if ($response && isset($response['status']) && $response['status'] === 'SUCCESS') {
        error_log("Dataset marked as complete successfully");
        return true;
    }

    return false;
}

/**
 * Save dataset submission to local database
 */
function saveDatasetSubmissionLocally($surveyId, $datasetUid, $orgUnit, $period, $dataValues, $dhis2Response, $submissionUID, $isUpdate) {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO dataset_submissions (
                survey_id,
                dataset_uid,
                orgunit_uid,
                period,
                data_values,
                dhis2_response,
                uid,
                is_update,
                submitted_at,
                created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");

        $stmt->execute([
            $surveyId,
            $datasetUid,
            $orgUnit,
            $period,
            json_encode($dataValues),
            json_encode($dhis2Response),
            $submissionUID,
            $isUpdate ? 1 : 0
        ]);

        $submissionId = $pdo->lastInsertId();
        error_log("Saved dataset submission locally with ID: $submissionId");

        return $submissionId;

    } catch (PDOException $e) {
        error_log("Error saving dataset submission to database: " . $e->getMessage());
        throw new Exception("Failed to save submission to local database: " . $e->getMessage());
    }
}

/**
 * Log error to DHIS2 error log
 */
function logDHIS2Error($surveyId, $payload, $locationData, $errorMessage, $submissionType = 'aggregate') {
    global $pdo;

    try {
        $stmt = $pdo->prepare("
            INSERT INTO dhis2_error_log (
                survey_id,
                submission_type,
                payload,
                location_data,
                error_message,
                created_at
            ) VALUES (?, ?, ?, ?, ?, NOW())
        ");

        $stmt->execute([
            $surveyId,
            $submissionType,
            json_encode($payload),
            json_encode($locationData),
            $errorMessage
        ]);

        error_log("Logged DHIS2 error to database");
    } catch (PDOException $e) {
        error_log("Failed to log error to database: " . $e->getMessage());
    }
}

/**
 * Log to payload checker for retry capability
 */
function logToPayloadChecker($submissionId, $status, $payload, $response, $message) {
    global $pdo;

    try {
        $payloadJson = $payload ? json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $responseJson = $response ? json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

        $stmt = $pdo->prepare("
            INSERT INTO dhis2_submission_log (submission_id, status, payload_sent, dhis2_response, dhis2_message, submitted_at, retries)
            VALUES (?, ?, ?, ?, ?, NOW(), 0)
            ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                payload_sent = VALUES(payload_sent),
                dhis2_response = VALUES(dhis2_response),
                dhis2_message = VALUES(dhis2_message),
                submitted_at = NOW(),
                retries = retries + 1
        ");

        $stmt->execute([
            $submissionId,
            $status,
            $payloadJson,
            $responseJson,
            $message
        ]);

        error_log("Dataset submission ID $submissionId logged to dhis2_submission_log with status: $status");
    } catch (PDOException $e) {
        error_log("Failed to log dataset submission to dhis2_submission_log: " . $e->getMessage());
    }
}

/**
 * Generate a DHIS2-style UID (11 characters)
 */
function generateUID() {
    $characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $uid = '';
    $firstChar = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';

    // First character must be a letter
    $uid .= $firstChar[random_int(0, strlen($firstChar) - 1)];

    // Remaining 10 characters can be letters or numbers
    for ($i = 0; $i < 10; $i++) {
        $uid .= $characters[random_int(0, strlen($characters) - 1)];
    }

    return $uid;
}
?>
