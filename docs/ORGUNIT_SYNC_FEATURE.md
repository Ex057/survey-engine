# Organization Unit Manual Sync Feature

## Overview
This feature allows administrators to manually sync organization units (facilities) from DHIS2 to the local database, ensuring that only currently attached facilities are available in dataset forms.

---

## Feature Location
**Admin Interface**: [fbs/admin/dataset_preview.php](../fbs/admin/dataset_preview.php)

The sync button is located in the left sidebar under "Organization Units" section.

---

## How It Works

### User Flow
1. Admin opens dataset preview page
2. Sees current org unit count and last sync time
3. Clicks **"Sync Facilities from DHIS2"** button
4. Confirms the sync action (warning about deletion)
5. System performs sync operation
6. Admin sees success message with count of synced facilities

### Technical Flow

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Admin Clicks "Sync Facilities from DHIS2" Button        │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. Confirmation Dialog                                      │
│    "This will delete all existing facilities for this       │
│     survey and fetch fresh data from DHIS2."                │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. AJAX POST to ajax_sync_dataset_orgunits.php             │
│    Parameters:                                               │
│    - survey_id                                               │
│    - dataset_uid                                             │
│    - instance_key                                            │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. DELETE FROM dataset_org_units WHERE survey_id = ?       │
│    (Removes all old org units for this survey)              │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 5. DHIS2 API Call                                           │
│    GET /api/dataSets/{uid}/organisationUnits.json           │
│    ?fields=id,name,displayName,parent,path,level            │
│    &paging=false                                             │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 6. INSERT INTO dataset_org_units                            │
│    (Stores only currently attached org units)               │
│    - survey_id, org_unit_uid, org_unit_name                 │
│    - org_unit_display_name, parent_uid, parent_name         │
│    - path, level                                             │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 7. UPDATE survey_settings                                   │
│    SET orgunit_last_sync = NOW()                            │
│    WHERE survey_id = ?                                       │
└────────────────────┬────────────────────────────────────────┘
                     │
                     ▼
┌─────────────────────────────────────────────────────────────┐
│ 8. Return JSON Response                                     │
│    {                                                         │
│      "success": true,                                        │
│      "count": 150,                                           │
│      "deleted": 145,                                         │
│      "message": "Successfully synced 150 facilities..."      │
│    }                                                         │
└─────────────────────────────────────────────────────────────┘
```

---

## Implementation Files

### 1. Frontend UI
**File**: [fbs/admin/dataset_preview.php](../fbs/admin/dataset_preview.php)

**HTML Added** (Lines ~492-513):
```html
<div class="card">
    <div class="card-header">
        <h6 class="mb-0"><i class="fas fa-hospital me-2"></i>Organization Units</h6>
    </div>
    <div class="card-body">
        <p class="small text-muted mb-2">
            Sync facilities from DHIS2 to local database
        </p>
        <div id="orgunitSyncStatus" class="alert alert-info small py-2 mb-2" style="display: none;">
            <i class="fas fa-info-circle me-1"></i>
            <span id="orgunitSyncMessage">Loading sync status...</span>
        </div>
        <button type="button" class="btn btn-warning w-100 mb-2" id="syncOrgUnitsBtn">
            <i class="fas fa-sync-alt me-2"></i>Sync Facilities from DHIS2
        </button>
        <small class="text-muted d-block" id="lastSyncTime" style="display: none;">
            <i class="fas fa-clock me-1"></i>Last synced: <span id="lastSyncTimeValue">Never</span>
        </small>
        <small class="text-muted d-block mt-1">
            <i class="fas fa-info-circle me-1"></i>Count: <span id="orgunitCount">-</span> facilities
        </small>
    </div>
