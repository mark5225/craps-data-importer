<?php
/**
 * Helper functions for Craps Data Importer
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Log messages for debugging
 */
function cdi_log($message) {
    if (cdi_get_option('enable_logging', 0)) {
        error_log('CDI: ' . $message);
    }
}

/**
 * Validate uploaded file
 */
function cdi_validate_uploaded_file($file) {
    $result = array('valid' => false, 'message' => '');
    
    // Check if file was uploaded
    if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
        $result['message'] = 'No file was uploaded.';
        return $result;
    }
    
    // Check file size (15MB max)
    $max_size = 15 * 1024 * 1024; // 15MB
    if ($file['size'] > $max_size) {
        $result['message'] = 'File is too large. Maximum size is 15MB.';
        return $result;
    }
    
    // Check file type
    $allowed_types = array('text/csv', 'application/csv', 'text/plain');
    if (!in_array($file['type'], $allowed_types)) {
        $result['message'] = 'Invalid file type. Please upload a CSV file.';
        return $result;
    }
    
    // Check file extension
    $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($file_extension !== 'csv') {
        $result['message'] = 'Invalid file extension. Please upload a .csv file.';
        return $result;
    }
    
    // Check if file is readable
    if (!is_readable($file['tmp_name'])) {
        $result['message'] = 'Uploaded file is not readable.';
        return $result;
    }
    
    $result['valid'] = true;
    return $result;
}

/**
 * Get upload error message
 */
function cdi_get_upload_error_message($error_code) {
    switch ($error_code) {
        case UPLOAD_ERR_OK:
            return 'No error';
        case UPLOAD_ERR_INI_SIZE:
            return 'File size exceeds upload_max_filesize directive';
        case UPLOAD_ERR_FORM_SIZE:
            return 'File size exceeds MAX_FILE_SIZE directive';
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
        $location = !empty($locations) ? $locations[0] : '';
    }
    
    return $location;
}

/**
 * Format currency value
 */
function cdi_format_currency($value) {
    if (empty($value) || !is_numeric($value)) {
        return $value;
    }
    
    return '$' . number_format($value, 0);
}

/**
 * Clean casino name for matching
 */
function cdi_clean_casino_name($name) {
    $name = strtolower(trim($name));
    
    // Remove common suffixes/prefixes
    $replacements = array(
        ' casino' => '',
        ' hotel' => '',
        ' resort' => '',
        ' las vegas' => '',
        ' lv' => '',
        'the ' => '',
        ' & ' => ' and ',
    );
    
    foreach ($replacements as $search => $replace) {
        $name = str_replace($search, $replace, $name);
    }
    
    // Clean up whitespace
    $name = preg_replace('/\s+/', ' ', $name);
    $name = trim($name);
    
    return $name;
}

/**
 * Parse minimum bet value
 */
function cdi_parse_min_bet($value) {
    if (empty($value)) {
        return '';
    }
    
    // Remove currency symbols and extra text
    $value = preg_replace('/[^\d\.]/', '', $value);
    
    if (is_numeric($value)) {
        return (int) $value;
    }
    
    return $value;
}

/**
 * Parse odds value
 */
function cdi_parse_odds($value) {
    if (empty($value)) {
        return '';
    }
    
    // Common patterns: "3x", "3X", "3x4x5x", etc.
    $value = strtolower(trim($value));
    
    // Remove extra spaces and normalize
    $value = preg_replace('/\s+/', '', $value);
    
    return $value;
}

/**
 * Check if casino has bubble craps
 */
function cdi_has_bubble_craps($casino_id) {
    $bubble_craps = get_post_meta($casino_id, '_bubble_craps', true);
    return !empty($bubble_craps) && strtolower($bubble_craps) !== 'no';
}

/**
 * Get directory post types that might be casinos
 */
function cdi_get_casino_post_types() {
    return array('at_biz_dir', 'business', 'casino', 'listing');
}

/**
 * Search for existing casino posts
 */
function cdi_search_casino_posts($search_term, $limit = 20) {
    $post_types = cdi_get_casino_post_types();
    
    $args = array(
        'post_type' => $post_types,
        'post_status' => 'publish',
        's' => $search_term,
        'posts_per_page' => $limit,
        'orderby' => 'relevance',
        'order' => 'DESC'
    );
    
    return get_posts($args);
}

/**
 * Convert Yes/No values to boolean
 */
function cdi_parse_boolean($value) {
    if (empty($value)) {
        return '';
    }
    
    $value = strtolower(trim($value));
    
    if (in_array($value, array('yes', 'y', '1', 'true', 'on'))) {
        return 'Yes';
    } elseif (in_array($value, array('no', 'n', '0', 'false', 'off'))) {
        return 'No';
    }
    
    return $value;
}

/**
 * Get current WordPress timezone
 */
function cdi_get_wp_timezone() {
    $timezone_string = get_option('timezone_string');
    
    if (!empty($timezone_string)) {
        return new DateTimeZone($timezone_string);
    }
    
    $offset = get_option('gmt_offset');
    $hours = (int) $offset;
    $minutes = abs(($offset - $hours) * 60);
    $offset_string = sprintf('%+03d:%02d', $hours, $minutes);
    
    return new DateTimeZone($offset_string);
}

/**
 * Format date for display
 */
function cdi_format_date($date_string) {
    if (empty($date_string)) {
        return '';
    }
    
    $timezone = cdi_get_wp_timezone();
    $date = new DateTime($date_string, $timezone);
    
    return $date->format(get_option('date_format') . ' ' . get_option('time_format'));
}