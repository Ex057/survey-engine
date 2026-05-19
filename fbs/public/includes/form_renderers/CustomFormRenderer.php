<?php
/**
 * Custom Form Renderer
 *
 * Parses and renders DHIS2 custom HTML forms (dataEntryForm)
 * Replaces placeholders with actual input elements
 */

require_once __DIR__ . '/DatasetFormRenderer.php';

class CustomFormRenderer extends DatasetFormRenderer {

    /**
     * Render the complete custom form
     *
     * @return string HTML output
     */
    public function render() {
        $dataEntryForm = $this->dataset['dataEntryForm'] ?? null;

        if (!$dataEntryForm || empty($dataEntryForm['htmlCode'])) {
            return '<div class="alert alert-warning">Custom form HTML not available. Falling back to default rendering.</div>';
        }

        $htmlCode = $dataEntryForm['htmlCode'];
        $processedHtml = $this->parseCustomForm($htmlCode);

        $html = '<div class="dataset-custom-form">';
        $html .= $processedHtml;
        $html .= '</div>';

        return $html;
    }

    /**
     * Parse custom form HTML and replace DHIS2 placeholders with inputs
     *
     * @param string $htmlCode Raw HTML from DHIS2
     * @return string Processed HTML with functional inputs
     */
    private function parseCustomForm($htmlCode) {
        // DHIS2 custom forms use pattern: {dataElementUid}-{categoryOptionComboUid}-val
        // or simply: {dataElementUid} for elements without categories

        // STEP 1: Remove problematic elements that could cause reloads or issues

        // Remove any script tags
        $htmlCode = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $htmlCode);

        // Remove meta refresh tags
        $htmlCode = preg_replace('/<meta[^>]*http-equiv=["\']refresh["\'][^>]*>/i', '', $htmlCode);

        // Remove form action attributes
        $htmlCode = preg_replace('/<form([^>]*?)action\s*=\s*["\'][^"\']*["\']([^>]*?)>/i', '<form$1$2>', $htmlCode);

        // Remove form method=get to prevent URL parameters
        $htmlCode = preg_replace('/<form([^>]*?)method\s*=\s*["\']get["\']([^>]*?)>/i', '<form$1method="post"$2>', $htmlCode);

        // Remove any onload, onsubmit, or other event handlers from form tags
        $htmlCode = preg_replace('/<form([^>]*?)on\w+\s*=\s*["\'][^"\']*["\']([^>]*?)>/i', '<form$1$2>', $htmlCode);

        // STEP 2: Replace DHIS2 input placeholders with functional inputs

        // Pattern 1: {deId}-{cocId}-val (most common) or deId-cocId-val
        $htmlCode = preg_replace_callback(
            '/<input([^>]*?)id\s*=\s*["\']?\{?([a-zA-Z0-9]{11})\}?-\{?([a-zA-Z0-9]{11})\}?-val["\']?([^>]*?)>/i',
            [$this, 'replaceInputWithCategories'],
            $htmlCode
        );
        $htmlCode = preg_replace_callback(
            '/<textarea([^>]*?)id\s*=\s*["\']?\{?([a-zA-Z0-9]{11})\}?-\{?([a-zA-Z0-9]{11})\}?-val["\']?([^>]*)>.*?<\/textarea>/is',
            [$this, 'replaceInputWithCategories'],
            $htmlCode
        );
        $htmlCode = preg_replace_callback(
            '/<select([^>]*?)id\s*=\s*["\']?\{?([a-zA-Z0-9]{11})\}?-\{?([a-zA-Z0-9]{11})\}?-val["\']?([^>]*)>.*?<\/select>/is',
            [$this, 'replaceInputWithCategories'],
            $htmlCode
        );

