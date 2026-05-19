# Dynamic Text Labels - SIMPLIFIED PLAN (Using Existing survey_settings)

## Analysis: Why Use Existing `survey_settings` Table?

You're absolutely correct! The `survey_settings` table already has:

### Existing Customizable Text Fields:
- `title_text` - Form title
- `subheading_text` - Form description/instructions
- `rating_instruction1_text` - Rating instructions line 1
- `rating_instruction2_text` - Rating instructions line 2
- `republic_title_text` - For share pages
- `ministry_subtitle_text` - For share pages
- `qr_instructions_text` - QR code instructions
- `footer_note_text` - Footer text

### Why This is Better:
✅ **Already exists** - No new table needed
✅ **Already has UI** - `preview_form.php` already edits these
✅ **Already working** - System already loads and uses these
✅ **One record per survey** - Simple 1:1 relationship
✅ **Easy to extend** - Just add more columns

## SIMPLIFIED SOLUTION: Extend `survey_settings` Table

Instead of a new complex table with rows for each label, we simply **add more columns** to `survey_settings` for the labels we need.

### Step 1: Add New Columns to `survey_settings`

```sql
ALTER TABLE `survey_settings`
-- Facility/Location Labels
ADD COLUMN `facility_section_title` VARCHAR(255) DEFAULT 'Facility/Organization Unit Selection' AFTER `show_dynamic_images`,
ADD COLUMN `facility_search_label` VARCHAR(100) DEFAULT 'Search Facility' AFTER `facility_section_title`,
ADD COLUMN `facility_search_placeholder` VARCHAR(255) DEFAULT 'Type to search for a facility...' AFTER `facility_search_label`,
ADD COLUMN `facility_selected_label` VARCHAR(100) DEFAULT 'Selected Facility:' AFTER `facility_search_placeholder`,
ADD COLUMN `facility_change_button` VARCHAR(50) DEFAULT 'Change' AFTER `facility_selected_label`,

-- Period Labels (for datasets)
ADD COLUMN `period_section_title` VARCHAR(255) DEFAULT 'Reporting Period' AFTER `facility_change_button`,
ADD COLUMN `period_select_label` VARCHAR(100) DEFAULT 'Select Period' AFTER `period_section_title`,
ADD COLUMN `period_load_existing_button` VARCHAR(100) DEFAULT 'Load Existing Data' AFTER `period_select_label`,

-- Data Entry Labels
ADD COLUMN `data_entry_section_title` VARCHAR(255) DEFAULT 'Data Entry' AFTER `period_load_existing_button`,

-- Submit Button
ADD COLUMN `submit_button_text` VARCHAR(100) DEFAULT 'Submit Data' AFTER `data_entry_section_title`,
ADD COLUMN `submit_loading_text` VARCHAR(255) DEFAULT 'Submitting data...' AFTER `submit_button_text`,

-- Location Labels (for surveys)
ADD COLUMN `location_label` VARCHAR(100) DEFAULT 'Locations:' AFTER `submit_loading_text`,
ADD COLUMN `location_search_placeholder` VARCHAR(255) DEFAULT 'Type to search...' AFTER `location_label`;
```

### Step 2: Update Forms to Use These Fields

**In `dataset_form.php`:**

```php
<?php
// Fetch survey settings (already being fetched in most forms)
$stmt = $pdo->prepare("SELECT * FROM survey_settings WHERE survey_id = ?");
$stmt->execute([$surveyId]);
$settings = $stmt->fetch(PDO::FETCH_ASSOC);

// Helper function
function getSetting($key, $default = '') {
    global $settings;
    return $settings[$key] ?? $default;
}
?>

<!-- Use in HTML -->
<div class="section-title">
    <?= getSetting('facility_section_title', 'Facility/Organization Unit Selection') ?>
</div>

<label><?= getSetting('facility_search_label', 'Search Facility') ?></label>

<input type="text"
       placeholder="<?= getSetting('facility_search_placeholder', 'Type to search for a facility...') ?>">

<button><?= getSetting('facility_change_button', 'Change') ?></button>

<div class="section-title">
    <?= getSetting('period_section_title', 'Reporting Period') ?>
</div>

<button><?= getSetting('submit_button_text', 'Submit Data') ?></button>
```

