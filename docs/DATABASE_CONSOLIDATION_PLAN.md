# Database Consolidation & Cleanup Plan

## Executive Summary

**Goal:** Unify ALL survey/form settings into `survey_settings` table and clean up unused tables.

## Current State Analysis

### Settings Tables (DUPLICATED FUNCTIONALITY!)

| Table | Rows | Columns | Used By | Status |
|-------|------|---------|---------|--------|
| `survey_settings` | 5 | 32 | Regular surveys, some trackers | **KEEP & EXPAND** |
| `dataset_layout_settings` | 2 | 15 | Dataset forms | **MERGE INTO survey_settings** |
| `tracker_layout_settings` | 2 | 15 | Tracker forms | **MERGE INTO survey_settings** |

**Problem:** Three separate settings tables doing the same thing!

### Orphaned Surveys (No settings)

**4 surveys without `survey_settings`:**
- ID 16: Pre-Primary Termly Tool (aggregate)
- ID 20: Primary Termly Tool (aggregate)
- ID 21: V1 Pre-Primary Termly Tool (aggregate)
- ID 22: Teacher Attendance Tracker (tracker)

### Empty/Unused Tables (8 tables with 0 rows)

**Can be safely dropped:**
1. `dataset_dataelement_settings` - Never used
2. `dataset_submissions` - Not being used (data goes to DHIS2 directly?)
3. `default_text` - Translation system not implemented
4. `deletion_log` - Not being used
5. `dhis2_error_log` - Not being used
6. `dhis2_system_field_mapping` - Not being used
7. `stage_questions` - Not being used
8. `survey_stages` - Not being used

## Consolidation Strategy

### Phase 1: Merge Settings Tables

**Add missing columns from `dataset_layout_settings` and `tracker_layout_settings` to `survey_settings`:**

```sql
ALTER TABLE `survey_settings`
-- From dataset_layout_settings (columns not already in survey_settings)
ADD COLUMN `use_direct_orgunits` TINYINT(1) DEFAULT 0 COMMENT 'Use direct org units instead of search',
ADD COLUMN `form_type` VARCHAR(20) DEFAULT NULL COMMENT 'Custom form type override',
ADD COLUMN `cache_dataset_metadata` TINYINT(1) DEFAULT 1 COMMENT 'Cache metadata for performance',

-- Note: These already exist in survey_settings with same purpose:
-- - layout_type -> image_layout_type (rename? or keep both?)
-- - show_facility_section (already exists)
-- - selected_instance_key (already exists)
-- - selected_hierarchy_level (already exists)
-- - show_flag_bar (already exists)
-- - flag_black_color, flag_yellow_color, flag_red_color (already exist)

-- Add timestamps if not present
ADD COLUMN `settings_created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN `settings_updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP;
```

### Phase 2: Add New Text Label Columns

**Add customizable text labels for all form types:**

```sql
ALTER TABLE `survey_settings`
-- Facility/Location Labels (universal)
ADD COLUMN `facility_section_title` VARCHAR(255) DEFAULT NULL COMMENT 'Facility section heading',
ADD COLUMN `facility_search_label` VARCHAR(100) DEFAULT NULL COMMENT 'Search input label',
ADD COLUMN `facility_search_placeholder` VARCHAR(255) DEFAULT NULL COMMENT 'Search placeholder text',
ADD COLUMN `facility_selected_label` VARCHAR(100) DEFAULT NULL COMMENT 'Selected facility label',
ADD COLUMN `facility_change_button` VARCHAR(50) DEFAULT NULL COMMENT 'Change button text',

-- Period Labels (datasets)
ADD COLUMN `period_section_title` VARCHAR(255) DEFAULT NULL COMMENT 'Period section heading',
ADD COLUMN `period_select_label` VARCHAR(100) DEFAULT NULL COMMENT 'Period selector label',
ADD COLUMN `period_load_button` VARCHAR(100) DEFAULT NULL COMMENT 'Load existing data button',

-- Data Entry Labels
ADD COLUMN `data_entry_section_title` VARCHAR(255) DEFAULT NULL COMMENT 'Data entry section heading',

-- Submit Button (universal)
ADD COLUMN `submit_button_text` VARCHAR(100) DEFAULT NULL COMMENT 'Submit button text',
ADD COLUMN `submit_loading_text` VARCHAR(255) DEFAULT NULL COMMENT 'Loading message during submit',

-- Instructions (universal)
ADD COLUMN `search_min_chars_instruction` VARCHAR(255) DEFAULT NULL COMMENT 'Search minimum characters message';
```

