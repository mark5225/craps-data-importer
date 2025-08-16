<?php
/**
 * Helper functions for Craps Data Importer
 */

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
 * Format casino types for display
 */
function cdi_format_casino_types($types) {
    if (empty($types)) {
        return '';
    }
    
    if (is_string($types)) {
        $types = explode(',', $types);
    }
    
    $formatted = array();
    foreach ($types as $type) {
        $type = trim($type);
        $formatted[] = !empty($type) ? ucfirst($type) : '';
    }
    
    return implode(', ', array_filter($formatted));
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
        // Try address field
        $location = get_post_meta($casino_id, '_address', true);
    }
    
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
 * Validate uploaded file
 */
function cdi_validate_uploaded_file($file) {
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return array(
            'valid' => false,
            'message' => 'File upload error: ' . ($file['error'] ?? 'Unknown error')
        );
    }
    
    $allowed_extensions = array('csv');
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
 * Log import activity
 */
function cdi_log($message, $level = 'info') {
    if (!cdi_get_option('enable_logging')) {
        return;
    }
    
    $log_entry = sprintf(
        '[%s] [%s] %s',
        date('Y-m-d H:i:s'),
        strtoupper($level),
        $message
    );
    
    error_log($log_entry);
}

/**
 * Get file upload errors
 */
function cdi_get_upload_error_message($error_code) {
    switch ($error_code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'File size exceeds PHP upload_max_filesize directive';
        case UPLOAD_ERR_FORM_SIZE:
            return 'File size exceeds HTML form MAX_FILE_SIZE directive';
        case UPLOAD_ERR_PARTIAL:
            return 'File was only partially uploaded';
        case UPLOAD_ERR_NO_FILE:
            return 'No file was uploaded';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Missing temporary folder';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Failed to write file to disk';
        case UPLOAD_ERR_EXTENSION:
            return 'File upload stopped by extension';
        default:
            return 'Unknown upload error';
    }
}