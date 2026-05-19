-- =====================================================
-- DHIS2 Aggregate Dataset Tables
-- Created for dataset form functionality
-- =====================================================

-- Table: dataset_submissions
-- Stores all dataset submissions made through the system
CREATE TABLE IF NOT EXISTS `dataset_submissions` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `survey_id` INT NOT NULL,
  `dataset_uid` VARCHAR(11) NOT NULL COMMENT 'DHIS2 dataset UID',
  `orgunit_uid` VARCHAR(11) NOT NULL COMMENT 'DHIS2 organization unit UID',
  `period` VARCHAR(20) NOT NULL COMMENT 'DHIS2 period (e.g., 202401, 2024Q1, etc.)',
  `data_values` JSON NOT NULL COMMENT 'Array of data element values submitted',
  `dhis2_response` JSON DEFAULT NULL COMMENT 'Response from DHIS2 API',
  `uid` VARCHAR(11) NOT NULL COMMENT 'Unique submission identifier',
  `is_update` TINYINT(1) DEFAULT 0 COMMENT '1 if this was an update to existing data',
  `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid_unique` (`uid`),
  KEY `idx_survey_id` (`survey_id`),
  KEY `idx_dataset_uid` (`dataset_uid`),
  KEY `idx_orgunit_period` (`orgunit_uid`, `period`),
  KEY `idx_submitted_at` (`submitted_at`),
  CONSTRAINT `fk_dataset_submissions_survey` FOREIGN KEY (`survey_id`) REFERENCES `survey` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Stores dataset submissions to DHIS2';

-- Table: dataset_layout_settings
-- Stores layout and appearance settings for dataset forms
CREATE TABLE IF NOT EXISTS `dataset_layout_settings` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `survey_id` INT NOT NULL,
  `layout_type` ENUM('horizontal', 'vertical') DEFAULT 'horizontal' COMMENT 'Image layout type',
  `show_flag_bar` TINYINT(1) DEFAULT 1 COMMENT 'Show Uganda flag bar',
  `flag_black_color` VARCHAR(7) DEFAULT '#000000',
  `flag_yellow_color` VARCHAR(7) DEFAULT '#FCD116',
  `flag_red_color` VARCHAR(7) DEFAULT '#D21034',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `survey_id_unique` (`survey_id`),
  CONSTRAINT `fk_dataset_layout_survey` FOREIGN KEY (`survey_id`) REFERENCES `survey` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Layout settings for dataset forms';

-- Table: dataset_images
-- Stores images to display on dataset forms and share pages
CREATE TABLE IF NOT EXISTS `dataset_images` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `survey_id` INT NOT NULL,
  `image_order` INT NOT NULL DEFAULT 0 COMMENT 'Display order of image',
  `image_path` VARCHAR(500) NOT NULL COMMENT 'Path or URL to image',
  `image_alt_text` VARCHAR(255) DEFAULT NULL COMMENT 'Alt text for accessibility',
  `width_px` INT DEFAULT NULL COMMENT 'Width in pixels',
  `height_px` INT DEFAULT NULL COMMENT 'Height in pixels',
  `position_type` ENUM('top', 'center', 'bottom') DEFAULT 'center',
  `is_active` TINYINT(1) DEFAULT 1 COMMENT '1 = active, 0 = inactive',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_survey_id` (`survey_id`),
  KEY `idx_order` (`image_order`),
  CONSTRAINT `fk_dataset_images_survey` FOREIGN KEY (`survey_id`) REFERENCES `survey` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Images for dataset forms';

-- Table: dataset_data_element_mapping
-- Maps DHIS2 data elements to local question/field configurations
CREATE TABLE IF NOT EXISTS `dataset_data_element_mapping` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `survey_id` INT NOT NULL,
  `dataset_uid` VARCHAR(11) NOT NULL,
  `data_element_uid` VARCHAR(11) NOT NULL COMMENT 'DHIS2 data element UID',
  `data_element_name` VARCHAR(255) NOT NULL,
  `custom_label` VARCHAR(255) DEFAULT NULL COMMENT 'Custom label to display (optional)',
  `custom_description` TEXT DEFAULT NULL COMMENT 'Custom description (optional)',
  `is_required` TINYINT(1) DEFAULT 0 COMMENT 'Make this field required',
  `display_order` INT DEFAULT 0 COMMENT 'Custom display order',
  `is_hidden` TINYINT(1) DEFAULT 0 COMMENT '1 = hide this field from form',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `survey_data_element_unique` (`survey_id`, `data_element_uid`),
  KEY `idx_dataset_uid` (`dataset_uid`),
  CONSTRAINT `fk_de_mapping_survey` FOREIGN KEY (`survey_id`) REFERENCES `survey` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Data element customization for dataset forms';

-- Table: dataset_submission_log
-- Detailed log of all submission attempts (success and failures)
CREATE TABLE IF NOT EXISTS `dataset_submission_log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `submission_id` INT DEFAULT NULL COMMENT 'Reference to dataset_submissions if successful',
  `survey_id` INT NOT NULL,
  `dataset_uid` VARCHAR(11) NOT NULL,
  `orgunit_uid` VARCHAR(11) NOT NULL,
  `period` VARCHAR(20) NOT NULL,
  `status` ENUM('success', 'failed', 'pending', 'retry') NOT NULL,
  `request_payload` JSON NOT NULL COMMENT 'Data sent to DHIS2',
  `response_payload` JSON DEFAULT NULL COMMENT 'Response from DHIS2',
  `error_message` TEXT DEFAULT NULL,
  `retry_count` INT DEFAULT 0,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_submission_id` (`submission_id`),
  KEY `idx_survey_id` (`survey_id`),
  KEY `idx_status` (`status`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_log_submission` FOREIGN KEY (`submission_id`) REFERENCES `dataset_submissions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_log_survey` FOREIGN KEY (`survey_id`) REFERENCES `survey` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci COMMENT='Log of all dataset submission attempts';

