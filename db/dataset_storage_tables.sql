-- Dataset Storage Tables Migration
-- This adds tables to store dataset data elements and option sets locally
-- Similar to how tracker programs store their data

-- Table to store dataset data elements
CREATE TABLE IF NOT EXISTS dataset_data_elements (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    survey_id INT NOT NULL,
    data_element_uid VARCHAR(11) NOT NULL,
    data_element_name VARCHAR(255) NOT NULL,
    display_name VARCHAR(255),
    form_name VARCHAR(255),
    code VARCHAR(50),
    description TEXT,
    value_type VARCHAR(50) NOT NULL DEFAULT 'TEXT',
    aggregation_type VARCHAR(50),
    zero_is_significant BOOLEAN DEFAULT FALSE,
    option_set_uid VARCHAR(11),
    option_set_name VARCHAR(255),
    category_combo_uid VARCHAR(11),
    category_combo_name VARCHAR(255),
    is_default_category BOOLEAN DEFAULT TRUE,
    section_uid VARCHAR(11),
    section_name VARCHAR(255),
    display_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_survey_id (survey_id),
    INDEX idx_data_element_uid (data_element_uid),
    INDEX idx_section_uid (section_uid),
    INDEX idx_option_set_uid (option_set_uid),
    UNIQUE KEY unique_survey_element (survey_id, data_element_uid),
    FOREIGN KEY (survey_id) REFERENCES survey(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store category option combos for data elements
CREATE TABLE IF NOT EXISTS dataset_category_option_combos (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    survey_id INT NOT NULL,
    data_element_uid VARCHAR(11) NOT NULL,
    coc_uid VARCHAR(11) NOT NULL,
    coc_name VARCHAR(255) NOT NULL,
    coc_display_name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_survey_id (survey_id),
    INDEX idx_data_element_uid (data_element_uid),
    INDEX idx_coc_uid (coc_uid),
    UNIQUE KEY unique_survey_element_coc (survey_id, data_element_uid, coc_uid),
    FOREIGN KEY (survey_id) REFERENCES survey(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store option sets for datasets
CREATE TABLE IF NOT EXISTS dataset_option_sets (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    survey_id INT NOT NULL,
    option_set_uid VARCHAR(11) NOT NULL,
    option_set_name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_survey_id (survey_id),
    INDEX idx_option_set_uid (option_set_uid),
    UNIQUE KEY unique_survey_optionset (survey_id, option_set_uid),
    FOREIGN KEY (survey_id) REFERENCES survey(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store option values for dataset option sets
CREATE TABLE IF NOT EXISTS dataset_option_values (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    option_set_id BIGINT NOT NULL,
    option_code VARCHAR(255) NOT NULL,
    option_display_name VARCHAR(255) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_option_set_id (option_set_id),
    INDEX idx_option_code (option_code),
    FOREIGN KEY (option_set_id) REFERENCES dataset_option_sets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store organization units attached to datasets
CREATE TABLE IF NOT EXISTS dataset_org_units (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    survey_id INT NOT NULL,
    org_unit_uid VARCHAR(11) NOT NULL,
    org_unit_name VARCHAR(255) NOT NULL,
    org_unit_display_name VARCHAR(255),
    parent_uid VARCHAR(11),
    parent_name VARCHAR(255),
    path VARCHAR(1000),
    level INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_survey_id (survey_id),
    INDEX idx_org_unit_uid (org_unit_uid),
    INDEX idx_parent_uid (parent_uid),
    UNIQUE KEY unique_survey_orgunit (survey_id, org_unit_uid),
    FOREIGN KEY (survey_id) REFERENCES survey(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store attribute category combos (dataset-level attributes like "School Term", "Data Set")
CREATE TABLE IF NOT EXISTS dataset_attribute_categories (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    survey_id INT NOT NULL,
    category_combo_uid VARCHAR(11) NOT NULL,
    category_combo_name VARCHAR(255) NOT NULL,
    is_default BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_survey_id (survey_id),
    UNIQUE KEY unique_survey_attribute_combo (survey_id, category_combo_uid),
    FOREIGN KEY (survey_id) REFERENCES survey(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table to store attribute option combos (the actual values like "Term 1", "Term 2", "Pre-Primary")
CREATE TABLE IF NOT EXISTS dataset_attribute_option_combos (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    attribute_category_id BIGINT NOT NULL,
    aoc_uid VARCHAR(11) NOT NULL,
    aoc_name VARCHAR(255) NOT NULL,
    aoc_display_name VARCHAR(255),
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_attribute_category_id (attribute_category_id),
    INDEX idx_aoc_uid (aoc_uid),
    FOREIGN KEY (attribute_category_id) REFERENCES dataset_attribute_categories(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Dataset storage tables created successfully!' AS status;
