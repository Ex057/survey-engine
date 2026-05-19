<?php
/**
 * Dataset Storage Service
 *
 * Stores dataset data elements, option sets, and org units in local database
 * Similar to how tracker programs store their metadata
 */

require_once __DIR__ . '/../connect.php';
require_once __DIR__ . '/dhis2_shared.php';
require_once __DIR__ . '/dataset_api_service.php';

class DatasetStorageService {

    /**
     * Store complete dataset metadata in database
     *
     * @param int $surveyId Survey ID
     * @param string $datasetUid Dataset UID
     * @param string $instanceKey DHIS2 instance key
     * @return array Result with success status and message
     */
    public static function storeDatasetMetadata($surveyId, $datasetUid, $instanceKey) {
        global $pdo;

        try {
            error_log("[DATASET STORAGE] Starting metadata storage for survey {$surveyId}, dataset {$datasetUid}");

            // Fetch complete dataset metadata from DHIS2
            $dataset = DatasetApiService::getDatasetComplete($datasetUid, $instanceKey, false);

            if (!$dataset) {
                return ['success' => false, 'message' => 'Failed to fetch dataset from DHIS2'];
            }

            // Start transaction
            $pdo->beginTransaction();

            // Clear existing data for this survey
            self::clearDatasetData($surveyId);

            // Store basic dataset metadata (name, periodType, formType, etc.)
            self::storeBasicDatasetInfo($surveyId, $datasetUid, $dataset);

            // Store option sets first (referenced by data elements)
            $optionSetMap = self::storeOptionSets($surveyId, $dataset);

            // Store attribute category combos (dataset-level attributes like "School Term")
            $attributesStored = self::storeAttributeCategoryCombos($surveyId, $dataset);

            // Store data elements
            $dataElementsStored = self::storeDataElements($surveyId, $dataset, $optionSetMap);

            // Store organization units
            $orgUnitsStored = self::storeOrgUnits($surveyId, $datasetUid, $instanceKey);

            // Commit transaction
            $pdo->commit();

            error_log("[DATASET STORAGE] Successfully stored metadata: {$dataElementsStored} data elements, " .
                      count($optionSetMap) . " option sets, {$attributesStored} attributes, {$orgUnitsStored} org units");

            return [
                'success' => true,
                'message' => "Stored {$dataElementsStored} data elements, " . count($optionSetMap) . " option sets, {$attributesStored} attributes, {$orgUnitsStored} org units",
                'data_elements' => $dataElementsStored,
                'option_sets' => count($optionSetMap),
                'attributes' => $attributesStored,
                'org_units' => $orgUnitsStored
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("[DATASET STORAGE] Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Error storing dataset metadata: ' . $e->getMessage()];
        }
    }

    /**
     * Clear existing dataset data for a survey
     */
    private static function clearDatasetData($surveyId) {
        global $pdo;

        // Delete in correct order due to foreign keys
        $tables = [
            'dataset_option_values' => 'option_set_id IN (SELECT id FROM dataset_option_sets WHERE survey_id = ?)',
            'dataset_attribute_option_combos' => 'attribute_category_id IN (SELECT id FROM dataset_attribute_categories WHERE survey_id = ?)',
            'dataset_category_option_combos' => 'survey_id = ?',
            'dataset_data_elements' => 'survey_id = ?',
            'dataset_option_sets' => 'survey_id = ?',
            'dataset_attribute_categories' => 'survey_id = ?',
            'dataset_org_units' => 'survey_id = ?',
            'dataset_metadata' => 'survey_id = ?'
        ];

        foreach ($tables as $table => $condition) {
            try {
                $stmt = $pdo->prepare("DELETE FROM {$table} WHERE {$condition}");
                $stmt->execute([$surveyId]);
            } catch (PDOException $e) {
                // Table might not exist yet, log but continue
                error_log("[DATASET STORAGE] Warning: Could not clear {$table}: " . $e->getMessage());
            }
        }
    }

    /**
     * Store basic dataset information (name, periodType, formType, custom form HTML, etc.)
     */
    private static function storeBasicDatasetInfo($surveyId, $datasetUid, $dataset) {
        global $pdo;

        try {
            // Extract custom form HTML and style if available
            $customFormHtml = null;
            $customFormStyle = null;
            if (isset($dataset['dataEntryForm']) && !empty($dataset['dataEntryForm'])) {
                $customFormHtml = $dataset['dataEntryForm']['htmlCode'] ?? null;
                $customFormStyle = $dataset['dataEntryForm']['style'] ?? null;
            }

            $stmt = $pdo->prepare("
                INSERT INTO dataset_metadata (
                    survey_id, dataset_uid, dataset_name, dataset_display_name,
                    dataset_description, period_type, form_type,
                    custom_form_html, custom_form_style,
                    timely_days, expiry_days, open_future_periods
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    dataset_uid = VALUES(dataset_uid),
                    dataset_name = VALUES(dataset_name),
                    dataset_display_name = VALUES(dataset_display_name),
                    dataset_description = VALUES(dataset_description),
                    period_type = VALUES(period_type),
                    form_type = VALUES(form_type),
                    custom_form_html = VALUES(custom_form_html),
                    custom_form_style = VALUES(custom_form_style),
                    timely_days = VALUES(timely_days),
                    expiry_days = VALUES(expiry_days),
                    open_future_periods = VALUES(open_future_periods),
                    synced_at = CURRENT_TIMESTAMP
            ");

            $stmt->execute([
                $surveyId,
                $datasetUid,
                $dataset['name'] ?? 'Unnamed Dataset',
                $dataset['displayName'] ?? $dataset['name'] ?? 'Unnamed Dataset',
                $dataset['description'] ?? '',
                $dataset['periodType'] ?? 'Monthly',
                $dataset['formType'] ?? 'DEFAULT',
                $customFormHtml,
                $customFormStyle,
                $dataset['timelyDays'] ?? null,
                $dataset['expiryDays'] ?? null,
                $dataset['openFuturePeriods'] ?? null
            ]);

            error_log("[DATASET STORAGE] Stored basic dataset info for survey {$surveyId}");
        } catch (PDOException $e) {
            error_log("[DATASET STORAGE] Error storing basic dataset info: " . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Store option sets from dataset
     *
     * @return array Map of optionSetUid => local option_set_id
     */
    private static function storeOptionSets($surveyId, $dataset) {
        global $pdo;

        $optionSetMap = [];
        $optionSetsToStore = [];

        // Collect all unique option sets from data elements
        $dataElements = [];

        // From sections
        if (isset($dataset['sections'])) {
            foreach ($dataset['sections'] as $section) {
                if (isset($section['dataElements'])) {
                    $dataElements = array_merge($dataElements, $section['dataElements']);
                }
            }
        }

        // From dataSetElements
        if (isset($dataset['dataSetElements'])) {
            foreach ($dataset['dataSetElements'] as $dse) {
                if (isset($dse['dataElement'])) {
                    $dataElements[] = $dse['dataElement'];
                }
            }
        }

        // Extract unique option sets
        foreach ($dataElements as $de) {
            if (isset($de['optionSet']) && !empty($de['optionSet']['id'])) {
                $optionSetsToStore[$de['optionSet']['id']] = $de['optionSet'];
            }
        }

        // Store each option set
        foreach ($optionSetsToStore as $optionSetUid => $optionSet) {
            $optionSetName = $optionSet['name'] ?? $optionSet['displayName'] ?? 'Unnamed Option Set';

            // Insert option set
            $stmt = $pdo->prepare("
                INSERT INTO dataset_option_sets (survey_id, option_set_uid, option_set_name)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$surveyId, $optionSetUid, $optionSetName]);
            $optionSetId = $pdo->lastInsertId();
            $optionSetMap[$optionSetUid] = $optionSetId;

            // Store option values
            if (isset($optionSet['options']) && is_array($optionSet['options'])) {
                $stmt = $pdo->prepare("
                    INSERT INTO dataset_option_values (option_set_id, option_code, option_display_name, sort_order)
                    VALUES (?, ?, ?, ?)
                ");

                foreach ($optionSet['options'] as $index => $option) {
                    $code = $option['code'] ?? $option['id'] ?? '';
                    $displayName = $option['displayName'] ?? $option['name'] ?? $code;
                    $sortOrder = $option['sortOrder'] ?? $index;
                    $stmt->execute([$optionSetId, $code, $displayName, $sortOrder]);
                }
            }
        }

        return $optionSetMap;
    }

    /**
     * Store attribute category combos (dataset-level attributes like "School Term", "Data Set")
     *
     * @return int Number of attribute category combos stored
     */
    private static function storeAttributeCategoryCombos($surveyId, $dataset) {
        global $pdo;

        if (!isset($dataset['categoryCombo']) || empty($dataset['categoryCombo']['id'])) {
            return 0; // No attribute category combo
        }

        $categoryCombo = $dataset['categoryCombo'];
        $ccUid = $categoryCombo['id'];
        $ccName = $categoryCombo['displayName'] ?? $categoryCombo['name'] ?? 'Default';
        $isDefault = $categoryCombo['isDefault'] ?? true;

        // If it's the default category combo, don't store it (no attributes needed)
        if ($isDefault) {
            return 0;
        }

        // Insert category combo
        $stmt = $pdo->prepare("
            INSERT INTO dataset_attribute_categories (survey_id, category_combo_uid, category_combo_name, is_default)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->execute([$surveyId, $ccUid, $ccName, 0]);
        $attributeCategoryId = $pdo->lastInsertId();

        // Store attribute option combos
        if (isset($categoryCombo['categoryOptionCombos']) && is_array($categoryCombo['categoryOptionCombos'])) {
            $stmt = $pdo->prepare("
                INSERT INTO dataset_attribute_option_combos (attribute_category_id, aoc_uid, aoc_name, aoc_display_name, sort_order)
                VALUES (?, ?, ?, ?, ?)
            ");

            foreach ($categoryCombo['categoryOptionCombos'] as $index => $aoc) {
                $aocUid = $aoc['id'] ?? '';
                $aocName = $aoc['name'] ?? '';
                $aocDisplayName = $aoc['displayName'] ?? $aocName;

                if ($aocUid) {
                    $stmt->execute([$attributeCategoryId, $aocUid, $aocName, $aocDisplayName, $index]);
                }
            }
        }

        return 1; // One category combo stored
    }

    /**
     * Store data elements from dataset
     */
    private static function storeDataElements($surveyId, $dataset, $optionSetMap) {
        global $pdo;

        $count = 0;
        $order = 0;
        $processedElements = []; // Track which data elements we've already stored

        $dataElementStmt = $pdo->prepare("
            INSERT INTO dataset_data_elements (
                survey_id, data_element_uid, data_element_name, display_name, form_name,
                code, description, value_type, aggregation_type, zero_is_significant,
                option_set_uid, option_set_name, category_combo_uid, category_combo_name,
                is_default_category, section_uid, section_name, display_order
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $cocStmt = $pdo->prepare("
            INSERT INTO dataset_category_option_combos (
                survey_id, data_element_uid, coc_uid, coc_name, coc_display_name
            ) VALUES (?, ?, ?, ?, ?)
        ");

        // Process sections
        if (isset($dataset['sections'])) {
            foreach ($dataset['sections'] as $section) {
                $sectionUid = $section['id'] ?? null;
                $sectionName = $section['displayName'] ?? $section['name'] ?? null;

                if (isset($section['dataElements'])) {
                    foreach ($section['dataElements'] as $de) {
                        $deUid = $de['id'] ?? null;

                        // Skip if already processed
                        if ($deUid && !isset($processedElements[$deUid])) {
                            self::storeDataElement($de, $surveyId, $sectionUid, $sectionName, $order++, $optionSetMap, $dataElementStmt, $cocStmt);
                            $processedElements[$deUid] = true;
                            $count++;
                        }
                    }
                }
            }
        }

        // Process dataSetElements (for non-sectioned datasets or elements not in sections)
        if (isset($dataset['dataSetElements'])) {
            foreach ($dataset['dataSetElements'] as $dse) {
                $de = $dse['dataElement'] ?? null;
                if ($de) {
                    $deUid = $de['id'] ?? null;

                    // Only store if not already processed from sections
                    if ($deUid && !isset($processedElements[$deUid])) {
                        self::storeDataElement($de, $surveyId, null, null, $order++, $optionSetMap, $dataElementStmt, $cocStmt);
                        $processedElements[$deUid] = true;
                        $count++;
                    }
                }
            }
        }

        return $count;
    }

    /**
     * Store a single data element
     */
    private static function storeDataElement($de, $surveyId, $sectionUid, $sectionName, $order, $optionSetMap, $dataElementStmt, $cocStmt) {
        $deUid = $de['id'];
        $deName = $de['name'] ?? '';
        $displayName = $de['displayName'] ?? $deName;
        $formName = $de['formName'] ?? $displayName;
        $code = $de['code'] ?? '';
        $description = $de['description'] ?? '';
        $valueType = $de['valueType'] ?? 'TEXT';
        $aggregationType = $de['aggregationType'] ?? '';
        $zeroIsSignificant = isset($de['zeroIsSignificant']) ? ($de['zeroIsSignificant'] ? 1 : 0) : 0;

        // Option set info
        $optionSetUid = null;
        $optionSetName = null;
        if (isset($de['optionSet'])) {
            $optionSetUid = $de['optionSet']['id'] ?? null;
            $optionSetName = $de['optionSet']['name'] ?? $de['optionSet']['displayName'] ?? null;
        }

        // Category combo info
        $categoryComboUid = null;
        $categoryComboName = null;
        $isDefaultCategory = true;
        if (isset($de['categoryCombo'])) {
            $categoryComboUid = $de['categoryCombo']['id'] ?? null;
            $categoryComboName = $de['categoryCombo']['name'] ?? $de['categoryCombo']['displayName'] ?? null;
            $isDefaultCategory = $de['categoryCombo']['isDefault'] ?? true;
        }

        // Insert data element
        $dataElementStmt->execute([
            $surveyId, $deUid, $deName, $displayName, $formName,
            $code, $description, $valueType, $aggregationType, $zeroIsSignificant,
            $optionSetUid, $optionSetName, $categoryComboUid, $categoryComboName,
            $isDefaultCategory ? 1 : 0, $sectionUid, $sectionName, $order
        ]);

        // Store category option combos if not default
        if (!$isDefaultCategory && isset($de['categoryCombo']['categoryOptionCombos'])) {
            foreach ($de['categoryCombo']['categoryOptionCombos'] as $coc) {
                $cocUid = $coc['id'] ?? '';
                $cocName = $coc['name'] ?? '';
                $cocDisplayName = $coc['displayName'] ?? $cocName;

                if ($cocUid) {
                    $cocStmt->execute([$surveyId, $deUid, $cocUid, $cocName, $cocDisplayName]);
                }
            }
        }
    }

    /**
     * Store organization units attached to dataset
     */
    private static function storeOrgUnits($surveyId, $datasetUid, $instanceKey) {
        global $pdo;

        // Fetch org units with hierarchy from DHIS2
        $apiEndpoint = "/api/dataSets/{$datasetUid}/organisationUnits.json?fields=id,name,displayName,parent[id,name],path,level&paging=false";
        $response = dhis2_get($apiEndpoint, $instanceKey);

        if (!$response || !isset($response['organisationUnits'])) {
            error_log("[DATASET STORAGE] Failed to fetch org units for dataset {$datasetUid}");
            return 0;
        }

        $orgUnits = $response['organisationUnits'];
        $count = 0;

        $stmt = $pdo->prepare("
            INSERT INTO dataset_org_units (
                survey_id, org_unit_uid, org_unit_name, org_unit_display_name,
                parent_uid, parent_name, path, level
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                org_unit_name = VALUES(org_unit_name),
                org_unit_display_name = VALUES(org_unit_display_name),
                parent_uid = VALUES(parent_uid),
                parent_name = VALUES(parent_name),
                path = VALUES(path),
                level = VALUES(level)
        ");

        foreach ($orgUnits as $ou) {
            $ouUid = $ou['id'] ?? '';
            $ouName = $ou['name'] ?? '';
            $ouDisplayName = $ou['displayName'] ?? $ouName;
            $parentUid = isset($ou['parent']['id']) ? $ou['parent']['id'] : null;
            $parentName = isset($ou['parent']['name']) ? $ou['parent']['name'] : null;
            $path = $ou['path'] ?? '';
            $level = $ou['level'] ?? null;

            if ($ouUid) {
                $stmt->execute([
                    $surveyId, $ouUid, $ouName, $ouDisplayName,
                    $parentUid, $parentName, $path, $level
                ]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get stored data elements for a survey
     */
    public static function getDataElements($surveyId) {
        global $pdo;

        try {
            $stmt = $pdo->prepare("
                SELECT dde.*,
                       dos.id as local_option_set_id,
                       GROUP_CONCAT(
                           CONCAT(dov.option_code, ':', dov.option_display_name)
                           ORDER BY dov.sort_order
                           SEPARATOR '|||'
                       ) as option_values
                FROM dataset_data_elements dde
                LEFT JOIN dataset_option_sets dos ON dde.option_set_uid = dos.option_set_uid AND dde.survey_id = dos.survey_id
                LEFT JOIN dataset_option_values dov ON dos.id = dov.option_set_id
                WHERE dde.survey_id = ?
                GROUP BY dde.id
                ORDER BY dde.display_order, dde.section_name, dde.data_element_name
            ");
            $stmt->execute([$surveyId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("[DATASET STORAGE] Error fetching data elements: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get stored org units for a survey
     */
    public static function getOrgUnits($surveyId) {
        global $pdo;

        try {
            $stmt = $pdo->prepare("
                SELECT *
                FROM dataset_org_units
                WHERE survey_id = ?
                ORDER BY org_unit_name
            ");
            $stmt->execute([$surveyId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("[DATASET STORAGE] Error fetching org units: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get stored attribute category combos for a survey
     * Returns array with category combo info and its options
     */
    public static function getAttributeCategoryCombo($surveyId) {
        global $pdo;

        try {
            // Get category combo
            $stmt = $pdo->prepare("
                SELECT *
                FROM dataset_attribute_categories
                WHERE survey_id = ?
                LIMIT 1
            ");
            $stmt->execute([$surveyId]);
            $categoryCombo = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$categoryCombo) {
                return null; // No attribute category combo (using default)
            }

            // Get attribute option combos
            $stmt = $pdo->prepare("
                SELECT *
                FROM dataset_attribute_option_combos
                WHERE attribute_category_id = ?
                ORDER BY sort_order
            ");
            $stmt->execute([$categoryCombo['id']]);
            $categoryCombo['options'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return $categoryCombo;
        } catch (PDOException $e) {
            error_log("[DATASET STORAGE] Error fetching attribute category combo: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get stored dataset structure from local database
     * Reconstructs the dataset structure similar to DHIS2 API format
     * This is the LOCAL equivalent of DatasetApiService::getDatasetComplete()
     *
     * @param int $surveyId Survey ID
     * @param string $datasetUid Dataset UID (for logging)
     * @param string $instanceKey DHIS2 instance key (for potential API fallback)
     * @return array|null Dataset structure or null if not found
     */
    public static function getStoredDataset($surveyId, $datasetUid, $instanceKey) {
        global $pdo;

        try {
            error_log("[DATASET STORAGE] Fetching stored dataset for survey {$surveyId}");

            // Get basic dataset info from LOCAL database
            $stmt = $pdo->prepare("SELECT * FROM dataset_metadata WHERE survey_id = ?");
            $stmt->execute([$surveyId]);
            $metadata = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$metadata) {
                error_log("[DATASET STORAGE] Dataset metadata not found for survey {$surveyId}. Please sync the dataset first.");
                return null;
            }

            // Build dataset structure from LOCAL storage
            $dataset = [
                'id' => $metadata['dataset_uid'],
                'name' => $metadata['dataset_name'],
                'displayName' => $metadata['dataset_display_name'],
                'description' => $metadata['dataset_description'],
                'periodType' => $metadata['period_type'],
                'formType' => $metadata['form_type']
            ];

            // Add custom form if available
            if (!empty($metadata['custom_form_html'])) {
                $dataset['dataEntryForm'] = [
                    'htmlCode' => $metadata['custom_form_html'],
                    'style' => $metadata['custom_form_style']
                ];
            }

            // Get stored data elements with option sets
            $dataset = self::addStoredDataElements($dataset, $surveyId);

            // Get stored attribute category combo
            $attributeCombo = self::getAttributeCategoryCombo($surveyId);
            if ($attributeCombo) {
                $dataset['categoryCombo'] = [
                    'id' => $attributeCombo['category_combo_uid'],
                    'name' => $attributeCombo['category_combo_name'],
                    'displayName' => $attributeCombo['category_combo_name'],
                    'isDefault' => false,
                    'categoryOptionCombos' => array_map(function($opt) {
                        return [
                            'id' => $opt['aoc_uid'],
                            'name' => $opt['aoc_name'],
                            'displayName' => $opt['aoc_display_name']
                        ];
                    }, $attributeCombo['options'])
                ];
            }

            error_log("[DATASET STORAGE] Successfully reconstructed dataset structure from local storage");
            return $dataset;

        } catch (Exception $e) {
            error_log("[DATASET STORAGE] Error getting stored dataset: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Add stored data elements to dataset structure
     * Organizes by sections if available
     */
    private static function addStoredDataElements($dataset, $surveyId) {
        global $pdo;

        try {
            // Fetch all data elements with their option sets
            $stmt = $pdo->prepare("
                SELECT
                    dde.*,
                    dos.option_set_uid as os_uid,
                    dos.option_set_name as os_name
                FROM dataset_data_elements dde
                LEFT JOIN dataset_option_sets dos ON dde.option_set_uid = dos.option_set_uid AND dde.survey_id = dos.survey_id
                WHERE dde.survey_id = ?
                ORDER BY dde.section_name, dde.display_order
            ");
            $stmt->execute([$surveyId]);
            $dataElements = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Get option set values for all option sets
            $optionSetsData = [];
            $stmt = $pdo->prepare("
                SELECT dos.option_set_uid, dov.option_code, dov.option_display_name, dov.sort_order
                FROM dataset_option_sets dos
                JOIN dataset_option_values dov ON dos.id = dov.option_set_id
                WHERE dos.survey_id = ?
                ORDER BY dos.option_set_uid, dov.sort_order
            ");
            $stmt->execute([$surveyId]);
            $optionValues = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Group option values by option set UID
            foreach ($optionValues as $ov) {
                $optionSetsData[$ov['option_set_uid']][] = [
                    'code' => $ov['option_code'],
                    'displayName' => $ov['option_display_name']
                ];
            }

            // Organize data elements by section
            $sections = [];
            $dataSetElements = [];

            foreach ($dataElements as $de) {
                $dataElement = [
                    'id' => $de['data_element_uid'],
                    'name' => $de['data_element_name'],
                    'displayName' => $de['display_name'] ?: $de['data_element_name'],
                    'formName' => $de['form_name'] ?: $de['display_name'],
                    'code' => $de['code'] ?: '',
                    'description' => $de['description'] ?: '',
                    'valueType' => $de['value_type'],
                    'aggregationType' => $de['aggregation_type'] ?: '',
                    'zeroIsSignificant' => (bool)$de['zero_is_significant']
                ];

                // Add option set if present (attach options when available)
                if ($de['option_set_uid']) {
                    $dataElement['optionSet'] = [
                        'id' => $de['option_set_uid'],
                        'name' => $de['option_set_name'],
                        'options' => $optionSetsData[$de['option_set_uid']] ?? []
                    ];
                }

                // Add category combo info
                if ($de['category_combo_uid']) {
                    $dataElement['categoryCombo'] = [
                        'id' => $de['category_combo_uid'],
                        'name' => $de['category_combo_name'],
                        'displayName' => $de['category_combo_name'],
                        'isDefault' => (bool)$de['is_default_category']
                    ];

                    // Get category option combos if not default
                    if (!$de['is_default_category']) {
                        $cocStmt = $pdo->prepare("
                            SELECT coc_uid, coc_name, coc_display_name
                            FROM dataset_category_option_combos
                            WHERE survey_id = ? AND data_element_uid = ?
                        ");
                        $cocStmt->execute([$surveyId, $de['data_element_uid']]);
                        $cocs = $cocStmt->fetchAll(PDO::FETCH_ASSOC);

                        if ($cocs) {
                            $dataElement['categoryCombo']['categoryOptionCombos'] = array_map(function($coc) {
                                return [
                                    'id' => $coc['coc_uid'],
                                    'name' => $coc['coc_name'],
                                    'displayName' => $coc['coc_display_name']
                                ];
                            }, $cocs);
                        }
                    }
                }

                // Organize by section
                if ($de['section_uid']) {
                    if (!isset($sections[$de['section_uid']])) {
                        $sections[$de['section_uid']] = [
                            'id' => $de['section_uid'],
                            'name' => $de['section_name'],
                            'displayName' => $de['section_name'],
                            'dataElements' => []
                        ];
                    }
                    $sections[$de['section_uid']]['dataElements'][] = $dataElement;
                } else {
                    // Non-sectioned data elements
                    $dataSetElements[] = ['dataElement' => $dataElement];
                }
            }

            // Add to dataset structure
            if (!empty($sections)) {
                $dataset['sections'] = array_values($sections);
            }
            if (!empty($dataSetElements)) {
                $dataset['dataSetElements'] = $dataSetElements;
            }

            return $dataset;

        } catch (PDOException $e) {
            error_log("[DATASET STORAGE] Error adding stored data elements: " . $e->getMessage());
            return $dataset;
        }
    }
}
?>
