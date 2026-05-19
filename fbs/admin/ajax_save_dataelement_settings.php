<?php
/**
 * AJAX endpoint to save dataset data element settings
 * Handles visibility, ordering, and configuration for data elements
 */

session_start();
require_once 'connect.php';

header('Content-Type: application/json');

try {
    // Get JSON input
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);

    if (!$data) {
        throw new Exception('Invalid JSON data');
    }

    $surveyId = $data['survey_id'] ?? null;
    $elements = $data['elements'] ?? [];

    if (!$surveyId) {
        throw new Exception('Survey ID is required');
    }

    if (!is_array($elements) || empty($elements)) {
        throw new Exception('Data elements array is required');
    }

    // Begin transaction
    $pdo->beginTransaction();

    // Delete existing settings for this survey
    $deleteStmt = $pdo->prepare("DELETE FROM dataset_dataelement_settings WHERE survey_id = ?");
    $deleteStmt->execute([$surveyId]);

    // Insert new settings
    $insertStmt = $pdo->prepare("
        INSERT INTO dataset_dataelement_settings
        (survey_id, data_element_id, data_element_name, data_element_code, section_id, section_name, display_order, is_visible, is_required)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    foreach ($elements as $index => $element) {
        $insertStmt->execute([
            $surveyId,
            $element['id'],
            $element['name'],
            $element['code'] ?? '',
            $element['sectionId'] ?? null,
            $element['sectionName'] ?? null,
            $element['order'] ?? $index,
            isset($element['visible']) ? ($element['visible'] ? 1 : 0) : 1,
            isset($element['required']) ? ($element['required'] ? 1 : 0) : 0
        ]);
    }

    // Commit transaction
    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Data element settings saved successfully',
        'count' => count($elements)
    ]);

} catch (Exception $e) {
    // Rollback on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    error_log("Error in ajax_save_dataelement_settings: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
