<?php
/**
 * Helper Functions for Craps Data Importer
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Get plugin instance
 */
function cdi_get_instance() {
    return CDI_Main::get_instance();
}

/**
 * Get file handler instance
 */
function cdi_get_file_handler() {
    return cdi_get_instance()->get_file_handler();
}

/**
 * Get matcher instance
 */
function cdi_get_matcher() {
    return cdi_get_instance()->get_matcher();
}

/**
 * Get processor instance
 */
function cdi_get_processor() {
    return cdi_get_instance()->get_processor();
}

/**
 * Format file size for display
 */
function cdi_format_file_size($bytes) {
    if ($bytes >= 1073741824) {
        return number_format($bytes / 1073741824, 2) . ' GB';
    } elseif ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 2) . ' MB';
    } elseif ($bytes >= 1024) {
        return number_format($bytes / 1024, 2) . ' KB';
    } elseif ($bytes > 1) {
        return $bytes . ' bytes';
    } elseif ($bytes == 1) {
        return $bytes . ' byte';
    } else {
        return '0 bytes';
    }
}

/**
 * Sanitize casino name for consistent matching
 */
function cdi_sanitize_casino_name($name) {
    // Remove common suffixes/prefixes
    $patterns = array(
        '/\b(casino|resort|hotel|gaming|riverboat|tribal|nation)\b/i',
        '/\b(the|a|an)\b/i'
    );
    
    foreach ($patterns as $pattern) {
        $name = preg_replace($pattern, '', $name);
    }
    
    // Clean up whitespace and special characters
    $name = preg_replace('/[^\w\s]/', '', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    
    return trim($name);
}

/**
 * Format phone number
 */
function cdi_format_phone($phone) {
    if (empty($phone)) return '';
    
    // Remove all non-numeric characters except + for international
    $phone = preg_replace('/[^\d\+]/', '', $phone);
    
    // Format US phone numbers
    if (strlen($phone) === 10) {
        return sprintf('(%s) %s-%s', 
                      substr($phone, 0, 3),
                      substr($phone, 3, 3),
                      substr($phone, 6, 4));
    } elseif (strlen($phone) === 11 && substr($phone, 0, 1) === '1') {
        return sprintf('+1 (%s) %s-%s', 
                      substr($phone, 1, 3),
                      substr($phone, 4, 3),
                      substr($phone, 7, 4));
    }
    
    return $phone;
}

/**
 * Validate and format URL
 */
function cdi_format_url($url) {
    if (empty($url)) return '';
    
    $url = trim($url);
    
    // Add protocol if missing
    if (!preg_match('/^https?:\/\//', $url)) {
        $url = 'http://' . $url;
    }
    
    // Validate URL
    if (filter_var($url, FILTER_VALIDATE_URL)) {
        return $url;
    }
    
    return '';
}

/**
 * Get casino post type - SIMPLIFIED for bubble-craps.com
 */
function cdi_get_casino_post_type() {
    return 'at_biz_dir'; // Simple hardcoded for your site
}

/**
 * Check if Directorist plugin is active - SIMPLIFIED  
 */
function cdi_is_directorist_active() {
    return true; // Just assume it's active for your site
}

/**
 * Get all casino posts
 */
function cdi_get_all_casinos() {
    $args = array(
        'post_type' => cdi_get_casino_post_type(),
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => array(
            array(
                'key' => '_directory_type',
                'value' => 'casino',
                'compare' => 'LIKE'
            )
        )
    );
    
    return get_posts($args);
}

/**
 * Get casino meta fields
 */
function cdi_get_casino_meta($casino_id, $field = null) {
    $meta_fields = array(
        'phone' => '_phone',
        'website' => '_website',
        'email' => '_email',
        'address' => '_address',
        'location' => '_location',
        'city' => '_city',
        'state' => '_state',
        'region' => '_region',
        'minimum_bet' => '_minimum_bet',
        'maximum_bet' => '_maximum_bet',
        'table_limit' => '_table_limit',
        'hours' => '_hours',
        'notes' => '_notes'
    );
    
    if ($field) {
        $meta_key = $meta_fields[$field] ?? '_' . $field;
        return get_post_meta($casino_id, $meta_key, true);
    }
    
    $meta_data = array();
    foreach ($meta_fields as $field_name => $meta_key) {
        $value = get_post_meta($casino_id, $meta_key, true);
        if (!empty($value)) {
            $meta_data[$field_name] = $value;
        }
    }
    
    return $meta_data;
}

/**
 * Update casino meta field
 */
function cdi_update_casino_meta($casino_id, $field, $value) {
    $meta_fields = array(
        'phone' => '_phone',
        'website' => '_website',
        'email' => '_email',
        'address' => '_address',
        'location' => '_location',
        'city' => '_city',
        'state' => '_state',
        'region' => '_region',
        'minimum_bet' => '_minimum_bet',
        'maximum_bet' => '_maximum_bet',
        'table_limit' => '_table_limit',
        'hours' => '_hours',
        'notes' => '_notes'
    );
    
    $meta_key = $meta_fields[$field] ?? '_' . $field;
    return update_post_meta($casino_id, $meta_key, $value);
}

/**
 * Log import activity
 */
function cdi_log($message, $level = 'info') {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('CDI [' . strtoupper($level) . ']: ' . $message);
    }
}

