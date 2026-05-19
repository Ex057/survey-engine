<?php
/**
 * AJAX endpoint to fetch dataset data elements with sections from DHIS2
 * Returns structured data for the preview interface
 */

session_start();
require_once 'connect.php';
require_once 'dhis2/dhis2_shared.php';
require_once 'dhis2/dataset_api_service.php';

header('Content-Type: application/json');

try {
    $surveyId = $_GET['survey_id'] ?? null;

    if (!$surveyId) {
        throw new Exception('Survey ID is required');
    }

    // Fetch survey details
    $stmt = $pdo->prepare("SELECT id, program_dataset, dhis2_instance FROM survey WHERE id = ?");
    $stmt->execute([$surveyId]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$survey) {
        throw new Exception('Survey not found');
    }

    $datasetUid = $survey['program_dataset'];
    $instanceKey = $survey['dhis2_instance'];

    if (!$datasetUid || !$instanceKey) {
        throw new Exception('Dataset UID or instance key missing');
    }

    // Fetch complete dataset using new API service
    $datasetData = DatasetApiService::getDatasetComplete($datasetUid, $instanceKey, true);

    if (!$datasetData) {
        throw new Exception('Failed to fetch dataset from DHIS2');
    }

    // Fetch existing settings from database
    $settingsStmt = $pdo->prepare("
        SELECT data_element_id, section_id, display_order, is_visible, is_required
        FROM dataset_dataelement_settings
        WHERE survey_id = ?
        ORDER BY display_order ASC
    ");
    $settingsStmt->execute([$surveyId]);
    $existingSettings = [];
    while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
        $existingSettings[$row['data_element_id']] = $row;
    }

    // Structure the response
    $response = [
        'success' => true,
        'dataset' => [
            'id' => $datasetData['id'],
            'name' => $datasetData['name']
        ],
        'formType' => $datasetData['formType'] ?? 'DEFAULT',
        'hasCustomForm' => isset($datasetData['dataEntryForm']) && !empty($datasetData['dataEntryForm']),
        'sections' => [],
        'dataElements' => []
    ];

    $globalOrder = 0;

    // Process sections if they exist
    if (isset($datasetData['sections']) && is_array($datasetData['sections'])) {
        usort($datasetData['sections'], function($a, $b) {
            return ($a['sortOrder'] ?? 0) - ($b['sortOrder'] ?? 0);
        });

        foreach ($datasetData['sections'] as $section) {
            $sectionData = [
                'id' => $section['id'],
                'name' => $section['name'],
                'sortOrder' => $section['sortOrder'] ?? 0,
                'dataElements' => []
            ];

            if (isset($section['dataElements']) && is_array($section['dataElements'])) {
                foreach ($section['dataElements'] as $de) {
                    $deId = $de['id'];
                    $settings = $existingSettings[$deId] ?? null;

                    $elementData = [
                        'id' => $deId,
                        'name' => $de['name'],
                        'code' => $de['code'] ?? '',
                        'valueType' => $de['valueType'] ?? 'TEXT',
                        'sectionId' => $section['id'],
                        'sectionName' => $section['name'],
                        'order' => $settings ? $settings['display_order'] : $globalOrder++,
                        'visible' => $settings ? (bool)$settings['is_visible'] : true,
                        'required' => $settings ? (bool)$settings['is_required'] : false,
                        'categoryCombo' => null
                    ];

                    // Process category combination if exists
                    if (isset($de['categoryCombo']) && $de['categoryCombo']['id'] !== 'default') {
                        $elementData['categoryCombo'] = [
                            'id' => $de['categoryCombo']['id'],
                            'name' => $de['categoryCombo']['name'],
                            'categories' => $de['categoryCombo']['categories'] ?? []
                        ];
                    }

                    $sectionData['dataElements'][] = $elementData;
                    $response['dataElements'][] = $elementData;
                }
            }

            $response['sections'][] = $sectionData;
        }
    } else {
        // No sections - use dataSetElements directly
        if (isset($datasetData['dataSetElements']) && is_array($datasetData['dataSetElements'])) {
            $defaultSection = [
                'id' => 'default',
                'name' => 'Data Elements',
                'sortOrder' => 0,
                'dataElements' => []
            ];

            foreach ($datasetData['dataSetElements'] as $dse) {
                $de = $dse['dataElement'];
                $deId = $de['id'];
                $settings = $existingSettings[$deId] ?? null;

                $elementData = [
                    'id' => $deId,
                    'name' => $de['name'],
                    'code' => $de['code'] ?? '',
                    'valueType' => $de['valueType'] ?? 'TEXT',
                    'sectionId' => 'default',
                    'sectionName' => 'Data Elements',
                    'order' => $settings ? $settings['display_order'] : $globalOrder++,
                    'visible' => $settings ? (bool)$settings['is_visible'] : true,
                    'required' => $settings ? (bool)$settings['is_required'] : false,
                    'categoryCombo' => null
                ];

                if (isset($de['categoryCombo']) && $de['categoryCombo']['id'] !== 'default') {
                    $elementData['categoryCombo'] = [
                        'id' => $de['categoryCombo']['id'],
                        'name' => $de['categoryCombo']['name']
                    ];
                }

                $defaultSection['dataElements'][] = $elementData;
                $response['dataElements'][] = $elementData;
            }

            $response['sections'][] = $defaultSection;
        }
    }

    // Sort all data elements by their order
    usort($response['dataElements'], function($a, $b) {
        return $a['order'] - $b['order'];
    });

    echo json_encode($response);

} catch (Exception $e) {
    error_log("Error in ajax_get_dataset_elements: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
