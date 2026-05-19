# Survey Settings Analysis - Database Structure

## Key Findings

### 1. Survey Types are Identified By:

**From `survey` table:**
- `type`: `enum('local','dhis2')` - Whether it uses DHIS2 or not
- `program_type`: `enum('local','event','tracker','aggregate')` - The specific type
- `domain_type`: varchar(100) - Additional classification

**Survey Type Combinations:**
| Type | Program Type | Domain Type | Use Case | Example |
|------|--------------|-------------|----------|---------|
| dhis2 | tracker | tracker | DHIS2 Tracker Programs | Survey #1, #2, #22 |
| dhis2 | aggregate | aggregate | DHIS2 Datasets | Survey #20 |
| local | local | (empty) | Regular Surveys | Survey #3 |
| dhis2 | event | event | DHIS2 Event Programs | (not in sample) |

### 2. Survey Settings Table - Current Structure

**Total Columns:** 32

**Text Customization Fields (Already Exist):**
1. `title_text` - Form title
2. `subheading_text` - Form description
3. `rating_instruction1_text` - Rating instructions line 1
4. `rating_instruction2_text` - Rating instructions line 2
5. `republic_title_text` - Share page republic title
6. `ministry_subtitle_text` - Share page ministry subtitle
7. `qr_instructions_text` - QR code instructions
8. `footer_note_text` - Footer text

**Important Finding:**
- Survey #20 (aggregate/dataset) has **NO survey_settings row**!
- Survey #22 (tracker) has **NO survey_settings row**!
- Only surveys #1, #2, #3 have survey_settings

**This means:**
✅ `survey_settings` is optional (not all surveys have it)
✅ Need to handle `NULL` settings gracefully
✅ Must create settings row when needed
✅ Default values in column definition are crucial

### 3. Recommendation: Add Generic Columns

Since `survey_settings`:
- Has ONE row per survey (1:1 relationship)
- Already stores customizable text
- Already works with the preview UI

**Solution:** Add new columns that work for ALL survey types with smart defaults

## Proposed New Columns

```sql
ALTER TABLE `survey_settings`

-- Generic facility/location labels (used by all types)
ADD COLUMN `facility_label` VARCHAR(255) DEFAULT 'School / Facility',
ADD COLUMN `facility_search_label` VARCHAR(100) DEFAULT 'Search',
ADD COLUMN `facility_search_placeholder` VARCHAR(255) DEFAULT 'Type to search...',
ADD COLUMN `facility_selected_label` VARCHAR(100) DEFAULT 'Selected:',
ADD COLUMN `facility_change_button` VARCHAR(50) DEFAULT 'Change',

-- Period labels (mainly for datasets, but could be used by others)
ADD COLUMN `period_label` VARCHAR(100) DEFAULT 'Period',
ADD COLUMN `period_load_button` VARCHAR(100) DEFAULT 'Load Data',

-- Submit button (used by all)
ADD COLUMN `submit_button_text` VARCHAR(100) DEFAULT 'Submit',
ADD COLUMN `submit_loading_text` VARCHAR(255) DEFAULT 'Submitting...',

-- Instructions (used by all)
ADD COLUMN `search_instruction` VARCHAR(255) DEFAULT 'Type at least 2 characters to search';
```

**Total: ~10 new columns** (very manageable!)

## Smart Defaults Strategy

The form code checks `survey.program_type` and uses appropriate defaults:

```php
function getDefaultLabel($key, $programType) {
    $defaults = [
        'aggregate' => [
            'facility_label' => 'Facility/Organization Unit Selection',
            'facility_search_placeholder' => 'Type to search for a facility...',
            'period_label' => 'Reporting Period',
            'submit_button_text' => 'Submit Data'
        ],
        'tracker' => [
            'facility_label' => 'School Selection',
            'facility_search_placeholder' => 'Type to search for school...',
            'submit_button_text' => 'Submit Entry'
        ],
        'local' => [
            'facility_label' => 'Locations:',
            'facility_search_placeholder' => 'Type to search...',
            'submit_button_text' => 'Submit'
        ]
    ];

    return $defaults[$programType][$key] ?? '';
}
```

## Usage in Forms

```php
// In dataset_form.php, survey_page.php, tracker_program_form.php

// Get survey info
$stmt = $pdo->prepare("SELECT * FROM survey WHERE id = ?");
$stmt->execute([$surveyId]);
$survey = $stmt->fetch();

// Get settings (may not exist!)
$stmt = $pdo->prepare("SELECT * FROM survey_settings WHERE survey_id = ?");
$stmt->execute([$surveyId]);
$settings = $stmt->fetch();

// Helper function with type-aware defaults
function getLabel($key, $default = null) {
    global $settings, $survey;

    // 1. Try custom setting
    if ($settings && !empty($settings[$key])) {
        return $settings[$key];
    }

    // 2. Try provided default
    if ($default !== null) {
        return $default;
    }

    // 3. Use type-specific default
    return getDefaultLabel($key, $survey['program_type']);
}

// Use in HTML
<label><?= getLabel('facility_label', 'Facility Selection') ?></label>
<input placeholder="<?= getLabel('facility_search_placeholder') ?>">
<button><?= getLabel('submit_button_text') ?></button>
```

## Benefits of This Approach

1. ✅ **Works with missing settings** - Uses smart defaults based on survey type
2. ✅ **Backwards compatible** - Existing surveys without settings work fine
3. ✅ **Type-aware** - Different defaults for dataset/tracker/local
4. ✅ **Simple** - Just add columns, no complex joins
5. ✅ **Flexible** - Each survey can override defaults
6. ✅ **Fast** - Single row fetch (or smart defaults if no row)

## Migration Strategy

### Phase 1: Add Columns
```sql
-- Run migration to add new columns with defaults
ALTER TABLE survey_settings ADD COLUMN facility_label VARCHAR(255) DEFAULT 'School / Facility';
-- ... etc
```

### Phase 2: Create Missing Settings Rows
```sql
-- Create survey_settings for surveys that don't have it
INSERT INTO survey_settings (survey_id, title_text)
SELECT id, name FROM survey
WHERE id NOT IN (SELECT survey_id FROM survey_settings);
```

### Phase 3: Update Forms
- Add `getLabel()` helper function
- Replace hardcoded text with `getLabel()` calls
- Test with surveys that have/don't have settings

## Next Steps

1. Finalize which labels to add (priority list)
2. Create migration SQL script
3. Update helper functions in forms
4. Update preview interfaces to edit new labels
5. Test with all survey types

## Example: Priority Labels to Add

**High Priority (Universal):**
- `submit_button_text`
- `facility_label`
- `facility_search_placeholder`

**Medium Priority (Dataset-specific):**
- `period_label`
- `period_load_button`

**Low Priority (Nice-to-have):**
- `search_instruction`
- `facility_change_button`

## Conclusion

**The `survey_settings` table CAN identify survey types** by:
1. Checking the parent `survey.program_type` column
2. Using type-specific defaults when settings are missing
3. Allowing per-survey customization when settings exist

This is the simplest, most pragmatic approach that:
- Reuses existing infrastructure
- Handles missing settings gracefully
- Provides type-specific defaults
- Allows full customization
- Requires minimal code changes
