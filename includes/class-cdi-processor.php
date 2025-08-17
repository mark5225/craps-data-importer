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
     * Preview CSV data
     */
    public function preview_csv_data() {
        $csv_data = get_transient('cdi_csv_data');
        
        if (!$csv_data) {
            throw new Exception('No CSV data found. Please upload a file first.');
        }
        
        return array(
            'headers' => $csv_data['headers'],
            'sample_data' => array_slice($csv_data['data'], 0, 5),
            'total_rows' => count($csv_data['data']),
            'filename' => $csv_data['filename']
        );
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
        
        // Get form selections (if any)
        $row_actions = isset($_POST['row_actions']) ? $_POST['row_actions'] : array();
        
        try {
            $results = $this->process_csv_data($csv_data['data'], $row_actions);
            
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
        // Try different possible column names for casino
        $possible_names = array(
            'Downtown Casino',
            'Casino',
            'Casino Name',
            'Name',
            'Property',
            'Location'
        );
        
        foreach ($possible_names as $column_name) {
            if (isset($row[$column_name]) && !empty(trim($row[$column_name]))) {
                return trim($row[$column_name]);
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