<?php
// ajax_get_orgunits.php - Fetch organization units from DHIS2 for dataset forms
session_start();
require_once __DIR__ . '/dhis2_shared.php';

header('Content-Type: application/json');
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/php-error.log');

$response = ['success' => false, 'data' => [], 'message' => ''];

$instanceKey = $_GET['instance'] ?? '';
$surveyId = $_GET['survey_id'] ?? '';
$levelFilter = $_GET['level'] ?? ''; // Optional: filter by org unit level

if (empty($instanceKey)) {
    $response['message'] = 'Missing instance parameter.';
    echo json_encode($response);
    exit;
}

$dhis2ConfigDetails = getDhis2Config($instanceKey);
if (!$dhis2ConfigDetails) {
    $response['message'] = 'DHIS2 instance configuration not found.';
    error_log("ajax_get_orgunits.php: Config not found for instance: " . $instanceKey);
    echo json_encode($response);
    exit;
}

try {
    // Check if survey has specific org unit level filter from settings
    $selectedLevel = null;
    if (!empty($surveyId)) {
        global $pdo;
        $stmt = $pdo->prepare("SELECT selected_hierarchy_level FROM survey_settings WHERE survey_id = ?");
        $stmt->execute([$surveyId]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($settings && !empty($settings['selected_hierarchy_level'])) {
            $selectedLevel = $settings['selected_hierarchy_level'];
        }
    }

    // Use provided level filter or survey setting
    $levelFilter = !empty($levelFilter) ? $levelFilter : $selectedLevel;

    // Build the API endpoint
    $fields = 'id,name,displayName,level,path';
    $endpoint = '/organisationUnits?fields=' . $fields . '&paging=false';

    // Add level filter if specified
    if (!empty($levelFilter) && is_numeric($levelFilter)) {
        $endpoint .= '&filter=level:eq:' . $levelFilter;
    } else {
        // Default: get all org units or filter by a reasonable level
        // You can adjust this based on your needs
        $endpoint .= '&filter=level:le:4'; // Get up to level 4 by default to avoid too many results
    }

    error_log("Fetching org units from DHIS2: " . $endpoint);

    $orgUnits = dhis2_get($endpoint, $instanceKey);

    if ($orgUnits && isset($orgUnits['organisationUnits'])) {
        $response['data'] = $orgUnits['organisationUnits'];
        $response['success'] = true;
        $response['count'] = count($response['data']);
        error_log("Successfully fetched " . $response['count'] . " organization units");
    } else {
        $response['message'] = 'No organization units found or API returned unexpected format.';
        error_log("ajax_get_orgunits.php: API response format unexpected: " . json_encode($orgUnits));
    }

} catch (Exception $e) {
    $response['message'] = 'Error fetching organization units: ' . $e->getMessage();
    error_log("ajax_get_orgunits.php: " . $e->getMessage());
}

echo json_encode($response);
exit;
?>
