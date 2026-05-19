-- =============================================================================
-- Complete Dataset Feature Migration
-- Run this script in phpMyAdmin to enable the full dataset preview system
-- =============================================================================

-- Step 1: Add missing columns to dataset_layout_settings table
-- These columns are required for the facility section filters
ALTER TABLE dataset_layout_settings
ADD COLUMN IF NOT EXISTS show_facility_section TINYINT(1) DEFAULT 1 AFTER layout_type,
ADD COLUMN IF NOT EXISTS selected_instance_key VARCHAR(64) DEFAULT NULL AFTER show_facility_section,
ADD COLUMN IF NOT EXISTS selected_hierarchy_level INT DEFAULT NULL AFTER selected_instance_key;

-- Set default values for existing rows
UPDATE dataset_layout_settings
SET show_facility_section = 1
WHERE show_facility_section IS NULL;

-- Step 2: Create the dataset_dataelement_settings table
-- This table stores visibility, ordering, and configuration for data elements
CREATE TABLE IF NOT EXISTS dataset_dataelement_settings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    survey_id BIGINT NOT NULL,
    data_element_id VARCHAR(11) NOT NULL,
    data_element_name VARCHAR(255) NOT NULL,
    data_element_code VARCHAR(50) DEFAULT NULL,
    section_id VARCHAR(11) DEFAULT NULL,
    section_name VARCHAR(255) DEFAULT NULL,
    display_order INT DEFAULT 0,
    is_visible TINYINT(1) DEFAULT 1,
    is_required TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    FOREIGN KEY (survey_id) REFERENCES survey(id) ON DELETE CASCADE,
    UNIQUE KEY unique_survey_element (survey_id, data_element_id),
    INDEX idx_survey_order (survey_id, display_order),
    INDEX idx_section (survey_id, section_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================================================
-- Verification Queries (optional - run these to verify the migration)
-- =============================================================================

-- Check if columns were added successfully
SHOW COLUMNS FROM dataset_layout_settings LIKE 'show_facility_section';
SHOW COLUMNS FROM dataset_layout_settings LIKE 'selected_instance_key';
SHOW COLUMNS FROM dataset_layout_settings LIKE 'selected_hierarchy_level';

-- Check if new table was created
SHOW TABLES LIKE 'dataset_dataelement_settings';

-- View structure of new table
DESCRIBE dataset_dataelement_settings;

-- =============================================================================
-- Success! All database changes have been applied.
-- You can now use the new dataset preview system with:
-- - Data element visibility controls
-- - Drag-and-drop reordering
-- - Section-based organization
-- - Live preview pane
-- =============================================================================
