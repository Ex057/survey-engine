<?php
session_start();
require_once 'connect.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: survey.php");
    exit();
}

$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

$surveyId = $_POST['survey_id'] ?? null;
$showFlagBar = isset($_POST['show_flag_bar']) ? 1 : 0;
$flagBlackColor = $_POST['flag_black_color'] ?? '#000000';
$flagYellowColor = $_POST['flag_yellow_color'] ?? '#FCD116';
$flagRedColor = $_POST['flag_red_color'] ?? '#D21034';
$layoutType = $_POST['layout_type'] ?? 'horizontal';
$showFacilitySection = isset($_POST['show_facility_section']) ? 1 : 0;
$selectedInstanceKey = $_POST['selected_instance_key'] ?? null;
$selectedHierarchyLevel = $_POST['selected_hierarchy_level'] ?? null;
$facilitySectionTitle = $_POST['facility_section_title'] ?? null;
$facilitySearchLabel = $_POST['facility_search_label'] ?? null;
$facilitySearchPlaceholder = $_POST['facility_search_placeholder'] ?? null;
$facilitySelectedLabel = $_POST['facility_selected_label'] ?? null;
$periodSectionTitle = $_POST['period_section_title'] ?? null;
$periodSelectLabel = $_POST['period_select_label'] ?? null;
$searchMinCharsInstruction = $_POST['search_min_chars_instruction'] ?? null;

if (!$surveyId) {
    if ($isAjax) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Survey ID is missing.']);
        exit();
    }
    $_SESSION['error_message'] = "Survey ID is missing.";
    header("Location: survey.php");
    exit();
}

try {
    // Check if settings exist
    $stmt = $pdo->prepare("SELECT id FROM survey_settings WHERE survey_id = ?");
    $stmt->execute([$surveyId]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        // Update existing settings
        $stmt = $pdo->prepare("
            UPDATE survey_settings
            SET show_flag_bar = ?,
                flag_black_color = ?,
                flag_yellow_color = ?,
                flag_red_color = ?,
                image_layout_type = ?,
                show_facility_section = ?,
                selected_instance_key = ?,
                selected_hierarchy_level = ?,
                facility_section_title = ?,
                facility_search_label = ?,
                facility_search_placeholder = ?,
                facility_selected_label = ?,
                period_section_title = ?,
                period_select_label = ?,
                search_min_chars_instruction = ?,
                settings_updated_at = NOW()
            WHERE survey_id = ?
        ");
        $stmt->execute([
            $showFlagBar,
            $flagBlackColor,
            $flagYellowColor,
            $flagRedColor,
            $layoutType,
            $showFacilitySection,
            $selectedInstanceKey,
            $selectedHierarchyLevel,
            $facilitySectionTitle,
            $facilitySearchLabel,
            $facilitySearchPlaceholder,
            $facilitySelectedLabel,
            $periodSectionTitle,
            $periodSelectLabel,
            $searchMinCharsInstruction,
            $surveyId
        ]);
    } else {
        // Insert new settings
        $stmt = $pdo->prepare("
            INSERT INTO survey_settings
            (survey_id, show_flag_bar, flag_black_color, flag_yellow_color, flag_red_color, image_layout_type, show_facility_section, selected_instance_key, selected_hierarchy_level, facility_section_title, facility_search_label, facility_search_placeholder, facility_selected_label, period_section_title, period_select_label, search_min_chars_instruction, settings_created_at, settings_updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([
            $surveyId,
            $showFlagBar,
            $flagBlackColor,
            $flagYellowColor,
            $flagRedColor,
            $layoutType,
            $showFacilitySection,
            $selectedInstanceKey,
            $selectedHierarchyLevel,
            $facilitySectionTitle,
            $facilitySearchLabel,
            $facilitySearchPlaceholder,
            $facilitySelectedLabel,
            $periodSectionTitle,
            $periodSelectLabel,
            $searchMinCharsInstruction
        ]);
    }

    if ($isAjax) {
        echo json_encode(['success' => true]);
        exit();
    }
    $_SESSION['success_message'] = "Dataset settings saved successfully!";
    header("Location: dataset_preview.php?survey_id=" . $surveyId);
    exit();

} catch (PDOException $e) {
    error_log("Error saving dataset settings: " . $e->getMessage());
    if ($isAjax) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to save settings: ' . $e->getMessage()]);
        exit();
    }
    $_SESSION['error_message'] = "Failed to save settings: " . $e->getMessage();
    header("Location: dataset_preview.php?survey_id=" . $surveyId);
    exit();
}
?>
