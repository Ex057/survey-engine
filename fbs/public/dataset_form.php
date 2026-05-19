<?php
session_start();

require_once '../admin/connect.php';
require_once '../admin/dhis2/dhis2_shared.php';
require_once '../admin/dhis2/dataset_api_service.php';
require_once '../admin/dhis2/dataset_storage_service.php';
require_once 'includes/form_renderers/FormRendererFactory.php';
require_once 'includes/form_renderers/SectionFormRenderer.php';

// Function to show survey status messages
function showSurveyMessage($title, $message, $type = 'info') {
    $iconClass = '';
    $bgClass = '';
    $textClass = '';

    switch ($type) {
        case 'warning':
            $iconClass = 'fa-exclamation-triangle text-warning';
            $bgClass = 'bg-warning-subtle';
            $textClass = 'text-warning-emphasis';
            break;
        case 'info':
            $iconClass = 'fa-info-circle text-info';
            $bgClass = 'bg-info-subtle';
            $textClass = 'text-info-emphasis';
            break;
        case 'error':
            $iconClass = 'fa-times-circle text-danger';
            $bgClass = 'bg-danger-subtle';
            $textClass = 'text-danger-emphasis';
            break;
        case 'deadline':
            $iconClass = 'fa-clock text-white';
            $bgClass = 'bg-danger';
            $textClass = 'text-white';
            break;
        default:
            $iconClass = 'fa-info-circle text-primary';
            $bgClass = 'bg-primary-subtle';
            $textClass = 'text-primary-emphasis';
    }
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
    <link rel="icon" href="/fbs/public/logo.jpeg?v=1" type="image/jpeg">
    <link rel="shortcut icon" href="/fbs/public/logo.jpeg?v=1" type="image/jpeg">
    <link rel="apple-touch-icon" href="/fbs/public/logo.jpeg?v=1">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title><?= htmlspecialchars($title) ?></title>
        <link rel="icon" href="/fbs/public/logo.jpeg?v=1" type="image/jpeg">
        <link rel="shortcut icon" href="/fbs/public/logo.jpeg?v=1" type="image/jpeg">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
        <style>
            body {
                background: #f8f9fa;
                min-height: 100vh;
                font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            }
            .message-container {
                box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
                border-radius: 8px;
            }
            .message-icon {
                font-size: 3rem;
                margin-bottom: 1rem;
            }
        </style>
    </head>
    <body>
        <div class="container d-flex align-items-center justify-content-center min-vh-100">
            <div class="row w-100 justify-content-center">
                <div class="col-md-8 col-lg-6">
                    <div class="card message-container <?= $bgClass ?> border-0">
                        <div class="card-body text-center p-5">
                            <div class="message-icon mb-4">
                                <i class="fas <?= $iconClass ?>"></i>
                            </div>
                            <h2 class="card-title mb-3 <?= $textClass ?>"><?= htmlspecialchars($title) ?></h2>
                            <p class="card-text lead <?= $textClass ?>"><?= htmlspecialchars($message) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Get survey_id from URL
$surveyId = $_GET['survey_id'] ?? null;
if (!$surveyId) {
    die("Survey ID is missing.");
}

// Preview mode flag (used to keep preview params and disable submissions)
$isPreview = !empty($_GET['preview']);
$isInIframe = isset($_SERVER['HTTP_SEC_FETCH_DEST']) && $_SERVER['HTTP_SEC_FETCH_DEST'] === 'iframe';
if (!$isPreview && $isInIframe) {
    $referrer = $_SERVER['HTTP_REFERER'] ?? '';
    if (stripos($referrer, 'dataset_preview.php') !== false) {
        $isPreview = true;
    }
}

// Clean URL if it has unwanted parameters
// ONLY if not in an iframe (to prevent reload loops in preview)
$allowedParams = ['survey_id', 'preview', 'mobile_ui'];
$extraParams = array_diff(array_keys($_GET), $allowedParams);
if (!empty($extraParams) && !$isInIframe && !$isPreview) {
    header("Location: /d/" . $surveyId);
    exit();
}

// Fetch survey details
$survey = null;
try {
    $surveyStmt = $pdo->prepare("SELECT id, type, name, is_active, program_dataset, dhis2_instance, program_type FROM survey WHERE id = ?");
    $surveyStmt->execute([$surveyId]);
    $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error fetching survey: " . $e->getMessage());
}

if (!$survey) {
    showSurveyMessage("Survey Currently Unavailable",
        "This survey is currently unavailable. Please contact the administrator if you believe this is an error.",
        "warning");
}

// Check if survey is inactive
if ($survey && $survey['is_active'] == 0) {
    showSurveyMessage("Survey Deadline Reached",
        "The deadline for this survey has reached. Thank you for your time.",
        "deadline");
}

// Check if this is a DHIS2 aggregate dataset
if (empty($survey['program_dataset']) || $survey['program_type'] !== 'aggregate') {
    // Redirect to appropriate form type
    if ($survey['program_type'] === 'tracker') {
        header("Location: tracker_program_form.php?survey_id=" . $surveyId);
    } else {
        header("Location: survey_page.php?survey_id=" . $surveyId);
    }
    exit();
}

// Get DHIS2 configuration
$dhis2Config = null;
try {
    if (!empty($survey['dhis2_instance'])) {
        $dhis2Config = getDhis2Config($survey['dhis2_instance']);
    }
} catch (Exception $e) {
    error_log("Error fetching DHIS2 config: " . $e->getMessage());
}

if (!$dhis2Config) {
    die("DHIS2 configuration not found for this survey.");
}

// Fetch complete dataset structure from LOCAL STORAGE (not DHIS2 API)
// This is much faster and uses the data synced during survey creation
$datasetUid = $survey['program_dataset'];
$dataset = DatasetStorageService::getStoredDataset($surveyId, $datasetUid, $survey['dhis2_instance']);

if (!$dataset) {
    showSurveyMessage("Dataset Not Available",
        "Dataset metadata not found in local storage. Please ask an administrator to sync the dataset metadata.",
        "error");
}

// Get form type for logging/debugging
$formType = $dataset['formType'] ?? 'DEFAULT';
error_log("Rendering dataset form: {$datasetUid}, formType: {$formType}");

// Get dataset layout settings (unified survey_settings table)
$datasetSettings = [];
try {
    $settingsStmt = $pdo->prepare("SELECT * FROM survey_settings WHERE survey_id = ?");
    $settingsStmt->execute([$surveyId]);
    $datasetSettings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log("Error fetching dataset settings: " . $e->getMessage());
}

// Merge with defaults
$datasetSettings = array_merge([
    'layout_type' => 'horizontal',
    'show_flag_bar' => true,
    'flag_black_color' => '#000000',
    'flag_yellow_color' => '#FCD116',
    'flag_red_color' => '#D21034',
    'show_facility_section' => 1,
    'facility_section_title' => 'Facility/Organization Unit Selection',
    'facility_search_label' => 'Search Facility',
    'facility_search_placeholder' => 'Type to search for a facility...',
    'facility_selected_label' => 'Selected Facility',
    'period_section_title' => 'Reporting Period',
    'period_select_label' => 'Select Period',
    'search_min_chars_instruction' => 'Type at least 2 characters to search',
    'selected_instance_key' => $survey['dhis2_instance'],
    'selected_hierarchy_level' => null
], $datasetSettings);

if (empty($datasetSettings['layout_type']) && !empty($datasetSettings['image_layout_type'])) {
    $datasetSettings['layout_type'] = $datasetSettings['image_layout_type'];
}

// Fetch data element settings (visibility and order)
$dataElementSettings = [];
try {
    $deSettingsStmt = $pdo->prepare("
        SELECT data_element_id, section_id, display_order, is_visible, is_required
        FROM dataset_dataelement_settings
        WHERE survey_id = ?
        ORDER BY display_order ASC
    ");
    $deSettingsStmt->execute([$surveyId]);
    while ($row = $deSettingsStmt->fetch(PDO::FETCH_ASSOC)) {
        $dataElementSettings[$row['data_element_id']] = $row;
    }
} catch (PDOException $e) {
    error_log("Error fetching data element settings: " . $e->getMessage());
}

$defaultDatasetTitle = htmlspecialchars($survey['name'] ?? $dataset['name']);

// Fetch attribute category combo (for dataset-level attributes like "School Term")
$attributeCategoryCombo = DatasetStorageService::getAttributeCategoryCombo($surveyId);

// Pass settings to JavaScript
$instanceKey = $datasetSettings['selected_instance_key'] ?? $survey['dhis2_instance'];
$hierarchyLevel = $datasetSettings['selected_hierarchy_level'] ?? '';
$isCustomForm = strtoupper((string) ($formType ?? '')) === 'CUSTOM';
$mobileUiMode = $_GET['mobile_ui'] ?? '';
$mobileUiQueryRaw = strtolower((string) ($_SERVER['QUERY_STRING'] ?? ''));
$mobileUiUriRaw = strtolower((string) ($_SERVER['REQUEST_URI'] ?? ''));
$forceMobileAccordion = in_array(strtolower((string) $mobileUiMode), ['1', 'true', 'yes', 'accordion'], true)
    || strpos($mobileUiQueryRaw, 'mobile_ui=1') !== false
    || strpos($mobileUiQueryRaw, 'mobile_ui=true') !== false
    || strpos($mobileUiQueryRaw, 'mobile_ui=yes') !== false
    || strpos($mobileUiQueryRaw, 'mobile_ui=accordion') !== false
    || strpos($mobileUiUriRaw, 'mobile_ui=1') !== false
    || strpos($mobileUiUriRaw, 'mobile_ui=true') !== false
    || strpos($mobileUiUriRaw, 'mobile_ui=yes') !== false
    || strpos($mobileUiUriRaw, 'mobile_ui=accordion') !== false;

// Extract custom form styles/scripts (inline only)
$customFormStyle = $dataset['dataEntryForm']['style'] ?? '';
$customFormScripts = [];
if (!empty($dataset['dataEntryForm']['htmlCode'])) {
    if (preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $dataset['dataEntryForm']['htmlCode'], $matches, PREG_SET_ORDER)) {
        $seen = [];
        foreach ($matches as $match) {
            $attrs = $match[1] ?? '';
            if (stripos($attrs, 'src=') !== false) {
                continue;
            }
            $script = trim($match[2] ?? '');
            if ($script === '') {
                continue;
            }
            $key = md5($script);
            if (!isset($seen[$key])) {
                $customFormScripts[] = $script;
                $seen[$key] = true;
            }
        }
    }
}

// In forced mobile accordion mode, disable custom DHIS2 style/script overrides
// and force section-based rendering path.
if ($forceMobileAccordion) {
    $customFormStyle = '';
    $customFormScripts = [];
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$currentUrl = $scheme . '://' . $host . $requestUri;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $defaultDatasetTitle ?></title>

    <!-- Stylesheets -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css">

    <style>
        :root {
            --primary-color: #4a5568;
            --secondary-color: #718096;
            --accent-color: #2d3748;
            --uganda-black: <?= $datasetSettings['flag_black_color'] ?>;
            --uganda-yellow: <?= $datasetSettings['flag_yellow_color'] ?>;
            --uganda-red: <?= $datasetSettings['flag_red_color'] ?>;
            --light-bg: #f7fafc;
            --primary-font: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            --text-color-dark: #2d3748;
        }

        body {
            font-family: var(--primary-font);
            background: linear-gradient(135deg, var(--light-bg) 0%, #e2e8f0 100%);
            min-height: 100vh;
            padding: 20px;
        }

        .flag-bar {
            height: 8px;
            display: flex;
            width: 100%;
            border-radius: 4px 4px 0 0;
            overflow: hidden;
        }

        .flag-bar .black { background: var(--uganda-black); flex: 1; }
        .flag-bar .yellow { background: var(--uganda-yellow); flex: 1; }
        .flag-bar .red { background: var(--uganda-red); flex: 1; }

        .form-container {
            max-width: 2000px;
            margin: 0 auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.1);
            overflow: hidden;
        }

        .form-header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px 30px;
            text-align: center;
        }

        .form-header h1 {
            margin: 0;
            font-size: 1.6rem;
            font-weight: 600;
        }

        .form-header .description {
            margin-top: 8px;
            opacity: 0.9;
            font-size: 0.9rem;
        }

        .form-body {
            padding: 20px 30px;
        }

        /* Category Grid Styles */
        .category-grid {
            font-size: 0.9rem;
            margin-bottom: 20px;
        }

        .category-grid th {
            background: #f8f9fa;
            font-weight: 600;
            padding: 10px 8px;
            font-size: 0.85rem;
        }

        .category-grid td {
            padding: 8px;
            vertical-align: middle;
        }

        .category-grid .input-cell {
            min-width: 100px;
        }

        .category-grid .data-element-label {
            font-size: 0.9rem;
        }

        .matrix-scroll {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            border: 1px solid #e5e7eb;
            border-radius: 10px;
            background: #fff;
        }

        .mobile-matrix {
            min-width: 680px;
            margin-bottom: 0;
        }

        .mobile-matrix th,
        .mobile-matrix td {
            white-space: nowrap;
        }

        .mobile-matrix th,
        .mobile-matrix td {
            padding: 6px 8px;
            font-size: 0.86rem;
        }

        .mobile-matrix tbody tr:nth-child(odd) td {
            background: #fafcff;
        }

        .mobile-matrix tbody tr:nth-child(even) td {
            background: #f3f7fb;
        }

        .mobile-matrix .sticky-col {
            position: sticky;
            left: 0;
            z-index: 2;
            background: #f8fafc;
            min-width: 160px;
            white-space: normal;
        }

        .category-selector-block,
        .data-element-group {
            border: 1px solid #e3e8ef;
            border-radius: 12px;
        }

        .category-primary-selector {
            border: 1.5px solid #b8c7d8;
            border-radius: 10px;
            background-color: #fff;
            font-weight: 600;
        }

        .section-status-dot {
            box-shadow: 0 0 0 2px #fff, 0 0 0 3px #dbe4ef;
        }

        .section-status-text {
            display: inline-block;
            padding: 2px 7px;
            border-radius: 999px;
            background: #eef3f9;
            font-size: 0.74rem;
            font-weight: 600;
            letter-spacing: 0.01em;
        }

        @media (max-width: 599px) {
            .matrix-scroll {
                overflow-x: auto;
            }
            .mobile-matrix {
                min-width: 680px;
            }
        }

        @media (min-width: 600px) {
            .matrix-scroll {
                overflow-x: visible;
            }
            .mobile-matrix {
                min-width: 0;
                width: 100%;
                table-layout: fixed;
            }
            .mobile-matrix th,
            .mobile-matrix td {
                white-space: normal;
            }
        }

        .nested-category-table {
            margin: 0;
            font-size: 0.85rem;
        }

        .nested-category-table th,
        .nested-category-table td {
            padding: 6px 8px;
        }

        /* Section Styles */
        .dataset-section {
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #e9ecef;
        }

        .dataset-section:last-child {
            border-bottom: none;
        }

        .section-header {
            margin-bottom: 15px;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
        }

        .section-title {
            font-size: 1.2rem;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .section-description {
            font-size: 0.9rem;
            margin: 0;
        }

        .section-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 15px;
            padding-bottom: 8px;
            border-bottom: 2px solid var(--uganda-yellow);
        }

        .section-status {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.85rem;
            color: #6c757d;
        }

        .section-status-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background: #adb5bd;
            display: inline-block;
        }

        .dataset-section[data-status="dirty"] .section-status-dot {
            background: #f0ad4e;
        }

        .dataset-section[data-status="saving"] .section-status-dot {
            background: #0dcaf0;
        }

        .dataset-section[data-status="saved"] .section-status-dot {
            background: #198754;
        }

        .dataset-section[data-status="error"] .section-status-dot {
            background: #dc3545;
        }

        .form-group {
            margin-bottom: 1.2rem;
        }

        .form-label {
            font-weight: 600;
            color: var(--text-color-dark);
            margin-bottom: 0.5rem;
        }

        .required-asterisk {
            color: #dc3545;
            margin-left: 3px;
        }

        .form-control, .form-select {
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            padding: 8px 12px;
            transition: all 0.3s ease;
            font-size: 0.95rem;
        }

        .data-element-input {
            max-width: 100%;
        }

        .dataset-custom-form {
            width: 100%;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
        }

        .dataset-custom-form table {
            width: 100%;
            border-collapse: collapse;
            min-width: 720px;
        }

        .dataset-custom-form th,
        .dataset-custom-form td {
            padding: 8px 10px;
            border: 1px solid #e2e8f0;
            vertical-align: top;
        }

        .dataset-custom-form input,
        .dataset-custom-form select,
        .dataset-custom-form textarea {
            width: 100%;
            max-width: 100%;
            font-size: 0.95rem;
        }

        /* Compact inputs in tables */
        .category-grid .form-control,
        .category-grid .form-select,
        .nested-category-table .form-control,
        .nested-category-table .form-select {
            padding: 6px 10px;
            font-size: 0.9rem;
        }

        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(74, 85, 104, 0.1);
        }

        #facilitySearchContainer,
        #periodSelector {
            position: relative;
            z-index: 2;
        }

        .btn-submit {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 12px 40px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1.1rem;
            width: 100%;
            transition: all 0.3s ease;
        }

        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(74, 85, 104, 0.3);
        }


        .loading-spinner {
            display: none;
            text-align: center;
            padding: 20px;
        }

        .loading-spinner.active {
            display: block;
        }

        .alert-custom {
            border-radius: 8px;
            border: none;
            padding: 15px 20px;
        }

        .validation-error {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.1);
        }

        .data-element-group {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }

        .helper-text {
            font-size: 0.85rem;
            color: #6c757d;
            margin-top: 5px;
        }

        .period-selector {
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .period-selector select {
            flex: 1;
        }

        .category-pane {
            display: none;
        }

        .category-pane.active {
            display: block;
        }

        .mobile-section-nav {
            display: none;
        }

        .mobile-section-tabs {
            display: none;
        }

        .mobile-ui-forced .form-header {
            padding: 10px 14px;
        }

        .mobile-ui-forced .form-header h1 {
            font-size: 1.05rem;
            line-height: 1.25;
            margin: 0;
        }

        .mobile-ui-forced .form-header .description {
            margin-top: 4px;
            font-size: 0.82rem;
            line-height: 1.25;
        }

        .mobile-ui-forced .form-body {
            padding: 16px;
        }

        .mobile-ui-forced .mobile-section-nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 10px;
            background: #e9eff6;
            border: 2px solid #c7d3e0;
            border-radius: 12px;
            padding: 10px 12px;
            margin-bottom: 12px;
        }

        .mobile-ui-forced .mobile-section-nav .nav-title {
            font-weight: 700;
            font-size: 0.98rem;
            color: #243447;
            text-align: center;
            flex: 1;
            line-height: 1.25;
        }

        .mobile-ui-forced .mobile-section-nav .nav-btn {
            border: none;
            background: #fff;
            border-radius: 10px;
            width: 36px;
            height: 36px;
            color: #2d4b66;
            font-weight: 700;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.14);
        }

        @media (max-width: 768px) {
            .form-header {
                padding: 10px 14px;
            }

            .form-header h1 {
                font-size: 1.05rem;
                line-height: 1.25;
                margin: 0;
            }

            .form-header .description {
                margin-top: 4px;
                font-size: 0.82rem;
                line-height: 1.25;
            }

            .mobile-section-nav {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 10px;
                background: #e9eff6;
                border: 2px solid #c7d3e0;
                border-radius: 12px;
                padding: 10px 12px;
                margin-bottom: 12px;
            }

            .mobile-section-tabs {
                display: flex;
                gap: 8px;
                overflow-x: auto;
                -webkit-overflow-scrolling: touch;
                scrollbar-width: thin;
                padding: 4px 2px 8px;
                margin-bottom: 8px;
            }

            .mobile-section-tabs .tab-btn {
                flex: 0 0 auto;
                border: 1px solid #c7d3e0;
                background: #fff;
                color: #27435c;
                border-radius: 999px;
                padding: 8px 14px;
                font-size: 0.86rem;
                font-weight: 600;
                line-height: 1.2;
                white-space: normal;
                text-align: center;
                min-width: 180px;
                max-width: 260px;
            }

            .mobile-section-tabs .tab-btn.active {
                background: #2f6ea6;
                color: #fff;
                border-color: #2f6ea6;
            }

            .mobile-section-nav .nav-title {
                font-weight: 700;
                font-size: 0.98rem;
                color: #243447;
                text-align: center;
                flex: 1;
                line-height: 1.25;
            }

            .mobile-section-nav .nav-btn {
                border: none;
                background: #fff;
                border-radius: 10px;
                width: 36px;
                height: 36px;
                color: #2d4b66;
                font-weight: 700;
                box-shadow: 0 1px 4px rgba(0, 0, 0, 0.14);
            }

            .mobile-section-nav .nav-btn:disabled {
                opacity: 0.45;
                box-shadow: none;
            }

            .mobile-sections-condensed .mobile-section-item {
                display: none !important;
            }

            .mobile-sections-condensed .mobile-section-item.active-mobile-section {
                display: block !important;
            }

            .form-container {
                border-radius: 10px;
            }

            .form-body {
                padding: 16px;
            }

            .period-selector {
                flex-direction: column;
            }

            .dataset-custom-form table {
                display: block;
                overflow-x: auto;
                min-width: 640px;
            }

            .dataset-custom-form th,
            .dataset-custom-form td {
                padding: 10px 12px;
            }

            .dataset-custom-form input,
            .dataset-custom-form select,
            .dataset-custom-form textarea {
                font-size: 1rem;
                min-width: 140px;
            }

            .dataset-section.is-mobile-accordion .section-header {
                cursor: pointer;
                user-select: none;
                margin-bottom: 0;
                padding: 14px 16px;
                border: 2px solid #d6dde6;
                border-radius: 12px;
                background: #f8fafc;
                box-shadow: 0 2px 8px rgba(30, 41, 59, 0.06);
            }

            .dataset-section.is-mobile-accordion .section-title {
                border-bottom: none;
                margin-bottom: 0;
                padding-bottom: 0;
                font-size: 1.08rem;
            }

            .dataset-section.is-mobile-accordion .section-content {
                display: none;
                padding: 14px 12px 12px;
                border-left: 2px solid #d6dde6;
                border-right: 2px solid #d6dde6;
                border-bottom: 2px solid #d6dde6;
                border-radius: 0 0 12px 12px;
                background: #fff;
            }

            .dataset-section.is-mobile-accordion {
                margin-bottom: 14px;
                padding-bottom: 0;
                border-bottom: none;
            }

            .dataset-section.is-mobile-accordion.expanded .section-header {
                border-bottom-left-radius: 0;
                border-bottom-right-radius: 0;
                border-color: #b8c4d1;
                background: #f3f6fa;
            }

            .dataset-section.is-mobile-accordion.expanded .section-content {
                display: block;
            }

            .accordion-chevron {
                margin-left: auto;
                color: #6c757d;
                transition: transform 0.2s ease;
            }

            .dataset-section.is-mobile-accordion.expanded .accordion-chevron {
                transform: rotate(180deg);
            }

            .dataset-custom-form.is-cardified table {
                width: 100%;
                min-width: 0;
                border: none !important;
                display: block !important;
            }

            .dataset-custom-form.is-cardified thead {
                display: none !important;
            }

            .dataset-custom-form.is-cardified tbody,
            .dataset-custom-form.is-cardified tr,
            .dataset-custom-form.is-cardified td {
                display: block !important;
                width: 100% !important;
            }

            .dataset-custom-form.is-cardified tr {
                border: 1px solid #e2e8f0 !important;
                border-radius: 10px;
                margin-bottom: 12px;
                padding: 8px 10px;
                background: #fff;
            }


            .dataset-custom-form.is-cardified td {
                border: none;
                padding: 8px 0;
            }

            .dataset-custom-form.is-cardified td[data-mobile-label]::before {
                content: attr(data-mobile-label);
                display: block;
                font-size: 0.78rem;
                font-weight: 700;
                color: #6c757d;
                margin-bottom: 4px;
                text-transform: uppercase;
                letter-spacing: 0.02em;
                white-space: normal;
                overflow: visible;
                text-overflow: clip;
                word-break: break-word;
            }


            .dataset-custom-form.is-cardified input,
            .dataset-custom-form.is-cardified select,
            .dataset-custom-form.is-cardified textarea {
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
            }

            .dataset-custom-form.is-cardified th,
            .dataset-custom-form.is-cardified td,
            .dataset-custom-form.is-cardified label,
            .dataset-custom-form.is-cardified .data-element-label {
                white-space: normal !important;
                overflow: visible !important;
                text-overflow: clip !important;
                word-break: normal !important;
                overflow-wrap: anywhere !important;
                line-height: 1.25;
            }

            .dataset-custom-form.is-cardified .form-control,
            .dataset-custom-form.is-cardified .form-select {
                min-height: 44px;
                border-radius: 10px;
                padding-top: 10px;
                padding-bottom: 10px;
            }

            .dataset-custom-form.is-cardified img {
                max-width: 100% !important;
                height: auto !important;
            }
        }

        @media (max-width: 480px) {
            .dataset-custom-form table {
                min-width: 560px;
            }

            .dataset-custom-form th,
            .dataset-custom-form td {
                padding: 8px 10px;
            }

            .dataset-custom-form input,
            .dataset-custom-form select,
            .dataset-custom-form textarea {
                min-width: 120px;
            }
        }
    </style>
    <?php if (!empty($customFormStyle)): ?>
        <style>
            <?= $customFormStyle ?>
        </style>
    <?php endif; ?>
    <?php if ($isPreview): ?>
        <style>
            #datasetForm {
                pointer-events: none;
            }
            #datasetForm input,
            #datasetForm select,
            #datasetForm textarea,
            #datasetForm button {
                background-color: #f8f9fa;
            }
            #submitBtn,
            .dataset-custom-form button[type="submit"] {
                display: none !important;
            }
        </style>
    <?php endif; ?>
