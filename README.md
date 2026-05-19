# Survey Engine

This project is a PHP-based survey management tool called "FormBase". It allows users to create, deploy, and analyze surveys with features like a visual survey builder, DHIS2 integration, and real-time analytics.

## Features

*   **Survey Management:** Create, edit, and manage surveys.
*   **Question Bank:** A repository of questions for building surveys.
*   **User Management:** Manage admin users.
*   **DHIS2 Integration:** Sync survey data with DHIS2 instances.
*   **Reporting and Analytics:** View survey submissions and analyze data through a dashboard with charts and statistics.
*   **Mobile-First Design:** Surveys are optimized for mobile devices.
*   **Advanced Question Types:** Supports various question types, including conditional logic and validation.
*   **Multi-Language Support:** Create surveys in multiple languages.

## Installation

1.  **Prerequisites:**
    *   PHP
    *   MySQL or other compatible database
    *   Composer

2.  **Clone the repository:**
    ```bash
    git clone <repository-url>
    ```

3.  **Install dependencies:**
    ```bash
    cd fbs
    composer install
    ```

4.  **Database Setup:**
    *   Create a database for the application.
    *   Import the latest SQL file from the `db/` directory to set up the necessary tables:
        ```bash
        mysql -u <username> -p <database_name> < db/fbtv3_20250810.sql
        ```
    *   Configure the database connection in `fbs/admin/connect.php` with your database credentials.

5.  **Web Server Configuration:**
    *   Point your web server's document root to the project directory.
    *   If using Apache, ensure the `.htaccess` file is configured to handle URL rewriting for the survey engine routes.
    *   For subfolder installations (e.g., `/survey-engine/`), update the base paths in:
        *   `.htaccess` - Adjust RewriteBase if needed
        *   `fbs/admin/connect.php` - Update any absolute path references
        *   Configuration files to reflect the correct subfolder structure

## Apache .htaccess Configuration

The project requires proper `.htaccess` configuration for optimal functionality. Create or update the `.htaccess` file in your project root:

### Basic .htaccess Configuration

```apache
# Enable rewrite engine
RewriteEngine On

# Set base path for subfolder installations
# Uncomment and adjust if installing in a subfolder like /survey-engine/
# RewriteBase /survey-engine/

# Security Headers
Header always set X-Frame-Options DENY
Header always set X-Content-Type-Options nosniff
Header always set X-XSS-Protection "1; mode=block"
Header always set Strict-Transport-Security "max-age=31536000; includeSubDomains"

# Hide sensitive files
<Files ~ "^\.">
    Order allow,deny
    Deny from all
</Files>

# Protect configuration files
<FilesMatch "\.(env|ini|conf|config)$">
    Order allow,deny
    Deny from all
</FilesMatch>

# Protect database files
<FilesMatch "\.sql$">
    Order allow,deny
    Deny from all
</FilesMatch>

# Block access to vendor directories from web
<IfModule mod_alias.c>
    RedirectMatch 404 /vendor/
    RedirectMatch 404 /\.git
    RedirectMatch 404 /\.env
</IfModule>

# Enable compression for better performance
<IfModule mod_deflate.c>
    AddOutputFilterByType DEFLATE text/plain
    AddOutputFilterByType DEFLATE text/html
    AddOutputFilterByType DEFLATE text/xml
    AddOutputFilterByType DEFLATE text/css
    AddOutputFilterByType DEFLATE application/xml
    AddOutputFilterByType DEFLATE application/xhtml+xml
    AddOutputFilterByType DEFLATE application/rss+xml
    AddOutputFilterByType DEFLATE application/javascript
    AddOutputFilterByType DEFLATE application/x-javascript
    AddOutputFilterByType DEFLATE application/json
</IfModule>

# Set cache headers for static assets
<IfModule mod_expires.c>
    ExpiresActive on
    ExpiresByType text/css "access plus 1 month"
    ExpiresByType application/javascript "access plus 1 month"
    ExpiresByType image/png "access plus 1 month"
    ExpiresByType image/jpg "access plus 1 month"
    ExpiresByType image/jpeg "access plus 1 month"
    ExpiresByType image/gif "access plus 1 month"
    ExpiresByType image/svg+xml "access plus 1 month"
</IfModule>

# Custom error pages (optional)
ErrorDocument 404 /fbs/public/404.php
ErrorDocument 500 /fbs/public/500.php

# PHP settings for file uploads and execution
<IfModule mod_php.c>
    php_value upload_max_filesize 10M
    php_value post_max_size 10M
    php_value max_execution_time 300
    php_value memory_limit 256M
</IfModule>

# Survey form routing
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^survey/([0-9]+)/?$ fbs/public/survey_page.php?survey_id=$1 [L,QSA]

# Tracker program routing
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^tracker/([0-9]+)/?$ fbs/public/tracker_program_form.php?survey_id=$1 [L,QSA]

# Admin panel routing
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^admin/?$ fbs/admin/ [L,R=301]
```

