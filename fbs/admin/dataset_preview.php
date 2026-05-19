<?php
session_start();
require_once 'includes/session_timeout.php';
require_once 'connect.php';
require_once 'dhis2/dhis2_shared.php';

// Check if $pdo object is available from connect.php
if (!isset($pdo)) {
    die("Database connection failed. Please check connect.php.");
}

// Get survey_id from the URL
$surveyId = $_GET['survey_id'] ?? null;

if (!$surveyId) {
    die("Survey ID is missing.");
}

// Fetch survey details
try {
    $surveyStmt = $pdo->prepare("SELECT id, type, name, program_dataset, dhis2_instance, program_type FROM survey WHERE id = ?");
    $surveyStmt->execute([$surveyId]);
    $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);

    if (!$survey) {
        die("Survey not found.");
    }

    // Check if this is a DHIS2 aggregate dataset
    if ($survey['type'] !== 'dhis2' || $survey['program_type'] !== 'aggregate' || empty($survey['program_dataset'])) {
        // Redirect to regular preview form
        header("Location: preview_form.php?survey_id=" . $surveyId);
        exit();
    }
} catch (PDOException $e) {
    error_log("Database error fetching survey details: " . $e->getMessage());
    die("Error fetching survey details.");
}

// Get DHIS2 configuration
$dhis2Config = null;
$datasetInfo = null;

try {
    if (!empty($survey['dhis2_instance'])) {
        $dhis2Config = getDhis2Config($survey['dhis2_instance']);

        if ($dhis2Config) {
            // Fetch dataset info from DHIS2
            $datasetUid = $survey['program_dataset'];
            $datasetInfo = dhis2_get("dataSets/{$datasetUid}.json?fields=id,name,description,periodType", $survey['dhis2_instance']);
        }
    }
} catch (Exception $e) {
    error_log("Error fetching DHIS2 config or dataset: " . $e->getMessage());
}

if (!$dhis2Config) {
    die("DHIS2 configuration not found for this survey.");
}

// Get dataset layout settings (unified survey_settings table)
$datasetSettings = [];
try {
    $settingsStmt = $pdo->prepare("SELECT * FROM survey_settings WHERE survey_id = ?");
    $settingsStmt->execute([$surveyId]);
    $datasetSettings = $settingsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log("Error fetching dataset settings: " . $e->getMessage());
}

// Get available DHIS2 instances
$instanceKeys = [];
try {
    $instanceStmt = $pdo->query("SELECT instance_key FROM dhis2_instances WHERE status = 1 ORDER BY instance_key");
    while ($row = $instanceStmt->fetch(PDO::FETCH_ASSOC)) {
        $instanceKeys[] = $row['instance_key'];
    }
} catch (PDOException $e) {
    error_log("Error fetching instances: " . $e->getMessage());
}

// Hierarchy Level Mapping
$hierarchyLevels = ['' => 'All Levels'];
for ($i = 1; $i <= 7; $i++) {
    $hierarchyLevels[$i] = 'Level ' . $i;
}

// Merge with defaults
$datasetSettings = array_merge([
    'layout_type' => 'horizontal',
    'show_flag_bar' => 1,
    'flag_black_color' => '#000000',
    'flag_yellow_color' => '#FCD116',
    'flag_red_color' => '#D21034',
    'show_facility_section' => 1,
    'selected_instance_key' => $survey['dhis2_instance'],
    'selected_hierarchy_level' => 7
], $datasetSettings);

if (empty($datasetSettings['layout_type']) && !empty($datasetSettings['image_layout_type'])) {
    $datasetSettings['layout_type'] = $datasetSettings['image_layout_type'];
}