### Phase 3: Migrate Existing Data

**Copy data from `dataset_layout_settings` and `tracker_layout_settings` to `survey_settings`:**

```sql
-- Migrate dataset_layout_settings
INSERT INTO survey_settings (
    survey_id,
    show_flag_bar,
    flag_black_color,
    flag_yellow_color,
    flag_red_color,
    selected_instance_key,
    selected_hierarchy_level,
    use_direct_orgunits,
    form_type,
    cache_dataset_metadata,
    settings_created_at,
    settings_updated_at
)
SELECT
    survey_id,
    show_flag_bar,
    flag_black_color,
    flag_yellow_color,
    flag_red_color,
    selected_instance_key,
    selected_hierarchy_level,
    use_direct_orgunits,
    form_type,
    cache_dataset_metadata,
    created_at,
    updated_at
FROM dataset_layout_settings
WHERE survey_id NOT IN (SELECT survey_id FROM survey_settings)
ON DUPLICATE KEY UPDATE
    use_direct_orgunits = VALUES(use_direct_orgunits),
    form_type = VALUES(form_type),
    cache_dataset_metadata = VALUES(cache_dataset_metadata);

-- Migrate tracker_layout_settings
INSERT INTO survey_settings (
    survey_id,
    show_flag_bar,
    flag_black_color,
    flag_yellow_color,
    flag_red_color,
    selected_instance_key,
    selected_hierarchy_level,
    settings_created_at,
    settings_updated_at
)
SELECT
    survey_id,
    show_flag_bar,
    flag_black_color,
    flag_yellow_color,
    flag_red_color,
    selected_instance_key,
    selected_hierarchy_level,
    created_at,
    updated_at
FROM tracker_layout_settings
WHERE survey_id NOT IN (SELECT survey_id FROM survey_settings)
ON DUPLICATE KEY UPDATE
    show_flag_bar = VALUES(show_flag_bar),
    flag_black_color = VALUES(flag_black_color),
    flag_yellow_color = VALUES(flag_yellow_color),
    flag_red_color = VALUES(flag_red_color);
```

### Phase 4: Create Settings for Orphaned Surveys

**Create default survey_settings for surveys that don't have them:**

```sql
INSERT INTO survey_settings (
    survey_id,
    title_text,
    show_logo,
    show_flag_bar,
    show_title,
    show_submit_button
)
SELECT
    id,
    name,
    1, -- show_logo
    1, -- show_flag_bar
    1, -- show_title
    1  -- show_submit_button
FROM survey
WHERE id NOT IN (SELECT survey_id FROM survey_settings);
```

### Phase 5: Drop Old Tables

```sql
-- After verifying migration worked correctly
DROP TABLE IF EXISTS `dataset_layout_settings`;
DROP TABLE IF EXISTS `tracker_layout_settings`;

-- Drop unused/empty tables
DROP TABLE IF EXISTS `dataset_dataelement_settings`;
DROP TABLE IF EXISTS `dataset_submissions`;  -- Verify first!
DROP TABLE IF EXISTS `default_text`;
DROP TABLE IF EXISTS `deletion_log`;
DROP TABLE IF EXISTS `dhis2_error_log`;  -- Consider keeping for debugging
DROP TABLE IF EXISTS `dhis2_system_field_mapping`;
DROP TABLE IF EXISTS `stage_questions`;
DROP TABLE IF EXISTS `survey_stages`;
```

## Code Changes Required

### 1. Update Dataset Forms

**File:** `/fbs/admin/dataset_preview.php`

**Change:** Instead of querying `dataset_layout_settings`, query `survey_settings`

```php
// OLD
$stmt = $pdo->prepare("SELECT * FROM dataset_layout_settings WHERE survey_id = ?");

// NEW
$stmt = $pdo->prepare("SELECT * FROM survey_settings WHERE survey_id = ?");
```

**File:** `/fbs/admin/save_dataset_settings.php`

```php
// OLD
$stmt = $pdo->prepare("
    INSERT INTO dataset_layout_settings (...) VALUES (...)
    ON DUPLICATE KEY UPDATE ...
");

// NEW
$stmt = $pdo->prepare("
    INSERT INTO survey_settings (...) VALUES (...)
    ON DUPLICATE KEY UPDATE ...
");
```

### 2. Update Tracker Forms

**File:** `/fbs/admin/tracker_preview.php`

```php
// OLD
$stmt = $pdo->prepare("SELECT * FROM tracker_layout_settings WHERE survey_id = ?");

// NEW
$stmt = $pdo->prepare("SELECT * FROM survey_settings WHERE survey_id = ?");
```

