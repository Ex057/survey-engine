<?php
// Quick diagnostic script to check survey configuration
require_once 'connect.php';

$surveyId = $_GET['id'] ?? null;

if (!$surveyId) {
    die("Usage: check_survey.php?id=SURVEY_ID");
}

try {
    $stmt = $pdo->prepare("SELECT * FROM survey WHERE id = ?");
    $stmt->execute([$surveyId]);
    $survey = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$survey) {
        die("Survey not found with ID: $surveyId");
    }

    echo "<h2>Survey Diagnostic Report</h2>";
    echo "<table border='1' cellpadding='10'>";
    echo "<tr><th>Field</th><th>Value</th><th>Status</th></tr>";

    // Check each critical field
    $checks = [
        'id' => ['value' => $survey['id'], 'required' => true],
        'name' => ['value' => $survey['name'], 'required' => true],
        'type' => ['value' => $survey['type'], 'required' => true, 'expected' => 'dhis2'],
        'program_type' => ['value' => $survey['program_type'], 'required' => true, 'expected' => 'aggregate'],
        'domain_type' => ['value' => $survey['domain_type'], 'required' => false, 'expected' => 'aggregate'],
        'dhis2_instance' => ['value' => $survey['dhis2_instance'], 'required' => true],
        'program_dataset' => ['value' => $survey['program_dataset'], 'required' => true],
        'dhis2_program_uid' => ['value' => $survey['dhis2_program_uid'], 'required' => false],
        'is_active' => ['value' => $survey['is_active'], 'required' => true, 'expected' => '1']
    ];

    foreach ($checks as $field => $check) {
        $value = $check['value'] ?? 'NULL';
        $status = '✓ OK';
        $color = 'green';

        if ($check['required'] && empty($value)) {
            $status = '✗ EMPTY (REQUIRED!)';
            $color = 'red';
        } elseif (isset($check['expected']) && $value != $check['expected']) {
            $status = "⚠ Expected '{$check['expected']}'";
            $color = 'orange';
        }

        echo "<tr>";
        echo "<td><strong>$field</strong></td>";
        echo "<td>" . htmlspecialchars($value) . "</td>";
        echo "<td style='color: $color;'>$status</td>";
        echo "</tr>";
    }

    echo "</table>";

    // Routing check
    echo "<h3>Routing Check</h3>";
    echo "<p>Based on the configuration, this survey should route to:</p>";

    if (empty($survey['program_dataset']) || $survey['program_type'] !== 'aggregate') {
        if ($survey['program_type'] === 'tracker') {
            echo "<p style='color: orange;'><strong>→ tracker_program_form.php</strong> (Tracker Program)</p>";
            echo "<p>Reason: program_type is 'tracker' OR program_dataset is empty</p>";
        } else {
            echo "<p style='color: orange;'><strong>→ survey_page.php</strong> (Local Survey)</p>";
            echo "<p>Reason: Not aggregate and not tracker</p>";
        }
    } else {
        echo "<p style='color: green;'><strong>→ dataset_form.php</strong> (Aggregate Dataset) ✓</p>";
        echo "<p>All conditions met!</p>";
    }

    // URL Test Links
    echo "<h3>Test Links</h3>";
    echo "<ul>";
    echo "<li><a href='/d/{$surveyId}' target='_blank'>Dataset Form: /d/{$surveyId}</a></li>";
    echo "<li><a href='/share/d/{$surveyId}' target='_blank'>Share Page: /share/d/{$surveyId}</a></li>";
    echo "<li><a href='/t/{$surveyId}' target='_blank'>Tracker Form: /t/{$surveyId}</a> (for comparison)</li>";
    echo "</ul>";

} catch (Exception $e) {
    die("Error: " . $e->getMessage());
}
?>