**In `survey_page.php`:**

```php
<label><?= getSetting('location_label', 'Locations:') ?></label>

<input placeholder="<?= getSetting('location_search_placeholder', 'Type to search...') ?>">

<button><?= getSetting('submit_button_text', 'Submit') ?></button>
```

### Step 3: Update Preview Interfaces

**In `dataset_preview.php`** - Add to existing tabs:

Under the **"Layout"** tab or create new **"Text Labels"** tab:

```html
<div class="form-group">
    <label>Facility Section Title</label>
    <input type="text"
           id="facility_section_title"
           class="form-control"
           value="<?= $settings['facility_section_title'] ?? 'Facility/Organization Unit Selection' ?>">
</div>

<div class="form-group">
    <label>Search Label</label>
    <input type="text"
           id="facility_search_label"
           class="form-control"
           value="<?= $settings['facility_search_label'] ?? 'Search Facility' ?>">
</div>

<div class="form-group">
    <label>Search Placeholder</label>
    <input type="text"
           id="facility_search_placeholder"
           class="form-control"
           value="<?= $settings['facility_search_placeholder'] ?? 'Type to search for a facility...' ?>">
</div>

<!-- Add more fields... -->
```

**JavaScript to save (add to existing save function):**

```javascript
function saveAllSettings() {
    const settingsData = {
        survey_id: surveyId,

        // Existing fields...
        show_flag_bar: $('#show_flag_bar').is(':checked') ? 1 : 0,
        flag_black_color: $('#flag_black_color').val(),
        // ... etc

        // NEW: Text label fields
        facility_section_title: $('#facility_section_title').val(),
        facility_search_label: $('#facility_search_label').val(),
        facility_search_placeholder: $('#facility_search_placeholder').val(),
        facility_selected_label: $('#facility_selected_label').val(),
        facility_change_button: $('#facility_change_button').val(),
        period_section_title: $('#period_section_title').val(),
        period_select_label: $('#period_select_label').val(),
        period_load_existing_button: $('#period_load_existing_button').val(),
        data_entry_section_title: $('#data_entry_section_title').val(),
        submit_button_text: $('#submit_button_text').val(),
        submit_loading_text: $('#submit_loading_text').val()
    };

    // Existing AJAX call to save_dataset_settings.php
    $.ajax({
        url: 'save_dataset_settings.php',
        method: 'POST',
        data: settingsData,
        // ...
    });
}
```

**In `preview_form.php`** - Add to existing accordions:

Already has many text customization fields! Just add the new ones we need:

```html
<div class="accordion-item">
    <h2 class="accordion-header">
        <button class="accordion-button collapsed" data-bs-target="#textLabelsCollapse">
            Text Labels
        </button>
    </h2>
    <div id="textLabelsCollapse" class="accordion-collapse collapse">
        <div class="accordion-body">
            <div class="mb-3">
                <label>Location Label</label>
                <input type="text"
                       id="location_label"
                       class="form-control"
                       value="<?= $settings['location_label'] ?? 'Locations:' ?>">
            </div>

            <div class="mb-3">
                <label>Submit Button Text</label>
                <input type="text"
                       id="submit_button_text"
                       class="form-control"
                       value="<?= $settings['submit_button_text'] ?? 'Submit' ?>">
            </div>
        </div>
    </div>
</div>
```

### Step 4: Update Backend Save Handlers

**In `save_dataset_settings.php`** (already exists):

