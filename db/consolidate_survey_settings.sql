-- ==========================================================
-- SURVEY SETTINGS CONSOLIDATION MIGRATION
-- Unifies all settings into single survey_settings table
-- ==========================================================
-- Created: 2026-01-27
-- Backup: db/backups/fbtv3_before_consolidation_*.sql
-- ==========================================================

-- STEP 1: Add new columns to survey_settings
-- ==========================================================

ALTER TABLE `survey_settings`
-- Additional dataset/tracker fields
ADD COLUMN `use_direct_orgunits` TINYINT(1) DEFAULT 0 COMMENT 'Use direct org units instead of search',
ADD COLUMN `form_type_override` VARCHAR(20) DEFAULT NULL COMMENT 'Custom form type override',
ADD COLUMN `cache_dataset_metadata` TINYINT(1) DEFAULT 1 COMMENT 'Cache metadata for performance',

-- Timestamps
ADD COLUMN `settings_created_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
ADD COLUMN `settings_updated_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

-- Text labels - Facility/Location
ADD COLUMN `facility_section_title` VARCHAR(255) DEFAULT NULL COMMENT 'Facility section heading',
ADD COLUMN `facility_search_label` VARCHAR(100) DEFAULT NULL COMMENT 'Search input label',
ADD COLUMN `facility_search_placeholder` VARCHAR(255) DEFAULT NULL COMMENT 'Search placeholder text',
ADD COLUMN `facility_selected_label` VARCHAR(100) DEFAULT NULL COMMENT 'Selected facility label',
ADD COLUMN `facility_change_button` VARCHAR(50) DEFAULT NULL COMMENT 'Change button text',

-- Text labels - Period
ADD COLUMN `period_section_title` VARCHAR(255) DEFAULT NULL COMMENT 'Period section heading',
ADD COLUMN `period_select_label` VARCHAR(100) DEFAULT NULL COMMENT 'Period selector label',
ADD COLUMN `period_load_button` VARCHAR(100) DEFAULT NULL COMMENT 'Load existing data button',

-- Text labels - Data Entry
ADD COLUMN `data_entry_section_title` VARCHAR(255) DEFAULT NULL COMMENT 'Data entry section heading',

-- Text labels - Submit Button
ADD COLUMN `submit_button_text` VARCHAR(100) DEFAULT NULL COMMENT 'Submit button text',
ADD COLUMN `submit_loading_text` VARCHAR(255) DEFAULT NULL COMMENT 'Loading message during submit',

-- Text labels - Instructions
ADD COLUMN `search_min_chars_instruction` VARCHAR(255) DEFAULT NULL COMMENT 'Search minimum characters message';

-- ==========================================================
-- STEP 2: Migrate dataset_layout_settings data
-- ==========================================================

INSERT INTO survey_settings (
    survey_id,
    show_flag_bar,
    flag_black_color,
    flag_yellow_color,
    flag_red_color,
    selected_instance_key,
    selected_hierarchy_level,
    use_direct_orgunits,
    form_type_override,
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
    form_type_override = VALUES(form_type_override),
    cache_dataset_metadata = VALUES(cache_dataset_metadata),
    settings_updated_at = VALUES(settings_updated_at);

-- ==========================================================
-- STEP 3: Migrate tracker_layout_settings data
-- ==========================================================

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

-- ==========================================================
-- STEP 4: Create settings for orphaned surveys
-- ==========================================================

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
    1,  -- show_logo
    1,  -- show_flag_bar
    1,  -- show_title
    1,  -- show_submit_button
    NOW()
FROM survey
WHERE id NOT IN (SELECT survey_id FROM survey_settings);

-- ==========================================================
-- STEP 5: Verification Queries
-- ==========================================================

SELECT '=== VERIFICATION RESULTS ===' as '';

SELECT
    'Total Survey Settings' as metric,
    COUNT(*) as value
FROM survey_settings;

SELECT
    'Surveys Without Settings' as metric,
    COUNT(*) as value
FROM survey s
LEFT JOIN survey_settings ss ON s.id = ss.survey_id
WHERE ss.id IS NULL;

SELECT
    'Settings by Program Type' as metric,
    s.program_type,
    COUNT(*) as count
FROM survey s
INNER JOIN survey_settings ss ON s.id = ss.survey_id
GROUP BY s.program_type;

-- ==========================================================
-- STEP 6: Drop old tables (COMMENTED - uncomment after verification)
-- ==========================================================

-- Rename first to test
RENAME TABLE dataset_layout_settings TO dataset_layout_settings_OLD;
RENAME TABLE tracker_layout_settings TO tracker_layout_settings_OLD;

-- After testing, uncomment to drop:
-- DROP TABLE IF EXISTS `dataset_layout_settings_OLD`;
-- DROP TABLE IF EXISTS `tracker_layout_settings_OLD`;

-- ==========================================================
-- STEP 7: Drop unused tables (COMMENTED - uncomment after verification)
-- ==========================================================

-- Rename first to test
RENAME TABLE dataset_dataelement_settings TO dataset_dataelement_settings_OLD;
RENAME TABLE default_text TO default_text_OLD;
RENAME TABLE deletion_log TO deletion_log_OLD;
RENAME TABLE dhis2_system_field_mapping TO dhis2_system_field_mapping_OLD;
RENAME TABLE stage_questions TO stage_questions_OLD;
RENAME TABLE survey_stages TO survey_stages_OLD;

-- After testing, uncomment to drop:
-- DROP TABLE IF EXISTS `dataset_dataelement_settings_OLD`;
-- DROP TABLE IF EXISTS `default_text_OLD`;
-- DROP TABLE IF EXISTS `deletion_log_OLD`;
-- DROP TABLE IF EXISTS `dhis2_system_field_mapping_OLD`;
-- DROP TABLE IF EXISTS `stage_questions_OLD`;
-- DROP TABLE IF EXISTS `survey_stages_OLD`;

-- Optional: Keep for debugging
-- RENAME TABLE dhis2_error_log TO dhis2_error_log_OLD;
-- RENAME TABLE dataset_submissions TO dataset_submissions_OLD;

SELECT '=== MIGRATION COMPLETE ===' as '';
SELECT 'Old tables renamed with _OLD suffix. Test your application before dropping.' as 'IMPORTANT';
