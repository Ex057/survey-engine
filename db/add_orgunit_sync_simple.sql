-- ============================================================================
-- Add orgunit_last_sync Column to survey_settings Table
-- ============================================================================
-- Run this SQL in phpMyAdmin or your MySQL client
-- This adds tracking for when organization units were last synced from DHIS2
-- ============================================================================

-- Add the column (will skip if already exists due to IF NOT EXISTS check in procedure)
ALTER TABLE survey_settings
ADD COLUMN IF NOT EXISTS orgunit_last_sync DATETIME NULL
COMMENT 'Timestamp of last org unit sync from DHIS2'
AFTER selected_hierarchy_level;

-- Verify the column was added successfully
SELECT
    'SUCCESS: Column added/verified' AS status,
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'survey_settings'
  AND COLUMN_NAME = 'orgunit_last_sync';
