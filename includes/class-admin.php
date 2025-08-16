// Clean up if auto-clean enabled
        if (get_option('cdi_auto_clean', '1') === '1') {
            $this->file_handler->clear_upload_data();
            echo '<div class="cdi-card cdi-notice-info">';
            echo '<p>✅ ' . esc_html__('Import data automatically cleaned up (auto-clean is enabled).', 'craps-data-importer') . '</p>';
            echo '</div>';
        }
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
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'craps-data-importer'));
        }
        
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Craps Importer Settings', 'craps-data-importer') . '</h1>';
        
        echo '<form method="post" action="">';
        wp_nonce_field('cdi_save_settings');
        
        echo '<table class="form-table">';
        
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Auto-clean Data', 'craps-data-importer') . '</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="auto_clean" value="1"' . checked(get_option('cdi_auto_clean', '1'), '1', false) . '> ';
        echo esc_html__('Delete import data after processing', 'craps-data-importer') . '</label>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Notification Email', 'craps-data-importer') . '</th>';
        echo '<td>';
        echo '<input type="email" name="notification_email" value="' . esc_attr(get_option('cdi_notification_email', get_option('admin_email'))) . '" class="regular-text">';
        echo '<p class="description">' . esc_html__('Email address for import notifications', 'craps-data-importer') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Batch Size', 'craps-data-importer') . '</th>';
        echo '<td>';
        echo '<input type="number" name="batch_size" value="' . esc_attr(get_option('cdi_batch_size', 50)) . '" min="10" max="500" class="small-text">';
        echo '<p class="description">' . esc_html__('Records per batch (10-500)', 'craps-data-importer') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th scope="row">' . esc_html__('Similarity Threshold', 'craps-data-importer') . '</th>';
        echo '<td>';
        echo '<input type="number" name="similarity_threshold" value="' . esc_attr(get_option('cdi_similarity_threshold', 70)) . '" min="50" max="100" class="small-text">%';
        echo '<p class="description">' . esc_html__('Minimum similarity for automatic matching (50-100%)', 'craps-data-importer') . '</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        
        submit_button(__('Save Settings', 'craps-data-importer'), 'primary', 'save_cdi_settings');
        echo '</form>';
        
        echo '</div>';
    }
    
    /**
     * Handle settings save
     */
    private function handle_settings_save() {
        if (!wp_verify_nonce($_POST['_wpnonce'] ?? '', 'cdi_save_settings')) {
            return;
        }
        
        update_option('cdi_auto_clean', isset($_POST['auto_clean']) ? '1' : '0');
        update_option('cdi_notification_email', sanitize_email($_POST['notification_email'] ?? ''));
        update_option('cdi_batch_size', intval($_POST['batch_size'] ?? 50));
        update_option('cdi_similarity_threshold', intval($_POST['similarity_threshold'] ?? 70));
        
        echo '<div class="notice notice-success"><p>✅ ' . esc_html__('Settings saved successfully!', 'craps-data-importer') . '</p></div>';
    }
    
    /**
     * Handle clear data action
     */
    private function handle_clear_data() {
        $this->file_handler->clear_upload_data();
        $this->processor->clear_review_queue();
        
        echo '<div class="notice notice-success"><p>✅ ' . esc_html__('All import data cleared!', 'craps-data-importer') . '</p></div>';
    }
    
    /**
     * AJAX: Search casinos
     */
    public function ajax_search_casinos() {
        check_ajax_referer('cdi_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        $search = sanitize_text_field($_POST['search'] ?? '');
        $results = cdi_search_casinos($search, 20);
        
        if (empty($results)) {
            wp_send_json_success(array('html' => '<em>' . esc_html__('No casinos found', 'craps-data-importer') . '</em>'));
            return;
        }
        
        $html = '';
        foreach ($results as $casino) {
            $html .= '<div class="casino-search-result" onclick="selectCasino(' . $casino['id'] . ', \'' . esc_js($casino['title']) . '\')">';
            $html .= '<div class="casino-title">' . esc_html($casino['title']) . '</div>';
            $html .= '<div class="casino-meta">ID: ' . $casino['id'] . ' | ' . esc_html($casino['location']) . ' | ' . esc_html(ucfirst($casino['bubble_status'])) . '</div>';
            $html .= '</div>';
        }
        
        wp_send_json_success(array('html' => $html));
    }
    
    /**
     * AJAX: Preview casino
     */
    public function ajax_preview_casino() {
        check_ajax_referer('cdi_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        $casino_id = intval($_POST['casino_id'] ?? 0);
        $preview = cdi_get_casino_preview($casino_id);
        
        if (!$preview) {
            wp_send_json_error(array('message' => __('Casino not found', 'craps-data-importer')));
            return;
        }
        
        $html = '<div class="casino-preview-card">';
        $html .= '<div class="preview-title">' . esc_html($preview['title']) . '</div>';
        $html .= '<div class="preview-meta">';
        $html .= esc_html__('Location:', 'craps-data-importer') . ' ' . esc_html($preview['location']) . '<br>';
        $html .= esc_html__('Bubble Craps:', 'craps-data-importer') . ' ' . esc_html(ucfirst($preview['bubble_status'])) . '<br>';
        $html .= esc_html__('Last Modified:', 'craps-data-importer') . ' ' . esc_html($preview['last_modified']);
        $html .= '</div>';
        $html .= '</div>';
        
        wp_send_json_success(array('html' => $html));
    }
    
    /**
     * AJAX: Process review queue
     */
    public function ajax_process_review_queue() {
        check_ajax_referer('cdi_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die();
        }
        
        $item_id = intval($_POST['item_id'] ?? 0);
        $action = sanitize_text_field($_POST['action'] ?? '');
        $casino_id = intval($_POST['casino_id'] ?? 0);
        
        $result = $this->processor->process_review_item($item_id, $action, $casino_id);
        
        if ($result['success']) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
    }
    
    /**
     * Render admin CSS
     */
    private function render_admin_css() {
        ?>
        <style>
        .cdi-main-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 30px; margin: 20px 0; }
        .cdi-upload-section, .cdi-sidebar-section { }
        .cdi-card { background: #fff; border: 1px solid #c3c4c7; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; border-radius: 4px; margin-bottom: 20px; }
        .cdi-card h2, .cdi-card h3 { margin-top: 0; color: #1d3557; }
        .cdi-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(120px, 1fr)); gap: 15px; margin: 15px 0; }
        .cdi-stat-box { text-align: center; padding: 15px; border-radius: 4px; border: 1px solid #e0e0e0; }
        .cdi-stat-success { background: #d4edda; color: #155724; border-color: #c3e6cb; }
        .cdi-stat-warning { background: #fff3cd; color: #856404; border-color: #ffeaa7; }
        .cdi-stat-info { background: #cce7ff; color: #004085; border-color: #b3d7ff; }
        .cdi-stat-neutral { background: #f8f9fa; color: #495057; border-color: #dee2e6; }
        .cdi-stat-error { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
        .cdi-stat-number { font-size: 24px; font-weight: 600; line-height: 1; margin-bottom: 5px; }
        .cdi-stat-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
        .cdi-notice { padding: 15px; border-radius: 4px; margin: 15px 0; }
        .cdi-notice-info { background: #d1ecf1; border-left: 4px solid #00a0d2; color: #0c5460; }
        .cdi-notice-success { background: #d4edda; border-left: 4px solid #00a32a; color: #155724; }
        .cdi-notice-warning { background: #fff3cd; border-left: 4px solid #ffb900; color: #856404; }
        .cdi-config-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin: 20px 0; }
        .cdi-config-section { background: #f9f9f9; padding: 15px; border-radius: 4px; }
        .cdi-config-section h4 { margin: 0 0 10px 0; color: #1d3557; }
        .cdi-checkbox-label, .cdi-radio-label { display: block; margin: 8px 0; padding: 5px 0; }
        .cdi-results-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; margin: 20px 0; }
        .cdi-result-card { text-align: center; padding: 20px; border-radius: 8px; border: 2px solid; }
        .cdi-result-card .cdi-stat-number { font-size: 32px; margin-bottom: 8px; }
        .cdi-result-card .cdi-stat-label { font-size: 14px; font-weight: 700; margin-bottom: 8px; }
        .cdi-result-card p { margin: 0; font-size: 13px; opacity: 0.9; }
        
        /* Table styles */
        .cdi-review-table { margin-top: 20px; }
        .cdi-review-actions { min-width: 200px; }
        .cdi-action-select { width: 100%; max-width: 150px; margin-bottom: 10px; }
        .cdi-manual-link { margin-top: 10px; padding: 10px; background: #f9f9f9; border-radius: 4px; display: none; }
        
        /* Modal styles */
        .cdi-modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0, 0, 0, 0.7); z-index: 100000; display: none; justify-content: center; align-items: center; }
        .cdi-modal { background: white; border-radius: 8px; width: 90%; max-width: 800px; max-height: 90vh; overflow-y: auto; }
        .cdi-modal-header { background: #1d3557; color: white; padding: 15px 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; }
        .cdi-modal-header h3 { margin: 0; color: white; }
        .cdi-modal-close { background: none; border: none; color: white; font-size: 24px; cursor: pointer; padding: 0; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; }
        .cdi-modal-close:hover { background: rgba(255, 255, 255, 0.2); }
        .cdi-modal-body { padding: 20px; }
        
        /* Search results */
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
        echo '<p><a href="' . esc_url(admin_url('edit.php?post_type=at_biz_dir')) . '">🎰 ' . esc_html__('View All Casinos', 'craps-data-importer') . '</a></p>';
        echo '</div>';
    }
    
    private function render_system_info_card() {
        $system_info = cdi_get_system_info();
        
        echo '<div class="cdi-card">';
        echo '<h3>💻 ' . esc_html__('System Info', 'craps-data-importer') . '</h3>';
        echo '<table class="form-table">';
        echo '<tr><th>' . esc_html__('WordPress', 'craps-data-importer') . '</th><td>' . esc_html($system_info['wordpress_version']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('PHP', 'craps-data-importer') . '</th><td>' . esc_html($system_info['php_version']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Max Upload', 'craps-data-importer') . '</th><td>' . esc_html($system_info['max_upload_size']) . '</td></tr>';
        echo '<tr><th>' . esc_html__('Casinos', 'craps-data-importer') . '</th><td>' . esc_html($system_info['business_directory_posts']) . '</td></tr>';
        echo '</table>';
        echo '</div>';
    }
    
    private function render_data_overview($data, $stats) {
        echo '<div class="cdi-card">';
        echo '<h3>📋 ' . esc_html__('Data Overview', 'craps-data-importer') . '</h3>';
        
        echo '<div class="cdi-stats-grid">';
        echo '<div class="cdi-stat-box cdi-stat-neutral">';
        echo '<div class="cdi-stat-number">' . esc_html($stats['total_records']) . '</div>';
        echo '<div class="cdi-stat-label">' . esc_html__('Total Casinos', 'craps-data-importer') . '</div>';
        echo '</div>';
        
        echo '<div class="cdi-stat-box cdi-stat-success">';
        echo '<div class="cdi-stat-number">' . esc_html($stats['bubble_craps_count']) . '</div>';
        echo '<div class="cdi-stat-label">' . esc_html__('With Bubble Craps', 'craps-data-importer') . '</div>';
        echo '</div>';
        
        echo '<div class="cdi-stat-box cdi-stat-info">';
        echo '<div class="cdi-stat-number">' . count($stats['locations']) . '</div>';
        echo '<div class="cdi-stat-label">' . esc_html__('Locations', 'craps-data-importer') . '</div>';
        echo '</div>';
        echo '</div>';
        
        // Show recommendations
        $recommendations = cdi_get_import_recommendations($stats);
        if (!empty($recommendations)) {
            foreach ($recommendations as $rec) {
                echo '<div class="cdi-notice cdi-notice-' . esc_attr($rec['type']) . '">';
                echo '<p>' . esc_html($rec['message']) . '</p>';
                echo '</div>';
            }
        }
        
        echo '</div>';
    }
    
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
            echo '<strong>' . esc_html($sheet_name) . '</strong> (' . $sheet_count . ' ' . esc_html__('casinos', 'craps-data-importer') . ')';
            echo '</label>';
        }
        echo '</div>';
        
        // Strategy selection
        echo '<div class="cdi-config-section">';
        echo '<h4>' . esc_html__('Import Strategy:', 'craps-data-importer') . '</h4>';
        echo '<label class="cdi-radio-label">';
        echo '<input type="radio" name="import_strategy" value="updates_only" checked> ';
        echo '<strong>' . esc_html__('Updates Only', 'craps-data-importer') . '</strong> - ' . esc_html__('Only update existing casinos', 'craps-data-importer');
        echo '</label>';
        echo '<label class="cdi-radio-label">';
        echo '<input type="radio" name="import_strategy" value="create_and_update"> ';
        echo '<strong>' . esc_html__('Create & Update', 'craps-data-importer') . '</strong> - ' . esc_html__('Add new casinos and update existing', 'craps-data-importer');
        echo '</label>';
        echo '<label class="cdi-radio-label">';
        echo '<input type="radio" name="import_strategy" value="review_queue"> ';
        echo '<strong>' . esc_html__('Manual Review', 'craps-data-importer') . '</strong> - ' . esc_html__('Send all changes to review queue', 'craps-data-importer');
        echo '</label>';
        echo '</div>';
        
        // New casino handling
        echo '<div class="cdi-config-section">';
        echo '<h4>' . esc_html__('New Casino Handling:', 'craps-data-importer') . '</h4>';
        echo '<label class="cdi-radio-label">';
        echo '<input type="radio" name="new_casino_action" value="auto_create"> ';
        echo esc_html__('Auto-create new casino listings', 'craps-data-importer');
        echo '</label>';
        echo '<label class="cdi-radio-label">';
        echo '<input type="radio" name="new_casino_action" value="review_queue" checked> ';
        echo esc_html__('Send to manual review queue', 'craps-data-importer');
        echo '</label>';
        echo '<label class="cdi-radio-label">';
        echo '<input type="radio" name="new_casino_action" value="skip"> ';
        echo esc_html__('Skip new casinos entirely', 'craps-data-importer');
        echo '</label>';
        echo '</div>';
        
        echo '</div>';
        
        submit_button(__('🚀 Start Import', 'craps-data-importer'), 'primary', 'start_import');
        echo '</form>';
        echo '</div>';
    }
    
    private function render_data_preview($data) {
        echo '<div class="cdi-card">';
        echo '<h3>👀 ' . esc_html__('Quick Preview (First 10 Records)', 'craps-data-importer') . '</h3>';
        
        $preview_count = 0;
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>' . esc_html__('Casino', 'craps-data-importer') . '</th><th>' . esc_html__('Bubble Craps', 'craps-data-importer') . '</th><th>' . esc_html__('Current Status', 'craps-data-importer') . '</th><th>' . esc_html__('Action Needed', 'craps-data-importer') . '</th></tr></thead><tbody>';
        
        foreach ($data as $sheet_name => $sheet_data) {
            if ($preview_count >= 10) break;
            if (empty($sheet_data['data'])) continue;
            
            foreach ($sheet_data['data'] as $row) {
                if ($preview_count >= 10) break;
                
                $casino_name = $row[array_keys($row)[0]] ?? __('Unknown Casino', 'craps-data-importer');
                $spreadsheet_bubble = $row['Bubble Craps'] ?? __('Unknown', 'craps-data-importer');
                
                $match_result = $this->matcher->find_casino($casino_name);
                $existing_casino = $match_result['post'];
                
                if ($existing_casino) {
                    $changes_needed = $this->processor->analyze_needed_changes ? array() : array(); // Simplified for preview
                    $action_text = empty($changes_needed) ? '✅ ' . esc_html__('No changes needed', 'craps-data-importer') : '🔄 ' . esc_html__('Needs update', 'craps-data-importer');
                    $action_color = empty($changes_needed) ? '#28a745' : '#ffc107';
                } else {
                    $action_text = '🆕 ' . esc_html__('New casino', 'craps-data-importer');
                    $action_color = '#007cba';
                }
                
                echo '<tr>';
                echo '<td><strong>' . esc_html($casino_name) . '</strong></td>';
                echo '<td>' . esc_html($spreadsheet_bubble) . '</td>';
                echo '<td>' . ($existing_casino ? esc_html__('Found', 'craps-data-importer') : esc_html__('Not Found', 'craps-data-importer')) . '</td>';
                echo '<td><span style="color: ' . esc_attr($action_color) . ';">' . $action_text . '</span></td>';
                echo '</tr>';
                
                $preview_count++;
            }
        }
        
        echo '</tbody></table>';
        echo '<p><small>' . esc_html__('Showing first 10 entries. Full analysis will be done during import.', 'craps-data-importer') . '</small></p>';
        echo '</div>';
    }
    
    private function render_process_config($selected_sheets, $strategy, $new_casino_action) {
        echo '<div class="cdi-card">';
        echo '<h3>' . esc_html__('Import Configuration', 'craps-data-importer') . '</h3>';
        echo '<p><strong>' . esc_html__('Strategy:', 'craps-data-importer') . '</strong> ' . esc_html(ucwords(str_replace('_', ' ', $strategy))) . '</p>';
        echo '<p><strong>' . esc_html__('New Casinos:', 'craps-data-importer') . '</strong> ' . esc_html(ucwords(str_replace('_', ' ', $new_casino_action))) . '</p>';
        echo '<p><strong>' . esc_html__('Sheets:', 'craps-data-importer') . '</strong> ' . esc_html(implode(', ', $selected_sheets)) . '</p>';
        echo '</div>';
    }
    
    private function render_process_results($results) {
        // Display basic results summary
        echo '<div class="cdi-results-grid">';
        
        echo '<div class="cdi-result-card cdi-stat-success">';
        echo '<div class="cdi-stat-number">✅ ' . esc_html($results['updated']) . '</div>';
        echo '<div class="cdi-stat-label">' . esc_html__('Updated', 'craps-data-importer') . '</div>';
        echo '<p>' . esc_html__('Existing casinos updated with new data', 'craps-data-importer') . '</p>';
        echo '</div>';
        
        echo '<div class="cdi-result-card cdi-stat-info">';
        echo '<div class="cdi-stat-number">🆕 ' . esc_html($results['created']) . '</div>';
        echo '<div class="cdi-stat-label">' . esc_html__('Created', 'craps-data-importer') . '</div>';
        echo '<p>' . esc_html__('New casino listings created', 'craps-data-importer') . '</p>';
        echo '</div>';
        
        echo '<div class="cdi-result-card cdi-stat-warning">';
        echo '<div class="cdi-stat-number">📋 ' . esc_html($results['queued']) . '</div>';
        echo '<div class="cdi-stat-label">' . esc_html__('Queued', 'craps-data-importer') . '</div>';
        echo '<p>' . esc_html__('Items sent to review queue', 'craps-data-importer') . '</p>';
        echo '</div>';
        
        echo '<div class="cdi-result-card cdi-stat-neutral">';
        echo '<div class="cdi-stat-number">⏭️ ' . esc_html($results['skipped']) . '</div>';
        echo '<div class="cdi-stat-label">' . esc_html__('Skipped', 'craps-data-importer') . '</div>';
        echo '<p>' . esc_html__('Already up-to-date (no changes needed)', 'craps-data-importer') . '</p>';
        echo '</div>';
        
        if ($results['errors'] > 0) {
            echo '<div class="cdi-result-card cdi-stat-error">';
            echo '<div class="cdi-stat-number">❌ ' . esc_html($results['errors']) . '</div>';
            echo '<div class="cdi-stat-label">' . esc_html__('Errors', 'craps-data-importer') . '</div>';
            echo '<p>' . esc_html__('Failed to process', 'craps-data-importer') . '</p>';
            echo '</div>';
        }
        
        echo '</div>';
        
        // Summary card
        $total_processed = $results['updated'] + $results['created'] + $results['queued'] + $results['skipped'] + $results['errors'];
        echo '<div class="cdi-card">';
        echo '<h3>📊 ' . esc_html__('Import Summary', 'craps-data-importer') . '</h3>';
        echo '<p><strong>' . esc_html__('Total Processed:', 'craps-data-importer') . '</strong> ' . esc_html($total_processed) . ' ' . esc_html__('records', 'craps-data-importer') . '</p>';
        echo '<p><strong>' . esc_html__('Efficiency:', 'craps-data-importer') . '</strong> ' . esc_html($results['skipped']) . ' ' . esc_html__('records already had correct data (skipped unnecessary updates)', 'craps-data-importer') . '</p>';
        if ($total_processed > 0) {
            $success_rate = round((($results['updated'] + $results['created'] + $results['skipped']) / $total_processed) * 100, 1);
            echo '<p><strong>' . esc_html__('Success Rate:', 'craps-data-importer') . '</strong> ' . esc_html($success_rate) . '%</p>';
        }
        echo '</div>';
        
        // Detailed results if available
        if (!empty($results['details'])) {
            $this->render_detailed_results($results['details']);
        }
        
        // Action buttons
        if ($results['queued'] > 0) {
            echo '<div class="cdi-card cdi-notice-warning">';
            echo '<h3>📋 ' . esc_html__('Review Required', 'craps-data-importer') . '</h3>';
            echo '<p>' . sprintf(esc_html__('%d items require manual review and approval.', 'craps-data-importer'), $results['queued']) . '</p>';
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=craps-review-queue')) . '" class="button button-primary">' . esc_html__('Review Queue →', 'craps-data-importer') . '</a></p>';
            echo '</div>';
        }
        
        if ($results['skipped'] > 0) {
            echo '<div class="cdi-card cdi-notice-info">';
            echo '<h3>⚡ ' . esc_html__('Efficiency Report', 'craps-data-importer') . '</h3>';
            echo '<p><strong>' . sprintf(esc_html__('%d casinos were skipped', 'craps-data-importer'), $results['skipped']) . '</strong> ' . esc_html__('because their data was already accurate.', 'craps-data-importer') . '</p>';
            echo '<p>' . esc_html__('This saved processing time and avoided unnecessary database writes. Your directory data is well-maintained!', 'craps-data-importer') . '</p>';
            echo '</div>';
        }
    }
    
    private function render_detailed_results($details) {
        echo '<div class="cdi-card">';
        echo '<h3>📋 ' . esc_html__('Detailed Import Results', 'craps-data-importer') . '</h3>';
        echo '<p>' . esc_html__('Review all processing details, matching information, and changes made to each casino listing.', 'craps-data-importer') . '</p>';
        
        // Simple table view for detailed results
        echo '<div style="max-height: 400px; overflow-y: auto; border: 1px solid #e0e0e0; border-radius: 4px; margin: 15px 0;">';
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th width="25%">' . esc_html__('Casino Name', 'craps-data-importer') . '</th>';
        echo '<th width="20%">' . esc_html__('Action', 'craps-data-importer') . '</th>';
        echo '<th width="30%">' . esc_html__('Changes Made', 'craps-data-importer') . '</th>';
        echo '<th width="25%">' . esc_html__('Matching Info', 'craps-data-importer') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($details as $detail) {
            $action_badge = cdi_get_action_badge($detail['action_type']);
            
            echo '<tr>';
            echo '<td>';
            echo '<strong>' . esc_html($detail['casino']) . '</strong>';
            if (!empty($detail['spreadsheet_location'])) {
                echo '<br><small style="color: #666;">📍 ' . esc_html($detail['spreadsheet_location']) . '</small>';
            }
            echo '</td>';
            
            echo '<td>' . $action_badge . '</td>';
            
            echo '<td>';
            if (!empty($detail['changes']) && is_array($detail['changes']) && count($detail['changes']) > 0) {
                echo '<ul style="margin: 0; padding-left: 16px; font-size: 12px;">';
                foreach (array_slice($detail['changes'], 0, 3) as $change) { // Show max 3 changes
                    echo '<li>' . esc_html($change) . '</li>';
                }
                if (count($detail['changes']) > 3) {
                    echo '<li><em>+ ' . (count($detail['changes']) - 3) . ' ' . esc_html__('more changes', 'craps-data-importer') . '</em></li>';
                }
                echo '</ul>';
            } else {
                echo '<em style="color: #6c757d;">' . esc_html__('No changes', 'craps-data-importer') . '</em>';
            }
            echo '</td>';
            
            echo '<td>';
            echo '<small>' . esc_html(cdi_truncate_text($detail['matching'], 80)) . '</small>';
            echo '</td>';
            
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
        
        echo '<p><button class="button" onclick="exportResultsToCSV()">' . esc_html__('📊 Export Results to CSV', 'craps-data-importer') . '</button></p>';
        echo '</div>';
        
        // Add simple export JavaScript
        ?>
        <script>
        function exportResultsToCSV() {
            const details = <?php echo cdi_json_encode_for_js($details); ?>;
            let csv = 'Casino Name,Action Type,Action Description,Changes Made,Matching Information\n';
            
            details.forEach(detail => {
                const casino = detail.casino.replace(/"/g, '""');
                const action = detail.action.replace(/"/g, '""');
                const changes = Array.isArray(detail.changes) ? detail.changes.join(' | ').replace(/"/g, '""') : '';
                const matching = detail.matching.replace(/"/g, '""');
                const actionType = detail.action_type.replace(/"/g, '""');
                
                csv += `"${casino}","${actionType}","${action}","${changes}","${matching}"\n`;
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = window.URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'casino-import-results-' + new Date().toISOString().split('T')[0] + '.csv';
            a.click();
            window.URL.revokeObjectURL(url);
        }
        </script>
        <?php
    }
    
    private function render_review_queue_table($queue_items) {
        echo '<div class="cdi-card">';
        echo '<h3>⏳ ' . sprintf(esc_html__('Pending Review: %d Items', 'craps-data-importer'), count($queue_items)) . '</h3>';
        
        echo '<table class="wp-list-table widefat fixed striped cdi-review-table">';
        echo '<thead><tr>';
        echo '<th width="20%">' . esc_html__('Casino Name', 'craps-data-importer') . '</th>';
        echo '<th width="12%">' . esc_html__('Region', 'craps-data-importer') . '</th>';
        echo '<th width="10%">' . esc_html__('Bubble Craps', 'craps-data-importer') . '</th>';
        echo '<th width="15%">' . esc_html__('Reason', 'craps-data-importer') . '</th>';
        echo '<th width="8%">' . esc_html__('Date', 'craps-data-importer') . '</th>';
        echo '<th width="25%">' . esc_html__('Action', 'craps-data-importer') . '</th>';
        echo '</tr></thead><tbody>';
        
        foreach ($queue_items as $item) {
            $data = json_decode($item->spreadsheet_data, true);
            $bubble_craps = $data['Bubble Craps'] ?? __('Unknown', 'craps-data-importer');
            
            echo '<tr>';
            echo '<td><strong>' . esc_html($item->casino_name) . '</strong></td>';
            echo '<td>' . esc_html($item->region) . '</td>';
            echo '<td>' . esc_html($bubble_craps) . '</td>';
            echo '<td><small>' . esc_html($item->reason) . '</small></td>';
            echo '<td>' . esc_html(date('M j, Y', strtotime($item->created_at))) . '</td>';
            echo '<td class="cdi-review-actions">';
            
            // Simplified action buttons (full AJAX implementation would go here)
            echo '<select class="cdi-action-select" onchange="handleReviewAction(' . $item->id . ', this.value)">';
            echo '<option value="">' . esc_html__('Choose Action', 'craps-data-importer') . '</option>';
            
            // Check if casino exists for appropriate actions
            $match_result = $this->matcher->find_casino($item->casino_name, $item->region);
            $existing = $match_result['post'];
            if ($existing) {
                echo '<option value="approve_update">✅ ' . esc_html__('Update Existing', 'craps-data-importer') . '</option>';
            } else {
                echo '<option value="approve_create">✅ ' . esc_html__('Create New', 'craps-data-importer') . '</option>';
            }
            echo '<option value="reject">❌ ' . esc_html__('Reject', 'craps-data-importer') . '</option>';
            echo '</select>';
            
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
        
        // Add basic JavaScript for review actions
        ?>
        <script>
        function handleReviewAction(itemId, action) {
            if (!action) return;
            
            if (confirm('<?php echo esc_js__('Are you sure you want to perform this action?', 'craps-data-importer'); ?>')) {
                // Simple form submission for now
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
        </script>
        <?php
    }
}<?php
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
        
        // Handle settings save
        if (isset($_POST['save_cdi_settings'])) {
            $this->handle_settings_save();
        }
        
        // Handle clear data
        if (isset($_GET['action']) && $_GET['action'] === 'clear_data' && wp_verify_nonce($_GET['_wpnonce'], 'cdi_clear_data')) {
            $this->handle_clear_data();
        }
    }
    
    /**
     * Render main page
     */
    public function render_main_page() {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'craps-data-importer'));
        }
        
        $step = isset($_GET['step']) ? sanitize_text_field($_GET['step']) : 'upload';
        
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('🎲 Craps Data Importer', 'craps-data-importer') . '</h1>';
        echo '<p>' . esc_html__('Import data from the r/craps community spreadsheet created by u/necrochaos', 'craps-data-importer') . '</p>';
        
        switch ($step) {
            case 'upload':
                $this->render_upload_page();
                break;
            case 'analyze':
                $this->render_analysis_page();
                break;
            case 'process':
                $this->render_process_page();
                break;
            default:
                $this->render_upload_page();
        }
        
        echo '</div>';
    }
    
    /**
     * Render upload page
     */
    private function render_upload_page() {
        // Handle file upload
        if (isset($_POST['upload_excel']) && isset($_FILES['excel_file'])) {
            if (wp_verify_nonce($_POST['_wpnonce'] ?? '', 'cdi_upload_file')) {
                $upload_result = $this->file_handler->handle_upload($_FILES['excel_file']);
                if ($upload_result['success']) {
                    echo '<div class="notice notice-success"><p>✅ ' . esc_html($upload_result['message']) . '</p></div>';
                    echo '<script>window.location.href = "' . esc_url(admin_url('admin.php?page=craps-data-importer&step=analyze')) . '";</script>';
                    return;
                } else {
                    echo '<div class="notice notice-error"><p>❌ ' . esc_html($upload_result['error']) . '</p></div>';
                }
            } else {
                echo '<div class="notice notice-error"><p>❌ ' . esc_html__('Security check failed.', 'craps-data-importer') . '</p></div>';
            }
        }
        
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
        echo '<input type="file" name="excel_file" accept=".xlsx,.xls,.csv" required>';
        echo '<p class="description">';
        echo '<strong>' . esc_html__('Recommended:', 'craps-data-importer') . '</strong> ' . esc_html__('Export as CSV from Google Sheets', 'craps-data-importer') . '<br>';
        echo esc_html__('Supports: CSV, Excel (.xlsx, .xls)', 'craps-data-importer');
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
        echo '<li><strong><a href="https://docs.google.com/spreadsheets/d/1txvaruxsoprcfgHOXkNh4MSqwxz3GYIciX_8TNOigGk/edit" target="_blank">' . esc_html__('Open the Community Spreadsheet', 'craps-data-importer') . '</a></strong></li>';
        echo '<li><strong>' . esc_html__('Go to File → Download → CSV', 'craps-data-importer') . '</strong></li>';
        echo '<li><strong>' . esc_html__('Upload the CSV file here', 'craps-data-importer') . '</strong></li>';
        echo '<li><strong>' . esc_html__('Review and process the import', 'craps-data-importer') . '</strong></li>';
        echo '</ol>';
        echo '</div>';
        
        submit_button(__('📤 Upload & Analyze', 'craps-data-importer'), 'primary', 'upload_excel');
        echo '</form>';
        echo '</div>';
    }
    
    /**
     * Render current data status
     */
    private function render_current_data_status() {
        $upload_data = $this->file_handler->get_upload_data();
        $queue_items = $this->processor->get_review_queue();
        
        if ($upload_data || !empty($queue_items)) {
            echo '<div class="cdi-card">';
            echo '<h3>📋 ' . esc_html__('Current Data Status', 'craps-data-importer') . '</h3>';
            
            if ($upload_data) {
                echo '<p><strong>✅ ' . esc_html__('Excel data loaded', 'craps-data-importer') . '</strong><br>';
                if (isset($upload_data['timestamp'])) {
                    echo '<small>' . sprintf(esc_html__('Uploaded: %s', 'craps-data-importer'), date('M j, Y g:i A', strtotime($upload_data['timestamp']))) . '</small>';
                }
                echo '</p>';
                echo '<p><a href="' . esc_url(admin_url('admin.php?page=craps-data-importer&step=analyze')) . '" class="button">📊 ' . esc_html__('View Analysis', 'craps-data-importer') . '</a></p>';
            }
            
            if (!empty($queue_items)) {
                echo '<p><strong>📋 ' . sprintf(esc_html__('Review Queue: %d items', 'craps-data-importer'), count($queue_items)) . '</strong><br>';
                echo '<a href="' . esc_url(admin_url('admin.php?page=craps-review-queue')) . '" class="button">' . esc_html__('Review Items', 'craps-data-importer') . '</a></p>';
            }
            
            echo '<hr style="margin: 15px 0;">';
            echo '<p><a href="' . esc_url(wp_nonce_url(admin_url('admin.php?page=craps-data-importer&action=clear_data'), 'cdi_clear_data')) . '" class="button" onclick="return confirm(\'' . esc_js__('Clear all import data and review queue?', 'craps-data-importer') . '\')">🗑️ ' . esc_html__('Clear All Data', 'craps-data-importer') . '</a></p>';
            echo '</div>';
        }
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