```php
<?php
// This file already saves to survey_settings
// Just add the new fields to the UPDATE query

$stmt = $pdo->prepare("
    UPDATE survey_settings SET
        show_flag_bar = ?,
        flag_black_color = ?,
        -- ... existing fields ...

        -- NEW: Text label fields
        facility_section_title = ?,
        facility_search_label = ?,
        facility_search_placeholder = ?,
        facility_selected_label = ?,
        facility_change_button = ?,
        period_section_title = ?,
        period_select_label = ?,
        period_load_existing_button = ?,
        data_entry_section_title = ?,
        submit_button_text = ?,
        submit_loading_text = ?

    WHERE survey_id = ?
");

$stmt->execute([
    $_POST['show_flag_bar'],
    $_POST['flag_black_color'],
    // ... existing params ...

    // NEW params
    $_POST['facility_section_title'],
    $_POST['facility_search_label'],
    $_POST['facility_search_placeholder'],
    $_POST['facility_selected_label'],
    $_POST['facility_change_button'],
    $_POST['period_section_title'],
    $_POST['period_select_label'],
    $_POST['period_load_existing_button'],
    $_POST['data_entry_section_title'],
    $_POST['submit_button_text'],
    $_POST['submit_loading_text'],

    $surveyId
]);
```

## Migration Script

```sql
-- Add new columns to survey_settings
ALTER TABLE `survey_settings`
-- Facility/Location Labels
ADD COLUMN `facility_section_title` VARCHAR(255) DEFAULT 'Facility/Organization Unit Selection',
ADD COLUMN `facility_search_label` VARCHAR(100) DEFAULT 'Search Facility',
ADD COLUMN `facility_search_placeholder` VARCHAR(255) DEFAULT 'Type to search for a facility...',
ADD COLUMN `facility_selected_label` VARCHAR(100) DEFAULT 'Selected Facility:',
ADD COLUMN `facility_change_button` VARCHAR(50) DEFAULT 'Change',

-- Period Labels (for datasets)
ADD COLUMN `period_section_title` VARCHAR(255) DEFAULT 'Reporting Period',
ADD COLUMN `period_select_label` VARCHAR(100) DEFAULT 'Select Period',
ADD COLUMN `period_load_existing_button` VARCHAR(100) DEFAULT 'Load Existing Data',

-- Data Entry Labels
ADD COLUMN `data_entry_section_title` VARCHAR(255) DEFAULT 'Data Entry',

-- Submit Button
ADD COLUMN `submit_button_text` VARCHAR(100) DEFAULT 'Submit Data',
ADD COLUMN `submit_loading_text` VARCHAR(255) DEFAULT 'Submitting data...',

-- Location Labels (for surveys)
ADD COLUMN `location_label` VARCHAR(100) DEFAULT 'Locations:',
ADD COLUMN `location_search_placeholder` VARCHAR(255) DEFAULT 'Type to search...';
```

## Comparison: New Table vs Extending survey_settings

| Aspect | New `form_text_labels` Table | Extend `survey_settings` |
|--------|------------------------------|-------------------------|
| **Complexity** | High - need new manager class, APIs | Low - use existing patterns |
| **Code Changes** | Major - new architecture | Minor - add columns, update forms |
| **Database** | New table + relationships | Just add columns |
| **Migration** | Complex | Simple ALTER TABLE |
| **UI Changes** | Build new label editor | Add fields to existing UI |
| **Backwards Compat** | Need fallback logic | Automatic (DEFAULT values) |
| **Flexibility** | Very flexible (unlimited labels) | Limited (one column per label) |
| **Performance** | Multiple rows to fetch | Single row (already fetched) |
| **Maintenance** | New code to maintain | Existing code continues working |
| **Development Time** | 4-5 weeks | 1-2 days |

## Recommendation: Use `survey_settings` Approach

**Why?**
1. ✅ **Already exists and working**
2. ✅ **10x simpler to implement**
3. ✅ **Follows existing pattern** (title_text, subheading_text, etc.)
4. ✅ **No new architecture needed**
5. ✅ **1-2 days vs 4-5 weeks**
6. ✅ **Lower risk** - small incremental changes
7. ✅ **Easy to test** - modify existing preview, existing forms
8. ✅ **Backwards compatible** - DEFAULT values handle missing data

