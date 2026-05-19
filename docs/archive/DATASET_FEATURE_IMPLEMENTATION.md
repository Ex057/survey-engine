# Dataset Feature Implementation - Complete Guide

## Overview
A fully dynamic DHIS2 aggregate dataset preview and management system has been implemented. The system allows administrators to:
- **Visualize** the dataset form in real-time
- **Manage data element visibility** (show/hide individual elements)
- **Reorder data elements** using drag-and-drop
- **Configure form appearance** (flag bar colors, facility filters, layout)
- **Preview changes live** before publishing to users

## What Was Built

### 1. Database Schema
Two key database components:

#### `dataset_layout_settings` Table (Enhanced)
New columns added:
- `show_facility_section` - Toggle facility search section
- `selected_instance_key` - Filter facilities by DHIS2 instance
- `selected_hierarchy_level` - Filter facilities by organization level

#### `dataset_dataelement_settings` Table (New)
Stores per-element configuration:
- `data_element_id` - DHIS2 data element UID
- `data_element_name` - Element name
- `section_id` - Parent section UID (if applicable)
- `display_order` - Custom ordering (0, 1, 2, ...)
- `is_visible` - Show/hide toggle
- `is_required` - Mark as required field

### 2. Backend Files

#### AJAX Endpoints
**`/fbs/admin/ajax_get_dataset_elements.php`**
- Fetches dataset structure from DHIS2 API
- Retrieves sections and data elements
- Merges with existing database settings
- Returns organized JSON with visibility and order info

**`/fbs/admin/ajax_save_dataelement_settings.php`**
- Saves data element configuration
- Handles batch updates via transaction
- Stores visibility, order, and metadata

#### Preview System
**`/fbs/admin/dataset_preview.php` (Rebuilt)**
- **Two-panel layout** matching [preview_form.php](fbs/admin/preview_form.php) pattern
- **Left panel**: Settings accordion with:
  - Flag bar color picker
  - Facility section filters
  - **Data elements list** with:
    - Drag-and-drop reordering (Sortable.js)
    - Visibility checkboxes
    - Section grouping
    - Value type badges
  - Layout settings
  - Save button
- **Right panel**: Live preview iframe showing actual form
- **Auto-load**: Data elements fetch automatically from DHIS2
- **Real-time updates**: Changes apply immediately

### 3. Frontend Improvements

#### `dataset_form.php` Enhancements
**Before**: All data elements shown in API order
**After**:
- ✅ Respects visibility settings (hidden elements not shown)
- ✅ Applies custom ordering from database
- ✅ **Table layout for category combinations** (cleaner appearance)
- ✅ Required field indicators
- ✅ Improved visual hierarchy

**Example**: Category combo rendering now uses tables:
```
┌──────────────────────────────────────────┐
│ Category            │ Value              │
├──────────────────────────────────────────┤
│ Male, <1 year       │ [input field]      │
│ Male, 1-4 years     │ [input field]      │
│ Female, <1 year     │ [input field]      │
│ Female, 1-4 years   │ [input field]      │
└──────────────────────────────────────────┘
```

## Installation Steps

### Step 1: Run Database Migration
**IMPORTANT**: You must run this SQL before using the new system.

Open **phpMyAdmin** and execute:
```bash
/Applications/MAMP/htdocs/survey-engine/db/dataset_complete_migration.sql
```

This script:
1. Adds 3 new columns to `dataset_layout_settings`
2. Creates `dataset_dataelement_settings` table
3. Sets default values for existing datasets

### Step 2: Verify Installation
After running the SQL, verify:
```sql
-- Check new columns exist
SHOW COLUMNS FROM dataset_layout_settings;

-- Check new table exists
DESCRIBE dataset_dataelement_settings;
```

### Step 3: Access the New Preview System
1. Go to **Admin Panel** → **Surveys**
2. Click on any **aggregate dataset** survey
3. You'll be automatically redirected to the new [dataset_preview.php](fbs/admin/dataset_preview.php)

## How to Use

### Managing Data Elements

1. **Load Elements**: Click "Load from DHIS2" to fetch data elements
2. **Toggle Visibility**: Check/uncheck boxes to show/hide elements
3. **Reorder**: Drag elements by the grip icon (⋮⋮) to reorder
4. **Section Grouping**: Elements are automatically grouped by DHIS2 sections
5. **Save**: Click "Save All Settings" to apply changes

### Quick Actions
- **Select All**: Show all data elements
- **Deselect All**: Hide all elements
- **Refresh Preview**: Reload the live preview iframe