</head>
<body class="<?= $forceMobileAccordion ? 'mobile-ui-forced' : '' ?>">
    <div class="form-container">
        <?php if ($datasetSettings['show_flag_bar']): ?>
            <div class="flag-bar">
                <div class="black"></div>
                <div class="yellow"></div>
                <div class="red"></div>
            </div>
        <?php endif; ?>

        <div class="form-header">
            <h1><?= $defaultDatasetTitle ?></h1>
            <?php if (!empty($dataset['description'])): ?>
                <div class="description"><?= htmlspecialchars($dataset['description']) ?></div>
            <?php endif; ?>
        </div>

        <div class="form-body">
            <!-- Alert Messages -->
            <div id="alertContainer"></div>
            <div id="validationMessages"></div>

            <form id="datasetForm">
                <!-- Hidden Fields -->
                <input type="hidden" name="survey_id" value="<?= $surveyId ?>">
                <input type="hidden" name="dataset_uid" value="<?= $datasetUid ?>">

                <!-- Organization Unit Selection -->
                <div class="section-title">
                    <i class="fas fa-map-marker-alt me-2"></i><?= htmlspecialchars($datasetSettings['facility_section_title'] ?? 'Facility/Organization Unit Selection') ?>
                </div>

                <div class="form-group">
                    <div id="facilitySearchContainer">
                        <!-- Facility Search -->
                        <div class="row mb-3">
                            <div class="col-md-12">
                                <label for="facility_search" class="form-label">
                                    <i class="fas fa-search me-1"></i><?= htmlspecialchars($datasetSettings['facility_search_label'] ?? 'Search Facility') ?> <span class="required-asterisk">*</span>
                                </label>
                                <input type="text"
                                       id="facility_search"
                                       class="form-control"
                                       placeholder="<?= htmlspecialchars($datasetSettings['facility_search_placeholder'] ?? 'Type to search for a facility...') ?>"
                                       autocomplete="off">
                                <div class="form-text"><?= htmlspecialchars($datasetSettings['search_min_chars_instruction'] ?? 'Type at least 2 characters to search') ?></div>
                            </div>
                        </div>

                        <!-- Search Results -->
                        <div id="facility_results" style="display: none;">
                            <label class="form-label">Select Your Facility</label>
                            <div id="facility_results_list" class="list-group" style="max-height: 300px; overflow-y: auto;">
                                <!-- Results will be populated here -->
                            </div>
                        </div>
                    </div>

                    <!-- Hidden field to store final selection -->
                    <input type="hidden" id="orgunit" name="orgunit" required>

                    <!-- Selected Facility Display -->
                    <div id="selectedFacilityDisplay" class="alert alert-success" style="display: none;">
                        <i class="fas fa-check-circle me-2"></i>
                        <strong><?= htmlspecialchars($datasetSettings['facility_selected_label'] ?? 'Selected Facility') ?>:</strong> <span id="selectedFacilityText"></span>
                        <div class="text-muted small mt-1" id="selectedFacilityHierarchy"></div>
                        <button type="button" class="btn btn-sm btn-outline-secondary float-end" id="changeFacilityBtn">
                            <i class="fas fa-edit me-1"></i>Change
                        </button>
                    </div>
                </div>

                <!-- Period Selection -->
                <div class="section-title">
                    <i class="fas fa-calendar me-2"></i><?= htmlspecialchars($datasetSettings['period_section_title'] ?? 'Reporting Period') ?>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        <?= htmlspecialchars($datasetSettings['period_select_label'] ?? 'Select Period') ?> <span class="required-asterisk">*</span>
                    </label>
                    <div class="period-selector" id="periodSelector">
                        <!-- Period selector will be dynamically generated based on periodType -->
                    </div>
                    <div class="helper-text">
                        Period Type: <strong><?= htmlspecialchars($dataset['periodType']) ?></strong>
                    </div>
                    <input type="hidden" id="selectedPeriod" name="period" required>
                    <?php if (!$isPreview): ?>
                    <button type="button" class="btn btn-outline-primary btn-sm mt-2" id="loadExistingBtn">
                        <i class="fas fa-download me-1"></i>Load Existing Data
                    </button>
                    <?php endif; ?>
                </div>

                <?php if ($attributeCategoryCombo && !empty($attributeCategoryCombo['options'])): ?>
                <!-- Attribute Category Selection (e.g., School Term, Data Set) -->
                <div class="section-title">
                    <i class="fas fa-tag me-2"></i><?= htmlspecialchars($attributeCategoryCombo['category_combo_name']) ?>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        Select <?= htmlspecialchars($attributeCategoryCombo['category_combo_name']) ?> <span class="required-asterisk">*</span>
                    </label>
                    <select class="form-control" id="attributeOptionCombo" name="attributeOptionCombo" required>
                        <option value="">-- Select --</option>
                        <?php foreach ($attributeCategoryCombo['options'] as $option): ?>
                            <option value="<?= htmlspecialchars($option['aoc_uid']) ?>">
                                <?= htmlspecialchars($option['aoc_display_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="helper-text">
                        This attribute is required for data submission
                    </div>
                </div>
                <?php endif; ?>

                <!-- Data Elements -->
                <div class="section-title">
                    <i class="fas fa-table me-2"></i>Data Entry
                </div>

                <div id="dataEntryGate" class="alert alert-info alert-custom" style="<?= $isPreview ? 'display: none;' : '' ?>">
                    <i class="fas fa-info-circle me-2"></i>
                    Select facility and reporting period to start data entry.
                </div>

                <div id="dataElementsContainer" style="<?= $isPreview ? '' : 'display: none;' ?>">
                    <?php
                    // Force section-based renderer for mobile accordion mode.
                    // Otherwise, use the normal factory.
                    $activeRenderer = 'factory';
                    if ($forceMobileAccordion) {
                        $renderer = new SectionFormRenderer($dataset, $datasetSettings, $dataElementSettings);
                        $activeRenderer = 'section-forced';
                    } else {
                        $renderer = FormRendererFactory::createFromDataset($dataset, $datasetSettings, $dataElementSettings, [
                            'force_section_renderer' => false
                        ]);
                        $activeRenderer = strtoupper((string) ($dataset['formType'] ?? 'DEFAULT'));
                    }
                    echo $renderer->render();
                    ?>
                </div>

                <!-- Loading Spinner -->
                <div class="loading-spinner" id="loadingSpinner">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                    <p class="mt-3">Submitting data to DHIS2...</p>
                </div>

                <?php if (!$isPreview): ?>
                <!-- Submit Button -->
                <div class="mt-4" id="submitContainer" style="display: none;">
                    <button type="submit" class="btn btn-submit" id="submitBtn">
                        <i class="fas fa-paper-plane me-2"></i>Submit Data
                    </button>
                </div>
                <?php endif; ?>
                <div id="mobileModeBadge" style="margin-top:10px;font-size:12px;color:#495057;">
                    mode: <?= $forceMobileAccordion ? 'mobile_ui_forced' : 'normal' ?> | renderer: <?= htmlspecialchars($activeRenderer ?? 'unknown') ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

    <script>
        const surveyId = <?= $surveyId ?>;
        const datasetUid = '<?= $datasetUid ?>';
        const periodType = '<?= $dataset['periodType'] ?>';
        const dhis2Instance = '<?= $instanceKey ?>';
        const hasAttributeCombo = <?= $attributeCategoryCombo && !empty($attributeCategoryCombo['options']) ? 'true' : 'false' ?>;
        const isPreview = <?= $isPreview ? 'true' : 'false' ?>;
        const isCustomDatasetForm = <?= $isCustomForm ? 'true' : 'false' ?>;
        const forceMobileAccordion = (() => {
            const serverFlag = <?= $forceMobileAccordion ? 'true' : 'false' ?>;
            try {
                const p = new URLSearchParams(window.location.search);
                const q = (p.get('mobile_ui') || '').toLowerCase();
                const queryFlag = q === '1' || q === 'true' || q === 'yes' || q === 'accordion';
                return serverFlag || queryFlag;
            } catch (e) {
                return serverFlag;
            }
        })();

        const datasetStructure = <?= json_encode($dataset, JSON_PRETTY_PRINT) ?>;

        function buildOptionSetMap(structure) {
            const map = {};
            const addOptionSet = (optionSet) => {
                if (!optionSet || !optionSet.id || !Array.isArray(optionSet.options) || optionSet.options.length === 0) {
                    return;
                }
                map[optionSet.id] = optionSet.options;
            };

            if (structure.sections) {
                structure.sections.forEach(section => {
                    if (section.dataElements) {
                        section.dataElements.forEach(de => addOptionSet(de.optionSet));
                    }
                });
            }

            if (structure.dataSetElements) {
                structure.dataSetElements.forEach(dse => {
                    const de = dse.dataElement;
                    if (de) {
                        addOptionSet(de.optionSet);
                    }
                });
            }

            return map;
        }

        const optionSetMap = buildOptionSetMap(datasetStructure);
        let validationRules = [];
        let validationTimer = null;
        let existingDataLoaded = false;
        let isSubmitting = false;
        let submitArmed = false;
        const sectionSaveTimers = new Map();
        const sectionInFlight = new Set();
        const sectionSavedOnce = new Set();
        let locationMap = {};
        const customFormInitTasks = [];

        function registerCustomFormInitTask(fn) {
            if (typeof fn === 'function') {
                customFormInitTasks.push(fn);
            }
        }

        function applyOptionsToSelect(selectElement, options) {
            const placeholder = selectElement.querySelector('option[value=""]');
            selectElement.innerHTML = '';
            if (placeholder) {
                selectElement.appendChild(placeholder);
            } else {
                const defaultOption = document.createElement('option');
                defaultOption.value = '';
                defaultOption.textContent = 'Select...';
                selectElement.appendChild(defaultOption);
            }

            options.forEach(option => {
                const opt = document.createElement('option');
                opt.value = option.code ?? option.value ?? '';
                opt.textContent = option.displayName ?? option.label ?? option.name ?? option.code ?? '';
                selectElement.appendChild(opt);
            });
        }

        async function ensureOptionSetOptions(selectElement) {
            const optionSetId = selectElement.dataset.optionSetId;
            if (!optionSetId) {
                return;
            }

            if (selectElement.options.length > 1) {
                return;
            }

            if (optionSetMap[optionSetId]) {
                applyOptionsToSelect(selectElement, optionSetMap[optionSetId]);
                return;
            }

            try {
                const response = await fetch(`/fbs/admin/get_option_set_values.php?option_set_id=${encodeURIComponent(optionSetId)}&survey_id=${surveyId}`);
                const data = await response.json();
                const options = Array.isArray(data) ? data : (data.options || []);
                if (options.length > 0) {
                    applyOptionsToSelect(selectElement, options);
                    return;
                }
            } catch (error) {
                console.warn('Failed to load option set values:', optionSetId, error);
            }

            try {
                const response = await fetch(`/fbs/admin/dhis2/dhis2_fetch.php?instance=${encodeURIComponent(dhis2Instance)}&endpoint=optionSets/${encodeURIComponent(optionSetId)}.json?fields=options[code,displayName]`);
                const data = await response.json();
                const options = data && Array.isArray(data.options) ? data.options : [];
                if (options.length > 0) {
                    applyOptionsToSelect(selectElement, options);
                }
            } catch (error) {
                console.warn('Failed to fetch option set from DHIS2:', optionSetId, error);
            }
        }

        function parseExpressionValue(rawExpression) {
            if (!rawExpression) {
                return null;
            }

            let expression = rawExpression;
            const pattern = /#\{([A-Za-z0-9]{11})(?:\.([A-Za-z0-9]{11}))?\}/g;
            expression = expression.replace(pattern, (match, deId, cocId) => {
                const selector = cocId
                    ? `[data-de-id="${deId}"][data-coc-id="${cocId}"]`
                    : `[data-de-id="${deId}"]`;
                const input = document.querySelector(selector);
                if (!input) {
                    return '0';
                }
                const value = input.value;
                const num = parseFloat(value);
                return Number.isFinite(num) ? String(num) : '0';
            });

            if (/[^0-9+\-*/().\s]/.test(expression)) {
                return null;
            }

            try {
                // eslint-disable-next-line no-new-func
                const result = Function(`"use strict"; return (${expression});`)();
                return Number.isFinite(result) ? result : null;
            } catch (error) {
                return null;
            }
        }

        function normalizeOperator(operator) {
            if (!operator) {
                return null;
            }
            const op = operator.toLowerCase();
            const map = {
                equal_to: '==',
                not_equal_to: '!=',
                greater_than: '>',
                greater_or_equal_to: '>=',
                greater_than_or_equal_to: '>=',
                less_than: '<',
                less_or_equal_to: '<=',
                less_than_or_equal_to: '<='
            };
            return map[op] || null;
        }

        function evaluateValidationRules() {
            clearValidationMessages();
            if (!validationRules.length) {
                return true;
            }

            let isValid = true;
            const messages = [];
            const invalidFields = new Set();

            validationRules.forEach(rule => {
                if (rule.skipFormValidation) {
                    return;
                }

                const leftValue = parseExpressionValue(rule.leftSide?.expression);
                const rightValue = parseExpressionValue(rule.rightSide?.expression);
                const operator = normalizeOperator(rule.operator);

                if (leftValue === null || rightValue === null || !operator) {
                    return;
                }

                let passed = true;
                switch (operator) {
                    case '==':
                        passed = leftValue === rightValue;
                        break;
                    case '!=':
                        passed = leftValue !== rightValue;
                        break;
                    case '>':
                        passed = leftValue > rightValue;
                        break;
                    case '>=':
                        passed = leftValue >= rightValue;
                        break;
                    case '<':
                        passed = leftValue < rightValue;
                        break;
                    case '<=':
                        passed = leftValue <= rightValue;
                        break;
                    default:
                        passed = true;
                }

                if (!passed) {
                    isValid = false;
                    messages.push(rule.name || 'Validation rule failed.');
                    const pattern = /#\{([A-Za-z0-9]{11})(?:\.([A-Za-z0-9]{11}))?\}/g;
                    const collectMatches = expr => {
                        if (!expr) {
                            return;
                        }
                        let match;
                        while ((match = pattern.exec(expr)) !== null) {
                            invalidFields.add(match[1] + (match[2] ? `.${match[2]}` : ''));
                        }
                    };
                    collectMatches(rule.leftSide?.expression || '');
                    collectMatches(rule.rightSide?.expression || '');
                }
            });

            if (!isValid) {
                renderValidationMessages(messages);
                highlightValidationFields(invalidFields);
            }

            return isValid;
        }

        function highlightValidationFields(fieldKeys) {
            document.querySelectorAll('.validation-error').forEach(el => el.classList.remove('validation-error'));
            fieldKeys.forEach(key => {
                const [deId, cocId] = key.split('.');
                const selector = cocId
                    ? `[data-de-id="${deId}"][data-coc-id="${cocId}"]`
                    : `[data-de-id="${deId}"]`;
                document.querySelectorAll(selector).forEach(el => el.classList.add('validation-error'));
            });
        }

        function renderValidationMessages(messages) {
            if (!messages.length) {
                return;
            }
            const items = messages.map(msg => `<li>${msg}</li>`).join('');
            const html = `
                <div class="alert alert-danger alert-custom" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Validation errors:</strong>
                    <ul class="mb-0">${items}</ul>
                </div>
            `;
            $('#validationMessages').html(html);
        }

        function clearValidationMessages() {
            $('#validationMessages').empty();
            document.querySelectorAll('.validation-error').forEach(el => el.classList.remove('validation-error'));
        }

        function scheduleValidation() {
            if (validationTimer) {
                clearTimeout(validationTimer);
            }
            validationTimer = setTimeout(evaluateValidationRules, 300);
        }

        async function loadValidationRules() {
            try {
                const endpoint = `validationRules.json?fields=id,name,operator,leftSide[expression],rightSide[expression],skipFormValidation&filter=dataSets.id:eq:${datasetUid}`;
                const response = await fetch(`/fbs/admin/dhis2/dhis2_fetch.php?instance=${encodeURIComponent(dhis2Instance)}&endpoint=${encodeURIComponent(endpoint)}`);
                const data = await response.json();
                if (data && Array.isArray(data.validationRules)) {
                    validationRules = data.validationRules;
                }
            } catch (error) {
                console.warn('Failed to load validation rules:', error);
            }
        }

        function recalcTotals() {
            document.querySelectorAll('input[name="total"][dataelementid]').forEach(totalInput => {
                const deId = totalInput.getAttribute('dataelementid');
                if (!deId) {
                    return;
                }
                let sum = 0;
                document.querySelectorAll(`.data-element-input[data-de-id="${deId}"]`).forEach(input => {
                    const value = parseFloat(input.value);
                    if (!Number.isNaN(value)) {
                        sum += value;
                    }
                });
                totalInput.value = sum === 0 ? '' : sum;
            });
        }

        async function loadExistingData() {
            const orgUnit = $('#orgunit').val();
            const period = $('#selectedPeriod').val();
            const attributeOptionCombo = hasAttributeCombo ? $('#attributeOptionCombo').val() : '';

            if (!orgUnit || !period || (hasAttributeCombo && !attributeOptionCombo)) {
                showAlert('warning', 'Please select organization unit, period, and required attributes before loading data.');
                return;
            }

            $('#loadExistingBtn').prop('disabled', true);
            showAlert('info', 'Loading existing data values...');

            try {
                const params = new URLSearchParams({
                    dataSet: datasetUid,
                    orgUnit: orgUnit,
                    period: period,
                    fields: 'dataValues[dataElement,categoryOptionCombo,value]'
                });
                if (attributeOptionCombo) {
                    params.append('attributeOptionCombo', attributeOptionCombo);
                }

                const endpoint = `dataValueSets.json?${params.toString()}`;
                const response = await fetch(`/fbs/admin/dhis2/dhis2_fetch.php?instance=${encodeURIComponent(dhis2Instance)}&endpoint=${encodeURIComponent(endpoint)}`);
                const data = await response.json();
                const dataValues = data && Array.isArray(data.dataValues) ? data.dataValues : [];

                if (!dataValues.length) {
                    showAlert('warning', 'No existing data found for the selected facility and period.');
                    return;
                }

                const setSelectValue = async (input, value) => {
                    if (input.tagName.toLowerCase() === 'select' && input.dataset.optionSetId) {
                        await ensureOptionSetOptions(input);
                    }
                    input.value = value;
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                };

                for (const dv of dataValues) {
                    const selector = dv.categoryOptionCombo
                        ? `.data-element-input[data-de-id="${dv.dataElement}"][data-coc-id="${dv.categoryOptionCombo}"]`
                        : `.data-element-input[data-de-id="${dv.dataElement}"]`;
                    let input = document.querySelector(selector);

                    // Fallback for default category combo: inputs don't carry data-coc-id
                    if (!input) {
                        const candidates = document.querySelectorAll(`.data-element-input[data-de-id="${dv.dataElement}"]`);
                        if (candidates.length === 1) {
                            input = candidates[0];
                        }
                    }

                    if (input) {
                        await setSelectValue(input, dv.value);
                    }
                }

                recalcTotals();
                scheduleValidation();
                initializeCategorySelectors();
                existingDataLoaded = true;
                setAllSectionStatus('idle', 'Loaded');
                showAlert('success', 'Existing data loaded.');
            } catch (error) {
                console.error('Failed to load existing data:', error);
                showAlert('error', 'Failed to load existing data.');
            } finally {
                $('#loadExistingBtn').prop('disabled', false);
            }
        }

        // Clean URL immediately if it has unwanted parameters
        // Only if not in an iframe to prevent reload loops
        (function() {
            const isInIframe = window.self !== window.top;
            console.log('[FORM] Page loaded. Is in iframe:', isInIframe);

            if (!isInIframe) {
                const url = new URL(window.location.href);
                const params = url.searchParams;
                console.log('[FORM] Current URL params:', Array.from(params.keys()));

                // If there are any parameters other than survey_id, clean the URL
                if (params.has('dataset_uid') || params.has('orgunit') || params.has('period') ||
                    params.has('entryfield') || params.has('indicator')) {
                    console.log('[FORM] Cleaning URL from unwanted parameters...');
                    window.history.replaceState({}, '', '/d/' + surveyId);
                } else {
                    console.log('[FORM] URL is clean, no action needed');
                }
            } else {
                console.log('[FORM] Running in iframe, skipping URL cleanup to prevent loops');
            }
        })();

        $(document).ready(function() {
            registerCustomFormInitTask(() => loadLocationMap());
            registerCustomFormInitTask(() => setupMobileSectionAccordion());
            registerCustomFormInitTask(() => transformCustomTablesForMobile());
            registerCustomFormInitTask(() => observeCustomFormForMobileTransforms());
            registerCustomFormInitTask(() => compactSectionMenuForMobile());
            registerCustomFormInitTask(() => initKotlinStyleSectionNavigator());

            // Facility search functionality
            let searchTimeout;
            $('#facility_search').on('input', function() {
                const searchTerm = $(this).val().trim();

                clearTimeout(searchTimeout);

                if (searchTerm.length >= 2) {
                    searchTimeout = setTimeout(function() {
                        searchFacilities(searchTerm);
                    }, 300); // Debounce for 300ms
                } else {
                    $('#facility_results').hide();
                }
            });

            // Filter change handlers
            $('#filter_instance, #filter_level').on('change', function() {
                const searchTerm = $('#facility_search').val().trim();
                if (searchTerm.length >= 2) {
                    searchFacilities(searchTerm);
                }
            });

            // Change facility button
            $('#changeFacilityBtn').on('click', function() {
                $('#selectedFacilityDisplay').hide();
                $('#facility_search').val('').focus();
                $('#orgunit').val('');
                $('#selectedFacilityHierarchy').text('');
                $('#facilitySearchContainer').show();
            });

            // Generate period selector
            generatePeriodSelector(periodType);

            // Load validation rules for this dataset
            loadValidationRules();

            // Hydrate option set dropdowns if needed
            document.querySelectorAll('select[data-option-set-id]').forEach(select => {
                ensureOptionSetOptions(select);
            });
            initializeCategorySelectors();

            // Simple totals and validation
            $(document).on('input change', '.data-element-input', function() {
                recalcTotals();
                scheduleValidation();
                const section = getSectionContainer(this);
                if (section) {
                    setSectionStatus(section, 'dirty', 'Changed');
                    scheduleSectionSave(section);
                }
            });

            // Load existing values
            $('#loadExistingBtn').on('click', function() {
                loadExistingData();
            });

            // Gate data entry until required selections are made
            $('#orgunit, #selectedPeriod').on('change input', function() {
                updateDataEntryVisibility();
            });
            updateDataEntryVisibility();

            // Form submission (only when submit button is used)
            $('#datasetForm').on('submit', function(e) {
                e.preventDefault();
                if (isPreview) {
                    return;
                }
                if (!submitArmed) {
                    return;
                }
                submitArmed = false;
                submitDataset();
            });

            // Prevent accidental submit on Enter while editing inputs
            $('#datasetForm').on('keydown', 'input, select', function(e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                }
            });

            $('#submitBtn').on('click', function() {
                submitArmed = true;
            });

            if (isPreview) {
                $('#submitContainer').hide();
                $('#loadExistingBtn').prop('disabled', true);
                // Preview is read-only: disable all inputs/selects/buttons in the form
                $('#datasetForm').find('input, select, textarea, button').prop('disabled', true);
                updateDataEntryVisibility();
            }

            runCustomFormInitTasks();
        });

        async function runCustomFormInitTasks() {
            for (const task of customFormInitTasks) {
                try {
                    await task();
                } catch (error) {
                    console.warn('Custom form init task failed:', error);
                }
            }
            runInjectedCustomScripts();
        }

        function isMobileViewport() {
            if (forceMobileAccordion) {
                return true;
            }
            return window.matchMedia('(max-width: 768px)').matches;
        }

        function setupMobileSectionAccordion() {
            const sections = document.querySelectorAll('.dataset-section');
            if (!sections.length) {
                return;
            }

            sections.forEach((section, index) => {
                const header = section.querySelector('.section-header');
                if (!header) {
                    return;
                }
                section.classList.add('is-mobile-accordion');
                if (!header.querySelector('.accordion-chevron')) {
                    const icon = document.createElement('i');
                    icon.className = 'fas fa-chevron-down accordion-chevron';
                    header.appendChild(icon);
                }
                header.setAttribute('role', 'button');
                header.setAttribute('tabindex', '0');

                const openDefault = index === 0;
                toggleSectionAccordion(section, openDefault, true);

                header.addEventListener('click', () => {
                    const shouldExpand = !section.classList.contains('expanded');
                    sections.forEach(sec => toggleSectionAccordion(sec, false, false));
                    toggleSectionAccordion(section, shouldExpand, false);
                    if (shouldExpand) {
                        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                });

                header.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        header.click();
                    }
                });
            });
        }

        function toggleSectionAccordion(section, expand, skipDesktopCheck) {
            if (!skipDesktopCheck && !isMobileViewport()) {
                section.classList.add('expanded');
                return;
            }
            if (expand) {
                section.classList.add('expanded');
            } else {
                section.classList.remove('expanded');
            }
        }

        function transformCustomTablesForMobile() {
            if (forceMobileAccordion) {
                return;
            }
            if (!isMobileViewport()) {
                return;
            }

            const container = document.querySelector('.dataset-custom-form');
            if (!container) {
                return;
            }
            container.classList.add('is-cardified');

            container.querySelectorAll('table').forEach((table) => {
                const headerLabels = [];
                const headRow = table.querySelector('thead tr') || table.querySelector('tr');
                if (headRow) {
                    headRow.querySelectorAll('th, td').forEach((cell) => {
                        headerLabels.push((cell.textContent || '').trim());
                    });
                }

                table.querySelectorAll('tbody tr, tr').forEach((row, rowIndex) => {
                    if (row.querySelector('th')) {
                        return;
                    }
                    const cells = row.querySelectorAll('td');
                    cells.forEach((cell, cellIndex) => {
                        const fallback = `Field ${cellIndex + 1}`;
                        const label = headerLabels[cellIndex] || headerLabels[0] || fallback;
                        cell.setAttribute('data-mobile-label', label);
                    });

                    // Skip first row if it was used as the header source and has no inputs
                    if (rowIndex === 0 && !row.querySelector('input, select, textarea') && table.querySelector('thead') === null) {
                        row.style.display = 'none';
                    }
                });
            });
        }

        function observeCustomFormForMobileTransforms() {
            if (forceMobileAccordion) {
                return;
            }
            if (!isMobileViewport()) {
                return;
            }
            const container = document.querySelector('.dataset-custom-form');
            if (!container) {
                return;
            }
            const observer = new MutationObserver(() => {
                transformCustomTablesForMobile();
            });
            observer.observe(container, { childList: true, subtree: true });
        }

        function compactSectionMenuForMobile() {
            if (forceMobileAccordion) {
                return;
            }
            if (!isMobileViewport()) {
                return;
            }

            const allCandidates = Array.from(document.querySelectorAll('a, button, div, li')).filter((el) => {
                const text = (el.textContent || '').trim();
                if (!text || text.length > 120) {
                    return false;
                }
                return /^section\\s+[a-z0-9]/i.test(text) || /^part\\s+[a-z0-9]/i.test(text);
            });

            if (allCandidates.length < 5) {
                return;
            }

            // Prefer candidates from the same parent block (the real section menu list)
            const parentCounts = new Map();
            allCandidates.forEach((el) => {
                const parent = el.parentElement;
                if (!parent) return;
                parentCounts.set(parent, (parentCounts.get(parent) || 0) + 1);
            });

            let menuContainer = null;
            let maxCount = 0;
            parentCounts.forEach((count, parent) => {
                if (count > maxCount) {
                    maxCount = count;
                    menuContainer = parent;
                }
            });

            if (!menuContainer || maxCount < 5 || menuContainer.dataset.mobileCompactApplied === '1') {
                return;
            }

            const items = Array.from(menuContainer.children).filter((child) => {
                const text = (child.textContent || '').trim();
                return /^section\\s+[a-z0-9]/i.test(text) || /^part\\s+[a-z0-9]/i.test(text);
            });
            if (items.length < 5) {
                return;
            }

            menuContainer.dataset.mobileCompactApplied = '1';
            menuContainer.classList.add('mobile-sections-condensed');
            items.forEach((item) => item.classList.add('mobile-section-item'));

            const nav = document.createElement('div');
            nav.className = 'mobile-section-nav';
            nav.innerHTML = `
                <button type="button" class="nav-btn prev-btn" aria-label="Previous section">&lsaquo;</button>
                <div class="nav-title"></div>
                <button type="button" class="nav-btn next-btn" aria-label="Next section">&rsaquo;</button>
            `;
            const tabs = document.createElement('div');
            tabs.className = 'mobile-section-tabs';
            menuContainer.parentNode.insertBefore(nav, menuContainer);
            menuContainer.parentNode.insertBefore(tabs, menuContainer);

            const prevBtn = nav.querySelector('.prev-btn');
            const nextBtn = nav.querySelector('.next-btn');
            const titleEl = nav.querySelector('.nav-title');

            function detectActiveIndex() {
                const idx = items.findIndex((el) => {
                    const cls = (el.className || '').toLowerCase();
                    return cls.includes('active') || cls.includes('selected') || cls.includes('current');
                });
                return idx >= 0 ? idx : 0;
            }

            let currentIndex = detectActiveIndex();

            function paint() {
                items.forEach((el, idx) => {
                    el.classList.toggle('active-mobile-section', idx === currentIndex);
                });
                titleEl.textContent = (items[currentIndex].textContent || '').trim();
                prevBtn.disabled = currentIndex <= 0;
                nextBtn.disabled = currentIndex >= items.length - 1;
                renderTabs();
            }

            function renderTabs() {
                tabs.innerHTML = '';
                items.forEach((el, idx) => {
                    const btn = document.createElement('button');
                    btn.type = 'button';
                    btn.className = 'tab-btn' + (idx === currentIndex ? ' active' : '');
                    btn.textContent = (el.textContent || '').trim();
                    btn.addEventListener('click', () => goTo(idx));
                    tabs.appendChild(btn);
                });
                const activeBtn = tabs.querySelector('.tab-btn.active');
                if (activeBtn) {
                    activeBtn.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
                }
            }

            function goTo(index) {
                if (index < 0 || index >= items.length) {
                    return;
                }
                currentIndex = index;
                const target = items[currentIndex];
                if (target && typeof target.click === 'function') {
                    target.click();
                }
                paint();
                setTimeout(paint, 80);
            }

            prevBtn.addEventListener('click', () => goTo(currentIndex - 1));
            nextBtn.addEventListener('click', () => goTo(currentIndex + 1));

            items.forEach((item, idx) => {
                item.addEventListener('click', () => {
                    currentIndex = idx;
                    paint();
                });
            });

            paint();
        }

        function initKotlinStyleSectionNavigator() {
            if (!forceMobileAccordion) {
                return;
            }
            const sections = Array.from(document.querySelectorAll('.dataset-section'));
            if (sections.length < 2) {
                return;
            }

            sections.forEach((section) => section.classList.add('mobile-kotlin-section'));

            const nav = document.createElement('div');
            nav.className = 'mobile-section-nav';
            nav.innerHTML = `
                <button type="button" class="nav-btn prev-btn" aria-label="Previous section">&lsaquo;</button>
                <div class="nav-title"></div>
                <button type="button" class="nav-btn next-btn" aria-label="Next section">&rsaquo;</button>
            `;
            const subNav = document.createElement('div');
            subNav.className = 'mobile-section-nav';
            subNav.style.marginTop = '8px';
            subNav.innerHTML = `
                <button type="button" class="nav-btn prev-sub-btn" aria-label="Previous subsection">&lsaquo;</button>
                <div class="nav-title sub-title"></div>
                <button type="button" class="nav-btn next-sub-btn" aria-label="Next subsection">&rsaquo;</button>
            `;

            const form = document.getElementById('datasetForm');
            const dataEntryTitle = Array.from(form.querySelectorAll('.section-title')).find((el) => (el.textContent || '').toLowerCase().includes('data entry'));
            if (dataEntryTitle && dataEntryTitle.parentNode) {
                dataEntryTitle.parentNode.insertBefore(nav, dataEntryTitle.nextSibling);
                dataEntryTitle.parentNode.insertBefore(subNav, nav.nextSibling);
            } else {
                form.insertBefore(nav, document.getElementById('dataElementsContainer'));
                form.insertBefore(subNav, nav.nextSibling);
            }

            const prevBtn = nav.querySelector('.prev-btn');
            const nextBtn = nav.querySelector('.next-btn');
            const titleEl = nav.querySelector('.nav-title');
            const prevSubBtn = subNav.querySelector('.prev-sub-btn');
            const nextSubBtn = subNav.querySelector('.next-sub-btn');
            const subTitleEl = subNav.querySelector('.sub-title');

            let currentSectionIndex = 0;
            let currentSubsectionIndex = 0;

            function getSectionName(section) {
                const title = section.querySelector('.section-title');
                return (title ? title.textContent : section.id || 'Section').trim();
            }

            function renderNav() {
                sections.forEach((section, idx) => {
                    const active = idx === currentSectionIndex;
                    section.style.display = active ? '' : 'none';
                    section.classList.toggle('expanded', active);
                });
                titleEl.textContent = getSectionName(sections[currentSectionIndex]);
                prevBtn.disabled = currentSectionIndex <= 0;
                nextBtn.disabled = currentSectionIndex >= sections.length - 1;
                renderSubNav();
            }

            function getActiveSubsections() {
                const activeSection = sections[currentSectionIndex];
                if (!activeSection) return [];
                return Array.from(activeSection.querySelectorAll('[data-subsection-id]'));
            }

            function renderSubNav() {
                const subs = getActiveSubsections();
                if (!subs.length) {
                    subNav.style.display = 'none';
                    return;
                }
                subNav.style.display = 'flex';
                if (currentSubsectionIndex >= subs.length) currentSubsectionIndex = 0;
                subs.forEach((s, idx) => {
                    s.style.display = idx === currentSubsectionIndex ? '' : 'none';
                });
                subTitleEl.textContent = subs[currentSubsectionIndex].dataset.subsectionTitle || `Subsection ${currentSubsectionIndex + 1}`;
                prevSubBtn.disabled = currentSubsectionIndex <= 0;
                nextSubBtn.disabled = currentSubsectionIndex >= subs.length - 1;
            }

            prevBtn.addEventListener('click', () => {
                if (currentSectionIndex > 0) {
                    currentSectionIndex -= 1;
                    currentSubsectionIndex = 0;
                    renderNav();
                }
            });
            nextBtn.addEventListener('click', () => {
                if (currentSectionIndex < sections.length - 1) {
                    currentSectionIndex += 1;
                    currentSubsectionIndex = 0;
                    renderNav();
                }
            });
            prevSubBtn.addEventListener('click', () => {
                if (currentSubsectionIndex > 0) {
                    currentSubsectionIndex -= 1;
                    renderSubNav();
                }
            });
            nextSubBtn.addEventListener('click', () => {
                const subs = getActiveSubsections();
                if (currentSubsectionIndex < subs.length - 1) {
                    currentSubsectionIndex += 1;
                    renderSubNav();
                }
            });

            renderNav();
        }

        async function loadLocationMap() {
            try {
                const response = await fetch('/fbs/admin/location_manager.php?action=get_map');
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                locationMap = await response.json();
            } catch (error) {
                console.warn('Failed to preload location map:', error);
            }
        }

        function convertPathToReadable(path) {
            if (!path) {
                return '';
            }
            const uids = String(path).split('/').filter(Boolean);
            if (!uids.length) {
                return '';
            }
            return uids.map((uid) => locationMap[uid] || '').filter(Boolean).join(' / ');
        }

        function collectMissingPathUids(paths) {
            const set = new Set();
            paths.forEach((path) => {
                if (!path) {
                    return;
                }
                String(path).split('/').filter(Boolean).forEach((uid) => {
                    if (!locationMap[uid]) {
                        set.add(uid);
                    }
                });
            });
            return Array.from(set);
        }

        async function fetchAndCacheLocationNames(uids) {
            if (!uids || !uids.length || !dhis2Instance) {
                return;
            }
            try {
                const response = await fetch('/fbs/admin/location_manager.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'fetch_missing',
                        uids,
                        dhis2_instance: dhis2Instance
                    })
                });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                const fetched = await response.json();
                Object.assign(locationMap, fetched || {});
            } catch (error) {
                console.warn('Failed bulk location name resolve:', error);
            }
        }

        async function fetchMissingLocationNames(path) {
            if (!path || !dhis2Instance) {
                return '';
            }
            const uids = String(path).split('/').filter(Boolean);
            const missing = uids.filter((uid) => !locationMap[uid]);
            if (!missing.length) {
                return convertPathToReadable(path);
            }

            try {
                const response = await fetch('/fbs/admin/location_manager.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'fetch_missing',
                        uids: missing,
                        dhis2_instance: dhis2Instance
                    })
                });
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }
                const fetched = await response.json();
                Object.assign(locationMap, fetched || {});
            } catch (error) {
                console.warn('Failed resolving missing location names:', error);
            }
            return convertPathToReadable(path);
        }


        function updateDataEntryVisibility() {
            if (isPreview) {
                $('#dataEntryGate').hide();
                $('#dataElementsContainer').show();
                $('#submitContainer').hide();
                return;
            }
            const orgUnit = $('#orgunit').val();
            const period = $('#selectedPeriod').val();
            const ready = !!(orgUnit && period);

            if (ready) {
                $('#dataEntryGate').hide();
                $('#dataElementsContainer').show();
                $('#submitContainer').show();
            } else {
                $('#dataElementsContainer').hide();
                $('#submitContainer').hide();
                $('#dataEntryGate').show();
            }
        }

        function getSectionContainer(inputEl) {
            if (!inputEl) {
                return null;
            }
            return inputEl.closest('.dataset-section');
        }

        function setSectionStatus(sectionEl, status, text) {
            if (!sectionEl) {
                return;
            }
            if (status) {
                sectionEl.dataset.status = status;
            } else {
                delete sectionEl.dataset.status;
            }
            const statusText = sectionEl.querySelector('.section-status-text');
            if (statusText && text) {
                statusText.textContent = text;
            }
        }

        function setAllSectionStatus(status, text) {
            document.querySelectorAll('.dataset-section').forEach(section => {
                setSectionStatus(section, status, text);
            });
        }

        function scheduleSectionSave(sectionEl) {
            if (isPreview) {
                return;
            }
            const sectionId = sectionEl.dataset.sectionId || sectionEl.id;
            if (!sectionId) {
                return;
            }
            if (sectionSaveTimers.has(sectionId)) {
                clearTimeout(sectionSaveTimers.get(sectionId));
            }
            sectionSaveTimers.set(sectionId, setTimeout(() => {
                autosaveSection(sectionEl);
            }, 1500));
        }

        function collectSectionDataValues(sectionEl) {
            const dataValues = [];
            sectionEl.querySelectorAll('.data-element-input').forEach(input => {
                const value = input.value;
                if (value !== '' && value !== null) {
                    dataValues.push({
                        dataElement: input.dataset.deId,
                        value: value,
                        categoryOptionCombo: input.dataset.cocId || undefined
                    });
                }
            });
            return dataValues;
        }

        function autosaveSection(sectionEl) {
            if (isPreview) {
                return;
            }
            const orgUnit = $('#orgunit').val();
            const period = $('#selectedPeriod').val();
            const attributeOptionCombo = hasAttributeCombo ? $('#attributeOptionCombo').val() : '';

            if (!orgUnit || !period || (hasAttributeCombo && !attributeOptionCombo)) {
                return;
            }

            const sectionId = sectionEl.dataset.sectionId || sectionEl.id;
            if (sectionInFlight.has(sectionId)) {
                return;
            }

            const dataValues = collectSectionDataValues(sectionEl);
            if (!dataValues.length) {
                return;
            }

            sectionInFlight.add(sectionId);
            setSectionStatus(sectionEl, 'saving', 'Saving...');

            const payload = {
                survey_id: surveyId,
                dataset_uid: datasetUid,
                orgunit: orgUnit,
                period: period,
                data_values: dataValues,
                is_update: existingDataLoaded || sectionSavedOnce.has(sectionId),
                mark_complete: false,
                async_submit: true,
                section_autosave: true
            };
            if (attributeOptionCombo) {
                payload.attributeOptionCombo = attributeOptionCombo;
            }

            $.ajax({
                url: '/fbs/public/dataset_submit.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                dataType: 'json',
                success: async function(response) {
                    sectionInFlight.delete(sectionId);
                    if (response && response.success) {
                        sectionSavedOnce.add(sectionId);
                        setSectionStatus(sectionEl, 'saved', 'Saved');
                    } else {
                        setSectionStatus(sectionEl, 'error', 'Save failed');
                    }
                },
                error: function() {
                    sectionInFlight.delete(sectionId);
                    setSectionStatus(sectionEl, 'error', 'Save failed');
                }
            });
        }

        function initializeCategorySelectors() {
            document.querySelectorAll('.category-primary-selector').forEach((selector) => {
                const deId = selector.dataset.targetDe;
                if (!deId) {
                    return;
                }
                selectBestCategoryOption(selector, deId);
                selector.addEventListener('change', function() {
                    activateCategoryPane(deId, this.value);
                });
                activateCategoryPane(deId, selector.value);
            });
        }

        function cssEscapeSafe(value) {
            if (window.CSS && typeof window.CSS.escape === 'function') {
                return window.CSS.escape(value);
            }
            return String(value).replace(/[^a-zA-Z0-9_-]/g, '\\$&');
        }

        // Some DHIS2 custom forms use inline onclick="opentab(...)"
        // Keep a safe fallback so missing function definitions do not halt execution.
        if (typeof window.opentab !== 'function') {
            window.opentab = function() {};
        }

        function selectBestCategoryOption(selector, deId) {
            const options = Array.from(selector.options || []);
            if (!options.length) {
                return;
            }
            let bestValue = options[0].value;
            let bestScore = -1;

            options.forEach((opt) => {
                const pane = document.querySelector(`.category-pane[data-de-pane=\"${cssEscapeSafe(deId)}\"][data-selector-option=\"${cssEscapeSafe(opt.value)}\"]`);
                if (!pane) {
                    return;
                }
                let score = 0;
                pane.querySelectorAll('input.data-element-input, select.data-element-input, textarea.data-element-input').forEach((input) => {
                    if ((input.value || '').trim() !== '') {
                        score += 1;
                    }
                });
                if (score > bestScore) {
                    bestScore = score;
                    bestValue = opt.value;
                }
            });

            selector.value = bestValue;
        }

        function activateCategoryPane(deId, selectedOptionId) {
            document.querySelectorAll(`.category-pane[data-de-pane=\"${cssEscapeSafe(deId)}\"]`).forEach((pane) => {
                const isActive = pane.dataset.selectorOption === selectedOptionId;
                pane.classList.toggle('active', isActive);
            });
        }

        function renderFacilityResults(orgUnits, searchTerm) {
            if (!orgUnits.length) {
                $('#facility_results_list').html('<div class="list-group-item">No facilities found matching "' + searchTerm + '"</div>');
                return;
            }

            let html = '';
            const maxResults = 50;
            const displayResults = orgUnits.slice(0, maxResults);

            displayResults.forEach(function(ou) {
                const hierarchy = ou.hierarchyPath || '';
                const readableHierarchy = convertPathToReadable(hierarchy) || hierarchy || '';
                html += `
                    <a href="#" class="list-group-item list-group-item-action facility-item"
                       data-uid="${ou.id}"
                       data-name="${ou.displayName || ou.name}"
                       data-hierarchy="${hierarchy}">
                        <div class="d-flex w-100 justify-content-between">
                            <h6 class="mb-1">${ou.displayName || ou.name}</h6>
                        </div>
                        ${readableHierarchy ? `<small class="text-muted">${readableHierarchy}</small>` : ''}
                    </a>
                `;
            });

            if (orgUnits.length > maxResults) {
                html += `
                    <div class="list-group-item text-center text-muted">
                        Showing ${maxResults} of ${orgUnits.length} results. Type more to narrow down.
                    </div>
                `;
            }

            $('#facility_results_list').html(html);
            $('.facility-item').on('click', function(e) {
                e.preventDefault();
                const uid = $(this).data('uid');
                const name = $(this).data('name');
                selectFacility(null, uid, name);
            });
        }

        // Search facilities directly from DHIS2
        function searchFacilities(searchTerm) {
            console.log('[SEARCH] Searching for:', searchTerm);
            $.ajax({
                url: '/fbs/admin/ajax_get_dataset_orgunits.php',
                method: 'GET',
                data: {
                    dataset_uid: '<?= $datasetUid ?>',
                    instance_key: dhis2Instance,
                    survey_id: surveyId,
                    search: searchTerm,
                    page: 1,
                    limit: 100
                },
                dataType: 'json',
                beforeSend: function() {
                    $('#facility_results_list').html('<div class="list-group-item"><i class="fas fa-spinner fa-spin me-2"></i>Searching from DHIS2...</div>');
                    $('#facility_results').show();
                },
                success: function(response) {
                    try {
                        console.log('[SEARCH] Got response:', response);

                        // Handle dhis2_api_proxy response format
                        let orgUnits = [];
                        if (response && response.orgUnits && Array.isArray(response.orgUnits)) {
                            orgUnits = response.orgUnits;
                        }

                        // Filter by search term
                        if (searchTerm && orgUnits.length > 0) {
                            const searchLower = searchTerm.toLowerCase();
                            orgUnits = orgUnits.filter(ou => {
                                const name = (ou.displayName || ou.name || '').toLowerCase();
                                return name.includes(searchLower);
                            });
                        }

                        console.log('[SEARCH] Filtered results:', orgUnits.length);
                        renderFacilityResults(orgUnits, searchTerm);
                    } catch (e) {
                        console.error('[SEARCH] Render failure:', e);
                        $('#facility_results_list').html('<div class="list-group-item text-danger"><i class="fas fa-exclamation-triangle me-2"></i>Failed to render search results</div>');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[SEARCH] Facility search error:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        responseJSON: xhr.responseJSON
                    });
                    const message = xhr.responseJSON && xhr.responseJSON.message ? xhr.responseJSON.message : 'Error searching facilities';
                    $('#facility_results_list').html('<div class="list-group-item text-danger"><i class="fas fa-exclamation-triangle me-2"></i>' + message + '</div>');
                }
            });
        }

        // Select a facility
        async function selectFacility(id, uid, name) {
            const hierarchy = $('.facility-item[data-uid="' + uid + '"]').data('hierarchy') || '';
            $('#orgunit').val(uid);  // Store DHIS2 UID
            $('#selectedFacilityText').text(name);
            const readableHierarchy = await fetchMissingLocationNames(hierarchy);
            $('#selectedFacilityHierarchy').text(readableHierarchy || 'Path unavailable');
            $('#selectedFacilityDisplay').slideDown();
            $('#facility_results').hide();
            $('#facility_search').val('');
            $('#facilitySearchContainer').hide();
            updateDataEntryVisibility();
        }

        function runInjectedCustomScripts() {
            if (forceMobileAccordion) {
                return;
            }
            if (!Array.isArray(window.__datasetCustomScriptSource) || window.__datasetCustomScriptSource.length === 0) {
                return;
            }

            window.__datasetCustomScriptSource.forEach((source, idx) => {
                try {
                    (0, eval)(source);
                } catch (error) {
                    console.error('Custom DHIS2 script failed at index ' + idx + ':', error);
                }
            });
        }

        // Generate period selector based on period type
        function generatePeriodSelector(type) {
            const container = $('#periodSelector');
            const currentYear = new Date().getFullYear();
            const currentMonth = new Date().getMonth() + 1;

            let html = '';

            switch(type) {
                case 'Daily':
                    html = `<input type="date" class="form-control" id="dailyPeriod" max="${new Date().toISOString().split('T')[0]}" required>`;
                    container.html(html);
                    $('#dailyPeriod').on('change', function() {
                        const date = $(this).val().replace(/-/g, '');
                        $('#selectedPeriod').val(date);
                        updateDataEntryVisibility();
                    });
                    break;

                case 'Weekly':
                    html = `
                        <select class="form-select" id="weeklyYear" required>
                            ${generateYearOptions(currentYear)}
                        </select>
                        <select class="form-select" id="weeklyWeek" required>
                            ${generateWeekOptions()}
                        </select>
                    `;
                    container.html(html);
                    $('#weeklyYear, #weeklyWeek').on('change', updateWeeklyPeriod);
                    updateWeeklyPeriod();
                    break;

                case 'Monthly':
                    html = `
                        <select class="form-select" id="monthlyYear" required>
                            ${generateYearOptions(currentYear)}
                        </select>
                        <select class="form-select" id="monthlyMonth" required>
                            ${generateMonthOptions()}
                        </select>
                    `;
                    container.html(html);
                    $('#monthlyYear, #monthlyMonth').on('change', updateMonthlyPeriod);
                    updateMonthlyPeriod();
                    break;

                case 'Quarterly':
                    html = `
                        <select class="form-select" id="quarterlyYear" required>
                            ${generateYearOptions(currentYear)}
                        </select>
                        <select class="form-select" id="quarterlyQuarter" required>
                            <option value="Q1">Quarter 1 (Jan-Mar)</option>
                            <option value="Q2">Quarter 2 (Apr-Jun)</option>
                            <option value="Q3">Quarter 3 (Jul-Sep)</option>
                            <option value="Q4">Quarter 4 (Oct-Dec)</option>
                        </select>
                    `;
                    container.html(html);
                    $('#quarterlyYear, #quarterlyQuarter').on('change', updateQuarterlyPeriod);
                    updateQuarterlyPeriod();
                    break;

                case 'Yearly':
                case 'FinancialApril':
                case 'FinancialJuly':
                case 'FinancialOct':
                    html = `
                        <select class="form-select" id="yearlyYear" required>
                            ${generateYearOptions(currentYear, 10)}
                        </select>
                    `;
                    container.html(html);
                    $('#yearlyYear').on('change', function() {
                        $('#selectedPeriod').val($(this).val());
                        updateDataEntryVisibility();
                    });
                    $('#selectedPeriod').val($('#yearlyYear').val());
                    updateDataEntryVisibility();
                    break;

                default:
                    html = `<input type="text" class="form-control" id="customPeriod" placeholder="Enter period (e.g., 202401)" required>`;
                    container.html(html);
                    $('#customPeriod').on('input', function() {
                        $('#selectedPeriod').val($(this).val());
                        updateDataEntryVisibility();
                    });
            }
        }

        function generateYearOptions(currentYear, range = 5) {
            let html = '<option value="">-- Year --</option>';
            for (let i = 0; i <= range; i++) {
                const year = currentYear - i;
                html += `<option value="${year}" ${i === 0 ? 'selected' : ''}>${year}</option>`;
            }
            return html;
        }

        function generateMonthOptions() {
            const months = ['January', 'February', 'March', 'April', 'May', 'June',
                          'July', 'August', 'September', 'October', 'November', 'December'];
            let html = '<option value="">-- Month --</option>';
            const currentMonth = new Date().getMonth();

            months.forEach((month, index) => {
                const value = String(index + 1).padStart(2, '0');
                html += `<option value="${value}" ${index === currentMonth ? 'selected' : ''}>${month}</option>`;
            });
            return html;
        }

        function generateWeekOptions() {
            let html = '<option value="">-- Week --</option>';
            for (let i = 1; i <= 53; i++) {
                const week = String(i).padStart(2, '0');
                html += `<option value="W${week}">Week ${i}</option>`;
            }
            return html;
        }

        function updateMonthlyPeriod() {
            const year = $('#monthlyYear').val();
            const month = $('#monthlyMonth').val();
            if (year && month) {
                $('#selectedPeriod').val(year + month);
                updateDataEntryVisibility();
            }
        }

        function updateQuarterlyPeriod() {
            const year = $('#quarterlyYear').val();
            const quarter = $('#quarterlyQuarter').val();
            if (year && quarter) {
                $('#selectedPeriod').val(year + quarter);
                updateDataEntryVisibility();
            }
        }

        function updateWeeklyPeriod() {
            const year = $('#weeklyYear').val();
            const week = $('#weeklyWeek').val();
            if (year && week) {
                $('#selectedPeriod').val(year + week);
                updateDataEntryVisibility();
            }
        }

        // Submit dataset to DHIS2
        function submitDataset() {
            if (isSubmitting) {
                return;
            }
            if (isPreview) {
                return;
            }
            // Validate form
            if (!$('#datasetForm')[0].checkValidity()) {
                $('#datasetForm')[0].reportValidity();
                return;
            }

            if (!evaluateValidationRules()) {
                return;
            }

            const orgUnit = $('#orgunit').val();
            const period = $('#selectedPeriod').val();
            const attributeOptionCombo = hasAttributeCombo ? $('#attributeOptionCombo').val() : '';

            if (!orgUnit || !period) {
                showAlert('warning', 'Please select both organization unit and period.');
                return;
            }

            // Collect data values
            const dataValues = [];
            $('.data-element-input').each(function() {
                const input = $(this);
                const value = input.val();

                if (value !== '' && value !== null) {
                    dataValues.push({
                        dataElement: input.data('de-id'),
                        value: value,
                        categoryOptionCombo: input.data('coc-id') || undefined
                    });
                }
            });

            if (dataValues.length === 0) {
                showAlert('warning', 'Please enter at least one data value.');
                return;
            }

            // Show loading
            isSubmitting = true;
            $('#submitBtn').prop('disabled', true);
            $('#loadingSpinner').addClass('active');

            // Submit to server
            const payload = {
                survey_id: surveyId,
                dataset_uid: datasetUid,
                orgunit: orgUnit,
                period: period,
                data_values: dataValues,
                is_update: existingDataLoaded,
                mark_complete: !existingDataLoaded,
                async_submit: true
            };
            if (attributeOptionCombo) {
                payload.attributeOptionCombo = attributeOptionCombo;
            }

            $.ajax({
                url: '/fbs/public/dataset_submit.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify(payload),
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        showAlert('success', 'Data submitted successfully!');
                        setTimeout(function() {
                            window.location.href = '/dataset-success/' + response.submission_id;
                        }, 2000);
                    } else {
                        showAlert('error', 'Submission failed: ' + (response.message || 'Unknown error'));
                        $('#submitBtn').prop('disabled', false);
                        $('#loadingSpinner').removeClass('active');
                        isSubmitting = false;
                    }
                },
                error: function(xhr) {
                    let errorMsg = 'An error occurred during submission.';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    }
                    showAlert('error', errorMsg);
                    $('#submitBtn').prop('disabled', false);
                    $('#loadingSpinner').removeClass('active');
                    isSubmitting = false;
                }
            });
        }

        function showAlert(type, message) {
            const alertClass = type === 'success' ? 'alert-success' :
                             type === 'error' ? 'alert-danger' :
                             type === 'warning' ? 'alert-warning' : 'alert-info';

            const icon = type === 'success' ? 'check-circle' :
                        type === 'error' ? 'exclamation-circle' :
                        type === 'warning' ? 'exclamation-triangle' : 'info-circle';

            const html = `
                <div class="alert ${alertClass} alert-custom alert-dismissible fade show" role="alert">
                    <i class="fas fa-${icon} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;

            $('#alertContainer').html(html);
            $('html, body').animate({ scrollTop: 0 }, 'fast');
        }
    </script>
    <?php if (!empty($customFormScripts)): ?>
        <script>
            window.__datasetCustomScriptSource = <?= json_encode(array_values($customFormScripts)) ?>;
        </script>
    <?php endif; ?>
</body>
</html>

<?php
// Helper function to render data element input based on value type
function renderDataElementInput($dataElement, $fieldId, $cocId = null) {
    $deId = $dataElement['id'];
    $valueType = $dataElement['valueType'];
    $hasOptionSet = isset($dataElement['optionSet']) && !empty($dataElement['optionSet']);

    $dataAttrs = 'data-de-id="' . htmlspecialchars($deId) . '"';
    if ($cocId) {
        $dataAttrs .= ' data-coc-id="' . htmlspecialchars($cocId) . '"';
    }

    $baseClass = 'form-control data-element-input';

    if ($hasOptionSet) {
        // Dropdown for option sets
        $html = '<select class="' . $baseClass . '" id="' . htmlspecialchars($fieldId) . '" name="' . htmlspecialchars($fieldId) . '" ' . $dataAttrs . '>';
        $html .= '<option value="">-- Select --</option>';

        if (isset($dataElement['optionSet']['options'])) {
            foreach ($dataElement['optionSet']['options'] as $option) {
                $html .= '<option value="' . htmlspecialchars($option['code']) . '">' .
                         htmlspecialchars($option['displayName']) . '</option>';
            }
        }

        $html .= '</select>';
        return $html;
    }

    switch ($valueType) {
        case 'NUMBER':
        case 'INTEGER':
        case 'INTEGER_POSITIVE':
        case 'INTEGER_NEGATIVE':
        case 'INTEGER_ZERO_OR_POSITIVE':
            return '<input type="number" class="' . $baseClass . '" id="' . htmlspecialchars($fieldId) . '"
                    name="' . htmlspecialchars($fieldId) . '" ' . $dataAttrs . '
                    step="' . ($valueType === 'NUMBER' ? '0.01' : '1') . '"
                    min="' . (in_array($valueType, ['INTEGER_POSITIVE', 'INTEGER_ZERO_OR_POSITIVE']) ? '0' : '') . '"
                    placeholder="Enter number">';

        case 'BOOLEAN':
        case 'TRUE_ONLY':
            return '<select class="' . $baseClass . '" id="' . htmlspecialchars($fieldId) . '"
                    name="' . htmlspecialchars($fieldId) . '" ' . $dataAttrs . '>
                    <option value="">-- Select --</option>
                    <option value="true">Yes</option>
                    ' . ($valueType === 'BOOLEAN' ? '<option value="false">No</option>' : '') . '
                    </select>';

        case 'DATE':
            return '<input type="date" class="' . $baseClass . '" id="' . htmlspecialchars($fieldId) . '"
                    name="' . htmlspecialchars($fieldId) . '" ' . $dataAttrs . '>';

        case 'PERCENTAGE':
            return '<input type="number" class="' . $baseClass . '" id="' . htmlspecialchars($fieldId) . '"
                    name="' . htmlspecialchars($fieldId) . '" ' . $dataAttrs . '
                    min="0" max="100" step="0.01" placeholder="Enter percentage (0-100)">';

        case 'LONG_TEXT':
            return '<textarea class="' . $baseClass . '" id="' . htmlspecialchars($fieldId) . '"
                    name="' . htmlspecialchars($fieldId) . '" ' . $dataAttrs . '
                    rows="3" placeholder="Enter text"></textarea>';

        case 'TEXT':
        default:
            return '<input type="text" class="' . $baseClass . '" id="' . htmlspecialchars($fieldId) . '"
                    name="' . htmlspecialchars($fieldId) . '" ' . $dataAttrs . '
                    placeholder="Enter text">';
    }
}
?>
