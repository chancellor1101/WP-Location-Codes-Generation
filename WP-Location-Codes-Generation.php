<?php
/**
 * Plugin Name: NWS Location Codes
 * Plugin URI: https://example.com/nws-location-codes
 * Description: Manages UGC and SAME codes from National Weather Service data for weather alert integration
 * Version: 1.0.0
 * Author: Your Name
 * Author URI: https://example.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: nws-location-codes
 */

// If this file is called directly, abort.
if (!defined('WPINC')) {
    die;
}

class NWS_Location_Codes {
    
    private $version = '1.0.0';
    private $data_url = 'https://www.weather.gov/source/gis/Shapefiles/County/bp05mr24.dbx';
    
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
        
        // Add meta boxes
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post', array($this, 'save_meta_boxes'));
        
        // Register activation hook
        register_activation_hook(__FILE__, array($this, 'activate'));
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
                        </ul>
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
                    <h2>About UGC and SAME Codes</h2>
                    <h3>UGC (Universal Geographic Code)</h3>
                    <p>Format: <code>STATE + TYPE + LAST_3_FIPS</code></p>
                    <p>Example: <code>TXC121</code> (TX + C + 121 from FIPS 48121)</p>
                    <ul>
                        <li><strong>C</strong> = County</li>
                        <li><strong>Z</strong> = Zone</li>
                    </ul>
                    
                    <h3>SAME (Specific Area Message Encoding)</h3>
                    <p>Format: <code>0 + STATE_FIPS + COUNTY_CODE</code></p>
                    <p>Example: <code>048121</code> (0 + 48 + 121 from FIPS 48121)</p>
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
        
        // Track unique entries to avoid duplicates
        $ugc_codes = array();
        $same_codes = array();
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }
            
            $fields = explode('|', $line);
            
            if (count($fields) < 11) {
                continue;
            }
            
            // Parse fields according to NWS format
            // STATE|ZONE|CWA|NAME|STATE_ZONE|COUNTY|FIPS|TIME_ZONE|FE_AREA|LAT|LON
            $state = $fields[0];
            $zone = $fields[1];
            $cwa = $fields[2];
            $zone_name = $fields[3];
            $state_zone = $fields[4];
            $county = $fields[5];
            $fips = $fields[6];
            $time_zone = isset($fields[7]) ? $fields[7] : '';
            $fe_area = isset($fields[8]) ? $fields[8] : '';
            $lat = isset($fields[9]) ? $fields[9] : '';
            $lon = isset($fields[10]) ? $fields[10] : '';
            
            // Validate FIPS code
            if (strlen($fips) !== 5 || !is_numeric($fips)) {
                continue;
            }
            
            $total_processed++;
            
            // Determine UGC type
            $ugc_type = 'C'; // Default to County
            if (!empty($fe_area)) {
                $ugc_type = strtoupper($fe_area[0]); // First character
            }
            
            // Derive codes
            $state_fips = substr($fips, 0, 2);
            $county_code = substr($fips, 2, 3);
            
            // UGC Code: STATE + TYPE + LAST_3_FIPS
            $ugc_code = $state . $ugc_type . $county_code;
            
            // SAME Code: 0 + STATE_FIPS + COUNTY_CODE
            $same_code = '0' . $state_fips . $county_code;
            
            // Create UGC Code post (only if unique)
            if (!isset($ugc_codes[$ugc_code])) {
                $ugc_post_id = $this->create_ugc_post($county, array(
                    'ugc_code' => $ugc_code,
                    'state' => $state,
                    'zone' => $zone,
                    'cwa' => $cwa,
                    'zone_name' => $zone_name,
                    'county' => $county,
                    'fips' => $fips,
                    'ugc_type' => $ugc_type,
                    'time_zone' => $time_zone,
                    'lat' => $lat,
                    'lon' => $lon,
                ));
                
                if ($ugc_post_id) {
                    $ugc_codes[$ugc_code] = $ugc_post_id;
                    $ugc_created++;
                }
            }
            
            // Create SAME Code post (only if unique)
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
                }
            }
        }
        
        return array(
            'success' => true,
            'ugc_created' => $ugc_created,
            'same_created' => $same_created,
            'total_processed' => $total_processed,
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
            return $existing[0]->ID;
        }
        
        $post_id = wp_insert_post(array(
            'post_title' => $title . ' (' . $meta['ugc_code'] . ')',
            'post_type' => 'ugc_code',
            'post_status' => 'publish',
        ));
        
        if ($post_id) {
            foreach ($meta as $key => $value) {
                update_post_meta($post_id, $key, sanitize_text_field($value));
            }
        }
        
        return $post_id;
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
            return $existing[0]->ID;
        }
        
        $post_id = wp_insert_post(array(
            'post_title' => $title . ' (' . $meta['same_code'] . ')',
            'post_type' => 'same_code',
            'post_status' => 'publish',
        ));
        
        if ($post_id) {
            foreach ($meta as $key => $value) {
                update_post_meta($post_id, $key, sanitize_text_field($value));
            }
        }
        
        return $post_id;
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
        ));
        
        foreach ($ugc_posts as $post) {
            wp_delete_post($post->ID, true);
            $deleted++;
        }
        
        // Delete all SAME codes
        $same_posts = get_posts(array(
            'post_type' => 'same_code',
            'posts_per_page' => -1,
        ));
        
        foreach ($same_posts as $post) {
            wp_delete_post($post->ID, true);
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
            'ugc_type' => 'UGC Type',
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
            
            $fields = array('ugc_code', 'state', 'zone', 'cwa', 'zone_name', 'county', 'fips', 'ugc_type', 'time_zone', 'lat', 'lon');
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
}

// Initialize the plugin
new NWS_Location_Codes();

// Add deactivation hook
register_deactivation_hook(__FILE__, 'nws_location_codes_deactivate');

function nws_location_codes_deactivate() {
    flush_rewrite_rules();
}