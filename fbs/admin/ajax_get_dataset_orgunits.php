<?php
/**
 * AJAX endpoint to fetch organization units for a dataset directly from DHIS2
 *
 * Features:
 * - Search functionality
 * - Pagination support
 * - Session caching (5 minutes)
 * - Hierarchy path display
 */

session_start();
require_once 'connect.php';
require_once 'dhis2/dataset_api_service.php';

header('Content-Type: application/json');

try {
    $datasetUid = $_GET['dataset_uid'] ?? null;
    $instanceKey = $_GET['instance_key'] ?? null;
    $surveyId = $_GET['survey_id'] ?? null;
    $search = $_GET['search'] ?? null;
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;

    // Validation
    if (!$datasetUid) {
        throw new Exception('Dataset UID is required');
    }

    if (!$instanceKey && $surveyId) {
        $stmt = $pdo->prepare("SELECT dhis2_instance FROM survey WHERE id = ?");
        $stmt->execute([$surveyId]);
        $survey = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($survey && !empty($survey['dhis2_instance'])) {
            $instanceKey = $survey['dhis2_instance'];
        }
    }

    if (!$instanceKey) {
        throw new Exception('DHIS2 instance key is required');
    }

    if ($page < 1) {
        $page = 1;
    }

    if ($limit < 1 || $limit > 100) {
        $limit = 50;
    }

    // Prefer locally stored org units for this survey to avoid heavy DHIS2 calls
    if ($surveyId) {
        $searchLike = '%' . ($search ?? '') . '%';
        $offset = ($page - 1) * $limit;

        $countSql = "
            SELECT COUNT(*)
            FROM dataset_org_units
            WHERE survey_id = ?
              AND (org_unit_name LIKE ? OR org_unit_display_name LIKE ?)
        ";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute([$surveyId, $searchLike, $searchLike]);
        $total = (int)$countStmt->fetchColumn();

        $dataSql = "
            SELECT org_unit_uid, org_unit_name, org_unit_display_name, path
            FROM dataset_org_units
            WHERE survey_id = ?
              AND (org_unit_name LIKE ? OR org_unit_display_name LIKE ?)
            ORDER BY org_unit_name
            LIMIT ? OFFSET ?
        ";
        $dataStmt = $pdo->prepare($dataSql);
        $dataStmt->bindValue(1, $surveyId, PDO::PARAM_INT);
        $dataStmt->bindValue(2, $searchLike, PDO::PARAM_STR);
        $dataStmt->bindValue(3, $searchLike, PDO::PARAM_STR);
        $dataStmt->bindValue(4, (int)$limit, PDO::PARAM_INT);
        $dataStmt->bindValue(5, (int)$offset, PDO::PARAM_INT);
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        $orgUnits = array_map(function($row) {
            return [
                'id' => $row['org_unit_uid'],
                'name' => $row['org_unit_name'],
                'displayName' => $row['org_unit_display_name'] ?: $row['org_unit_name'],
                'hierarchyPath' => $row['path'] ?? ''
            ];
        }, $rows);

        $pageCount = $limit > 0 ? (int)ceil($total / $limit) : 1;

        $result = [
            'success' => true,
            'orgUnits' => $orgUnits,
            'pagination' => [
                'page' => $page,
                'pageSize' => $limit,
                'total' => $total,
                'pageCount' => $pageCount
            ],
            'hasMore' => $page < $pageCount
        ];

        echo json_encode($result);
        exit();
    }

    // Fallback: DHIS2 API (with session cache)
    $cacheKey = "orgunits_{$datasetUid}_{$instanceKey}_" . md5($search ?? '');
    $cacheValid = false;

    if (isset($_SESSION[$cacheKey]) && isset($_SESSION["{$cacheKey}_time"])) {
        $cacheAge = time() - $_SESSION["{$cacheKey}_time"];
        if ($cacheAge < 300) { // 5 minutes
            $cacheValid = true;
        }
    }

    if ($cacheValid && isset($_SESSION[$cacheKey])) {
        $response = $_SESSION[$cacheKey];
        error_log("Using cached org units for dataset: {$datasetUid}, page: {$page}");
    } else {
        $response = DatasetApiService::getDatasetOrganisationUnits(
            $datasetUid,
            $instanceKey,
            $search,
            $page,
            $limit
        );

        if (!$response) {
            throw new Exception('Failed to fetch organization units from DHIS2');
        }

        $_SESSION[$cacheKey] = $response;
        $_SESSION["{$cacheKey}_time"] = time();
    }

    $orgUnits = $response['organisationUnits'] ?? [];
    $pager = $response['pager'] ?? null;

    $result = [
        'success' => true,
        'orgUnits' => $orgUnits,
        'pagination' => [
            'page' => $pager['page'] ?? $page,
            'pageSize' => $pager['pageSize'] ?? $limit,
            'total' => $pager['total'] ?? count($orgUnits),
            'pageCount' => $pager['pageCount'] ?? 1
        ],
        'hasMore' => ($pager && $pager['page'] < $pager['pageCount']) ?? false
    ];

    echo json_encode($result);

} catch (Exception $e) {
    error_log("Error in ajax_get_dataset_orgunits: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