### Subfolder Installation .htaccess

If installing in a subfolder (e.g., `http://yoursite.com/survey-engine/`):

```apache
RewriteEngine On
RewriteBase /survey-engine/

# All other rules remain the same, but update routing rules:
RewriteRule ^survey/([0-9]+)/?$ fbs/public/survey_page.php?survey_id=$1 [L,QSA]
RewriteRule ^tracker/([0-9]+)/?$ fbs/public/tracker_program_form.php?survey_id=$1 [L,QSA]
RewriteRule ^admin/?$ fbs/admin/ [L,R=301]
```

### Security Considerations

1. **File Protection:** The `.htaccess` configuration blocks access to sensitive files like `.env`, configuration files, and database dumps.

2. **Directory Traversal:** Protects against directory traversal attacks by blocking access to hidden files and vendor directories.

3. **Security Headers:** Implements essential security headers to protect against XSS, clickjacking, and content-type sniffing attacks.

4. **HTTPS Enforcement:** The HSTS header encourages browsers to use HTTPS connections.

### Performance Optimization

1. **Compression:** Enables gzip compression for text-based files to reduce transfer sizes.

2. **Caching:** Sets appropriate cache headers for static assets to improve loading times.

3. **PHP Settings:** Optimizes PHP settings for file uploads and script execution.

### Troubleshooting .htaccess Issues

**500 Internal Server Error:**
- Check that `mod_rewrite` is enabled on your server
- Verify file permissions (644 for .htaccess)
- Comment out sections to identify problematic rules

**URL Routing Not Working:**
- Ensure RewriteBase is set correctly for subfolder installations
- Check that Apache has permission to read .htaccess files
- Verify mod_rewrite module is installed and enabled

**Upload Issues:**
- Adjust `upload_max_filesize` and `post_max_size` as needed
- Ensure your server's PHP configuration allows the specified limits

6.  **Remove Unused Dependencies:**
    *   Delete the root-level `vendor/` directory as the application uses `fbs/vendor/` instead:
        ```bash
        rm -rf vendor/
        ```

## Usage

*   **Admin Panel:** Access the admin panel by navigating to `fbs/admin/`. The default login credentials may need to be configured or reset.
*   **Survey Submission:** Surveys can be accessed and submitted via their respective URLs. The `fbs/submit.php` file handles form submissions.
*   **Landing Page:** The main landing page is `index.php`.

## Survey Types & Form Creation

FormBase supports three types of survey forms, each with different capabilities and use cases:

### 1. DHIS2 Tracker Program Forms

**Overview:** These forms are based on DHIS2 Tracker Programs and support complex data collection with tracked entities, program stages, and sections.

**Features:**
- Multi-stage data collection workflows
- Tracked entity registration and enrollment
- Program stage sections for organized data entry
- Duplicate detection for unique attributes (phone, email, ID)
- Real-time validation against DHIS2 data
- Support for both sectioned and non-sectioned data elements
- Dynamic images and branding support
- Mobile-optimized interface

**Creation Steps:**

