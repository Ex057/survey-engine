<?php
/**
 * AJAX endpoint for hierarchical/cascading organization unit loading
 * Fetches org units by level or parent for drill-down selection
 */

require_once '../connect.php';
require_once 'dhis2_shared.php';

header('Content-Type: application/json');

try {
    $instance_key = $_GET['instance'] ?? null;
    $survey_id = $_GET['survey_id'] ?? null;
    $dataset_uid = $_GET['dataset'] ?? null;
    $level = $_GET['level'] ?? null;
    $parent_id = $_GET['parent'] ?? null;

    if (!$instance_key) {
        throw new Exception('DHIS2 instance key is required');
    }

    // Get DHIS2 configuration
    $dhis2_config = getDhis2Config($instance_key);
    if (!$dhis2_config) {
        throw new Exception('DHIS2 configuration not found');
    }

    // Build the API query based on parameters
    if ($parent_id) {
        // Fetch children of a specific parent
        $endpoint = "organisationUnits.json?filter=parent.id:eq:{$parent_id}&fields=id,name,displayName,level,path,children::isNotEmpty&paging=false";
    } elseif ($level) {
        // Fetch all org units at a specific level
        $endpoint = "organisationUnits.json?filter=level:eq:{$level}&fields=id,name,displayName,level,path,children::isNotEmpty&paging=false";
    } else {
        // Fetch root level (level 1) organization units
        $endpoint = "organisationUnits.json?filter=level:eq:1&fields=id,name,displayName,level,path,children::isNotEmpty&paging=false";
    }

    // If dataset is specified, we can optionally filter by dataset assignment
    // Note: This is more complex and would require fetching dataset org units first
    // For now, we'll fetch all org units at the requested level

    $data = dhis2_get($endpoint, $instance_key);

    if ($data === null) {
        throw new Exception('Failed to fetch organization units from DHIS2');
    }

    // Return the organization units
    echo json_encode([
        'success' => true,
        'data' => $data['organisationUnits'] ?? [],
        'level' => $level,
        'parent' => $parent_id
    ]);

} catch (Exception $e) {
    error_log("Error in ajax_get_orgunits_hierarchical: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
