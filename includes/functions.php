<?php
/**
 * Helper Functions for Craps Data Importer
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Format currency for display
 */
function cdi_format_currency($amount, $show_sign = true) {
    $formatted = number_format(abs($amount), 2);
    
    if ($show_sign) {
        return $amount >= 0 ? '+$' . $formatted : '-$' . $formatted;
    }
    
    return '$' . $formatted;
}

/**
 * Format percentage for display
 */
function cdi_format_percentage($value, $decimals = 1) {
    return number_format($value, $decimals) . '%';
}

/**
 * Get human-readable time difference
 */
function cdi_human_time_diff($from, $to = null) {
    if (!$to) {
        $to = current_time('timestamp');
    }
    
    if (is_string($from)) {
        $from = strtotime($from);
    }
    
    if (is_string($to)) {
        $to = strtotime($to);
    }
    
    return human_time_diff($from, $to);
}

/**
 * Sanitize import session ID
 */
function cdi_sanitize_session_id($session_id) {
    return preg_replace('/[^a-zA-Z0-9_-]/', '', $session_id);
}

/**
 * Get casino post by ID with validation
 */
function cdi_get_casino_post($post_id) {
    $post = get_post($post_id);
    
    if (!$post || $post->post_type !== 'at_biz_dir') {
        return null;
    }
    
    return $post;
}

/**
 * Get casino bubble craps status
 */
function cdi_get_casino_bubble_status($post_id) {
    $categories = wp_get_post_terms($post_id, 'at_biz_dir-category', array('fields' => 'names'));
    
    if (is_wp_error($categories)) {
        return 'unknown';
    }
    
    if (in_array('Has Bubble Craps', $categories)) {
        return 'has';
    } elseif (in_array('No Bubble Craps (or unknown)', $categories) || in_array('No Bubble Craps', $categories)) {
        return 'none';
    }
    
    return 'unknown';
}

/**
 * Get casino bubble craps types
 */
function cdi_get_casino_bubble_types($post_id) {
    $types = get_post_meta($post_id, '_custom-checkbox', true);
    
    if (empty($types)) {
        return array();
    }
    
    if (!is_array($types)) {
        $types = array($types);
    }
    
    // Map internal values to display names
    $type_map = array(
        'single' => __('Single Machine', 'craps-data-importer'),
        'stadium' => __('Stadium Craps', 'craps-data-importer'),
        'casino wizard' => __('Casino Wizard', 'craps-data-importer'),
        'none' => __('No Bubble Craps', 'craps-data-importer'),
        'crapless' => __('Crapless Craps', 'craps-data-importer'),
        'rtw' => __('Roll to Win', 'craps-data-importer')
    );
    
    $display_types = array();
    foreach ($types as $type) {
        if (isset($type_map[$type])) {
            $display_types[] = $type_map[$type];
        } else {
            $display_types[] = ucwords(str_replace('_', ' ', $type));
        }
    }
    
    return $display_types;
}

/**
 * Get casino minimum bet
 */
function cdi_get_casino_min_bet($post_id) {
    return get_post_meta($post_id, '_custom-radio-3', true) ?: __('Unknown', 'craps-data-importer');
}

/**
 * Get casino rewards program
 */
function cdi_get_casino_rewards($post_id) {
    $rewards = get_post_meta($post_id, '_custom-radio', true);
    
    if ($rewards === 'Yes') {
        return __('Earns Points/Comps', 'craps-data-importer');
    } elseif ($rewards === 'No') {
        return __('No Points/Comps', 'craps-data-importer');
    }
    
    return __('Unknown', 'craps-data-importer');
}

/**
 * Generate action type badge HTML
 */