1. **Configure DHIS2 Connection:**
   - Navigate to Admin Panel → Settings → DHIS2 Configuration
   - Add your DHIS2 instance URL, username, and password
   - Test the connection to ensure it's working

2. **Create New Survey:**
   - Go to Admin Panel → Survey Management → Create New Survey
   - Select "DHIS2 Tracker" as the survey type
   - Choose your configured DHIS2 instance

3. **Select Tracker Program:**
   - Choose the DHIS2 Tracker Program from the dropdown
   - The system will automatically fetch program stages, sections, and data elements
   - Both sectioned and non-sectioned data elements will be included

4. **Configure Form Settings:**
   - Set survey title and description
   - Configure survey deadline and status (active/inactive)
   - Add dynamic images/logos if needed
   - Set up location/facility selection if required

5. **Preview & Test:**
   - Use the Preview function to test the form
   - Verify all program stages are displayed correctly
   - Test duplicate detection for unique attributes
   - Ensure proper validation and submission flow

6. **Deploy:**
   - Activate the survey to make it available
   - Share the survey URL for data collection
   - Monitor submissions through the Analytics dashboard

**Access:** `fbs/public/tracker_program_form.php?survey_id={id}`

### 2. DHIS2 Non-Tracker (Event) Program Forms

**Overview:** These forms are based on DHIS2 Event Programs for single-event data collection without tracked entities.

**Features:**
- Single-stage data collection
- Event-based data entry
- DHIS2 data element validation
- Option set support for dropdowns
- Simpler workflow than tracker programs
- Direct submission to DHIS2 events

**Creation Steps:**

1. **Configure DHIS2 Connection:**
   - Ensure DHIS2 instance is configured in Settings

2. **Create New Survey:**
   - Go to Admin Panel → Survey Management → Create New Survey
   - Select "DHIS2 Event Program" as the survey type
   - Choose your DHIS2 instance

3. **Select Event Program:**
   - Choose the DHIS2 Event Program from available options
   - System fetches program stages and data elements
   - Configure organization unit selection

4. **Form Configuration:**
   - Set form title and metadata
   - Configure validation rules
   - Set up conditional logic if needed
   - Add branding elements

5. **Testing & Deployment:**
   - Preview the form to ensure proper layout
   - Test data submission to DHIS2
   - Activate and deploy for data collection

**Access:** Standard survey form interface with DHIS2 event submission

### 3. DHIS2 Aggregate Dataset Forms

**Overview:** These forms are based on DHIS2 Aggregate Data Sets for routine data collection and reporting. Designed for periodic data entry (monthly, quarterly, yearly) across multiple organization units.

**Features:**
- Period-based data collection (Daily, Weekly, Monthly, Quarterly, Yearly)
- Organization unit (facility) selection with hierarchical search
- Three form rendering types: Default, Section-based, and Custom HTML
- Category combinations for disaggregation (e.g., Age/Gender breakdowns)
- Dataset-level attributes (e.g., School Term, Data Set Type)
- Option sets for dropdown selections
- Validation rules from DHIS2
- Load existing data from DHIS2 for updates
- Automatic completion registration
- Local caching for performance
- Greyed/disabled fields support
- Mobile-responsive design

**Creation Steps:**

1. **Configure DHIS2 Connection:**
   - Navigate to Admin Panel → Settings → DHIS2 Configuration
   - Ensure your DHIS2 instance is configured with proper credentials
   - Test the connection

2. **Create New Survey:**
   - Go to Admin Panel → Survey Management → Create New Survey
   - Select "DHIS2 Aggregate Dataset" as the survey type
   - Choose your configured DHIS2 instance
   - Select the Dataset from the dropdown (fetched from DHIS2)
   - Save the survey

3. **Sync Dataset Metadata:**
   - Navigate to Admin Panel → Dataset Preview
   - Click "Sync Metadata" button
   - System fetches and stores locally:
     - Dataset basic info (name, description, period type)
     - Data elements organized by sections
     - Category combinations and disaggregations
     - Option sets with values
     - Organization units assigned to the dataset
     - Validation rules
     - Custom HTML forms (if configured in DHIS2)
   - Metadata cached for fast form loading

