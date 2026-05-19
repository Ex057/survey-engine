-- =============================================================================
-- Dataset Native Form Implementation - Database Migration
-- Run this to add cache table and update settings columns
-- =============================================================================

-- Step 1: Create dataset metadata cache table
CREATE TABLE IF NOT EXISTS dataset_metadata_cache (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    dataset_uid VARCHAR(11) NOT NULL,
    instance_key VARCHAR(64) NOT NULL,
    metadata JSON NOT NULL,
    cached_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMP NOT NULL,
    UNIQUE KEY unique_dataset_cache (dataset_uid, instance_key),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Step 2: Add columns to dataset_layout_settings (if they don't exist)
-- Note: Run these one at a time, ignore errors if column already exists

ALTER TABLE dataset_layout_settings
ADD COLUMN use_direct_orgunits TINYINT(1) DEFAULT 1 AFTER selected_hierarchy_level;

ALTER TABLE dataset_layout_settings
ADD COLUMN form_type VARCHAR(20) DEFAULT 'DEFAULT' AFTER use_direct_orgunits;

ALTER TABLE dataset_layout_settings
ADD COLUMN cache_dataset_metadata TINYINT(1) DEFAULT 1 AFTER form_type;

-- Step 3: Set defaults for existing rows
UPDATE dataset_layout_settings
SET use_direct_orgunits = 1
WHERE use_direct_orgunits IS NULL;

UPDATE dataset_layout_settings
SET cache_dataset_metadata = 1
WHERE cache_dataset_metadata IS NULL;

-- =============================================================================
-- Verification
-- =============================================================================

SELECT '✅ Checking dataset_metadata_cache table...' AS status;
DESCRIBE dataset_metadata_cache;

SELECT '✅ Checking dataset_layout_settings columns...' AS status;
SHOW COLUMNS FROM dataset_layout_settings
WHERE Field IN ('use_direct_orgunits', 'form_type', 'cache_dataset_metadata');

SELECT '✅ Migration complete!' AS status;
