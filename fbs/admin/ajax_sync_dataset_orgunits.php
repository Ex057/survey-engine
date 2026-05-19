<?php
/**
 * AJAX endpoint to manually sync dataset org units from DHIS2
 *
 * This endpoint:
 * 1. Deletes all existing org units for the survey
 * 2. Fetches fresh org units from DHIS2 (only attached ones)
 * 3. Stores them in local database
 * 4. Updates last sync timestamp
 */
session_start();
require_once 'connect.php';
require_once 'dhis2/dhis2_shared.php';

// Increase execution time and memory for large datasets
set_time_limit(600); // 10 minutes
ini_set('memory_limit', '512M');

header('Content-Type: application/json');

try {
    // Verify request method
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid request method');
    }

    $surveyId = $_POST['survey_id'] ?? null;
    $datasetUid = $_POST['dataset_uid'] ?? null;
    $instanceKey = $_POST['instance_key'] ?? null;

    // Validation
    if (!$surveyId || !$datasetUid || !$instanceKey) {
        throw new Exception('Missing required parameters: survey_id, dataset_uid, or instance_key');
    }

    // Verify survey exists and matches dataset
    $stmt = $pdo->prepare("SELECT id, program_dataset, dhis2_instance, program_type
                           FROM survey
                           WHERE id = ?");
    $stmt->execute([$surveyId]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$survey) {
        throw new Exception('Survey not found');
    }

    // Verify dataset matches
    if ($survey['program_dataset'] !== $datasetUid) {
        throw new Exception('Dataset UID mismatch');
    }

    // Verify instance matches
    if ($survey['dhis2_instance'] !== $instanceKey) {
        throw new Exception('DHIS2 instance mismatch');
    }

    // Verify this is an aggregate dataset
    if ($survey['program_type'] !== 'aggregate') {
        throw new Exception('This feature is only for aggregate datasets');
    }

    error_log("[ORGUNIT SYNC] Starting manual sync for survey {$surveyId}, dataset {$datasetUid}");

    // Step 1: Delete existing org units for this survey (OUTSIDE transaction so it commits immediately)
    // Verify count before deletion
    $countStmt = $pdo->prepare("SELECT COUNT(*) as count FROM dataset_org_units WHERE survey_id = ?");
    $countStmt->execute([$surveyId]);
    $beforeCount = $countStmt->fetchColumn();
    error_log("[ORGUNIT SYNC] Found {$beforeCount} existing org units to delete");

    // Delete old records (this commits immediately in autocommit mode)
    $deleteStmt = $pdo->prepare("DELETE FROM dataset_org_units WHERE survey_id = ?");
    $deleteStmt->execute([$surveyId]);
    $deletedCount = $deleteStmt->rowCount();
    error_log("[ORGUNIT SYNC] Deleted {$deletedCount} old org units");

    // Verify deletion worked
    $countStmt->execute([$surveyId]);
    $afterCount = $countStmt->fetchColumn();
    if ($afterCount > 0) {
        error_log("[ORGUNIT SYNC] WARNING: {$afterCount} records still remain after deletion!");
        throw new Exception("Failed to delete all existing org units. {$afterCount} records remain.");
    } else {
        error_log("[ORGUNIT SYNC] Deletion verified - table is clean");
    }

    // Step 2: Fetch org units from DHIS2 with ancestors for human-readable paths
    $apiEndpoint = "/api/dataSets/{$datasetUid}/organisationUnits.json?fields=id,name,displayName,parent[id,name],path,level,ancestors[id,name,displayName]&paging=false";

    error_log("[ORGUNIT SYNC] Fetching from DHIS2: {$apiEndpoint}");
    $response = dhis2_get($apiEndpoint, $instanceKey);

    if (!$response || !isset($response['organisationUnits'])) {
        throw new Exception('Failed to fetch org units from DHIS2. Please check DHIS2 connection and dataset UID.');
    }

    $orgUnits = $response['organisationUnits'];

    if (empty($orgUnits)) {
        error_log("[ORGUNIT SYNC] No org units attached to this dataset in DHIS2");

        // Update last sync timestamp even if no org units
        $updateStmt = $pdo->prepare("
            INSERT INTO survey_settings (survey_id, orgunit_last_sync, settings_created_at, settings_updated_at)
            VALUES (?, NOW(), NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                orgunit_last_sync = NOW(),
                settings_updated_at = NOW()
        ");
        $updateStmt->execute([$surveyId]);

        echo json_encode([
            'success' => true,
            'count' => 0,
            'deleted' => $deletedCount,
            'message' => 'No organization units are attached to this dataset in DHIS2'
        ]);
        exit();
    }

    error_log("[ORGUNIT SYNC] Fetched " . count($orgUnits) . " org units from DHIS2");

    // Deduplicate org units by UID (DHIS2 sometimes returns duplicates)
    $uniqueOrgUnits = [];
    $duplicateCount = 0;
    foreach ($orgUnits as $ou) {
        $uid = $ou['id'] ?? '';
        if ($uid && !isset($uniqueOrgUnits[$uid])) {
            $uniqueOrgUnits[$uid] = $ou;
        } else if ($uid) {
            $duplicateCount++;
        }
    }

    $orgUnits = array_values($uniqueOrgUnits); // Re-index array
    error_log("[ORGUNIT SYNC] After deduplication: " . count($orgUnits) . " unique org units" .
              ($duplicateCount > 0 ? " (removed {$duplicateCount} duplicates)" : ""));

    // Step 3: Insert new org units using batch inserts for performance
    $insertedCount = 0;
    $batchSize = 500; // Insert 500 records at a time
    $totalBatches = ceil(count($orgUnits) / $batchSize);

    error_log("[ORGUNIT SYNC] Starting batch insert: " . count($orgUnits) . " org units in {$totalBatches} batches");

    // Process in batches
    for ($batchNum = 0; $batchNum < $totalBatches; $batchNum++) {
        $offset = $batchNum * $batchSize;
        $batch = array_slice($orgUnits, $offset, $batchSize);

        // Build batch insert query
        $values = [];
        $params = [];

        foreach ($batch as $ou) {
            $ouUid = $ou['id'] ?? '';
            if (!$ouUid) continue; // Skip if no UID

            $ouName = $ou['name'] ?? '';
            $ouDisplayName = $ou['displayName'] ?? $ouName;
            $parentUid = isset($ou['parent']['id']) ? $ou['parent']['id'] : null;
            $parentName = isset($ou['parent']['name']) ? $ou['parent']['name'] : null;
            $level = $ou['level'] ?? null;

            // Build human-readable path from ancestors
            $humanReadablePath = '';
            if (isset($ou['ancestors']) && is_array($ou['ancestors']) && !empty($ou['ancestors'])) {
                $ancestorNames = [];
                foreach ($ou['ancestors'] as $ancestor) {
                    $ancestorNames[] = $ancestor['displayName'] ?? $ancestor['name'] ?? '';
                }
                // Add current org unit name
                $ancestorNames[] = $ouDisplayName;
                $humanReadablePath = implode(' > ', $ancestorNames);
            } else {
                // Fallback: just use the org unit name
                $humanReadablePath = $ouDisplayName;
            }

            $values[] = "(?, ?, ?, ?, ?, ?, ?, ?)";
            $params = array_merge($params, [
                $surveyId, $ouUid, $ouName, $ouDisplayName,
                $parentUid, $parentName, $humanReadablePath, $level
            ]);
        }

        if (empty($values)) {
            continue; // Skip empty batches
        }

        // Execute batch insert
        try {
            $sql = "INSERT INTO dataset_org_units (
                survey_id, org_unit_uid, org_unit_name, org_unit_display_name,
                parent_uid, parent_name, path, level
            ) VALUES " . implode(", ", $values);

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $insertedCount += $stmt->rowCount();

            error_log("[ORGUNIT SYNC] Batch " . ($batchNum + 1) . "/{$totalBatches} completed: {$insertedCount} total inserted");

        } catch (PDOException $e) {
            error_log("[ORGUNIT SYNC] ERROR in batch " . ($batchNum + 1) . ": " . $e->getMessage());

            // On error, try to identify which record caused the issue
            if ($e->getCode() == 23000) {
                error_log("[ORGUNIT SYNC] Duplicate entry in batch - checking database state");
                // Check how many records currently exist
                $checkStmt = $pdo->prepare("SELECT COUNT(*) FROM dataset_org_units WHERE survey_id = ?");
                $checkStmt->execute([$surveyId]);
                $currentCount = $checkStmt->fetchColumn();
                error_log("[ORGUNIT SYNC] Current record count in DB: {$currentCount}");
            }

            throw new Exception('Database error during batch insert: ' . $e->getMessage());
        }
    }

    error_log("[ORGUNIT SYNC] All batches completed. Total inserted: {$insertedCount}");

    // Step 4: Update last sync timestamp
    $updateStmt = $pdo->prepare("
        INSERT INTO survey_settings (survey_id, orgunit_last_sync, settings_created_at, settings_updated_at)
        VALUES (?, NOW(), NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            orgunit_last_sync = NOW(),
            settings_updated_at = NOW()
    ");
    $updateStmt->execute([$surveyId]);

    error_log("[ORGUNIT SYNC] Sync completed successfully. Inserted: {$insertedCount}, Deleted: {$deletedCount}");

    echo json_encode([
        'success' => true,
        'count' => $insertedCount,
        'deleted' => $deletedCount,
        'message' => "Successfully synced {$insertedCount} facilities from DHIS2"
    ]);

} catch (Exception $e) {
    error_log("[ORGUNIT SYNC] Error: " . $e->getMessage());
    error_log("[ORGUNIT SYNC] Stack trace: " . $e->getTraceAsString());

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