4. **Configure Form Layout:**
   - In Dataset Preview, customize appearance:
     - Toggle flag bar display (Uganda flag colors)
     - Configure data element visibility
     - Set custom display order
     - Mark fields as required
     - Filter organization units by instance/hierarchy level
   - Save layout settings

5. **Preview & Test:**
   - Use the live preview panel to see the form
   - Test facility search functionality
   - Try different period selections
   - Test "Load Existing Data" feature
   - Verify validation rules
   - Check category combinations render correctly

6. **Deploy:**
   - Activate the survey
   - Share the dataset URL: `/d/{survey_id}`
   - Users can now submit data to DHIS2

**Access:**
- Public form: `/d/{survey_id}` (e.g., `http://yoursite.com/d/20`)
- Share page: `/share/d/{survey_id}` (QR code generation)
- Admin preview: `fbs/admin/dataset_preview.php?survey_id={survey_id}`

**Technical Architecture:**

**Database Tables:**
- `surveys` - Main survey/dataset configuration
- `dataset_metadata` - Basic dataset info (name, period type, form type)
- `dataset_data_elements` - Data elements with value types
- `dataset_category_option_combos` - Disaggregations (Age, Gender, etc.)
- `dataset_option_sets` & `dataset_option_values` - Dropdown options
- `dataset_attribute_categories` - Dataset-level attributes
- `dataset_org_units` - Facilities/organization units
- `dataset_layout_settings` - Custom appearance settings
- `dataset_dataelement_settings` - Per-element configuration
- `dataset_submissions` - Submitted data with DHIS2 responses
- `dataset_metadata_cache` - Performance cache (60 min TTL)

**Form Rendering (Form Renderer Architecture):**

The system uses three different renderers based on DHIS2 form configuration:

1. **DefaultFormRenderer:**
   - Simple flat list of data elements
   - No section grouping
   - Category combos shown in nested tables
   - Best for small datasets (< 20 elements)

2. **SectionFormRenderer:**
   - Organizes data elements by sections
   - Horizontal grid layout for category combinations:
     ```
     Data Element | Male | Female | Total
     -------------|------|--------|------
     Enrollment   | [  ] | [  ]   | [  ]
     Dropout      | [  ] | [  ]   | [  ]
     ```
   - Vertical list layout option
   - Best for medium-large datasets with logical grouping

3. **CustomFormRenderer:**
   - Parses DHIS2 custom HTML forms (`dataEntryForm`)
   - Replaces DHIS2 placeholders with functional inputs:
     - `{dataElementUid}-{categoryOptionComboUid}-val`
     - `{dataElementUid}-val`
   - Preserves custom styles, classes, JavaScript
   - Supports complex layouts designed in DHIS2 maintenance app
   - Best for highly customized forms with specific layouts

**Data Flow:**

1. **Form Load:**
   - User visits `/d/{survey_id}`
   - System loads metadata from LOCAL database (no API call)
   - Renderer selected based on `formType`
   - Form HTML generated with proper inputs

2. **User Interaction:**
   - Facility search: Queries local `dataset_org_units` table
   - Period selection: Dynamic UI based on `periodType`
   - Attribute selection: If dataset has non-default category combo
   - Data entry gate: Data entry UI stays hidden until facility + period are selected
   - Data entry: Inputs rendered based on `valueType`

3. **Load Existing Data (Optional):**
   - User clicks "Load Existing Data"
   - System queries DHIS2: `/api/dataValueSets?dataSet={uid}&orgUnit={uid}&period={period}`
   - Pre-fills form with existing values
   - User can update and resubmit

4. **Validation:**
   - Client-side: HTML5 validation, required fields
   - DHIS2 validation rules: Real-time evaluation
     - Expressions: `#{dataElement.cocUid}`
     - Operators: equal_to, greater_than, less_than, etc.
   - Custom validation: Blocks submission if rules fail

