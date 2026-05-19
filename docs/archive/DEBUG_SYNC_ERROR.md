# Debugging Survey 18 Sync Error

## Problem
When attempting to re-sync Survey 18 (Primary Termly Tool) to populate missing option values, the sync fails with:
```
ajax_sync_dataset_metadata.php:1 Failed to load resource: the server responded with a status of 500 (Internal Server Error)
```

## Root Cause
Survey 18 has 83 data elements with `option_set_uid` in the database, but **ZERO option values** in the `dataset_option_values` table. This means option set dropdowns show as text inputs instead of `<select>` elements.

## Changes Made

### 1. Enhanced Error Logging in AJAX Endpoint
**File:** `/fbs/admin/ajax_sync_dataset_metadata.php`

**Changes:**
- Added detailed error message to JSON response
- Added stack trace to JSON response
- Enhanced error logging to PHP error log

**Before:**
```php
} catch (Exception $e) {
    error_log("[AJAX SYNC] Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while syncing dataset metadata'
    ]);
}
```

**After:**
```php
} catch (Exception $e) {
    error_log("[AJAX SYNC] Error: " . $e->getMessage());
    error_log("[AJAX SYNC] Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'An error occurred while syncing dataset metadata',
        'error' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
}
```

### 2. Enhanced Error Display in Sync UI
**File:** `/fbs/admin/sync_dataset_metadata.php`

**Changes:**
- Added console logging for debugging
- Display detailed error message in UI
- Display stack trace in scrollable pre block

**Now Shows:**
- Error message
- Detailed error description
- Full stack trace (if available)
- Response text in browser console

### 3. Created Direct Test Script
**File:** `/test_sync_survey18.php` (NEW)

**Purpose:** Bypass AJAX layer to see raw PHP errors directly in browser

**Usage:** Visit `http://localhost/test_sync_survey18.php`

**What it does:**
1. Connects to database
2. Gets Survey 18 details
3. Calls `DatasetStorageService::storeDatasetMetadata()` directly
4. Shows full error message, file, line number, and stack trace if error occurs
5. Shows success details if sync works

## Next Steps

### Step 1: Run the Direct Test Script (RECOMMENDED)
Visit: **http://localhost/test_sync_survey18.php**

This will show you the exact PHP error without any AJAX layer hiding it.

### Step 2: OR Use Enhanced Sync Page
Visit: **http://localhost/fbs/admin/sync_dataset_metadata.php**

Click "Sync Now" for Survey 18 (Primary Termly Tool).

The error details should now be visible in the UI.

### Step 3: Check PHP Error Logs
If using MAMP, check:
- `/Applications/MAMP/logs/php_error.log`

Look for lines starting with `[AJAX SYNC]` or `[DATASET STORAGE]`

## Possible Error Causes

Based on the code review, here are the most likely causes:

### 1. DHIS2 API Connection Issue
- Instance key might be incorrect
- URL might be unreachable
- Credentials might be invalid
- API timeout (120 seconds)

**Solution:** Check if `dhis2_instances` table has correct data for the instance key

### 2. Dataset Not Found in DHIS2
- Dataset UID might have changed
- Dataset might have been deleted

**Solution:** Verify dataset exists in DHIS2 at the API endpoint

### 3. Database Transaction Issue
- Foreign key constraint violation
- Duplicate key error
- Table not exists

**Solution:** Check database structure with `check_tables.php`

### 4. Memory Limit
- Dataset metadata might be too large
- PHP memory limit exceeded

**Current limit:** 512M (set in dhis2_shared.php)

**Solution:** Increase memory limit if needed

### 5. Option Set Structure Changed
- DHIS2 API response format might be different
- Option set might not have `options` array

**Solution:** Check API response in error logs

## Database Verification

To verify option values are missing, run:

```sql
-- Check how many data elements have option_set_uid
SELECT COUNT(*) as count_with_optionset
FROM dataset_data_elements
WHERE survey_id = 18 AND option_set_uid IS NOT NULL;
-- Result: 83 data elements

-- Check how many option values are stored
SELECT COUNT(*) as count_option_values
FROM dataset_option_values dov
JOIN dataset_option_sets dos ON dov.option_set_id = dos.id
WHERE dos.survey_id = 18;
-- Result: 0 option values (THIS IS THE PROBLEM!)
```

## Expected Outcome After Successful Re-Sync

1. **dataset_option_sets table** - Should have records for Survey 18
2. **dataset_option_values table** - Should have option values (codes + display names)
3. **Option set dropdowns** - Should render properly in dataset_form.php
4. **Data elements with option sets** - Should show `<select>` elements instead of text inputs

## Debug Output Examples

### Survey 16 (Working) Debug Output:
```
Data Element: Does the school have the following
ID: xwZ8dCgYLpB
Value Type: TEXT
Option Set:
Array(
    [id] => ewgkWmm6sn4
    [name] => Yes/No
    [options] => Array(
        [0] => Array([code] => YES, [displayName] => Yes)
        [1] => Array([code] => NO, [displayName] => No)
    )
)
```

### Survey 18 (Broken) Debug Output:
```
Data elements with option_set_uid in database: Array(
    [0] => Array([data_element_uid] => AItQ86C9TNM, [option_set_uid] => ewgkWmm6sn4, ...)
    ... 82 more
)

Option values in database: Array()  // EMPTY!
```

## Files Modified

1. `/fbs/admin/ajax_sync_dataset_metadata.php` - Enhanced error reporting
2. `/fbs/admin/sync_dataset_metadata.php` - Enhanced error display
3. `/test_sync_survey18.php` - NEW direct test script
4. `/DEBUG_SYNC_ERROR.md` - THIS FILE

## Next Steps After Fixing Sync Error

Once the sync completes successfully:

1. ✅ Re-sync Survey 18 to populate missing option values
2. ✅ Verify option values in database using debug script
3. ✅ Test dataset form to verify dropdowns render
4. ⬜ Extract and apply custom form scripts/CSS
5. ⬜ Make custom form responsive and improve design
6. ⬜ Test complete dataset form workflow

---

**Last Updated:** 2026-01-22
**Status:** Ready for user testing