-- =====================================================
-- Indexes for Performance
-- =====================================================

-- Add composite indexes for common queries
CREATE INDEX `idx_dataset_orgunit_period` ON `dataset_submissions` (`dataset_uid`, `orgunit_uid`, `period`);
CREATE INDEX `idx_survey_period` ON `dataset_submissions` (`survey_id`, `period`);

-- =====================================================
-- Sample Data (Optional - for testing)
-- =====================================================

-- Insert default layout settings for existing aggregate surveys
-- INSERT INTO dataset_layout_settings (survey_id, layout_type, show_flag_bar)
-- SELECT id, 'horizontal', 1
-- FROM survey
-- WHERE program_type = 'aggregate'
-- AND NOT EXISTS (
--     SELECT 1 FROM dataset_layout_settings WHERE survey_id = survey.id
-- );

-- =====================================================
-- Views for Reporting (Optional)
-- =====================================================

-- View: dataset_submission_summary
-- Provides summary of submissions by survey, period, and status
CREATE OR REPLACE VIEW `dataset_submission_summary` AS
SELECT
    s.id AS survey_id,
    s.name AS survey_name,
    ds.dataset_uid,
    ds.period,
    ds.orgunit_uid,
    COUNT(ds.id) AS total_submissions,
    SUM(ds.is_update) AS total_updates,
    MIN(ds.submitted_at) AS first_submission,
    MAX(ds.submitted_at) AS last_submission
FROM
    survey s
    JOIN dataset_submissions ds ON s.id = ds.survey_id
WHERE
    s.program_type = 'aggregate'
GROUP BY
    s.id, ds.dataset_uid, ds.period, ds.orgunit_uid;

-- View: recent_dataset_submissions
-- Shows recent dataset submissions for dashboard display
CREATE OR REPLACE VIEW `recent_dataset_submissions` AS
SELECT
    ds.id,
    ds.uid,
    s.name AS survey_name,
    ds.dataset_uid,
    ds.period,
    ds.orgunit_uid,
    ds.is_update,
    ds.submitted_at,
    JSON_LENGTH(ds.data_values) AS value_count
FROM
    dataset_submissions ds
    JOIN survey s ON ds.survey_id = s.id
ORDER BY
    ds.submitted_at DESC
LIMIT 50;

-- =====================================================
-- Triggers
-- =====================================================

-- Trigger: Auto-create submission log on insert
DELIMITER //
CREATE TRIGGER `after_dataset_submission_insert`
AFTER INSERT ON `dataset_submissions`
FOR EACH ROW
BEGIN
    INSERT INTO dataset_submission_log (
        submission_id,
        survey_id,
        dataset_uid,
        orgunit_uid,
        period,
        status,
        request_payload,
        response_payload,
        error_message
    ) VALUES (
        NEW.id,
        NEW.survey_id,
        NEW.dataset_uid,
        NEW.orgunit_uid,
        NEW.period,
        'success',
        NEW.data_values,
        NEW.dhis2_response,
        NULL
    );
END//
DELIMITER ;

-- =====================================================
-- Permissions (Optional - adjust as needed)
-- =====================================================

-- Grant permissions to application user
-- GRANT SELECT, INSERT, UPDATE, DELETE ON dataset_submissions TO 'your_app_user'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON dataset_layout_settings TO 'your_app_user'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON dataset_images TO 'your_app_user'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON dataset_data_element_mapping TO 'your_app_user'@'localhost';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON dataset_submission_log TO 'your_app_user'@'localhost';
-- GRANT SELECT ON dataset_submission_summary TO 'your_app_user'@'localhost';
-- GRANT SELECT ON recent_dataset_submissions TO 'your_app_user'@'localhost';

-- =====================================================
-- Notes
-- =====================================================
-- 1. Make sure to run this after the main database schema is created
-- 2. The survey table must exist with program_type ENUM including 'aggregate'
-- 3. Adjust character sets and collations based on your database configuration
-- 4. Consider adding additional indexes based on your query patterns
-- 5. Review and adjust foreign key constraints based on your cascade preferences