5. **Submission:**
   - JavaScript collects all data values
   - AJAX POST to `dataset_submit.php`
   - Server validates and checks for existing data (unless client sends `is_update`)
   - Submits to DHIS2: `/api/dataValueSets` (async mode supported)
   - Marks dataset complete: `/api/completeDataSetRegistrations` (can be skipped on updates)
   - Saves locally in `dataset_submissions`
   - Redirects to success page: `/dataset-success/{submission_id}`

**DHIS2 Integration:**

**Metadata Sync:**
- Fetches complete dataset structure from DHIS2
- Endpoint: `/api/dataSets/{uid}.json?fields=[complex field list]`
- Stores in local tables for fast rendering
- Updates on admin trigger (no automatic sync)

**Data Submission:**
- Submits to `/api/dataValueSets` endpoint
- Payload format:
  ```json
  {
    "dataSet": "datasetUid",
    "period": "202401",
    "orgUnit": "orgUnitUid",
    "completeDate": "2024-01-15",
    "attributeOptionCombo": "aocUid",
    "dataValues": [
      {
        "dataElement": "deUid1",
        "value": "100",
        "categoryOptionCombo": "cocUid1"
      }
    ]
  }
  ```
- Handles both new submissions and updates
- Error logging to `dhis2_error_log` table

**Authentication:**
- HTTP Basic Auth or Bearer token
- Per-instance configuration in `dhis2_instances` table
- Credentials stored securely

**Caching:**
- Metadata cached for 60 minutes
- Form loading uses local data (zero API calls)
- Only submission and "load existing" hit DHIS2
- Massive performance improvement for field users

**Advanced Features:**

1. **Category Combinations:**
   - Automatic disaggregation by Age, Gender, etc.
   - Horizontal grid or vertical list layouts
   - Default category combo detection

2. **Option Sets:**
   - Dropdown selections from DHIS2
   - Values submitted as codes, displayed as labels
   - Lazy loading support

3. **Greyed Fields:**
   - DHIS2-configured disabled fields
   - Rendered with `disabled` attribute
   - Visual indication with `.greyed-field` class

4. **Validation Rules:**
   - Real-time JavaScript evaluation
   - Supports complex expressions
   - Visual feedback (red border on invalid)
   - Blocks submission if validation fails

5. **Section Autosave + Status Colors (SECTION forms only):**
   - Each section header shows a status dot and text:
     - Grey = not saved
     - Yellow = changed (dirty)
     - Cyan = saving
     - Green = saved
     - Red = save failed
   - Autosave sends only that section’s data with `async_submit: true`
   - Final Submit still sends the full payload
   - Custom forms do not show per‑section status colors

5. **Period Type Support:**
   - Daily: Date picker
   - Weekly: Year + Week number
   - Monthly: Year + Month
   - Quarterly: Year + Quarter
   - Yearly/Financial: Year only
   - Automatic DHIS2 format conversion

6. **Custom Form Scripts:**
   - Extracts JavaScript from DHIS2 custom forms
   - Executes after form render
   - Supports totals, conditionals, etc.
   - Security: External scripts filtered

**Best Practices:**

1. **Sync Metadata Regularly:**
   - Sync before major changes in DHIS2
   - After adding new data elements
   - After modifying option sets

2. **Configure Layout Settings:**
   - Hide unused data elements
   - Order fields logically
   - Mark critical fields as required

3. **Test Thoroughly:**
   - Test with different facilities
   - Try various periods
   - Test update functionality
   - Verify validation rules

4. **Monitor Submissions:**
   - Check `dataset_submissions` table
   - Review DHIS2 responses for errors
   - Use `dhis2_error_log` for debugging

5. **Performance:**
   - Metadata cache reduces API calls
   - Local storage enables offline-first design
   - Organization unit search optimized with indexes

### 4. Local Survey Forms

**Overview:** Traditional survey forms stored locally in the database without DHIS2 integration.

