# DHIS2 Aggregate Dataset Feature - Implementation Guide

## Overview

The aggregate dataset feature allows your survey system to collect and submit aggregated data to DHIS2 instances. This complements the existing tracker program functionality by supporting DHIS2's aggregate data model.

**Key Features:**
- Pull dataset structure directly from DHIS2 API
- Dynamic form generation based on data elements
- Support for multiple period types (Daily, Weekly, Monthly, Quarterly, Yearly)
- Organization unit selection from DHIS2 hierarchy
- Automatic detection and update of existing data
- QR code generation for easy mobile access
- Complete submission tracking and logging

---

## Table of Contents

1. [Architecture](#architecture)
2. [Database Schema](#database-schema)
3. [File Structure](#file-structure)
4. [Setup Instructions](#setup-instructions)
5. [Creating a Dataset Survey](#creating-a-dataset-survey)
6. [How It Works](#how-it-works)
7. [API Integration](#api-integration)
8. [Customization](#customization)
9. [Troubleshooting](#troubleshooting)

---

## Architecture

### System Flow

```
┌─────────────────────────────────────────────────────────────┐
│                     Admin Creates Survey                     │
│  - Select DHIS2 Instance                                     │
│  - Choose "Aggregate" domain                                 │
│  - Select Dataset from DHIS2                                 │
└──────────────────┬──────────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────────┐
│              Generate QR Code (Share Page)                   │
│  URL Pattern: /d/{survey_id}                                 │
└──────────────────┬──────────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────────┐
│              User Scans QR / Opens Link                      │
│  - dataset_form.php loads                                    │
│  - Fetches dataset structure from DHIS2                      │
└──────────────────┬──────────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────────┐
│                  Dynamic Form Display                        │
│  1. Organization Unit Selector (from DHIS2)                  │
│  2. Period Picker (based on dataset periodType)              │
│  3. Data Elements (with appropriate input types)             │
└──────────────────┬──────────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────────┐
│              User Fills and Submits Form                     │
│  - JavaScript validates inputs                               │
│  - AJAX POST to dataset_submit.php                           │
└──────────────────┬──────────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────────┐
│              Server-Side Processing                          │
│  1. Validate submission data                                 │
│  2. Check if data exists (update vs create)                  │
│  3. Format payload for DHIS2 API                             │
│  4. POST to /api/dataValueSets                               │
│  5. Mark dataset as complete                                 │
│  6. Save to local database                                   │
└──────────────────┬──────────────────────────────────────────┘
                   │
                   ▼
┌─────────────────────────────────────────────────────────────┐
│              Success Page with Details                       │
│  - Submission UID                                            │
│  - Period and OrgUnit info                                   │
│  - Option to submit new data                                 │
└─────────────────────────────────────────────────────────────┘
```

---

## Database Schema

### Core Tables

#### 1. `dataset_submissions`
Stores all dataset submissions.

```sql
CREATE TABLE `dataset_submissions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `survey_id` INT NOT NULL,
  `dataset_uid` VARCHAR(11) NOT NULL,
  `orgunit_uid` VARCHAR(11) NOT NULL,
  `period` VARCHAR(20) NOT NULL,
  `data_values` JSON NOT NULL,
  `dhis2_response` JSON,
  `uid` VARCHAR(11) NOT NULL UNIQUE,
  `is_update` TINYINT(1) DEFAULT 0,
  `submitted_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_survey_id` (`survey_id`),
  KEY `idx_orgunit_period` (`orgunit_uid`, `period`)
);
```

#### 2. `dataset_layout_settings`
Customization settings for dataset forms.

```sql
CREATE TABLE `dataset_layout_settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `survey_id` INT NOT NULL UNIQUE,
  `layout_type` ENUM('horizontal', 'vertical') DEFAULT 'horizontal',
  `show_flag_bar` TINYINT(1) DEFAULT 1,
  `flag_black_color` VARCHAR(7) DEFAULT '#000000',
  `flag_yellow_color` VARCHAR(7) DEFAULT '#FCD116',
  `flag_red_color` VARCHAR(7) DEFAULT '#D21034'
);
```

#### 3. `dataset_images`
Images for dataset forms and share pages.

```sql
CREATE TABLE `dataset_images` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `survey_id` INT NOT NULL,
  `image_order` INT DEFAULT 0,
  `image_path` VARCHAR(500) NOT NULL,
  `image_alt_text` VARCHAR(255),
  `is_active` TINYINT(1) DEFAULT 1
);
```

#### 4. `dataset_data_element_mapping`
Maps DHIS2 data elements to custom configurations.

```sql
CREATE TABLE `dataset_data_element_mapping` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `survey_id` INT NOT NULL,
  `data_element_uid` VARCHAR(11) NOT NULL,
  `custom_label` VARCHAR(255),
  `is_required` TINYINT(1) DEFAULT 0,
  `display_order` INT DEFAULT 0,
  `is_hidden` TINYINT(1) DEFAULT 0
);
```

---

## File Structure

### Public-Facing Files

```
fbs/public/
├── dataset_form.php           # Main form for data entry
├── dataset_submit.php          # Handles form submission
├── dataset_share.php           # QR code and sharing page
└── dataset_success.php         # Success confirmation page
```

### Admin/Backend Files

```
fbs/admin/dhis2/
├── dhis2_shared.php           # Existing - DHIS2 API helpers
├── ajax_get_orgunits.php      # NEW - Fetch organization units
└── ajax_get_programs_datasets.php  # Existing - Extended for datasets
```

### Database Files

```
db/
└── dataset_tables.sql         # Database schema for dataset feature
```

### Configuration Files

```
.htaccess                      # URL routing rules
```

---

## Setup Instructions

### Step 1: Create Database Tables

Run the SQL script to create necessary tables:

```bash
mysql -u your_username -p your_database < db/dataset_tables.sql
```

Or import via phpMyAdmin/MySQL Workbench.

### Step 2: Verify File Permissions

Ensure the web server can read the new PHP files:

```bash
chmod 644 fbs/public/dataset_*.php
chmod 644 fbs/admin/dhis2/ajax_get_orgunits.php
```

### Step 3: Test URL Routing

The `.htaccess` file has been updated with these rules:

```apache
# Dataset form access
RewriteRule ^d/([0-9]+)$ fbs/public/dataset_form.php?survey_id=$1 [L]

# Dataset share page
RewriteRule ^share/d/([0-9]+)$ fbs/public/dataset_share.php?survey_id=$1 [L]

# Dataset success page
RewriteRule ^dataset-success/([0-9]+)$ fbs/public/dataset_success.php?submission_id=$1 [L]
```

Test by accessing: `http://yoursite.com/d/1`

### Step 4: Configure DHIS2 Instance

Ensure you have an active DHIS2 instance configured in:
- Admin Panel → DHIS2 Instances
- Status must be "Active"
- Credentials must be valid

---

## Creating a Dataset Survey

### Method 1: Via Admin Interface (Recommended - To be implemented)

1. Navigate to **Admin Panel → Surveys → Create New**
2. Fill in basic details:
   - Survey Name
   - Start/End Dates
3. Select Survey Type: **DHIS2**
4. Select Program Type: **Aggregate**
5. Choose DHIS2 Instance
6. Select Dataset from dropdown (loaded via AJAX)
7. Save Survey

### Method 2: Direct Database Insert (For Testing)

```sql
INSERT INTO survey (
    name,
    type,
    program_type,
    domain_type,
    dhis2_instance,
    program_dataset,
    is_active
) VALUES (
    'Monthly Health Facility Report',
    'dhis2',
    'aggregate',
    'aggregate',
    'your_instance_key',  -- From dhis2_instances table
    'dataSetUID123',       -- DHIS2 dataset UID
    1
);
```

### Step 3: Customize Appearance (Optional)

```sql
INSERT INTO dataset_layout_settings (
    survey_id,
    layout_type,
    show_flag_bar,
    flag_black_color,
    flag_yellow_color,
    flag_red_color
) VALUES (
    1,              -- Your survey ID
    'horizontal',
    1,
    '#000000',
    '#FCD116',
    '#D21034'
);
```

### Step 4: Generate QR Code

Access the share page:
```
http://yoursite.com/share/d/{survey_id}
```

---

## How It Works

### 1. Form Loading Process

When a user accesses `/d/{survey_id}`:

1. **Survey Lookup**: System fetches survey details from database
2. **Validation**: Checks if survey is active and type is 'aggregate'
3. **DHIS2 API Call**: Fetches dataset structure:
   ```
   GET /api/dataSets/{uid}?fields=id,name,periodType,dataSetElements[...]
   ```
4. **Form Rendering**: Generates HTML form with:
   - Organization unit dropdown
   - Period selector (based on periodType)
   - Data element inputs (based on valueType)

### 2. Period Selection

The system supports multiple DHIS2 period types:

| Period Type | Format | Example |
|------------|--------|---------|
| Daily | YYYYMMDD | 20240115 |
| Weekly | YYYYWnn | 2024W03 |
| Monthly | YYYYMM | 202401 |
| Quarterly | YYYYQn | 2024Q1 |
| Yearly | YYYY | 2024 |
| FinancialApril | YYYYApril | 2024April |
| FinancialJuly | YYYYJuly | 2024July |
| FinancialOct | YYYYOct | 2024Oct |

### 3. Data Element Rendering

Data elements are rendered based on their `valueType`:

```php
switch ($valueType) {
    case 'NUMBER':
    case 'INTEGER':
        return '<input type="number" ...>';

    case 'BOOLEAN':
        return '<select><option>Yes</option><option>No</option></select>';

    case 'DATE':
        return '<input type="date" ...>';

    case 'TEXT':
    default:
        return '<input type="text" ...>';
}
```

### 4. Category Combinations

If a data element has category combos (disaggregation):

```html
<div class="data-element-group">
    <label>Malaria Cases</label>

    <!-- Male, <5 years -->
    <input name="de_uid_coc1" data-de-id="de_uid" data-coc-id="coc1">

    <!-- Female, <5 years -->
    <input name="de_uid_coc2" data-de-id="de_uid" data-coc-id="coc2">

    <!-- Male, 5+ years -->
    <input name="de_uid_coc3" data-de-id="de_uid" data-coc-id="coc3">

    <!-- Female, 5+ years -->
    <input name="de_uid_coc4" data-de-id="de_uid" data-coc-id="coc4">
</div>
```

### 5. Submission Process

**Client-Side (JavaScript):**
```javascript
{
    survey_id: 1,
    dataset_uid: "dataSetUID",
    orgunit: "orgUnitUID",
    period: "202401",
    data_values: [
        { dataElement: "de1", value: "100" },
        { dataElement: "de2", value: "50", categoryOptionCombo: "coc1" }
    ]
}
```

**Server-Side (PHP):**
1. Validate required fields
2. Check existing data: `GET /api/dataValueSets?dataSet=X&period=Y&orgUnit=Z`
3. Prepare payload:
```php
$payload = [
    'dataSet' => $datasetUid,
    'period' => $period,
    'orgUnit' => $orgUnit,
    'completeDate' => date('Y-m-d'),
    'dataValues' => [
        ['dataElement' => 'de1', 'value' => '100'],
        ['dataElement' => 'de2', 'value' => '50', 'categoryOptionCombo' => 'coc1']
    ]
];
```
4. Submit: `POST /api/dataValueSets`
5. Mark complete: `POST /api/completeDataSetRegistrations`
6. Save locally to `dataset_submissions`

---

## API Integration

### DHIS2 API Endpoints Used

#### 1. Get Dataset Structure
```http
GET /api/dataSets/{uid}.json?fields=id,name,description,periodType,dataSetElements[dataElement[id,name,displayName,valueType,categoryCombo[...]]]
Authorization: Basic {base64(username:password)}
```

#### 2. Get Organization Units
```http
GET /api/organisationUnits?fields=id,name,displayName,level&filter=level:le:4&paging=false
Authorization: Basic {base64(username:password)}
```

#### 3. Check Existing Data
```http
GET /api/dataValueSets?dataSet={datasetUid}&period={period}&orgUnit={orgUnitUid}
Authorization: Basic {base64(username:password)}
```

#### 4. Submit Data Values
```http
POST /api/dataValueSets
Content-Type: application/json
Authorization: Basic {base64(username:password)}

{
  "dataSet": "dataSetUID",
  "period": "202401",
  "orgUnit": "orgUnitUID",
  "completeDate": "2024-01-15",
  "dataValues": [...]
}
```

#### 5. Mark Dataset Complete
```http
POST /api/completeDataSetRegistrations
Content-Type: application/json
Authorization: Basic {base64(username:password)}

{
  "completeDataSetRegistrations": [{
    "dataSet": "dataSetUID",
    "period": "202401",
    "organisationUnit": "orgUnitUID",
    "completed": true,
    "date": "2024-01-15"
  }]
}
```

### Response Handling

**Success Response:**
```json
{
  "httpStatus": "OK",
  "httpStatusCode": 200,
  "status": "SUCCESS",
  "importCount": {
    "imported": 5,
    "updated": 0,
    "ignored": 0,
    "deleted": 0
  }
}
```

**Error Response:**
```json
{
  "httpStatus": "Conflict",
  "httpStatusCode": 409,
  "status": "ERROR",
  "description": "Import process failed",
  "conflicts": [
    {
      "object": "dataValue",
      "value": "Data element 'de1' is not part of data set 'ds1'"
    }
  ]
}
```

---

## Customization

### 1. Customize Form Appearance

Edit `dataset_form.php` CSS variables:

```css
:root {
    --primary-color: #4a5568;
    --uganda-yellow: #FCD116;
    /* Add your colors */
}
```

### 2. Add Custom Validation

In `dataset_form.php`, add JavaScript validation:

```javascript
function validateCustomRules() {
    // Example: Ensure total doesn't exceed 100
    const total = calculateTotal();
    if (total > 100) {
        showAlert('error', 'Total cannot exceed 100');
        return false;
    }
    return true;
}
```

### 3. Custom Data Element Labels

Use the `dataset_data_element_mapping` table:

```sql
INSERT INTO dataset_data_element_mapping (
    survey_id,
    data_element_uid,
    custom_label,
    display_order,
    is_required
) VALUES (
    1,
    'dataElementUID',
    'Number of Patients (Custom Label)',
    1,
    1
);
```

Then modify `dataset_form.php` to check for custom labels before displaying.

### 4. Add Pre-fill Logic

Modify `dataset_form.php` to load existing values:

```php
// After line where dataset structure is fetched
$existingData = dhis2_get(
    "dataValueSets?dataSet={$datasetUid}&period={$period}&orgUnit={$orgUnit}",
    $instanceKey
);
```

Then in the form rendering, populate inputs with existing values.

---

## Troubleshooting

### Issue 1: "DHIS2 configuration not found"

**Cause:** Survey's `dhis2_instance` field doesn't match any active instance.

**Solution:**
```sql
-- Check configured instances
SELECT instance_key, description, status FROM dhis2_instances WHERE status = 1;

-- Update survey with correct instance
UPDATE survey SET dhis2_instance = 'correct_key' WHERE id = 1;
```

### Issue 2: Organization Units Not Loading

**Cause:** AJAX endpoint not accessible or DHIS2 API timeout.

**Debug:**
1. Check browser console for errors
2. Check PHP error log:
```bash
tail -f fbs/admin/dhis2/php-error.log
```
3. Test DHIS2 API directly:
```bash
curl -u username:password "https://dhis2-instance/api/organisationUnits?paging=false"
```

### Issue 3: Period Format Errors

**Cause:** Incorrect period format for dataset's periodType.

**Solution:** Ensure period selector generates correct format:
- Monthly: `202401` not `2024-01`
- Quarterly: `2024Q1` not `2024-Q1`
- Weekly: `2024W05` not `2024-W05`

### Issue 4: Submission Fails with "Data element not part of dataset"

**Cause:** Data element UID doesn't belong to the selected dataset.

**Debug:**
```php
// In dataset_submit.php, add logging
error_log("Dataset UID: " . $datasetUid);
error_log("Data Elements: " . json_encode($dataValues));
```

**Solution:** Re-fetch dataset structure to ensure UIDs are correct.

### Issue 5: QR Code Not Displaying

**Cause:** QR code generation API unavailable or URL too long.

**Solution:**
- Use alternative QR library (e.g., PHP QR Code)
- Or self-host QR generator
- Check if URL is accessible from external network

---

## Testing Checklist

### Unit Tests
- [ ] Dataset form loads correctly for valid survey_id
- [ ] Period selector generates correct period format
- [ ] Organization units populate from DHIS2
- [ ] Data element inputs render with correct types
- [ ] Form validation works for required fields

### Integration Tests
- [ ] Submit new data to DHIS2 successfully
- [ ] Update existing data in DHIS2
- [ ] Error handling for DHIS2 API failures
- [ ] Local database saves submission correctly
- [ ] Success page displays accurate information

### End-to-End Tests
- [ ] QR code scan opens correct form
- [ ] Complete data entry flow works on mobile
- [ ] Submissions appear in DHIS2 data entry app
- [ ] Admin can view submission logs
- [ ] Retry failed submissions

---

## Future Enhancements

### Short-term
1. **Admin UI Integration**
   - Add dataset selection to survey creation form
   - Visualize submission statistics
   - Bulk data entry interface

2. **Offline Support**
   - Cache dataset structure
   - Queue submissions when offline
   - Sync when connection restored

3. **Data Validation**
   - Min/max value validation
   - Inter-field validation rules
   - Custom validation expressions

### Long-term
1. **Advanced Features**
   - Data import from Excel/CSV
   - Bulk editing of submissions
   - Data quality checks
   - Automated report generation

2. **Multi-language Support**
   - Translate data element names
   - RTL language support

3. **Analytics Dashboard**
   - Submission trends
   - Data completeness reports
   - Geographic visualization

---

## Support and Contribution

For issues or questions:
1. Check the troubleshooting section
2. Review DHIS2 API documentation: https://docs.dhis2.org
3. Check PHP error logs
4. Contact system administrator

---

## Appendix

### A. DHIS2 Value Types Reference

| Value Type | Description | Input Type |
|-----------|-------------|------------|
| TEXT | Short text | `<input type="text">` |
| LONG_TEXT | Paragraph | `<textarea>` |
| NUMBER | Decimal number | `<input type="number" step="0.01">` |
| INTEGER | Whole number | `<input type="number" step="1">` |
| INTEGER_POSITIVE | Positive integer | `<input type="number" min="1">` |
| INTEGER_ZERO_OR_POSITIVE | Zero or positive | `<input type="number" min="0">` |
| BOOLEAN | Yes/No | `<select>` with true/false |
| TRUE_ONLY | Yes only | `<select>` with true option |
| DATE | Calendar date | `<input type="date">` |
| PERCENTAGE | 0-100 | `<input type="number" min="0" max="100">` |

### B. Period Type Formats

| Period Type | Example | Regex Pattern |
|------------|---------|---------------|
| Daily | 20240115 | `^\d{8}$` |
| Weekly | 2024W03 | `^\d{4}W\d{2}$` |
| Monthly | 202401 | `^\d{6}$` |
| Quarterly | 2024Q1 | `^\d{4}Q[1-4]$` |
| Yearly | 2024 | `^\d{4}$` |

### C. Useful SQL Queries

**Find all aggregate surveys:**
```sql
SELECT id, name, program_dataset, dhis2_instance
FROM survey
WHERE program_type = 'aggregate' AND is_active = 1;
```

**View recent submissions:**
```sql
SELECT s.name, ds.period, ds.orgunit_uid, ds.submitted_at, ds.is_update
FROM dataset_submissions ds
JOIN survey s ON ds.survey_id = s.id
ORDER BY ds.submitted_at DESC
LIMIT 20;
```

**Check submission stats:**
```sql
SELECT
    s.name,
    COUNT(ds.id) AS total_submissions,
    SUM(ds.is_update) AS updates,
    COUNT(DISTINCT ds.period) AS periods_covered
FROM survey s
LEFT JOIN dataset_submissions ds ON s.id = ds.survey_id
WHERE s.program_type = 'aggregate'
GROUP BY s.id;
```

---

**Document Version:** 1.0
**Last Updated:** 2026-01-06
**Author:** Claude Code Assistant
