<?php
session_start();

// Include the database connection file
require_once '../admin/connect.php';

// Get survey_id from the URL
$surveyId = $_GET['survey_id'] ?? null;

if (!$surveyId) {
    die("Survey ID is missing.");
}

// Fetch survey details
$defaultDatasetTitle = 'DHIS2 Aggregate Dataset';

if (isset($pdo)) {
    try {
        $surveyStmt = $pdo->prepare("SELECT id, type, name, program_dataset, dhis2_instance, program_type FROM survey WHERE id = ?");
        if ($surveyStmt) {
            $surveyStmt->execute([$surveyId]);
            $survey = $surveyStmt->fetch(PDO::FETCH_ASSOC);
            if ($survey) {
                $defaultDatasetTitle = htmlspecialchars($survey['name']);

                // Check if this is a DHIS2 aggregate dataset
                if ($survey['type'] !== 'dhis2' || empty($survey['program_dataset']) || $survey['program_type'] !== 'aggregate') {
                    // Redirect to appropriate share page
                    if ($survey['program_type'] === 'tracker') {
                        header("Location: tracker_share.php?survey_id=" . $surveyId);
                    } else {
                        header("Location: share_page.php?survey_id=" . $surveyId);
                    }
                    exit();
                }
            }
        }
    } catch (PDOException $e) {
        error_log("Database Query failed in dataset_share.php (survey fetch): " . $e->getMessage());
    }
}

// Get dataset settings from unified table
$datasetSettings = [];
$dynamicImages = [];