</div>
```

**JavaScript Functions Added** (Lines ~838-950):
- `loadOrgUnitSyncStatus()` - Loads sync status on page load
- `syncOrgUnitsFromDHIS2()` - Handles sync button click

### 2. Backend Sync Endpoint
**File**: [fbs/admin/ajax_sync_dataset_orgunits.php](../fbs/admin/ajax_sync_dataset_orgunits.php) (NEW)

**Purpose**: Handles the sync operation

**Key Operations**:
1. Validates survey, dataset, and instance
2. Deletes old org units for the survey
3. Fetches fresh org units from DHIS2
4. Inserts new org units (transaction-safe)
5. Updates sync timestamp

**Security Checks**:
- ✅ POST method only
- ✅ Survey exists
- ✅ Dataset UID matches
- ✅ Instance key matches
- ✅ Only aggregate datasets

### 3. Sync Status Endpoint
**File**: [fbs/admin/ajax_get_orgunit_sync_status.php](../fbs/admin/ajax_get_orgunit_sync_status.php) (NEW)

**Purpose**: Returns current sync status

**Response**:
```json
{
    "success": true,
    "last_sync": "2026-01-27 14:30:00",
    "count": 150
}
```

### 4. Database Migration
**File**: [db/add_orgunit_sync_timestamp.sql](../db/add_orgunit_sync_timestamp.sql) (NEW)

**Purpose**: Adds `orgunit_last_sync` column to `survey_settings` table

**Run Migration**:
```bash
mysql -u your_user -p your_database < db/add_orgunit_sync_timestamp.sql
```

---

## Database Schema

### New Column in `survey_settings`
```sql
ALTER TABLE survey_settings
ADD COLUMN orgunit_last_sync DATETIME NULL
COMMENT 'Timestamp of last org unit sync from DHIS2'
AFTER selected_hierarchy_level;
```

### Existing Table: `dataset_org_units`
```sql
CREATE TABLE dataset_org_units (
    id INT PRIMARY KEY AUTO_INCREMENT,
    survey_id INT NOT NULL,
    org_unit_uid VARCHAR(11) NOT NULL,
    org_unit_name VARCHAR(255) NOT NULL,
    org_unit_display_name VARCHAR(255),
    parent_uid VARCHAR(11),
    parent_name VARCHAR(255),
    path TEXT,
    level INT,
    UNIQUE KEY unique_survey_orgunit (survey_id, org_unit_uid),
    INDEX idx_survey_id (survey_id),
    INDEX idx_org_unit_uid (org_unit_uid)
);
```

---

## Use Cases

### Use Case 1: Dataset Has New Facilities in DHIS2
**Scenario**: Admin adds 10 new health facilities to a dataset in DHIS2

**Before Sync**:
- Local database has 100 facilities
- New 10 facilities don't appear in form search

**After Sync**:
- Admin clicks "Sync Facilities from DHIS2"
- System deletes old 100 facilities
- Fetches 110 facilities from DHIS2
- Local database now has 110 facilities
- All 110 facilities available in form search

**Result**: ✅ New facilities immediately available

---

### Use Case 2: Dataset Has Removed Facilities in DHIS2
**Scenario**: Admin removes 5 outdated facilities from dataset in DHIS2

**Before Sync**:
- Local database has 100 facilities
- Removed 5 facilities still appear in form search (stale data)

**After Sync**:
- Admin clicks "Sync Facilities from DHIS2"
- System deletes old 100 facilities
- Fetches 95 facilities from DHIS2 (only active ones)
- Local database now has 95 facilities
- Removed facilities no longer appear in search

**Result**: ✅ Only active facilities available

---

### Use Case 3: Dataset Org Units Reorganized in DHIS2
**Scenario**: Health facilities moved between districts in DHIS2

**Before Sync**:
- Facility "ABC Clinic" shows as "District A > ABC Clinic"
- In DHIS2, it's now under "District B"

**After Sync**:
- Admin clicks sync
- Fresh data fetched with updated hierarchy paths
- Facility now shows as "District B > ABC Clinic"

**Result**: ✅ Hierarchy paths up-to-date

---

## Memory & Performance

### Memory Efficiency

#### During Sync (Server-Side)
- **Small Dataset** (< 100 facilities): ~50 KB memory
- **Medium Dataset** (100-1,000 facilities): ~500 KB memory
- **Large Dataset** (1,000-10,000 facilities): ~5 MB memory
- **Very Large Dataset** (> 10,000 facilities): ~50 MB memory

**Note**: Sync happens server-side once, not during user searches.

#### During Form Search (User-Side)
- **Always memory-efficient**: Paginated database queries (50 results per page)
- No API calls during search
- Fast indexed lookups

### Performance Comparison

| Operation | Without Sync (API) | With Sync (Local DB) |
|-----------|-------------------|----------------------|
| **Search Speed** | 2-5 seconds | < 100ms |
| **Memory Usage** | High (loads all) | Low (paginated) |
| **Network Dependency** | Required | None |
| **DHIS2 Load** | Every search | Only during sync |
| **Always Up-to-Date** | ✅ Yes | ❌ No (requires sync) |

---

## Security & Validation

### Request Validation
1. ✅ **Survey ID validation**: Checks survey exists
2. ✅ **Dataset UID validation**: Verifies it matches survey
3. ✅ **Instance key validation**: Ensures correct DHIS2 instance
4. ✅ **Program type check**: Only aggregate datasets allowed

### Confirmation Dialog
```javascript
"This will delete all existing facilities for this survey and fetch fresh data from DHIS2.

