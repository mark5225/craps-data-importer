<?php
/**
 * Admin Class - All admin pages and interfaces
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CDI_Admin {
    
    private $file_handler;
    private $matcher;
    private $processor;
    
    /**
     * Constructor
     */
    public function __construct($file_handler, $matcher, $processor) {
        $this->file_handler = $file_handler;
        $this->matcher = $matcher;
        $this->processor = $processor;
        
        $this->init_hooks();
    }
    
    /**
     * Initialize admin hooks
     */
    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'handle_form_submissions'));
        add_action('wp_ajax_cdi_search_casinos', array($this, 'ajax_search_casinos'));
        add_action('wp_ajax_cdi_preview_casino', array($this, 'ajax_preview_casino'));
        add_action('wp_ajax_cdi_process_review_queue', array($this, 'ajax_process_review_queue'));
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Craps Data Importer', 'craps-data-importer'),
            __('Craps Importer', 'craps-data-importer'),
            'manage_options',
            'craps-data-importer',
            array($this, 'render_main_page'),
            'dashicons-spreadsheet-alt',
            26
        );
        
        add_submenu_page(
            'craps-data-importer',
            __('Review Queue', 'craps-data-importer'),
            __('Review Queue', 'craps-data-importer'),
            'manage_options',
            'craps-review-queue',
            array($this, 'render_review_queue_page')
        );
        
        add_submenu_page(
            'craps-data-importer',
            __('Import History', 'craps-data-importer'),
            __('Import History', 'craps-data-importer'),
            'manage_options',
            'craps-import-history',
            array($this, 'render_history_page')
        );
        
        add_submenu_page(
            'craps-data-importer',
            __('Settings', 'craps-data-importer'),
            __('Settings', 'craps-data-importer'),
            'manage_options',
            'craps-importer-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Handle form submissions
     */
    public function handle_form_submissions() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        // Handle file upload
        if (isset($_POST['upload_file']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'cdi_upload_file')) {
            $this->handle_file_upload();
        }
        
        // Handle settings save
        if (isset($_POST['save_settings']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'cdi_save_settings')) {
            $this->handle_settings_save();
        }
        
        // Handle review queue actions
        if (isset($_POST['process_review_item']) && wp_verify_nonce($_POST['_wpnonce'] ?? '', 'cdi_process_review')) {
            $this->handle_review_action();
        }
        
        // Handle clear data action
        if (isset($_GET['action']) && $_GET['action'] === 'clear_data' && wp_verify_nonce($_GET['_wpnonce'] ?? '', 'cdi_clear_data')) {
            $this->handle_clear_data();
        }
    }
    
    /**
     * Handle file upload
     */
    private function handle_file_upload() {
        try {
            if (!isset($_FILES['excel_file']) || $_FILES['excel_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception(__('File upload failed.', 'craps-data-importer'));
            }
            
            $result = $this->file_handler->process_upload($_FILES['excel_file']);
            
            if ($result['success']) {
                add_action('admin_notices', function() use ($result) {
                    echo '<div class="notice notice-success"><p>✅ ' . esc_html($result['message']) . '</p></div>';
                });
                
                // Redirect to analysis step
                wp_redirect(admin_url('admin.php?page=craps-data-importer&step=analyze'));
                exit;
            } else {
                throw new Exception($result['error'] ?? __('Unknown upload error.', 'craps-data-importer'));
            }
            
        } catch (Exception $e) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>❌ ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }
    }
    
    /**
     * Handle settings save
     */
    private function handle_settings_save() {
        $settings = array(
            'cdi_auto_clean' => isset($_POST['auto_clean']) ? '1' : '0',
            'cdi_notification_email' => sanitize_email($_POST['notification_email'] ?? ''),
            'cdi_batch_size' => intval($_POST['batch_size'] ?? 50),
            'cdi_similarity_threshold' => intval($_POST['similarity_threshold'] ?? 70),
            'cdi_location_boost' => intval($_POST['location_boost'] ?? 10),
        );
        
        foreach ($settings as $option => $value) {
            update_option($option, $value);
        }
        
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>✅ ' . esc_html__('Settings saved successfully.', 'craps-data-importer') . '</p></div>';
        });
    }
    
    /**
     * Handle review queue actions
     */
    private function handle_review_action() {
        $item_id = intval($_POST['item_id'] ?? 0);
        $action = sanitize_text_field($_POST['action'] ?? '');
        
        if (!$item_id || !$action) {
            return;
        }
        
        $result = $this->processor->process_review_item($item_id, $action);
        
        if ($result['success']) {
            add_action('admin_notices', function() use ($result) {
                echo '<div class="notice notice-success"><p>✅ ' . esc_html($result['message']) . '</p></div>';
            });
        } else {
            add_action('admin_notices', function() use ($result) {
                echo '<div class="notice notice-error"><p>❌ ' . esc_html($result['error']) . '</p></div>';
            });
        }
    }
    
    /**
     * Handle clear data action
     */
    private function handle_clear_data() {
        $this->file_handler->clear_upload_data();
        $this->processor->clear_review_queue();
        
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>✅ ' . esc_html__('All import data cleared successfully.', 'craps-data-importer') . '</p></div>';
        });
        
        wp_redirect(admin_url('admin.php?page=craps-data-importer'));
        exit;
    }
    
    /**
     * Main page renderer
     */
    public function render_main_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'craps-data-importer'));
        }
        
        $step = $_GET['step'] ?? 'upload';
        
        echo '<div class="wrap">';
        echo '<h1>🎲 ' . esc_html__('Craps Data Importer', 'craps-data-importer') . '</h1>';
        
        switch ($step) {
            case 'analyze':
                $this->render_analysis_page();
                break;
            case 'process':
                $this->render_process_page();
                break;
            default:
                $this->render_upload_page();
                break;
        }
        
        echo '</div>';
    }
    
    /**
     * Render upload page
     */
    private function render_upload_page() {
        $this->render_admin_css();
        
        echo '<div class="cdi-main-grid">';
        
        // Upload section
        echo '<div class="cdi-upload-section">';
        $this->render_upload_form();
        $this->render_current_data_status();
        echo '</div>';
        
        // Sidebar section
        echo '<div class="cdi-sidebar-section">';
        $this->render_settings_card();
        $this->render_quick_links_card();
        $this->render_system_info_card();
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Render upload form
     */
    private function render_upload_form() {
        $server_info = $this->file_handler->check_server_capabilities();
        
        echo '<div class="cdi-card">';
        echo '<h2>📊 ' . esc_html__('Upload Community Spreadsheet', 'craps-data-importer') . '</h2>';
        
        echo '<form method="post" enctype="multipart/form-data">';
        wp_nonce_field('cdi_upload_file');
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Excel/CSV File', 'craps-data-importer') . '</th>';
        echo '<td>';
        echo '<input type="file" name="excel_file" accept=".csv" required>';
        echo '<p class="description">';
        echo '<strong>' . esc_html__('Recommended:', 'craps-data-importer') . '</strong> ' . esc_html__('Export as CSV from Google Sheets', 'craps-data-importer') . '<br>';
        echo esc_html__('Supports: CSV files only', 'craps-data-importer');
        echo '<br><strong>' . esc_html__('Max size:', 'craps-data-importer') . '</strong> ' . size_format($server_info['limits']['max_upload_size']);
        echo '</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        
        // Show server warnings if any
        if (!empty($server_info['warnings'])) {
            echo '<div class="cdi-notice cdi-notice-warning">';
            echo '<h4>⚠️ ' . esc_html__('Server Limitations', 'craps-data-importer') . '</h4>';
            echo '<ul>';
            foreach ($server_info['warnings'] as $warning) {
                echo '<li>' . esc_html($warning) . '</li>';
            }
            echo '</ul>';
            echo '</div>';
        }
        
        echo '<div class="cdi-notice cdi-notice-info">';
        echo '<h4>📥 ' . esc_html__('Easy Import Method', 'craps-data-importer') . '</h4>';
        echo '<ol>';
        echo '<li>' . esc_html__('Open the community spreadsheet', 'craps-data-importer') . '</li>';
        echo '<li>' . esc_html__('File → Download → CSV (.csv)', 'craps-data-importer') . '</li>';
        echo '<li>' . esc_html__('Upload the CSV file here', 'craps-data-importer') . '</li>';
        echo '</ol>';
        echo '</div>';
        
        echo '<p class="submit">';
        echo '<input type="submit" name="upload_file" class="button button-primary" value="' . esc_attr__('Upload & Analyze', 'craps-data-importer') . '">';
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }
    
    /**
     * Render current data status
     */
    private function render_current_data_status() {
        $upload_data = $this->file_handler->get_upload_data();
        $queue_items = $this->processor->get_review_queue();
        
        echo '<div class="cdi-card">';
        echo '<h3>📈 ' . esc_html__('Current Data Status', 'craps-data-importer') . '</h3>';
        
        if ($upload_data) {
            $stats = $upload_data['stats'];
            echo '<p><strong>' . esc_html__('Last Upload:', 'craps-data-importer') . '</strong> ' . esc_html($upload_data['filename']) . '</p>';
            echo '<p><strong>' . esc_html__('Rows Found:', 'craps-data-importer') . '</strong> ' . esc_html($stats['total_rows']) . '</p>';
            echo '<p><strong>' . esc_html__('Sheets:', 'craps-data-importer') . '</strong> ' . esc_html($stats['total_sheets']) . '</p>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=craps-data-importer&step=analyze')) . '" class="button button-primary">📊 ' . esc_html__('Analyze Data', 'craps-data-importer') . '</a></p>';
            
            if (!empty($queue_items)) {
                echo '<hr style="margin: 15px 0;">';
                echo '<p><strong style="color: #d63638;">⚠️ ' . 
                    sprintf(esc_html__('Review Queue: %d items', 'craps-data-importer'), count($queue_items)) . '</strong><br>';
                echo '<a href="' . esc_url(admin_url('admin.php?page=craps-review-queue')) . '" class="button">' . esc_html__('Review Items', 'craps-data-importer') . '</a></p>';
            }
            
            echo '<hr style="margin: 15px 0;">';
            echo '<p><a href="' . esc_url(wp_nonce_url(admin_url('admin.php?page=craps-data-importer&action=clear_data'), 'cdi_clear_data')) . '" class="button" onclick="return confirm(\'' . esc_js__('Clear all import data and review queue?', 'craps-data-importer') . '\')">🗑️ ' . esc_html__('Clear All Data', 'craps-data-importer') . '</a></p>';
        } else {
            echo '<p>' . esc_html__('No data uploaded yet.', 'craps-data-importer') . '</p>';
            echo '<p><em>' . esc_html__('Upload a spreadsheet file to get started.', 'craps-data-importer') . '</em></p>';
        }
        
        echo '</div>';
    }
    
    /**
     * Render analysis page
     */
    private function render_analysis_page() {
        $upload_data = $this->file_handler->get_upload_data();
        if (!$upload_data) {
            echo '<div class="notice notice-error"><p>❌ ' . esc_html__('No upload data found. Please upload a file first.', 'craps-data-importer') . '</p></div>';
            $this->render_upload_page();
            return;
        }
        
        $this->render_admin_css();
        
        echo '<h2>📊 ' . esc_html__('File Analysis', 'craps-data-importer') . '</h2>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=craps-data-importer')) . '" class="button">← ' . esc_html__('Back to Upload', 'craps-data-importer') . '</a></p>';
        
        $data = $upload_data['data'];
        $stats = $upload_data['stats'];
        
        // Data overview
        $this->render_data_overview($data, $stats);
        
        // Import configuration form
        $this->render_import_config_form($data);
        
        // Quick preview
        $this->render_data_preview($data);
    }
    
    /**
     * Render data overview
     */
    private function render_data_overview($data, $stats) {
        echo '<div class="cdi-card">';
        echo '<h3>📈 ' . esc_html__('Data Overview', 'craps-data-importer') . '</h3>';
        
        echo '<div class="cdi-stats-grid">';
        echo '<div class="cdi-stat-card cdi-stat-info">';
        echo '<div class="cdi-stat-number">📄 ' . esc_html($stats['total_sheets']) . '</div>';
        echo '<div class="cdi-stat-label">' . esc_html__('Sheets Found', 'craps-data-importer') . '</div>';
        echo '</div>';
        
        echo '<div class="cdi-stat-card cdi-stat-success">';
        echo '<div class="cdi-stat-number">📊 ' . esc_html($stats['total_rows']) . '</div>';
        echo '<div class="cdi-stat-label">' . esc_html__('Total Rows', 'craps-data-importer') . '</div>';
        echo '</div>';
        
        echo '<div class="cdi-stat-card cdi-stat-warning">';
        echo '<div class="cdi-stat-number">🎯 ' . esc_html($stats['valid_casinos']) . '</div>';
        echo '<div class="cdi-stat-label">' . esc_html__('Valid Casinos', 'craps-data-importer') . '</div>';
        echo '</div>';
        echo '</div>';
        
        // Sheet breakdown
        echo '<h4>' . esc_html__('Sheet Breakdown:', 'craps-data-importer') . '</h4>';
        foreach ($data as $sheet_name => $sheet_data) {
            if (empty($sheet_data['data'])) continue;
            
            $sheet_count = count($sheet_data['data']);
            echo '<div class="cdi-sheet-summary">';
            echo '<h5>' . esc_html($sheet_name) . ' (' . esc_html($sheet_count) . ' ' . esc_html__('rows', 'craps-data-importer') . ')</h5>';
            
            if (isset($sheet_data['headers'])) {
                echo '<p><strong>' . esc_html__('Columns:', 'craps-data-importer') . '</strong> ' . esc_html(implode(', ', array_slice($sheet_data['headers'], 0, 5))) . '</p>';
            }
            echo '</div>';
        }
        
        echo '</div>';
    }
    
    /**
     * Render import configuration form
     */
    private function render_import_config_form($data) {
        echo '<div class="cdi-card">';
        echo '<h3>🚀 ' . esc_html__('Configure Import Process', 'craps-data-importer') . '</h3>';
        
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=craps-data-importer&step=process')) . '">';
        wp_nonce_field('cdi_start_import');
        
        echo '<div class="cdi-config-grid">';
        
        // Sheet selection
        echo '<div class="cdi-config-section">';
        echo '<h4>' . esc_html__('Select Data to Import:', 'craps-data-importer') . '</h4>';
        foreach ($data as $sheet_name => $sheet_data) {
            if (empty($sheet_data['data'])) continue;
            $sheet_count = count($sheet_data['data']);
            echo '<label class="cdi-checkbox-label">';
            echo '<input type="checkbox" name="import_sheets[]" value="' . esc_attr($sheet_name) . '" checked> ';
            echo '<strong>' . esc_html($sheet_name) . '</strong> (' . $sheet_count . ' ' . esc_html__('rows', 'craps-data-importer') . ')';
            echo '</label>';
        }
        echo '</div>';
        
        // Import strategy
        echo '<div class="cdi-config-section">';
        echo '<h4>' . esc_html__('Import Strategy:', 'craps-data-importer') . '</h4>';
        echo '<label class="cdi-radio-label">';
        echo '<input type="radio" name="import_strategy" value="updates_only" checked> ';
        echo '<strong>' . esc_html__('Updates Only', 'craps-data-importer') . '</strong><br>';
        echo '<small>' . esc_html__('Only update existing casinos that match', 'craps-data-importer') . '</small>';
        echo '</label>';
        
        echo '<label class="cdi-radio-label">';
        echo '<input type="radio" name="import_strategy" value="create_and_update"> ';
        echo '<strong>' . esc_html__('Create & Update', 'craps-data-importer') . '</strong><br>';
        echo '<small>' . esc_html__('Update existing and create new casinos', 'craps-data-importer') . '</small>';
        echo '</label>';
        echo '</div>';
        
        // New casino handling
        echo '<div class="cdi-config-section">';
        echo '<h4>' . esc_html__('New Casino Handling:', 'craps-data-importer') . '</h4>';
        echo '<label class="cdi-radio-label">';
        echo '<input type="radio" name="new_casino_action" value="review_queue" checked> ';
        echo '<strong>' . esc_html__('Review Queue', 'craps-data-importer') . '</strong><br>';
        echo '<small>' . esc_html__('Add to manual review queue', 'craps-data-importer') . '</small>';
        echo '</label>';
        
        echo '<label class="cdi-radio-label">';
        echo '<input type="radio" name="new_casino_action" value="auto_create"> ';
        echo '<strong>' . esc_html__('Auto Create', 'craps-data-importer') . '</strong><br>';
        echo '<small>' . esc_html__('Automatically create new listings', 'craps-data-importer') . '</small>';
        echo '</label>';
        echo '</div>';
        
        echo '</div>';
        
        echo '<p class="submit">';
        echo '<input type="submit" name="start_import" class="button button-primary" value="' . esc_attr__('🚀 Start Import Process', 'craps-data-importer') . '">';
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }
    
    /**
     * Render data preview
     */
    private function render_data_preview($data) {
        echo '<div class="cdi-card">';
        echo '<h3>👀 ' . esc_html__('Data Preview', 'craps-data-importer') . '</h3>';
        
        foreach ($data as $sheet_name => $sheet_data) {
            if (empty($sheet_data['data'])) continue;
            
            echo '<h4>' . esc_html($sheet_name) . '</h4>';
            
            if (!empty($sheet_data['data'])) {
                echo '<div style="overflow-x: auto;">';
                echo '<table class="wp-list-table widefat striped">';
                echo '<thead><tr>';
                
                $headers = array_keys($sheet_data['data'][0]);
                foreach (array_slice($headers, 0, 6) as $header) {
                    echo '<th>' . esc_html($header) . '</th>';
                }
                echo '</tr></thead>';
                
                echo '<tbody>';
                foreach (array_slice($sheet_data['data'], 0, 3) as $row) {
                    echo '<tr>';
                    foreach (array_slice($row, 0, 6) as $value) {
                        echo '<td>' . esc_html(substr($value, 0, 50)) . (strlen($value) > 50 ? '...' : '') . '</td>';
                    }
                    echo '</tr>';
                }
                echo '</tbody></table>';
                echo '</div>';
                
                $remaining = count($sheet_data['data']) - 3;
                if ($remaining > 0) {
                    echo '<p><em>' . sprintf(esc_html__('... and %d more rows', 'craps-data-importer'), $remaining) . '</em></p>';
                }
            }
        }
        
        echo '</div>';
    }
    
    /**
     * Render process page
     */
    private function render_process_page() {
        if (!isset($_POST['start_import'])) {
            echo '<div class="notice notice-error"><p>❌ ' . esc_html__('Import not confirmed.', 'craps-data-importer') . '</p></div>';
            return;
        }
        
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'cdi_start_import')) {
            echo '<div class="notice notice-error"><p>❌ ' . esc_html__('Security check failed.', 'craps-data-importer') . '</p></div>';
            return;
        }
        
        $this->render_admin_css();
        
        $selected_sheets = $_POST['import_sheets'] ?? array();
        $import_strategy = $_POST['import_strategy'] ?? 'updates_only';
        $new_casino_action = $_POST['new_casino_action'] ?? 'review_queue';
        $upload_data = $this->file_handler->get_upload_data();
        
        if (!$upload_data || empty($selected_sheets)) {
            echo '<div class="notice notice-error"><p>❌ ' . esc_html__('No data to import.', 'craps-data-importer') . '</p></div>';
            return;
        }
        
        echo '<h2>⚙️ ' . esc_html__('Processing Community Data Import', 'craps-data-importer') . '</h2>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=craps-data-importer')) . '" class="button">← ' . esc_html__('Back to Main Page', 'craps-data-importer') . '</a></p>';
        
        // Show configuration
        $this->render_process_config($selected_sheets, $import_strategy, $new_casino_action);
        
        // Process import
        $results = $this->processor->process_import($upload_data['data'], $selected_sheets, $import_strategy, $new_casino_action);
        
        // Show results
        $this->render_process_results($results);
        
        // Clean up if auto-clean enabled
        if (get_option('cdi_auto_clean', '1') === '1') {
            $this->file_handler->clear_upload_data();
            echo '<div class="cdi-card cdi-notice-info">';
            echo '<p>✅ ' . esc_html__('Import data automatically cleaned up (auto-clean is enabled).', 'craps-data-importer') . '</p>';
            echo '</div>';
        }
    }
    
    /**
     * Render process configuration summary
     */
    private function render_process_config($selected_sheets, $import_strategy, $new_casino_action) {
        echo '<div class="cdi-card">';
        echo '<h3>⚙️ ' . esc_html__('Import Configuration', 'craps-data-importer') . '</h3>';
        
        echo '<p><strong>' . esc_html__('Selected Sheets:', 'craps-data-importer') . '</strong> ' . esc_html(implode(', ', $selected_sheets)) . '</p>';
        echo '<p><strong>' . esc_html__('Strategy:', 'craps-data-importer') . '</strong> ' . esc_html(ucwords(str_replace('_', ' ', $import_strategy))) . '</p>';
        echo '<p><strong>' . esc_html__('New Casino Action:', 'craps-data-importer') . '</strong> ' . esc_html(ucwords(str_replace('_', ' ', $new_casino_action))) . '</p>';
        echo '</div>';
    }
    
    /**
     * Render process results
     */
    private function render_process_results($results) {
        echo '<div class="cdi-card">';
        echo '<h3>📊 ' . esc_html__('Import Results', 'craps-data-importer') . '</h3>';
        
        // Results summary
        echo '<div class="cdi-results-grid">';
        
        echo '<div class="cdi-result-card" style="border-color: #00a32a; background: #f0f9ff;">';
        echo '<div class="cdi-stat-number">✅ ' . esc_html($results['updated']) . '</div>';
        echo '<div class="cdi-stat-label">' . esc_html__('Updated', 'craps-data-importer') . '</div>';
        echo '<p>' . esc_html__('Existing casinos updated', 'craps-data-importer') . '</p>';
        echo '</div>';
        
        echo '<div class="cdi-result-card" style="border-color: #0073aa; background: #e8f4f8;">';
        echo '<div class="cdi-stat-number">🆕 ' . esc_html($results['created']) . '</div>';
        echo '<div class="cdi-stat-label">' . esc_html__('Created', 'craps-data-importer') . '</div>';
        echo '<p>' . esc_html__('New casinos created', 'craps-data-importer') . '</p>';
        echo '</div>';
        
        echo '<div class="cdi-result-card" style="border-color: #ffb900; background: #fff8e1;">';
        echo '<div class="cdi-stat-number">⏳ ' . esc_html($results['queued']) . '</div>';
        echo '<div class="cdi-stat-label">' . esc_html__('Queued', 'craps-data-importer') . '</div>';
        echo '<p>' . esc_html__('Items in review queue', 'craps-data-importer') . '</p>';
        echo '</div>';
        
        echo '<div class="cdi-result-card" style="border-color: #666; background: #f1f1f1;">';
        echo '<div class="cdi-stat-number">⏭️ ' . esc_html($results['skipped']) . '</div>';
        echo '<div class="cdi-stat-label">' . esc_html__('Skipped', 'craps-data-importer') . '</div>';
        echo '<p>' . esc_html__('No changes needed', 'craps-data-importer') . '</p>';
        echo '</div>';
        
        if ($results['errors'] > 0) {
            echo '<div class="cdi-result-card" style="border-color: #d63638; background: #ffeaea;">';
            echo '<div class="cdi-stat-number">❌ ' . esc_html($results['errors']) . '</div>';
            echo '<div class="cdi-stat-label">' . esc_html__('Errors', 'craps-data-importer') . '</div>';
            echo '<p>' . esc_html__('Processing errors', 'craps-data-importer') . '</p>';
            echo '</div>';
        }
        
        echo '</div>';
        
        // Detailed results table
        if (!empty($results['details'])) {
            $this->render_results_table($results['details']);
        }
        
        // Next steps
        echo '<div class="cdi-notice cdi-notice-success">';
        echo '<h4>🎉 ' . esc_html__('Import Complete!', 'craps-data-importer') . '</h4>';
        echo '<p>' . esc_html__('Your casino data has been successfully processed.', 'craps-data-importer') . '</p>';
        
        if ($results['queued'] > 0) {
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=craps-review-queue')) . '" class="button button-primary">' . 
                 esc_html__('Review Queued Items', 'craps-data-importer') . '</a></p>';
        }
        
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=craps-import-history')) . '" class="button">' . 
             esc_html__('View Import History', 'craps-data-importer') . '</a></p>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Render detailed results table
     */
    private function render_results_table($details) {
        echo '<h4>' . esc_html__('Detailed Results', 'craps-data-importer') . '</h4>';
        echo '<div class="cdi-results-container">';
        echo '<table class="wp-list-table widefat striped cdi-results-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__('Casino', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Location', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Matching', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Action', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Changes', 'craps-data-importer') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($details as $detail) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($detail['casino']) . '</strong></td>';
            echo '<td>' . esc_html($detail['spreadsheet_location'] ?? 'N/A') . '</td>';
            echo '<td><small>' . esc_html($detail['matching']) . '</small></td>';
            echo '<td>';
            
            $action_class = 'cdi-action-' . $detail['action_type'];
            echo '<span class="cdi-action-badge ' . esc_attr($action_class) . '">';
            echo esc_html($detail['action']);
            echo '</span>';
            echo '</td>';
            
            echo '<td>';
            if (!empty($detail['changes'])) {
                echo '<details class="cdi-changes-details">';
                echo '<summary>' . sprintf(esc_html__('%d changes', 'craps-data-importer'), count($detail['changes'])) . '</summary>';
                echo '<ul class="cdi-changes-list">';
                foreach ($detail['changes'] as $change) {
                    echo '<li>' . esc_html($change) . '</li>';
                }
                echo '</ul>';
                echo '</details>';
            } else {
                echo '<em>' . esc_html__('No changes', 'craps-data-importer') . '</em>';
            }
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
    }
    
    /**
     * Render review queue page
     */
    public function render_review_queue_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'craps-data-importer'));
        }
        
        $this->render_admin_css();
        
        echo '<div class="wrap">';
        echo '<h1>📋 ' . esc_html__('Manual Review Queue', 'craps-data-importer') . '</h1>';
        echo '<p>' . esc_html__('Items requiring manual approval before processing', 'craps-data-importer') . '</p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=craps-data-importer')) . '" class="button">← ' . esc_html__('Back to Main Page', 'craps-data-importer') . '</a></p>';
        
        $queue_items = $this->processor->get_review_queue();
        
        if (empty($queue_items)) {
            echo '<div class="cdi-card">';
            echo '<div class="cdi-notice cdi-notice-info">';
            echo '<h3>📭 ' . esc_html__('No Items in Queue', 'craps-data-importer') . '</h3>';
            echo '<p>' . esc_html__('The review queue is empty. All imports have been processed.', 'craps-data-importer') . '</p>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=craps-data-importer')) . '" class="button button-primary">' . esc_html__('Import More Data', 'craps-data-importer') . '</a></p>';
            echo '</div>';
            echo '</div>';
        } else {
            $this->render_review_queue_table($queue_items);
        }
        
        echo '</div>';
    }
    
    /**
     * Render review queue table
     */
    private function render_review_queue_table($queue_items) {
        echo '<div class="cdi-card">';
        echo '<h3>🔍 ' . sprintf(esc_html__('Review Queue (%d items)', 'craps-data-importer'), count($queue_items)) . '</h3>';
        
        echo '<div class="cdi-results-container">';
        echo '<table class="wp-list-table widefat striped cdi-review-table">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__('Casino Name', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Region', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Reason', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Data Preview', 'craps-data-importer') . '</th>';
        echo '<th class="cdi-review-actions">' . esc_html__('Actions', 'craps-data-importer') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($queue_items as $item) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($item->casino_name) . '</strong></td>';
            echo '<td>' . esc_html($item->region ?? 'N/A') . '</td>';
            echo '<td>' . esc_html($item->reason ?? 'Manual review required') . '</td>';
            echo '<td>';
            
            $spreadsheet_data = json_decode($item->spreadsheet_data, true);
            if ($spreadsheet_data) {
                echo '<details>';
                echo '<summary>' . esc_html__('View Data', 'craps-data-importer') . '</summary>';
                echo '<div class="casino-preview-card">';
                foreach (array_slice($spreadsheet_data, 0, 5, true) as $key => $value) {
                    echo '<div><strong>' . esc_html($key) . ':</strong> ' . esc_html($value) . '</div>';
                }
                echo '</div>';
                echo '</details>';
            }
            echo '</td>';
            
            echo '<td class="cdi-review-actions">';
            echo '<select class="cdi-action-select" onchange="handleReviewAction(' . esc_attr($item->id) . ', this.value)">';
            echo '<option value="">' . esc_html__('Choose Action...', 'craps-data-importer') . '</option>';
            echo '<option value="approve">✅ ' . esc_html__('Approve & Create', 'craps-data-importer') . '</option>';
            
            // Get potential matches for linking
            $matches = $this->matcher->find_matches($item->casino_name, $item->region);
            if (!empty($matches)) {
                echo '<optgroup label="' . esc_attr__('Link to Existing', 'craps-data-importer') . '">';
                foreach (array_slice($matches, 0, 5) as $match) {
                    echo '<option value="link_' . esc_attr($match['id']) . '">';
                    echo '🔗 ' . esc_html($match['title']);
                    echo '</option>';
                }
                echo '</optgroup>';
            }
            echo '<option value="reject">❌ ' . esc_html__('Reject', 'craps-data-importer') . '</option>';
            echo '</select>';
            
            echo '<div class="cdi-manual-link">';
            echo '<label>' . esc_html__('Or search manually:', 'craps-data-importer') . '</label>';
            echo '<input type="text" class="casino-search" placeholder="' . esc_attr__('Search casinos...', 'craps-data-importer') . '">';
            echo '<div class="search-results"></div>';
            echo '</div>';
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
        echo '</div>';
        
        // Add JavaScript for review actions
        $this->render_review_queue_scripts();
    }
    
    /**
     * Render review queue JavaScript
     */
    private function render_review_queue_scripts() {
        ?>
        <script>
        function handleReviewAction(itemId, action) {
            if (!action) return;
            
            if (confirm('<?php echo esc_js__('Are you sure you want to perform this action?', 'craps-data-importer'); ?>')) {
                const form = document.createElement('form');
                form.method = 'post';
                form.innerHTML = 
                    '<input type="hidden" name="process_review_item" value="1">' +
                    '<input type="hidden" name="item_id" value="' + itemId + '">' +
                    '<input type="hidden" name="action" value="' + action + '">' +
                    '<?php echo wp_nonce_field('cdi_process_review', '_wpnonce', true, false); ?>';
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        // Casino search functionality
        document.addEventListener('DOMContentLoaded', function() {
            const searchInputs = document.querySelectorAll('.casino-search');
            
            searchInputs.forEach(function(input) {
                let searchTimeout;
                
                input.addEventListener('input', function() {
                    clearTimeout(searchTimeout);
                    const query = this.value;
                    const resultsDiv = this.parentElement.querySelector('.search-results');
                    
                    if (query.length < 3) {
                        resultsDiv.innerHTML = '';
                        return;
                    }
                    
                    searchTimeout = setTimeout(function() {
                        // AJAX search for casinos
                        fetch(ajaxurl, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded',
                            },
                            body: 'action=cdi_search_casinos&query=' + encodeURIComponent(query) + '&nonce=<?php echo wp_create_nonce('cdi_search_nonce'); ?>'
                        })
                        .then(response => response.json())
                        .then(data => {
                            if (data.success) {
                                resultsDiv.innerHTML = data.data.html;
                            }
                        });
                    }, 300);
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render import history page
     */
    public function render_history_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'craps-data-importer'));
        }
        
        $this->render_admin_css();
        
        echo '<div class="wrap">';
        echo '<h1>📚 ' . esc_html__('Import History', 'craps-data-importer') . '</h1>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=craps-data-importer')) . '" class="button">← ' . esc_html__('Back to Main Page', 'craps-data-importer') . '</a></p>';
        
        $logs = $this->processor->get_import_logs(null, 200);
        
        if (empty($logs)) {
            echo '<div class="cdi-card">';
            echo '<div class="cdi-notice cdi-notice-info">';
            echo '<h3>📭 ' . esc_html__('No Import History', 'craps-data-importer') . '</h3>';
            echo '<p>' . esc_html__('No imports have been performed yet.', 'craps-data-importer') . '</p>';
            echo '</div>';
            echo '</div>';
        } else {
            $this->render_history_table($logs);
        }
        
        echo '</div>';
    }
    
    /**
     * Render history table
     */
    private function render_history_table($logs) {
        echo '<div class="cdi-card">';
        echo '<h3>📊 ' . esc_html__('Recent Import Activity', 'craps-data-importer') . '</h3>';
        
        echo '<div class="cdi-results-container">';
        echo '<table class="wp-list-table widefat striped">';
        echo '<thead>';
        echo '<tr>';
        echo '<th>' . esc_html__('Date', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Casino', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Action', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Location', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Status', 'craps-data-importer') . '</th>';
        echo '<th>' . esc_html__('Changes', 'craps-data-importer') . '</th>';
        echo '</tr>';
        echo '</thead>';
        echo '<tbody>';
        
        foreach ($logs as $log) {
            echo '<tr>';
            echo '<td>' . esc_html(mysql2date('M j, Y g:i A', $log->created_at)) . '</td>';
            echo '<td><strong>' . esc_html($log->casino_name) . '</strong></td>';
            echo '<td>' . esc_html(ucwords(str_replace('_', ' ', $log->action_type))) . '</td>';
            echo '<td>' . esc_html($log->spreadsheet_location ?? 'N/A') . '</td>';
            echo '<td>';
            
            $status_class = 'cdi-action-' . $log->status;
            echo '<span class="cdi-action-badge ' . esc_attr($status_class) . '">';
            echo esc_html(ucfirst($log->status));
            echo '</span>';
            echo '</td>';
            
            echo '<td>';
            if ($log->changes_made) {
                $changes = json_decode($log->changes_made, true);
                if ($changes && is_array($changes)) {
                    echo '<details>';
                    echo '<summary>' . sprintf(esc_html__('%d changes', 'craps-data-importer'), count($changes)) . '</summary>';
                    echo '<ul style="margin: 5px 0; padding-left: 20px;">';
                    foreach ($changes as $change) {
                        echo '<li style="font-size: 11px;">' . esc_html($change) . '</li>';
                    }
                    echo '</ul>';
                    echo '</details>';
                } else {
                    echo '<small>' . esc_html($log->changes_made) . '</small>';
                }
            } else {
                echo '<em>' . esc_html__('No changes', 'craps-data-importer') . '</em>';
            }
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody>';
        echo '</table>';
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'craps-data-importer'));
        }
        
        $this->render_admin_css();
        
        echo '<div class="wrap">';
        echo '<h1>⚙️ ' . esc_html__('Craps Importer Settings', 'craps-data-importer') . '</h1>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=craps-data-importer')) . '" class="button">← ' . esc_html__('Back to Main Page', 'craps-data-importer') . '</a></p>';
        
        echo '<form method="post" action="">';
        wp_nonce_field('cdi_save_settings');
        
        echo '<div class="cdi-config-grid">';
        
        // General settings
        echo '<div class="cdi-card">';
        echo '<h3>🔧 ' . esc_html__('General Settings', 'craps-data-importer') . '</h3>';
        echo '<table class="form-table">';
        
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Auto-clean Data', 'craps-data-importer') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="auto_clean" value="1"' . checked(get_option('cdi_auto_clean', '1'), '1', false) . '> ';
        echo esc_html__('Delete import data after processing', 'craps-data-importer') . '</label>';
        echo '<p class="description">' . esc_html__('Automatically remove uploaded files and temporary data after import completion.', 'craps-data-importer') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Notification Email', 'craps-data-importer') . '</th>';
        echo '<td>';
        echo '<input type="email" name="notification_email" value="' . esc_attr(get_option('cdi_notification_email', get_option('admin_email'))) . '" class="regular-text">';
        echo '<p class="description">' . esc_html__('Email address for import completion notifications.', 'craps-data-importer') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Batch Size', 'craps-data-importer') . '</th>';
        echo '<td>';
        echo '<input type="number" name="batch_size" value="' . esc_attr(get_option('cdi_batch_size', 50)) . '" min="10" max="200" class="small-text">';
        echo '<p class="description">' . esc_html__('Number of records to process in each batch (10-200).', 'craps-data-importer') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        echo '</div>';
        
        // Matching settings
        echo '<div class="cdi-card">';
        echo '<h3>🎯 ' . esc_html__('Matching Settings', 'craps-data-importer') . '</h3>';
        echo '<table class="form-table">';
        
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Similarity Threshold', 'craps-data-importer') . '</th>';
        echo '<td>';
        echo '<input type="range" name="similarity_threshold" value="' . esc_attr(get_option('cdi_similarity_threshold', 70)) . '" min="50" max="95" class="regular-text" oninput="this.nextElementSibling.textContent = this.value + \'%\'">';
        echo '<span>' . esc_html(get_option('cdi_similarity_threshold', 70)) . '%</span>';
        echo '<p class="description">' . esc_html__('Minimum similarity percentage required for automatic matching (50-95%).', 'craps-data-importer') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Location Boost', 'craps-data-importer') . '</th>';
        echo '<td>';
        echo '<input type="number" name="location_boost" value="' . esc_attr(get_option('cdi_location_boost', 10)) . '" min="0" max="30" class="small-text">';
        echo '<span>%</span>';
        echo '<p class="description">' . esc_html__('Extra similarity boost when locations match (0-30%).', 'craps-data-importer') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        echo '</div>';
        
        echo '</div>';
        
        echo '<p class="submit">';
        echo '<input type="submit" name="save_settings" class="button button-primary" value="' . esc_attr__('Save Settings', 'craps-data-importer') . '">';
        echo '</p>';
        echo '</form>';
        echo '</div>';
    }
    
    /**
     * AJAX: Search casinos
     */
    public function ajax_search_casinos() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'cdi_search_nonce')) {
            wp_die('Security check failed');
        }
        
        $query = sanitize_text_field($_POST['query'] ?? '');
        $results = $this->matcher->search_casinos($query);
        
        $html = '';
        if (!empty($results)) {
            foreach ($results as $casino) {
                $html .= '<div class="casino-search-result" onclick="selectCasino(' . esc_attr($casino['id']) . ')">';
                $html .= '<div class="casino-title">' . esc_html($casino['title']) . '</div>';
                $html .= '<div class="casino-meta">' . esc_html($casino['location']) . '</div>';
                $html .= '</div>';
            }
        } else {
            $html = '<div class="casino-search-result"><em>' . esc_html__('No casinos found', 'craps-data-importer') . '</em></div>';
        }
        
        wp_send_json_success(array('html' => $html));
    }
    
    /**
     * AJAX: Preview casino
     */
    public function ajax_preview_casino() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'cdi_preview_nonce')) {
            wp_die('Security check failed');
        }
        
        $casino_id = intval($_POST['casino_id'] ?? 0);
        $casino_data = $this->matcher->get_casino_data($casino_id);
        
        if ($casino_data) {
            wp_send_json_success($casino_data);
        } else {
            wp_send_json_error(__('Casino not found', 'craps-data-importer'));
        }
    }
    
    /**
     * AJAX: Process review queue
     */
    public function ajax_process_review_queue() {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'cdi_review_nonce')) {
            wp_die('Security check failed');
        }
        
        $item_id = intval($_POST['item_id'] ?? 0);
        $action = sanitize_text_field($_POST['action'] ?? '');
        
        $result = $this->processor->process_review_item($item_id, $action);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result['error'] ?? __('Unknown error', 'craps-data-importer'));
        }
    }
    
    /**
     * Helper methods for rendering different sections
     */
    private function render_settings_card() {
        echo '<div class="cdi-card">';
        echo '<h3>⚙️ ' . esc_html__('Quick Settings', 'craps-data-importer') . '</h3>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=craps-importer-settings')) . '" class="button">' . esc_html__('Full Settings', 'craps-data-importer') . '</a></p>';
        echo '<p><strong>' . esc_html__('Similarity Threshold:', 'craps-data-importer') . '</strong> ' . get_option('cdi_similarity_threshold', 70) . '%</p>';
        echo '<p><strong>' . esc_html__('Auto-clean:', 'craps-data-importer') . '</strong> ' . (get_option('cdi_auto_clean', '1') === '1' ? esc_html__('Enabled', 'craps-data-importer') : esc_html__('Disabled', 'craps-data-importer')) . '</p>';
        echo '</div>';
    }
    
    private function render_quick_links_card() {
        echo '<div class="cdi-card">';
        echo '<h3>🔗 ' . esc_html__('Quick Links', 'craps-data-importer') . '</h3>';
        echo '<p><a href="https://docs.google.com/spreadsheets/d/1txvaruxsoprcfgHOXkNh4MSqwxz3GYIciX_8TNOigGk/edit" target="_blank">📊 ' . esc_html__('Community Spreadsheet', 'craps-data-importer') . '</a></p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=craps-review-queue')) . '">📋 ' . esc_html__('Review Queue', 'craps-data-importer') . '</a></p>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=craps-import-history')) . '">📚 ' . esc_html__('Import History', 'craps-data-importer') . '</a></p>';
        echo '</div>';
    }
    
    private function render_system_info_card() {
        $server_info = $this->file_handler->check_server_capabilities();
        
        echo '<div class="cdi-card">';
        echo '<h3>🖥️ ' . esc_html__('System Info', 'craps-data-importer') . '</h3>';
        echo '<p><strong>' . esc_html__('PHP Version:', 'craps-data-importer') . '</strong> ' . PHP_VERSION . '</p>';
        echo '<p><strong>' . esc_html__('WordPress:', 'craps-data-importer') . '</strong> ' . get_bloginfo('version') . '</p>';
        echo '<p><strong>' . esc_html__('Max Upload:', 'craps-data-importer') . '</strong> ' . size_format($server_info['limits']['max_upload_size']) . '</p>';
        echo '<p><strong>' . esc_html__('Memory Limit:', 'craps-data-importer') . '</strong> ' . ini_get('memory_limit') . '</p>';
        
        // Check if Directorist is active
        if (post_type_exists('at_biz_dir')) {
            echo '<p><span style="color: #00a32a;">✅ ' . esc_html__('Directorist Active', 'craps-data-importer') . '</span></p>';
        } else {
            echo '<p><span style="color: #d63638;">❌ ' . esc_html__('Directorist Required', 'craps-data-importer') . '</span></p>';
        }
        
        echo '</div>';
    }
    
    /**
     * Render admin CSS
     */
    private function render_admin_css() {
        ?>
        <style>
        .cdi-main-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px; }
        .cdi-upload-section, .cdi-sidebar-section { display: flex; flex-direction: column; gap: 20px; }
        .cdi-card { background: white; border: 1px solid #c3c4c7; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04); }
        .cdi-card h2, .cdi-card h3 { margin-top: 0; color: #1d3557; }
        .cdi-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 15px; margin: 20px 0; }
        .cdi-stat-card { text-align: center; padding: 20px; border-radius: 8px; border: 2px solid #e0e0e0; }
        .cdi-stat-number { font-size: 28px; font-weight: bold; margin-bottom: 8px; }
        .cdi-stat-label { font-size: 14px; font-weight: 600; color: #666; }
        .cdi-stat-info { border-color: #0073aa; background: #e8f4f8; }
        .cdi-stat-success { border-color: #00a32a; background: #f0f9ff; }
        .cdi-stat-warning { border-color: #ffb900; background: #fff8e1; }
        .cdi-notice { padding: 12px 16px; margin: 16px 0; border-left: 4px solid; border-radius: 0 4px 4px 0; }
        .cdi-notice-info { background: #e8f4f8; border-color: #0073aa; color: #0073aa; }
        .cdi-notice-success { background: #d4edda; border-color: #00a32a; color: #155724; }
        .cdi-notice-warning { background: #fff3cd; border-color: #ffb900; color: #856404; }
        .cdi-notice-error { background: #f8d7da; border-color: #d63638; color: #721c24; }
        .cdi-config-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0; }
        .cdi-config-section { background: #f9f9f9; padding: 15px; border-radius: 4px; }
        .cdi-config-section h4 { margin: 0 0 10px 0; color: #1d3557; }
        .cdi-checkbox-label, .cdi-radio-label { display: block; margin: 8px 0; padding: 5px 0; }
        .cdi-results-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0; }
        .cdi-result-card { text-align: center; padding: 20px; border-radius: 8px; border: 2px solid; }
        .cdi-result-card .cdi-stat-number { font-size: 32px; margin-bottom: 8px; }
        .cdi-result-card .cdi-stat-label { font-size: 14px; font-weight: 700; margin-bottom: 8px; }
        .cdi-result-card p { margin: 0; font-size: 13px; opacity: 0.9; }
        .cdi-sheet-summary { border: 1px solid #c3c4c7; padding: 15px; margin: 10px 0; border-radius: 4px; background: #fafafa; }
        .cdi-sheet-summary h5 { margin: 0 0 10px 0; color: #1d3557; }
        .cdi-results-container { max-height: 500px; overflow-y: auto; border: 1px solid #e0e0e0; border-radius: 4px; margin-bottom: 15px; background: white; }
        .cdi-results-table { margin: 0; }
        .cdi-results-table th { position: sticky; top: 0; background: #f8f9fa; z-index: 10; border-bottom: 2px solid #dee2e6; }
        .cdi-results-table td { vertical-align: top; padding: 12px 8px; line-height: 1.4; }
        .cdi-review-table { margin-top: 20px; }
        .cdi-review-actions { min-width: 200px; }
        .cdi-action-select { width: 100%; max-width: 150px; margin-bottom: 10px; }
        .cdi-manual-link { margin-top: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px; display: none; }
        .cdi-action-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .cdi-action-updated { background: #d4edda; color: #155724; }
        .cdi-action-created { background: #cce7ff; color: #004085; }
        .cdi-action-skipped { background: #e2e3e5; color: #383d41; }
        .cdi-action-queued { background: #fff3cd; color: #856404; }
        .cdi-action-error { background: #f8d7da; color: #721c24; }
        .cdi-action-completed { background: #d4edda; color: #155724; }
        .cdi-action-pending { background: #fff3cd; color: #856404; }
        .cdi-changes-details { cursor: pointer; }
        .cdi-changes-details summary { font-weight: 600; color: #1d3557; font-size: 12px; }
        .cdi-changes-details[open] summary { margin-bottom: 6px; }
        .cdi-changes-list { margin: 0; padding-left: 16px; font-size: 11px; line-height: 1.4; }
        .cdi-changes-list li { margin: 2px 0; }
        .casino-search-result { padding: 10px; border: 1px solid #ddd; margin: 5px 0; border-radius: 4px; cursor: pointer; transition: background 0.2s ease; }
        .casino-search-result:hover { background: #f0f0f0; }
        .casino-search-result .casino-title { font-weight: bold; color: #1d3557; }
        .casino-search-result .casino-meta { font-size: 11px; color: #666; margin-top: 4px; }
        .casino-preview-card { background: white; border: 1px solid #ddd; border-radius: 4px; padding: 8px; }
        .casino-preview-card .preview-title { font-weight: bold; color: #1d3557; margin-bottom: 4px; }
        .casino-preview-card .preview-meta { font-size: 11px; color: #666; }
        
        /* Responsive */
        @media (max-width: 782px) {
            .cdi-main-grid { grid-template-columns: 1fr; gap: 20px; }
            .cdi-stats-grid { grid-template-columns: repeat(2, 1fr); gap: 10px; }
            .cdi-config-grid { grid-template-columns: 1fr; }
            .cdi-results-grid { grid-template-columns: repeat(2, 1fr); gap: 15px; }
        }
        </style>
        <?php
    }
}