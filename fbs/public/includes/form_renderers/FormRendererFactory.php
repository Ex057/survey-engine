<?php
/**
 * Form Renderer Factory
 *
 * Creates appropriate renderer based on dataset form type
 */

require_once __DIR__ . '/DatasetFormRenderer.php';
require_once __DIR__ . '/DefaultFormRenderer.php';
require_once __DIR__ . '/SectionFormRenderer.php';
require_once __DIR__ . '/CustomFormRenderer.php';

class FormRendererFactory {

    /**
     * Create renderer based on form type
     *
     * @param string $formType Form type: DEFAULT, SECTION, or CUSTOM
     * @param array $dataset Complete dataset metadata
     * @param array $datasetSettings Layout settings
     * @param array $dataElementSettings Data element visibility/order settings
     * @return DatasetFormRenderer Appropriate renderer instance
     */
    public static function create($formType, $dataset, $datasetSettings, $dataElementSettings, $options = []) {
        $forceSectionRenderer = !empty($options['force_section_renderer']);
        if ($forceSectionRenderer) {
            return new SectionFormRenderer($dataset, $datasetSettings, $dataElementSettings);
        }

        switch (strtoupper($formType)) {
            case 'SECTION':
                return new SectionFormRenderer($dataset, $datasetSettings, $dataElementSettings);

            case 'CUSTOM':
                return new CustomFormRenderer($dataset, $datasetSettings, $dataElementSettings);

            case 'DEFAULT':
            default:
                return new DefaultFormRenderer($dataset, $datasetSettings, $dataElementSettings);
        }
    }

    /**
     * Get renderer for dataset (auto-detects form type)
     *
     * @param array $dataset Complete dataset metadata
     * @param array $datasetSettings Layout settings
     * @param array $dataElementSettings Data element visibility/order settings
     * @return DatasetFormRenderer Appropriate renderer instance
     */
    public static function createFromDataset($dataset, $datasetSettings, $dataElementSettings, $options = []) {
        $formType = $dataset['formType'] ?? 'DEFAULT';
        return self::create($formType, $dataset, $datasetSettings, $dataElementSettings, $options);
    }
}
?>
