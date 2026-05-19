<?php
/**
 * Abstract base class for dataset form renderers
 *
 * Defines the interface that all form renderers must implement
 */

abstract class DatasetFormRenderer {

    protected $dataset;
    protected $datasetSettings;
    protected $dataElementSettings;

    /**
     * Constructor
     *
     * @param array $dataset Complete dataset metadata from DHIS2
     * @param array $datasetSettings Layout settings from database
     * @param array $dataElementSettings Data element visibility/order settings
     */
    public function __construct($dataset, $datasetSettings, $dataElementSettings) {
        $this->dataset = $dataset;
        $this->datasetSettings = $datasetSettings;
        $this->dataElementSettings = $dataElementSettings;
    }

    /**
     * Render the complete form
     *
     * @return string HTML output
     */
    abstract public function render();

    /**
     * Render an input field for a data element
     *
     * @param array $dataElement Data element metadata
     * @param string $fieldId Unique field ID
     * @param string|null $cocId Category option combo ID
     * @return string HTML input element
     */
    protected function renderInput($dataElement, $fieldId, $cocId = null) {
        $valueType = $dataElement['valueType'] ?? 'TEXT';
        $deId = $dataElement['id'];
        $optionSet = $dataElement['optionSet'] ?? null;

        // DEBUG: Log option set data
        if ($optionSet) {
            error_log("[RENDERER] Data element {$deId} has optionSet: " . json_encode($optionSet));
        }

        // Check if field is greyed out (disabled)
        $isGreyed = false;
        if ($cocId && isset($this->dataset['greyedFieldsLookup'])) {
            $isGreyed = isset($this->dataset['greyedFieldsLookup']["{$deId}_{$cocId}"]);
        }

        $disabled = $isGreyed ? 'disabled' : '';
        $cssClass = 'form-control data-element-input';
        if ($isGreyed) {
            $cssClass .= ' greyed-field';
        }

        // Data attributes for submission
        $dataAttrs = "data-de-id=\"{$deId}\"";
        if ($cocId) {
            $dataAttrs .= " data-coc-id=\"{$cocId}\"";
        }
        if ($optionSet && !empty($optionSet['id'])) {
            $dataAttrs .= " data-option-set-id=\"{$optionSet['id']}\"";
        }

        // Handle option sets (dropdowns)
        if ($optionSet) {
            $html = "<select class=\"{$cssClass}\" id=\"{$fieldId}\" {$dataAttrs} {$disabled}>";
            $html .= "<option value=\"\">Select...</option>";
            if (isset($optionSet['options']) && is_array($optionSet['options'])) {
                foreach ($optionSet['options'] as $option) {
                    $code = htmlspecialchars($option['code']);
                    $label = htmlspecialchars($option['displayName']);
                    $html .= "<option value=\"{$code}\">{$label}</option>";
                }
            }
            $html .= "</select>";
            return $html;
        }

        // Handle different value types
        switch ($valueType) {
            case 'NUMBER':
            case 'INTEGER':
            case 'INTEGER_POSITIVE':
            case 'INTEGER_NEGATIVE':
            case 'INTEGER_ZERO_OR_POSITIVE':
                $type = 'number';
                $step = ($valueType === 'INTEGER' || strpos($valueType, 'INTEGER') === 0) ? '1' : 'any';
                return "<input type=\"{$type}\" class=\"{$cssClass}\" id=\"{$fieldId}\" {$dataAttrs} step=\"{$step}\" {$disabled}>";

            case 'BOOLEAN':
            case 'TRUE_ONLY':
                $html = "<select class=\"{$cssClass}\" id=\"{$fieldId}\" {$dataAttrs} {$disabled}>";
                $html .= "<option value=\"\">Select...</option>";
                $html .= "<option value=\"true\">Yes</option>";
                if ($valueType !== 'TRUE_ONLY') {
                    $html .= "<option value=\"false\">No</option>";
                }
                $html .= "</select>";
                return $html;

            case 'DATE':
                return "<input type=\"date\" class=\"{$cssClass}\" id=\"{$fieldId}\" {$dataAttrs} {$disabled}>";

            case 'DATETIME':
                return "<input type=\"datetime-local\" class=\"{$cssClass}\" id=\"{$fieldId}\" {$dataAttrs} {$disabled}>";

            case 'TIME':
                return "<input type=\"time\" class=\"{$cssClass}\" id=\"{$fieldId}\" {$dataAttrs} {$disabled}>";

            case 'PERCENTAGE':
                return "<input type=\"number\" class=\"{$cssClass}\" id=\"{$fieldId}\" {$dataAttrs} min=\"0\" max=\"100\" step=\"0.01\" {$disabled}>";

            case 'LONG_TEXT':
                return "<textarea class=\"{$cssClass}\" id=\"{$fieldId}\" {$dataAttrs} rows=\"3\" {$disabled}></textarea>";

            case 'EMAIL':
                return "<input type=\"email\" class=\"{$cssClass}\" id=\"{$fieldId}\" {$dataAttrs} {$disabled}>";

            case 'PHONE_NUMBER':
                return "<input type=\"tel\" class=\"{$cssClass}\" id=\"{$fieldId}\" {$dataAttrs} {$disabled}>";

            case 'URL':
                return "<input type=\"url\" class=\"{$cssClass}\" id=\"{$fieldId}\" {$dataAttrs} {$disabled}>";

            case 'TEXT':
            default:
                return "<input type=\"text\" class=\"{$cssClass}\" id=\"{$fieldId}\" {$dataAttrs} {$disabled}>";
        }
    }

    /**
     * Check if data element is visible based on settings
     *
     * @param string $dataElementId Data element UID
     * @return bool True if visible
     */
    protected function isVisible($dataElementId) {
        if (empty($this->dataElementSettings)) {
            return true; // Show all if no settings
        }

        if (!isset($this->dataElementSettings[$dataElementId])) {
            return true; // Show if not configured
        }

        return (bool)$this->dataElementSettings[$dataElementId]['is_visible'];
    }

    /**
     * Get display order for data element
     *
     * @param string $dataElementId Data element UID
     * @param int $defaultOrder Default order if not set
     * @return int Display order
     */
    protected function getDisplayOrder($dataElementId, $defaultOrder = 999) {
        if (empty($this->dataElementSettings)) {
            return $defaultOrder;
        }

        if (!isset($this->dataElementSettings[$dataElementId])) {
            return $defaultOrder;
        }

        return (int)$this->dataElementSettings[$dataElementId]['display_order'];
    }

    /**
     * Check if data element is required
     *
     * @param string $dataElementId Data element UID
     * @return bool True if required
     */
    protected function isRequired($dataElementId) {
        if (empty($this->dataElementSettings)) {
            return false;
        }

        if (!isset($this->dataElementSettings[$dataElementId])) {
            return false;
        }

        return (bool)$this->dataElementSettings[$dataElementId]['is_required'];
    }

    /**
     * Generate unique field ID
     *
     * @param string $dataElementId Data element UID
     * @param string|null $cocId Category option combo ID
     * @return string Field ID
     */
    protected function generateFieldId($dataElementId, $cocId = null) {
        if ($cocId) {
            return "{$dataElementId}-{$cocId}-val";
        }
        return "{$dataElementId}-val";
    }
}
?>
