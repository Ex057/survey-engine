<?php
// Mobile-first SECTION B matrix demo/refactor scaffold
// Plain PHP + HTML + CSS + vanilla JS

$ageBands = [
    'age_lt_6' => '< 6 years',
    'age_6' => '6 years',
    'age_7' => '7 years',
    'age_8' => '8 years',
    'age_9' => '9 years',
    'age_10' => '10 years',
    'age_11' => '11 years',
    'age_12' => '12 years',
    'age_13' => '13 years',
    'age_14_plus' => '14 years and above',
];

$pLevels = ['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7'];
$sexes = ['M', 'F'];

// Existing values can come from DB/previous POST
$existingEnrollment = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $existingEnrollment = $_POST['enrollment'] ?? [];
}

function safeValue(array $enrollment, string $ageKey, string $p, string $sex): string {
    $value = $enrollment[$ageKey][$p][$sex] ?? '';
    return is_scalar($value) ? (string)$value : '';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>SECTION B: Learner Enrolment</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <main class="page-wrap">
        <section class="matrix-card" aria-labelledby="section-title">
            <header class="section-header">
                <div>
                    <h1 id="section-title">SECTION B: Learner Enrolment in the Reporting Term</h1>
                    <p class="section-subtitle">Enter counts by age band, class (P1..P7), and sex.</p>
                </div>
                <div class="status-pill" id="unsavedStatus" aria-live="polite">Saved</div>
            </header>

            <form method="post" action="" id="enrollmentForm" novalidate>
                <div class="toggle-row">
                    <label class="toggle-label">
                        <input type="checkbox" id="showTotals" checked>
                        Show row totals
                    </label>
                </div>

                <div class="matrix-scroll" id="matrixScroll" role="region" aria-label="Learner enrolment matrix" tabindex="0">
                    <table class="matrix-table" id="enrollmentMatrix">
                        <thead>
                            <tr>
                                <th class="sticky-col sticky-head age-head" rowspan="2" scope="col">Age Band</th>
                                <?php foreach ($pLevels as $p): ?>
                                    <th class="sticky-head" colspan="2" scope="colgroup"><?= htmlspecialchars($p) ?></th>
                                <?php endforeach; ?>
                                <th class="sticky-head total-head" rowspan="2" scope="col" data-total-col>Row Total</th>
                            </tr>
                            <tr>
                                <?php foreach ($pLevels as $p): ?>
                                    <?php foreach ($sexes as $sex): ?>
                                        <th class="sticky-head sex-head" scope="col"><?= htmlspecialchars($sex) ?></th>
                                    <?php endforeach; ?>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ageBands as $ageKey => $ageLabel): ?>
                                <tr data-age-row="<?= htmlspecialchars($ageKey) ?>">
                                    <th class="sticky-col row-head" scope="row"><?= htmlspecialchars($ageLabel) ?></th>
                                    <?php foreach ($pLevels as $p): ?>
                                        <?php foreach ($sexes as $sex): ?>
                                            <?php
                                            $inputId = "enrollment_{$ageKey}_{$p}_{$sex}";
                                            $value = safeValue($existingEnrollment, $ageKey, $p, $sex);
                                            ?>
                                            <td>
                                                <label class="sr-only" for="<?= htmlspecialchars($inputId) ?>">
                                                    <?= htmlspecialchars("{$ageLabel}, {$p}, {$sex}") ?>
                                                </label>
                                                <input
                                                    id="<?= htmlspecialchars($inputId) ?>"
                                                    name="enrollment[<?= htmlspecialchars($ageKey) ?>][<?= htmlspecialchars($p) ?>][<?= htmlspecialchars($sex) ?>]"
                                                    class="matrix-input"
                                                    type="number"
                                                    min="0"
                                                    step="1"
                                                    inputmode="numeric"
                                                    pattern="[0-9]*"
                                                    aria-label="<?= htmlspecialchars("{$ageLabel} {$p} {$sex}") ?>"
                                                    value="<?= htmlspecialchars($value) ?>"
                                                >
                                            </td>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                    <td class="total-cell" data-row-total>0</td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="form-errors" id="formErrors" role="alert" aria-live="polite"></div>

                <button class="submit-btn" type="submit" id="submitBtn">Submit Data</button>
            </form>
        </section>

        <?php if ($_SERVER['REQUEST_METHOD'] === 'POST'): ?>
            <section class="result-card" aria-labelledby="posted-title">
                <h2 id="posted-title">Posted Payload (`$_POST['enrollment']`)</h2>
                <pre><?= htmlspecialchars(print_r($_POST['enrollment'] ?? [], true)) ?></pre>
            </section>
        <?php endif; ?>
    </main>

    <script src="form.js"></script>
</body>
</html>
