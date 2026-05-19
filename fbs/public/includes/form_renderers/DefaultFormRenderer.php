<?php
/**
 * Default Form Renderer
 *
 * Renders simple list of data elements without sections
 * Used when dataset has no sections or custom form
 */

require_once __DIR__ . '/DatasetFormRenderer.php';

class DefaultFormRenderer extends DatasetFormRenderer {

    /**
     * Render the complete default form
     *
     * @return string HTML output
     */
    public function render() {
        $dataSetElements = $this->dataset['dataSetElements'] ?? [];

        if (empty($dataSetElements)) {
            return '<div class="alert alert-warning">No data elements found in this dataset.</div>';
        }

        // Extract data elements and apply visibility/ordering
        $orderedElements = $this->filterAndOrderDataElements($dataSetElements);

        if (empty($orderedElements)) {
            return '<div class="alert alert-info">All data elements are hidden. Please check preview settings.</div>';
        }

        $html = '<div class="dataset-default-form">';

        foreach ($orderedElements as $item) {
            $de = $item['element'];
            $html .= $this->renderDataElement($de);
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Filter and order data elements
     *
     * @param array $dataSetElements Array of data set elements
     * @return array Filtered and ordered elements
     */
    private function filterAndOrderDataElements($dataSetElements) {
        $orderedElements = [];

        foreach ($dataSetElements as $index => $dse) {
            $de = $dse['dataElement'];
            $deId = $de['id'];

            // Skip hidden elements
            if (!$this->isVisible($deId)) {
                continue;
            }

            $order = $this->getDisplayOrder($deId, $index);

            $orderedElements[] = [
                'element' => $de,
                'order' => $order
            ];
        }

        // Sort by order
        usort($orderedElements, function($a, $b) {
            return $a['order'] - $b['order'];
        });

        return $orderedElements;
    }

    /**
     * Render a single data element
     *
     * @param array $de Data element metadata
     * @return string HTML output
     */
    private function renderDataElement($de) {
        $deId = $de['id'];
        $deName = htmlspecialchars($de['displayName'] ?? $de['name']);
        $deDesc = isset($de['description']) ? htmlspecialchars($de['description']) : '';
        $isRequired = $this->isRequired($deId);
        $hasCategoryCombo = isset($de['categoryCombo']) && !($de['categoryCombo']['isDefault'] ?? true);

        $html = '<div class="data-element-group mb-4">';

        // Label
        $html .= '<label class="form-label">';
        $html .= '<strong>' . $deName . '</strong>';
        if ($isRequired) {
            $html .= ' <span class="required-asterisk">*</span>';
        }
        $html .= '</label>';

        // Description
        if ($deDesc) {
            $html .= '<div class="helper-text mb-2">' . $deDesc . '</div>';
        }

        // Render input(s)
        if ($hasCategoryCombo) {
            $html .= $this->renderCategoryComboInputs($de);
        } else {
            $fieldId = $this->generateFieldId($deId);
            $html .= $this->renderInput($de, $fieldId);
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render inputs for data element with category combinations
     *
     * @param array $de Data element metadata
     * @return string HTML output
     */
    private function renderCategoryComboInputs($de) {
        $deId = $de['id'];
        $categoryOptionCombos = $de['categoryCombo']['categoryOptionCombos'] ?? [];

        if (empty($categoryOptionCombos)) {
            $fieldId = $this->generateFieldId($deId);
            return $this->renderInput($de, $fieldId);
        }

        $html = '<div class="table-responsive">';
        $html .= '<table class="table table-sm table-bordered">';
        $html .= '<thead class="table-light">';
        $html .= '<tr><th style="width: 40%;">Category</th><th>Value</th></tr>';
        $html .= '</thead>';
        $html .= '<tbody>';

        foreach ($categoryOptionCombos as $coc) {
            $cocId = $coc['id'];
            $cocName = htmlspecialchars($coc['displayName'] ?? $coc['name']);
            $fieldId = $this->generateFieldId($deId, $cocId);

            $html .= '<tr>';
            $html .= '<td>' . $cocName . '</td>';
            $html .= '<td>' . $this->renderInput($de, $fieldId, $cocId) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';

        return $html;
    }
}
?>