Only facilities currently attached to this dataset in DHIS2 will be available in the form.

Do you want to continue?"
```

### Transaction Safety
```php
$pdo->beginTransaction();
try {
    // Insert org units
    foreach ($orgUnits as $ou) {
        $insertStmt->execute([...]);
    }
    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    throw $e;
}
```

---

## Error Handling

### Frontend Errors
| Error | Cause | User Message |
|-------|-------|-------------|
| **Network Timeout** | DHIS2 unreachable | "Failed to sync facilities from DHIS2" |
| **Invalid Dataset** | Wrong UID | "Dataset UID mismatch" |
| **No Permissions** | Session expired | "Survey not found" |

### Backend Errors
| Error | Cause | Response |
|-------|-------|----------|
| **DHIS2 API Error** | Connection failed | `{"success": false, "message": "Failed to fetch org units..."}` |
| **Database Error** | Insert failed | Transaction rolled back, error logged |
| **Validation Error** | Missing params | `{"success": false, "message": "Missing required parameters..."}` |

### Error Logging
```php
error_log("[ORGUNIT SYNC] Starting manual sync for survey {$surveyId}");
error_log("[ORGUNIT SYNC] Deleted {$deletedCount} old org units");
error_log("[ORGUNIT SYNC] Fetched " . count($orgUnits) . " org units from DHIS2");
error_log("[ORGUNIT SYNC] Successfully inserted {$insertedCount} org units");
error_log("[ORGUNIT SYNC] Error: " . $e->getMessage());
```

---

## Testing Checklist

### Pre-Sync Verification
- [ ] Dataset has org units attached in DHIS2
- [ ] DHIS2 instance configured and accessible
- [ ] Survey exists and is active
- [ ] Admin is logged in

### Sync Process Testing
- [ ] Click sync button shows confirmation dialog
- [ ] Cancel works (no sync performed)
- [ ] Confirm starts sync (loading spinner appears)
- [ ] Success message shows org unit count
- [ ] Last sync timestamp updates
- [ ] Org unit count updates

### Post-Sync Verification
- [ ] Old org units deleted from database
- [ ] New org units inserted successfully
- [ ] Form search shows updated facilities
- [ ] Removed facilities no longer appear
- [ ] New facilities are searchable
- [ ] Hierarchy paths are correct

### Edge Cases
- [ ] Sync with 0 org units (dataset has none attached)
- [ ] Sync with 10,000+ org units (large dataset)
- [ ] Sync during DHIS2 outage (error handling)
- [ ] Sync with invalid dataset UID (validation)
- [ ] Multiple rapid syncs (button disabled during sync)

---

## API Reference

### Sync Endpoint
**URL**: `POST /fbs/admin/ajax_sync_dataset_orgunits.php`

**Request Parameters**:
```
survey_id: int (required)
dataset_uid: string (required, 11 chars)
instance_key: string (required)
```

**Success Response** (HTTP 200):
```json
{
    "success": true,
    "count": 150,
    "deleted": 145,
    "message": "Successfully synced 150 facilities from DHIS2"
}
```

**Error Response** (HTTP 500):
```json
{
    "success": false,
    "message": "Failed to fetch org units from DHIS2. Please check..."
}
```

---

### Status Endpoint
**URL**: `GET /fbs/admin/ajax_get_orgunit_sync_status.php`

**Request Parameters**:
```
survey_id: int (required)
```

**Success Response** (HTTP 200):
```json
{
    "success": true,
    "last_sync": "2026-01-27 14:30:00",
    "count": 150
}
```

**Error Response** (HTTP 500):
```json
{
    "success": false,
    "message": "Survey ID required"
}
```

---

## DHIS2 API Endpoint Used

**Endpoint**: `GET /api/dataSets/{uid}/organisationUnits.json`

**Parameters**:
- `fields`: `id,name,displayName,parent[id,name],path,level`
- `paging`: `false` (fetch all)

**Example Request**:
```
GET https://play.dhis2.org/2.40.0/api/dataSets/BfMAe6Itzgt/organisationUnits.json?
    fields=id,name,displayName,parent[id,name],path,level
    &paging=false