function cdi_get_action_badge($action_type) {
    $badges = array(
        'updated' => array(
            'class' => 'cdi-badge-success',
            'icon' => '✅',
            'text' => __('Updated', 'craps-data-importer')
        ),
        'created' => array(
            'class' => 'cdi-badge-info',
            'icon' => '🆕',
            'text' => __('Created', 'craps-data-importer')
        ),
        'skipped' => array(
            'class' => 'cdi-badge-secondary',
            'icon' => '⏭️',
            'text' => __('Skipped', 'craps-data-importer')
        ),
        'queued' => array(
            'class' => 'cdi-badge-warning',
            'icon' => '📋',
            'text' => __('Queued', 'craps-data-importer')
        ),
        'errors' => array(
            'class' => 'cdi-badge-danger',
            'icon' => '❌',
            'text' => __('Error', 'craps-data-importer')
        )
    );
    
    $badge = $badges[$action_type] ?? $badges['errors'];
    
    return sprintf(
        '<span class="cdi-action-badge %s">%s %s</span>',
        esc_attr($badge['class']),
        $badge['icon'],
        esc_html($badge['text'])
    );
}

/**
 * Generate similarity badge HTML
 */
function cdi_get_similarity_badge($similarity) {
    $similarity = floatval($similarity);
    
    if ($similarity >= 90) {
        $class = 'cdi-similarity-excellent';
        $color = '#28a745';
    } elseif ($similarity >= 80) {
        $class = 'cdi-similarity-good';
        $color = '#17a2b8';
    } elseif ($similarity >= 70) {
        $class = 'cdi-similarity-fair';
        $color = '#ffc107';
    } else {
        $class = 'cdi-similarity-poor';
        $color = '#dc3545';
    }
    
    return sprintf(
        '<span class="cdi-similarity-badge %s" style="background-color: %s; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: bold;">🎯 %s%%</span>',
        esc_attr($class),
        esc_attr($color),
        number_format($similarity, 1)
    );
}

/**
 * Truncate text with ellipsis
 */
function cdi_truncate_text($text, $length = 100, $suffix = '...') {
    if (strlen($text) <= $length) {
        return $text;
    }
    
    return substr($text, 0, $length - strlen($suffix)) . $suffix;
}

/**
 * Escape and prepare JSON for JavaScript
 */
