<?php
/**
 * Dataset Metadata Sync Tool
 * Syncs dataset metadata (data elements, option sets, org units) to local database
 */

session_start();
require_once 'connect.php';

// Debug session
error_log("[SYNC PAGE] Session ID: " . session_id());
error_log("[SYNC PAGE] Admin ID in session: " . (isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : 'NOT SET'));
error_log("[SYNC PAGE] Session data: " . print_r($_SESSION, true));

if (!isset($_SESSION['admin_id'])) {
    error_log("[SYNC PAGE] Admin not logged in, redirecting to login.php");
    header("Location: login.php");
    exit;
}

error_log("[SYNC PAGE] Admin logged in successfully, proceeding...");

// Get all dataset surveys
$stmt = $pdo->query("
    SELECT id, name, program_dataset, dhis2_instance
    FROM survey
    WHERE program_type = 'aggregate'
    ORDER BY id DESC
");
$datasets = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sync Dataset Metadata - FormBase Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background: #f8f9fa;
        }
        .main-container {
            padding: 30px;
        }
        .sync-card {
            margin-bottom: 15px;
            transition: all 0.3s;
        }
        .sync-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .sync-status {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .status-pending { background: #e3f2fd; color: #1976d2; }
        .status-syncing { background: #fff3e0; color: #f57c00; }
        .status-success { background: #e8f5e9; color: #388e3c; }
        .status-error { background: #ffebee; color: #d32f2f; }
    </style>
</head>
<body>
    <?php include 'components/navbar.php'; ?>

    <div class="d-flex">
        <?php include 'components/aside.php'; ?>

        <div class="main-container flex-grow-1">
            <div class="container-fluid">
                <div class="row mb-4">
                    <div class="col-12">
                        <div class="d-flex justify-content-between align-items-center">
                            <h2><i class="fas fa-sync-alt me-2"></i>Sync Dataset Metadata</h2>
                            <a href="main.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-1"></i>Back to Dashboard
                            </a>
                        </div>
                        <p class="text-muted">Sync dataset metadata (data elements, option sets, org units) to local database for improved performance and dropdown functionality</p>
                    </div>
                </div>

                <?php if (empty($datasets)): ?>
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        No dataset surveys found. Create a dataset survey first.
                    </div>
                <?php else: ?>
                    <div class="row mb-3">
                        <div class="col-12">
                            <button class="btn btn-primary" id="syncAllBtn">
                                <i class="fas fa-sync me-1"></i>Sync All Datasets
                            </button>
                            <span class="ms-3 text-muted" id="overallStatus"></span>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-12">
                            <?php foreach ($datasets as $dataset): ?>
                                <div class="card sync-card" data-survey-id="<?= $dataset['id'] ?>">
                                    <div class="card-body">
                                        <div class="row align-items-center">
                                            <div class="col-md-6">
                                                <h5 class="mb-1"><?= htmlspecialchars($dataset['name']) ?></h5>
                                                <small class="text-muted">
                                                    Survey ID: <?= $dataset['id'] ?> |
                                                    Dataset: <?= htmlspecialchars($dataset['program_dataset']) ?> |
                                                    Instance: <?= htmlspecialchars($dataset['dhis2_instance']) ?>
                                                </small>
                                            </div>
                                            <div class="col-md-4">
                                                <span class="sync-status status-pending" data-status>
                                                    <i class="fas fa-clock me-1"></i>Not synced
                                                </span>
                                                <div class="mt-2" style="display: none;" data-result></div>
                                            </div>
                                            <div class="col-md-2 text-end">
                                                <button class="btn btn-sm btn-primary sync-btn"
                                                        data-survey-id="<?= $dataset['id'] ?>">
                                                    <i class="fas fa-sync me-1"></i>Sync Now
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        $(document).ready(function() {
            // Sync single dataset
            $('.sync-btn').on('click', function() {
                const surveyId = $(this).data('survey-id');
                syncDataset(surveyId);
            });

            // Sync all datasets
            $('#syncAllBtn').on('click', function() {
                const surveyIds = $('.sync-card').map(function() {
                    return $(this).data('survey-id');
                }).get();

                syncAllDatasets(surveyIds);
            });
        });

        function syncDataset(surveyId) {
            const $card = $(`.sync-card[data-survey-id="${surveyId}"]`);
            const $status = $card.find('[data-status]');
            const $result = $card.find('[data-result]');
            const $btn = $card.find('.sync-btn');

            // Update UI to syncing state
            $status.removeClass('status-pending status-success status-error')
                   .addClass('status-syncing')
                   .html('<i class="fas fa-spinner fa-spin me-1"></i>Syncing...');
            $btn.prop('disabled', true);
            $result.hide();

            // Make AJAX request
            $.ajax({
                url: 'ajax_sync_dataset_metadata.php',
                method: 'POST',
                data: { survey_id: surveyId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $status.removeClass('status-syncing')
                               .addClass('status-success')
                               .html('<i class="fas fa-check-circle me-1"></i>Synced successfully');

                        const parts = [];
                        if (response.data_elements) parts.push(`${response.data_elements} data elements`);
                        if (response.option_sets) parts.push(`${response.option_sets} option sets`);
                        if (response.attributes) parts.push(`${response.attributes} attributes`);
                        if (response.org_units) parts.push(`${response.org_units} org units`);

                        $result.html(`<small class="text-success">${parts.join(', ')}</small>`).show();
                    } else {
                        $status.removeClass('status-syncing')
                               .addClass('status-error')
                               .html('<i class="fas fa-exclamation-circle me-1"></i>Sync failed');

                        $result.html(`<small class="text-danger">${response.message}</small>`).show();
                    }
                },
                error: function(xhr) {
                    console.error('AJAX Error:', xhr);

                    let message = 'Network error';
                    let details = '';

                    if (xhr.responseJSON) {
                        message = xhr.responseJSON.message || 'Unknown error';

                        // Show detailed error and trace if available
                        if (xhr.responseJSON.error) {
                            details = `<br><strong>Error:</strong> ${xhr.responseJSON.error}`;
                        }
                        if (xhr.responseJSON.trace) {
                            details += `<br><pre style="font-size: 0.7rem; max-height: 200px; overflow-y: auto;">${xhr.responseJSON.trace}</pre>`;
                        }
                    } else if (xhr.responseText) {
                        message = 'Server error (see console)';
                        console.error('Response text:', xhr.responseText);
                    }

                    $status.removeClass('status-syncing')
                           .addClass('status-error')
                           .html('<i class="fas fa-exclamation-circle me-1"></i>Sync failed');

                    $result.html(`<small class="text-danger">${message}${details}</small>`).show();
                },
                complete: function() {
                    $btn.prop('disabled', false);
                }
            });
        }

        async function syncAllDatasets(surveyIds) {
            $('#syncAllBtn').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-1"></i>Syncing...');
            $('#overallStatus').text(`Syncing ${surveyIds.length} datasets...`);

            let completed = 0;
            let success = 0;
            let failed = 0;

            for (const surveyId of surveyIds) {
                await new Promise((resolve) => {
                    syncDataset(surveyId);
                    setTimeout(resolve, 1000); // Wait 1 second between syncs
                });

                completed++;
                $('#overallStatus').text(`Synced ${completed} of ${surveyIds.length} datasets...`);
            }

            $('#syncAllBtn').prop('disabled', false).html('<i class="fas fa-sync me-1"></i>Sync All Datasets');
            $('#overallStatus').text(`Completed syncing ${surveyIds.length} datasets`);
        }
    </script>
</body>
</html>
