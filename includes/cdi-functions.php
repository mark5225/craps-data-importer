<?php
/**
 * Helper functions for Craps Data Importer
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Truncate text to specified length
 */
function cdi_truncate_text($text, $length = 50, $suffix = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    
    return substr($text, 0, $length) . $suffix;
}

/**
 * Get Directorist casino count
 */
function cdi_get_casino_count() {
    $count = wp_count_posts('at_biz_dir');
    return $count->publish ?? 0;
}

/**
 * Get review queue count
 */
function cdi_get_review_queue_count() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'cdi_review_queue';
    
    return $wpdb->get_var(
        "SELECT COUNT(*) FROM $table_name WHERE status = 'pending'"
    );
}

/**
 * Get import history count
 */
function cdi_get_import_history_count() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'cdi_import_history';
    
    return $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
}

/**
 * Clean casino name for display
 */
function cdi_clean_display_name($name) {
    return ucwords(strtolower(trim($name)));
}

/**
 * Format currency value
 */
function cdi_format_currency($value) {
    if (empty($value) || !is_numeric($value)) {
        return $value;
    }
    
    return '$' . number_format(floatval($value), 0);
}

/**
 * Convert boolean values to Yes/No
 */
function cdi_format_boolean($value) {
    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }
    
    $value = strtolower(trim($value));
    
    if (in_array($value, ['yes', 'y', '1', 'true'])) {
        return 'Yes';
    } elseif (in_array($value, ['no', 'n', '0', 'false'])) {
        return 'No';
    } else {
        return 'Unknown';
    }
}

/**
 * Get bubble craps category ID
 */
function cdi_get_bubble_craps_category_id($has_bubble_craps = true) {
    $category_name = $has_bubble_craps ? 'Has Bubble Craps' : 'No Bubble Craps (or unknown)';
    
    $term = get_term_by('name', $category_name, 'at_biz_dir-categories');
    
    return $term ? $term->term_id : null;
}

/**
 * Get or create bubble craps tag
 */
function cdi_get_or_create_tag($tag_name) {
    $term = get_term_by('name', $tag_name, 'at_biz_dir-tags');
    
    if (!$term) {
        $result = wp_insert_term($tag_name, 'at_biz_dir-tags');
        
        if (!is_wp_error($result)) {
            $term = get_term($result['term_id'], 'at_biz_dir-tags');
        }
    }
    
    return $term;
}

/**
 * Validate CSV headers
 */
function cdi_validate_csv_headers($headers) {
    $required_headers = array('Casino', 'Downtown Casino');
    $found_required = false;
    
    foreach ($required_headers as $required) {
        if (in_array($required, $headers)) {
            $found_required = true;
            break;
        }
    }
    
    return array(
        'valid' => $found_required,
        'message' => $found_required ? 
            'Valid CSV headers detected' : 
            'Missing required casino name column'
    );
}

/**
 * Get field mapping for CSV to WordPress
 */
function cdi_get_field_mappings() {
    return array(
        'csv_to_meta' => array(
            'WeekDay Min' => '_custom-radio-2',
            'WeekNight Min' => '_custom-radio-7',
            'Weekend Min' => '_custom-radio-8', 
            'WeekendNight Min' => '_custom-radio-9',
            'Rewards' => '_custom-radio-5',
            'Sidebet' => '_custom-checkbox-2'
        ),
        'bubble_craps_fields' => array(
            'types' => '_custom-checkbox',
            'min_bet' => '_custom-radio-3',
            'rewards' => '_custom-radio'
        ),
        'min_bet_ranges' => array(
            'N/A or Unknown',
            '$1 - $10',
            '$11 - $20', 
            '$20 +'
        ),
        'bubble_craps_types' => array(
            'none' => 'No Bubble Craps or Unknown',
            'single' => 'Single Machine',
            'stadium' => 'Stadium Craps',
            'crapless' => 'Crapless Craps',
            'casino-wizard' => 'Casino Wizard',
            'rtw' => 'Roll to Win'
        )
    );
}

/**
 * Log import activity
 */
function cdi_log_activity($message, $level = 'info') {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log("CDI [{$level}]: {$message}");
    }
}

/**
 * Get casino meta field value with fallback
 */
function cdi_get_casino_meta($casino_id, $meta_key, $default = '') {
    $value = get_post_meta($casino_id, $meta_key, true);
    
    return !empty($value) ? $value : $default;
}

/**
 * Update casino meta field safely
 */
function cdi_update_casino_meta($casino_id, $meta_key, $value, $prev_value = '') {
    if (empty($prev_value)) {
        return update_post_meta($casino_id, $meta_key, $value);
    } else {
        return update_post_meta($casino_id, $meta_key, $value, $prev_value);
    }
}

/**
 * Get casino categories
 */
function cdi_get_casino_categories($casino_id) {
    return wp_get_post_terms($casino_id, 'at_biz_dir-categories', array(
        'fields' => 'names'
    ));
}

/**
 * Get casino tags
 */