### 3. Update Public Forms

**Files:** `dataset_form.php`, `tracker_program_form.php`, `survey_page.php`

All use the same unified approach:

```php
// Load survey
$stmt = $pdo->prepare("SELECT * FROM survey WHERE id = ?");
$stmt->execute([$surveyId]);
$survey = $stmt->fetch();

// Load settings (unified table)
$stmt = $pdo->prepare("SELECT * FROM survey_settings WHERE survey_id = ?");
$stmt->execute([$surveyId]);
$settings = $stmt->fetch() ?: [];

// Helper function with type-aware defaults
function getSetting($key, $customDefault = null) {
    global $settings, $survey;

    // 1. Check if custom setting exists
    if (!empty($settings[$key])) {
        return $settings[$key];
    }

    // 2. Use provided default
    if ($customDefault !== null) {
        return $customDefault;
    }

    // 3. Use type-specific default
    $defaults = [
        'aggregate' => [
            'facility_section_title' => 'Facility/Organization Unit Selection',
            'facility_search_placeholder' => 'Type to search for a facility...',
            'submit_button_text' => 'Submit Data',
            'title_text' => $survey['name']
        ],
        'tracker' => [
            'facility_section_title' => 'School Selection',
            'facility_search_placeholder' => 'Type to search for school...',
            'submit_button_text' => 'Submit Entry',
            'title_text' => $survey['name']
        ],
        'local' => [
            'facility_section_title' => 'Locations:',
            'facility_search_placeholder' => 'Type to search...',
            'submit_button_text' => 'Submit',
            'title_text' => $survey['name']
        ]
    ];

    $programType = $survey['program_type'] ?? 'local';
    return $defaults[$programType][$key] ?? '';
}
```

## Complete Migration Script

**File:** `/db/consolidate_survey_settings.sql`

