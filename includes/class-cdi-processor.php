<?php
/**
 * Data processor for Craps Data Importer
 */

if (!defined('ABSPATH')) {
    exit;
}

class CDI_Processor {
    
    private $matcher;
    
    public function __construct() {
        // Matcher will be initialized when needed
    }
    
    /**
     * Get matcher instance
     */
    private function get_matcher() {
        if (!$this->matcher) {
            $this->matcher = new CDI_Matcher();
        }
        return $this->matcher;
    }
    
    /**
     * Handle CSV file upload
     */
    public function handle_csv_upload() {
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception(__('File upload failed', 'craps-data-importer'));
        }
        
        $file = $_FILES['csv_file'];
        $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($file_extension !== 'csv') {
            throw new Exception(__('Please upload a CSV file only', 'craps-data-importer'));
        }
        
        $csv_data = $this->parse_csv_file($file['tmp_name']);
        
        if (empty($csv_data)) {
            throw new Exception(__('Could not parse CSV file or no data found', 'craps-data-importer'));
        }
        
        // Store data temporarily
        set_transient('cdi_csv_data', $csv_data, 3600);
        
        return array(
            'success' => true,
            'message' => __('CSV file uploaded successfully', 'craps-data-importer'),
            'rows_found' => count($csv_data['data']),
            'redirect' => admin_url('admin.php?page=craps-data-importer&step=preview')
        );
    }
    
    /**
     * Parse CSV file
     */
    private function parse_csv_file($file_path) {
        if (!file_exists($file_path)) {
            throw new Exception(__('File not found', 'craps-data-importer'));
        }
        
        $data = array();
        $headers = array();
        
        if (($handle = fopen($file_path, 'r')) !== FALSE) {
            $row_count = 0;
            
            while (($row = fgetcsv($handle, 1000, ',')) !== FALSE) {
                if ($row_count === 0) {
                    // First row contains headers
                    $headers = array_map('trim', $row);
                } else {
                    // Data rows
                    $row_data = array();
                    foreach ($headers as $index => $header) {
                        $row_data[$header] = isset($row[$index]) ? trim($row[$index]) : '';
                    }
                    $data[] = $row_data;
                }
                $row_count++;
            }
            
            fclose($handle);
        }
        
        return array(
            'headers' => $headers,
            'data' => $data
        );
    }
    
    /**
     * Preview CSV data
     */
    public function preview_csv_data() {
        $csv_data = get_transient('cdi_csv_data');
        
        if (!$csv_data) {
            throw new Exception(__('No CSV data found. Please upload a file first.', 'craps-data-importer'));
        }
        
        $preview_data = array_slice($csv_data['data'], 0, 10);
        
        return array(
            'headers' => $csv_data['headers'],
            'preview_data' => $preview_data,
            'total_rows' => count($csv_data['data'])
        );
    }
    
    /**
     * Process import with settings
     */
    public function process_import($settings) {
        $csv_data = get_transient('cdi_csv_data');
        
        if (!$csv_data) {
            throw new Exception(__('No CSV data found. Please upload a file first.', 'craps-data-importer'));
        }
        
        $matcher = $this->get_matcher();
        $results = array(
            'total_rows' => count($csv_data['data']),
            'processed' => 0,
            'updated' => 0,
            'queued' => 0,
            'errors' => array()
        );
        
        foreach ($csv_data['data'] as $index => $row) {
            try {
                $casino_name = $this->extract_casino_name($row);
                
                if (empty($casino_name)) {
                    $results['errors'][] = "Row " . ($index + 1) . ": No casino name found";
                    continue;
                }
                
                $match_result = $matcher->find_casino_match($casino_name, $settings['similarity_threshold']);
                
                if ($match_result['casino'] && $match_result['similarity'] >= $settings['similarity_threshold']) {
                    if ($settings['auto_update']) {
                        $this->update_casino_data($match_result['casino']->ID, $row, $settings['update_existing']);
                        $results['updated']++;
                    } else {
                        $this->add_to_review_queue($casino_name, $row, 'Manual review required');
                        $results['queued']++;
                    }
                } else {
                    $this->add_to_review_queue($casino_name, $row, 'No matching casino found');
                    $results['queued']++;
                }
                
                $results['processed']++;
                
            } catch (Exception $e) {
                $results['errors'][] = "Row " . ($index + 1) . ": " . $e->getMessage();
            }
        }
        
        // Save import history
        $this->save_import_history($csv_data, $results, $settings);
        
        // Clean up temporary data
        delete_transient('cdi_csv_data');
        
        return $results;
    }
    
    /**
     * Extract casino name from CSV row
     */
    private function extract_casino_name($row) {
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
     * Update casino data with CSV row data
     */
    private function update_casino_data($casino_id, $row_data, $update_existing = true) {
        $field_mapping = $this->get_field_mapping();
        
        foreach ($row_data as $csv_field => $value) {
            if (empty($value) || !isset($field_mapping[$csv_field])) {
                continue;
            }
            
            $meta_key = $field_mapping[$csv_field];
            
            // Check if we should update existing data
            if (!$update_existing) {
                $existing_value = get_post_meta($casino_id, $meta_key, true);
                if (!empty($existing_value)) {
                    continue; // Skip if field already has data
                }
            }
            
            update_post_meta($casino_id, $meta_key, sanitize_text_field($value));
        }
    }
    
    /**
     * Get field mapping for CSV columns to WordPress meta fields
     */
    private function get_field_mapping() {
        return array(
            'WeekDay Min' => '_weekday_min',
            'WeekNight Min' => '_weeknight_min',
            'WeekendMin' => '_weekend_min',
            'WeekendnightMin' => '_weekend_night_min',
            'MaxOdds' => '_max_odds',
            'Field Pay' => '_field_pay',
            'Sidebet' => '_sidebet',
            'Dividers/Per Side' => '_dividers_per_side',
            'Rewards' => '_rewards',
            'Crapless' => '_crapless',
            'Bubble Craps' => '_bubble_craps',
            'Roll To Win' => '_roll_to_win',
            'RTW Mins' => '_rtw_mins',
            'Comments' => '_comments'
        );
    }
    
    /**
     * Add item to review queue
     */
    private function add_to_review_queue($casino_name, $row_data, $reason) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cdi_review_queue';
        
        $wpdb->insert(
            $table_name,
            array(
                'casino_name' => $casino_name,
                'csv_data' => wp_json_encode($row_data),
                'reason' => $reason,
                'status' => 'pending'
            ),
            array('%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Resolve queue item
     */
    public function resolve_queue_item($queue_id, $action, $casino_id = 0) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cdi_review_queue';
        
        $queue_item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $queue_id
        ));
        
        if (!$queue_item) {
            throw new Exception(__('Queue item not found', 'craps-data-importer'));
        }
        
        switch ($action) {
            case 'accept':
                if ($casino_id && $casino_id > 0) {
                    $row_data = json_decode($queue_item->csv_data, true);
                    $this->update_casino_data($casino_id, $row_data, true);
                    
                    $wpdb->update(
                        $table_name,
                        array('status' => 'resolved'),
                        array('id' => $queue_id),
                        array('%s'),
                        array('%d')
                    );
                    
                    return array('success' => true, 'message' => 'Item resolved and casino updated');
                } else {
                    throw new Exception(__('Casino ID required for acceptance', 'craps-data-importer'));
                }
                break;
                
            case 'skip':
                $wpdb->update(
                    $table_name,
                    array('status' => 'skipped'),
                    array('id' => $queue_id),
                    array('%s'),
                    array('%d')
                );
                
                return array('success' => true, 'message' => 'Item skipped');
                break;
                
            default:
                throw new Exception(__('Invalid action', 'craps-data-importer'));
        }
    }
    
    /**
     * Save import history
     */
    private function save_import_history($csv_data, $results, $settings) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cdi_import_history';
        
        $wpdb->insert(
            $table_name,
            array(
                'filename' => 'upload-' . date('Y-m-d-H-i-s') . '.csv',
                'total_rows' => $results['total_rows'],
                'processed_rows' => $results['processed'],
                'updated_casinos' => $results['updated'],
                'queued_items' => $results['queued'],
                'import_settings' => wp_json_encode($settings)
            ),
            array('%s', '%d', '%d', '%d', '%d', '%s')
        );
    }
}