try {
    // Load layout and share settings
    $layoutStmt = $pdo->prepare("
        SELECT
            image_layout_type,
            show_flag_bar,
            flag_black_color,
            flag_yellow_color,
            flag_red_color,
            qr_instructions_text,
            show_qr_instructions_share,
            footer_note_text,
            show_footer_note_share,
            title_text
        FROM survey_settings
        WHERE survey_id = ?
    ");
    $layoutStmt->execute([$surveyId]);
    $layoutSettings = $layoutStmt->fetch(PDO::FETCH_ASSOC);

    // Load active images
    $imageStmt = $pdo->prepare("
        SELECT image_order, image_path, image_alt_text, width_px, height_px, position_type
        FROM dataset_images
        WHERE survey_id = ? AND is_active = 1
        ORDER BY image_order ASC
    ");
    $imageStmt->execute([$surveyId]);
    $dynamicImages = $imageStmt->fetchAll(PDO::FETCH_ASSOC);

    // Merge layout settings
    if ($layoutSettings) {
        $datasetSettings = [
            'show_flag_bar' => (bool)$layoutSettings['show_flag_bar'],
            'flag_black_color' => $layoutSettings['flag_black_color'],
            'flag_yellow_color' => $layoutSettings['flag_yellow_color'],
            'flag_red_color' => $layoutSettings['flag_red_color'],
            'qr_instructions_text' => $layoutSettings['qr_instructions_text'],
            'show_qr_instructions_share' => (bool)$layoutSettings['show_qr_instructions_share'],
            'footer_note_text' => $layoutSettings['footer_note_text'],
            'show_footer_note_share' => (bool)$layoutSettings['show_footer_note_share'],
            'title_text' => $layoutSettings['title_text']
        ];
        if (!empty($layoutSettings['image_layout_type'])) {
            $datasetSettings['layout_type'] = $layoutSettings['image_layout_type'];
        }
    }

} catch (PDOException $e) {
    error_log("Database error fetching dataset settings: " . $e->getMessage());
    $datasetSettings = [];
    $dynamicImages = [];
}

$datasetSettings = array_merge([
    'layout_type' => 'horizontal',
    'show_flag_bar' => true,
    'flag_black_color' => '#000000',
    'flag_yellow_color' => '#FCD116',
    'flag_red_color' => '#D21034',
    'qr_instructions_text' => 'Scan this QR Code to Access the DHIS2 Aggregate Data Entry Form',
    'show_qr_instructions_share' => 1,
    'footer_note_text' => 'Thank you for participating in our data collection program.',
    'show_footer_note_share' => 1,
    'title_text' => $defaultDatasetTitle,
], $datasetSettings);

// Construct the full URL for dataset_form.php
$scheme = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$scriptPath = $_SERVER['SCRIPT_NAME'];
$basePath = dirname($scriptPath);

$finalBaseDir = $basePath;
if ($finalBaseDir !== '/') {
    $finalBaseDir .= '/';
} else {
    $finalBaseDir = '/';
}

$qrCodeTargetUrl = $scheme . "://" . $host . "/d/" . $surveyId;

error_log("Dataset QR Code Target URL: " . $qrCodeTargetUrl);

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="/fbs/public/logo.jpeg?v=1" type="image/jpeg">
    <link rel="shortcut icon" href="/fbs/public/logo.jpeg?v=1" type="image/jpeg">
    <link rel="apple-touch-icon" href="/fbs/public/logo.jpeg?v=1">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Share Dataset: <?php echo htmlspecialchars($datasetSettings['title_text'] ?? $defaultDatasetTitle); ?></title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary-color: #4a5568;
            --secondary-color: #718096;
            --accent-color: #2d3748;
            --uganda-black: <?= $datasetSettings['flag_black_color'] ?>;
            --uganda-yellow: <?= $datasetSettings['flag_yellow_color'] ?>;
            --uganda-red: <?= $datasetSettings['flag_red_color'] ?>;
            --light-bg: #f7fafc;
            --primary-font: 'Poppins', sans-serif;
            --text-color-dark: #2d3748;
            --success-color: #38a169;
            --info-color: #3182ce;
            --neutral-gray: #e2e8f0;
            --dark-gray: #4a5568;
        }

        body {
            font-family: var(--primary-font);
            background: linear-gradient(135deg, var(--light-bg) 0%, var(--neutral-gray) 100%);
            margin: 0;
            padding: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            color: var(--text-color-dark);
            line-height: 1.6;
        }

        .feedback-container {
            background: white;
            padding: 40px;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            max-width: 700px;
            width: 100%;
            position: relative;
            overflow: hidden;
        }

        <?php if ($datasetSettings['show_flag_bar']): ?>
        .flag-bar {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 10px;
            display: flex;
        }
        .flag-bar .black {
            flex: 1;
            background: var(--uganda-black);
        }
        .flag-bar .yellow {
            flex: 1;
            background: var(--uganda-yellow);
        }
        .flag-bar .red {
            flex: 1;
            background: var(--uganda-red);
        }
        <?php endif; ?>

        .content-wrapper {
            margin-top: <?= $datasetSettings['show_flag_bar'] ? '20px' : '0' ?>;
        }

        .title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 20px;
            text-align: center;
        }

        .subtitle {
            font-size: 1.1rem;
            color: var(--secondary-color);
            text-align: center;
            margin-bottom: 30px;
        }

        .image-container {
            text-align: center;
            margin-bottom: 30px;
        }

        .image-container.horizontal {
            display: flex;
            justify-content: center;
            gap: 20px;
            flex-wrap: wrap;
        }

        .image-container.vertical {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 20px;
        }

        .dynamic-image {
            max-width: 100%;
            height: auto;
            border-radius: 8px;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
        }

        .qr-code-section {
            text-align: center;
            padding: 30px;
            background: linear-gradient(135deg, #f8f9fa, #e9ecef);
            border-radius: 12px;
            margin: 30px 0;
        }

        .qr-code-instruction {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 20px;
        }

        .qr-code-wrapper {
            display: inline-block;
            padding: 20px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 8px 16px rgba(0, 0, 0, 0.1);
        }

        .qr-code-image {
            display: block;
            margin: 0 auto;
        }

        .url-display {
            margin-top: 20px;
            padding: 15px;
            background: white;
            border-radius: 8px;
            border: 2px dashed var(--uganda-yellow);
            word-break: break-all;
        }

        .url-label {
            font-weight: 600;
            color: var(--primary-color);
            margin-bottom: 5px;
        }

        .url-text {
            color: var(--info-color);
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
        }
        .url-row {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .url-logo {
            width: 28px;
            height: 28px;
            object-fit: contain;
            flex-shrink: 0;
        }

        .copy-url-btn {
            margin-top: 15px;
            padding: 10px 30px;
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .copy-url-btn:hover {
            background: var(--accent-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }


        .actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
        }

        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary:hover {
            background: var(--accent-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
        }

        .btn-secondary {
            background: white;
            color: var(--primary-color);
            border: 2px solid var(--primary-color);
        }

        .btn-secondary:hover {
            background: var(--primary-color);
            color: white;
        }

        @media print {
            body {
                background: white;
            }
            .actions, .copy-url-btn {
                display: none;
            }
            .feedback-container {
                box-shadow: none;
                max-width: 100%;
            }
        }

        @media (max-width: 768px) {
            .feedback-container {
                padding: 20px;
            }
            .title {
                font-size: 1.5rem;
            }
            .actions {
                flex-direction: column;
            }
            .btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="feedback-container">
        <?php if ($datasetSettings['show_flag_bar']): ?>
            <div class="flag-bar">
                <div class="black"></div>
                <div class="yellow"></div>
                <div class="red"></div>
            </div>
        <?php endif; ?>

        <div class="content-wrapper">
            <h1 class="title"><?= htmlspecialchars($datasetSettings['title_text']) ?></h1>

            <?php if (!empty($dynamicImages)): ?>
                <div class="image-container <?= $datasetSettings['layout_type'] ?>">
                    <?php foreach ($dynamicImages as $image): ?>
                        <img src="<?= htmlspecialchars($image['image_path']) ?>"
                             alt="<?= htmlspecialchars($image['image_alt_text'] ?? 'Image') ?>"
                             class="dynamic-image"
                             <?php if (!empty($image['width_px'])): ?>width="<?= $image['width_px'] ?>"<?php endif; ?>
                             <?php if (!empty($image['height_px'])): ?>height="<?= $image['height_px'] ?>"<?php endif; ?>>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="qr-code-section">
                <?php if ($datasetSettings['show_qr_instructions_share']): ?>
                    <div class="qr-code-instruction">
                        <i class="fas fa-qrcode me-2"></i>
                        <?= htmlspecialchars($datasetSettings['qr_instructions_text']) ?>
                    </div>
                <?php endif; ?>

                <div class="qr-code-wrapper">
                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=<?= urlencode($qrCodeTargetUrl) ?>"
                         alt="QR Code for Dataset Form"
                         class="qr-code-image">
                </div>

                <div class="url-display">
                    <div class="url-label">
                        <i class="fas fa-link me-2"></i>Direct Link:
                    </div>
                    <div class="url-row">
                        <img src="/fbs/public/logo.jpeg?v=1" alt="Logo" class="url-logo">
                        <div class="url-text" id="surveyUrl"><?= htmlspecialchars($qrCodeTargetUrl) ?></div>
                    </div>
                </div>

                <button class="copy-url-btn" onclick="copyUrl()">
                    <i class="fas fa-copy me-2"></i>Copy Link
                </button>
            </div>


            <div class="actions">
                <button class="btn btn-primary" onclick="window.print()">
                    <i class="fas fa-print"></i>
                    Print QR Code
                </button>
                <a href="<?= $qrCodeTargetUrl ?>" class="btn btn-secondary" target="_blank">
                    <i class="fas fa-external-link-alt"></i>
                    Open Form
                </a>
            </div>
        </div>
    </div>

    <script>
        function copyUrl() {
            const urlText = document.getElementById('surveyUrl').textContent;
            const tempInput = document.createElement('input');
            tempInput.value = urlText;
            document.body.appendChild(tempInput);
            tempInput.select();
            document.execCommand('copy');
            document.body.removeChild(tempInput);

            // Visual feedback
            const btn = event.target.closest('.copy-url-btn');
            const originalText = btn.innerHTML;
            btn.innerHTML = '<i class="fas fa-check me-2"></i>Copied!';
            btn.style.background = '#38a169';

            setTimeout(() => {
                btn.innerHTML = originalText;
                btn.style.background = '';
            }, 2000);
        }
    </script>
</body>
</html>