function cdi_json_encode_for_js($data) {
    return wp_json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/**
 * Get plugin option with default
 */
function cdi_get_option($option_name, $default = null) {
    return get_option('cdi_' . $option_name, $default);
}

/**
 * Update plugin option
 */
function cdi_update_option($option_name, $value) {
    return update_option('cdi_' . $option_name, $value);
}

/**
 * Delete plugin option
 */
function cdi_delete_option($option_name) {
    return delete_option('cdi_' . $option_name);
}

/**
 * Check if user can manage imports
 */
function cdi_user_can_manage_imports() {
    return current_user_can('manage_options');
}

/**
 * Get casino search results for AJAX
 */
function cdi_search_casinos($search_term, $limit = 20) {
    $args = array(
        'post_type' => 'at_biz_dir',
        'post_status' => 'publish',
        's' => $search_term,
        'posts_per_page' => $limit,
        'orderby' => 'relevance',
        'order' => 'DESC'
    );
    
    $query = new WP_Query($args);
    $results = array();
    
    if ($query->have_posts()) {
        while ($query->have_posts()) {
            $query->the_post();
            
            $post_id = get_the_ID();
            $locations = wp_get_post_terms($post_id, 'at_biz_dir-location', array('fields' => 'names'));
            $location_text = !empty($locations) && !is_wp_error($locations) ? implode(', ', $locations) : '';
            
            $results[] = array(
                'id' => $post_id,
                'title' => get_the_title(),
                'location' => $location_text,
                'bubble_status' => cdi_get_casino_bubble_status($post_id),
                'edit_url' => get_edit_post_link($post_id),
                'view_url' => get_permalink($post_id)
            );
        }
        wp_reset_postdata();
    }
    
    return $results;
}

/**
 * Get casino preview data for AJAX
 */
function cdi_get_casino_preview($post_id) {
    $post = cdi_get_casino_post($post_id);
    if (!$post) {
        return null;
    }
    
    $locations = wp_get_post_terms($post_id, 'at_biz_dir-location', array('fields' => 'names'));
    $location_text = !empty($locations) && !is_wp_error($locations) ? implode(', ', $locations) : __('No location set', 'craps-data-importer');
    
    $bubble_status = cdi_get_casino_bubble_status($post_id);
    $bubble_types = cdi_get_casino_bubble_types($post_id);
    $min_bet = cdi_get_casino_min_bet($post_id);
    $rewards = cdi_get_casino_rewards($post_id);
    
    return array(
        'id' => $post_id,
        'title' => $post->post_title,
        'location' => $location_text,
        'bubble_status' => $bubble_status,
        'bubble_types' => $bubble_types,
        'min_bet' => $min_bet,
        'rewards' => $rewards,
        'edit_url' => get_edit_post_link($post_id),
        'view_url' => get_permalink($post_id),
        'last_modified' => get_the_modified_date('F j, Y g:i A', $post_id)
    );
}

/**
 * Validate import configuration
 */
function cdi_validate_import_config($config) {
    $errors = array();
    
    // Check required fields
    if (empty($config['strategy'])) {
        $errors[] = __('Import strategy is required', 'craps-data-importer');
    }
    
    if (empty($config['sheets']) || !is_array($config['sheets'])) {
        $errors[] = __('At least one sheet must be selected', 'craps-data-importer');
    }
    
    // Validate strategy
    $valid_strategies = array('updates_only', 'create_and_update', 'review_queue');
    if (!in_array($config['strategy'], $valid_strategies)) {
        $errors[] = __('Invalid import strategy', 'craps-data-importer');
    }
    
    // Validate new casino action
    $valid_actions = array('auto_create', 'review_queue', 'skip');
    if (!empty($config['new_casino_action']) && !in_array($config['new_casino_action'], $valid_actions)) {
        $errors[] = __('Invalid new casino action', 'craps-data-importer');
    }
    
    return $errors;
}

/**
 * Log debug message if debug mode is enabled
 */
function cdi_debug_log($message, $data = null) {
    if (!defined('WP_DEBUG') || !WP_DEBUG) {
        return;
    }
    
    $log_message = '[CDI Debug] ' . $message;
    
    if ($data !== null) {
        $log_message .= ' | Data: ' . wp_json_encode($data);
    }
    
    error_log($log_message);
}

/**
 * Generate unique session ID
 */
function cdi_generate_session_id($prefix = 'import') {
    return $prefix . '_' . uniqid() . '_' . time();
}

/**
 * Get file type icon
 */
function cdi_get_file_type_icon($filename) {
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    $icons = array(
        'csv' => '📊',
        'xlsx' => '📈',
        'xls' => '📈',
        'pdf' => '📄',
        'txt' => '📝'
    );
    
    return $icons[$extension] ?? '📁';
}

/**
 * Get status color for different states
 */
function cdi_get_status_color($status) {
    $colors = array(
        'pending' => '#ffc107',
        'processing' => '#17a2b8',
        'completed' => '#28a745',
        'error' => '#dc3545',
        'skipped' => '#6c757d',
        'updated' => '#28a745',
        'created' => '#007bff',
        'queued' => '#fd7e14'
    );
    
    return $colors[$status] ?? '#6c757d';
}

/**
 * Format import statistics for display
 */
function cdi_format_import_stats($stats) {
    $total = $stats['updated'] + $stats['created'] + $stats['queued'] + $stats['skipped'] + $stats['errors'];
    
    if ($total === 0) {
        return __('No records processed', 'craps-data-importer');
    }
    
    $parts = array();
    
    if ($stats['updated'] > 0) {
        $parts[] = sprintf(_n('%d updated', '%d updated', $stats['updated'], 'craps-data-importer'), $stats['updated']);
    }
    
    if ($stats['created'] > 0) {
        $parts[] = sprintf(_n('%d created', '%d created', $stats['created'], 'craps-data-importer'), $stats['created']);
    }
    
    if ($stats['skipped'] > 0) {
        $parts[] = sprintf(_n('%d skipped', '%d skipped', $stats['skipped'], 'craps-data-importer'), $stats['skipped']);
    }
    
    if ($stats['queued'] > 0) {
        $parts[] = sprintf(_n('%d queued', '%d queued', $stats['queued'], 'craps-data-importer'), $stats['queued']);
    }
    
    if ($stats['errors'] > 0) {
        $parts[] = sprintf(_n('%d error', '%d errors', $stats['errors'], 'craps-data-importer'), $stats['errors']);
    }
    
    return implode(', ', $parts);
}

/**
 * Get import recommendations based on data analysis
 */
function cdi_get_import_recommendations($analysis) {
    $recommendations = array();
    
    $total_records = $analysis['total_records'] ?? 0;
    $bubble_craps_count = $analysis['bubble_craps_count'] ?? 0;
    
    if ($total_records === 0) {
        $recommendations[] = array(
            'type' => 'warning',
            'message' => __('No data found to import. Please check your file format.', 'craps-data-importer')
        );
        return $recommendations;
    }
    
    // Data quality recommendations
    if ($bubble_craps_count === 0) {
        $recommendations[] = array(
            'type' => 'info',
            'message' => __('No casinos with bubble craps found. This might indicate a data formatting issue.', 'craps-data-importer')
        );
    }
    
    if ($total_records > 100) {
        $recommendations[] = array(
            'type' => 'info',
            'message' => sprintf(__('Large dataset detected (%d records). Consider using "Review Queue" strategy for better control.', 'craps-data-importer'), $total_records)
        );
    }
    
    // Strategy recommendations
    $bubble_percentage = $total_records > 0 ? ($bubble_craps_count / $total_records) * 100 : 0;
    
    if ($bubble_percentage > 80) {
        $recommendations[] = array(
            'type' => 'success',
            'message' => sprintf(__('High quality data detected (%.1f%% have bubble craps data). Safe to use automatic processing.', 'craps-data-importer'), $bubble_percentage)
        );
    } elseif ($bubble_percentage < 20) {
        $recommendations[] = array(
            'type' => 'warning',
            'message' => sprintf(__('Low bubble craps data coverage (%.1f%%). Consider manual review.', 'craps-data-importer'), $bubble_percentage)
        );
    }
    
    return $recommendations;
}

/**
 * Clean up temporary files and data
 */
function cdi_cleanup_temp_data($max_age_hours = 24) {
    // Clean up old upload data
    $temp_options = array(
        'cdi_excel_data',
        'cdi_import_progress',
        'cdi_last_import_results'
    );
    
    $cutoff_time = time() - ($max_age_hours * HOUR_IN_SECONDS);
    
    foreach ($temp_options as $option) {
        $data = get_option($option);
        if ($data && isset($data['timestamp'])) {
            $data_time = strtotime($data['timestamp']);
            if ($data_time < $cutoff_time) {
                delete_option($option);
            }
        }
    }
    
    // Clean up old import logs
    global $wpdb;
    $logs_table = $wpdb->prefix . 'cdi_import_logs';
    $cutoff_date = date('Y-m-d H:i:s', $cutoff_time);
    
    $wpdb->delete(
        $logs_table,
        array('created_at' => $cutoff_date),
        array('%s')
    );
}

/**
 * Get system information for debugging
 */
function cdi_get_system_info() {
    global $wp_version, $wpdb;
    
    return array(
        'wordpress_version' => $wp_version,
        'php_version' => PHP_VERSION,
        'mysql_version' => $wpdb->db_version(),
        'plugin_version' => CDI_VERSION,
        'max_upload_size' => size_format(wp_max_upload_size()),
        'max_execution_time' => ini_get('max_execution_time'),
        'memory_limit' => ini_get('memory_limit'),
        'post_max_size' => ini_get('post_max_size'),
        'directorist_active' => is_plugin_active('directorist/directorist.php'),
        'business_directory_posts' => wp_count_posts('at_biz_dir')->publish ?? 0
    );
}
            '