function cdi_get_casino_tags($casino_id) {
    return wp_get_post_terms($casino_id, 'at_biz_dir-tags', array(
        'fields' => 'names'
    ));
}

/**
 * Check if casino has bubble craps
 */
function cdi_has_bubble_craps($casino_id) {
    $categories = cdi_get_casino_categories($casino_id);
    return in_array('Has Bubble Craps', $categories);
}

/**
 * Get bubble craps types for casino
 */
function cdi_get_bubble_craps_types($casino_id) {
    $types = get_post_meta($casino_id, '_custom-checkbox', true);
    
    if (is_array($types)) {
        return $types;
    } elseif (is_string($types)) {
        return explode(',', $types);
    }
    
    return array();
}

/**
 * Format bubble craps types for display
 */
function cdi_format_bubble_craps_types($types) {
    if (empty($types)) {
        return 'None';
    }
    
    $mappings = cdi_get_field_mappings();
    $type_labels = $mappings['bubble_craps_types'];
    
    $formatted = array();
    foreach ((array)$types as $type) {
        $formatted[] = $type_labels[$type] ?? ucfirst($type);
    }
    
    return implode(', ', $formatted);
}

/**
 * Sanitize CSV row data
 */
function cdi_sanitize_csv_row($row) {
    $sanitized = array();
    
    foreach ($row as $key => $value) {
        $sanitized_key = sanitize_key(str_replace(' ', '_', strtolower($key)));
        $sanitized_value = sanitize_text_field($value);
        $sanitized[$sanitized_key] = $sanitized_value;
    }
    
    return $sanitized;
}

/**
 * Validate casino ID
 */
function cdi_validate_casino_id($casino_id) {
    if (!is_numeric($casino_id) || $casino_id <= 0) {
        return false;
    }
    
    $post = get_post($casino_id);
    
    return $post && $post->post_type === 'at_biz_dir' && $post->post_status === 'publish';
}

/**
 * Get casino permalink
 */
function cdi_get_casino_permalink($casino_id) {
    if (!cdi_validate_casino_id($casino_id)) {
        return '';
    }
    
    return get_permalink($casino_id);
}

/**
 * Generate nonce for AJAX requests
 */
function cdi_get_nonce() {
    return wp_create_nonce('cdi_nonce');
}

/**
 * Verify nonce for AJAX requests
 */
function cdi_verify_nonce($nonce) {
    return wp_verify_nonce($nonce, 'cdi_nonce');
}

/**
 * Get plugin options with defaults
 */
function cdi_get_option($option_name, $default = null) {
    $options = array(
        'similarity_threshold' => 80,
        'auto_update' => 1,
        'update_existing' => 1,
        'batch_size' => 50,
        'enable_logging' => 0
    );
    
    $value = get_option('cdi_' . $option_name, $options[$option_name] ?? $default);
    
    return $value;
}

/**
 * Update plugin option
 */
function cdi_update_option($option_name, $value) {
    return update_option('cdi_' . $option_name, $value);
}

/**
 * Get casino location
 */
function cdi_get_casino_location($casino_id) {
    // Try meta field first
    $location = get_post_meta($casino_id, '_location', true);
    
    if (empty($location)) {
        // Try taxonomy
        $locations = wp_get_post_terms($casino_id, 'at_biz_dir-location', array('fields' => 'names'));
        $location = !empty($locations) ? implode(', ', $locations) : '';
    }
    
    return $location;
}

/**
 * Format date for display
 */
function cdi_format_date($date, $format = 'M j, Y g:i A') {
    if (empty($date)) {
        return '';
    }
    
    return date($format, strtotime($date));
}

/**
 * Calculate import success rate
 */
function cdi_calculate_success_rate($total, $successful) {
    if ($total == 0) {
        return 0;
    }
    
    return round(($successful / $total) * 100, 1);
}

/**
 * Get recent import statistics
 */
function cdi_get_recent_stats($days = 30) {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'cdi_import_history';
    $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    
    $stats = $wpdb->get_row($wpdb->prepare(
        "SELECT 
            COUNT(*) as import_count,
            SUM(total_rows) as total_rows,
            SUM(updated_casinos) as total_updated,
            SUM(queued_items) as total_queued
         FROM {$table_name} 
         WHERE import_date >= %s",
        $date_threshold
    ));
    
    return array(
        'imports' => $stats->import_count ?? 0,
        'rows_processed' => $stats->total_rows ?? 0,
        'casinos_updated' => $stats->total_updated ?? 0,
        'items_queued' => $stats->total_queued ?? 0,
        'success_rate' => cdi_calculate_success_rate(
            $stats->total_rows ?? 0, 
            $stats->total_updated ?? 0
        )
    );
}

/**
 * Clean up old import data
 */
function cdi_cleanup_old_data($days = 90) {
    global $wpdb;
    
    $tables = array(
        $wpdb->prefix . 'cdi_import_history',
        $wpdb->prefix . 'cdi_review_queue'
    );
    
    $date_threshold = date('Y-m-d H:i:s', strtotime("-{$days} days"));
    $cleaned = 0;
    
    foreach ($tables as $table) {
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table} WHERE created_at < %s OR import_date < %s",
            $date_threshold,
            $date_threshold
        ));
        
        $cleaned += $deleted;
    }
    
    return $cleaned;
}

