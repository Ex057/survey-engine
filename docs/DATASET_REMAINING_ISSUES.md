# Dataset Feature - Remaining Issues & Solutions

## Current Status

The dataset feature has been implemented with local storage architecture (similar to tracker programs), but there are **3 critical issues** that need to be addressed:

---

## Issue 1: Option Set Dropdowns Not Working

### Problem
Data elements with option sets are not rendering as dropdowns. They should display as `<select>` elements with the option values, but they're currently showing as text inputs or not showing options at all.

### Root Cause
The form renderer (`DatasetFormRenderer.php`) needs to properly detect and render option sets from the locally stored metadata.

### Solution Required
1. **Check `dataset_option_sets` and `dataset_option_values` tables** - Verify data is being stored during sync
2. **Update `DatasetFormRenderer.php`** - Ensure it reads option sets from `$dataElement['optionSet']['options']` and renders them as dropdowns
3. **Test with actual dataset** - Verify dropdowns appear with correct values

### Code Location
- `/fbs/public/includes/form_renderers/DatasetFormRenderer.php` (lines 66-76)
- Check if option set structure from `DatasetStorageService::getStoredDataset()` matches expected format

---

## Issue 2: Custom Form Scripts/CSS Not Applied

### Problem
When a dataset has a custom form design (formType=CUSTOM) with inline `<script>` and `<style>` tags in the HTML, these are not being applied when the form renders.

### Example Script
```javascript
<script>
function opentab(evt, tabName) {
    // Tab switching logic
}

function trackTermSelection() {
    // Term-based tab enabling/disabling
    var term = document.getElementById('category-SKwLcRbzrAi');
    // ... logic to show/hide tabs based on selected term
}
</script>
```

### Root Cause
The `CustomFormRenderer.php` strips out `<script>` and `<style>` tags for security reasons, or they're not being properly executed after page load.

### Solution Required
1. **Extract scripts and styles from custom HTML** - Separate them from the HTML content
2. **Store them separately** - Already done in `dataset_metadata` table (`custom_form_style` column)
3. **Inject scripts at the end of the page** - Add them to the dataset_form.php template after the form
4. **Ensure proper script execution** - Scripts need to run after DOM is loaded

### Implementation Steps
```php
// In dataset_form.php (after form HTML)
<?php if ($dataset['formType'] === 'CUSTOM' && !empty($metadata['custom_form_html'])): ?>
    <!-- Extract and inject <style> tags -->
    <?php
    // Parse custom_form_html for <style> tags
    preg_match_all('/<style[^>]*>(.*?)<\/style>/is', $metadata['custom_form_html'], $styles);
    foreach ($styles[1] as $style) {
        echo "<style>{$style}</style>";
    }
    ?>

    <!-- Extract and inject <script> tags -->
    <?php
    preg_match_all('/<script[^>]*>(.*?)<\/script>/is', $metadata['custom_form_html'], $scripts);
    foreach ($scripts[1] as $script) {
        echo "<script>{$script}</script>";
    }
    ?>
<?php endif; ?>
```

### Security Considerations
- **Sanitize scripts** - Only allow scripts from trusted DHIS2 instances
- **Content Security Policy** - May need to adjust CSP headers
- **XSS Protection** - Scripts come from DHIS2 admin, so should be trusted

---

## Issue 3: Validation Rules Not Implemented

### Problem
DHIS2 datasets have validation rules that enforce data quality:
- **Value Type validation** (NUMBER, INTEGER, TEXT, DATE, etc.)
- **Min/Max ranges** - Numbers within specific ranges
- **Required fields** - Some data elements are mandatory
- **Cross-field validation** - Rules that compare multiple fields

### DHIS2 Validation Rules vs Program Rules

| Feature | Tracker/Event Programs | Aggregate Datasets |
|---------|----------------------|-------------------|
| **Rule Type** | Program Rules | Validation Rules |
| **When Applied** | Real-time (during data entry) | After submission or on-demand |
| **Actions** | Show/hide fields, assign values, show warnings | Compare data elements, show errors |
| **Storage** | Stored with program metadata | Stored separately in DHIS2 |
| **API Endpoint** | `/api/programs/{uid}?fields=programRules` | `/api/validationRules?filter=dataSet.id:eq:{uid}` |

### Current Implementation Gap
The current dataset storage service does NOT fetch or store validation rules.

### Solution Required

#### Step 1: Fetch Validation Rules During Sync
```php
// In DatasetStorageService::storeDatasetMetadata()
$validationRules = dhis2_get(
    "/api/validationRules.json?filter=dataSet.id:eq:{$datasetUid}&fields=id,name,description,importance,operator,leftSide[expression,missingValueStrategy],rightSide[expression,missingValueStrategy]",
    $instanceKey
);
```

