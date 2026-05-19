-- Add orgunit_last_sync column to survey_settings table
-- This tracks when organization units were last synced from DHIS2

-- Check if column exists before adding
SET @column_exists = (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'survey_settings'
      AND COLUMN_NAME = 'orgunit_last_sync'
);

-- Add column if it doesn't exist
SET @sql = IF(
    @column_exists = 0,
    'ALTER TABLE survey_settings ADD COLUMN orgunit_last_sync DATETIME NULL COMMENT "Timestamp of last org unit sync from DHIS2" AFTER selected_hierarchy_level',
    'SELECT "Column orgunit_last_sync already exists in survey_settings" AS message'
);

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Verify the column was added
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'survey_settings'
  AND COLUMN_NAME = 'orgunit_last_sync';