/**
 * Get plugin version
 */
function cdi_get_version() {
    return CDI_VERSION;
}

/**
 * Get plugin settings
 */
function cdi_get_settings() {
    return array(
        'auto_clean' => get_option('cdi_auto_clean', '1') === '1',
        'notification_email' => get_option('cdi_notification_email', get_option('admin_email')),
        'batch_size' => intval(get_option('cdi_batch_size', 50)),
        'similarity_threshold' => intval(get_option('cdi_similarity_threshold', 70)),
        'location_boost' => intval(get_option('cdi_location_boost', 10)),
        'max_upload_size' => get_option('cdi_max_upload_size', '5MB'),
        'allowed_file_types' => explode(',', get_option('cdi_allowed_file_types', 'csv,xlsx,xls'))
    );
}

/**
 * Update plugin setting
 */
function cdi_update_setting($key, $value) {
    return update_option('cdi_' . $key, $value);
}

/**
 * Get import statistics
 */
function cdi_get_stats($days = 30) {
    $processor = cdi_get_processor();
    return $processor->get_import_statistics($days);
}

/**
 * Clear all plugin data
 */
function cdi_clear_all_data() {
    // Clear upload data
    cdi_get_file_handler()->clear_upload_data();
    
    // Clear review queue
    cdi_get_processor()->clear_review_queue();
    
    // Clear matcher cache
    cdi_get_matcher()->clear_cache();
    
    // Remove options
    $options = array(
        'cdi_upload_data',
        'cdi_last_import_session',
        'cdi_activation_time'
    );
    
    foreach ($options as $option) {
        delete_option($option);
    }
}

/**
 * Check plugin requirements
 */
function cdi_check_requirements() {
    $requirements = array(
        'php_version' => version_compare(PHP_VERSION, '7.4', '>='),
        'wp_version' => version_compare(get_bloginfo('version'), '5.0', '>='),
        'directorist_active' => cdi_is_directorist_active(),
        'mbstring_extension' => extension_loaded('mbstring'),
        'json_extension' => extension_loaded('json')
    );
    
    return $requirements;
}

/**
 * Get requirement status
 */
function cdi_get_requirement_status() {
    $requirements = cdi_check_requirements();
    $all_met = true;
    
    foreach ($requirements as $req => $met) {
        if (!$met) {
            $all_met = false;
            break;
        }
    }
    
    return array(
        'all_met' => $all_met,
        'requirements' => $requirements
    );
}

/**
 * Generate import session ID
 */
function cdi_generate_session_id() {
    return 'import_' . date('Ymd_His') . '_' . wp_generate_password(8, false);
}

