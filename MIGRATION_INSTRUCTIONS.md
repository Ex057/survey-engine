# Database Migration Instructions

## Add orgunit_last_sync Column to survey_settings Table

### Option 1: Using phpMyAdmin (RECOMMENDED)

1. **Open phpMyAdmin**
   - Go to: `http://localhost:8888/phpMyAdmin/` or `http://localhost:8889/phpMyAdmin/`
   - Login with your MySQL credentials (usually `root` / `root` for MAMP)

2. **Select Database**
   - Click on `fbtv3` database in the left sidebar

3. **Open SQL Tab**
   - Click on the "SQL" tab at the top

4. **Run This SQL**
   Copy and paste the following SQL:

```sql
-- Add orgunit_last_sync column
ALTER TABLE survey_settings
ADD COLUMN orgunit_last_sync DATETIME NULL
COMMENT 'Timestamp of last org unit sync from DHIS2'
AFTER selected_hierarchy_level;

-- Verify the column was added
SELECT
    COLUMN_NAME,
    COLUMN_TYPE,
    IS_NULLABLE,
    COLUMN_COMMENT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = 'fbtv3'
  AND TABLE_NAME = 'survey_settings'
  AND COLUMN_NAME = 'orgunit_last_sync';
```

5. **Click "Go"**
   - You should see a success message
   - The verification query will show the column details

6. **Done!**
   - The column has been added successfully
   - You can now use the sync feature

---

### Option 2: Using MySQL Command Line

If you prefer the terminal:

1. **Find your MAMP MySQL path**
   ```bash
   /Applications/MAMP/Library/bin/mysql --version
   ```

2. **Run the SQL file**
   ```bash
   /Applications/MAMP/Library/bin/mysql -u root -proot -P 8889 fbtv3 < db/add_orgunit_sync_simple.sql
   ```

   Or connect first and then run the commands:
   ```bash
   /Applications/MAMP/Library/bin/mysql -u root -proot -P 8889 fbtv3
   ```

   Then paste:
   ```sql
   ALTER TABLE survey_settings
   ADD COLUMN orgunit_last_sync DATETIME NULL
   COMMENT 'Timestamp of last org unit sync from DHIS2'
   AFTER selected_hierarchy_level;
   ```

---

### Option 3: Using Sequel Pro / TablePlus / MySQL Workbench

If you use a GUI MySQL client:

1. **Connect to your database**
   - Host: `localhost` or `127.0.0.1`
   - Port: `8889` (MAMP default)
   - User: `root`
   - Password: `root`
   - Database: `fbtv3`

2. **Execute SQL**
   Paste and run:
   ```sql
   ALTER TABLE survey_settings
   ADD COLUMN orgunit_last_sync DATETIME NULL
   COMMENT 'Timestamp of last org unit sync from DHIS2'
   AFTER selected_hierarchy_level;
   ```

---

## Verification

After running the migration, verify the column exists:

### Method 1: phpMyAdmin
1. Go to `fbtv3` database
2. Click on `survey_settings` table
3. Click "Structure" tab
4. Look for `orgunit_last_sync` column
5. It should be type `DATETIME` and allow `NULL`

### Method 2: SQL Query
```sql
SHOW COLUMNS FROM survey_settings LIKE 'orgunit_last_sync';
```

Expected result:
```
Field: orgunit_last_sync
Type: datetime
Null: YES
Default: NULL
```

---

## Testing the Feature

Once the column is added:

1. **Login to Admin Panel**
   - Go to: `http://localhost:8888/survey-engine/fbs/admin/`

2. **Open Dataset Preview**
   - Go to your surveys list
   - Click on any DHIS2 aggregate dataset survey
   - You should see the dataset preview page

3. **Find Organization Units Card**
   - Look at the left sidebar
   - You should see "Organization Units" section
   - With a button: "Sync Facilities from DHIS2"

4. **Test Sync**
   - Click the sync button
   - Confirm the dialog
   - Wait for success message
   - Should show: "Successfully synced X facilities from DHIS2!"

---

## Troubleshooting

### Issue: Column already exists
If you see: `ERROR 1060 (42S21): Duplicate column name 'orgunit_last_sync'`

**Solution**: The column is already added. No action needed!

### Issue: Table 'survey_settings' doesn't exist
**Solution**: Check that you're using the correct database name (`fbtv3`)

### Issue: Access denied
**Solution**: Verify MySQL credentials in phpMyAdmin

---

## Files Reference

- **Migration SQL**: `db/add_orgunit_sync_simple.sql`
- **Sync Endpoint**: `fbs/admin/ajax_sync_dataset_orgunits.php`
- **Status Endpoint**: `fbs/admin/ajax_get_orgunit_sync_status.php`
- **Frontend**: `fbs/admin/dataset_preview.php`

---

## Summary

You need to run **ONE SQL COMMAND**:

```sql
ALTER TABLE survey_settings
ADD COLUMN orgunit_last_sync DATETIME NULL
COMMENT 'Timestamp of last org unit sync from DHIS2'
AFTER selected_hierarchy_level;
```

That's it! ✅