**Features:**
- Custom question types (text, radio, checkbox, dropdown, etc.)
- Conditional logic and skip patterns
- File upload support
- Local data storage
- Question bank integration
- Custom validation rules
- Drag-and-drop form builder

**Creation Steps:**

1. **Access Survey Builder:**
   - Navigate to Admin Panel → Survey Management
   - Click "Create New Survey" → "Local Survey"

2. **Build Survey Structure:**
   - Use the visual Survey Builder (`sb.php`)
   - Add questions from Question Bank or create new ones
   - Configure question types:
     - Text input
     - Radio buttons (single choice)
     - Checkboxes (multiple choice)
     - Dropdown menus
     - File uploads
     - Rating scales

3. **Configure Logic:**
   - Set up conditional logic (show/hide questions based on answers)
   - Add validation rules (required fields, format validation)
   - Configure skip patterns

4. **Form Settings:**
   - Set survey title, description, and instructions
   - Configure submission settings
   - Set survey deadline and access controls
   - Add custom styling/branding

5. **Preview & Test:**
   - Use Preview function to test all question types
   - Verify conditional logic works correctly
   - Test form validation and submission

6. **Deploy:**
   - Activate the survey
   - Share survey URL
   - Monitor submissions in Analytics dashboard

**Access:** `fbs/public/survey_page.php?survey_id={id}`

## Form Management Best Practices

### Survey Configuration
- Always test forms thoroughly before deployment
- Set appropriate deadlines and access controls
- Use clear, concise question text
- Implement proper validation to ensure data quality

### DHIS2 Integration
- Ensure DHIS2 connectivity is stable before deployment
- Test data synchronization with small datasets first
- Monitor for API rate limits and adjust accordingly
- Keep DHIS2 credentials secure and rotate regularly

### Data Quality
- Use duplicate detection for unique identifiers
- Implement client-side validation for immediate feedback
- Set up server-side validation as a fallback
- Provide clear error messages to users

### Mobile Optimization
- Test forms on various mobile devices and screen sizes
- Ensure touch-friendly interface elements
- Optimize image sizes for faster loading
- Use progressive enhancement for advanced features

### Performance
- Monitor form loading times, especially on slower connections
- Optimize image assets and implement lazy loading
- Cache DHIS2 metadata locally when possible
- Use appropriate database indexes for faster queries

## File Structure & Key Components

### Admin Interface
```
fbs/admin/
├── components/           # Reusable UI components
│   ├── aside.php        # Sidebar navigation
│   ├── navbar.php       # Top navigation bar
│   └── footer.php       # Footer component
├── settings/            # Settings management
│   ├── profile_tab.php  # User profile settings
│   ├── dhis2_tab.php    # DHIS2 configuration
│   └── users_tab.php    # User management
├── dhis2/              # DHIS2 integration endpoints
│   ├── check_duplicate.php    # Duplicate detection API
│   ├── location_manager.php   # Location data management
│   └── tracker_submit.php     # Tracker program submission
├── main.php            # Dashboard homepage
├── survey.php          # Survey management interface
├── sb.php             # Visual survey builder
├── records.php        # Analytics and reporting
└── tracker_preview.php # Tracker program preview
```

### Public Forms
```
fbs/public/
├── tracker_program_form.php    # DHIS2 Tracker Program forms
├── survey_page.php            # Local survey forms
├── submit.php                 # Form submission handler
└── tracker_program_submit.php # Tracker submission handler
```

### Core Components
```
fbs/
├── admin/connect.php          # Database configuration
├── includes/                  # Shared utilities
│   ├── profile_helper.php    # User profile functions
│   └── location_helper.php   # Location utilities
└── vendor/                   # Composer dependencies
```

### Key Features by File

**tracker_program_form.php:**
- Multi-stage form interface
- Section-based data organization
- Duplicate detection for unique attributes
- Real-time DHIS2 validation
- Mobile-responsive design
- Dynamic image support

**survey_page.php:**
- Traditional survey interface
- Question bank integration
- Conditional logic support
- Local data storage
- File upload handling

