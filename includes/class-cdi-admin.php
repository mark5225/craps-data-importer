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
        
        // Preview data
        echo '<div class="cdi-card">';
        echo '<h2>👀 ' . esc_html__('Data Preview', 'craps-data-importer') . '</h2>';
        
        $preview_data = array_slice($csv_data['data'], 0, 5);
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        foreach ($csv_data['headers'] as $header) {
            echo '<th>' . esc_html($header) . '</th>';
        }
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($preview_data as $row) {
            echo '<tr>';
            foreach ($csv_data['headers'] as $header) {
                $value = $row[$header] ?? '';
                echo '<td>' . esc_html(cdi_truncate_text($value, 30)) . '</td>';
            }
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        
        echo '<p><small>' . sprintf(
            esc_html__('Showing %d of %d total rows', 'craps-data-importer'),
            count($preview_data),
            count($csv_data['data'])
        ) . '</small></p>';
        
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
}