### Form Settings
- **Flag Bar**: Customize Uganda flag colors
- **Facility Section**:
  - Toggle facility search on/off
  - Filter by DHIS2 instance (e.g., "EMIS", "DHIS2")
  - Filter by hierarchy level (Level 1-7)
- **Layout**: Choose horizontal or vertical image layout

## Technical Details

### DHIS2 API Integration
The system uses DHIS2's metadata structure:

**Endpoint Used**:
```
/api/dataSets/{datasetUid}.json?fields=id,name,sections[id,name,sortOrder,dataElements[id,name,code,valueType,categoryCombo[...]]]
```

**Key Concepts**:
- **Sections**: Logical groupings of data elements (optional)
- **disableDataElementAutoGroup**: Controls auto-grouping by category combo
- **Category Combinations**: Disaggregation dimensions (e.g., age/sex)
- **Value Types**: NUMBER, INTEGER, BOOLEAN, DATE, TEXT, etc.

### Rendering Logic
1. **Fetch from DHIS2**: Get dataset structure via API
2. **Merge with Database**: Apply saved visibility/order settings
3. **Filter**: Remove hidden elements
4. **Sort**: Apply custom ordering
5. **Render**: Display with proper formatting

### Value Type Handling
Supported DHIS2 value types:
- `NUMBER` / `INTEGER` → `<input type="number">`
- `BOOLEAN` → `<select>` with Yes/No
- `DATE` → `<input type="date">`
- `PERCENTAGE` → `<input type="number" max="100">`
- `LONG_TEXT` → `<textarea>`
- `TEXT` → `<input type="text">`

### Category Combination Rendering
**Simple Element** (no categories):
```html
<input type="text" id="dataElementUid" name="dataElementUid">
```

**Disaggregated Element** (with categories):
```html
<table class="table">
  <tr>
    <td>Male, <1 year</td>
    <td><input id="dataElementUid_cocId1"></td>
  </tr>
  <tr>
    <td>Female, <1 year</td>
    <td><input id="dataElementUid_cocId2"></td>
  </tr>
</table>
```

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                     DATASET PREVIEW SYSTEM                  │
├───────────────────────────┬─────────────────────────────────┤
│    LEFT PANEL             │      RIGHT PANEL                │
│    (Settings)             │      (Live Preview)             │
│                           │                                 │
│  ┌─────────────────────┐ │  ┌───────────────────────────┐ │
│  │ Flag Bar Settings   │ │  │                           │ │
│  └─────────────────────┘ │  │    <iframe>               │ │
│                           │  │                           │ │
│  ┌─────────────────────┐ │  │    /d/{surveyId}          │ │
│  │ Facility Settings   │ │  │                           │ │
│  │  - Instance Filter  │ │  │    Real-time preview      │ │
│  │  - Level Filter     │ │  │    of dataset_form.php    │ │
│  └─────────────────────┘ │  │                           │ │
│                           │  │                           │ │
│  ┌─────────────────────┐ │  │                           │ │
│  │ Data Elements       │ │  │                           │ │
│  │ ┌─────────────────┐ │ │  │                           │ │
│  │ │ ⋮⋮ Element 1 ☑  │ │ │  │                           │ │
│  │ │ ⋮⋮ Element 2 ☑  │ │ │  │                           │ │
│  │ │ ⋮⋮ Element 3 ☐  │ │ │  │                           │ │
│  │ └─────────────────┘ │ │  └───────────────────────────┘ │
│  │ (Drag to reorder)   │ │                                 │
│  └─────────────────────┘ │                                 │
│                           │                                 │
│  [Save All Settings]      │     [Refresh Preview]           │
└───────────────────────────┴─────────────────────────────────┘
         │                               │
         │                               │
         ▼                               ▼
  ┌──────────────────┐          ┌────────────────────┐
  │  Save Settings   │          │   dataset_form.php │
  │  via AJAX        │          │   reads settings   │
  └──────────────────┘          └────────────────────┘
         │                               │
         │                               │
         ▼                               ▼
  ┌──────────────────────────────────────────────┐
  │           MySQL Database                      │
  │  ┌────────────────────────────────────────┐  │
  │  │  dataset_layout_settings               │  │
  │  │  - show_flag_bar                       │  │
  │  │  - flag_colors                         │  │
  │  │  - show_facility_section               │  │
  │  │  - selected_instance_key               │  │
  │  │  - selected_hierarchy_level            │  │
  │  └────────────────────────────────────────┘  │
  │                                               │
  │  ┌────────────────────────────────────────┐  │
  │  │  dataset_dataelement_settings (NEW!)   │  │
  │  │  - data_element_id                     │  │
  │  │  - display_order                       │  │
  │  │  - is_visible                          │  │
  │  │  - section_id                          │  │
  │  └────────────────────────────────────────┘  │
  └──────────────────────────────────────────────┘