**sb.php (Survey Builder):**
- Drag-and-drop question builder
- Question type management
- Conditional logic configuration
- Form preview functionality
- Question bank integration

**tracker_preview.php:**
- Admin preview for tracker programs
- Program stage visualization
- Data element organization
- Section management interface

## Dependencies

This project uses the following PHP libraries, managed by Composer:

*   `mpdf/mpdf`: A PHP library for generating PDF files.
*   `phpoffice/phpspreadsheet`: A PHP library for reading and writing spreadsheet files.

## Database

The database schema is defined in the SQL files located in the `db/` directory. The key tables include:

*   `survey`: Stores survey information.
*   `question`: Stores survey questions.
*   `submission`: Stores survey submission metadata.
*   `submission_response`: Stores individual responses to questions.
*   `users`: Stores admin user information.

## API

The application includes a few API endpoints for asynchronous data retrieval:

*   `fbs/admin/api/question_groupings.php`
*   `fbs/admin/api/questions.php`
*   `fbs/api/get_submissions.php`
*   `fbs/admin/dhis2/`: Contains several endpoints for DHIS2 integration.
*   `fbs/admin/dashboard_api.php`: Provides analytics data for survey dashboards, including tracker program support.

## Recent Updates

### Performance & UX Improvements (September 2025)
*   **Image Loading Optimization:** Fixed layout shift issues caused by large profile images in admin navigation
*   **DHIS2 API Enhancement:** Added `programStageDataElements` to tracker program API calls to support non-sectioned data elements
*   **Survey Status Styling:** Improved "Survey Deadline Reached" message with better visual design and red background
*   **Profile Image Management:** Enhanced profile image upload with fixed dimensions and lazy loading for better performance
*   **Duplicate Detection:** Improved user experience for duplicate attribute checking with context-aware error messages
*   **Admin Navigation:** Optimized sidebar and navbar image loading for faster navigation on slower servers

### DHIS2 Location Management & UI Improvements (August 2025)
*   **DHIS2 Path Conversion:** Implemented system to convert DHIS2 UIDs to human-readable location paths (e.g., `MoES Uganda → Acholi Region → Gulu District`)
*   **Unified Location API:** Created `location_manager.php` to handle all location operations (get map, fetch missing, enrich locations) in a single endpoint
*   **Enhanced Facility Dropdown:** Redesigned facility selection with horizontal scrollable columns (5 items per column) for better space utilization
*   **Smart Search:** Dropdown only appears after typing 2+ characters and hides automatically after selection
*   **Improved Layout:** Moved tracker header and step navigation inside main container for better visual cohesion and consistent styling
*   **Location Caching:** Added automatic caching of DHIS2 location data in local database for improved performance

### Tracker Program Support (August 2025)
*   **Enhanced Dashboard Analytics:** Fixed tracker program dashboard to properly display analytics for DHIS2 tracker programs
*   **Question Analysis:** Improved question response analysis for tracker programs that store data in JSON format in the `tracker_submissions` table
*   **Survey Type Identification:** Removed T, E, A bracket indicators from survey names since `domain_type` column now properly identifies survey types
*   **Vendor Cleanup:** Consolidated dependencies to use `fbs/vendor/` directory only, removing duplicate root-level `vendor/` folder
*   **Question Grouping System:** Enhanced tracker preview with drag-and-drop question grouping functionality for better form organization

### Database Schema
*   **Location Table:** Enhanced with better hierarchy management and path conversion support
*   **Tracker Tables:** Added `tracker_submissions` table for DHIS2 tracker program data storage  
*   **Program Types:** Enhanced `survey` table with `program_type` and `domain_type` columns for better survey classification

### Technical Improvements
*   **File Consolidation:** Merged multiple location-related PHP files into unified endpoints
*   **CSS Enhancements:** Added responsive flexbox layouts, improved container styling, and better visual hierarchy
*   **JavaScript Optimization:** Enhanced dropdown behavior, keyboard navigation, and search functionality
*   **Error Handling:** Improved error logging and user feedback for DHIS2 API operations
