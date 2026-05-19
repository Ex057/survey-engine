<?php
/**
 * Section Form Renderer
 *
 * Renders dataset forms organized by sections with category-combination decision rules
 * mirroring the Kotlin DataEntryViewModel strategy.
 */

require_once __DIR__ . '/DatasetFormRenderer.php';

class SectionFormRenderer extends DatasetFormRenderer {
    private function prettyLabel($label) {
        $text = trim((string)$label);
        if ($text === '') return '';
        $text = preg_replace('/^[A-Za-z]{1,8}_[A-Za-z0-9]{1,8}_/', '', $text);
        $text = str_replace('_', ' ', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }

    public function render() {
        $sections = $this->dataset['sections'] ?? [];

        if (empty($sections)) {
            return '<div class="alert alert-warning">No sections found in this dataset.</div>';
        }

        $html = '<div class="dataset-sections">';

        foreach ($sections as $section) {
            $html .= $this->renderSection($section);
        }

        $html .= '</div>';
        return $html;
    }

    private function renderSection($section) {
        $sectionId = $section['id'];
        $sectionName = htmlspecialchars($section['displayName'] ?? $section['name']);
        $sectionDesc = isset($section['description']) ? htmlspecialchars($section['description']) : '';
        $dataElements = $section['dataElements'] ?? [];
        $visibleElements = $this->filterAndOrderDataElements($dataElements);

        if (empty($visibleElements)) {
            return '';
        }

        $html = '<div class="dataset-section" id="section-' . $sectionId . '" data-section-id="' . $sectionId . '">';
        $html .= '<div class="section-header">';
        $html .= '<h3 class="section-title"><i class="fas fa-folder-open me-2"></i>' . $sectionName . '</h3>';
        $html .= '<div class="section-status" aria-live="polite"><span class="section-status-dot"></span><span class="section-status-text">Not saved</span></div>';
        if ($sectionDesc) {
            $html .= '<p class="section-description text-muted">' . $sectionDesc . '</p>';
        }
        $html .= '</div>';

        $html .= '<div class="section-content">';
        $html .= $this->renderSectionBody($visibleElements, $sectionId);
        $html .= '</div>';
        $html .= '</div>';

        return $html;
    }

    private function renderSectionBody($dataElements, $sectionId) {
        $html = '';
        $strategies = $this->computeGroupingStrategies($dataElements);
        $renderedIds = [];

        // Radio-style grouped controls first
        if (!empty($strategies['radio_groups'])) {
            foreach ($strategies['radio_groups'] as $groupKey => $group) {
                $html .= $this->renderRadioGroupBlock($groupKey, $group, $sectionId);
                foreach ($group as $g) {
                    if (!empty($g['de']['id'])) {
                        $renderedIds[$g['de']['id']] = true;
                    }
                }
            }
        }

        // Fallback parsed-label matrix path for default category-combo elements
        $parsedGrid = $this->buildParsedLabelMatrix($strategies['remaining']);
        if ($parsedGrid) {
            $html .= $this->renderParsedLabelMatrix($parsedGrid);
            foreach ($parsedGrid['elements'] as $de) {
                if (!empty($de['id'])) {
                    $renderedIds[$de['id']] = true;
                }
            }
        }

        // Remaining fields and subsections
        foreach ($strategies['remaining'] as $de) {
            if (!empty($de['id']) && isset($renderedIds[$de['id']])) {
                continue;
            }
            $html .= $this->renderDataElementByDecisionTree($de, $sectionId);
        }
        return $html;
    }

    private function computeGroupingStrategies($dataElements) {
        // Keep input control types exactly as metadata specifies.
        // Radio grouping heuristic is disabled for now because it can
        // incorrectly convert numeric/text fields into choice-only UI.
        $radioGroups = [];
        $orderedRemaining = $dataElements;
        return [
            'radio_groups' => $radioGroups,
            'remaining' => $orderedRemaining
        ];
    }

    private function renderRadioGroupBlock($groupTitle, $items, $sectionId) {
        $subId = 'subsec-' . md5($sectionId . '-' . $groupTitle);
        $titleText = htmlspecialchars($this->prettyLabel($groupTitle));

        $html = '<div class="data-element-group mb-3 subsection-group" data-subsection-id="' . $subId . '" data-subsection-title="' . $titleText . '">';
        $html .= '<label class="form-label"><strong>' . $titleText . '</strong></label>';
        $html .= '<div class="radio-group-options">';

        foreach ($items as $idx => $item) {
            $de = $item['de'];
            $deId = $de['id'];
            $fieldId = $this->generateFieldId($deId);
            $optionLabel = htmlspecialchars($this->prettyLabel($item['option_label']));
            $radioName = 'radio-group-' . $subId;
            $radioId = $fieldId . '-choice';

            // Hidden actual data input that is submitted
            $html .= '<div style="display:none">' . $this->renderInput($de, $fieldId) . '</div>';
            $html .= '<div class="form-check mb-2">';
            $html .= '<input class="form-check-input grouped-radio-choice" type="radio" name="' . $radioName . '" id="' . $radioId . '" data-target-input="' . $fieldId . '"' . ($idx === 0 ? '' : '') . '>';
            $html .= '<label class="form-check-label" for="' . $radioId . '">' . $optionLabel . '</label>';
            $html .= '</div>';
        }

        $html .= '</div></div>';
        return $html;
    }

    private function filterAndOrderDataElements($dataElements) {
        $orderedElements = [];

        foreach ($dataElements as $index => $de) {
            $deId = $de['id'];
            if (!$this->isVisible($deId)) {
                continue;
            }

            $orderedElements[] = [
                'element' => $de,
                'order' => $this->getDisplayOrder($deId, $index)
            ];
        }

        usort($orderedElements, function($a, $b) {
            return $a['order'] - $b['order'];
        });

        return array_map(function($item) {
            return $item['element'];
        }, $orderedElements);
    }

    private function renderDataElementByDecisionTree($de, $sectionId) {
        $deId = $de['id'];
        $deName = htmlspecialchars($this->prettyLabel($de['displayName'] ?? $de['name']));
        $deDesc = isset($de['description']) ? htmlspecialchars($de['description']) : '';
        $isRequired = $this->isRequired($deId);

        $html = '<div class="data-element-group mb-3" data-de-group="' . htmlspecialchars($deId) . '">';
        $html .= '<label class="form-label"><strong>' . $deName . '</strong>';
        if ($isRequired) {
            $html .= ' <span class="required-asterisk">*</span>';
        }
        $html .= '</label>';

        if ($deDesc) {
            $html .= '<div class="helper-text mb-2">' . $deDesc . '</div>';
        }

        $comboMeta = $this->extractCategoryComboMetadata($de);
        $categoryCount = count($comboMeta['categories']);

        // Decision tree
        if ($categoryCount === 0) {
            // No categories
            $fieldId = $this->generateFieldId($deId);
            $html .= $this->renderInput($de, $fieldId);
        } elseif ($categoryCount === 1) {
            // One category -> label/value rows
            $html .= $this->renderSingleCategoryRows($de, $comboMeta);
        } elseif ($categoryCount === 2) {
            // Two categories -> small matrix when manageable, else rows
            $rowCount = count($comboMeta['categories'][0]['options']);
            $colCount = count($comboMeta['categories'][1]['options']);
            if ($colCount >= 2 && $colCount <= 3 && $rowCount >= 2) {
                $html .= $this->renderTwoCategoryMatrix($de, $comboMeta, 0, 1);
            } else {
                $html .= $this->renderFlattenedCategoryRows($de, $comboMeta);
            }
        } else {
            // 3+ categories -> selector + matrix/rows
            $html .= $this->renderMultiCategorySelectorBlock($de, $comboMeta, $sectionId);
        }

        $html .= '</div>';
        return $html;
    }

    private function extractCategoryComboMetadata($de) {
        $categoryCombo = $de['categoryCombo'] ?? [];
        $isDefault = $categoryCombo['isDefault'] ?? true;
        $categories = [];
        $combos = $categoryCombo['categoryOptionCombos'] ?? [];

        if ($isDefault || empty($combos)) {
            return [
                'categories' => [],
                'combos' => [],
                'lookup' => []
            ];
        }

        if (!empty($categoryCombo['categories']) && is_array($categoryCombo['categories'])) {
            foreach ($categoryCombo['categories'] as $cat) {
                $catId = $cat['id'] ?? '';
                $catName = $cat['displayName'] ?? ($cat['name'] ?? 'Category');
                $opts = [];
                foreach (($cat['categoryOptions'] ?? []) as $opt) {
                    $opts[] = [
                        'id' => $opt['id'] ?? '',
                        'name' => $opt['displayName'] ?? ($opt['name'] ?? ($opt['id'] ?? 'Option'))
                    ];
                }
                if (!empty($opts)) {
                    $categories[] = [
                        'id' => $catId,
                        'name' => $catName,
                        'options' => $opts
                    ];
                }
            }
        }

        // Fallback when category metadata is sparse
        if (empty($categories)) {
            $categories[] = [
                'id' => 'fallback',
                'name' => 'Category',
                'options' => array_map(function($coc) {
                    return [
                        'id' => $coc['id'] ?? '',
                        'name' => $coc['displayName'] ?? ($coc['name'] ?? ($coc['id'] ?? 'Option'))
                    ];
                }, $combos)
            ];
        }

        $lookup = $this->buildOptionPathLookup($combos);

        return [
            'categories' => $categories,
            'combos' => $combos,
            'lookup' => $lookup
        ];
    }

    private function buildOptionPathLookup($combos) {
        $lookup = [];

        foreach ($combos as $coc) {
            $optionIds = [];
            foreach (($coc['categoryOptions'] ?? []) as $opt) {
                if (!empty($opt['id'])) {
                    $optionIds[] = $opt['id'];
                }
            }
            sort($optionIds);
            if (!empty($optionIds) && !empty($coc['id'])) {
                $lookup[join('|', $optionIds)] = $coc['id'];
            }
        }

        return $lookup;
    }

    private function findComboUidByOptionIds($lookup, $optionIds) {
        $ids = array_filter($optionIds);
        sort($ids);
        $key = join('|', $ids);
        return $lookup[$key] ?? null;
    }

    private function renderSingleCategoryRows($de, $meta) {
        $deId = $de['id'];
        $cat = $meta['categories'][0];
        $inferred = $this->inferTwoDimFromComboNames($meta['combos']);
        if ($inferred && $inferred['col_count'] >= 2 && $inferred['col_count'] <= 3 && $inferred['row_count'] >= 2) {
            return $this->renderInferredTwoDimMatrix($de, $inferred);
        }

        $html = '<div class="table-responsive matrix-scroll"><table class="table table-sm table-bordered nested-category-table mobile-matrix">';
        $html .= '<thead class="table-light"><tr><th class="sticky-col" style="width:40%;">' . htmlspecialchars($this->prettyLabel($cat['name'])) . '</th><th>Value</th></tr></thead><tbody>';

        foreach ($meta['combos'] as $coc) {
            $cocId = $coc['id'] ?? null;
            if (!$cocId) continue;
            $label = $this->prettyLabel($coc['displayName'] ?? ($coc['name'] ?? $cocId));
            $fieldId = $this->generateFieldId($deId, $cocId);
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($label) . '</td>';
            $html .= '<td>' . $this->renderInput($de, $fieldId, $cocId) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    private function renderTwoCategoryMatrix($de, $meta, $rowCatIndex, $colCatIndex) {
        $deId = $de['id'];
        $rowCat = $meta['categories'][$rowCatIndex];
        $colCat = $meta['categories'][$colCatIndex];
        $selectorId = 'selector-2d-' . $deId;
        $html = '<div class="mb-2">';
        $html .= '<label class="form-label" for="' . htmlspecialchars($selectorId) . '">' . htmlspecialchars($this->prettyLabel($rowCat['name'])) . '</label>';
        $html .= '<select class="form-select category-primary-selector" id="' . htmlspecialchars($selectorId) . '" data-target-de="' . htmlspecialchars($deId) . '">';
        foreach ($rowCat['options'] as $idx => $rowOpt) {
            $key = $rowOpt['id'] ?? ('row-' . $idx);
            $selected = $idx === 0 ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($key) . '"' . $selected . '>' . htmlspecialchars($this->prettyLabel($rowOpt['name'])) . '</option>';
        }
        $html .= '</select>';
        $html .= '</div>';

        foreach ($rowCat['options'] as $idx => $rowOpt) {
            $rowKey = $rowOpt['id'] ?? ('row-' . $idx);
            $paneClass = $idx === 0 ? ' category-pane active' : ' category-pane';
            $html .= '<div class="' . $paneClass . '" data-de-pane="' . htmlspecialchars($deId) . '" data-selector-option="' . htmlspecialchars($rowKey) . '">';
            $html .= '<div class="table-responsive matrix-scroll"><table class="table table-bordered table-hover category-grid mobile-matrix">';
            $html .= '<thead class="table-light"><tr>';
            $html .= '<th class="sticky-col" style="width:28%;">' . htmlspecialchars($this->prettyLabel($rowOpt['name'])) . '</th>';
            foreach ($colCat['options'] as $colOpt) {
                $html .= '<th>' . htmlspecialchars($this->prettyLabel($colOpt['name'])) . '</th>';
            }
            $html .= '</tr></thead><tbody><tr>';
            $html .= '<td class="data-element-label sticky-col"><strong>' . htmlspecialchars($this->prettyLabel($rowOpt['name'])) . '</strong></td>';
            foreach ($colCat['options'] as $colOpt) {
                $cocId = $this->findComboUidByOptionIds($meta['lookup'], [$rowOpt['id'], $colOpt['id']]);
                $html .= '<td class="input-cell">';
                if ($cocId) {
                    $fieldId = $this->generateFieldId($deId, $cocId);
                    $html .= $this->renderInput($de, $fieldId, $cocId);
                } else {
                    $html .= '<span class="text-muted">-</span>';
                }
                $html .= '</td>';
            }
            $html .= '</tr></tbody></table></div>';
            $html .= '</div>';
        }
        return $html;
    }

    private function renderFlattenedCategoryRows($de, $meta) {
        $deId = $de['id'];
        $html = '<div class="table-responsive matrix-scroll"><table class="table table-sm table-bordered nested-category-table mobile-matrix">';
        $html .= '<thead class="table-light"><tr><th style="width:55%;">Category Path</th><th>Value</th></tr></thead><tbody>';

        foreach ($meta['combos'] as $coc) {
            $cocId = $coc['id'] ?? null;
            if (!$cocId) {
                continue;
            }
            $pathParts = [];
            foreach (($coc['categoryOptions'] ?? []) as $opt) {
                $pathParts[] = $opt['displayName'] ?? ($opt['name'] ?? ($opt['id'] ?? 'Option'));
            }
            $pathLabel = !empty($pathParts)
                ? join(' / ', $pathParts)
                : ($coc['displayName'] ?? ($coc['name'] ?? $cocId));
            $pathLabel = $this->prettyLabel($pathLabel);

            $fieldId = $this->generateFieldId($deId, $cocId);
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($pathLabel) . '</td>';
            $html .= '<td>' . $this->renderInput($de, $fieldId, $cocId) . '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table></div>';
        return $html;
    }

    private function renderMultiCategorySelectorBlock($de, $meta, $sectionId) {
        $deId = $de['id'];
        $categories = $meta['categories'];

        // Selector category for 3+ categories
        $selectorCategory = $categories[0];
        $remainingCategories = array_values(array_slice($categories, 1));

        $selectorId = 'selector-' . $sectionId . '-' . $deId;

        $html = '<div class="category-selector-block" data-de-selector-block="' . htmlspecialchars($deId) . '">';
        $html .= '<div class="mb-2">';
        $html .= '<label class="form-label" for="' . htmlspecialchars($selectorId) . '">' . htmlspecialchars($this->prettyLabel($selectorCategory['name'])) . '</label>';
        $html .= '<select class="form-select category-primary-selector" id="' . htmlspecialchars($selectorId) . '" data-target-de="' . htmlspecialchars($deId) . '">';

        foreach ($selectorCategory['options'] as $idx => $opt) {
            $selected = $idx === 0 ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($opt['id']) . '"' . $selected . '>' . htmlspecialchars($this->prettyLabel($opt['name'])) . '</option>';
        }

        $html .= '</select>';
        $html .= '</div>';

        foreach ($selectorCategory['options'] as $idx => $opt) {
            $paneClass = $idx === 0 ? ' category-pane active' : ' category-pane';
            $html .= '<div class="' . $paneClass . '" data-de-pane="' . htmlspecialchars($deId) . '" data-selector-option="' . htmlspecialchars($opt['id']) . '">';

            if (count($remainingCategories) === 1) {
                // Effectively 2D after selector -> rows
                $single = $remainingCategories[0];
                $html .= '<div class="table-responsive matrix-scroll"><table class="table table-sm table-bordered nested-category-table mobile-matrix">';
                $html .= '<thead class="table-light"><tr><th class="sticky-col" style="width:45%;">' . htmlspecialchars($this->prettyLabel($single['name'])) . '</th><th>Value</th></tr></thead><tbody>';
                foreach ($single['options'] as $ropt) {
                    $cocId = $this->findComboUidByOptionIds($meta['lookup'], [$opt['id'], $ropt['id']]);
                    if (!$cocId) {
                        continue;
                    }
                    $fieldId = $this->generateFieldId($deId, $cocId);
                    $html .= '<tr><td class="sticky-col">' . htmlspecialchars($this->prettyLabel($ropt['name'])) . '</td><td>' . $this->renderInput($de, $fieldId, $cocId) . '</td></tr>';
                }
                $html .= '</tbody></table></div>';
            } elseif (count($remainingCategories) >= 2) {
                // Metadata matrix path:
                // row axis = first remaining category
                // column axis = cartesian product of all other remaining categories
                $rowCat = $remainingCategories[0];
                $colCats = array_slice($remainingCategories, 1);
                $columns = $this->buildCartesianColumns($colCats);
                $rowCount = count($rowCat['options']);
                $colCount = count($columns);

                if ($colCount >= 2 && $colCount <= 12 && $rowCount >= 2) {
                    $html .= '<div class="table-responsive matrix-scroll"><table class="table table-bordered table-hover category-grid mobile-matrix">';
                    $html .= '<thead class="table-light"><tr><th class="sticky-col" style="width:28%;">' . htmlspecialchars($this->prettyLabel($rowCat['name'])) . '</th>';
                    foreach ($columns as $col) {
                        $html .= '<th>' . htmlspecialchars($this->prettyLabel($col['label'])) . '</th>';
                    }
                    $html .= '</tr></thead><tbody>';

                    foreach ($rowCat['options'] as $rowOpt) {
                        $html .= '<tr><td class="data-element-label sticky-col"><strong>' . htmlspecialchars($this->prettyLabel($rowOpt['name'])) . '</strong></td>';
                        foreach ($columns as $col) {
                            $optionIds = array_merge([$opt['id'], $rowOpt['id']], $col['option_ids']);
                            $cocId = $this->findComboUidByOptionIds($meta['lookup'], $optionIds);
                            $html .= '<td class="input-cell">';
                            if ($cocId) {
                                $fieldId = $this->generateFieldId($deId, $cocId);
                                $html .= $this->renderInput($de, $fieldId, $cocId);
                            } else {
                                $html .= '<span class="text-muted">-</span>';
                            }
                            $html .= '</td>';
                        }
                        $html .= '</tr>';
                    }

                    $html .= '</tbody></table></div>';
                } else {
                    // Too complex -> flattened rows
                    foreach ($meta['combos'] as $coc) {
                        $cocId = $coc['id'] ?? null;
                        if (!$cocId) {
                            continue;
                        }
                        $optIds = array_map(function($o) {
                            return $o['id'] ?? '';
                        }, $coc['categoryOptions'] ?? []);
                        if (!in_array($opt['id'], $optIds, true)) {
                            continue;
                        }
                        $pathParts = [];
                        foreach (($coc['categoryOptions'] ?? []) as $co) {
                            $pathParts[] = $co['displayName'] ?? ($co['name'] ?? ($co['id'] ?? 'Option'));
                        }
                        $pathLabel = $this->prettyLabel(join(' / ', $pathParts));
                        $fieldId = $this->generateFieldId($deId, $cocId);
                        $html .= '<div class="row mb-2"><div class="col-7"><small class="text-muted">' . htmlspecialchars($pathLabel) . '</small></div><div class="col-5">' . $this->renderInput($de, $fieldId, $cocId) . '</div></div>';
                    }
                }
            }

            $html .= '</div>';
        }

        $html .= '</div>';
        return $html;
    }

    private function buildCartesianColumns($categories) {
        if (empty($categories)) {
            return [];
        }
        $result = [['label' => '', 'option_ids' => []]];
        foreach ($categories as $cat) {
            $next = [];
            foreach ($result as $base) {
                foreach (($cat['options'] ?? []) as $opt) {
                    $label = trim(($base['label'] !== '' ? $base['label'] . ' / ' : '') . ($opt['name'] ?? ''));
                    $ids = $base['option_ids'];
                    $ids[] = $opt['id'] ?? '';
                    $next[] = ['label' => $label, 'option_ids' => $ids];
                }
            }
            $result = $next;
        }
        return $result;
    }

    private function buildParsedLabelMatrix($dataElements) {
        $splitters = [' - ', ':', '|', '-', '/'];
        foreach ($splitters as $splitter) {
            $rows = [];
            $cols = [];
            $cells = [];
            $elementsUsed = [];
            foreach ($dataElements as $de) {
                $comboMeta = $this->extractCategoryComboMetadata($de);
                if (count($comboMeta['categories']) > 0) {
                    continue; // only default/no-metadata elements
                }
                $name = trim((string)($de['displayName'] ?? $de['name'] ?? ''));
                if ($name === '' || strpos($name, $splitter) === false) {
                    continue;
                }
                $parts = array_map('trim', explode($splitter, $name, 2));
                if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
                    continue;
                }
                $row = $parts[0];
                $col = $parts[1];
                if (!in_array($row, $rows, true)) $rows[] = $row;
                if (!in_array($col, $cols, true)) $cols[] = $col;
                $cells[$row . '||' . $col] = $de;
                $elementsUsed[] = $de;
            }
            if (count($rows) >= 2 && count($cols) >= 2 && count($cells) >= max(4, (int)ceil((count($rows) * count($cols)) * 0.35))) {
                return [
                    'rows' => $rows,
                    'cols' => $cols,
                    'cells' => $cells,
                    'elements' => $elementsUsed,
                    'splitter' => $splitter
                ];
            }
        }
        return null;
    }

    private function renderParsedLabelMatrix($matrix) {
        $html = '<div class="data-element-group mb-3">';
        $html .= '<label class="form-label"><strong>Category Matrix</strong></label>';
        $html .= '<div class="table-responsive matrix-scroll"><table class="table table-bordered table-hover category-grid mobile-matrix">';
        $html .= '<thead class="table-light"><tr><th class="sticky-col" style="width:28%;">Category</th>';
        foreach ($matrix['cols'] as $col) {
            $html .= '<th>' . htmlspecialchars($this->prettyLabel($col)) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($matrix['rows'] as $row) {
            $html .= '<tr><td class="data-element-label sticky-col"><strong>' . htmlspecialchars($this->prettyLabel($row)) . '</strong></td>';
            foreach ($matrix['cols'] as $col) {
                $de = $matrix['cells'][$row . '||' . $col] ?? null;
                $html .= '<td class="input-cell">';
                if ($de) {
                    $fieldId = $this->generateFieldId($de['id']);
                    $html .= $this->renderInput($de, $fieldId);
                } else {
                    $html .= '<span class="text-muted">-</span>';
                }
                $html .= '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div></div>';
        return $html;
    }

    private function inferTwoDimFromComboNames($combos) {
        if (count($combos) < 4) return null;
        $splitters = ['/', ' - ', '-', ',', ':'];
        foreach ($splitters as $splitter) {
            $pairs = [];
            foreach ($combos as $coc) {
                $name = trim((string)($coc['displayName'] ?? ($coc['name'] ?? '')));
                if ($name === '' || strpos($name, $splitter) === false) {
                    $pairs = [];
                    break;
                }
                $parts = array_map('trim', explode($splitter, $name));
                if (count($parts) < 2) {
                    $pairs = [];
                    break;
                }
                $row = $parts[0];
                $col = $parts[1];
                $pairs[] = ['row' => $row, 'col' => $col, 'coc_id' => $coc['id'] ?? null];
            }
            if (empty($pairs)) continue;

            $rows = [];
            $cols = [];
            foreach ($pairs as $p) {
                if (!in_array($p['row'], $rows, true)) $rows[] = $p['row'];
                if (!in_array($p['col'], $cols, true)) $cols[] = $p['col'];
            }
            if (count($rows) >= 2 && count($cols) >= 2) {
                return [
                    'pairs' => $pairs,
                    'rows' => $rows,
                    'cols' => $cols,
                    'row_count' => count($rows),
                    'col_count' => count($cols)
                ];
            }
        }
        return null;
    }

    private function renderInferredTwoDimMatrix($de, $inferred) {
        $deId = $de['id'];
        $lookup = [];
        foreach ($inferred['pairs'] as $p) {
            if (!empty($p['coc_id'])) {
                $lookup[$p['row'] . '||' . $p['col']] = $p['coc_id'];
            }
        }
        $rowsLookLikeYear = $this->labelsLookLikeYears($inferred['rows']);
        $colsLookLikeYear = $this->labelsLookLikeYears($inferred['cols']);

        if ($colsLookLikeYear && !$rowsLookLikeYear) {
            $selectorValues = $inferred['cols'];
            $headerValues = $inferred['rows'];
            $useColAsSelector = true;
            $selectorLabel = 'Year / Class';
        } else {
            $selectorValues = $inferred['rows'];
            $headerValues = $inferred['cols'];
            $useColAsSelector = false;
            $selectorLabel = $rowsLookLikeYear ? 'Year / Class' : 'Category';
        }

        $selectorId = 'selector-inf-' . $deId;
        $html = '<div class="mb-2">';
        $html .= '<label class="form-label" for="' . htmlspecialchars($selectorId) . '">' . htmlspecialchars($selectorLabel) . '</label>';
        $html .= '<select class="form-select category-primary-selector" id="' . htmlspecialchars($selectorId) . '" data-target-de="' . htmlspecialchars($deId) . '">';
        foreach ($selectorValues as $idx => $value) {
            $key = 'sel-' . $idx;
            $selected = $idx === 0 ? ' selected' : '';
            $html .= '<option value="' . htmlspecialchars($key) . '"' . $selected . '>' . htmlspecialchars($this->prettyLabel($value)) . '</option>';
        }
        $html .= '</select></div>';

        foreach ($selectorValues as $idx => $selectedValue) {
            $rowKey = 'sel-' . $idx;
            $paneClass = $idx === 0 ? ' category-pane active' : ' category-pane';
            $html .= '<div class="' . $paneClass . '" data-de-pane="' . htmlspecialchars($deId) . '" data-selector-option="' . htmlspecialchars($rowKey) . '">';
            $html .= '<div class="table-responsive matrix-scroll"><table class="table table-bordered table-hover category-grid mobile-matrix">';
            $html .= '<thead class="table-light"><tr><th class="sticky-col" style="width:28%;">' . htmlspecialchars($this->prettyLabel($selectedValue)) . '</th>';
            foreach ($headerValues as $header) {
                $html .= '<th>' . htmlspecialchars($this->prettyLabel($header)) . '</th>';
            }
            $html .= '</tr></thead><tbody><tr>';
            $html .= '<td class="data-element-label sticky-col"><strong>' . htmlspecialchars($this->prettyLabel($selectedValue)) . '</strong></td>';
            foreach ($headerValues as $header) {
                $row = $useColAsSelector ? $header : $selectedValue;
                $col = $useColAsSelector ? $selectedValue : $header;
                $cocId = $lookup[$row . '||' . $col] ?? null;
                $html .= '<td class="input-cell">';
                if ($cocId) {
                    $fieldId = $this->generateFieldId($deId, $cocId);
                    $html .= $this->renderInput($de, $fieldId, $cocId);
                } else {
                    $html .= '<span class="text-muted">-</span>';
                }
                $html .= '</td>';
            }
            $html .= '</tr></tbody></table></div>';
            $html .= '</div>';
        }
        return $html;
    }

    private function labelsLookLikeYears($labels) {
        if (empty($labels)) return false;
        $matches = 0;
        foreach ($labels as $label) {
            $t = strtolower(trim((string)$label));
            if ($t === '') continue;
            if (preg_match('/\b(grade|class|year|senior|primary)\b/', $t) ||
                preg_match('/\b(19|20)\d{2}\b/', $t) ||
                preg_match('/\b[1-9](st|nd|rd|th)?\b/', $t)) {
                $matches++;
            }
        }
        return $matches >= max(2, (int)ceil(count($labels) * 0.4));
    }
}
?>