```

## Data Flow

### Admin Workflow (Configuration)
```
1. Admin opens dataset_preview.php
        ↓
2. Click "Load from DHIS2"
        ↓
3. ajax_get_dataset_elements.php:
   - Fetches from DHIS2 API
   - Merges with database settings
   - Returns organized JSON
        ↓
4. JavaScript renders sortable list
        ↓
5. Admin reorders/toggles visibility
        ↓
6. Click "Save All Settings"
        ↓
7. Two parallel AJAX calls:
   a) save_dataset_settings.php (flag bar, facility settings)
   b) ajax_save_dataelement_settings.php (element visibility/order)
        ↓
8. Database updated
        ↓
9. Preview iframe refreshed automatically
```

### User Workflow (Data Entry)
```
1. User opens /d/{surveyId}
        ↓
2. dataset_form.php loads:
   a) Fetches dataset from DHIS2
   b) Loads settings from database:
      - dataset_layout_settings
      - dataset_dataelement_settings
        ↓
3. PHP filters and orders data elements:
   - Remove hidden elements (is_visible = 0)
   - Sort by display_order
   - Apply custom ordering
        ↓
4. Render form with:
   - Visible elements only
   - Custom order
   - Table layout for categories
   - Facility filters applied
        ↓
5. User fills form and submits
        ↓
6. Data sent to DHIS2 via dataValueSets API
```

## Files Modified/Created

### New Files
- `/fbs/admin/ajax_get_dataset_elements.php` - Fetch elements endpoint
- `/fbs/admin/ajax_save_dataelement_settings.php` - Save settings endpoint
- `/fbs/admin/dataset_preview_old.php` - Backup of original preview
- `/db/dataset_complete_migration.sql` - Complete migration script
- `/db/create_dataelement_settings_table.sql` - Table creation only

### Modified Files
- `/fbs/admin/dataset_preview.php` - **COMPLETELY REBUILT** with two-panel layout
- `/fbs/public/dataset_form.php` - Enhanced to respect settings and use table layout
- `/fbs/admin/save_dataset_settings.php` - Already updated by user (handles new columns)

### Unchanged Files
- `/fbs/admin/dhis2_api_proxy.php` - Already supports datasets
- `/fbs/admin/ajax_get_child_locations.php` - Facility search
- `/fbs/admin/dhis2/dhis2_shared.php` - DHIS2 API helper

## Troubleshooting

### Issue: "Column not found: show_facility_section"
**Solution**: Run the migration SQL in phpMyAdmin:
```bash
/db/dataset_complete_migration.sql
```

### Issue: Data elements not loading
**Check**:
1. DHIS2 API connection is working
2. Dataset UID is valid
3. Browser console for JavaScript errors
4. PHP error log for API failures

### Issue: Changes not appearing in form
**Solution**:
1. Verify settings were saved (check browser network tab)
2. Hard refresh the preview iframe (Ctrl+Shift+R)
3. Check database has records in `dataset_dataelement_settings`

### Issue: Drag-and-drop not working
**Check**:
1. Sortable.js is loaded (check browser console)
2. Elements have the `drag-handle` class
3. JavaScript is not blocked

## Future Enhancements

Potential improvements for later:
- [ ] Section-level visibility toggle
- [ ] Export/import element configurations
- [ ] Bulk edit operations
- [ ] Preview comparison (before/after)
- [ ] Support for data entry forms (custom HTML layouts)
- [ ] Validation rules configuration
- [ ] Data approval workflow integration

## Support & Documentation

- **DHIS2 Docs**: https://docs.dhis2.org/en/develop/using-the-api/dhis-core-version-master/metadata.html#webapi_data_sets
- **API Structure**: Based on DHIS2 v41+ dataset metadata endpoint
- **Sortable.js**: https://github.com/SortableJS/Sortable

## Credits

Implementation based on:
- DHIS2 DataSet API structure
- preview_form.php two-panel pattern
- Uganda EMIS flag colors and branding
- DHIS2 data entry form principles

---

**Status**: ✅ Complete and Ready for Use
**Date**: 2026-01-08
**Version**: 1.0.0
