<?php
session_start();

require_once '../admin/connect.php';

// Get submission_id from URL
$submissionId = $_GET['submission_id'] ?? null;

if (!$submissionId) {
    die("Submission ID is missing.");
}

// Fetch submission details
$submission = null;
$survey = null;

try {
    $stmt = $pdo->prepare("
        SELECT ds.*, s.name as survey_name
        FROM dataset_submissions ds
        JOIN survey s ON ds.survey_id = s.id
        WHERE ds.id = ?
    ");
    $stmt->execute([$submissionId]);
    $submission = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching submission: " . $e->getMessage());
}

if (!$submission) {
    die("Submission not found.");
}

$surveyName = htmlspecialchars($submission['survey_name']);
$submissionUID = htmlspecialchars($submission['uid']);
$period = htmlspecialchars($submission['period']);
$isUpdate = (bool)$submission['is_update'];

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$requestUri = $_SERVER['REQUEST_URI'] ?? '';
$currentUrl = $scheme . '://' . $host . $requestUri;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <link rel="icon" href="/fbs/public/logo.jpeg?v=1" type="image/jpeg">
    <link rel="shortcut icon" href="/fbs/public/logo.jpeg?v=1" type="image/jpeg">
    <link rel="apple-touch-icon" href="/fbs/public/logo.jpeg?v=1">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Submission Successful</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        :root {
            --primary-color: #4a5568;
            --success-color: #38a169;
            --uganda-black: #000000;
            --uganda-yellow: #FCD116;
            --uganda-red: #D21034;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f7fafc 0%, #e2e8f0 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .success-container {
            background: white;
            border-radius: 15px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            max-width: 600px;
            width: 100%;
            overflow: hidden;
        }

        .flag-bar {
            height: 10px;
            display: flex;
        }

        .flag-bar .black { background: var(--uganda-black); flex: 1; }
        .flag-bar .yellow { background: var(--uganda-yellow); flex: 1; }
        .flag-bar .red { background: var(--uganda-red); flex: 1; }

        .success-content {
            padding: 50px 40px;
            text-align: center;
        }

        .success-icon {
            width: 100px;
            height: 100px;
            background: linear-gradient(135deg, var(--success-color), #48bb78);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            animation: scaleIn 0.5s ease-out;
        }

        .success-icon i {
            font-size: 3rem;
            color: white;
        }

        @keyframes scaleIn {
            0% {
                transform: scale(0);
                opacity: 0;
            }
            50% {
                transform: scale(1.1);
            }
            100% {
                transform: scale(1);
                opacity: 1;
            }
        }

        .success-title {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 15px;
        }

        .success-message {
            font-size: 1.1rem;
            color: #718096;
            margin-bottom: 30px;
        }

        .submission-details {
            background: #f8f9fa;
            border-radius: 12px;
            padding: 25px;
            margin-bottom: 30px;
            text-align: left;
        }

        .detail-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #e2e8f0;
        }

        .detail-row:last-child {
            border-bottom: none;
        }

        .detail-label {
            font-weight: 600;
            color: var(--primary-color);
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .detail-value {
            color: #718096;
            font-family: 'Courier New', monospace;
        }

        .update-badge {
            display: inline-block;
            padding: 4px 12px;
            background: #fbbf24;
            color: white;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            flex-wrap: wrap;
        }

        .btn-custom {
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

        .btn-primary-custom {
            background: var(--primary-color);
            color: white;
        }

        .btn-primary-custom:hover {
            background: #2d3748;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.2);
            color: white;
        }

        .btn-secondary-custom {
            background: white;
            color: var(--primary-color);
            border: 2px solid var(--primary-color);
        }

        .btn-secondary-custom:hover {
            background: var(--primary-color);
            color: white;
        }


        @media (max-width: 576px) {
            .success-content {
                padding: 30px 20px;
            }

            .success-title {
                font-size: 1.5rem;
            }

            .actions {
                flex-direction: column;
            }

            .btn-custom {
                width: 100%;
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="flag-bar">
            <div class="black"></div>
            <div class="yellow"></div>
            <div class="red"></div>
        </div>

        <div class="success-content">
            <div class="success-icon">
                <i class="fas fa-check"></i>
            </div>

            <h1 class="success-title">
                <?= $isUpdate ? 'Data Updated Successfully!' : 'Submission Successful!' ?>
            </h1>

            <p class="success-message">
                Your data has been <?= $isUpdate ? 'updated' : 'submitted' ?> to DHIS2 successfully.
                Thank you for your contribution!
            </p>

            <div class="submission-details">
                <div class="detail-row">
                    <span class="detail-label">
                        <i class="fas fa-file-alt"></i>
                        Dataset Form
                    </span>
                    <span class="detail-value"><?= $surveyName ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">
                        <i class="fas fa-calendar"></i>
                        Period
                    </span>
                    <span class="detail-value"><?= $period ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">
                        <i class="fas fa-hashtag"></i>
                        Submission ID
                    </span>
                    <span class="detail-value"><?= $submissionUID ?></span>
                </div>
                <div class="detail-row">
                    <span class="detail-label">
                        <i class="fas fa-clock"></i>
                        Submitted
                    </span>
                    <span class="detail-value"><?= date('Y-m-d H:i:s', strtotime($submission['submitted_at'])) ?></span>
                </div>
                <?php if ($isUpdate): ?>
                <div class="detail-row">
                    <span class="detail-label">
                        <i class="fas fa-info-circle"></i>
                        Status
                    </span>
                    <span class="update-badge">
                        <i class="fas fa-sync-alt me-1"></i>Updated Existing Data
                    </span>
                </div>
                <?php endif; ?>
            </div>

            <div class="actions">
                <a href="/d/<?= $submission['survey_id'] ?>" class="btn-custom btn-secondary-custom">
                    <i class="fas fa-plus"></i>
                    Submit New Data
                </a>
              
            </div>
        </div>
    </div>

    <script>
        // Optional: Auto-redirect after a delay
        // setTimeout(() => {
        //     window.location.href = '/';
        // }, 10000); // Redirect after 10 seconds
    </script>
</body>
</html>
