<?php
/**
 * Admin interface for Craps Data Importer
 */

if (!defined('ABSPATH')) {
    exit;
}

class CDI_Admin {
    
    /**
     * Render main importer page
     */
    public function render_main_page() {
        $csv_data = get_transient('cdi_csv_data');
        $step = $_GET['step'] ?? 'upload';
        
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Craps Data Importer', 'craps-data-importer') . '</h1>';
        
        switch ($step) {
            case 'preview':
                $this->render_preview_step($csv_data);
                break;
            case 'import':
                $this->render_import_step();
                break;
            default:
                $this->render_upload_step();
                break;
        }
        
        echo '</div>';
    }
    
    /**
     * Render upload step
     */
    private function render_upload_step() {
        echo '<div class="cdi-grid">';
        
        // Upload form
        echo '<div class="cdi-card">';
        echo '<h2>📊 ' . esc_html__('Upload CSV File', 'craps-data-importer') . '</h2>';
        echo '<p>' . esc_html__('Upload a Downtown LV format CSV file to import craps data.', 'craps-data-importer') . '</p>';
        
        echo '<form id="cdi-upload-form" enctype="multipart/form-data">';
        wp_nonce_field('cdi_nonce', 'cdi_nonce');
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th><label for="csv_file">' . esc_html__('CSV File', 'craps-data-importer') . '</label></th>';
        echo '<td>';
        echo '<input type="file" id="csv_file" name="csv_file" accept=".csv" required>';
        echo '<p class="description">' . esc_html__('Expected columns: Downtown Casino, WeekDay Min, WeekNight Min, etc.', 'craps-data-importer') . '</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        
        echo '<p><button type="submit" class="button button-primary">' . esc_html__('📤 Upload & Preview', 'craps-data-importer') . '</button></p>';
        echo '</form>';
        echo '</div>';
        
        // Quick stats
        echo '<div class="cdi-card">';
        echo '<h3>📈 ' . esc_html__('Quick Stats', 'craps-data-importer') . '</h3>';
        
        $casino_count = wp_count_posts('at_biz_dir')->publish ?? 0;
        $queue_count = $this->get_queue_count();
        
        echo '<p><strong>' . esc_html__('Total Casinos:', 'craps-data-importer') . '</strong> ' . number_format($casino_count) . '</p>';
        echo '<p><strong>' . esc_html__('Review Queue:', 'craps-data-importer') . '</strong> ' . number_format($queue_count) . '</p>';
        echo '<p><a href="' . admin_url('admin.php?page=craps-review-queue') . '" class="button">' . esc_html__('View Review Queue', 'craps-data-importer') . '</a></p>';
        echo '</div>';
        
        echo '</div>';
        
        // System status
        echo '<div class="cdi-card">';
        echo '<h3>💻 ' . esc_html__('System Status', 'craps-data-importer') . '</h3>';
        echo '<table class="form-table">';
        echo '<tr><th>PHP Version</th><td>' . PHP_VERSION . '</td></tr>';
        echo '<tr><th>WordPress</th><td>' . get_bloginfo('version') . '</td></tr>';
        echo '<tr><th>Max Upload</th><td>' . size_format(wp_max_upload_size()) . '</td></tr>';
        echo '<tr><th>Memory Limit</th><td>' . ini_get('memory_limit') . '</td></tr>';
        echo '</table>';
        echo '</div>';
    }
    