        // Pattern 2: {deId}-val (no categories) or deId-val
        $htmlCode = preg_replace_callback(
            '/<input([^>]*?)id\s*=\s*["\']?\{?([a-zA-Z0-9]{11})\}?-val["\']?([^>]*?)>/i',
            [$this, 'replaceInputNoCategories'],
            $htmlCode
        );
        $htmlCode = preg_replace_callback(
            '/<textarea([^>]*?)id\s*=\s*["\']?\{?([a-zA-Z0-9]{11})\}?-val["\']?([^>]*)>.*?<\/textarea>/is',
            [$this, 'replaceInputNoCategories'],
            $htmlCode
        );
        $htmlCode = preg_replace_callback(
            '/<select([^>]*?)id\s*=\s*["\']?\{?([a-zA-Z0-9]{11})\}?-val["\']?([^>]*)>.*?<\/select>/is',
            [$this, 'replaceInputNoCategories'],
            $htmlCode
        );

        // Pattern 3: Simple placeholder {deId} or deId without -val suffix
        $htmlCode = preg_replace_callback(
            '/<input([^>]*?)id\s*=\s*["\']?\{?([a-zA-Z0-9]{11})\}?["\']?([^>]*?)>/i',
            [$this, 'replaceInputNoCategories'],
            $htmlCode
        );
        $htmlCode = preg_replace_callback(
            '/<textarea([^>]*?)id\s*=\s*["\']?\{?([a-zA-Z0-9]{11})\}?["\']?([^>]*)>.*?<\/textarea>/is',
            [$this, 'replaceInputNoCategories'],
            $htmlCode
        );
        $htmlCode = preg_replace_callback(
            '/<select([^>]*?)id\s*=\s*["\']?\{?([a-zA-Z0-9]{11})\}?["\']?([^>]*)>.*?<\/select>/is',
            [$this, 'replaceInputNoCategories'],
            $htmlCode
        );