**Trade-offs:**
- ❌ Less flexible (can't add unlimited labels without ALTER TABLE)
- ❌ Less suitable for multi-language (would need more columns)
- ❌ Wider table (more columns)

**But for your use case:**
- You need ~10-15 customizable labels
- Single language (or limited languages)
- Already using this pattern successfully
- Need quick implementation

## Implementation Steps (Simplified)

### Phase 1: Database (30 minutes)
1. Create migration SQL file
2. Run ALTER TABLE to add new columns
3. Test that defaults work

### Phase 2: Dataset Forms (4 hours)
1. Update `dataset_preview.php` - add text label fields to UI
2. Update `save_dataset_settings.php` - add new fields to UPDATE query
3. Update `dataset_form.php` - fetch settings, use getSetting() helper
4. Replace hardcoded text with getSetting() calls
5. Test end-to-end

### Phase 3: Regular Surveys (3 hours)
1. Update `preview_form.php` - add fields to accordion
2. Update save handler for survey settings
3. Update `survey_page.php` - use getSetting()
4. Replace hardcoded text
5. Test end-to-end

### Phase 4: Tracker Forms (3 hours)
1. Update `tracker_preview.php` - add fields
2. Update `tracker_program_form.php` - use getSetting()
3. Replace hardcoded text
4. Test end-to-end

**Total Time: ~1-2 days** instead of 4-5 weeks!

## Example Usage in Forms

### Before (Hardcoded):
```php
<label>Search Facility</label>
<input placeholder="Type to search for a facility...">
<button>Submit Data</button>
```

### After (Dynamic):
```php
<label><?= getSetting('facility_search_label', 'Search Facility') ?></label>
<input placeholder="<?= getSetting('facility_search_placeholder', 'Type to search for a facility...') ?>">
<button><?= getSetting('submit_button_text', 'Submit Data') ?></button>
```

## Specific Columns to Add

Based on common hardcoded text across all forms:

```sql
-- Facility/Location (12 columns)
facility_section_title VARCHAR(255) DEFAULT 'Facility/Organization Unit Selection'
facility_search_label VARCHAR(100) DEFAULT 'Search Facility'
facility_search_placeholder VARCHAR(255) DEFAULT 'Type to search for a facility...'
facility_select_label VARCHAR(100) DEFAULT 'Select Your Facility'
facility_selected_label VARCHAR(100) DEFAULT 'Selected Facility:'
facility_change_button VARCHAR(50) DEFAULT 'Change'
location_label VARCHAR(100) DEFAULT 'Locations:' -- for surveys
location_search_placeholder VARCHAR(255) DEFAULT 'Type to search...'

-- Period (3 columns)
period_section_title VARCHAR(255) DEFAULT 'Reporting Period'
period_select_label VARCHAR(100) DEFAULT 'Select Period'
period_load_existing_button VARCHAR(100) DEFAULT 'Load Existing Data'

-- Data Entry (1 column)
data_entry_section_title VARCHAR(255) DEFAULT 'Data Entry'

-- Submit (2 columns)
submit_button_text VARCHAR(100) DEFAULT 'Submit Data'
submit_loading_text VARCHAR(255) DEFAULT 'Submitting data...'

-- Instructions (1 column)
search_min_chars_instruction VARCHAR(255) DEFAULT 'Type at least 2 characters to search'
```

**Total: ~15 new columns** (very manageable!)

## Conclusion

**REVISED RECOMMENDATION:** Use the simple approach of extending `survey_settings` table.

This is:
- 10x faster to implement
- 10x less code to write
- Uses existing, proven patterns
- Minimal risk
- Easy to test
- Backwards compatible
- Meets all requirements

The complex `form_text_labels` table approach would only be needed if:
- You need 100+ customizable labels
- You need full multi-language support with translations
- You need label versioning/history
- You need to share labels across surveys

For now, **extend `survey_settings`** and you can always migrate to a more complex system later if needed.