    /**
     * Render preview step
     */
    private function render_preview_step($csv_data) {
        if (!$csv_data) {
            echo '<div class="notice notice-error"><p>' . esc_html__('No CSV data found. Please upload a file first.', 'craps-data-importer') . '</p></div>';
            echo '<p><a href="' . admin_url('admin.php?page=craps-data-importer') . '" class="button">' . esc_html__('← Back to Upload', 'craps-data-importer') . '</a></p>';
            return;
        }
        
        echo '<div class="cdi-grid">';
        
        // Preview data with matching analysis
        echo '<div class="cdi-card">';
        echo '<h2>🔍 ' . esc_html__('Matching Preview & Changes', 'craps-data-importer') . '</h2>';
        
        // Get matcher for analysis
        $matcher = new CDI_Matcher();
        $processor = new CDI_Processor();
        
        // Analyze each row
        echo '<div class="cdi-preview-analysis">';
        
        $match_count = 0;
        $update_count = 0;
        
        foreach ($csv_data['data'] as $index => $row) {
            $casino_name = $this->extract_casino_name_for_preview($row);
            if (empty($casino_name)) continue;
            
            // Find match
            $match_result = $matcher->find_casino_match($casino_name, 70); // Lower threshold for preview
            
            echo '<div class="cdi-match-item">';
            echo '<h4>📊 CSV Row ' . ($index + 1) . ': ' . esc_html($casino_name) . '</h4>';
            
            if ($match_result['casino']) {
                $match_count++;
                $matched_casino = $match_result['casino'];
                $similarity = $match_result['similarity'];
                
                echo '<div class="cdi-match-info">';
                echo '<span class="cdi-match-badge ' . ($similarity >= 80 ? 'high' : 'medium') . '">';
                echo '✅ Matched: ' . esc_html($matched_casino->post_title) . ' (' . round($similarity) . '% similarity)';
                echo '</span>';
                echo '<div class="cdi-casino-details">';
                echo '<strong>🆔 Casino ID:</strong> ' . $matched_casino->ID . ' | ';
                echo '<strong>🔗 View Casino:</strong> <a href="' . get_permalink($matched_casino->ID) . '" target="_blank">View Listing</a>';
                echo '</div>';
                echo '</div>';
                
                // Show field changes organized by type
                $changes = $this->analyze_field_changes($matched_casino->ID, $row);
                $has_changes = !empty($changes['bubble_craps']) || !empty($changes['table_craps']) || !empty($changes['categories']) || !empty($changes['tags']);
                
                if ($has_changes) {
                    $update_count++;
                    
                    // Category Changes
                    if (!empty($changes['categories'])) {
                        echo '<div class="cdi-changes-section">';
                        echo '<h5>📂 Category Updates</h5>';
                        echo '<table class="wp-list-table widefat">';
                        echo '<thead><tr><th>Category Type</th><th>Current</th><th>New</th><th>Action</th></tr></thead>';
                        echo '<tbody>';
                        foreach ($changes['categories'] as $change) {
                            echo '<tr>';
                            echo '<td><strong>' . esc_html($change['field_label']) . '</strong></td>';
                            echo '<td>' . esc_html($change['current_value']) . '</td>';
                            echo '<td>' . esc_html($change['new_value']) . '</td>';
                            echo '<td><span class="cdi-action-' . $change['action'] . '">' . esc_html($change['action_label']) . '</span></td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                        echo '</div>';
                    }
                    
                    // Tags Changes
                    if (!empty($changes['tags'])) {
                        echo '<div class="cdi-changes-section">';
                        echo '<h5>🏷️ Tag Updates</h5>';
                        echo '<table class="wp-list-table widefat">';
                        echo '<thead><tr><th>Tag Type</th><th>Current</th><th>New</th><th>Action</th></tr></thead>';
                        echo '<tbody>';
                        foreach ($changes['tags'] as $change) {
                            echo '<tr>';
                            echo '<td><strong>' . esc_html($change['field_label']) . '</strong></td>';
                            echo '<td>' . esc_html($change['current_value']) . '</td>';
                            echo '<td>' . esc_html($change['new_value']) . '</td>';
                            echo '<td><span class="cdi-action-' . $change['action'] . '">' . esc_html($change['action_label']) . '</span></td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                        echo '</div>';
                    }
                    
                    // Bubble Craps Changes
                    if (!empty($changes['bubble_craps'])) {
                        echo '<div class="cdi-changes-section">';
                        echo '<h5>🎲 Bubble Craps Updates</h5>';
                        echo '<table class="wp-list-table widefat">';
                        echo '<thead><tr><th>Field</th><th>Current Value</th><th>New Value</th><th>Action</th></tr></thead>';
                        echo '<tbody>';
                        foreach ($changes['bubble_craps'] as $change) {
                            echo '<tr>';
                            echo '<td><strong>' . esc_html($change['field_label']) . '</strong></td>';
                            echo '<td>' . esc_html($change['current_value']) . '</td>';
                            echo '<td>' . esc_html($change['new_value']) . '</td>';
                            echo '<td><span class="cdi-action-' . $change['action'] . '">' . esc_html($change['action_label']) . '</span></td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                        echo '</div>';
                    }
                    
                    // Table Craps Changes
                    if (!empty($changes['table_craps'])) {
                        echo '<div class="cdi-changes-section">';
                        echo '<h5>🎰 Table Craps Updates</h5>';
                        echo '<table class="wp-list-table widefat">';
                        echo '<thead><tr><th>Field</th><th>Current Value</th><th>New Value</th><th>Action</th></tr></thead>';
                        echo '<tbody>';
                        foreach ($changes['table_craps'] as $change) {
                            echo '<tr>';
                            echo '<td><strong>' . esc_html($change['field_label']) . '</strong></td>';
                            echo '<td>' . esc_html($change['current_value']) . '</td>';
                            echo '<td>' . esc_html($change['new_value']) . '</td>';
                            echo '<td><span class="cdi-action-' . $change['action'] . '">' . esc_html($change['action_label']) . '</span></td>';
                            echo '</tr>';
                        }
                        echo '</tbody></table>';
                        echo '</div>';
                    }
                    
                } else {
                    echo '<p class="cdi-no-changes">ℹ️ No changes needed - all values are already current</p>';
                }
                
            } else {
                echo '<div class="cdi-match-info">';
                echo '<span class="cdi-match-badge no-match">❌ No matching casino found</span>';
                echo '<p class="cdi-queue-note">→ Will be added to review queue for manual matching</p>';
                echo '</div>';
            }
            
            echo '</div>'; // cdi-match-item
        }
        
        echo '</div>'; // cdi-preview-analysis
        
        // Summary stats
        echo '<div class="cdi-preview-summary">';
        echo '<h3>📈 Preview Summary</h3>';
        echo '<div class="cdi-summary-grid">';
        echo '<div class="cdi-summary-item">';
        echo '<span class="cdi-summary-number">' . count($csv_data['data']) . '</span>';
        echo '<span class="cdi-summary-label">Total Rows</span>';
        echo '</div>';
        echo '<div class="cdi-summary-item">';
        echo '<span class="cdi-summary-number">' . $match_count . '</span>';
        echo '<span class="cdi-summary-label">Matched Casinos</span>';
        echo '</div>';
        echo '<div class="cdi-summary-item">';
        echo '<span class="cdi-summary-number">' . $update_count . '</span>';
        echo '<span class="cdi-summary-label">Will Be Updated</span>';
        echo '</div>';
        echo '<div class="cdi-summary-item">';
        echo '<span class="cdi-summary-number">' . (count($csv_data['data']) - $match_count) . '</span>';
        echo '<span class="cdi-summary-label">Review Queue</span>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        
        echo '<p>';
        echo '<a href="' . admin_url('admin.php?page=craps-data-importer&step=import') . '" class="button button-primary">' . esc_html__('▶ Proceed to Import', 'craps-data-importer') . '</a> ';
        echo '<a href="' . admin_url('admin.php?page=craps-data-importer') . '" class="button">' . esc_html__('← Back to Upload', 'craps-data-importer') . '</a>';
        echo '</p>';
        echo '</div>';
        
        // Import settings
        echo '<div class="cdi-card">';
        echo '<h3>⚙️ ' . esc_html__('Import Settings', 'craps-data-importer') . '</h3>';
        
        echo '<form id="cdi-settings-form">';
        echo '<table class="form-table">';
        
        echo '<tr>';
        echo '<th>' . esc_html__('Similarity Threshold', 'craps-data-importer') . '</th>';
        echo '<td>';
        echo '<input type="range" id="similarity_threshold" name="similarity_threshold" min="60" max="95" value="80" step="5">';
        echo '<span id="threshold_value">80%</span>';
        echo '<p class="description">' . esc_html__('Minimum similarity score for automatic matching', 'craps-data-importer') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th>' . esc_html__('Auto Update', 'craps-data-importer') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="auto_update" value="1" checked> ' . esc_html__('Automatically update matched casinos', 'craps-data-importer') . '</label>';
        echo '<p class="description">' . esc_html__('Uncheck to send all matches to review queue', 'craps-data-importer') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th>' . esc_html__('Update Existing Data', 'craps-data-importer') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="update_existing" value="1" checked> ' . esc_html__('Overwrite existing field values', 'craps-data-importer') . '</label>';
        echo '<p class="description">' . esc_html__('Uncheck to only update empty fields', 'craps-data-importer') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        echo '</form>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Render import step
     */
    private function render_import_step() {
        echo '<div class="cdi-card">';
        echo '<h2>🚀 ' . esc_html__('Processing Import', 'craps-data-importer') . '</h2>';
        
        echo '<div id="cdi-import-progress">';
        echo '<div class="cdi-progress-bar">';
        echo '<div class="cdi-progress-fill" style="width: 0%"></div>';
        echo '</div>';
        echo '<p id="cdi-progress-text">' . esc_html__('Preparing import...', 'craps-data-importer') . '</p>';
        echo '</div>';
        
        echo '<div id="cdi-import-results" style="display: none;">';
        echo '<h3>' . esc_html__('Import Complete', 'craps-data-importer') . '</h3>';
        echo '<div id="cdi-results-content"></div>';
        echo '<p>';
        echo '<a href="' . admin_url('admin.php?page=craps-review-queue') . '" class="button button-primary">' . esc_html__('Review Queue', 'craps-data-importer') . '</a> ';
        echo '<a href="' . admin_url('admin.php?page=craps-data-importer') . '" class="button">' . esc_html__('New Import', 'craps-data-importer') . '</a>';
        echo '</p>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Render review queue page
     */
    public function render_review_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Review Queue', 'craps-data-importer') . '</h1>';
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'cdi_review_queue';
        
        $queue_items = $wpdb->get_results(
            "SELECT * FROM $table_name WHERE status = 'pending' ORDER BY created_at DESC"
        );
        
        if (empty($queue_items)) {
            echo '<div class="cdi-card">';
            echo '<h2>✅ ' . esc_html__('All Clear!', 'craps-data-importer') . '</h2>';
            echo '<p>' . esc_html__('No items in the review queue.', 'craps-data-importer') . '</p>';
            echo '<p><a href="' . admin_url('admin.php?page=craps-data-importer') . '" class="button button-primary">' . esc_html__('Import More Data', 'craps-data-importer') . '</a></p>';
            echo '</div>';
        } else {
            $this->render_queue_items($queue_items);
        }
        
        echo '</div>';
    }
    
    /**
     * Render import history page
     */
    public function render_history_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Import History', 'craps-data-importer') . '</h1>';
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'cdi_import_history';
        
        $history_items = $wpdb->get_results(
            "SELECT * FROM $table_name ORDER BY import_date DESC LIMIT 50"
        );
        
        if (empty($history_items)) {
            echo '<div class="cdi-card">';
            echo '<p>' . esc_html__('No import history found.', 'craps-data-importer') . '</p>';
            echo '<p><a href="' . admin_url('admin.php?page=craps-data-importer') . '" class="button button-primary">' . esc_html__('Start Importing', 'craps-data-importer') . '</a></p>';
            echo '</div>';
        } else {
            $this->render_history_table($history_items);
        }
        
        echo '</div>';
    }
    
    /**
     * Render queue items
     */
    private function render_queue_items($queue_items) {
        echo '<div class="cdi-card">';
        echo '<p>' . sprintf(
            esc_html__('Found %d items requiring review. Click resolve buttons to take action.', 'craps-data-importer'),
            count($queue_items)
        ) . '</p>';
        
        foreach ($queue_items as $item) {
            $csv_data = json_decode($item->csv_data, true);
            
            echo '<div class="cdi-queue-item" data-queue-id="' . $item->id . '">';
            echo '<h4>🎰 ' . esc_html($item->casino_name) . '</h4>';
            echo '<p><strong>Reason:</strong> ' . esc_html($item->reason) . '</p>';
            
            if ($csv_data) {
                echo '<div class="cdi-csv-data">';
                echo '<strong>Data to import:</strong>';
                echo '<ul>';
                foreach ($csv_data as $key => $value) {
                    if (!empty($value)) {
                        echo '<li><strong>' . esc_html($key) . ':</strong> ' . esc_html($value) . '</li>';
                    }
                }
                echo '</ul>';
                echo '</div>';
            }
            
            echo '<div class="cdi-queue-actions">';
            echo '<button class="button button-primary cdi-search-casino" data-casino-name="' . esc_attr($item->casino_name) . '">' . esc_html__('Find Casino', 'craps-data-importer') . '</button> ';
            echo '<button class="button cdi-skip-item">' . esc_html__('Skip', 'craps-data-importer') . '</button>';
            echo '</div>';
            
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * Render history table
     */
    private function render_history_table($history_items) {
        echo '<div class="cdi-card">';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__('Filename', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Total Rows', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Processed', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Updated', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Queued', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Date', 'craps-data-importer') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($history_items as $item) {
            echo '<tr>';
            echo '<td>' . esc_html($item->filename) . '</td>';
            echo '<td>' . number_format($item->total_rows) . '</td>';
            echo '<td>' . number_format($item->processed_rows) . '</td>';
            echo '<td>' . number_format($item->updated_casinos) . '</td>';
            echo '<td>' . number_format($item->queued_items) . '</td>';
            echo '<td>' . esc_html(date('M j, Y g:i A', strtotime($item->import_date))) . '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
    }
    
    /**
     * Get review queue count
     */
    private function get_queue_count() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cdi_review_queue';
        
        return $wpdb->get_var(
            "SELECT COUNT(*) FROM $table_name WHERE status = 'pending'"
        );
    }
    
    /**
     * Extract casino name from CSV row for preview
     */
    private function extract_casino_name_for_preview($row) {
        // Try common column names for casino
        $possible_names = array(
            'Downtown Casino',
            'Casino',
            'Casino Name',
            'Name',
            'Property'
        );
        
        foreach ($possible_names as $name) {
            if (isset($row[$name]) && !empty(trim($row[$name]))) {
                return trim($row[$name]);
            }
        }
        
        // If no standard column found, try the first non-empty value
        foreach ($row as $value) {
            if (!empty(trim($value))) {
                return trim($value);
            }
        }
        
        return '';
    }
    
    /**
     * Analyze what field changes will be made with smart cascading logic
     */
    private function analyze_field_changes($casino_id, $csv_row) {
        $changes = array(
            'bubble_craps' => array(),
            'table_craps' => array(),
            'categories' => array(),
            'tags' => array()
        );
        
        // Get current categories and values
        $current_categories = wp_get_post_terms($casino_id, 'at_biz_dir-categories', array('fields' => 'names'));
        $current_bubble_status = $this->get_current_bubble_status($current_categories);
        $current_table_status = $this->get_current_table_status($current_categories);
        
        // BUBBLE CRAPS SMART LOGIC
        $bubble_craps_value = trim($csv_row['Bubble Craps'] ?? '');
        
        if (!empty($bubble_craps_value)) {
            $new_bubble_status = null;
            $new_minimum_bet = null;
            $clear_bubble_data = false;
            
            // Parse bubble craps value
            if (strtolower($bubble_craps_value) === 'removed') {
                $new_bubble_status = 'No Bubble Craps';
                $clear_bubble_data = true;
            } elseif (preg_match('/\$(\d+)/', $bubble_craps_value, $matches)) {
                // Has minimum bet value like "$3"
                $new_bubble_status = 'Has Bubble Craps';
                $new_minimum_bet = $this->map_minimum_bet_to_option($matches[1]);
            } elseif (strtolower($bubble_craps_value) === 'yes') {
                $new_bubble_status = 'Has Bubble Craps';
                $new_minimum_bet = 'N/A or Unknown'; // Default when just "Yes"
            }
            
            // Update bubble craps category if changed
            if ($new_bubble_status && $current_bubble_status !== $new_bubble_status) {
                $changes['categories'][] = array(
                    'field_label' => 'Bubble Craps Category',
                    'current_value' => $current_bubble_status ?: '(not set)',
                    'new_value' => $new_bubble_status,
                    'action' => 'update',
                    'action_label' => 'Update Category'
                );
                
                // Update tags based on category change
                if ($new_bubble_status === 'Has Bubble Craps') {
                    $changes['tags'][] = array(
                        'field_label' => 'Bubble Craps Tags',
                        'current_value' => 'Various tags',
                        'new_value' => 'Single Bubble Machine',
                        'action' => 'add',
                        'action_label' => 'Add BC Tags'
                    );
                } else {
                    $changes['tags'][] = array(
                        'field_label' => 'Bubble Craps Tags',
                        'current_value' => 'Bubble craps tags',
                        'new_value' => '(removed)',
                        'action' => 'clear',
                        'action_label' => 'Clear BC Tags'
                    );
                }
            }
            
            if ($clear_bubble_data) {
                // Clear all bubble craps data
                $current_min_bet = get_post_meta($casino_id, '_custom-radio-3', true);
                $current_machine_types = get_post_meta($casino_id, '_custom-checkbox', true);
                $current_rewards = get_post_meta($casino_id, '_custom-radio', true);
                
                if (!empty($current_min_bet) && $current_min_bet !== 'N/A or Unknown') {
                    $changes['bubble_craps'][] = array(
                        'field_label' => 'Bubble Craps Minimum Bet',
                        'current_value' => $current_min_bet,
                        'new_value' => 'N/A or Unknown',
                        'action' => 'clear',
                        'action_label' => 'Clear'
                    );
                }
                
                if (!empty($current_machine_types) && $current_machine_types !== 'none') {
                    $changes['bubble_craps'][] = array(
                        'field_label' => 'Machine Types',
                        'current_value' => $this->format_machine_types($current_machine_types),
                        'new_value' => 'No Bubble Craps',
                        'action' => 'clear',
                        'action_label' => 'Clear'
                    );
                }
                
                if (!empty($current_rewards) && $current_rewards !== 'Unknown') {
                    $changes['bubble_craps'][] = array(
                        'field_label' => 'Player Rewards',
                        'current_value' => $current_rewards,
                        'new_value' => 'Unknown',
                        'action' => 'clear',
                        'action_label' => 'Clear'
                    );
                }
                
            } elseif ($new_bubble_status === 'Has Bubble Craps') {
                // Update bubble craps fields
                
                // Minimum bet
                if ($new_minimum_bet) {
                    $current_min_bet = get_post_meta($casino_id, '_custom-radio-3', true);
                    if ($current_min_bet !== $new_minimum_bet) {
                        $changes['bubble_craps'][] = array(
                            'field_label' => 'Bubble Craps Minimum Bet',
                            'current_value' => $current_min_bet ?: '(empty)',
                            'new_value' => $new_minimum_bet,
                            'action' => empty($current_min_bet) ? 'add' : 'update',
                            'action_label' => empty($current_min_bet) ? 'Add New' : 'Update'
                        );
                    }
                }
                
                // Machine types (default to single if not set)
                $current_machine_types = get_post_meta($casino_id, '_custom-checkbox', true);
                if (empty($current_machine_types) || $current_machine_types === 'none') {
                    $changes['bubble_craps'][] = array(
                        'field_label' => 'Machine Types',
                        'current_value' => $this->format_machine_types($current_machine_types),
                        'new_value' => 'Single Machine',
                        'action' => 'add',
                        'action_label' => 'Add New'
                    );
                }
            }
        }
        
        // Handle Rewards field separately
        $rewards_value = trim($csv_row['Rewards'] ?? '');
        if (!empty($rewards_value) && $rewards_value !== 'Unknown') {
            $current_rewards_program = get_post_meta($casino_id, '_custom-radio-5', true);
            if ($current_rewards_program !== $rewards_value) {
                $changes['bubble_craps'][] = array(
                    'field_label' => 'Rewards Program',
                    'current_value' => $current_rewards_program ?: '(empty)',
                    'new_value' => $rewards_value,
                    'action' => empty($current_rewards_program) ? 'add' : 'update',
                    'action_label' => empty($current_rewards_program) ? 'Add New' : 'Update'
                );
            }
        }
        
        // TABLE CRAPS SMART LOGIC
        $table_fields = array(
            'WeekDay Min' => '_custom-radio-2',
            'WeekNight Min' => '_custom-radio-7',
            'WeekendMin' => '_custom-radio-8',
            'WeekendnightMin' => '_custom-radio-9'
        );
        
        $table_field_labels = array(
            'WeekDay Min' => 'Weekday Minimum',
            'WeekNight Min' => 'Weeknight Minimum',
            'WeekendMin' => 'Weekend Day Minimum',
            'WeekendnightMin' => 'Weekend Night Minimum'
        );
        
        // Check if we have any table craps data
        $has_table_data = false;
        $table_data_values = array();
        
        foreach ($table_fields as $csv_field => $meta_key) {
            $value = trim($csv_row[$csv_field] ?? '');
            if (!empty($value)) {
                $has_table_data = true;
                $table_data_values[$csv_field] = $value;
            }
        }
        
        // Determine new table status
        $new_table_status = null;
        if ($has_table_data) {
            $new_table_status = 'Has Craps Table';
        } else {
            // Check if all table fields are explicitly empty (not just missing)
            $all_table_fields_present = true;
            foreach ($table_fields as $csv_field => $meta_key) {
                if (!array_key_exists($csv_field, $csv_row)) {
                    $all_table_fields_present = false;
                    break;
                }
            }
            
            if ($all_table_fields_present) {
                $new_table_status = 'No Craps Table';
            }
        }
        
        // Update table craps category if determined
        if ($new_table_status && $current_table_status !== $new_table_status) {
            $changes['categories'][] = array(
                'field_label' => 'Table Craps Category',
                'current_value' => $current_table_status ?: '(not set)',
                'new_value' => $new_table_status,
                'action' => 'update',
                'action_label' => 'Update Category'
            );
        }
        
        if ($new_table_status === 'No Craps Table') {
            // Clear all table craps data
            foreach ($table_fields as $csv_field => $meta_key) {
                $current_value = get_post_meta($casino_id, $meta_key, true);
                if (!empty($current_value) && $current_value !== 'N/A or Unknown') {
                    $changes['table_craps'][] = array(
                        'field_label' => $table_field_labels[$csv_field],
                        'current_value' => $current_value,
                        'new_value' => 'N/A or Unknown',
                        'action' => 'clear',
                        'action_label' => 'Clear'
                    );
                }
            }
            
            // Clear side bets
            $current_sidebets = get_post_meta($casino_id, '_custom-checkbox-2', true);
            if (!empty($current_sidebets)) {
                $changes['table_craps'][] = array(
                    'field_label' => 'Side Bets Available',
                    'current_value' => $this->format_side_bets($current_sidebets),
                    'new_value' => '(none)',
                    'action' => 'clear',
                    'action_label' => 'Clear'
                );
            }
            
        } elseif ($has_table_data) {
            // Update specific table craps fields
            foreach ($table_data_values as $csv_field => $value) {
                $meta_key = $table_fields[$csv_field];
                $current_value = get_post_meta($casino_id, $meta_key, true);
                $new_value = $this->map_table_minimum_to_option($value);
                
                if ($current_value !== $new_value) {
                    $changes['table_craps'][] = array(
                        'field_label' => $table_field_labels[$csv_field],
                        'current_value' => $current_value ?: '(empty)',
                        'new_value' => $new_value,
                        'action' => empty($current_value) ? 'add' : 'update',
                        'action_label' => empty($current_value) ? 'Add New' : 'Update'
                    );
                }
            }
        }
        
        // Handle side bets for table craps
        $sidebet_value = trim($csv_row['Sidebet'] ?? '');
        if (!empty($sidebet_value) && $new_table_status === 'Has Craps Table') {
            $current_sidebets = get_post_meta($casino_id, '_custom-checkbox-2', true);
            $new_sidebets = $this->map_sidebets_to_options($sidebet_value);
            
            if ($current_sidebets !== $new_sidebets) {
                $changes['table_craps'][] = array(
                    'field_label' => 'Side Bets Available',
                    'current_value' => $this->format_side_bets($current_sidebets),
                    'new_value' => $this->format_side_bets($new_sidebets),
                    'action' => empty($current_sidebets) ? 'add' : 'update',
                    'action_label' => empty($current_sidebets) ? 'Add New' : 'Update'
                );
            }
        }
        
        return $changes;
    }
    
    /**
     * Map minimum bet amount to radio option
     */
    private function map_minimum_bet_to_option($amount) {
        $amount = intval($amount);
        
        if ($amount <= 0) return 'N/A or Unknown';
        if ($amount == 1) return '$1';
        if ($amount == 2) return '$2';
        if ($amount == 3) return '$3';
        if ($amount == 5) return '$5';
        if ($amount > 5) return '$5 +';
        
        return '$5'; // Default fallback
    }
    
    /**
     * Map table minimum to range option
     */
    private function map_table_minimum_to_option($amount) {
        if (empty($amount)) return 'N/A or Unknown';
        
        $amount = intval(preg_replace('/[^0-9]/', '', $amount));
        
        if ($amount <= 0) return 'N/A or Unknown';
        if ($amount >= 1 && $amount <= 10) return '$1 - $10';
        if ($amount >= 11 && $amount <= 20) return '$11 - $20';
        if ($amount > 20) return '$20 +';
        
        return 'N/A or Unknown';
    }
    
    /**
     * Map sidebet text to checkbox options
     */
    private function map_sidebets_to_options($sidebet_text) {
        $sidebet_text = strtolower($sidebet_text);
        $options = array();
        
        if (strpos($sidebet_text, 'ats') !== false || strpos($sidebet_text, 'all small') !== false) {
            $options[] = 'All Small';
        }
        if (strpos($sidebet_text, 'ats') !== false || strpos($sidebet_text, 'all tall') !== false) {
            $options[] = 'All Tall';
        }
        if (strpos($sidebet_text, 'fire') !== false) {
            $options[] = 'Fire Bet';
        }
        
        if (empty($options)) {
            $options[] = 'Other';
        }
        
        return $options;
    }
    
    /**
     * Format machine types for display
     */
    private function format_machine_types($types) {
        if (empty($types)) return '(empty)';
        if ($types === 'none') return 'No Bubble Craps';
        if ($types === 'single') return 'Single Machine';
        if (is_array($types)) return implode(', ', $types);
        return $types;
    }
    
    /**
     * Format side bets for display
     */
    private function format_side_bets($bets) {
        if (empty($bets)) return '(empty)';
        if (is_array($bets)) return implode(', ', $bets);
        return $bets;
    }
    
    /**
     * Get current bubble craps status from categories
     */
    private function get_current_bubble_status($categories) {
        if (in_array('Has Bubble Craps', $categories)) {
            return 'Has Bubble Craps';
        } elseif (in_array('No Bubble Craps', $categories)) {
            return 'No Bubble Craps';
        }
        return null;
    }
    
    /**
     * Get current table craps status from categories
     */
    private function get_current_table_status($categories) {
        if (in_array('Has Craps Table', $categories)) {
            return 'Has Craps Table';
        } elseif (in_array('No Craps Table', $categories)) {
            return 'No Craps Table';
        }
        return null;
    }
}