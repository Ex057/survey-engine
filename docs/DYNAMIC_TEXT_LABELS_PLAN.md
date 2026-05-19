# Dynamic Text Labels System - Implementation Plan

## Executive Summary

This plan details a comprehensive approach to implement a dynamic text labels system that allows administrators to customize hardcoded text across dataset forms, regular surveys, and tracker programs through preview interfaces.

## Current State

### Hardcoded Text Examples
- **Location labels:** "Locations:", "School:", "Facility/Organization Unit Selection"
- **Search placeholders:** "Type to search for a facility...", "Search for a school..."
- **Buttons:** "Submit", "Submit Data", "Change", "Load Existing Data"
- **Messages:** "Please select both organization unit and period"
- **Instructions:** "Type at least 2 characters to search"

### Existing Preview-Edit Patterns
- **dataset_preview.php:** Tabs for settings with live preview iframe, saves via AJAX
- **preview_form.php:** Accordions for survey settings with real-time preview
- **tracker_preview.php:** Similar pattern for tracker programs

## Solution Architecture

### Database Schema

**New Table: `form_text_labels`**

```sql
CREATE TABLE IF NOT EXISTS `form_text_labels` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `survey_id` INT NOT NULL,
  `label_key` VARCHAR(100) NOT NULL,
  `label_category` ENUM('ui', 'field', 'button', 'message', 'instruction') DEFAULT 'ui',
  `default_text` TEXT NOT NULL,
  `custom_text` TEXT DEFAULT NULL,
  `scope` ENUM('global', 'survey', 'form_type') DEFAULT 'survey',
  `form_type` ENUM('dataset', 'survey', 'tracker', 'all') DEFAULT 'all',
  `description` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) DEFAULT 1,
  `display_order` INT DEFAULT 0,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `survey_label_unique` (`survey_id`, `label_key`),
  KEY `idx_label_key` (`label_key`),
  KEY `idx_scope` (`scope`),
  KEY `idx_form_type` (`form_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### Label Key Naming Convention

Format: `{section}_{component}_{type}`

**Examples:**
- `facility_section_title` → "Facility/Organization Unit Selection"
- `facility_search_placeholder` → "Type to search for a facility..."
- `facility_change_button` → "Change"
- `period_section_title` → "Reporting Period"
- `submit_button_text` → "Submit Data"
- `error_missing_orgunit` → "Please select organization unit"
- `success_submission` → "Data submitted successfully!"

### Fallback Hierarchy

1. **Survey-specific custom text** (`survey_id` = actual ID, `custom_text` set)
2. **Global custom text** (`survey_id` = 0, `custom_text` set)
3. **Default text** (`default_text` field)

## Backend Implementation

### Core Helper Class: `/fbs/admin/includes/TextLabelManager.php`

```php
class TextLabelManager {
    private $pdo;
    private $cache = [];

    // Get label with fallback hierarchy
    public function getLabel($labelKey, $surveyId, $formType = 'all');

    // Get all labels for editing
    public function getAllLabels($surveyId, $formType = null);

    // Save custom label
    public function saveLabel($surveyId, $labelKey, $customText, $formType = 'all');

    // Reset to default
    public function resetLabel($surveyId, $labelKey);

    // Get as JSON for frontend
    public function getLabelsJSON($surveyId, $formType = null);
}
```

### API Endpoints

**`/fbs/admin/ajax_save_form_labels.php`** - Save custom labels
```json
POST: {
  "survey_id": 123,
  "labels": {
    "facility_section_title": "School Selection",
    "submit_button_text": "Submit Report"
  }
}
```

**`/fbs/admin/ajax_get_form_labels.php`** - Fetch labels for editing
```json
GET: ?survey_id=123&form_type=dataset
Response: {
  "success": true,
  "labels": { ... }
}
```

## Frontend Implementation

### Preview Interface Changes

#### Dataset Preview (`dataset_preview.php`)

Add new tab: **"Text Labels"**

