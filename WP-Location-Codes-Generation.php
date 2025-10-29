<?php
/**
 * Plugin Name: NWS Location Codes
 * Plugin URI: https://kn4oqw.com/nws-location-codes
 * Description: Manages UGC and SAME codes from National Weather Service data for weather alert integration
 * Version: 1.0.5
 * Author: Clint Chance (KN4OQW)
 * Author URI: https://kn4oqw.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nws-location-codes
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class NWS_Location_Codes {
    
    private $version = '1.0.5';
    private $data_url = 'https://www.weather.gov/source/gis/Shapefiles/County/bp05mr24.dbx';
    private $shapefile_url = 'https://www.weather.gov/source/gis/Shapefiles/WSOM/z_18mr25.zip';
    
    public function __construct() {
        // Register custom post types
        add_action('init', array($this, 'register_post_types'));
        
        // Add admin menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Add admin scripts
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        
        // AJAX handlers
        add_action('wp_ajax_nws_import_data', array($this, 'ajax_import_data'));
        add_action('wp_ajax_nws_clear_data', array($this, 'ajax_clear_data'));
        add_action('wp_ajax_nws_import_polygons', array($this, 'ajax_import_polygons'));
        add_action('wp_ajax_nws_process_polygon_batch', array($this, 'ajax_process_polygon_batch'));
        
        // Add meta boxes
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'));
        
        // Add custom admin columns
        add_filter('manage_ugc_code_posts_columns', array($this, 'ugc_columns'));
        add_action('manage_ugc_code_posts_custom_column', array($this, 'ugc_column_content'), 10, 2);
        add_filter('manage_same_code_posts_columns', array($this, 'same_columns'));
        add_action('manage_same_code_posts_custom_column', array($this, 'same_column_content'), 10, 2);
        
        // Register activation hook
        register_activation_hook(__FILE__, array($this, 'activate'));
    }
    
    /**
     * AJAX handler for polygon import initialization
     */
    public function ajax_import_polygons() {
        check_ajax_referer('nws_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        // Increase limits
        set_time_limit(600);
        ini_set('memory_limit', '512M');
        
        $simplify = isset($_POST['simplify']) && $_POST['simplify'] === 'true';
        
        // Download and extract shapefile
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/nws-temp/';
        
        // Create temp directory
        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
        }
        
        $zip_file = $temp_dir . 'shapefile.zip';
        
        // Download the shapefile
        $response = wp_remote_get($this->shapefile_url, array(
            'timeout' => 120,
            'stream' => true,
            'filename' => $zip_file
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => 'Failed to download shapefile: ' . $response->get_error_message()
            ));
        }
        
        // Extract ZIP
        WP_Filesystem();
        $unzip_result = unzip_file($zip_file, $temp_dir);
        
        if (is_wp_error($unzip_result)) {
            wp_send_json_error(array(
                'message' => 'Failed to extract shapefile: ' . $unzip_result->get_error_message()
            ));
        }
        
        // Find the .shp file
        $files = glob($temp_dir . '*.shp');
        if (empty($files)) {
            wp_send_json_error(array(
                'message' => 'No .shp file found in archive'
            ));
        }
        
        $shp_file = $files[0];
        
        // Store file path in transient for batch processing
        set_transient('nws_shapefile_path', $shp_file, 3600);
        set_transient('nws_simplify_polygons', $simplify, 3600);
        
        // Get total record count
        require_once(plugin_dir_path(__FILE__) . 'includes/shapefile.php');
        
        try {
            $shapefile = new ShapeFile($shp_file);
            $total_records = 0;
            while ($record = $shapefile->getRecord()) {
                if ($record === false) break;
                $total_records++;
            }
            
            wp_send_json_success(array(
                'total_records' => $total_records,
                'message' => 'Shapefile downloaded and ready for processing'
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Error reading shapefile: ' . $e->getMessage()
            ));
        }
    }
    
    /**
     * AJAX handler for processing polygon batches
     */
    public function ajax_process_polygon_batch() {
        check_ajax_referer('nws_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        set_time_limit(120);
        ini_set('memory_limit', '512M');
        
        $batch_size = isset($_POST['batch_size']) ? intval($_POST['batch_size']) : 50;
        $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
        
        $shp_file = get_transient('nws_shapefile_path');
        $simplify = get_transient('nws_simplify_polygons');
        
        if (!$shp_file || !file_exists($shp_file)) {
            wp_send_json_error(array('message' => 'Shapefile not found. Please restart import.'));
        }
        
        require_once(plugin_dir_path(__FILE__) . 'includes/shapefile.php');
        
        try {
            $shapefile = new ShapeFile($shp_file);
            $processed = 0;
            $updated = 0;
            $not_found = 0;
            $current = 0;
            
            while ($record = $shapefile->getRecord()) {
                if ($record === false) break;
                
                // Skip to offset
                if ($current < $offset) {
                    $current++;
                    continue;
                }
                
                // Process batch
                if ($processed >= $batch_size) {
                    break;
                }
                
                $dbf_data = $record['dbf'];
                $shp_data = $record['shp'];
                
                // Get the zone identifier (STATE_ZONE field)
                $state_zone = isset($dbf_data['STATE_ZONE']) ? trim($dbf_data['STATE_ZONE']) : '';
                
                if (empty($state_zone)) {
                    $processed++;
                    $current++;
                    continue;
                }
                
                // Construct UGC code from state_zone (e.g., "AKZ017" from state_zone)
                $ugc_code = $state_zone;
                
                // Find matching UGC post
                $posts = get_posts(array(
                    'post_type' => 'ugc_code',
                    'meta_key' => 'ugc_code',
                    'meta_value' => $ugc_code,
                    'posts_per_page' => 1,
                ));
                
                if (empty($posts)) {
                    $not_found++;
                    $processed++;
                    $current++;
                    continue;
                }
                
                $post_id = $posts[0]->ID;
                
                // Extract polygon coordinates
                $coordinates = $this->extract_coordinates($shp_data, $simplify);
                
                if (!empty($coordinates)) {
                    update_post_meta($post_id, 'polygon_coordinates', json_encode($coordinates));
                    update_post_meta($post_id, 'has_polygon', '1');
                    $updated++;
                }
                
                $processed++;
                $current++;
            }
            
            // Check if we're done
            $shapefile_temp = new ShapeFile($shp_file);
            $total = 0;
            while ($shapefile_temp->getRecord() !== false) {
                $total++;
            }
            
            $is_complete = ($offset + $processed) >= $total;
            
            // Clean up if complete
            if ($is_complete) {
                $this->cleanup_temp_files();
            }
            
            wp_send_json_success(array(
                'processed' => $processed,
                'updated' => $updated,
                'not_found' => $not_found,
                'next_offset' => $offset + $processed,
                'is_complete' => $is_complete
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => 'Error processing batch: ' . $e->getMessage()
            ));
        }
    }
    
    /**
     * Extract coordinates from shapefile record
     */
    private function extract_coordinates($shp_data, $simplify = true) {
        $coordinates = array();
        
        if (!isset($shp_data['parts']) || !isset($shp_data['points'])) {
            return $coordinates;
        }
        
        $parts = $shp_data['parts'];
        $points = $shp_data['points'];
        
        // Handle multi-part polygons
        for ($i = 0; $i < count($parts); $i++) {
            $start = $parts[$i];
            $end = isset($parts[$i + 1]) ? $parts[$i + 1] : count($points);
            
            $part_coords = array();
            for ($j = $start; $j < $end; $j++) {
                if (isset($points[$j])) {
                    $part_coords[] = array(
                        'lng' => $points[$j]['x'],
                        'lat' => $points[$j]['y']
                    );
                }
            }
            
            // Simplify polygon if requested
            if ($simplify && count($part_coords) > 100) {
                $part_coords = $this->simplify_polygon($part_coords, 0.01);
            }
            
            $coordinates[] = $part_coords;
        }
        
        return $coordinates;
    }
    
    /**
     * Simplify polygon using Douglas-Peucker algorithm
     */
    private function simplify_polygon($points, $tolerance) {
        if (count($points) <= 2) {
            return $points;
        }
        
        // Find the point with maximum distance
        $dmax = 0;
        $index = 0;
        $end = count($points) - 1;
        
        for ($i = 1; $i < $end; $i++) {
            $d = $this->perpendicular_distance(
                $points[$i],
                $points[0],
                $points[$end]
            );
            
            if ($d > $dmax) {
                $index = $i;
                $dmax = $d;
            }
        }
        
        // If max distance is greater than tolerance, recursively simplify
        if ($dmax > $tolerance) {
            $rec_results1 = $this->simplify_polygon(array_slice($points, 0, $index + 1), $tolerance);
            $rec_results2 = $this->simplify_polygon(array_slice($points, $index), $tolerance);
            
            return array_merge(
                array_slice($rec_results1, 0, -1),
                $rec_results2
            );
        } else {
            return array($points[0], $points[$end]);
        }
    }
    
    /**
     * Calculate perpendicular distance from point to line
     */
    private function perpendicular_distance($point, $line_start, $line_end) {
        $dx = $line_end['lng'] - $line_start['lng'];
        $dy = $line_end['lat'] - $line_start['lat'];
        
        $mag = sqrt($dx * $dx + $dy * $dy);
        if ($mag > 0.0000001) {
            $dx /= $mag;
            $dy /= $mag;
        }
        
        $pvx = $point['lng'] - $line_start['lng'];
        $pvy = $point['lat'] - $line_start['lat'];
        
        $pvdot = $dx * $pvx + $dy * $pvy;
        
        $dsx = $pvdot * $dx;
        $dsy = $pvdot * $dy;
        
        $ax = $pvx - $dsx;
        $ay = $pvy - $dsy;
        
        return sqrt($ax * $ax + $ay * $ay);
    }
    
    /**
     * Clean up temporary files
     */
    private function cleanup_temp_files() {
        $upload_dir = wp_upload_dir();
        $temp_dir = $upload_dir['basedir'] . '/nws-temp/';
        
        if (file_exists($temp_dir)) {
            $files = glob($temp_dir . '*');
            foreach ($files as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($temp_dir);
        }
        
        delete_transient('nws_shapefile_path');
        delete_transient('nws_simplify_polygons');
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        $this->register_post_types();
        flush_rewrite_rules();
    }
    
    /**
     * Register custom post types
     */
    public function register_post_types() {
        // UGC Codes Post Type
        $ugc_labels = array(
            'name'                  => _x('UGC Codes', 'Post type general name', 'nws-location-codes'),
            'singular_name'         => _x('UGC Code', 'Post type singular name', 'nws-location-codes'),
            'menu_name'             => _x('UGC Codes', 'Admin Menu text', 'nws-location-codes'),
            'add_new'               => __('Add New', 'nws-location-codes'),
            'add_new_item'          => __('Add New UGC Code', 'nws-location-codes'),
            'edit_item'             => __('Edit UGC Code', 'nws-location-codes'),
            'view_item'             => __('View UGC Code', 'nws-location-codes'),
            'all_items'             => __('All UGC Codes', 'nws-location-codes'),
            'search_items'          => __('Search UGC Codes', 'nws-location-codes'),
            'not_found'             => __('No UGC codes found.', 'nws-location-codes'),
        );
        
        $ugc_args = array(
            'labels'             => $ugc_labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'ugc-code'),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 20,
            'menu_icon'          => 'dashicons-location-alt',
            'supports'           => array('title'),
            'show_in_rest'       => true,
        );
        
        register_post_type('ugc_code', $ugc_args);
        
        // SAME Codes Post Type
        $same_labels = array(
            'name'                  => _x('SAME Codes', 'Post type general name', 'nws-location-codes'),
            'singular_name'         => _x('SAME Code', 'Post type singular name', 'nws-location-codes'),
            'menu_name'             => _x('SAME Codes', 'Admin Menu text', 'nws-location-codes'),
            'add_new'               => __('Add New', 'nws-location-codes'),
            'add_new_item'          => __('Add New SAME Code', 'nws-location-codes'),
            'edit_item'             => __('Edit SAME Code', 'nws-location-codes'),
            'view_item'             => __('View SAME Code', 'nws-location-codes'),
            'all_items'             => __('All SAME Codes', 'nws-location-codes'),
            'search_items'          => __('Search SAME Codes', 'nws-location-codes'),
            'not_found'             => __('No SAME codes found.', 'nws-location-codes'),
        );
        
        $same_args = array(
            'labels'             => $same_labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array('slug' => 'same-code'),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 21,
            'menu_icon'          => 'dashicons-admin-site-alt3',
            'supports'           => array('title'),
            'show_in_rest'       => true,
        );
        
        register_post_type('same_code', $same_args);
    }
    
    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_submenu_page(
            'edit.php?post_type=ugc_code',
            __('Import NWS Data', 'nws-location-codes'),
            __('Import Data', 'nws-location-codes'),
            'manage_options',
            'nws-import',
            array($this, 'import_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'ugc_code_page_nws-import') {
            return;
        }
        
        wp_enqueue_script(
            'nws-import-script',
            plugin_dir_url(__FILE__) . 'js/admin-import.js',
            array('jquery'),
            $this->version,
            true
        );
        
        wp_localize_script('nws-import-script', 'nwsImport', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('nws_import_nonce')
        ));
        
        wp_enqueue_style(
            'nws-import-style',
            plugin_dir_url(__FILE__) . 'css/admin-import.css',
            array(),
            $this->version
        );
    }
    
    /**
     * Import page HTML
     */
    public function import_page() {
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            
            <div class="nws-import-container">
                <div class="card">
                    <h2>Import NWS Location Data</h2>
                    <p>This will import UGC and SAME codes from the National Weather Service data file.</p>
                    <p><strong>Data Source:</strong> <?php echo esc_url($this->data_url); ?></p>
                    
                    <div id="nws-import-status" class="notice" style="display:none;">
                        <p></p>
                    </div>
                    
                    <div id="nws-import-progress" style="display:none;">
                        <progress id="nws-progress-bar" value="0" max="100" style="width: 100%; height: 30px;"></progress>
                        <p id="nws-progress-text">Preparing import...</p>
                    </div>
                    
                    <div class="nws-import-stats" id="nws-import-stats" style="display:none;">
                        <h3>Import Statistics</h3>
                        <ul>
                            <li>UGC Codes Created: <strong id="ugc-count">0</strong></li>
                            <li>SAME Codes Created: <strong id="same-count">0</strong></li>
                            <li>Total Records Processed: <strong id="total-count">0</strong></li>
                            <li>Errors/Skipped: <strong id="error-count">0</strong></li>
                        </ul>
                    </div>
                    
                    <div id="nws-error-log" class="nws-error-log" style="display:none;">
                        <h3>Import Errors/Warnings</h3>
                        <div id="nws-error-content"></div>
                    </div>
                    
                    <div class="nws-button-group">
                        <button id="nws-import-btn" class="button button-primary button-large">
                            <span class="dashicons dashicons-download" style="vertical-align: middle;"></span>
                            Import Data
                        </button>
                        
                        <button id="nws-clear-btn" class="button button-secondary button-large">
                            <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span>
                            Clear All Data
                        </button>
                    </div>
                </div>
                
                <div class="card">
                    <h2>Import Polygon Coordinates</h2>
                    <p>This optional step imports the geographic boundary polygons for each zone from the NWS shapefile.</p>
                    <p><strong>Shapefile Source:</strong> <?php echo esc_url($this->shapefile_url); ?></p>
                    <p><strong>File Size:</strong> ~50MB (this may take several minutes)</p>
                    
                    <div class="notice notice-info inline">
                        <p><strong>Note:</strong> You must import location data first before importing polygons. Polygons will be matched to existing UGC codes.</p>
                    </div>
                    
                    <div id="nws-polygon-status" class="notice" style="display:none;">
                        <p></p>
                    </div>
                    
                    <div id="nws-polygon-progress" style="display:none;">
                        <progress id="nws-polygon-bar" value="0" max="100" style="width: 100%; height: 30px;"></progress>
                        <p id="nws-polygon-text">Preparing polygon import...</p>
                    </div>
                    
                    <div class="nws-import-stats" id="nws-polygon-stats" style="display:none;">
                        <h3>Polygon Import Statistics</h3>
                        <ul>
                            <li>Polygons Imported: <strong id="polygon-count">0</strong></li>
                            <li>Zones Updated: <strong id="zones-updated">0</strong></li>
                            <li>Zones Not Found: <strong id="zones-not-found">0</strong></li>
                        </ul>
                    </div>
                    
                    <div class="nws-button-group">
                        <button id="nws-polygon-btn" class="button button-primary button-large">
                            <span class="dashicons dashicons-admin-site" style="vertical-align: middle;"></span>
                            Import Polygons
                        </button>
                        
                        <label style="margin-left: 15px; display: inline-flex; align-items: center;">
                            <input type="checkbox" id="nws-simplify-polygons" checked style="margin-right: 5px;" />
                            Simplify polygons (reduces file size, recommended)
                        </label>
                    </div>
                </div>
                
                <div class="card">
                    <h2>About UGC and SAME Codes</h2>
                    <h3>UGC (Universal Geographic Code)</h3>
                    <p>Format: <code>STATE + TYPE + ZONE</code></p>
                    <p>Example: <code>FLZ201</code> (FL + Z + 201 for Escambia Inland)</p>
                    <ul>
                        <li><strong>Z</strong> = Zone (forecast zone)</li>
                        <li><strong>C</strong> = County (when county-based)</li>
                    </ul>
                    
                    <h3>SAME (Specific Area Message Encoding)</h3>
                    <p>Format: <code>0 + STATE_FIPS + COUNTY_CODE</code></p>
                    <p>Example: <code>012033</code> (0 + 12 + 033 from FIPS 12033 for Escambia County)</p>
                    
                    <h3>Important Notes</h3>
                    <ul>
                        <li>UGC codes are zone-based (e.g., FLZ201, FLZ202 for different zones in same county)</li>
                        <li>SAME codes are county-based (e.g., 012033 for all of Escambia County)</li>
                        <li>Multiple zones can exist for the same county (Inland, Coastal, etc.)</li>
                        <li>Weather alerts typically use UGC zone codes</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
    
    /**
     * AJAX handler for data import
     */
    public function ajax_import_data() {
        check_ajax_referer('nws_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        // Increase execution time and memory
        set_time_limit(300);
        ini_set('memory_limit', '256M');
        
        // Fetch the data file
        $response = wp_remote_get($this->data_url, array(
            'timeout' => 60,
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => 'Failed to fetch data: ' . $response->get_error_message()
            ));
        }
        
        $body = wp_remote_retrieve_body($response);
        
        if (empty($body)) {
            wp_send_json_error(array('message' => 'Empty response from NWS'));
        }
        
        // Parse and import data
        $result = $this->parse_and_import($body);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Parse and import NWS data
     */
    private function parse_and_import($data) {
        $lines = explode("\n", $data);
        $ugc_created = 0;
        $same_created = 0;
        $total_processed = 0;
        $errors = array();
        
        // Track unique entries to avoid duplicates
        $ugc_codes = array();
        $same_codes = array();
        
        foreach ($lines as $line_num => $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            $fields = explode('|', $line);
            
            if (count($fields) < 11) {
                $errors[] = "Line " . ($line_num + 1) . ": Insufficient fields (" . count($fields) . " fields)";
                continue;
            }
            
            // Parse fields according to NWS format
            // STATE|ZONE|CWA|NAME|STATE_ZONE|COUNTY|FIPS|TIME_ZONE|FE_AREA|LAT|LON
            $state = trim($fields[0]);
            $zone = trim($fields[1]);
            $cwa = trim($fields[2]);
            $zone_name = trim($fields[3]);
            $state_zone = trim($fields[4]);
            $county = trim($fields[5]);
            $fips = trim($fields[6]);
            $time_zone = isset($fields[7]) ? trim($fields[7]) : '';
            $fe_area = isset($fields[8]) ? trim($fields[8]) : '';
            $lat = isset($fields[9]) ? trim($fields[9]) : '';
            $lon = isset($fields[10]) ? trim($fields[10]) : '';
            
            // Validate essential fields
            if (empty($state) || empty($zone) || empty($county)) {
                $errors[] = "Line " . ($line_num + 1) . ": Missing essential fields (state/zone/county)";
                continue;
            }
            
            // Validate FIPS code
            if (strlen($fips) !== 5 || !is_numeric($fips)) {
                $errors[] = "Line " . ($line_num + 1) . ": Invalid FIPS code: {$fips}";
                continue;
            }
            
            $total_processed++;
            
            // Derive codes
            $state_fips = substr($fips, 0, 2);
            $county_code = substr($fips, 2, 3);
            
            // UGC Code: Use actual zone format from STATE_ZONE field or construct it
            // Format: STATE + Z + ZONE (e.g., FLZ201)
            $ugc_code = $state . 'Z' . $zone;
            
            // SAME Code: 0 + STATE_FIPS + COUNTY_CODE
            $same_code = '0' . $state_fips . $county_code;
            
            // Create UGC Code post (only if unique)
            if (!isset($ugc_codes[$ugc_code])) {
                $ugc_post_id = $this->create_ugc_post($zone_name . ' - ' . $county, array(
                    'ugc_code' => $ugc_code,
                    'state' => $state,
                    'zone' => $zone,
                    'cwa' => $cwa,
                    'zone_name' => $zone_name,
                    'county' => $county,
                    'fips' => $fips,
                    'state_zone' => $state_zone,
                    'time_zone' => $time_zone,
                    'fe_area' => $fe_area,
                    'lat' => $lat,
                    'lon' => $lon,
                ));
                
                if ($ugc_post_id) {
                    $ugc_codes[$ugc_code] = $ugc_post_id;
                    $ugc_created++;
                } else {
                    $errors[] = "Failed to create UGC post for: {$ugc_code}";
                }
            }
            
            // Create SAME Code post (only if unique - county-based)
            if (!isset($same_codes[$same_code])) {
                $same_post_id = $this->create_same_post($county, array(
                    'same_code' => $same_code,
                    'state' => $state,
                    'county' => $county,
                    'fips' => $fips,
                    'state_fips' => $state_fips,
                    'county_code' => $county_code,
                    'time_zone' => $time_zone,
                    'lat' => $lat,
                    'lon' => $lon,
                ));
                
                if ($same_post_id) {
                    $same_codes[$same_code] = $same_post_id;
                    $same_created++;
                } else {
                    $errors[] = "Failed to create SAME post for: {$same_code}";
                }
            }
        }
        
        return array(
            'success' => true,
            'ugc_created' => $ugc_created,
            'same_created' => $same_created,
            'total_processed' => $total_processed,
            'errors' => $errors,
            'error_count' => count($errors),
        );
    }
    
    /**
     * Create UGC Code post
     */
    private function create_ugc_post($title, $meta) {
        // Check if post already exists
        $existing = get_posts(array(
            'post_type' => 'ugc_code',
            'meta_key' => 'ugc_code',
            'meta_value' => $meta['ugc_code'],
            'posts_per_page' => 1,
        ));
        
        if (!empty($existing)) {
            // Update existing post
            $post_id = $existing[0]->ID;
            wp_update_post(array(
                'ID' => $post_id,
                'post_title' => $title,
            ));
        } else {
            // Create new post
            $post_id = wp_insert_post(array(
                'post_title' => $title,
                'post_type' => 'ugc_code',
                'post_status' => 'publish',
            ));
        }
        
        if ($post_id && !is_wp_error($post_id)) {
            foreach ($meta as $key => $value) {
                update_post_meta($post_id, $key, sanitize_text_field($value));
            }
            return $post_id;
        }
        
        return false;
    }
    
    /**
     * Create SAME Code post
     */
    private function create_same_post($title, $meta) {
        // Check if post already exists
        $existing = get_posts(array(
            'post_type' => 'same_code',
            'meta_key' => 'same_code',
            'meta_value' => $meta['same_code'],
            'posts_per_page' => 1,
        ));
        
        if (!empty($existing)) {
            // Update existing post
            $post_id = $existing[0]->ID;
            wp_update_post(array(
                'ID' => $post_id,
                'post_title' => $title . ' County',
            ));
        } else {
            // Create new post
            $post_id = wp_insert_post(array(
                'post_title' => $title . ' County',
                'post_type' => 'same_code',
                'post_status' => 'publish',
            ));
        }
        
        if ($post_id && !is_wp_error($post_id)) {
            foreach ($meta as $key => $value) {
                update_post_meta($post_id, $key, sanitize_text_field($value));
            }
            return $post_id;
        }
        
        return false;
    }
    
    /**
     * AJAX handler for clearing data
     */
    public function ajax_clear_data() {
        check_ajax_referer('nws_import_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized'));
        }
        
        $deleted = 0;
        
        // Delete all UGC codes
        $ugc_posts = get_posts(array(
            'post_type' => 'ugc_code',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ));
        
        foreach ($ugc_posts as $post_id) {
            wp_delete_post($post_id, true);
            $deleted++;
        }
        
        // Delete all SAME codes
        $same_posts = get_posts(array(
            'post_type' => 'same_code',
            'posts_per_page' => -1,
            'fields' => 'ids',
        ));
        
        foreach ($same_posts as $post_id) {
            wp_delete_post($post_id, true);
            $deleted++;
        }
        
        wp_send_json_success(array(
            'message' => "Deleted {$deleted} posts",
            'deleted' => $deleted
        ));
    }
    
    /**
     * Add meta boxes
     */
    public function add_meta_boxes() {
        add_meta_box(
            'ugc_code_details',
            __('UGC Code Details', 'nws-location-codes'),
            array($this, 'ugc_code_meta_box'),
            'ugc_code',
            'normal',
            'high'
        );
        
        add_meta_box(
            'same_code_details',
            __('SAME Code Details', 'nws-location-codes'),
            array($this, 'same_code_meta_box'),
            'same_code',
            'normal',
            'high'
        );
    }
    
    /**
     * UGC Code meta box content
     */
    public function ugc_code_meta_box($post) {
        wp_nonce_field('ugc_code_meta_box', 'ugc_code_meta_box_nonce');
        
        $fields = array(
            'ugc_code' => 'UGC Code',
            'state' => 'State',
            'zone' => 'Zone',
            'cwa' => 'CWA',
            'zone_name' => 'Zone Name',
            'county' => 'County',
            'fips' => 'FIPS',
            'state_zone' => 'State Zone',
            'time_zone' => 'Time Zone',
            'fe_area' => 'FE Area',
            'lat' => 'Latitude',
            'lon' => 'Longitude',
        );
        
        echo '<table class="form-table">';
        foreach ($fields as $key => $label) {
            $value = get_post_meta($post->ID, $key, true);
            echo '<tr>';
            echo '<th><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
            echo '<td><input type="text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" class="regular-text" /></td>';
            echo '</tr>';
        }
        
        // Show polygon info if exists
        $has_polygon = get_post_meta($post->ID, 'has_polygon', true);
        if ($has_polygon) {
            $polygon_data = get_post_meta($post->ID, 'polygon_coordinates', true);
            $coords = json_decode($polygon_data, true);
            $point_count = 0;
            if ($coords) {
                foreach ($coords as $part) {
                    $point_count += count($part);
                }
            }
            echo '<tr>';
            echo '<th>Polygon Data</th>';
            echo '<td><span style="color: green;">✓ Polygon coordinates loaded (' . $point_count . ' points)</span></td>';
            echo '</tr>';
        } else {
            echo '<tr>';
            echo '<th>Polygon Data</th>';
            echo '<td><span style="color: #999;">No polygon data</span></td>';
            echo '</tr>';
        }
        
        echo '</table>';
    }
    
    /**
     * SAME Code meta box content
     */
    public function same_code_meta_box($post) {
        wp_nonce_field('same_code_meta_box', 'same_code_meta_box_nonce');
        
        $fields = array(
            'same_code' => 'SAME Code',
            'state' => 'State',
            'county' => 'County',
            'fips' => 'FIPS',
            'state_fips' => 'State FIPS',
            'county_code' => 'County Code',
            'time_zone' => 'Time Zone',
            'lat' => 'Latitude',
            'lon' => 'Longitude',
        );
        
        echo '<table class="form-table">';
        foreach ($fields as $key => $label) {
            $value = get_post_meta($post->ID, $key, true);
            echo '<tr>';
            echo '<th><label for="' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
            echo '<td><input type="text" id="' . esc_attr($key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" class="regular-text" /></td>';
            echo '</tr>';
        }
        echo '</table>';
    }
    
    /**
     * Save meta boxes
     */
    public function save_meta_boxes($post_id) {
        // UGC Code
        if (isset($_POST['ugc_code_meta_box_nonce'])) {
            if (!wp_verify_nonce($_POST['ugc_code_meta_box_nonce'], 'ugc_code_meta_box')) {
                return;
            }
            
            $fields = array('ugc_code', 'state', 'zone', 'cwa', 'zone_name', 'county', 'fips', 'state_zone', 'time_zone', 'fe_area', 'lat', 'lon');
            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
                }
            }
        }
        
        // SAME Code
        if (isset($_POST['same_code_meta_box_nonce'])) {
            if (!wp_verify_nonce($_POST['same_code_meta_box_nonce'], 'same_code_meta_box')) {
                return;
            }
            
            $fields = array('same_code', 'state', 'county', 'fips', 'state_fips', 'county_code', 'time_zone', 'lat', 'lon');
            foreach ($fields as $field) {
                if (isset($_POST[$field])) {
                    update_post_meta($post_id, $field, sanitize_text_field($_POST[$field]));
                }
            }
        }
    }
    
    /**
     * Add custom columns to UGC Code list
     */
    public function ugc_columns($columns) {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = $columns['title'];
        $new_columns['ugc_code'] = __('UGC Code', 'nws-location-codes');
        $new_columns['state'] = __('State', 'nws-location-codes');
        $new_columns['county'] = __('County', 'nws-location-codes');
        $new_columns['zone'] = __('Zone', 'nws-location-codes');
        $new_columns['date'] = $columns['date'];
        return $new_columns;
    }
    
    /**
     * Display custom column content for UGC codes
     */
    public function ugc_column_content($column, $post_id) {
        switch ($column) {
            case 'ugc_code':
                echo '<strong>' . esc_html(get_post_meta($post_id, 'ugc_code', true)) . '</strong>';
                break;
            case 'state':
                echo esc_html(get_post_meta($post_id, 'state', true));
                break;
            case 'county':
                echo esc_html(get_post_meta($post_id, 'county', true));
                break;
            case 'zone':
                echo esc_html(get_post_meta($post_id, 'zone', true));
                break;
        }
    }
    
    /**
     * Add custom columns to SAME Code list
     */
    public function same_columns($columns) {
        $new_columns = array();
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = $columns['title'];
        $new_columns['same_code'] = __('SAME Code', 'nws-location-codes');
        $new_columns['state'] = __('State', 'nws-location-codes');
        $new_columns['fips'] = __('FIPS', 'nws-location-codes');
        $new_columns['date'] = $columns['date'];
        return $new_columns;
    }
    
    /**
     * Display custom column content for SAME codes
     */
    public function same_column_content($column, $post_id) {
        switch ($column) {
            case 'same_code':
                echo '<strong>' . esc_html(get_post_meta($post_id, 'same_code', true)) . '</strong>';
                break;
            case 'state':
                echo esc_html(get_post_meta($post_id, 'state', true));
                break;
            case 'fips':
                echo esc_html(get_post_meta($post_id, 'fips', true));
                break;
        }
    }
}

// Initialize the plugin
new NWS_Location_Codes();

// Add deactivation hook
register_deactivation_hook(__FILE__, 'nws_location_codes_deactivate');

function nws_location_codes_deactivate() {
    flush_rewrite_rules();
}