/**
 * Export review queue to CSV
 */
function cdi_export_review_queue() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'cdi_review_queue';
    $items = $wpdb->get_results(
        "SELECT * FROM {$table_name} WHERE status = 'pending' ORDER BY created_at DESC"
    );
    
    if (empty($items)) {
        return false;
    }
    
    $filename = 'review-queue-' . date('Y-m-d') . '.csv';
    $filepath = wp_upload_dir()['basedir'] . '/' . $filename;
    
    $handle = fopen($filepath, 'w');
    
    // Headers
    fputcsv($handle, array('Casino Name', 'Reason', 'CSV Data', 'Date Created'));
    
    // Data rows
    foreach ($items as $item) {
        $csv_data = json_decode($item->csv_data, true);
        $csv_summary = '';
        
        if ($csv_data) {
            $summary_parts = array();
            foreach (array_slice($csv_data, 0, 3) as $key => $value) {
                $summary_parts[] = "{$key}: {$value}";
            }
            $csv_summary = implode(' | ', $summary_parts);
        }
        
        fputcsv($handle, array(
            $item->casino_name,
            $item->reason,
            $csv_summary,
            $item->created_at
        ));
    }
    
    fclose($handle);
    
    return array(
        'filename' => $filename,
        'filepath' => $filepath,
        'url' => wp_upload_dir()['baseurl'] . '/' . $filename
    );
}

/**
 * JSON encode for JavaScript with proper escaping
 */
function cdi_json_encode_for_js($data) {
    return wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

/**
 * Check if Directorist plugin is active
 */
function cdi_is_directorist_active() {
    return class_exists('Directorist_Base') || is_plugin_active('directorist/directorist.php');
}

/**
 * Get plugin status information
 */
function cdi_get_plugin_status() {
    return array(
        'directorist_active' => cdi_is_directorist_active(),
        'database_ready' => cdi_check_database_tables(),
        'upload_dir_writable' => wp_is_writable(wp_upload_dir()['basedir']),
        'php_version' => PHP_VERSION,
        'wp_version' => get_bloginfo('version')
    );
}

/**
 * Check if required database tables exist
 */
function cdi_check_database_tables() {
    global $wpdb;
    
    $tables = array(
        $wpdb->prefix . 'cdi_review_queue',
        $wpdb->prefix . 'cdi_import_history'
    );
    
    foreach ($tables as $table) {
        $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        if (!$exists) {
            return false;
        }
    }
    
    return true;
}

/**
 * Get system requirements status
 */
function cdi_check_system_requirements() {
    $requirements = array(
        'php_version' => array(
            'required' => '7.4',
            'current' => PHP_VERSION,
            'status' => version_compare(PHP_VERSION, '7.4', '>=')
        ),
        'wp_version' => array(
            'required' => '5.0',
            'current' => get_bloginfo('version'),
            'status' => version_compare(get_bloginfo('version'), '5.0', '>=')
        ),
        'directorist' => array(
            'required' => 'Active',
            'current' => cdi_is_directorist_active() ? 'Active' : 'Inactive',
            'status' => cdi_is_directorist_active()
        ),
        'upload_writable' => array(
            'required' => 'Writable',
            'current' => wp_is_writable(wp_upload_dir()['basedir']) ? 'Writable' : 'Not Writable',
            'status' => wp_is_writable(wp_upload_dir()['basedir'])
        )
    );
    
    return $requirements;
}

/**
 * Display admin notice
 */
function cdi_admin_notice($message, $type = 'info') {
    $class = 'notice notice-' . $type;
    printf('<div class="%1$s"><p>%2$s</p></div>', esc_attr($class), esc_html($message));
}

/**
 * Get allowed file extensions
 */
function cdi_get_allowed_extensions() {
    return array('csv');
}

/**
 * Validate uploaded file
 */
function cdi_validate_uploaded_file($file) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return array(
            'valid' => false,
            'message' => 'File upload error: ' . ($file['error'] ?? 'Unknown error')
        );
    }
    
    $allowed_extensions = cdi_get_allowed_extensions();
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if (!in_array($file_extension, $allowed_extensions)) {
        return array(
            'valid' => false,
            'message' => 'Invalid file type. Only CSV files are allowed.'
        );
    }
    
    $max_size = wp_max_upload_size();
    if ($file['size'] > $max_size) {
        return array(
            'valid' => false,
            'message' => 'File too large. Maximum size is ' . size_format($max_size)
        );
    }
    
    return array(
        'valid' => true,
        'message' => 'File is valid'
    );
}

/**
 * Get upload progress (for future enhancement)
 */
function cdi_get_upload_progress($session_id) {
    return get_transient('cdi_upload_progress_' . $session_id);
}

/**
 * Set upload progress (for future enhancement)
 */
function cdi_set_upload_progress($session_id, $progress) {
    set_transient('cdi_upload_progress_' . $session_id, $progress, 300);
}