```html
<div id="LabelsTab" class="tabcontent">
  <div class="mb-3">
    <label>Filter by Category:</label>
    <select id="labelCategoryFilter">
      <option value="all">All Categories</option>
      <option value="ui">UI Labels</option>
      <option value="field">Field Labels</option>
      <option value="button">Buttons</option>
      <option value="message">Messages</option>
    </select>
  </div>

  <div id="labelsList">
    <!-- Dynamically populated label editors -->
  </div>
</div>
```

**Features:**
- Group labels by category
- Inline editing with real-time save
- Show default vs custom indicator
- Reset to default button
- Live preview updates on save

#### Similar Changes for:
- `preview_form.php` (regular surveys)
- `tracker_preview.php` (tracker programs)

### Public Form Integration

#### Dataset Form (`dataset_form.php`)

**Step 1: Load labels at top**
```php
require_once '../admin/includes/TextLabelManager.php';

$labelManager = new TextLabelManager($pdo);
$formLabels = [];
$allLabels = $labelManager->getAllLabels($surveyId, 'dataset');
foreach ($allLabels as $key => $label) {
    $formLabels[$key] = $label['custom_text'] ?? $label['default_text'];
}

function getLabel($key, $default = '') {
    global $formLabels;
    return $formLabels[$key] ?? $default;
}
```

**Step 2: Replace hardcoded text**
```php
<!-- Before -->
<label>Search Facility</label>

<!-- After -->
<label><?= getLabel('facility_search_label', 'Search Facility') ?></label>
```

**Step 3: Pass to JavaScript**
```php
<script>
const formLabels = <?= json_encode($formLabels) ?>;
function getLabel(key, defaultValue = '') {
    return formLabels[key] || defaultValue;
}
</script>
```

**Step 4: Use in JS messages**
```javascript
if (!orgUnit) {
    alert(getLabel('error_missing_orgunit', 'Please select organization unit'));
}
```

## Default Labels (Seed Data)

### Facility/Location Labels
- `facility_section_title` → "Facility/Organization Unit Selection"
- `facility_search_label` → "Search Facility"
- `facility_search_placeholder` → "Type to search for a facility..."
- `facility_select_label` → "Select Your Facility"
- `facility_selected_label` → "Selected Facility:"
- `facility_change_button` → "Change"

### Period Labels (Dataset)
- `period_section_title` → "Reporting Period"
- `period_select_label` → "Select Period"
- `period_load_existing_button` → "Load Existing Data"

### Data Entry Labels
- `data_entry_section_title` → "Data Entry"

### Buttons
- `submit_button_text` → "Submit Data"
- `submit_loading_text` → "Submitting data to DHIS2..."

### Error Messages
- `error_missing_fields` → "Please fill in all required fields."
- `error_missing_orgunit` → "Please select organization unit."
- `error_missing_period` → "Please select period."

### Success Messages
- `success_submission` → "Data submitted successfully!"

### Instructions
- `validation_search_min_chars` → "Type at least 2 characters to search"

### Survey-Specific
- `location_label_school` → "Schools:"
- `location_label_facility` → "Facility:"
- `location_label_generic` → "Locations:"

## Implementation Phases

### Phase 1: Foundation (Week 1)
- [ ] Create `form_text_labels` table
- [ ] Seed default labels
- [ ] Create `TextLabelManager.php`
- [ ] Create AJAX endpoints
- [ ] Unit tests

### Phase 2: Dataset Forms (Week 2)
- [ ] Update `dataset_preview.php` - add Labels tab
- [ ] Add JavaScript for label editing
- [ ] Update `dataset_form.php` - integrate TextLabelManager
- [ ] Replace hardcoded text with `getLabel()`
- [ ] End-to-end testing

### Phase 3: Regular Surveys (Week 3)
- [ ] Update `preview_form.php` - add Labels section
- [ ] Update `survey_page.php` - integrate labels
- [ ] Replace hardcoded text
- [ ] Testing

### Phase 4: Tracker Programs (Week 4)
- [ ] Update `tracker_preview.php` - add Labels section
- [ ] Update `tracker_program_form.php` - integrate labels
- [ ] Replace hardcoded text
- [ ] Testing

### Phase 5: Polish (Week 5)
- [ ] Label search/filter
- [ ] Bulk edit functionality
- [ ] Export/import labels
- [ ] Documentation
- [ ] Performance optimization
- [ ] Final QA