/**
 * Format import session ID for display
 */
function cdi_format_session_id($session_id) {
    if (preg_match('/import_(\d{8})_(\d{6})_(.+)/', $session_id, $matches)) {
        $date = DateTime::createFromFormat('Ymd_His', $matches[1] . '_' . $matches[2]);
        if ($date) {
            return $date->format('M j, Y g:i A') . ' (' . $matches[3] . ')';
        }
    }
    
    return $session_id;
}

/**
 * Get casino locations (for dropdown/filters)
 */
function cdi_get_casino_locations() {
    $locations = array();
    
    // Get from taxonomy if available
    $location_taxonomy = 'at_biz_dir-location';
    if (taxonomy_exists($location_taxonomy)) {
        $terms = get_terms(array(
            'taxonomy' => $location_taxonomy,
            'hide_empty' => false
        ));
        
        foreach ($terms as $term) {
            $locations[] = $term->name;
        }
    }
    
    // Get from meta fields as fallback
    global $wpdb;
    $meta_locations = $wpdb->get_col("
        SELECT DISTINCT meta_value 
        FROM {$wpdb->postmeta} pm
        JOIN {$wpdb->posts} p ON pm.post_id = p.ID
        WHERE p.post_type = '" . cdi_get_casino_post_type() . "'
        AND pm.meta_key IN ('_location', '_city', '_state', '_region')
        AND pm.meta_value != ''
        ORDER BY pm.meta_value
    ");
    
    $locations = array_unique(array_merge($locations, $meta_locations));
    sort($locations);
    
    return $locations;
}

/**
 * Search casinos by name and location
 */
function cdi_search_casinos($query, $location = '', $limit = 20) {
    $args = array(
        'post_type' => cdi_get_casino_post_type(),
        'post_status' => 'publish',
        'posts_per_page' => $limit,
        's' => $query,
        'meta_query' => array(
            array(
                'key' => '_directory_type',
                'value' => 'casino',
                'compare' => 'LIKE'
            )
        )
    );
    
    if (!empty($location)) {
        $args['meta_query'][] = array(
            'relation' => 'OR',
            array(
                'key' => '_location',
                'value' => $location,
                'compare' => 'LIKE'
            ),
            array(
                'key' => '_city',
                'value' => $location,
                'compare' => 'LIKE'
            ),
            array(
                'key' => '_state',
                'value' => $location,
                'compare' => 'LIKE'
            )
        );
    }
    
    return get_posts($args);
}

/**
 * Get casino by ID with full data
 */
function cdi_get_casino($casino_id) {
    $post = get_post($casino_id);
    
    if (!$post || $post->post_type !== cdi_get_casino_post_type()) {
        return null;
    }
    
    return array(
        'id' => $post->ID,
        'title' => $post->post_title,
        'content' => $post->post_content,
        'url' => get_permalink($post->ID),
        'edit_url' => get_edit_post_link($post->ID),
        'meta' => cdi_get_casino_meta($casino_id),
        'location' => cdi_get_casino_meta($casino_id, 'location'),
        'created' => $post->post_date,
        'modified' => $post->post_modified
    );
}

/**
 * Export casinos to CSV
 */
function cdi_export_casinos_csv($filename = null) {
    if (!$filename) {
        $filename = 'craps_casinos_' . date('Y-m-d_H-i-s') . '.csv';
    }
    
    $casinos = cdi_get_all_casinos();
    
    if (empty($casinos)) {
        return false;
    }
    
    $upload_dir = wp_upload_dir();
    $file_path = $upload_dir['basedir'] . '/craps-importer/' . $filename;
    
    // Ensure directory exists
    wp_mkdir_p(dirname($file_path));
    
    $file = fopen($file_path, 'w');
    
    // Write headers
    $headers = array(
        'Casino Name',
        'Location',
        'Phone',
        'Website',
        'Email',
        'Address',
        'Minimum Bet',
        'Maximum Bet',
        'Table Limit',
        'Hours',
        'Notes',
        'URL',
        'Last Modified'
    );
    
    fputcsv($file, $headers);
    
    // Write casino data
    foreach ($casinos as $casino) {
        $meta = cdi_get_casino_meta($casino->ID);
        
        $row = array(
            $casino->post_title,
            $meta['location'] ?? '',
            $meta['phone'] ?? '',
            $meta['website'] ?? '',
            $meta['email'] ?? '',
            $meta['address'] ?? '',
            $meta['minimum_bet'] ?? '',
            $meta['maximum_bet'] ?? '',
            $meta['table_limit'] ?? '',
            $meta['hours'] ?? '',
            $casino->post_content,
            get_permalink($casino->ID),
            $casino->post_modified
        );
        
        fputcsv($file, $row);
    }
    
    fclose($file);
    
    return $file_path;
}

/**
 * Validate spreadsheet data structure
 */
function cdi_validate_spreadsheet_data($data) {
    $validation = array(
        'valid' => true,
        'errors' => array(),
        'warnings' => array(),
        'stats' => array(
            'total_rows' => 0,
            'valid_casinos' => 0,
            'missing_names' => 0,
            'missing_locations' => 0
        )
    );
    
    if (empty($data) || !is_array($data)) {
        $validation['valid'] = false;
        $validation['errors'][] = 'No valid data found';
        return $validation;
    }
    
    foreach ($data as $sheet_name => $sheet_data) {
        if (empty($sheet_data['data'])) continue;
        
        foreach ($sheet_data['data'] as $row) {
            $validation['stats']['total_rows']++;
            
            // Check for casino name
            $casino_name = '';
            $name_fields = array('Casino Name', 'Name', 'Casino');
            foreach ($name_fields as $field) {
                if (!empty($row[$field])) {
                    $casino_name = $row[$field];
                    break;
                }
            }
            
            if (empty($casino_name)) {
                $validation['stats']['missing_names']++;
            } else {
                $validation['stats']['valid_casinos']++;
            }
            
            // Check for location
            $location = '';
            $location_fields = array('Location', 'City', 'State', 'Region');
            foreach ($location_fields as $field) {
                if (!empty($row[$field])) {
                    $location = $row[$field];
                    break;
                }
            }
            
            if (empty($location)) {
                $validation['stats']['missing_locations']++;
            }
        }
    }
    
    // Add warnings based on stats
    if ($validation['stats']['missing_names'] > 0) {
        $validation['warnings'][] = sprintf(
            '%d rows are missing casino names and will be skipped',
            $validation['stats']['missing_names']
        );
    }
    
    if ($validation['stats']['missing_locations'] > ($validation['stats']['total_rows'] * 0.5)) {
        $validation['warnings'][] = 'More than 50% of rows are missing location data, which may affect matching accuracy';
    }
    
    if ($validation['stats']['valid_casinos'] === 0) {
        $validation['valid'] = false;
        $validation['errors'][] = 'No valid casino records found in the spreadsheet';
    }
    
    return $validation;
}

/**
 * Get duplicate casinos in database
 */
function cdi_find_duplicate_casinos($threshold = 90) {
    $casinos = cdi_get_all_casinos();
    $duplicates = array();
    $matcher = cdi_get_matcher();
    
    for ($i = 0; $i < count($casinos); $i++) {
        for ($j = $i + 1; $j < count($casinos); $j++) {
            $casino1 = $casinos[$i];
            $casino2 = $casinos[$j];
            
            $location1 = cdi_get_casino_meta($casino1->ID, 'location');
            $location2 = cdi_get_casino_meta($casino2->ID, 'location');
            
            if ($matcher->are_likely_same($casino1->post_title, $location1, $casino2->post_title, $location2)) {
                $duplicates[] = array(
                    'casino1' => array(
                        'id' => $casino1->ID,
                        'title' => $casino1->post_title,
                        'location' => $location1
                    ),
                    'casino2' => array(
                        'id' => $casino2->ID,
                        'title' => $casino2->post_title,
                        'location' => $location2
                    )
                );
            }
        }
    }
    
    return $duplicates;
}

/**
 * Get system information for debugging
 */
function cdi_get_system_info() {
    $file_handler = cdi_get_file_handler();
    $upload_dir_info = $file_handler->get_upload_dir_info();
    $server_capabilities = $file_handler->check_server_capabilities();
    
    return array(
        'plugin_version' => cdi_get_version(),
        'wordpress_version' => get_bloginfo('version'),
        'php_version' => PHP_VERSION,
        'requirements' => cdi_get_requirement_status(),
        'server_capabilities' => $server_capabilities,
        'upload_directory' => $upload_dir_info,
        'settings' => cdi_get_settings(),
        'database_tables' => cdi_check_database_tables(),
        'casino_count' => wp_count_posts(cdi_get_casino_post_type())->publish ?? 0
    );
}

/**
 * Check if database tables exist
 */
function cdi_check_database_tables() {
    global $wpdb;
    
    $tables = array(
        'import_logs' => $wpdb->prefix . 'cdi_import_logs',
        'review_queue' => $wpdb->prefix . 'cdi_review_queue'
    );
    
    $status = array();
    
    foreach ($tables as $name => $table_name) {
        $exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        )) === $table_name;
        
        $status[$name] = array(
            'exists' => $exists,
            'table_name' => $table_name
        );
        
        if ($exists) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            $status[$name]['row_count'] = intval($count);
        }
    }
    
    return $status;
}