$defaultDatasetTitle = htmlspecialchars($survey['name']);
$datasetDescription = $datasetInfo['description'] ?? '';
$periodType = $datasetInfo['periodType'] ?? 'Monthly';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dataset Preview & Settings - <?= $defaultDatasetTitle ?></title>
    <link rel="icon" href="favicon.ico" type="image/x-icon">
    <link rel="shortcut icon" href="favicon.ico" type="image/x-icon">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- Sortable CSS removed - not needed, functionality works without it -->
    <style>
        :root {
            --primary-color: #4a5568;
            --secondary-color: #718096;
            --uganda-black: <?= $datasetSettings['flag_black_color'] ?>;
            --uganda-yellow: <?= $datasetSettings['flag_yellow_color'] ?>;
            --uganda-red: <?= $datasetSettings['flag_red_color'] ?>;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            margin: 0;
            padding: 0;
        }

        .header {
            background: linear-gradient(135deg, var(--primary-color), var(--secondary-color));
            color: white;
            padding: 20px 0;
            margin-bottom: 30px;
        }

        .flag-preview {
            height: 10px;
            display: flex;
            width: 100%;
            margin-bottom: 20px;
            border-radius: 4px;
            overflow: hidden;
        }

        .flag-preview .black { background: var(--uganda-black); flex: 1; }
        .flag-preview .yellow { background: var(--uganda-yellow); flex: 1; }
        .flag-preview .red { background: var(--uganda-red); flex: 1; }

        .card {
            border: none;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.08);
            margin-bottom: 15px;
        }

        .card-body {
            padding: 1rem;
        }

        .card-header {
            background: var(--primary-color);
            color: white;
            border-radius: 12px 12px 0 0 !important;
            font-weight: 600;
        }

        /* Accordion Styles */
        .accordion-item {
            margin-bottom: 10px;
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
        }

        .accordion-header {
            width: 100%;
            padding: 15px;
            background: #f8f9fa;
            border: none;
            text-align: left;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: background 0.2s;
        }

        .accordion-header:hover {
            background: #e9ecef;
        }

        .accordion-header i {
            transition: transform 0.3s;
        }

        .accordion-header.active i {
            transform: rotate(180deg);
        }

        .accordion-content {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.3s ease;
            background: white;
        }

        .accordion-content.show {
            max-height: 2000px;
            padding: 15px;
        }

        .setting-group {
            margin-bottom: 20px;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }

        /* Data Elements List */
        .data-element-item {
            padding: 12px;
            background: white;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            margin-bottom: 8px;
            cursor: move;
            transition: all 0.2s;
        }

        .data-element-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .data-element-item.sortable-ghost {
            opacity: 0.4;
            background: #e3f2fd;
        }

        .data-element-item .drag-handle {
            cursor: grab;
            color: #999;
            margin-right: 10px;
        }

        .data-element-item .drag-handle:active {
            cursor: grabbing;
        }

        .element-badge {
            font-size: 11px;
            padding: 3px 8px;
            border-radius: 4px;
            background: #e3f2fd;
            color: #1976d2;
            margin-left: 8px;
        }

        /* Preview iframe */
        #preview-iframe {
            width: 100%;
            min-height: 1000px;
            border: 2px solid #e9ecef;
            border-radius: 8px;
            background: white;
        }

        .section-divider {
            margin: 20px 0;
            padding: 10px;
            background: #f0f0f0;
            border-left: 4px solid var(--primary-color);
            font-weight: 600;
        }

        .btn-primary {
            background: var(--primary-color);
            border: none;
        }

        .btn-primary:hover {
            background: var(--secondary-color);
        }

        .filter-group {
            margin-top: 10px;
        }

        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }

        .loading-spinner {
            text-align: center;
            padding: 40px;
            color: var(--primary-color);
        }

        /* Tab Navigation Styles */
        .tab-navigation {
            display: flex;
            border-bottom: 2px solid #e9ecef;
            margin-bottom: 20px;
            gap: 5px;
            flex-wrap: wrap;
        }

        .tablinks {
            padding: 10px 20px;
            border: none;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s;
            border-radius: 8px 8px 0 0;
            font-weight: 500;
            color: #6c757d;
        }

        .tablinks:hover {
            background: #e9ecef;
            color: var(--primary-color);
        }

        .tablinks.active {
            background: var(--primary-color);
            color: white;
        }

        .tabcontent {
            display: none;
            animation: fadeIn 0.3s;
        }

        .tabcontent.active {
            display: block;
        }

        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="header">
            <div class="container">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h1><i class="fas fa-table me-2"></i><?= $defaultDatasetTitle ?></h1>
                        <p class="mb-0">Aggregate Dataset Preview & Settings</p>
                    </div>
                    <a href="main.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-2"></i>Back to Admin
                    </a>
                </div>
            </div>
        </div>

        <div class="container-fluid px-4">
            <div class="row g-3">
                <!-- Left Panel: Settings -->
                <div class="col-lg-4">
                    <!-- Dataset Information -->
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-info-circle me-2"></i>Dataset Information
                        </div>
                        <div class="card-body">
                            <p><strong>Dataset UID:</strong> <?= htmlspecialchars($survey['program_dataset']) ?></p>
                            <p><strong>Period Type:</strong> <span class="badge bg-primary"><?= $periodType ?></span></p>
                            <p><strong>DHIS2 Instance:</strong> <?= htmlspecialchars($survey['dhis2_instance']) ?></p>
                            <p><strong>Form Type:</strong> <span class="badge bg-info" id="formTypeBadge">Loading...</span></p>
                            <?php if ($datasetDescription): ?>
                                <p><strong>Description:</strong><br><?= nl2br(htmlspecialchars($datasetDescription)) ?></p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Settings with Tabs -->
                    <div class="card">
                        <div class="card-header">
                            <i class="fas fa-cog me-2"></i>Form Settings
                        </div>
                        <div class="card-body">
                            <!-- Tab Navigation -->
                            <div class="tab-navigation">
                                <button class="tablinks" id="defaultOpen" onclick="opentab(event, 'FlagTab')">
                                    <i class="fas fa-flag me-1"></i> Flag Bar
                                </button>
                                <button class="tablinks" onclick="opentab(event, 'ElementsTab')">
                                    <i class="fas fa-list me-1"></i> Data Elements
                                </button>
                                <button class="tablinks" onclick="opentab(event, 'LayoutTab')">
                                    <i class="fas fa-th me-1"></i> Layout
                                </button>
                                <button class="tablinks" onclick="opentab(event, 'LabelsTab')">
                                    <i class="fas fa-font me-1"></i> Form Labels
                                </button>
                                <button class="tablinks" onclick="opentab(event, 'PreviewTab')">
                                    <i class="fas fa-eye me-1"></i> Preview Defaults
                                </button>
                            </div>

                            <form id="settingsForm">
                                <input type="hidden" name="survey_id" value="<?= $surveyId ?>">

                                <!-- Flag Bar Tab -->
                                <div id="FlagTab" class="tabcontent">
                                    <div class="form-check form-switch mb-3">
                                        <input class="form-check-input" type="checkbox" id="show_flag_bar" name="show_flag_bar"
                                               <?= $datasetSettings['show_flag_bar'] ? 'checked' : '' ?>>
                                        <label class="form-check-label" for="show_flag_bar">Show Flag Bar</label>
                                    </div>

                                    <div class="row">
                                        <div class="col-md-4">
                                            <label for="flag_black_color" class="form-label">Black</label>
                                            <input type="color" class="form-control form-control-color" id="flag_black_color"
                                                   name="flag_black_color" value="<?= $datasetSettings['flag_black_color'] ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label for="flag_yellow_color" class="form-label">Yellow</label>
                                            <input type="color" class="form-control form-control-color" id="flag_yellow_color"
                                                   name="flag_yellow_color" value="<?= $datasetSettings['flag_yellow_color'] ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label for="flag_red_color" class="form-label">Red</label>
                                            <input type="color" class="form-control form-control-color" id="flag_red_color"
                                                   name="flag_red_color" value="<?= $datasetSettings['flag_red_color'] ?>">
                                        </div>
                                    </div>

                                    <div class="flag-preview mt-3" id="flagPreview">
                                        <div class="black"></div>
                                        <div class="yellow"></div>
                                        <div class="red"></div>
                                    </div>
                                </div>
                                <!-- End Flag Tab -->

                                <!-- Data Elements Tab -->
                                <div id="ElementsTab" class="tabcontent">
                                        <div class="mb-3">
                                            <button type="button" class="btn btn-sm btn-outline-primary" id="loadElementsBtn">
                                                <i class="fas fa-sync me-1"></i>Load from DHIS2
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-success" id="selectAllBtn">
                                                <i class="fas fa-check-square me-1"></i>Select All
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-secondary" id="deselectAllBtn">
                                                <i class="fas fa-square me-1"></i>Deselect All
                                            </button>
                                        </div>

                                        <div id="elementsLoading" class="loading-spinner" style="display:none;">
                                            <i class="fas fa-spinner fa-spin fa-2x"></i>
                                            <p>Loading data elements...</p>
                                        </div>

                                        <div id="dataElementsList">
                                            <p class="text-muted">Click "Load from DHIS2" to fetch data elements</p>
                                        </div>
                                </div>
                                <!-- End Elements Tab -->

                                <!-- Layout Tab -->
                                <div id="LayoutTab" class="tabcontent">
                                        <label for="layout_type" class="form-label">Image Layout Type</label>
                                        <select class="form-select" id="layout_type" name="layout_type">
                                            <option value="horizontal" <?= $datasetSettings['layout_type'] === 'horizontal' ? 'selected' : '' ?>>
                                                Horizontal
                                            </option>
                                            <option value="vertical" <?= $datasetSettings['layout_type'] === 'vertical' ? 'selected' : '' ?>>
                                                Vertical
                                            </option>
                                        </select>
                                </div>
                                <!-- End Layout Tab -->

                                <!-- Form Labels Tab -->
                                <div id="LabelsTab" class="tabcontent">
                                    <div class="setting-group">
                                        <h6 class="mb-3"><i class="fas fa-map-marker-alt me-1"></i>Org Unit Labels</h6>
                                        <div class="mb-3">
                                            <label for="facility_section_title" class="form-label">Section Title</label>
                                            <input type="text" class="form-control" id="facility_section_title" name="facility_section_title"
                                                   value="<?= htmlspecialchars($datasetSettings['facility_section_title'] ?? '') ?>"
                                                   placeholder="Facility/Organization Unit Selection">
                                        </div>
                                        <div class="mb-3">
                                            <label for="facility_search_label" class="form-label">Search Label</label>
                                            <input type="text" class="form-control" id="facility_search_label" name="facility_search_label"
                                                   value="<?= htmlspecialchars($datasetSettings['facility_search_label'] ?? '') ?>"
                                                   placeholder="Search Facility">
                                        </div>
                                        <div class="mb-3">
                                            <label for="facility_search_placeholder" class="form-label">Search Placeholder</label>
                                            <input type="text" class="form-control" id="facility_search_placeholder" name="facility_search_placeholder"
                                                   value="<?= htmlspecialchars($datasetSettings['facility_search_placeholder'] ?? '') ?>"
                                                   placeholder="Type to search for a facility...">
                                        </div>
                                        <div class="mb-3">
                                            <label for="facility_selected_label" class="form-label">Selected Label</label>
                                            <input type="text" class="form-control" id="facility_selected_label" name="facility_selected_label"
                                                   value="<?= htmlspecialchars($datasetSettings['facility_selected_label'] ?? '') ?>"
                                                   placeholder="Selected Facility">
                                        </div>
                                    </div>

                                    <div class="setting-group">
                                        <h6 class="mb-3"><i class="fas fa-calendar me-1"></i>Period Labels</h6>
                                        <div class="mb-3">
                                            <label for="period_section_title" class="form-label">Section Title</label>
                                            <input type="text" class="form-control" id="period_section_title" name="period_section_title"
                                                   value="<?= htmlspecialchars($datasetSettings['period_section_title'] ?? '') ?>"
                                                   placeholder="Reporting Period">
                                        </div>
                                        <div class="mb-3">
                                            <label for="period_select_label" class="form-label">Select Label</label>
                                            <input type="text" class="form-control" id="period_select_label" name="period_select_label"
                                                   value="<?= htmlspecialchars($datasetSettings['period_select_label'] ?? '') ?>"
                                                   placeholder="Select Period">
                                        </div>
                                        <div class="mb-0">
                                            <label for="search_min_chars_instruction" class="form-label">Search Hint Text</label>
                                            <input type="text" class="form-control" id="search_min_chars_instruction" name="search_min_chars_instruction"
                                                   value="<?= htmlspecialchars($datasetSettings['search_min_chars_instruction'] ?? '') ?>"
                                                   placeholder="Type at least 2 characters to search">
                                        </div>
                                    </div>

                                </div>
                                <!-- End Form Labels Tab -->


                                <div class="mt-3">
                                    <button type="button" class="btn btn-primary w-100" id="saveAllBtn">
                                        <i class="fas fa-save me-2"></i>Save All Settings
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Organization Units Sync -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-hospital me-2"></i>Organization Units</h6>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-2">
                                Sync facilities from DHIS2 to local database
                            </p>
                            <div id="orgunitSyncStatus" class="alert alert-info small py-2 mb-2" style="display: none;">
                                <i class="fas fa-info-circle me-1"></i>
                                <span id="orgunitSyncMessage">Loading sync status...</span>
                            </div>
                            <button type="button" class="btn btn-warning w-100 mb-2" id="syncOrgUnitsBtn">
                                <i class="fas fa-sync-alt me-2"></i>Sync Facilities from DHIS2
                            </button>
                            <small class="text-muted d-block" id="lastSyncTime" style="display: none;">
                                <i class="fas fa-clock me-1"></i>Last synced: <span id="lastSyncTimeValue">Never</span>
                            </small>
                            <small class="text-muted d-block mt-1">
                                <i class="fas fa-info-circle me-1"></i>Count: <span id="orgunitCount">-</span> facilities
                            </small>
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="card">
                        <div class="card-header">
                            <h6 class="mb-0"><i class="fas fa-tools me-2"></i>Actions</h6>
                        </div>
                        <div class="card-body">
                            <div class="d-grid gap-2">
                                <a href="/d/<?= $surveyId ?>" target="_blank" class="btn btn-success">
                                    <i class="fas fa-eye me-2"></i>Preview Form
                                </a>
                                <a href="/share/d/<?= $surveyId ?>" target="_blank" class="btn btn-info">
                                    <i class="fas fa-qrcode me-2"></i>View QR Code
                                </a>
                                <hr class="my-2">
                                <a href="survey.php" class="btn btn-outline-secondary">
                                    <i class="fas fa-arrow-left me-2"></i>Back to Surveys
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Right Panel: Live Preview -->
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h4 class="mb-0"><?= $defaultDatasetTitle ?> - Live Preview</h4>
                            <button type="button" class="btn btn-sm btn-light" id="refreshPreviewBtn">
                                <i class="fas fa-sync me-1"></i>Refresh
                            </button>
                        </div>
                        <div class="card-body">
                            <iframe id="preview-iframe" src="/d/<?= $surveyId ?>?preview=1"></iframe>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>

    <script>
        /**
         * Dataset Preview JavaScript
         *
         * DEBUGGING ENABLED:
         * - [INIT] = Initialization events
         * - [TAB] = Tab navigation events
         * - [DATA] = Data loading events
         * - [PREVIEW] = Preview iframe events
         * - [SAVE] = Save operations
         *
         * Check browser console for detailed logs
         */

        const surveyId = <?= $surveyId ?>;
        let dataElements = [];
        let sortableInstance = null;

        $(document).ready(function() {
            console.log('[INIT] jQuery ready, survey ID:', surveyId);
            console.log('[INIT] Debugging enabled - check console for [TAG] messages');

            // Accordion functionality (kept for backwards compatibility if needed)
            $('.accordion-header').on('click', function() {
                const $header = $(this);
                const $content = $header.next('.accordion-content');

                $header.toggleClass('active');
                $content.toggleClass('show');
            });

            // Update flag preview when colors change
            $('input[type="color"]').on('change', updateFlagPreview);

            // Load elements button
            $('#loadElementsBtn').on('click', loadDataElements);

            // Select/Deselect all
            $('#selectAllBtn').on('click', function() {
                $('.element-visibility-checkbox').prop('checked', true);
            });

            $('#deselectAllBtn').on('click', function() {
                $('.element-visibility-checkbox').prop('checked', false);
            });

            // Save all settings
            $('#saveAllBtn').on('click', saveAllSettings);

            // Refresh preview
            $('#refreshPreviewBtn').on('click', function() {
                console.log('[PREVIEW] Refreshing iframe...');
                $('#preview-iframe').attr('src', $('#preview-iframe').attr('src'));
            });

            // Monitor iframe loading
            $('#preview-iframe').on('load', function() {
                console.log('[PREVIEW] Iframe loaded successfully');
            });

            $('#preview-iframe').on('error', function() {
                console.error('[PREVIEW] Iframe failed to load');
            });

            // Auto-load data elements on page load
            console.log('[INIT] Auto-loading data elements...');
            loadDataElements();

            // Sync org units button
            $('#syncOrgUnitsBtn').on('click', syncOrgUnitsFromDHIS2);

            // Load org unit sync status on page load
            loadOrgUnitSyncStatus();
        });

        function updateFlagPreview() {
            const flagPreview = document.getElementById('flagPreview');
            flagPreview.querySelector('.black').style.background = document.getElementById('flag_black_color').value;
            flagPreview.querySelector('.yellow').style.background = document.getElementById('flag_yellow_color').value;
            flagPreview.querySelector('.red').style.background = document.getElementById('flag_red_color').value;
        }

        function loadDataElements() {
            console.log('[DATA] Loading data elements for survey:', surveyId);
            $('#elementsLoading').show();
            $('#dataElementsList').html('');

            $.ajax({
                url: 'ajax_get_dataset_elements.php',
                method: 'GET',
                data: { survey_id: surveyId },
                dataType: 'json',
                success: function(response) {
                    console.log('[DATA] Received response:', response);
                    if (response.success) {
                        dataElements = response.dataElements;
                        console.log('[DATA] Loaded', dataElements.length, 'data elements');

                        // Update form type badge
                        const formType = response.formType || 'DEFAULT';
                        const badgeClass = formType === 'SECTION' ? 'bg-success' : (formType === 'CUSTOM' ? 'bg-warning' : 'bg-info');
                        $('#formTypeBadge').removeClass('bg-info bg-success bg-warning').addClass(badgeClass).text(formType);

                        renderDataElements(response);
                        initSortable();
                    } else {
                        console.error('[DATA] Failed to load:', response.message);
                        showError('Failed to load data elements: ' + response.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[DATA] AJAX error:', {
                        status: status,
                        error: error,
                        response: xhr.responseText
                    });
                    showError('Error loading data elements. Please try again.');
                },
                complete: function() {
                    console.log('[DATA] Request complete');
                    $('#elementsLoading').hide();
                }
            });
        }

        function renderDataElements(data) {
            let html = '';
            let currentSection = null;

            // Check if this is a CUSTOM form with no data elements
            if (data.formType === 'CUSTOM' && data.dataElements.length === 0) {
                html = `
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>Custom Form Detected</strong>
                        <p class="mb-0 mt-2">This dataset uses a DHIS2 custom HTML form. The form layout and data elements are defined entirely within the custom form HTML and cannot be individually configured here.</p>
                        <p class="mb-0 mt-2">The form will render exactly as designed in DHIS2's custom form editor.</p>
                    </div>
                `;
                $('#dataElementsList').html(html);
                console.log('[DATA] Custom form with embedded elements - no individual settings available');
                return;
            }

            // Check if no data elements at all
            if (data.dataElements.length === 0) {
                html = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <strong>No Data Elements Found</strong>
                        <p class="mb-0 mt-2">This dataset does not contain any data elements. Please check the DHIS2 dataset configuration.</p>
                    </div>
                `;
                $('#dataElementsList').html(html);
                console.log('[DATA] No data elements found in dataset');
                return;
            }

            data.dataElements.forEach(function(element, index) {
                // Add section divider if section changes
                if (element.sectionName && element.sectionName !== currentSection) {
                    html += `<div class="section-divider">${element.sectionName}</div>`;
                    currentSection = element.sectionName;
                }

                html += `
                    <div class="data-element-item" data-id="${element.id}" data-order="${index}">
                        <div class="d-flex align-items-center">
                            <i class="fas fa-grip-vertical drag-handle"></i>
                            <div class="form-check flex-grow-1">
                                <input class="form-check-input element-visibility-checkbox" type="checkbox"
                                       id="visible_${element.id}" ${element.visible ? 'checked' : ''}>
                                <label class="form-check-label" for="visible_${element.id}">
                                    <strong>${element.name}</strong>
                                    ${element.code ? `<span class="element-badge">${element.code}</span>` : ''}
                                    ${element.valueType ? `<span class="element-badge">${element.valueType}</span>` : ''}
                                    ${element.categoryCombo ? '<span class="element-badge">Has Categories</span>' : ''}
                                </label>
                            </div>
                        </div>
                    </div>
                `;
            });

            $('#dataElementsList').html(html);
        }

        function initSortable() {
            const el = document.getElementById('dataElementsList');
            if (sortableInstance) {
                sortableInstance.destroy();
            }

            sortableInstance = Sortable.create(el, {
                animation: 150,
                handle: '.drag-handle',
                ghostClass: 'sortable-ghost',
                onEnd: function(evt) {
                    console.log('Element moved from', evt.oldIndex, 'to', evt.newIndex);
                }
            });
        }

        function saveAllSettings() {
            // Save form settings first
            const formData = new FormData($('#settingsForm')[0]);

            $.ajax({
                url: 'save_dataset_settings.php',
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    let parsed = null;
                    if (typeof response === 'object' && response !== null) {
                        parsed = response;
                    } else if (typeof response === 'string') {
                        const trimmed = response.trim();
                        if (trimmed.startsWith('{')) {
                            try {
                                parsed = JSON.parse(trimmed);
                            } catch (e) {
                                parsed = null;
                            }
                        }
                    }

                    if (parsed && parsed.success === false) {
                        showError(parsed.message || 'Failed to save form settings');
                        return;
                    }
                    // Then save data element settings
                    saveDataElementSettings();
                },
                error: function(xhr, status, error) {
                    console.error('Error saving form settings:', error);
                    let message = 'Failed to save form settings';
                    if (xhr.responseText) {
                        try {
                            const parsed = JSON.parse(xhr.responseText);
                            if (parsed && parsed.message) {
                                message = parsed.message;
                            }
                        } catch (e) {}
                    }
                    showError(message);
                }
            });
        }

        function saveDataElementSettings() {
            const elements = [];
            $('.data-element-item').each(function(index) {
                const $item = $(this);
                const id = $item.data('id');
                const originalElement = dataElements.find(el => el.id === id);

                if (originalElement) {
                    elements.push({
                        id: id,
                        name: originalElement.name,
                        code: originalElement.code,
                        sectionId: originalElement.sectionId,
                        sectionName: originalElement.sectionName,
                        order: index,
                        visible: $item.find('.element-visibility-checkbox').is(':checked')
                    });
                }
            });

            $.ajax({
                url: 'ajax_save_dataelement_settings.php',
                method: 'POST',
                contentType: 'application/json',
                data: JSON.stringify({
                    survey_id: surveyId,
                    elements: elements
                }),
                success: function(response) {
                    if (response.success) {
                        showSuccess('All settings saved successfully!');
                        // Refresh preview
                        setTimeout(function() {
                            $('#preview-iframe').attr('src', $('#preview-iframe').attr('src'));
                        }, 500);
                    } else {
                        showError('Failed to save data element settings: ' + response.message);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Error saving data element settings:', error);
                    showError('Failed to save data element settings');
                }
            });
        }

        function showSuccess(message) {
            alert('Success: ' + message);
        }

        function showError(message) {
            alert('Error: ' + message);
        }

        // Tab navigation function
        function opentab(evt, tabName) {
            console.log('[TAB] Switching to tab:', tabName);
            var i, tabcontent, tablinks;

            // Hide all tab content
            tabcontent = document.getElementsByClassName("tabcontent");
            console.log('[TAB] Found', tabcontent.length, 'tab content elements');
            for (i = 0; i < tabcontent.length; i++) {
                tabcontent[i].style.display = "none";
            }

            // Remove active class from all tabs
            tablinks = document.getElementsByClassName("tablinks");
            console.log('[TAB] Found', tablinks.length, 'tab link elements');
            for (i = 0; i < tablinks.length; i++) {
                tablinks[i].className = tablinks[i].className.replace(" active", "");
            }

            // Show current tab and add active class
            var targetTab = document.getElementById(tabName);
            if (targetTab) {
                targetTab.style.display = "block";
                evt.currentTarget.className += " active";
                console.log('[TAB] Successfully switched to:', tabName);
            } else {
                console.error('[TAB] Could not find tab with ID:', tabName);
            }
        }

        // Initialize: Open the default tab on page load
        window.addEventListener('DOMContentLoaded', function() {
            console.log('[INIT] Page loaded, initializing tabs...');
            var defaultBtn = document.getElementById("defaultOpen");
            if (defaultBtn) {
                defaultBtn.click();
                console.log('[INIT] Default tab opened');
            } else {
                console.error('[INIT] Could not find defaultOpen button');
            }
        });

        // Load org unit sync status
        function loadOrgUnitSyncStatus() {
            console.log('[ORGUNIT] Loading sync status...');
            $.ajax({
                url: 'ajax_get_orgunit_sync_status.php',
                method: 'GET',
                data: { survey_id: surveyId },
                dataType: 'json',
                success: function(response) {
                    console.log('[ORGUNIT] Sync status:', response);
                    if (response.success) {
                        // Update last sync time
                        if (response.last_sync) {
                            $('#lastSyncTime').show();
                            $('#lastSyncTimeValue').text(response.last_sync);
                        }

                        // Update org unit count
                        if (response.count !== undefined) {
                            $('#orgunitCount').text(response.count);
                        }
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[ORGUNIT] Error loading sync status:', error);
                }
            });
        }

        // Sync org units from DHIS2
        function syncOrgUnitsFromDHIS2() {
            const btn = $('#syncOrgUnitsBtn');
            const icon = btn.find('i');
            const originalText = btn.html();

            // Confirm before sync
            if (!confirm('This will delete all existing facilities for this survey and fetch fresh data from DHIS2.\n\nOnly facilities currently attached to this dataset in DHIS2 will be available in the form.\n\nDo you want to continue?')) {
                return;
            }

            // Disable button and show loading
            btn.prop('disabled', true);
            btn.html('<i class="fas fa-spinner fa-spin me-2"></i>Syncing from DHIS2...');

            // Show status message
            $('#orgunitSyncStatus').show()
                .removeClass('alert-success alert-danger alert-warning')
                .addClass('alert-info');
            $('#orgunitSyncMessage').html('<i class="fas fa-spinner fa-spin me-1"></i>Deleting old records and fetching from DHIS2...');

            console.log('[ORGUNIT] Starting sync for survey:', surveyId);

            $.ajax({
                url: 'ajax_sync_dataset_orgunits.php',
                method: 'POST',
                data: {
                    survey_id: surveyId,
                    dataset_uid: '<?= $survey['program_dataset'] ?>',
                    instance_key: '<?= $survey['dhis2_instance'] ?>'
                },
                dataType: 'json',
                success: function(response) {
                    console.log('[ORGUNIT] Sync response:', response);
                    if (response.success) {
                        $('#orgunitSyncStatus')
                            .removeClass('alert-info alert-danger')
                            .addClass('alert-success');
                        $('#orgunitSyncMessage').html(
                            `<i class="fas fa-check-circle me-1"></i>Successfully synced ${response.count} facilities from DHIS2! ${response.deleted > 0 ? `(Deleted ${response.deleted} old records)` : ''}`
                        );

                        // Update org unit count
                        $('#orgunitCount').text(response.count);

                        // Update last sync time
                        const now = new Date().toLocaleString();
                        $('#lastSyncTime').show();
                        $('#lastSyncTimeValue').text(now);

                        // Hide success message after 5 seconds
                        setTimeout(function() {
                            $('#orgunitSyncStatus').fadeOut();
                        }, 5000);
                    } else {
                        $('#orgunitSyncStatus')
                            .removeClass('alert-info alert-success')
                            .addClass('alert-danger');
                        $('#orgunitSyncMessage').html(
                            `<i class="fas fa-exclamation-triangle me-1"></i>Sync failed: ${response.message || 'Unknown error'}`
                        );
                    }
                },
                error: function(xhr) {
                    console.error('[ORGUNIT] Sync error:', xhr);
                    let errorMsg = 'Failed to sync facilities from DHIS2';
                    if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMsg = xhr.responseJSON.message;
                    } else if (xhr.responseText) {
                        errorMsg = xhr.responseText;
                    }

                    $('#orgunitSyncStatus')
                        .removeClass('alert-info alert-success')
                        .addClass('alert-danger');
                    $('#orgunitSyncMessage').html(
                        `<i class="fas fa-exclamation-triangle me-1"></i>${errorMsg}`
                    );
                },
                complete: function() {
                    // Re-enable button
                    btn.prop('disabled', false);
                    btn.html(originalText);
                }
            });
        }
    </script>
</body>
</html>
