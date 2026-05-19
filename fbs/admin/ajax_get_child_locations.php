<?php
/**
 * AJAX endpoint to get child locations for cascading selector
 * Uses the existing location table structure
 */

require_once 'connect.php';

header('Content-Type: application/json');

try {
    $instance_key = $_GET['instance_key'] ?? null;
    $parent_id = $_GET['parent_id'] ?? null;
    $level = $_GET['level'] ?? null;

    if (!$instance_key) {
        throw new Exception('Instance key is required');
    }

    // Build query based on parameters
    if ($parent_id) {
        // Get children of specific parent
        $stmt = $pdo->prepare("
            SELECT id, uid, name, hierarchylevel, parent_id
            FROM location
            WHERE instance_key = ? AND parent_id = ?
            ORDER BY name ASC
        ");
        $stmt->execute([$instance_key, $parent_id]);
    } elseif ($level) {
        // Get all locations at specific level (for root level)
        $stmt = $pdo->prepare("
            SELECT id, uid, name, hierarchylevel, parent_id
            FROM location
            WHERE instance_key = ? AND hierarchylevel = ?
            ORDER BY name ASC
        ");
        $stmt->execute([$instance_key, $level]);
    } else {
        throw new Exception('Either parent_id or level is required');
    }

    $locations = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'data' => $locations,
        'count' => count($locations)
    ]);

} catch (Exception $e) {
    error_log("Error in ajax_get_child_locations: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