#### Step 2: Store Validation Rules in Database
```sql
CREATE TABLE IF NOT EXISTS dataset_validation_rules (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    survey_id INT NOT NULL,
    rule_uid VARCHAR(11) NOT NULL,
    rule_name VARCHAR(255) NOT NULL,
    description TEXT,
    importance ENUM('HIGH', 'MEDIUM', 'LOW') DEFAULT 'MEDIUM',
    operator VARCHAR(50), -- compulsory_pair, exclusive_pair, equal_to, not_equal_to, greater_than, greater_than_or_equal_to, less_than, less_than_or_equal_to
    left_side_expression TEXT,
    right_side_expression TEXT,
    left_side_missing_strategy VARCHAR(50),
    right_side_missing_strategy VARCHAR(50),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_survey_id (survey_id),
    UNIQUE KEY unique_survey_rule (survey_id, rule_uid),
    FOREIGN KEY (survey_id) REFERENCES survey(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### Step 3: Client-Side Validation (Basic)
Implement basic validation based on `valueType`:

```javascript
// In dataset_form.php
function validateDataElement(fieldId, valueType, value) {
    switch(valueType) {
        case 'NUMBER':
        case 'INTEGER':
            return !isNaN(value) && (valueType === 'NUMBER' || Number.isInteger(parseFloat(value)));
        case 'DATE':
            return !isNaN(Date.parse(value));
        case 'BOOLEAN':
        case 'TRUE_ONLY':
            return value === 'true' || value === 'false' || value === '1' || value === '0';
        default:
            return true;
    }
}
```

#### Step 4: Server-Side Validation (Advanced)
Evaluate validation rules on server before submitting to DHIS2:

```php
// Create DatasetValidationService.php
class DatasetValidationService {
    public static function validateDataValues($surveyId, $dataValues) {
        // Fetch validation rules from database
        // Evaluate expressions
        // Return validation errors
    }
}
```

---

## Implementation Priority

### Phase 1 (Critical - Do Now)
1. ✅ Fix preview timeout (COMPLETED)
2. ✅ Implement local storage sync (COMPLETED)
3. ❌ **Fix option set dropdowns** (IN PROGRESS)
4. ❌ **Apply custom form scripts/CSS** (BLOCKED)

### Phase 2 (Important - Do Next)
5. ❌ Implement basic client-side validation (value types)
6. ❌ Fetch and store validation rules
7. ❌ Add org unit hierarchy display
8. ❌ Implement section navigation sidebar

### Phase 3 (Enhancement - Do Later)
9. ❌ Server-side validation rule evaluation
10. ❌ Advanced validation rules (cross-field comparisons)
11. ❌ Validation error reporting UI
12. ❌ Data submission to DHIS2

---

## Testing Checklist

### Option Sets
- [ ] Sync dataset with option set data elements
- [ ] Verify `dataset_option_sets` table has records
- [ ] Verify `dataset_option_values` table has records
- [ ] Open dataset form and check if dropdowns render
- [ ] Select values from dropdown and submit

### Custom Forms
- [ ] Sync dataset with custom form (formType=CUSTOM)
- [ ] Verify `custom_form_html` stored in `dataset_metadata` table
- [ ] Open form and check if custom styling applied
- [ ] Test tab switching functionality
- [ ] Test term-based tab enabling/disabling

### Validation
- [ ] Enter invalid number (text in NUMBER field)
- [ ] Enter invalid date format
- [ ] Submit empty required field
- [ ] Verify validation errors display
- [ ] Verify valid data submits successfully

---

## Next Steps

1. **First, run the migration** to create `dataset_metadata` table:
   ```
   http://localhost/run_dataset_metadata_migration.php
   ```

2. **Then, re-sync all datasets** to populate with metadata:
   ```
   http://localhost/fbs/admin/sync_dataset_metadata.php
   ```

3. **Test option set rendering** - Try creating a dataset survey and check if dropdowns work

4. **Fix custom form scripts** - If using custom forms, implement script/style extraction

5. **Add validation** - Implement basic client-side validation for value types

---

## Questions for User

1. **Do your datasets use custom forms?** If yes, we need to prioritize script/style extraction
2. **Are option sets critical?** Do most of your data elements use option sets for dropdowns?
3. **What validation rules are most important?** Number ranges? Required fields? Cross-field comparisons?
4. **Do you need validation before or after submission?** Real-time or batch validation?