/**
 * Schedule cleanup task
 */
function cdi_schedule_cleanup() {
    if (!wp_next_scheduled('cdi_cleanup_old_data')) {
        wp_schedule_event(time(), 'daily', 'cdi_cleanup_old_data');
    }
}

/**
 * Unschedule cleanup task
 */
function cdi_unschedule_cleanup() {
    wp_clear_scheduled_hook('cdi_cleanup_old_data');
}

/**
 * Handle AJAX requests
 */
add_action('wp_ajax_cdi_get_system_info', 'cdi_ajax_get_system_info');
function cdi_ajax_get_system_info() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    wp_send_json_success(cdi_get_system_info());
}

add_action('wp_ajax_cdi_export_casinos', 'cdi_ajax_export_casinos');
function cdi_ajax_export_casinos() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized');
    }
    
    $file_path = cdi_export_casinos_csv();
    
    if ($file_path) {
        $upload_dir = wp_upload_dir();
        $file_url = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $file_path);
        
        wp_send_json_success(array(
            'file_url' => $file_url,
            'file_path' => $file_path,
            'filename' => basename($file_path)
        ));
    } else {
        wp_send_json_error('Failed to export casinos');
    }
}

/**
 * Debug helper functions
 */
function cdi_debug_log($data, $context = 'general') {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $message = is_array($data) || is_object($data) ? print_r($data, true) : $data;
        error_log("CDI DEBUG [$context]: " . $message);
    }
}

function cdi_memory_usage() {
    return array(
        'current' => memory_get_usage(true),
        'peak' => memory_get_peak_usage(true),
        'limit' => ini_get('memory_limit')
    );
}

/**
 * Plugin activation cleanup
 */
register_activation_hook(CDI_PLUGIN_FILE, 'cdi_activation_cleanup');
function cdi_activation_cleanup() {
    // Clean any orphaned data from previous versions
    cdi_clear_all_data();
    
    // Schedule cleanup task
    cdi_schedule_cleanup();
    
    // Set activation timestamp
    update_option('cdi_activated_at', current_time('mysql'));
}

/**
 * Plugin deactivation cleanup
 */
register_deactivation_hook(CDI_PLUGIN_FILE, 'cdi_deactivation_cleanup');
function cdi_deactivation_cleanup() {
    // Unschedule cleanup task
    cdi_unschedule_cleanup();
    
    // Clean temporary files
    $file_handler = cdi_get_file_handler();
    $file_handler->cleanup_old_files(0); // Clean all files
}