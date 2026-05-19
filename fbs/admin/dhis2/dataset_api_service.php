<?php
/**
 * DHIS2 Dataset API Service
 *
 * Comprehensive service for fetching dataset metadata with support for:
 * - Form type detection (DEFAULT, SECTION, CUSTOM)
 * - Section-based organization
 * - Custom HTML forms (dataEntryForm)
 * - Organization units assigned to dataset
 * - Metadata caching
 */

require_once __DIR__ . '/dhis2_shared.php';

class DatasetApiService {

    /**
     * Fetch complete dataset metadata with form type, sections, and custom forms
     *
     * @param string $datasetUid DHIS2 dataset UID
     * @param string $instanceKey DHIS2 instance key
     * @param bool $useCache Whether to use cached metadata
     * @return array|null Dataset metadata or null on failure
     */
    public static function getDatasetComplete($datasetUid, $instanceKey, $useCache = true) {
        // Check cache first
        if ($useCache) {
            $cached = self::getCachedMetadata($datasetUid, $instanceKey);
            if ($cached) {
                error_log("Using cached metadata for dataset: {$datasetUid}");
                return $cached;
            }
        }

        // Build comprehensive API fields as a single string
        // Include option set options for dropdown rendering
        $fieldsString = 'id,name,displayName,description,periodType,formType,timelyDays,expiryDays,openFuturePeriods,' .
            'dataEntryForm[id,name,htmlCode,style],' .
            'categoryCombo[id,name,displayName,isDefault,categoryOptionCombos[id,name,displayName]],' .
            'sections[id,name,displayName,sortOrder,description,' .
            'dataElements[id,name,displayName,formName,code,description,valueType,' .
            'zeroIsSignificant,aggregationType,' .
            'categoryCombo[id,name,displayName,isDefault,' .
            'categoryOptionCombos[id,name,displayName]],' .
            'optionSet[id,name,options[code,displayName,name,sortOrder]]]],' .
            'dataSetElements[dataElement[id,name,displayName,formName,code,description,valueType,' .
            'zeroIsSignificant,aggregationType,' .
            'categoryCombo[id,name,displayName,isDefault,' .
            'categoryOptionCombos[id,name,displayName]],' .
            'optionSet[id,name,options[code,displayName,name,sortOrder]]]],' .
            'greyedFields[dataElement[id],categoryOptionCombo[id]]';
        $apiEndpoint = "/api/dataSets/{$datasetUid}.json?fields={$fieldsString}";

        error_log("Fetching dataset from DHIS2: {$apiEndpoint}");

        try {
            $dataset = dhis2_get($apiEndpoint, $instanceKey);

            if (!$dataset) {
                error_log("Failed to fetch dataset from DHIS2: {$datasetUid}");
                return null;
            }

            // Determine and set form type if not present
            if (!isset($dataset['formType'])) {
                $dataset['formType'] = self::determineFormType($dataset);
            }

            // Process and organize the dataset structure
            $dataset = self::processDatasetStructure($dataset);

            // Cache the metadata
            if ($useCache) {
                self::cacheMetadata($datasetUid, $instanceKey, $dataset);
            }

            return $dataset;

        } catch (Exception $e) {
            error_log("Exception fetching dataset: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Determine form type based on dataset structure
     *
     * @param array $dataset Dataset metadata
     * @return string Form type: CUSTOM, SECTION, or DEFAULT
     */
    private static function determineFormType($dataset) {
        if (isset($dataset['dataEntryForm']) && !empty($dataset['dataEntryForm'])) {
            return 'CUSTOM';
        }

        if (isset($dataset['sections']) && !empty($dataset['sections'])) {
            return 'SECTION';
        }

        return 'DEFAULT';
    }

    /**
     * Process and organize dataset structure
     *
     * @param array $dataset Raw dataset from DHIS2
     * @return array Processed dataset
     */
    private static function processDatasetStructure($dataset) {
        // Sort sections by sortOrder if present
        if (isset($dataset['sections']) && is_array($dataset['sections'])) {
            usort($dataset['sections'], function($a, $b) {
                return ($a['sortOrder'] ?? 0) - ($b['sortOrder'] ?? 0);
            });

            // Process each section
            foreach ($dataset['sections'] as &$section) {
                // Ensure displayName is set
                if (!isset($section['displayName'])) {
                    $section['displayName'] = $section['name'];
                }

                // Add metadata about category combos
                if (isset($section['dataElements'])) {
                    $section['hasCategoryCombos'] = self::sectionHasCategoryCombos($section['dataElements']);
                }
            }
        }

        // Process data set elements if no sections
        if (isset($dataset['dataSetElements']) && is_array($dataset['dataSetElements'])) {
            foreach ($dataset['dataSetElements'] as &$dse) {
                if (isset($dse['dataElement'])) {
                    $de = &$dse['dataElement'];
                    if (!isset($de['displayName'])) {
                        $de['displayName'] = $de['name'];
                    }
                }
            }
        }

        // Build greyed fields lookup for quick access
        if (isset($dataset['greyedFields']) && is_array($dataset['greyedFields'])) {
            $greyedLookup = [];
            foreach ($dataset['greyedFields'] as $gf) {
                $deId = $gf['dataElement']['id'] ?? null;
                $cocId = $gf['categoryOptionCombo']['id'] ?? null;
                if ($deId && $cocId) {
                    $greyedLookup["{$deId}_{$cocId}"] = true;
                }
            }
            $dataset['greyedFieldsLookup'] = $greyedLookup;
        }

        return $dataset;
    }

    /**
     * Check if section has data elements with category combinations
     *
     * @param array $dataElements Array of data elements
     * @return bool True if any element has category combos
     */
    private static function sectionHasCategoryCombos($dataElements) {
        foreach ($dataElements as $de) {
            if (isset($de['categoryCombo']) && !($de['categoryCombo']['isDefault'] ?? true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fetch organization units assigned to a dataset
     *
     * @param string $datasetUid DHIS2 dataset UID
     * @param string $instanceKey DHIS2 instance key
     * @param string|null $search Search term
     * @param int $page Page number (1-based)
     * @param int $pageSize Results per page
     * @return array|null Organization units or null on failure
     */
    public static function getDatasetOrganisationUnits($datasetUid, $instanceKey, $search = null, $page = 1, $pageSize = 50) {
        $fields = 'id,displayName,name,level,path,ancestors[id,name,displayName]';

        // Build query parameters
        $params = [
            'fields' => $fields,
            'paging' => 'true',
            'page' => $page,
            'pageSize' => $pageSize
        ];

        // Add search filter if provided
        if (!empty($search)) {
            $params['filter'] = 'displayName:ilike:' . $search;
        }

        $queryString = http_build_query($params);
        $apiEndpoint = "/api/dataSets/{$datasetUid}/organisationUnits.json?{$queryString}";

        error_log("Fetching org units from DHIS2: {$apiEndpoint}");

        try {
            $response = dhis2_get($apiEndpoint, $instanceKey);

            if (!$response) {
                error_log("Failed to fetch org units for dataset: {$datasetUid}");
                return null;
            }

            // Process org units to add hierarchy path
            if (isset($response['organisationUnits']) && is_array($response['organisationUnits'])) {
                foreach ($response['organisationUnits'] as &$ou) {
                    $ou['hierarchyPath'] = self::buildHierarchyPath($ou);
                }
            }

            return $response;

        } catch (Exception $e) {
            error_log("Exception fetching org units: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Build human-readable hierarchy path from org unit data
     *
     * @param array $orgUnit Organization unit with ancestors
     * @return string Hierarchy path (e.g., "Uganda > Central > Kampala")
     */
    private static function buildHierarchyPath($orgUnit) {
        $path = [];

        // Add ancestors in order
        if (isset($orgUnit['ancestors']) && is_array($orgUnit['ancestors'])) {
            foreach ($orgUnit['ancestors'] as $ancestor) {
                $path[] = $ancestor['displayName'] ?? $ancestor['name'];
            }
        }

        // Add current org unit
        $path[] = $orgUnit['displayName'] ?? $orgUnit['name'];

        return implode(' > ', $path);
    }

    /**
     * Get cached dataset metadata
     *
     * @param string $datasetUid Dataset UID
     * @param string $instanceKey Instance key
     * @return array|null Cached metadata or null if not found/expired
     */
    private static function getCachedMetadata($datasetUid, $instanceKey) {
        global $pdo;

        try {
            $stmt = $pdo->prepare("
                SELECT metadata, expires_at
                FROM dataset_metadata_cache
                WHERE dataset_uid = ? AND instance_key = ?
                AND expires_at > NOW()
            ");
            $stmt->execute([$datasetUid, $instanceKey]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                return json_decode($row['metadata'], true);
            }
        } catch (PDOException $e) {
            // Silently fail if table doesn't exist - caching is optional
            if (strpos($e->getMessage(), "doesn't exist") === false) {
                error_log("Error fetching cached metadata: " . $e->getMessage());
            }
        }

        return null;
    }

    /**
     * Cache dataset metadata
     *
     * @param string $datasetUid Dataset UID
     * @param string $instanceKey Instance key
     * @param array $metadata Dataset metadata
     * @param int $ttlMinutes Cache TTL in minutes (default: 60)
     */
    private static function cacheMetadata($datasetUid, $instanceKey, $metadata, $ttlMinutes = 60) {
        global $pdo;

        try {
            $stmt = $pdo->prepare("
                INSERT INTO dataset_metadata_cache (dataset_uid, instance_key, metadata, expires_at)
                VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE))
                ON DUPLICATE KEY UPDATE
                    metadata = VALUES(metadata),
                    cached_at = NOW(),
                    expires_at = VALUES(expires_at)
            ");

            $stmt->execute([
                $datasetUid,
                $instanceKey,
                json_encode($metadata),
                $ttlMinutes
            ]);

            error_log("Cached metadata for dataset: {$datasetUid}");

        } catch (PDOException $e) {
            // Silently fail if table doesn't exist - caching is optional
            if (strpos($e->getMessage(), "doesn't exist") === false) {
                error_log("Error caching metadata: " . $e->getMessage());
            }
        }
    }

    /**
     * Invalidate cached metadata for a dataset
     *
     * @param string $datasetUid Dataset UID
     * @param string $instanceKey Instance key
     */
    public static function invalidateCache($datasetUid, $instanceKey) {
        global $pdo;

        try {
            $stmt = $pdo->prepare("
                DELETE FROM dataset_metadata_cache
                WHERE dataset_uid = ? AND instance_key = ?
            ");
            $stmt->execute([$datasetUid, $instanceKey]);

            error_log("Invalidated cache for dataset: {$datasetUid}");

        } catch (PDOException $e) {
            error_log("Error invalidating cache: " . $e->getMessage());
        }
    }

    /**
     * Clean up expired cache entries
     */
    public static function cleanExpiredCache() {
        global $pdo;

        try {
            $stmt = $pdo->prepare("DELETE FROM dataset_metadata_cache WHERE expires_at < NOW()");
            $stmt->execute();

            $count = $stmt->rowCount();
            if ($count > 0) {
                error_log("Cleaned up {$count} expired cache entries");
            }

        } catch (PDOException $e) {
            error_log("Error cleaning expired cache: " . $e->getMessage());
        }
    }
}
?>
