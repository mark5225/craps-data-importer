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
        
        // Define field mapping for relevant fields only
        $field_mapping = array(
            'WeekDay Min' => array('meta_key' => '_weekday_min', 'label' => 'Weekday Minimum'),
            'WeekNight Min' => array('meta_key' => '_weeknight_min', 'label' => 'Weeknight Minimum'),
            'WeekendMin' => array('meta_key' => '_weekend_min', 'label' => 'Weekend Minimum'),
            'WeekendnightMin' => array('meta_key' => '_weekend_night_min', 'label' => 'Weekend Night Minimum'),
            'MaxOdds' => array('meta_key' => '_max_odds', 'label' => 'Maximum Odds'),
            'Field Pay' => array('meta_key' => '_field_pay', 'label' => 'Field Pay'),
            'Sidebet' => array('meta_key' => '_sidebet', 'label' => 'Side Bets'),
            'Dividers/Per Side' => array('meta_key' => '_dividers_per_side', 'label' => 'Dividers Per Side'),
            'Rewards' => array('meta_key' => '_rewards', 'label' => 'Rewards Program'),
            'Crapless' => array('meta_key' => '_crapless', 'label' => 'Crapless Craps'),
            'Bubble Craps' => array('meta_key' => '_bubble_craps', 'label' => 'Bubble Craps'),
            'Roll To Win' => array('meta_key' => '_roll_to_win', 'label' => 'Roll to Win'),
            'RTW Mins' => array('meta_key' => '_rtw_mins', 'label' => 'RTW Minimums'),
            'Comments' => array('meta_key' => '_comments', 'label' => 'Comments')
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
                
                // Map only relevant data
                foreach ($field_mapping as $csv_column => $field_info) {
                    if (isset($row[$csv_column]) && !empty(trim($row[$csv_column]))) {
                        $new_value = $this->clean_field_value($csv_column, trim($row[$csv_column]));
                        $mapped_data[$field_info['label']] = $new_value;
                        
                        if ($casino_id) {
                            // Compare with existing value
                            $current_value = get_post_meta($casino_id, $field_info['meta_key'], true);
                            $current_value = $this->clean_field_value($csv_column, $current_value);
                            
                            if ($current_value !== $new_value) {
                                $change_type = empty($current_value) ? 'add' : 'update';
                                $changes[$field_info['meta_key']] = array(
                                    'label' => $field_info['label'],
                                    'current' => $current_value,
                                    'new' => $new_value,
                                    'type' => $change_type
                                );
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
     * Clean field values for comparison
     */
    private function clean_field_value($field_type, $value) {
        if (empty($value)) {
            return '';
        }
        
        $value = trim($value);
        
        // Clean specific field types
        switch ($field_type) {
            case 'WeekDay Min':
            case 'WeekNight Min':
            case 'WeekendMin':
            case 'WeekendnightMin':
            case 'RTW Mins':
                // Parse minimum bet values
                return cdi_parse_min_bet($value);
                
            case 'MaxOdds':
                // Parse odds values
                return cdi_parse_odds($value);
                
            case 'Crapless':
            case 'Bubble Craps':
            case 'Roll To Win':
                // Parse boolean values
                return cdi_parse_boolean($value);
                
            default:
                return sanitize_text_field($value);
        }
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