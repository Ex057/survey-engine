# DHIS2 Native Dataset Form Implementation Plan

## Summary

Rebuild the dataset form system to use DHIS2's native form design and fetch organization units directly from DHIS2 API.

## Key Changes

### 1. Form Type Detection
- Detect DHIS2's `formType`: DEFAULT, SECTION, or CUSTOM
- Render based on DHIS2's actual structure
- Support sections with proper grouping
- Parse custom HTML forms

### 2. Direct Organization Unit Fetching
- Fetch org units from `/api/dataSets/{uid}/organisationUnits`
- Remove dependency on local `location` table
- Always fresh data from DHIS2
- Search with pagination

## Implementation Steps

### Step 1: Create Dataset API Service (2 hours)
**File**: `fbs/admin/dhis2/dataset_api_service.php`

Fetch comprehensive dataset metadata:
```php
getDatasetComplete($datasetUid, $instanceKey)
  → formType, sections, dataEntryForm, greyedFields
```

### Step 2: Create Form Renderer Classes (3 hours)
**Files**: `fbs/public/includes/form_renderers/*.php`

Factory pattern with 3 renderers:
- `DefaultFormRenderer` - Simple list
- `SectionFormRenderer` - Section-based (matches your screenshot)
- `CustomFormRenderer` - Parse HTML

### Step 3: Implement Section Renderer (4 hours)
Most critical for your use case:
- Render sections with headers
- Category combo grid layout
- Sort by `sortOrder`

### Step 4: Direct Org Unit Fetching (3 hours)
**File**: `fbs/admin/ajax_get_dataset_orgunits.php`

Features:
- Search-as-you-type
- Pagination (50 per page)
- Show hierarchy path
- Session caching

### Step 5: Update dataset_form.php (2 hours)
Replace manual rendering:
```php
$dataset = DatasetApiService::getDatasetComplete($uid, $instance);
$renderer = FormRendererFactory::create($dataset['formType']);
echo $renderer->render($dataset, $settings);
```

### Step 6: Database Migration (1 hour)
```sql
-- Add to dataset_layout_settings
ALTER TABLE dataset_layout_settings
ADD COLUMN use_direct_orgunits TINYINT(1) DEFAULT 1,
ADD COLUMN form_type VARCHAR(20) DEFAULT 'DEFAULT';

-- New cache table
CREATE TABLE dataset_metadata_cache (
    dataset_uid VARCHAR(11),
    metadata JSON,
    expires_at TIMESTAMP
);
```

### Step 7: Update Preview (2 hours)
Show form type, sections, and test org unit search

**Total: ~17 hours**

## Benefits

✅ Forms match DHIS2 exactly
✅ Changes in DHIS2 automatically reflected
✅ No org unit sync needed
✅ Support for custom forms
✅ Cleaner category combo grids

## Ready to Start?

All planning is complete. Ready to begin implementation when you are!