```sql
-- ==========================================================
-- SURVEY SETTINGS CONSOLIDATION MIGRATION
-- Unifies all settings into single survey_settings table
-- ==========================================================

-- STEP 1: Add new columns to survey_settings
ALTER TABLE `survey_settings`
-- Additional dataset/tracker fields
ADD COLUMN IF NOT EXISTS `use_direct_orgunits` TINYINT(1) DEFAULT 0,
ADD COLUMN IF NOT EXISTS `form_type` VARCHAR(20) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `cache_dataset_metadata` TINYINT(1) DEFAULT 1,

-- Timestamps
ADD COLUMN IF NOT EXISTS `settings_created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN IF NOT EXISTS `settings_updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

-- Text labels - Facility/Location
ADD COLUMN IF NOT EXISTS `facility_section_title` VARCHAR(255) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `facility_search_label` VARCHAR(100) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `facility_search_placeholder` VARCHAR(255) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `facility_selected_label` VARCHAR(100) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `facility_change_button` VARCHAR(50) DEFAULT NULL,

-- Text labels - Period
ADD COLUMN IF NOT EXISTS `period_section_title` VARCHAR(255) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `period_select_label` VARCHAR(100) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `period_load_button` VARCHAR(100) DEFAULT NULL,

-- Text labels - Data Entry
ADD COLUMN IF NOT EXISTS `data_entry_section_title` VARCHAR(255) DEFAULT NULL,

-- Text labels - Submit
ADD COLUMN IF NOT EXISTS `submit_button_text` VARCHAR(100) DEFAULT NULL,
ADD COLUMN IF NOT EXISTS `submit_loading_text` VARCHAR(255) DEFAULT NULL,

-- Text labels - Instructions
ADD COLUMN IF NOT EXISTS `search_min_chars_instruction` VARCHAR(255) DEFAULT NULL;

-- STEP 2: Migrate dataset_layout_settings data
INSERT INTO survey_settings (
    survey_id,
    show_flag_bar,
    flag_black_color,
    flag_yellow_color,
    flag_red_color,
    selected_instance_key,
    selected_hierarchy_level,
    use_direct_orgunits,
    form_type,
    cache_dataset_metadata,
    settings_created_at,
    settings_updated_at
)
SELECT
    survey_id,
    show_flag_bar,
    flag_black_color,
    flag_yellow_color,
    flag_red_color,
    selected_instance_key,
    selected_hierarchy_level,
    use_direct_orgunits,
    form_type,
    cache_dataset_metadata,
    created_at,
    updated_at
FROM dataset_layout_settings
WHERE survey_id NOT IN (SELECT survey_id FROM survey_settings)
ON DUPLICATE KEY UPDATE
    use_direct_orgunits = VALUES(use_direct_orgunits),
    form_type = VALUES(form_type),
    cache_dataset_metadata = VALUES(cache_dataset_metadata),
    settings_updated_at = VALUES(settings_updated_at);

-- STEP 3: Migrate tracker_layout_settings data
INSERT INTO survey_settings (
    survey_id,
    show_flag_bar,
    flag_black_color,
    flag_yellow_color,
    flag_red_color,
    selected_instance_key,
    selected_hierarchy_level,
    settings_created_at,
    settings_updated_at
)
SELECT
    survey_id,
    show_flag_bar,
    flag_black_color,
    flag_yellow_color,
    flag_red_color,
    selected_instance_key,
    selected_hierarchy_level,
    created_at,
    updated_at
FROM tracker_layout_settings
WHERE survey_id NOT IN (SELECT survey_id FROM survey_settings)
ON DUPLICATE KEY UPDATE
    show_flag_bar = VALUES(show_flag_bar),
    flag_black_color = VALUES(flag_black_color),
    flag_yellow_color = VALUES(flag_yellow_color),
    flag_red_color = VALUES(flag_red_color),
    selected_instance_key = VALUES(selected_instance_key),
    selected_hierarchy_level = VALUES(selected_hierarchy_level),
    settings_updated_at = VALUES(settings_updated_at);

-- STEP 4: Create settings for orphaned surveys
INSERT INTO survey_settings (
    survey_id,
    title_text,
    show_logo,
    show_flag_bar,
    show_title,
    show_submit_button,
    settings_created_at
)
SELECT
    id,
    name,
    1,
    1,
    1,
    1,
    NOW()
FROM survey
WHERE id NOT IN (SELECT survey_id FROM survey_settings);

-- STEP 5: Verify migration
SELECT
    'Survey Settings Count' as metric,
    COUNT(*) as value
FROM survey_settings

UNION ALL

SELECT
    'Surveys Without Settings' as metric,
    COUNT(*) as value
FROM survey s
LEFT JOIN survey_settings ss ON s.id = ss.survey_id
WHERE ss.id IS NULL;

-- STEP 6: Drop old tables (COMMENTED - uncomment after verification)
-- DROP TABLE IF EXISTS `dataset_layout_settings`;
-- DROP TABLE IF EXISTS `tracker_layout_settings`;

-- STEP 7: Drop unused tables (COMMENTED - uncomment after verification)
-- DROP TABLE IF EXISTS `dataset_dataelement_settings`;
-- DROP TABLE IF EXISTS `default_text`;
-- DROP TABLE IF EXISTS `deletion_log`;
-- DROP TABLE IF EXISTS `dhis2_system_field_mapping`;
-- DROP TABLE IF EXISTS `stage_questions`;
-- DROP TABLE IF EXISTS `survey_stages`;

-- Optional: Keep for debugging/logging
-- DROP TABLE IF EXISTS `dhis2_error_log`;
-- DROP TABLE IF EXISTS `dataset_submissions`;
```

## Testing Checklist

### Before Migration
- [ ] Backup database
- [ ] Document current dataset_layout_settings rows
- [ ] Document current tracker_layout_settings rows
- [ ] Test current forms work

### After Migration
- [ ] Verify all surveys have survey_settings row
- [ ] Test dataset preview loads settings correctly
- [ ] Test tracker preview loads settings correctly
- [ ] Test regular survey preview still works
- [ ] Test dataset form displays correctly
- [ ] Test tracker form displays correctly
- [ ] Test survey form displays correctly
- [ ] Test saving settings from each preview type
- [ ] Verify no broken references to old tables

### Cleanup
- [ ] Verify old tables can be dropped
- [ ] Drop old tables
- [ ] Update documentation
- [ ] Clean up any old code references

## Benefits

### Before (Current State)
- ❌ 3 separate settings tables
- ❌ Duplicate code to handle each
- ❌ Confusing which table to use
- ❌ 4 surveys without settings
- ❌ 8 unused tables cluttering database

### After (Consolidated)
- ✅ 1 unified settings table
- ✅ Single code path for all forms
- ✅ All surveys have settings
- ✅ Clean database
- ✅ Easy to add new settings
- ✅ Type-aware smart defaults
- ✅ Fully customizable per survey

## Timeline

- **Phase 1:** Add columns (30 minutes)
- **Phase 2:** Migrate data (30 minutes)
- **Phase 3:** Update code (2-3 hours)
- **Phase 4:** Testing (2 hours)
- **Phase 5:** Cleanup (30 minutes)

**Total: ~1 day of work**
