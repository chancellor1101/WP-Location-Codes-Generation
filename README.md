# NWS Location Codes Plugin

A WordPress plugin that creates and manages UGC (Universal Geographic Code) and SAME (Specific Area Message Encoding) codes from National Weather Service data for weather alert integration.

## Features

- **Two Custom Post Types**: UGC Codes and SAME Codes
- **Automatic Data Import**: Fetches and parses NWS location data
- **Code Derivation**: Automatically derives UGC and SAME codes from FIPS data
- **Meta Fields**: Stores comprehensive location information including:
  - State, County, Zone information
  - FIPS codes
  - Geographic coordinates (latitude/longitude)
  - Time zones
  - CWA (County Warning Area) assignments

## Installation

1. **Upload the Plugin**
   - Create a folder called `nws-location-codes` in your WordPress plugins directory (`wp-content/plugins/`)
   - Place all plugin files in this folder

2. **File Structure**
   ```
   nws-location-codes/
   ├── nws-location-codes.php (main plugin file)
   ├── js/
   │   └── admin-import.js
   └── css/
       └── admin-import.css
   ```

3. **Activate the Plugin**
   - Go to WordPress Admin → Plugins
   - Find "NWS Location Codes" and click "Activate"

## Usage

### Importing Data

1. Navigate to **UGC Codes → Import Data** in the WordPress admin menu
2. Click the **Import Data** button
3. Wait for the import to complete (this may take a few minutes)
4. View the import statistics showing:
   - Number of UGC Codes created
   - Number of SAME Codes created
   - Total records processed

### Managing Codes

**UGC Codes**
- Access via **UGC Codes** menu in WordPress admin
- Each post represents a unique UGC code
- Title format: `County Name (UGC Code)`
- Example: `Denton (TXC121)`

**SAME Codes**
- Access via **SAME Codes** menu in WordPress admin
- Each post represents a unique SAME code
- Title format: `County Name (SAME Code)`
- Example: `Denton (048121)`

### Code Format Reference

#### UGC Code Format
**Format**: `STATE + TYPE + LAST_3_FIPS`

**Example**: `TXC121`
- `TX` = Texas (state)
- `C` = County (type)
- `121` = Last 3 digits of FIPS code (48121)

**Types**:
- `C` = County
- `Z` = Zone

#### SAME Code Format
**Format**: `0 + STATE_FIPS + COUNTY_CODE`

**Example**: `048121`
- `0` = Leading zero
- `48` = State FIPS (Texas)
- `121` = County code

### Example Data Breakdown

Given this raw data:
```
TX|103|FWD|Denton|TX103|Denton|48121|C|nc|33.2043|-97.1171
```

**Parsed Values**:
- State: `TX`
- Zone/County ID: `103`
- County Name: `Denton`
- FIPS: `48121`
- UGC Type: `C` (county)

**Derived Codes**:
- UGC: `TXC121` (TX + C + 121)
- SAME: `048121` (0 + 48 + 121)
- FIPS: `48121` (already present)

### Meta Fields

**UGC Code Post Meta**:
- `ugc_code` - The UGC code
- `state` - Two-letter state abbreviation
- `zone` - Three-digit zone number
- `cwa` - County Warning Area ID
- `zone_name` - Name of the zone
- `county` - County name
- `fips` - 5-digit FIPS code
- `ugc_type` - Type of UGC (C or Z)
- `time_zone` - Time zone
- `lat` - Latitude
- `lon` - Longitude

**SAME Code Post Meta**:
- `same_code` - The SAME code
- `state` - Two-letter state abbreviation
- `county` - County name
- `fips` - 5-digit FIPS code
- `state_fips` - 2-digit state FIPS
- `county_code` - 3-digit county code
- `time_zone` - Time zone
- `lat` - Latitude
- `lon` - Longitude

## Future Integration

This plugin is designed to integrate with weather alert systems. In future updates, you'll be able to:
- Link weather alerts to UGC and SAME codes
- Allow users to click alerts to view county/state information
- Display all alerts for a specific location
- Create relationships between alerts and location codes

## Data Source

Data is sourced from the National Weather Service:
- **URL**: https://www.weather.gov/gis/ZoneCounty
- **Current File**: bp05mr24.dbx (March 5, 2024)
- **Format**: Pipe-delimited text file

## API / Developer Usage

### Query Posts by Code

**Get UGC Code by Code Value**:
```php
$args = array(
    'post_type' => 'ugc_code',
    'meta_key' => 'ugc_code',
    'meta_value' => 'TXC121',
    'posts_per_page' => 1
);
$ugc_post = get_posts($args);
```

**Get SAME Code by Code Value**:
```php
$args = array(
    'post_type' => 'same_code',
    'meta_key' => 'same_code',
    'meta_value' => '048121',
    'posts_per_page' => 1
);
$same_post = get_posts($args);
```

**Get All Codes for a State**:
```php
$args = array(
    'post_type' => 'ugc_code',
    'meta_key' => 'state',
    'meta_value' => 'TX',
    'posts_per_page' => -1
);
$texas_codes = get_posts($args);
```

## Clearing Data

To remove all imported data:
1. Go to **UGC Codes → Import Data**
2. Click the **Clear All Data** button
3. Confirm the action

⚠️ **Warning**: This action cannot be undone!

## System Requirements

- WordPress 5.0 or higher
- PHP 7.0 or higher
- cURL or allow_url_fopen enabled for remote data fetching

## Support

For issues or questions about this plugin, please refer to:
- National Weather Service GIS Data: https://www.weather.gov/gis/
- WordPress Codex: https://codex.wordpress.org/

## License

GPL v2 or later

## Changelog

### Version 1.0.0
- Initial release
- UGC and SAME custom post types
- NWS data import functionality
- Meta field management
- Admin interface with import/clear options