```

**Example Response**:
```json
{
    "organisationUnits": [
        {
            "id": "DiszpKrYNg8",
            "name": "Ngelehun CHC",
            "displayName": "Ngelehun CHC",
            "parent": {
                "id": "qhqAxPSTUXp",
                "name": "Badjia"
            },
            "path": "/ImspTQPwCqd/O6uvpzGd5pu/qhqAxPSTUXp/DiszpKrYNg8",
            "level": 4
        }
    ]
}
```

---

## Advantages Over API-Only Approach

| Feature | Manual Sync (This Feature) | API-Only (No Sync) |
|---------|---------------------------|-------------------|
| **Search Speed** | ⚡ < 100ms (local DB) | 🐌 2-5s (API call) |
| **Memory Usage** | 🟢 Low (paginated) | 🔴 High (loads all) |
| **Network Dependency** | 🟢 None (after sync) | 🔴 Required every search |
| **DHIS2 Server Load** | 🟢 Low (sync only) | 🔴 High (every search) |
| **Offline Support** | ✅ Yes (local cache) | ❌ No (needs internet) |
| **Always Up-to-Date** | ⚠️ Manual sync needed | ✅ Always current |
| **User Control** | ✅ Admin decides when | ❌ Automatic |

---

## Future Enhancements

### Automatic Sync
- Scheduled cron job (daily/weekly)
- Background queue processing
- Auto-sync on survey activation

### Smart Sync
- Only sync changed org units (delta sync)
- Track last modified timestamp
- Incremental updates

### Multi-Dataset Sync
- Bulk sync all datasets
- Progress bar for large syncs
- Batch processing

### Notifications
- Email admin when sync completes
- Alert if sync fails
- Dashboard widget for sync status

---

## Troubleshooting

### Issue: "No organization units attached to this dataset"
**Cause**: Dataset in DHIS2 has no facilities assigned
**Solution**: In DHIS2, assign facilities to the dataset

### Issue: "Failed to fetch org units from DHIS2"
**Cause**: DHIS2 connection issue or invalid dataset UID
**Solution**: Check DHIS2 instance settings and dataset UID

### Issue: Sync button disabled indefinitely
**Cause**: JavaScript error or AJAX timeout
**Solution**: Refresh page, check browser console for errors

### Issue: Old facilities still appear after sync
**Cause**: Browser cache or database transaction failed
**Solution**: Hard refresh browser (Ctrl+F5), check error logs

---

## Conclusion

This feature provides a **balance between performance and data freshness**:
- ✅ **Fast searches** (local database)
- ✅ **Memory-efficient** (paginated results)
- ✅ **Admin control** (manual sync when needed)
- ✅ **Clean data** (deletes old, inserts only attached)
- ✅ **Transparent** (shows last sync time and count)

**Best Practice**: Sync org units whenever:
1. New facilities added to dataset in DHIS2
2. Facilities removed from dataset in DHIS2
3. Hierarchy structure changed in DHIS2
4. Before launching a new survey round