        return $htmlCode;
    }

    /**
     * Replace input placeholder with actual input (with category combo)
     *
     * @param array $matches Regex matches
     * @return string Replacement HTML
     */
    private function replaceInputWithCategories($matches) {
        $originalHtml = $matches[0] ?? '';
        $beforeId = $matches[1] ?? '';
        $deId = $matches[2];
        $cocId = $matches[3];
        $afterId = $matches[4] ?? '';

        // Find data element in dataset
        $dataElement = $this->findDataElement($deId);

        if (!$dataElement) {
            // Keep original HTML (often used for totals or indicators)
            return $originalHtml;
        }

        // Check visibility
        if (!$this->isVisible($deId)) {
            return ''; // Hide if not visible
        }

        // Generate field ID
        $fieldId = $this->generateFieldId($deId, $cocId);

        // Render the input
        $inputHtml = $this->renderInput($dataElement, $fieldId, $cocId);

        return $this->applyCustomAttributes($inputHtml, $beforeId . ' ' . $afterId);
    }

    /**
     * Replace input placeholder with actual input (no categories)
     *
     * @param array $matches Regex matches
     * @return string Replacement HTML
     */
    private function replaceInputNoCategories($matches) {
        $originalHtml = $matches[0] ?? '';
        $beforeId = $matches[1] ?? '';
        $deId = $matches[2];
        $afterId = $matches[3] ?? '';

        // Find data element in dataset
        $dataElement = $this->findDataElement($deId);

        if (!$dataElement) {
            // Keep original HTML (often used for totals or indicators)
            return $originalHtml;
        }

        // Check visibility
        if (!$this->isVisible($deId)) {
            return ''; // Hide if not visible
        }

        // Generate field ID
        $fieldId = $this->generateFieldId($deId);

        // Render the input
        $inputHtml = $this->renderInput($dataElement, $fieldId);

        return $this->applyCustomAttributes($inputHtml, $beforeId . ' ' . $afterId);
    }

    /**
     * Apply safe attributes from the original placeholder element to the rendered input.
     *
     * @param string $inputHtml Rendered input HTML
     * @param string $rawAttributes Original attribute string
     * @return string Updated input HTML
     */
    private function applyCustomAttributes($inputHtml, $rawAttributes) {
        $rawAttributes = trim($rawAttributes);
        if ($rawAttributes === '') {
            return $inputHtml;
        }

        $classAttr = $this->extractAttribute($rawAttributes, 'class');
        $styleAttr = $this->extractAttribute($rawAttributes, 'style');
        $placeholderAttr = $this->extractAttribute($rawAttributes, 'placeholder');

        if ($classAttr) {
            $inputHtml = $this->mergeAttribute($inputHtml, 'class', $classAttr, ' ');
        }
        if ($styleAttr) {
            $inputHtml = $this->mergeAttribute($inputHtml, 'style', $styleAttr, '; ');
        }
        if ($placeholderAttr && stripos($inputHtml, '<select') === false) {
            $inputHtml = $this->mergeAttribute($inputHtml, 'placeholder', $placeholderAttr, '');
        }

        $dataAttributes = $this->extractDataAttributes($rawAttributes);
        foreach ($dataAttributes as $name => $value) {
            if (!preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*"/i', $inputHtml)) {
                $inputHtml = preg_replace(
                    '/<([a-zA-Z0-9]+)(\s|>)/',
                    '<$1 ' . $name . '="' . $value . '"$2',
                    $inputHtml,
                    1
                );
            }
        }

        return $inputHtml;
    }

    /**
     * Extract a single attribute value from a raw attribute string.
     *
     * @param string $rawAttributes
     * @param string $name
     * @return string|null
     */
    private function extractAttribute($rawAttributes, $name) {
        if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*["\']([^"\']*)["\']/i', $rawAttributes, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }

    /**
     * Extract data-* attributes from a raw attribute string.
     *
     * @param string $rawAttributes
     * @return array
     */
    private function extractDataAttributes($rawAttributes) {
        $attributes = [];
        if (preg_match_all('/\b(data-[a-zA-Z0-9_-]+)\s*=\s*["\']([^"\']*)["\']/i', $rawAttributes, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes[$match[1]] = $match[2];
            }
        }
        return $attributes;
    }

    /**
     * Merge an attribute into the rendered input HTML.
     *
     * @param string $inputHtml
     * @param string $name
     * @param string $value
     * @param string $separator
     * @return string
     */
    private function mergeAttribute($inputHtml, $name, $value, $separator) {
        $value = trim($value);
        if ($value === '') {
            return $inputHtml;
        }

        if (preg_match('/\b' . preg_quote($name, '/') . '\s*=\s*"([^"]*)"/i', $inputHtml, $matches)) {
            $existing = trim($matches[1]);
            $merged = $separator === '' ? $value : trim($existing . $separator . $value);
            return preg_replace(
                '/\b' . preg_quote($name, '/') . '\s*=\s*"[^"]*"/i',
                $name . '="' . $merged . '"',
                $inputHtml,
                1
            );
        }

        return preg_replace(
            '/<([a-zA-Z0-9]+)(\s|>)/',
            '<$1 ' . $name . '="' . $value . '"$2',
            $inputHtml,
            1
        );
    }

    /**
     * Find data element in dataset by ID
     *
     * @param string $deId Data element UID
     * @return array|null Data element metadata or null if not found
     */
    private function findDataElement($deId) {
        // Search in sections first
        if (isset($this->dataset['sections']) && is_array($this->dataset['sections'])) {
            foreach ($this->dataset['sections'] as $section) {
                if (isset($section['dataElements']) && is_array($section['dataElements'])) {
                    foreach ($section['dataElements'] as $de) {
                        if ($de['id'] === $deId) {
                            return $de;
                        }
                    }
                }
            }
        }

        // Search in dataSetElements
        if (isset($this->dataset['dataSetElements']) && is_array($this->dataset['dataSetElements'])) {
            foreach ($this->dataset['dataSetElements'] as $dse) {
                $de = $dse['dataElement'] ?? null;
                if ($de && $de['id'] === $deId) {
                    return $de;
                }
            }
        }

        return null;
    }
}
?>
