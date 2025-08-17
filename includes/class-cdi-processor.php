<?php
/**
 * CDI_Processor - Actually processes imports and updates WordPress database
 * This is the missing piece that makes the plugin actually work!
 */

if (!defined('ABSPATH')) {
    exit;
}

class CDI_Processor {
    
    /**
     * Handle CSV upload and parse data
     */
    public function handle_csv_upload() {
        // FIXED: Check for correct file input name from the form
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('No file uploaded or upload error: ' . cdi_get_upload_error_message($_FILES['csv_file']['error'] ?? UPLOAD_ERR_NO_FILE));
        }

        // Validate the uploaded file
        $validation = cdi_validate_uploaded_file($_FILES['csv_file']);
        if (!$validation['valid']) {
            throw new Exception($validation['message']);
        }

        $file = $_FILES['csv_file'];
        $file_path = $file['tmp_name'];
        
        // Parse CSV
        $csv_data = array();
        $headers = array();
        
        if (($handle = fopen($file_path, 'r')) !== FALSE) {
            // Get headers
            $headers = fgetcsv($handle);
            if (!$headers) {
                fclose($handle);
                throw new Exception('Unable to read CSV headers');
            }
            
            // Clean up headers (remove BOM and extra whitespace)
            $headers = array_map(function($header) {
                return trim(str_replace("\xEF\xBB\xBF", '', $header));
            }, $headers);
            
            // Get data rows
            while (($row = fgetcsv($handle)) !== FALSE) {
                if (count($row) == count($headers)) {
                    $csv_data[] = array_combine($headers, $row);
                }
            }
            fclose($handle);
        } else {
            throw new Exception('Unable to open CSV file');
        }
        
        if (empty($csv_data)) {
            throw new Exception('No data found in CSV file');
        }
        
        // Store in transient
        set_transient('cdi_csv_data', array(
            'headers' => $headers,
            'data' => $csv_data,
            'filename' => $file['name'],
            'uploaded_at' => current_time('mysql')
        ), HOUR_IN_SECONDS);
        
        cdi_log('CSV uploaded successfully: ' . $file['name'] . ' with ' . count($csv_data) . ' rows');
        
