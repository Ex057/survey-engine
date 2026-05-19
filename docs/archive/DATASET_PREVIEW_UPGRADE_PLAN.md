# Dataset Preview Upgrade Plan

## Issue
The current dataset_preview.php is too basic and lacks:
1. Database column `show_facility_section` causing save errors
2. Live preview pane showing how the form will look
3. Data elements display from DHIS2
4. Re-arrangement capabilities for data elements
5. Dynamic updates when settings change

## Solution

### 1. Database Fix (IMMEDIATE)
Run this SQL in phpMyAdmin:

```sql
ALTER TABLE dataset_layout_settings
ADD COLUMN IF NOT EXISTS show_facility_section TINYINT(1) DEFAULT 1 AFTER layout_type,
ADD COLUMN IF NOT EXISTS selected_instance_key VARCHAR(64) DEFAULT NULL AFTER show_facility_section,
ADD COLUMN IF NOT EXISTS selected_hierarchy_level INT DEFAULT NULL AFTER selected_instance_key;

UPDATE dataset_layout_settings
SET show_facility_section = 1
WHERE show_facility_section IS NULL;
```

### 2. New Dataset Preview Architecture

#### Layout Structure (Like preview_form.php)
```
┌─────────────────────────────────────────────────────────┐
│                    HEADER (Dataset Name)                 │
├──────────────────────┬──────────────────────────────────┤
│                      │                                  │
│   SETTINGS PANEL     │        LIVE PREVIEW PANE         │
│   (Left Sidebar)     │        (Right Side)              │
│                      │                                  │
│  - Flag Bar          │    Shows actual form with:       │
│  - Facility Section  │    - Flag bar (if enabled)       │
│  - Instance Filter   │    - Facility search             │
│  - Level Filter      │    - Period selector             │
│  - Data Elements     │    - Data elements (draggable)   │
│    * Toggle show/hide│    - Submit button               │
│    * Drag to reorder │                                  │
│                      │                                  │
└──────────────────────┴──────────────────────────────────┘
```

#### Features to Implement

**Settings Panel:**
- ✅ Flag Bar Settings (already exists)
  - Toggle show/hide
  - Color pickers
  - Live preview update

- ✅ Facility Section (already exists)
  - Toggle show/hide
  - Instance filter dropdown
  - Level filter dropdown

- 🆕 Data Elements Section (NEW!)
  - List all data elements from DHIS2 dataset
  - Checkbox to show/hide each element
  - Drag handles to reorder
  - Save order to database
  - Category combo display (if applicable)

**Live Preview Pane:**
- Real iframe or div showing actual dataset_form.php
- Updates in real-time as settings change
- Shows exact appearance user will see

**JavaScript Functionality:**
- Fetch data elements from DHIS2 on page load
- Sortable.js or HTML5 drag-and-drop for reordering
- AJAX save for settings changes
- Real-time preview updates

### 3. Database Schema for Data Element Settings

```sql
CREATE TABLE IF NOT EXISTS dataset_dataelement_settings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    survey_id BIGINT NOT NULL,
    data_element_id VARCHAR(11) NOT NULL,
    data_element_name VARCHAR(255) NOT NULL,
    display_order INT DEFAULT 0,
    is_visible TINYINT(1) DEFAULT 1,
    is_required TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (survey_id) REFERENCES survey(id) ON DELETE CASCADE,
    UNIQUE KEY unique_survey_element (survey_id, data_element_id),
    INDEX idx_survey_order (survey_id, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 4. File Changes Required

#### A. dataset_preview.php
- Split into left panel (settings) and right panel (preview)
- Add accordion sections for each setting group
- Fetch and display DHIS2 data elements
- Implement drag-and-drop reordering
- Real-time preview updates via JavaScript

#### B. dataset_form.php
- Respect data element visibility settings
- Respect data element order from settings
- Only show elements marked as visible

#### C. New Files Needed
- `fbs/admin/ajax_save_dataelement_settings.php` - Save element visibility & order
- `fbs/admin/ajax_get_dataset_elements.php` - Fetch elements from DHIS2

### 5. Implementation Steps

1. ✅ Fix database columns (run SQL above)
2. Create data element settings table
3. Create AJAX endpoints for:
   - Fetching data elements from DHIS2
   - Saving element visibility
   - Saving element order
4. Rebuild dataset_preview.php with:
   - Two-panel layout
   - Settings accordion on left
   - Live preview iframe on right
5. Update dataset_form.php to:
   - Load element settings from database
   - Apply visibility filters
   - Apply custom ordering
6. Add JavaScript for:
   - Drag-and-drop (using Sortable.js)
   - Live preview updates
   - Settings persistence

### 6. Quick Win Alternative

If full rebuild is too complex, here's a simpler approach:

**Minimal Version:**
1. Fix database columns (DONE - SQL provided above)
2. Keep current settings form
3. Add a "Data Elements" section that lists elements with:
   - Checkboxes for visibility
   - Up/down arrows for reordering
4. Save to simple JSON column in dataset_layout_settings
5. Update dataset_form.php to read and apply JSON settings

## Files to Provide

1. `db/add_dataset_columns.sql` - ✅ Created
2. Updated `dataset_preview.php` with better UX
3. `ajax_get_dataset_elements.php` for fetching elements
4. Updated `dataset_form.php` to respect settings

## Next Action

User should:
1. Run the SQL migration in phpMyAdmin first
2. Then we can proceed with upgrading the preview system