## Critical Files to Modify

1. **`/db/add_form_text_labels.sql`** - Database schema
2. **`/fbs/admin/includes/TextLabelManager.php`** - Core logic
3. **`/fbs/admin/ajax_save_form_labels.php`** - Save API
4. **`/fbs/admin/ajax_get_form_labels.php`** - Fetch API
5. **`/fbs/admin/dataset_preview.php`** - Preview UI
6. **`/fbs/public/dataset_form.php`** - Public form
7. **`/fbs/admin/preview_form.php`** - Survey preview
8. **`/fbs/public/survey_page.php`** - Survey form
9. **`/fbs/admin/tracker_preview.php`** - Tracker preview
10. **`/fbs/public/tracker_program_form.php`** - Tracker form

## Benefits

### For Administrators
- Customize labels to match local terminology
- Support multiple languages/dialects
- Match organizational branding
- A/B test different wording
- No code changes needed

### For End Users
- Familiar terminology
- Better user experience
- Clearer instructions
- Contextually appropriate language

### For Developers
- Centralized text management
- Easy to maintain
- DRY principle (Don't Repeat Yourself)
- Future-proof for i18n

## Best Practices

### Label Customization
- Keep labels concise and clear
- Maintain consistency across similar fields
- Test with target users
- Use familiar terminology

### Performance
- Cache labels in memory
- Load once per page
- Use database indexes
- Consider Redis for high traffic

### Security
- Sanitize all custom text output
- Use prepared statements
- Validate user permissions
- XSS prevention

## Future Enhancements

1. **Multi-language Support**
   - Add `language_code` column
   - Integrate with existing `default_text` table
   - Language switcher in forms

2. **Label Versioning**
   - Track changes over time
   - Rollback capability
   - Audit trail

3. **Label Templates**
   - Pre-configured sets (Healthcare, Education, etc.)
   - One-click apply templates
   - Share templates between surveys

4. **Conditional Labels**
   - Show different text based on user role
   - Location-based labels
   - Time-based labels

5. **Bulk Operations**
   - Export all labels to CSV
   - Import from spreadsheet
   - Find and replace across labels
   - Copy labels between surveys

## Testing Checklist

### Backend
- [ ] Labels load correctly for survey
- [ ] Fallback hierarchy works (survey → global → default)
- [ ] Save updates existing or creates new
- [ ] Reset clears customization
- [ ] Cascade delete on survey deletion

### Preview Interface
- [ ] Labels tab loads all labels
- [ ] Category filter works
- [ ] Save triggers preview refresh
- [ ] Reset button works
- [ ] Customized labels show badge

### Public Forms
- [ ] All custom labels display
- [ ] JavaScript messages use custom text
- [ ] Placeholders customized
- [ ] Button text customized
- [ ] Error messages customized

### Performance
- [ ] Page load time acceptable
- [ ] No N+1 query issues
- [ ] Caching reduces DB calls
- [ ] Preview updates quickly

## Migration Strategy

1. Create new table
2. Seed with defaults (survey_id = 0)
3. Deploy backend code (TextLabelManager)
4. Deploy API endpoints
5. Deploy preview interfaces
6. Deploy public form integration
7. Test thoroughly
8. Document for users
9. Optional: Migrate existing `survey_settings` text fields

## Backwards Compatibility

- Keep existing `survey_settings` customization fields
- New system works alongside old system
- Gradual migration, no breaking changes
- Fallback to defaults if labels not found

## Conclusion

This plan provides a production-ready approach to implementing dynamic text labels across the entire application. The solution leverages existing patterns, maintains backwards compatibility, and provides excellent UX with minimal performance impact.

**Key Success Factors:**
- Phased implementation reduces risk
- Clear separation of concerns
- Comprehensive testing at each stage
- Excellent caching strategy
- Intuitive admin interface
- Graceful error handling

**Next Steps:**
1. Review and approve this plan
2. Create database migration script
3. Implement TextLabelManager class
4. Start with dataset forms (highest impact)
5. Iterate based on feedback
