<?php
/**
 * Admin interface for Craps Data Importer
 */

class CDI_Admin {
    
    /**
     * Render main importer page
     */
    public function render_main_page() {
        // Check for CSV data in session
        $csv_data = get_transient('cdi_csv_data');
        $step = $_GET['step'] ?? 'upload';
        
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Craps Data Importer', 'craps-data-importer') . '</h1>';
        
        // Render CSS
        $this->render_admin_css();
        
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
        
        $this->render_admin_js();
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
        
        $this->render_admin_css();
        
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
        
        $this->render_admin_js();
    }
    
    /**
     * Render import history page
     */
    public function render_history_page() {
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Import History', 'craps-data-importer') . '</h1>';
        
        $this->render_admin_css();
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'cdi_import_history';
        
        $history_items = $wpdb->get_results(
            "SELECT * FROM $table_name ORDER BY import_date DESC LIMIT 50"
        );
        
        if (empty($history_items)) {
            echo '<div class="cdi-card">';
            echo '<p>' . esc_html__('No import history found.', 'craps-data-importer') . '</p>';
            echo '</div>';
        } else {
            $this->render_history_items($history_items);
        }
        
        echo '</div>';
    }
    
    /**
     * Render queue items table
     */
    private function render_queue_items($queue_items) {
        echo '<div class="cdi-card">';
        echo '<h2>⏳ ' . sprintf(esc_html__('Pending Review: %d Items', 'craps-data-importer'), count($queue_items)) . '</h2>';
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th width="25%">' . esc_html__('Casino Name', 'craps-data-importer') . '</th>';
        echo '<th width="15%">' . esc_html__('Reason', 'craps-data-importer') . '</th>';
        echo '<th width="25%">' . esc_html__('CSV Data', 'craps-data-importer') . '</th>';
        echo '<th width="10%">' . esc_html__('Date', 'craps-data-importer') . '</th>';
        echo '<th width="25%">' . esc_html__('Actions', 'craps-data-importer') . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';
        
        foreach ($queue_items as $item) {
            $csv_data = json_decode($item->csv_data, true);
            
            echo '<tr>';
            echo '<td><strong>' . esc_html($item->casino_name) . '</strong></td>';
            echo '<td>' . esc_html($item->reason) . '</td>';
            echo '<td>';
            
            if ($csv_data) {
                echo '<small>';
                foreach (array_slice($csv_data, 0, 3) as $key => $value) {
                    echo '<strong>' . esc_html($key) . ':</strong> ' . esc_html(cdi_truncate_text($value, 20)) . '<br>';
                }
                echo '</small>';
            }
            
            echo '</td>';
            echo '<td>' . esc_html(date('M j, Y', strtotime($item->created_at))) . '</td>';
            echo '<td>';
            
            echo '<button class="button button-small cdi-search-btn" data-queue-id="' . $item->id . '">' . esc_html__('🔍 Find Match', 'craps-data-importer') . '</button> ';
            echo '<button class="button button-small cdi-skip-btn" data-queue-id="' . $item->id . '">' . esc_html__('⏭️ Skip', 'craps-data-importer') . '</button>';
            
            echo '</td>';
            echo '</tr>';
        }
        
        echo '</tbody></table>';
        echo '</div>';
        
        // Search modal
        echo '<div id="cdi-search-modal" class="cdi-modal" style="display: none;">';
        echo '<div class="cdi-modal-content">';
        echo '<div class="cdi-modal-header">';
        echo '<h3>' . esc_html__('Find Matching Casino', 'craps-data-importer') . '</h3>';
        echo '<button class="cdi-modal-close">&times;</button>';
        echo '</div>';
        echo '<div class="cdi-modal-body">';
        echo '<p><input type="text" id="cdi-casino-search" placeholder="' . esc_attr__('Search casino name...', 'craps-data-importer') . '" class="widefat"></p>';
        echo '<div id="cdi-search-results"></div>';
        echo '<div class="cdi-modal-actions">';
        echo '<button id="cdi-confirm-match" class="button button-primary" disabled>' . esc_html__('Confirm Match', 'craps-data-importer') . '</button>';
        echo '<button id="cdi-cancel-search" class="button">' . esc_html__('Cancel', 'craps-data-importer') . '</button>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
    
    /**
     * Render history items table
     */
    private function render_history_items($history_items) {
        echo '<div class="cdi-card">';
        echo '<h2>📋 ' . esc_html__('Recent Imports', 'craps-data-importer') . '</h2>';
        
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr>';
        echo '<th width="20%">' . esc_html__('Filename', 'craps-data-importer') . '</th>';
        echo '<th width="15%">' . esc_html__('Total Rows', 'craps-data-importer') . '</th>';
        echo '<th width="15%">' . esc_html__('Processed', 'craps-data-importer') . '</th>';
        echo '<th width="15%">' . esc_html__('Updated', 'craps-data-importer') . '</th>';
        echo '<th width="15%">' . esc_html__('Queued', 'craps-data-importer') . '</th>';
        echo '<th width="20%">' . esc_html__('Date', 'craps-data-importer') . '</th>';
        echo '</tr></thead>';
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
     * Render admin CSS
     */
    private function render_admin_css() {
        ?>
        <style>
        .cdi-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px; }
        .cdi-card { background: white; border: 1px solid #ccd0d4; border-radius: 4px; padding: 20px; box-shadow: 0 1px 1px rgba(0,0,0,0.04); }
        .cdi-card h2, .cdi-card h3 { margin-top: 0; color: #1d3557; }
        .cdi-progress-bar { width: 100%; height: 20px; background: #f1f1f1; border-radius: 10px; overflow: hidden; margin: 10px 0; }
        .cdi-progress-fill { height: 100%; background: linear-gradient(90deg, #4CAF50, #45a049); transition: width 0.3s ease; }
        .cdi-modal { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100000; }
        .cdi-modal-content { position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border-radius: 8px; width: 600px; max-width: 90vw; max-height: 80vh; overflow: auto; }
        .cdi-modal-header { background: #1d3557; color: white; padding: 15px 20px; border-radius: 8px 8px 0 0; display: flex; justify-content: space-between; align-items: center; }
        .cdi-modal-header h3 { margin: 0; }
        .cdi-modal-close { background: none; border: none; color: white; font-size: 24px; cursor: pointer; }
        .cdi-modal-body { padding: 20px; }
        .cdi-modal-actions { margin-top: 20px; text-align: right; }
        .cdi-modal-actions .button { margin-left: 10px; }
        .cdi-search-result { padding: 10px; border: 1px solid #ddd; margin: 5px 0; border-radius: 4px; cursor: pointer; }
        .cdi-search-result:hover { background: #f0f0f0; }
        .cdi-search-result.selected { background: #e3f2fd; border-color: #2196f3; }
        .cdi-search-result .casino-title { font-weight: bold; color: #1d3557; }
        .cdi-search-result .casino-meta { font-size: 12px; color: #666; margin-top: 4px; }
        #threshold_value { margin-left: 10px; font-weight: bold; }
        @media (max-width: 782px) {
            .cdi-grid { grid-template-columns: 1fr; }
            .cdi-modal-content { width: 95vw; }
        }
        </style>
        <?php
    }
    
    /**
     * Render admin JavaScript
     */
    private function render_admin_js() {
        ?>
        <script>
        jQuery(document).ready(function($) {
            var selectedCasinoId = null;
            var currentQueueId = null;
            
            // Handle file upload
            $('#cdi-upload-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = new FormData();
                var fileInput = $('#csv_file')[0];
                
                if (!fileInput.files[0]) {
                    alert('Please select a CSV file');
                    return;
                }
                
                formData.append('action', 'cdi_upload_csv');
                formData.append('nonce', $('[name="cdi_nonce"]').val());
                formData.append('csv_file', fileInput.files[0]);
                
                var submitBtn = $(this).find('button[type="submit"]');
                submitBtn.prop('disabled', true).text('Uploading...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            window.location.href = '<?php echo admin_url('admin.php?page=craps-data-importer&step=preview'); ?>';
                        } else {
                            alert('Upload failed: ' + response.data.message);
                            submitBtn.prop('disabled', false).text('📤 Upload & Preview');
                        }
                    },
                    error: function() {
                        alert('Upload failed: Network error');
                        submitBtn.prop('disabled', false).text('📤 Upload & Preview');
                    }
                });
            });
            
            // Handle threshold slider
            $('#similarity_threshold').on('input', function() {
                $('#threshold_value').text($(this).val() + '%');
            });
            
            // Handle import processing
            if ($('#cdi-import-progress').length) {
                processImport();
            }
            
            function processImport() {
                var settings = {
                    action: 'cdi_process_import',
                    nonce: $('[name="cdi_nonce"]').val(),
                    auto_update: $('[name="auto_update"]').is(':checked'),
                    similarity_threshold: $('#similarity_threshold').val() || 80,
                    update_existing: $('[name="update_existing"]').is(':checked')
                };
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: settings,
                    success: function(response) {
                        if (response.success) {
                            showImportResults(response.data);
                        } else {
                            showImportError(response.data.message);
                        }
                    },
                    error: function() {
                        showImportError('Network error during import');
                    }
                });
            }
            
            function showImportResults(results) {
                $('#cdi-import-progress').hide();
                
                var html = '<div class="cdi-results-grid">';
                html += '<div class="cdi-stat-box"><h4>✅ Updated</h4><div class="cdi-stat-number">' + results.updated + '</div></div>';
                html += '<div class="cdi-stat-box"><h4>⏳ Queued</h4><div class="cdi-stat-number">' + results.queued + '</div></div>';
                html += '<div class="cdi-stat-box"><h4>⏭️ Skipped</h4><div class="cdi-stat-number">' + results.skipped + '</div></div>';
                html += '<div class="cdi-stat-box"><h4>❌ Errors</h4><div class="cdi-stat-number">' + results.errors + '</div></div>';
                html += '</div>';
                
                $('#cdi-results-content').html(html);
                $('#cdi-import-results').show();
            }
            
            function showImportError(message) {
                $('#cdi-import-progress').hide();
                $('#cdi-results-content').html('<div class="notice notice-error"><p>Import failed: ' + message + '</p></div>');
                $('#cdi-import-results').show();
            }
            
            // Handle search modal
            $('.cdi-search-btn').on('click', function() {
                currentQueueId = $(this).data('queue-id');
                $('#cdi-search-modal').show();
                $('#cdi-casino-search').focus();
            });
            
            $('.cdi-modal-close, #cdi-cancel-search').on('click', function() {
                $('#cdi-search-modal').hide();
                selectedCasinoId = null;
                $('#cdi-confirm-match').prop('disabled', true);
            });
            
            // Handle casino search
            var searchTimeout;
            $('#cdi-casino-search').on('input', function() {
                var searchTerm = $(this).val().trim();
                
                clearTimeout(searchTimeout);
                
                if (searchTerm.length < 2) {
                    $('#cdi-search-results').empty();
                    return;
                }
                
                searchTimeout = setTimeout(function() {
                    searchCasinos(searchTerm);
                }, 300);
            });
            
            function searchCasinos(searchTerm) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cdi_search_casino',
                        nonce: $('[name="cdi_nonce"]').val(),
                        search: searchTerm
                    },
                    success: function(response) {
                        if (response.success) {
                            displaySearchResults(response.data);
                        }
                    }
                });
            }
            
            function displaySearchResults(results) {
                var html = '';
                
                if (results.length === 0) {
                    html = '<p>No matching casinos found.</p>';
                } else {
                    results.forEach(function(casino) {
                        html += '<div class="cdi-search-result" data-casino-id="' + casino.ID + '">';
                        html += '<div class="casino-title">' + casino.post_title + '</div>';
                        html += '<div class="casino-meta">';
                        if (casino.location) html += 'Location: ' + casino.location + ' | ';
                        html += 'ID: ' + casino.ID;
                        html += '</div>';
                        html += '</div>';
                    });
                }
                
                $('#cdi-search-results').html(html);
            }
            
            // Handle search result selection
            $(document).on('click', '.cdi-search-result', function() {
                $('.cdi-search-result').removeClass('selected');
                $(this).addClass('selected');
                selectedCasinoId = $(this).data('casino-id');
                $('#cdi-confirm-match').prop('disabled', false);
            });
            
            // Handle match confirmation
            $('#cdi-confirm-match').on('click', function() {
                if (!selectedCasinoId || !currentQueueId) return;
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cdi_resolve_queue_item',
                        nonce: $('[name="cdi_nonce"]').val(),
                        queue_id: currentQueueId,
                        action: 'match',
                        casino_id: selectedCasinoId
                    },
                    success: function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('Failed to process match: ' + response.data.message);
                        }
                    }
                });
            });
            
            // Handle skip action
            $('.cdi-skip-btn').on('click', function() {
                var queueId = $(this).data('queue-id');
                
                if (confirm('Skip this item? It will be marked as resolved without updating any casino.')) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'cdi_resolve_queue_item',
                            nonce: $('[name="cdi_nonce"]').val(),
                            queue_id: queueId,
                            action: 'skip'
                        },
                        success: function(response) {
                            if (response.success) {
                                location.reload();
                            }
                        }
                    });
                }
            });
        });
        </script>
        <?php
    }
}