        return array(
            'redirect' => admin_url('admin.php?page=craps-data-importer&step=preview'),
            'message' => 'CSV uploaded successfully',
            'rows' => count($csv_data)
        );
    }
    
    /**
     * Prepare data for review interface
     */
    public function prepare_review_data($csv_rows) {
        $matcher = new CDI_Matcher();
        $review_data = array();
        
        // Debug: Log the first row to see what columns we have
        if (!empty($csv_rows)) {
            $first_row = $csv_rows[0];
            error_log('CDI Debug: CSV columns available: ' . implode(', ', array_keys($first_row)));
        }
        
        // Define field mapping for ALL editable fields - COMPLETE MAPPING
        $field_mapping = array(
            // Traditional Craps Table Fields
            'WeekDay Min' => array(
                'meta_key' => '_custom-radio-2',
                'label' => 'Weekday Minimum Bet',
                'directorist_field' => 'Weekday Min Bet',
                'values' => array('N/A or Unknown', '$1 - $10', '$11 - $20', '$20 +'),
                'always_show' => true
            ),
            'WeekNight Min' => array(
                'meta_key' => '_custom-radio-7',
                'label' => 'Weeknight Minimum Bet',
                'directorist_field' => 'Weeknight Min Bet',
                'values' => array('N/A or Unknown', '$1 - $10', '$11 - $20', '$20 +'),
                'always_show' => true
            ),
            'WeekendMin' => array(
                'meta_key' => '_custom-radio-8',
                'label' => 'Weekend Day Minimum Bet',
                'directorist_field' => 'Weekend Day Min Bet',
                'values' => array('N/A or Unknown', '$1 - $10', '$11 - $20', '$20 +'),
                'always_show' => true
            ),
            'WeekendnightMin' => array(
                'meta_key' => '_custom-radio-9',
                'label' => 'Weekend Night Minimum Bet',
                'directorist_field' => 'Weekend Night Min Bet',
                'values' => array('N/A or Unknown', '$1 - $10', '$11 - $20', '$20 +'),
                'always_show' => true
            ),
            'Sidebet' => array(
                'meta_key' => '_custom-checkbox-2',
                'label' => 'Craps Table Sidebets',
                'directorist_field' => 'Craps Sidebets',
                'values' => array('Fire Bet', 'All Small', 'All Tall', 'Make \'Em All', 'Sharp Shooter', 'Repeater Bets', 'Other'),
                'always_show' => true
            ),
            'Rewards' => array(
                'meta_key' => '_custom-radio-5',
                'label' => 'Rewards Program',
                'directorist_field' => 'Rewards Affiliation',
                'always_show' => true
            ),
            
            // Bubble Craps Fields
            'Bubble Craps' => array(
                'meta_key' => '_custom-radio-3',
                'label' => 'Bubble Craps Minimum Bet',
                'directorist_field' => 'Bubble Craps Min Bet',
                'values' => array('N/A or Unknown', '$1', '$2', '$3', '$5', '$5 +'),
                'category_change' => true,
                'always_show' => true
            ),
            
            // Always Show Fields (even if not in CSV)
            '_number_of_tables' => array(
                'meta_key' => '_custom-radio-4',
                'label' => 'Number of Craps Tables',
                'directorist_field' => '# of Craps Tables',
                'values' => array('1 Table', '2 Tables', '3 Tables', '4 or more Tables', 'No Craps Tables'),
                'always_show' => true,
                'csv_column' => null  // Not in CSV, but always show
            ),
            '_bubble_craps_types' => array(
                'meta_key' => '_custom-checkbox',
                'label' => 'Bubble Craps Machine Types',
                'directorist_field' => 'Machine Types',
                'values' => array('single', 'stadium', 'casino wizard', 'none', 'crapless', 'rtw'),
                'always_show' => true,
                'csv_column' => null
            ),
            '_bubble_craps_rewards' => array(
                'meta_key' => '_custom-radio',
                'label' => 'Bubble Craps Player Rewards',
                'directorist_field' => 'Player Rewards',
                'values' => array('Yes', 'No', 'Unknown'),
                'always_show' => true,
                'csv_column' => null
            )
        );
        
        foreach ($csv_rows as $index => $row) {
            $casino_name = $this->extract_casino_name($row);
            $casino_id = null;
            $changes = array();
            $mapped_data = array();
            
            // Debug: Log what casino name we extracted
            error_log("CDI Debug: Row {$index} - Extracted casino name: '{$casino_name}'");
            
            if (!empty($casino_name)) {
                // Try to find existing casino 
                $casino_id = $matcher->find_casino_by_name($casino_name);
                
                // Debug: Log matching result
                error_log("CDI Debug: Casino '{$casino_name}' " . ($casino_id ? "matched to ID {$casino_id}" : "not matched"));
                
                // Process ALL editable fields (both CSV and always-show fields)
                foreach ($field_mapping as $csv_column => $field_info) {
                    $has_csv_data = !is_null($field_info['csv_column'] ?? $csv_column) && 
                                   isset($row[$field_info['csv_column'] ?? $csv_column]) && 
                                   !empty(trim($row[$field_info['csv_column'] ?? $csv_column]));
                    
                    $new_value = null;
                    $should_process = false;
                    
                    // Handle CSV fields with data
                    if ($has_csv_data) {
                        $csv_col = $field_info['csv_column'] ?? $csv_column;
                        
                        // Special handling for Bubble Craps
                        if ($csv_col === 'Bubble Craps') {
                            $bubble_result = $this->process_bubble_craps_field($row, $casino_id);
                            if ($bubble_result['process']) {
                                $new_value = $bubble_result['min_bet_value'];
                                $should_process = true;
                                
                                // Add category change if needed
                                if ($bubble_result['category_change_needed']) {
                                    $changes['category_change'] = array(
                                        'label' => 'Bubble Craps Status',
                                        'current' => 'No Bubble Craps',
                                        'new' => 'Has Bubble Craps',
                                        'type' => 'update'
                                    );
                                }
                                
                                // Add machine type change 
                                if ($casino_id) {
                                    $current_types = get_post_meta($casino_id, '_custom-checkbox', true);
                                    if (empty($current_types) || (is_array($current_types) && in_array('none', $current_types))) {
                                        $changes['_custom-checkbox'] = array(
                                            'label' => 'Bubble Craps Machine Types → Machine Types',
                                            'current' => 'Not specified',
                                            'new' => 'Single Machine',
                                            'type' => 'update'
                                        );
                                    }
                                }
                            }
                        } else {
                            // Normal field processing
                            $new_value = $this->clean_field_value($csv_col, trim($row[$csv_col]));
                            $should_process = true;
                        }
                    }
                    
                    // Show field in interface (always show or if has CSV data)
                    if ($field_info['always_show'] || $has_csv_data) {
                        $display_label = $field_info['label'] . ' → ' . $field_info['directorist_field'];
                        
                        if ($casino_id) {
                            // Get current value
                            $current_value = get_post_meta($casino_id, $field_info['meta_key'], true);
                            $current_display = $this->format_current_value($current_value, $field_info);
                            
                            if ($should_process && $new_value !== null) {
                                // Show as change if we have new data
                                $mapped_data[$display_label] = $new_value;
                                
                                $cleaned_current = $this->clean_field_value($csv_column, $current_value);
                                if ($cleaned_current !== $new_value) {
                                    $change_type = empty($current_value) ? 'add' : 'update';
                                    $changes[$field_info['meta_key']] = array(
                                        'label' => $display_label,
                                        'current' => $current_display,
                                        'new' => $new_value,
                                        'type' => $change_type
                                    );
                                }
                            } else {
                                // Show current value with no change
                                $mapped_data[$display_label] = $current_display . ' (no change)';
                            }
                        } else {
                            // New casino - show what we'll set
                            if ($should_process && $new_value !== null) {
                                $mapped_data[$display_label] = $new_value;
                            } else {
                                $mapped_data[$display_label] = 'Not specified (no CSV data)';
                            }
                        }
                    }
                }
                
                // Debug: Log mapped data
                error_log("CDI Debug: Mapped data for '{$casino_name}': " . print_r($mapped_data, true));
            }
            
            $review_data[] = array(
                'casino_name' => $casino_name,
                'casino_id' => $casino_id,
                'changes' => $changes,
                'mapped_data' => $mapped_data,
                'raw_row' => $row
            );
        }
        
        return $review_data;
    }
    
    /**
     * Determine categories and tags based on CSV data with smart logic
     */
    private function determine_categories_and_tags($row, $casino_id) {
        $category_changes = array();
        $tag_changes = array();
        
        // Analyze bubble craps data
        $bubble_craps_data = isset($row['Bubble Craps']) ? trim($row['Bubble Craps']) : '';
        $has_bubble_data = !empty($bubble_craps_data);
        
        // Analyze table craps data
        $table_fields = array('WeekDay Min', 'WeekNight Min', 'WeekendMin', 'WeekendnightMin', 'Sidebet');
        $has_table_data = false;
        foreach ($table_fields as $field) {
            if (isset($row[$field]) && !empty(trim($row[$field]))) {
                $has_table_data = true;
                break;
            }
        }
        
        // Current categories if existing casino
        $current_categories = array();
        if ($casino_id) {
            $current_category_objects = wp_get_post_terms($casino_id, 'at_biz_dir-categories');
            foreach ($current_category_objects as $cat) {
                $current_categories[] = $cat->term_id;
            }
        }
        
        // BUBBLE CRAPS LOGIC
        if ($has_bubble_data) {
            $is_numeric = is_numeric($bubble_craps_data);
            $is_yes = in_array(strtolower($bubble_craps_data), array('yes', 'y', '1', 'true'));
            $is_no = in_array(strtolower($bubble_craps_data), array('no', 'n', '0', 'false'));
            
            if ($is_numeric && $bubble_craps_data > 0) {
                // Has bubble craps with minimum bet
                if (!in_array(252, $current_categories)) {
                    $category_changes[] = array(
                        'type' => 'add',
                        'category_id' => 252,
                        'category_name' => 'Has Bubble Craps',
                        'reason' => 'CSV has bubble craps minimum: 
    private function process_bubble_craps_field($row, $casino_id) {
        $bubble_value = isset($row['Bubble Craps']) ? trim($row['Bubble Craps']) : '';
        
        // Check if it's a number (minimum bet)
        if (is_numeric($bubble_value) && $bubble_value > 0) {
            return array(
                'process' => true,
                'min_bet_value' => $this->convert_to_bubble_min_bet($bubble_value),
                'category_change_needed' => $casino_id ? $this->needs_bubble_craps_category($casino_id) : true,
                'bubble_craps_type' => 'single'  // Default to single machine
            );
        }
        
        // Check if it's Yes/No
        $is_yes = in_array(strtolower($bubble_value), array('yes', 'y', '1', 'true'));
        $is_no = in_array(strtolower($bubble_value), array('no', 'n', '0', 'false'));
        
        if ($is_yes) {
            // Has bubble craps but no specific minimum - don't override existing if entry exists
            if ($casino_id) {
                $existing_min = get_post_meta($casino_id, '_custom-radio-3', true);
                if (!empty($existing_min) && $existing_min !== 'N/A or Unknown') {
                    // Don't override existing minimum
                    return array('process' => false);
                }
            }
            
            return array(
                'process' => true,
                'min_bet_value' => 'N/A or Unknown',
                'category_change_needed' => $casino_id ? $this->needs_bubble_craps_category($casino_id) : true,
                'bubble_craps_type' => 'single'
            );
        }
        
        // If No or unknown, don't process
        return array('process' => false);
    }
    
    /**
     * Check if casino needs Bubble Craps category
     */
    private function needs_bubble_craps_category($casino_id) {
        $categories = wp_get_post_terms($casino_id, 'at_biz_dir-categories', array('fields' => 'names'));
        
        if (is_array($categories)) {
            // Check if already has "Has Bubble Craps" category
            foreach ($categories as $category) {
                if (stripos($category, 'bubble') !== false || stripos($category, 'has bubble') !== false) {
                    return false; // Already has bubble craps category
                }
            }
        }
        
        return true; // Needs bubble craps category
    }
    
    /**
     * Format current value for display
     */
    private function format_current_value($current_value, $field_info) {
        if (empty($current_value)) {
            return 'Not set';
        }
        
        // Handle arrays (checkboxes)
        if (is_array($current_value)) {
            if (empty($current_value)) {
                return 'Not set';
            }
            return implode(', ', $current_value);
        }
        
        return $current_value;
    }
    private function extract_location_hint($row) {
        $location_columns = array('Location', 'State', 'Region', 'Area', 'City');
        
        foreach ($location_columns as $column) {
            if (isset($row[$column]) && !empty(trim($row[$column]))) {
                return trim($row[$column]);
            }
        }
        
        return '';
    }
    
    /**
     * Clean field values for comparison and convert to Directorist format
     */
    private function clean_field_value($field_type, $value) {
        // Handle arrays - convert to string or return empty
        if (is_array($value)) {
            if (empty($value)) {
                return '';
            }
            // Convert array to comma-separated string
            $value = implode(', ', array_filter($value));
        }
        
        if (empty($value)) {
            return '';
        }
        
        $value = trim($value);
        
        // Convert CSV values to Directorist format
        switch ($field_type) {
            case 'WeekDay Min':
            case 'WeekNight Min':
            case 'WeekendMin':
            case 'WeekendnightMin':
                // Convert numeric values to Directorist ranges
                return $this->convert_to_min_bet_range($value);
                
            case 'Sidebet':
                // Convert sidebet info to checkbox array
                return $this->convert_to_sidebet_array($value);
                
            default:
                return sanitize_text_field($value);
        }
    }
    
    /**
     * Convert numeric minimum bet to Directorist range
     */
    private function convert_to_min_bet_range($value) {
        $numeric = intval(preg_replace('/[^\d]/', '', $value));
        
        if ($numeric == 0 || empty($value)) {
            return 'N/A or Unknown';
        } elseif ($numeric <= 10) {
            return '$1 - $10';
        } elseif ($numeric <= 20) {
            return '$11 - $20';
        } else {
            return '$20 +';
        }
    }
    
    /**
     * Convert to Bubble Craps minimum bet format
     */
    private function convert_to_bubble_min_bet($value) {
        $numeric = intval(preg_replace('/[^\d]/', '', $value));
        
        if ($numeric == 0 || empty($value)) {
            return 'N/A or Unknown';
        } elseif ($numeric == 1) {
            return '$1';
        } elseif ($numeric == 2) {
            return '$2';
        } elseif ($numeric == 3) {
            return '$3';
        } elseif ($numeric == 5) {
            return '$5';
        } else {
            return '$5 +';
        }
    }
    
    /**
     * Convert to bubble craps type
     */
    private function convert_to_bubble_craps_type($value) {
        $value = strtolower(trim($value));
        
        if (in_array($value, array('yes', 'y', '1', 'true', 'single'))) {
            return array('single');
        } elseif (in_array($value, array('stadium'))) {
            return array('stadium');
        } elseif (in_array($value, array('no', 'n', '0', 'false', 'none'))) {
            return array('none');
        }
        
        return array('none');
    }
    
    /**
     * Convert RTW to type
     */
    private function convert_to_rtw_type($value) {
        $value = strtolower(trim($value));
        
        if (in_array($value, array('yes', 'y', '1', 'true'))) {
            return array('rtw');
        }
        
        return array();
    }
    
    /**
     * Convert to Yes/No
     */
    private function convert_to_yes_no($value) {
        $value = strtolower(trim($value));
        
        if (in_array($value, array('yes', 'y', '1', 'true'))) {
            return 'Yes';
        } elseif (in_array($value, array('no', 'n', '0', 'false'))) {
            return 'No';
        }
        
        return 'No';
    }
    
    /**
     * Convert sidebet info to array
     */
    private function convert_to_sidebet_array($value) {
        $value = strtolower(trim($value));
        
        if (in_array($value, array('none', 'no', '0', 'false', ''))) {
            return array();
        }
        
        // Try to identify specific sidebets
        $sidebets = array();
        if (stripos($value, 'fire') !== false) $sidebets[] = 'Fire Bet';
        if (stripos($value, 'small') !== false) $sidebets[] = 'All Small';
        if (stripos($value, 'tall') !== false) $sidebets[] = 'All Tall';
        if (stripos($value, 'make') !== false || stripos($value, 'all') !== false) $sidebets[] = 'Make \'Em All';
        
        if (empty($sidebets)) {
            $sidebets[] = 'Other';
        }
        
        return $sidebets;
    }
    
    /**
     * Process the import based on form selections
     */
    public function process_import() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cdi_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        // Get CSV data from transient
        $csv_data = get_transient('cdi_csv_data');
        if (!$csv_data) {
            wp_send_json_error('No CSV data found. Please upload a file first.');
            return;
        }
        
        // Get selected rows to process
        $selected_rows = isset($_POST['process_row']) ? $_POST['process_row'] : array();
        
        if (empty($selected_rows)) {
            wp_send_json_error('No rows selected for processing.');
            return;
        }
        
        try {
            // Filter CSV data to only selected rows
            $filtered_data = array();
            foreach ($selected_rows as $row_index) {
                if (isset($csv_data['data'][$row_index])) {
                    $filtered_data[] = $csv_data['data'][$row_index];
                }
            }
            
            $results = $this->process_csv_data($filtered_data, array());
            
            // Log the import
            $this->log_import_results($csv_data['filename'], $results);
            
            wp_send_json_success(array(
                'message' => 'Import completed successfully',
                'results' => $results,
                'redirect' => admin_url('admin.php?page=craps-data-importer&step=import')
            ));
            
        } catch (Exception $e) {
            cdi_log('Import failed: ' . $e->getMessage());
            wp_send_json_error('Import failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Process CSV data and update directory listings
     */
    private function process_csv_data($csv_rows, $row_actions = array()) {
        $matcher = new CDI_Matcher();
        $results = array(
            'processed' => 0,
            'updated' => 0,
            'created' => 0,
            'skipped' => 0,
            'errors' => array()
        );
        
        foreach ($csv_rows as $index => $row) {
            try {
                $results['processed']++;
                
                // Get casino name from the row
                $casino_name = $this->extract_casino_name($row);
                
                if (empty($casino_name)) {
                    $results['skipped']++;
                    $results['errors'][] = "Row {$index}: No casino name found";
                    continue;
                }
                
                // Try to find existing casino
                $casino_id = $matcher->find_casino_by_name($casino_name);
                
                if ($casino_id) {
                    // Update existing casino
                    $updated_fields = $matcher->update_casino_data($casino_id, $row);
                    
                    if (!empty($updated_fields)) {
                        $results['updated']++;
                        cdi_log("Updated casino {$casino_id} ({$casino_name}) - fields: " . implode(', ', $updated_fields));
                    } else {
                        $results['skipped']++;
                        cdi_log("No changes needed for casino {$casino_id} ({$casino_name})");
                    }
                } else {
                    // Create new casino entry if auto-create is enabled
                    if (cdi_get_option('auto_create', 0)) {
                        $new_casino_id = $this->create_new_casino($row);
                        if ($new_casino_id) {
                            $results['created']++;
                            cdi_log("Created new casino {$new_casino_id} ({$casino_name})");
                        } else {
                            $results['errors'][] = "Row {$index}: Failed to create casino for '{$casino_name}'";
                        }
                    } else {
                        $results['skipped']++;
                        $results['errors'][] = "Row {$index}: No matching casino found for '{$casino_name}'";
                    }
                }
                
            } catch (Exception $e) {
                $results['errors'][] = "Row {$index}: " . $e->getMessage();
                cdi_log("Error processing row {$index}: " . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Extract casino name from CSV row
     */
    private function extract_casino_name($row) {
        // Try different possible column names for casino (more comprehensive list)
        $possible_names = array(
            'Strip Casino',           // For LV Strip CSV
            'Downtown Casino',        // For Downtown CSV  
            'Casino',
            'Casino Name',
            'Name',
            'Property',
            'Location',
            'Venue',
            'Site',
            'Property Name',
            'Business Name'
        );
        
        foreach ($possible_names as $column_name) {
            if (isset($row[$column_name]) && !empty(trim($row[$column_name]))) {
                return trim($row[$column_name]);
            }
        }
        
        // If no casino name column found, try the first column that has actual data
        foreach ($row as $key => $value) {
            if (!empty(trim($value)) && !is_numeric($value)) {
                return trim($value);
            }
        }
        
        return '';
    }
    
    /**
     * Create new casino listing
     */
    private function create_new_casino($row) {
        $casino_name = $this->extract_casino_name($row);
        
        if (empty($casino_name)) {
            return false;
        }
        
        // Create new post
        $post_data = array(
            'post_title' => $casino_name,
            'post_type' => 'at_biz_dir',
            'post_status' => 'publish',
            'post_content' => '',
            'meta_input' => array(
                '_created_by_cdi' => 1,
                '_created_date' => current_time('mysql')
            )
        );
        
        $casino_id = wp_insert_post($post_data);
        
        if ($casino_id && !is_wp_error($casino_id)) {
            // Update with CSV data
            $matcher = new CDI_Matcher();
            $matcher->update_casino_data($casino_id, $row);
            
            return $casino_id;
        }
        
        return false;
    }
    
    /**
     * Log import results
     */
    private function log_import_results($filename, $results) {
        $log_entry = array(
            'filename' => $filename,
            'timestamp' => current_time('mysql'),
            'results' => $results
        );
        
        // Get existing log
        $import_log = get_option('cdi_import_log', array());
        
        // Add new entry
        array_unshift($import_log, $log_entry);
        
        // Keep only last 50 entries
        $import_log = array_slice($import_log, 0, 50);
        
        // Save log
        update_option('cdi_import_log', $import_log);
        
        cdi_log("Import completed - Processed: {$results['processed']}, Updated: {$results['updated']}, Created: {$results['created']}, Skipped: {$results['skipped']}, Errors: " . count($results['errors']));
    }
    
    /**
     * Get import history
     */
    public function get_import_history($limit = 20) {
        $import_log = get_option('cdi_import_log', array());
        
        if ($limit > 0) {
            $import_log = array_slice($import_log, 0, $limit);
        }
        
        return $import_log;
    }
    
    /**
     * Clean up old transients and temporary data
     */
    public function cleanup_old_data() {
        // Clean up transients older than 24 hours
        delete_transient('cdi_csv_data');
        delete_transient('cdi_preview_data');
        
        // Clean up old import logs (keep only last 100)
        $import_log = get_option('cdi_import_log', array());
        if (count($import_log) > 100) {
            $import_log = array_slice($import_log, 0, 100);
            update_option('cdi_import_log', $import_log);
        }
    }
}
     . $bubble_craps_data
                    );
                    if (in_array(253, $current_categories)) {
                        $category_changes[] = array(
                            'type' => 'remove',
                            'category_id' => 253,
                            'category_name' => 'No Bubble Craps',
                            'reason' => 'Replacing with Has Bubble Craps'
                        );
                    }
                }
                
                // Tag changes for numeric bubble craps
                $tag_changes[] = array('action' => 'add', 'tag' => 'Single Bubble Machine');
                $tag_changes[] = array('action' => 'remove', 'tag' => 'No Bubble Craps');
                
            } elseif ($is_yes) {
                // Has bubble craps but no specific minimum
                if (!in_array(252, $current_categories)) {
                    $category_changes[] = array(
                        'type' => 'add',
                        'category_id' => 252,
                        'category_name' => 'Has Bubble Craps',
                        'reason' => 'CSV indicates bubble craps available'
                    );
                    if (in_array(253, $current_categories)) {
                        $category_changes[] = array(
                            'type' => 'remove',
                            'category_id' => 253,
                            'category_name' => 'No Bubble Craps',
                            'reason' => 'Replacing with Has Bubble Craps'
                        );
                    }
                }
                
                // Tag changes for yes bubble craps
                $tag_changes[] = array('action' => 'add', 'tag' => 'Single Bubble Machine');
                $tag_changes[] = array('action' => 'remove', 'tag' => 'No Bubble Craps');
                
            } elseif ($is_no) {
                // No bubble craps
                if (!in_array(253, $current_categories)) {
                    $category_changes[] = array(
                        'type' => 'add',
                        'category_id' => 253,
                        'category_name' => 'No Bubble Craps',
                        'reason' => 'CSV indicates no bubble craps'
                    );
                    if (in_array(252, $current_categories)) {
                        $category_changes[] = array(
                            'type' => 'remove',
                            'category_id' => 252,
                            'category_name' => 'Has Bubble Craps',
                            'reason' => 'Replacing with No Bubble Craps'
                        );
                    }
                }
                
                // Tag changes for no bubble craps
                $tag_changes[] = array('action' => 'add', 'tag' => 'No Bubble Craps');
                $tag_changes[] = array('action' => 'remove', 'tag' => 'Single Bubble Machine');
                $tag_changes[] = array('action' => 'remove', 'tag' => 'Stadium Bubble Craps');
                $tag_changes[] = array('action' => 'remove', 'tag' => 'Casino Wizard');
                $tag_changes[] = array('action' => 'remove', 'tag' => 'Roll to Win');
                $tag_changes[] = array('action' => 'remove', 'tag' => 'Crapless Bubble Craps');
            }
        }
        // If no bubble craps data in CSV, make NO CHANGES (default behavior)
        
        // TABLE CRAPS LOGIC  
        if ($has_table_data) {
            // Has table craps data
            if (!in_array(344, $current_categories)) {
                $category_changes[] = array(
                    'type' => 'add',
                    'category_id' => 344,
                    'category_name' => 'Has Craps Table',
                    'reason' => 'CSV has table minimum bet data'
                );
                if (in_array(354, $current_categories)) {
                    $category_changes[] = array(
                        'type' => 'remove',
                        'category_id' => 354,
                        'category_name' => 'No Craps Table',
                        'reason' => 'Replacing with Has Craps Table'
                    );
                }
            }
            
            // Tag changes for table craps
            $tag_changes[] = array('action' => 'add', 'tag' => 'Craps Table');
        }
        // If no table data in CSV, make NO CHANGES (default behavior)
        
        return array(
            'category_changes' => $category_changes,
            'tag_changes' => $tag_changes
        );
    }
    
    /**
     * Check if this casino will be a bubble craps casino (for organization)
     */
    private function is_bubble_craps_casino($row, $casino_id) {
        $bubble_craps_data = isset($row['Bubble Craps']) ? trim($row['Bubble Craps']) : '';
        
        if (!empty($bubble_craps_data)) {
            $is_numeric = is_numeric($bubble_craps_data);
            $is_yes = in_array(strtolower($bubble_craps_data), array('yes', 'y', '1', 'true'));
            
            if ($is_numeric && $bubble_craps_data > 0) {
                return true;
            }
            if ($is_yes) {
                return true;
            }
        }
        
        // If no CSV data, check current category
        if ($casino_id) {
            $current_categories = wp_get_post_terms($casino_id, 'at_biz_dir-categories', array('fields' => 'ids'));
            return is_array($current_categories) && in_array(252, $current_categories);
        }
        
        return false;
    }
    private function process_bubble_craps_field($row, $casino_id) {
        $bubble_value = isset($row['Bubble Craps']) ? trim($row['Bubble Craps']) : '';
        
        // Check if it's a number (minimum bet)
        if (is_numeric($bubble_value) && $bubble_value > 0) {
            return array(
                'process' => true,
                'min_bet_value' => $this->convert_to_bubble_min_bet($bubble_value),
                'category_change_needed' => $casino_id ? $this->needs_bubble_craps_category($casino_id) : true,
                'bubble_craps_type' => 'single'  // Default to single machine
            );
        }
        
        // Check if it's Yes/No
        $is_yes = in_array(strtolower($bubble_value), array('yes', 'y', '1', 'true'));
        $is_no = in_array(strtolower($bubble_value), array('no', 'n', '0', 'false'));
        
        if ($is_yes) {
            // Has bubble craps but no specific minimum - don't override existing if entry exists
            if ($casino_id) {
                $existing_min = get_post_meta($casino_id, '_custom-radio-3', true);
                if (!empty($existing_min) && $existing_min !== 'N/A or Unknown') {
                    // Don't override existing minimum
                    return array('process' => false);
                }
            }
            
            return array(
                'process' => true,
                'min_bet_value' => 'N/A or Unknown',
                'category_change_needed' => $casino_id ? $this->needs_bubble_craps_category($casino_id) : true,
                'bubble_craps_type' => 'single'
            );
        }
        
        // If No or unknown, don't process
        return array('process' => false);
    }
    
    /**
     * Check if casino needs Bubble Craps category
     */
    private function needs_bubble_craps_category($casino_id) {
        $categories = wp_get_post_terms($casino_id, 'at_biz_dir-categories', array('fields' => 'names'));
        
        if (is_array($categories)) {
            // Check if already has "Has Bubble Craps" category
            foreach ($categories as $category) {
                if (stripos($category, 'bubble') !== false || stripos($category, 'has bubble') !== false) {
                    return false; // Already has bubble craps category
                }
            }
        }
        
        return true; // Needs bubble craps category
    }
    
    /**
     * Format current value for display
     */
    private function format_current_value($current_value, $field_info) {
        if (empty($current_value)) {
            return 'Not set';
        }
        
        // Handle arrays (checkboxes)
        if (is_array($current_value)) {
            if (empty($current_value)) {
                return 'Not set';
            }
            return implode(', ', $current_value);
        }
        
        return $current_value;
    }
    private function extract_location_hint($row) {
        $location_columns = array('Location', 'State', 'Region', 'Area', 'City');
        
        foreach ($location_columns as $column) {
            if (isset($row[$column]) && !empty(trim($row[$column]))) {
                return trim($row[$column]);
            }
        }
        
        return '';
    }
    
    /**
     * Clean field values for comparison and convert to Directorist format
     */
    private function clean_field_value($field_type, $value) {
        // Handle arrays - convert to string or return empty
        if (is_array($value)) {
            if (empty($value)) {
                return '';
            }
            // Convert array to comma-separated string
            $value = implode(', ', array_filter($value));
        }
        
        if (empty($value)) {
            return '';
        }
        
        $value = trim($value);
        
        // Convert CSV values to Directorist format
        switch ($field_type) {
            case 'WeekDay Min':
            case 'WeekNight Min':
            case 'WeekendMin':
            case 'WeekendnightMin':
                // Convert numeric values to Directorist ranges
                return $this->convert_to_min_bet_range($value);
                
            case 'Sidebet':
                // Convert sidebet info to checkbox array
                return $this->convert_to_sidebet_array($value);
                
            default:
                return sanitize_text_field($value);
        }
    }
    
    /**
     * Convert numeric minimum bet to Directorist range
     */
    private function convert_to_min_bet_range($value) {
        $numeric = intval(preg_replace('/[^\d]/', '', $value));
        
        if ($numeric == 0 || empty($value)) {
            return 'N/A or Unknown';
        } elseif ($numeric <= 10) {
            return '$1 - $10';
        } elseif ($numeric <= 20) {
            return '$11 - $20';
        } else {
            return '$20 +';
        }
    }
    
    /**
     * Convert to Bubble Craps minimum bet format
     */
    private function convert_to_bubble_min_bet($value) {
        $numeric = intval(preg_replace('/[^\d]/', '', $value));
        
        if ($numeric == 0 || empty($value)) {
            return 'N/A or Unknown';
        } elseif ($numeric == 1) {
            return '$1';
        } elseif ($numeric == 2) {
            return '$2';
        } elseif ($numeric == 3) {
            return '$3';
        } elseif ($numeric == 5) {
            return '$5';
        } else {
            return '$5 +';
        }
    }
    
    /**
     * Convert to bubble craps type
     */
    private function convert_to_bubble_craps_type($value) {
        $value = strtolower(trim($value));
        
        if (in_array($value, array('yes', 'y', '1', 'true', 'single'))) {
            return array('single');
        } elseif (in_array($value, array('stadium'))) {
            return array('stadium');
        } elseif (in_array($value, array('no', 'n', '0', 'false', 'none'))) {
            return array('none');
        }
        
        return array('none');
    }
    
    /**
     * Convert RTW to type
     */
    private function convert_to_rtw_type($value) {
        $value = strtolower(trim($value));
        
        if (in_array($value, array('yes', 'y', '1', 'true'))) {
            return array('rtw');
        }
        
        return array();
    }
    
    /**
     * Convert to Yes/No
     */
    private function convert_to_yes_no($value) {
        $value = strtolower(trim($value));
        
        if (in_array($value, array('yes', 'y', '1', 'true'))) {
            return 'Yes';
        } elseif (in_array($value, array('no', 'n', '0', 'false'))) {
            return 'No';
        }
        
        return 'No';
    }
    
    /**
     * Convert sidebet info to array
     */
    private function convert_to_sidebet_array($value) {
        $value = strtolower(trim($value));
        
        if (in_array($value, array('none', 'no', '0', 'false', ''))) {
            return array();
        }
        
        // Try to identify specific sidebets
        $sidebets = array();
        if (stripos($value, 'fire') !== false) $sidebets[] = 'Fire Bet';
        if (stripos($value, 'small') !== false) $sidebets[] = 'All Small';
        if (stripos($value, 'tall') !== false) $sidebets[] = 'All Tall';
        if (stripos($value, 'make') !== false || stripos($value, 'all') !== false) $sidebets[] = 'Make \'Em All';
        
        if (empty($sidebets)) {
            $sidebets[] = 'Other';
        }
        
        return $sidebets;
    }
    
    /**
     * Process the import based on form selections
     */
    public function process_import() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'cdi_nonce')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        // Get CSV data from transient
        $csv_data = get_transient('cdi_csv_data');
        if (!$csv_data) {
            wp_send_json_error('No CSV data found. Please upload a file first.');
            return;
        }
        
        // Get selected rows to process
        $selected_rows = isset($_POST['process_row']) ? $_POST['process_row'] : array();
        
        if (empty($selected_rows)) {
            wp_send_json_error('No rows selected for processing.');
            return;
        }
        
        try {
            // Filter CSV data to only selected rows
            $filtered_data = array();
            foreach ($selected_rows as $row_index) {
                if (isset($csv_data['data'][$row_index])) {
                    $filtered_data[] = $csv_data['data'][$row_index];
                }
            }
            
            $results = $this->process_csv_data($filtered_data, array());
            
            // Log the import
            $this->log_import_results($csv_data['filename'], $results);
            
            wp_send_json_success(array(
                'message' => 'Import completed successfully',
                'results' => $results,
                'redirect' => admin_url('admin.php?page=craps-data-importer&step=import')
            ));
            
        } catch (Exception $e) {
            cdi_log('Import failed: ' . $e->getMessage());
            wp_send_json_error('Import failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Process CSV data and update directory listings
     */
    private function process_csv_data($csv_rows, $row_actions = array()) {
        $matcher = new CDI_Matcher();
        $results = array(
            'processed' => 0,
            'updated' => 0,
            'created' => 0,
            'skipped' => 0,
            'errors' => array()
        );
        
        foreach ($csv_rows as $index => $row) {
            try {
                $results['processed']++;
                
                // Get casino name from the row
                $casino_name = $this->extract_casino_name($row);
                
                if (empty($casino_name)) {
                    $results['skipped']++;
                    $results['errors'][] = "Row {$index}: No casino name found";
                    continue;
                }
                
                // Try to find existing casino
                $casino_id = $matcher->find_casino_by_name($casino_name);
                
                if ($casino_id) {
                    // Update existing casino
                    $updated_fields = $matcher->update_casino_data($casino_id, $row);
                    
                    if (!empty($updated_fields)) {
                        $results['updated']++;
                        cdi_log("Updated casino {$casino_id} ({$casino_name}) - fields: " . implode(', ', $updated_fields));
                    } else {
                        $results['skipped']++;
                        cdi_log("No changes needed for casino {$casino_id} ({$casino_name})");
                    }
                } else {
                    // Create new casino entry if auto-create is enabled
                    if (cdi_get_option('auto_create', 0)) {
                        $new_casino_id = $this->create_new_casino($row);
                        if ($new_casino_id) {
                            $results['created']++;
                            cdi_log("Created new casino {$new_casino_id} ({$casino_name})");
                        } else {
                            $results['errors'][] = "Row {$index}: Failed to create casino for '{$casino_name}'";
                        }
                    } else {
                        $results['skipped']++;
                        $results['errors'][] = "Row {$index}: No matching casino found for '{$casino_name}'";
                    }
                }
                
            } catch (Exception $e) {
                $results['errors'][] = "Row {$index}: " . $e->getMessage();
                cdi_log("Error processing row {$index}: " . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Extract casino name from CSV row
     */
    private function extract_casino_name($row) {
        // Try different possible column names for casino (more comprehensive list)
        $possible_names = array(
            'Strip Casino',           // For LV Strip CSV
            'Downtown Casino',        // For Downtown CSV  
            'Casino',
            'Casino Name',
            'Name',
            'Property',
            'Location',
            'Venue',
            'Site',
            'Property Name',
            'Business Name'
        );
        
        foreach ($possible_names as $column_name) {
            if (isset($row[$column_name]) && !empty(trim($row[$column_name]))) {
                return trim($row[$column_name]);
            }
        }
        
        // If no casino name column found, try the first column that has actual data
        foreach ($row as $key => $value) {
            if (!empty(trim($value)) && !is_numeric($value)) {
                return trim($value);
            }
        }
        
        return '';
    }
    
    /**
     * Create new casino listing
     */
    private function create_new_casino($row) {
        $casino_name = $this->extract_casino_name($row);
        
        if (empty($casino_name)) {
            return false;
        }
        
        // Create new post
        $post_data = array(
            'post_title' => $casino_name,
            'post_type' => 'at_biz_dir',
            'post_status' => 'publish',
            'post_content' => '',
            'meta_input' => array(
                '_created_by_cdi' => 1,
                '_created_date' => current_time('mysql')
            )
        );
        
        $casino_id = wp_insert_post($post_data);
        
        if ($casino_id && !is_wp_error($casino_id)) {
            // Update with CSV data
            $matcher = new CDI_Matcher();
            $matcher->update_casino_data($casino_id, $row);
            
            return $casino_id;
        }
        
        return false;
    }
    
    /**
     * Log import results
     */
    private function log_import_results($filename, $results) {
        $log_entry = array(
            'filename' => $filename,
            'timestamp' => current_time('mysql'),
            'results' => $results
        );
        
        // Get existing log
        $import_log = get_option('cdi_import_log', array());
        
        // Add new entry
        array_unshift($import_log, $log_entry);
        
        // Keep only last 50 entries
        $import_log = array_slice($import_log, 0, 50);
        
        // Save log
        update_option('cdi_import_log', $import_log);
        
        cdi_log("Import completed - Processed: {$results['processed']}, Updated: {$results['updated']}, Created: {$results['created']}, Skipped: {$results['skipped']}, Errors: " . count($results['errors']));
    }
    
    /**
     * Get import history
     */
    public function get_import_history($limit = 20) {
        $import_log = get_option('cdi_import_log', array());
        
        if ($limit > 0) {
            $import_log = array_slice($import_log, 0, $limit);
        }
        
        return $import_log;
    }
    
    /**
     * Clean up old transients and temporary data
     */
    public function cleanup_old_data() {
        // Clean up transients older than 24 hours
        delete_transient('cdi_csv_data');
        delete_transient('cdi_preview_data');
        
        // Clean up old import logs (keep only last 100)
        $import_log = get_option('cdi_import_log', array());
        if (count($import_log) > 100) {
            $import_log = array_slice($import_log, 0, 100);
            update_option('cdi_import_log', $import_log);
        }
    }
}