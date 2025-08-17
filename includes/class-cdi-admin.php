<?php
/**
 * OUTLINE Admin interface for Craps Data Importer
 * 
 * STATUS: Basic functionality works, needs enhanced preview
 * NEXT: Add detailed field-by-field analysis in render_preview_step()
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
        $step = isset($_GET['step']) ? $_GET['step'] : 'upload';
        
        echo '<div class="wrap">';
        echo '<h1>Craps Data Importer</h1>';
        
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
     * Render upload step - WORKING, DON'T CHANGE
     */
    private function render_upload_step() {
        echo '<div class="cdi-grid">';
        
        echo '<div class="cdi-card">';
        echo '<h2>📊 Upload CSV File</h2>';
        echo '<p>Upload a CSV file to import craps data. First column should be casino names.</p>';
        
        echo '<form id="cdi-upload-form" enctype="multipart/form-data">';
        wp_nonce_field('cdi_nonce', 'cdi_nonce');
        echo '<table class="form-table">';
        echo '<tr>';
        echo '<th><label for="csv_file">CSV File</label></th>';
        echo '<td>';
        echo '<input type="file" id="csv_file" name="csv_file" accept=".csv" required>';
        echo '<p class="description">Expected: Casino names in first column, Bubble Craps, WeekDay Min, etc.</p>';
        echo '</td>';
        echo '</tr>';
        echo '</table>';
        
        echo '<p><button type="submit" class="button button-primary">📤 Upload & Preview</button></p>';
        echo '</form>';
        echo '</div>';
        
        // Quick stats card - SAFE TO ADD
        echo '<div class="cdi-card">';
        echo '<h3>📈 Quick Stats</h3>';
        $casino_count = wp_count_posts('at_biz_dir');
        $casino_count = $casino_count ? $casino_count->publish : 0;
        echo '<p><strong>Total Casinos:</strong> ' . number_format($casino_count) . '</p>';
        echo '<p><a href="' . admin_url('admin.php?page=craps-review-queue') . '" class="button">View Review Queue</a></p>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Render preview step - ENHANCED WITH SMART FEATURES
     * 
     * IMPLEMENTED: Complete changes list, field filtering, action controls
     * PLACEHOLDERS: All advanced features for future implementation
     */
    private function render_preview_step($csv_data) {
        if (!$csv_data) {
            echo '<div class="notice notice-error"><p>No CSV data found. Please upload a file first.</p></div>';
            echo '<p><a href="' . admin_url('admin.php?page=craps-data-importer') . '" class="button">← Back to Upload</a></p>';
            return;
        }
        
        echo '<div class="cdi-grid">';
        
        // MAIN PREVIEW SECTION
        echo '<div class="cdi-card">';
        echo '<h2>🔍 Complete Import Analysis</h2>';
        
        // PLACEHOLDER: Smart filters and controls
        echo '<div class="cdi-smart-controls" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 4px;">';
        echo '<div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">';
        
        // Filter controls - PLACEHOLDER
        echo '<label style="margin-right: 15px;">';
        echo '<input type="checkbox" id="show-only-changes" checked> Show only rows with changes';
        echo '</label>';
        
        echo '<label style="margin-right: 15px;">';
        echo '<input type="checkbox" id="hide-irrelevant-fields" checked> Hide unused fields';
        echo '</label>';
        
        // Confidence filter - PLACEHOLDER
        echo '<select id="confidence-filter" style="margin-right: 15px;">';
        echo '<option value="all">All Matches</option>';
        echo '<option value="high">High Confidence Only (90%+)</option>';
        echo '<option value="medium">Medium+ Confidence (70%+)</option>';
        echo '<option value="low">Low Confidence Only (<70%)</option>';
        echo '</select>';
        
        // Bulk actions - PLACEHOLDER
        echo '<button type="button" class="button" id="select-all-high">Select All High Confidence</button>';
        echo '<button type="button" class="button" id="send-unmatched-review">Send Unmatched to Review</button>';
        
        echo '</div>';
        echo '</div>';
        
        // ENHANCED IMPORT SUMMARY - PLACEHOLDER FOR ANALYTICS
        echo '<div class="cdi-import-summary" style="margin-bottom: 20px;">';
        echo '<h3>📈 Import Summary Preview</h3>';
        echo '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px;">';
        
        // Calculate basic stats (WORKING VERSION)
        $total_rows = count($csv_data['data']);
        $estimated_matches = max(1, intval($total_rows * 0.8)); // Estimate 80% match rate
        $estimated_review = $total_rows - $estimated_matches;
        
        echo '<div style="text-align: center; padding: 10px; background: #d4edda; border-radius: 4px;">';
        echo '<div style="font-size: 18px; font-weight: bold; color: #155724;">' . $estimated_matches . '</div>';
        echo '<div style="font-size: 12px;">🎯 Est. Matches</div>';
        echo '</div>';
        
        echo '<div style="text-align: center; padding: 10px; background: #fff3cd; border-radius: 4px;">';
        echo '<div style="font-size: 18px; font-weight: bold; color: #856404;">' . $estimated_review . '</div>';
        echo '<div style="font-size: 12px;">⚠️ Need Review</div>';
        echo '</div>';
        
        echo '<div style="text-align: center; padding: 10px; background: #e3f2fd; border-radius: 4px;">';
        echo '<div style="font-size: 18px; font-weight: bold; color: #1976d2;">' . $total_rows . '</div>';
        echo '<div style="font-size: 12px;">📊 Total Rows</div>';
        echo '</div>';
        
        echo '<div style="text-align: center; padding: 10px; background: #f3e5f5; border-radius: 4px;">';
        echo '<div style="font-size: 18px; font-weight: bold; color: #7b1fa2;">~30s</div>';
        echo '<div style="font-size: 12px;">⏱️ Est. Time</div>';
        echo '</div>';
        
        echo '</div>';
        echo '</div>';
        
        // COMPLETE CHANGES LIST (WORKING - Shows all rows)
        echo '<div class="cdi-preview-analysis">';
        echo '<form id="cdi-import-selections">';
        
        // Process ALL rows, not just first 5
        foreach ($csv_data['data'] as $index => $row) {
            $casino_name = $this->extract_casino_name_from_row($row);
            if (empty($casino_name)) continue;
            
            echo '<div class="cdi-match-item" data-row="' . $index . '">';
            
            // ROW HEADER with action controls
            echo '<div style="display: flex; justify-content: between; align-items: center; margin-bottom: 10px;">';
            echo '<h4 style="margin: 0; flex-grow: 1;">📊 Row ' . ($index + 1) . ': ' . esc_html($casino_name) . '</h4>';
            
            // INDIVIDUAL ROW ACTION CONTROL (WORKING)
            echo '<select name="row_action[' . $index . ']" class="cdi-row-action" style="margin-left: 10px;">';
            echo '<option value="update">✅ Update</option>';
            echo '<option value="review">👁️ Manual Review</option>';
            echo '<option value="skip">⏸️ Skip</option>';
            echo '</select>';
            
            echo '</div>';
            
            // CONFIDENCE SCORING - PLACEHOLDER (Using dummy scores for now)
            $dummy_similarity = rand(65, 95); // TODO: Replace with real matching
            $confidence_class = $dummy_similarity >= 90 ? 'high' : ($dummy_similarity >= 70 ? 'medium' : 'low');
            $confidence_color = $dummy_similarity >= 90 ? '#28a745' : ($dummy_similarity >= 70 ? '#ffc107' : '#dc3545');
            
            echo '<div class="cdi-match-info">';
            echo '<span class="cdi-match-badge ' . $confidence_class . '" style="background-color: ' . $confidence_color . '; color: white; padding: 4px 8px; border-radius: 12px; font-size: 12px;">';
            
            if ($dummy_similarity >= 80) {
                echo '✅ Matched: ' . esc_html($casino_name) . ' (' . $dummy_similarity . '% similarity)';
            } else {
                echo '❓ Possible Match (' . $dummy_similarity . '% similarity)';
            }
            echo '</span>';
            
            // PLACEHOLDER: Casino ID and link (TODO: Real matching)
            echo '<div class="cdi-casino-details" style="margin-top: 5px; font-size: 13px; color: #666;">';
            echo '<strong>🆔 Casino ID:</strong> [TODO: Real ID] | ';
            echo '<strong>🔗 View Casino:</strong> <a href="#" target="_blank">[TODO: Real Link]</a>';
            echo '</div>';
            
            echo '</div>';
            
            // FILTERED FIELD CHANGES (WORKING - Only show relevant fields)
            echo '<div class="cdi-changes-preview">';
            $relevant_fields = $this->filter_relevant_fields($row);
            
            if (!empty($relevant_fields)) {
                echo '<table class="wp-list-table widefat" style="margin-top: 10px;">';
                echo '<thead><tr><th>Field</th><th>Current Value</th><th>New Value</th><th>Action</th></tr></thead>';
                echo '<tbody>';
                
                foreach ($relevant_fields as $field => $value) {
                    echo '<tr>';
                    echo '<td><strong>' . esc_html($this->get_field_display_name($field)) . '</strong></td>';
                    echo '<td style="color: #666;">[TODO: Current Value]</td>';
                    echo '<td style="color: #1976d2;"><strong>' . esc_html($value) . '</strong></td>';
                    
                    // PLACEHOLDER: Smart action determination
                    $action = empty($value) ? 'No Change' : 'Update';
                    $action_class = $action === 'Update' ? 'add' : 'no-change';
                    echo '<td><span class="cdi-action-' . $action_class . '">' . $action . '</span></td>';
                    
                    echo '</tr>';
                }
                
                echo '</tbody></table>';
            } else {
                echo '<p style="color: #666; font-style: italic;">No relevant field changes detected.</p>';
            }
            
            echo '</div>';
            
            echo '</div>'; // cdi-match-item
        }
        
        echo '</form>';
        echo '</div>'; // cdi-preview-analysis
        
        // BATCH ACTION CONTROLS (WORKING)
        echo '<div style="margin: 20px 0; padding: 15px; background: #f8f9fa; border-radius: 4px;">';
        echo '<h4>🎛️ Batch Actions</h4>';
        echo '<div style="display: flex; gap: 10px; flex-wrap: wrap;">';
        echo '<button type="button" class="button" onclick="cdiSelectAllRows(\'update\')">Select All for Update</button>';
        echo '<button type="button" class="button" onclick="cdiSelectAllRows(\'review\')">Send All to Review</button>';
        echo '<button type="button" class="button" onclick="cdiSelectAllRows(\'skip\')">Skip All</button>';
        echo '<button type="button" class="button button-secondary" onclick="cdiResetSelections()">Reset Selections</button>';
        echo '</div>';
        echo '</div>';
        
        // ACTION BUTTONS
        echo '<div style="text-align: center; margin: 20px 0;">';
        echo '<button type="button" id="cdi-process-selected" class="button button-primary button-large">🚀 Process Selected Rows</button> ';
        echo '<a href="' . admin_url('admin.php?page=craps-data-importer') . '" class="button button-large">← Back to Upload</a>';
        echo '</div>';
        
        echo '</div>'; // main card
        
        // SETTINGS PANEL 
        echo '<div class="cdi-card">';
        echo '<h3>⚙️ Import Settings</h3>';
        
        // PLACEHOLDER: Advanced settings
        echo '<div style="padding: 10px; background: #fff3cd; border-radius: 4px; margin-bottom: 15px;">';
        echo '<strong>🚧 Advanced Features Coming:</strong>';
        echo '<ul style="margin: 5px 0 0 20px; font-size: 13px;">';
        echo '<li>Field-level control toggles</li>';
        echo '<li>Rollback safety planning</li>';
        echo '<li>Data freshness detection</li>';
        echo '<li>Conflict resolution</li>';
        echo '</ul>';
        echo '</div>';
        
        echo '<form id="cdi-settings-form">';
        echo '<table class="form-table">';
        
        echo '<tr>';
        echo '<th>Similarity Threshold</th>';
        echo '<td>';
        echo '<input type="range" id="similarity_threshold" name="similarity_threshold" min="60" max="95" value="80" step="5">';
        echo '<span id="threshold_value">80%</span>';
        echo '<p class="description">Minimum similarity score for automatic matching</p>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th>Auto Update High Confidence</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="auto_update_high" value="1" checked> Automatically update 90%+ similarity matches</label>';
        echo '</td>';
        echo '</tr>';
        
        echo '<tr>';
        echo '<th>Backup Current Values</th>';
        echo '<td>';
        echo '<label><input type="checkbox" name="create_backup" value="1" checked> Create backup before overwriting (recommended)</label>';
        echo '</td>';
        echo '</tr>';
        
        echo '</table>';
        echo '</form>';
        echo '</div>';
        
        echo '</div>'; // cdi-grid
        
        // PLACEHOLDER JAVASCRIPT for batch actions
        echo '<script>
        function cdiSelectAllRows(action) {
            document.querySelectorAll(".cdi-row-action").forEach(select => {
                select.value = action;
            });
        }
        
        function cdiResetSelections() {
            document.querySelectorAll(".cdi-row-action").forEach(select => {
                select.value = "update";
            });
        }
        
        // TODO: Add smart filtering, confidence-based selection, etc.
        </script>';
    }
    
    /**
     * Filter out irrelevant CSV fields - WORKING
     * Only show fields we actually map to WordPress
     */
    private function filter_relevant_fields($row) {
        // Define fields we actually care about and map
        $relevant_field_mapping = array(
            'Bubble Craps' => 'bubble_craps_status',
            'WeekDay Min' => 'weekday_minimum',
            'WeekNight Min' => 'weeknight_minimum', 
            'WeekendMin' => 'weekend_minimum',
            'WeekendnightMin' => 'weekend_night_minimum',
            'Rewards' => 'rewards_program',
            'Sidebet' => 'side_bets'
            // Deliberately excluding: Comments, Coordinates, MaxOdds, Field Pay, Dividers, etc.
        );
        
        $filtered = array();
        foreach ($relevant_field_mapping as $csv_field => $wp_field) {
            if (isset($row[$csv_field]) && !empty(trim($row[$csv_field]))) {
                $filtered[$csv_field] = trim($row[$csv_field]);
            }
        }
        
        return $filtered;
    }
    
    /**
     * Get user-friendly field display names - WORKING
     */
    private function get_field_display_name($csv_field) {
        $display_names = array(
            'Bubble Craps' => 'Bubble Craps Status',
            'WeekDay Min' => 'Weekday Minimum',
            'WeekNight Min' => 'Weeknight Minimum',
            'WeekendMin' => 'Weekend Minimum', 
            'WeekendnightMin' => 'Weekend Night Minimum',
            'Rewards' => 'Rewards Program',
            'Sidebet' => 'Side Bets Available'
        );
        
        return isset($display_names[$csv_field]) ? $display_names[$csv_field] : $csv_field;
    }
    
    /**
     * Extract casino name from CSV row - HELPER METHOD
     * NOTE: First column is always casino name, but header varies by location
     */
    private function extract_casino_name_from_row($row) {
        // Get first non-empty value from the row (should be casino name)
        foreach ($row as $value) {
            if (!empty(trim($value))) {
                return trim($value);
            }
        }
        return 'Unknown Casino';
    }
    
    /**
     * PLACEHOLDER: Analyze field changes for a casino
     * 
     * TODO: Implement smart field mapping logic:
     * - CSV "Bubble Craps" column: "$3" = Has BC + $3 min, "Removed" = No BC
     * - Table minimums → WordPress meta fields  
     * - Categories: BC status + TC status
     * - Field mapping from documentation
     */
    private function analyze_field_changes($casino_id, $csv_row) {
        // PLACEHOLDER: This method needs to be implemented
        return array(
            'bubble_craps' => array(),
            'table_craps' => array(), 
            'categories' => array()
        );
    }
    
    /**
     * PLACEHOLDER: Get current bubble craps status
     * TODO: Check categories for "Has Bubble Craps" vs "No Bubble Craps"
     */
    private function get_current_bubble_status($categories) {
        // PLACEHOLDER: Implementation needed
        return null;
    }
    
    /**
     * PLACEHOLDER: Get current table craps status  
     * TODO: Check categories for "Has Craps Table" vs "No Craps Table"
     */
    private function get_current_table_status($categories) {
        // PLACEHOLDER: Implementation needed
        return null;
    }
    
    /**
     * Render import step - WORKING, DON'T CHANGE
     */
    private function render_import_step() {
        echo '<div class="cdi-card">';
        echo '<h2>🚀 Processing Import</h2>';
        
        echo '<div id="cdi-import-progress">';
        echo '<div class="cdi-progress-bar">';
        echo '<div class="cdi-progress-fill" style="width: 0%"></div>';
        echo '</div>';
        echo '<p id="cdi-progress-text">Preparing import...</p>';
        echo '</div>';
        
        echo '<div id="cdi-import-results" style="display: none;">';
        echo '<h3>Import Complete</h3>';
        echo '<div id="cdi-results-content"></div>';
        echo '<p>';
        echo '<a href="' . admin_url('admin.php?page=craps-review-queue') . '" class="button button-primary">Review Queue</a> ';
        echo '<a href="' . admin_url('admin.php?page=craps-data-importer') . '" class="button">New Import</a>';
        echo '</p>';
        echo '</div>';
        
        echo '</div>';
    }
    
    /**
     * Render review queue page - PLACEHOLDER
     */
    public function render_review_page() {
        echo '<div class="wrap">';
        echo '<h1>Review Queue</h1>';
        echo '<p>TODO: Add review queue functionality</p>';
        echo '</div>';
    }
    
    /**
     * Render import history page - PLACEHOLDER  
     */
    public function render_history_page() {
        echo '<div class="wrap">';
        echo '<h1>Import History</h1>';
        echo '<p>TODO: Add import history functionality</p>';
        echo '</div>';
    }
}