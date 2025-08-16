<?php
/**
 * Data processor for Craps Data Importer
 */

class CDI_Processor {
    
    private $matcher;
    
    public function __construct() {
        $this->matcher = new CDI_Matcher();
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
            'rows_found' => count($csv_data['data'])
        );
    }
    
    /**
     * Parse CSV file
     */
    private function parse_csv_file($file_path) {
        $csv_content = file_get_contents($file_path);
        
        if (empty($csv_content)) {
            return array();
        }
        
        // Detect encoding
        $encoding = mb_detect_encoding($csv_content, ['UTF-8', 'UTF-16', 'Windows-1252', 'ISO-8859-1'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $csv_content = mb_convert_encoding($csv_content, 'UTF-8', $encoding);
        }
        
        $lines = str_getcsv($csv_content, "\n");
        if (empty($lines)) {
            return array();
        }
        
        $headers = str_getcsv(array_shift($lines));
        $headers = $this->normalize_headers($headers);
        
        $data = array();
        $row_number = 2;
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $row_data = str_getcsv($line);
            
            // Ensure row has same number of columns as headers
            while (count($row_data) < count($headers)) {
                $row_data[] = '';
            }
            
            $row_assoc = array_combine($headers, array_slice($row_data, 0, count($headers)));
            $row_assoc = $this->clean_row_data($row_assoc);
            
            if (!$this->is_empty_row($row_assoc)) {
                $row_assoc['_row_number'] = $row_number;
                $data[] = $row_assoc;
            }
            
            $row_number++;
        }
        
        return array(
            'headers' => $headers,
            'data' => $data,
            'source_file' => basename($file_path)
        );
    }
    
    /**
     * Normalize CSV headers to expected format
     */
    private function normalize_headers($headers) {
        $normalized = array();
        $header_map = array(
            'downtown casino' => 'Casino',
            'casino' => 'Casino',
            'weekday min' => 'WeekDay Min',
            'weeknight min' => 'WeekNight Min', 
            'weekend min' => 'Weekend Min',
            'weekendmin' => 'Weekend Min',
            'weekendnight min' => 'WeekendNight Min',
            'weekendnightmin' => 'WeekendNight Min',
            'maxodds' => 'MaxOdds',
            'max odds' => 'MaxOdds',
            'field pay' => 'Field Pay',
            'sidebet' => 'Sidebet',
            'side bet' => 'Sidebet',
            'dividers/per side' => 'Dividers',
            'dividers' => 'Dividers',
            'rewards' => 'Rewards',
            'crapless' => 'Crapless',
            'bubble craps' => 'Bubble Craps',
            'roll to win' => 'Roll To Win',
            'rtw mins' => 'RTW Mins',
            'last update' => 'Last Update',
            'comments' => 'Comments',
            'coordinates' => 'Coordinates'
        );
        
        foreach ($headers as $header) {
            $clean_header = strtolower(trim($header));
            $clean_header = preg_replace('/[^\w\s]/', '', $clean_header);
            $clean_header = preg_replace('/\s+/', ' ', $clean_header);
            
            if (isset($header_map[$clean_header])) {
                $normalized[] = $header_map[$clean_header];
            } else {
                // Keep original if no mapping found
                $normalized[] = ucwords(str_replace(['_', '-'], ' ', $header));
            }
        }
        
        return $normalized;
    }
    
    /**
     * Clean row data
     */
    private function clean_row_data($row) {
        $cleaned = array();
        
        foreach ($row as $key => $value) {
            $value = trim($value);
            $value = str_replace(["\r", "\n"], ' ', $value);
            $value = preg_replace('/\s+/', ' ', $value);
            
            // Normalize specific field types
            switch ($key) {
                case 'Bubble Craps':
                    $value = $this->normalize_bubble_craps_value($value);
                    break;
                case 'Crapless':
                    $value = $this->normalize_boolean_value($value);
                    break;
                case 'WeekDay Min':
                case 'WeekNight Min':
                case 'Weekend Min':
                case 'WeekendNight Min':
                    $value = $this->normalize_currency_value($value);
                    break;
                case 'Rewards':
                    $value = $this->normalize_rewards_value($value);
                    break;
            }
            
            $cleaned[$key] = $value;
        }
        
        return $cleaned;
    }
    
    /**
     * Check if row is empty
     */
    private function is_empty_row($row) {
        $casino_name = $row['Casino'] ?? '';
        return empty(trim($casino_name));
    }
    
    /**
     * Preview CSV data
     */
    public function preview_csv_data() {
        $csv_data = get_transient('cdi_csv_data');
        
        if (!$csv_data) {
            throw new Exception(__('No CSV data found. Please upload a file first.', 'craps-data-importer'));
        }
        
        return array(
            'preview' => array_slice($csv_data['data'], 0, 5),
            'total_rows' => count($csv_data['data']),
            'headers' => $csv_data['headers']
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
        
        $results = array(
            'total' => count($csv_data['data']),
            'updated' => 0,
            'queued' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => array()
        );
        
        foreach ($csv_data['data'] as $row) {
            try {
                $result = $this->process_single_row($row, $settings);
                $results[$result['action']]++;
                $results['details'][] = $result['detail'];
            } catch (Exception $e) {
                $results['errors']++;
                $results['details'][] = array(
                    'casino' => $row['Casino'] ?? 'Unknown',
                    'action' => 'error',
                    'message' => $e->getMessage()
                );
            }
        }
        
        // Save import history
        $this->save_import_history($csv_data, $results, $settings);
        
        // Clean up transient
        delete_transient('cdi_csv_data');
        
        return $results;
    }
    
    /**
     * Process single CSV row
     */
    private function process_single_row($row, $settings) {
        $casino_name = $row['Casino'] ?? '';
        
        if (empty($casino_name)) {
            return array(
                'action' => 'skipped',
                'detail' => array(
                    'casino' => 'Empty',
                    'reason' => 'No casino name provided'
                )
            );
        }
        
        // Find matching casino
        $match_result = $this->matcher->find_casino_match($casino_name, $settings['similarity_threshold']);
        
        if (!$match_result['casino']) {
            // No match found - add to queue
            $this->add_to_review_queue($row, 'No matching casino found');
            
            return array(
                'action' => 'queued',
                'detail' => array(
                    'casino' => $casino_name,
                    'reason' => 'No match found',
                    'similarity' => 0
                )
            );
        }
        
        $casino = $match_result['casino'];
        $similarity = $match_result['similarity'];
        
        // Check if confidence is too low
        if ($similarity < $settings['similarity_threshold']) {
            $this->add_to_review_queue($row, "Low confidence match: {$similarity}%");
            
            return array(
                'action' => 'queued',
                'detail' => array(
                    'casino' => $casino_name,
                    'matched_casino' => $casino->post_title,
                    'reason' => "Low confidence: {$similarity}%",
                    'similarity' => $similarity
                )
            );
        }
        
        // Auto-update if enabled
        if ($settings['auto_update']) {
            $changes = $this->update_casino_fields($casino->ID, $row, $settings['update_existing']);
            
            return array(
                'action' => 'updated',
                'detail' => array(
                    'casino' => $casino_name,
                    'matched_casino' => $casino->post_title,
                    'changes' => $changes,
                    'similarity' => $similarity
                )
            );
        } else {
            // Send to review queue
            $this->add_to_review_queue($row, 'Manual review requested');
            
            return array(
                'action' => 'queued',
                'detail' => array(
                    'casino' => $casino_name,
                    'matched_casino' => $casino->post_title,
                    'reason' => 'Manual review requested',
                    'similarity' => $similarity
                )
            );
        }
    }
    
    /**
     * Update casino fields with CSV data
     */
    private function update_casino_fields($casino_id, $csv_data, $update_existing = true) {
        $changes = array();
        
        // Map CSV fields to WordPress meta fields
        $field_mappings = array(
            'WeekDay Min' => '_custom-radio-2',
            'WeekNight Min' => '_custom-radio-7', 
            'Weekend Min' => '_custom-radio-8',
            'WeekendNight Min' => '_custom-radio-9',
            'Rewards' => '_custom-radio-5',
            'Sidebet' => '_custom-checkbox-2'
        );
        
        foreach ($field_mappings as $csv_field => $meta_key) {
            if (!isset($csv_data[$csv_field])) continue;
            
            $new_value = $csv_data[$csv_field];
            if (empty($new_value) || $new_value === 'Unknown' || $new_value === 'N/A') continue;
            
            $current_value = get_post_meta($casino_id, $meta_key, true);
            
            // Skip if field has value and update_existing is false
            if (!$update_existing && !empty($current_value) && $current_value !== 'Unknown') {
                continue;
            }
            
            // Convert value to proper format
            $processed_value = $this->convert_csv_value_to_wp($csv_field, $new_value);
            
            if ($processed_value !== $current_value) {
                update_post_meta($casino_id, $meta_key, $processed_value);
                $changes[] = sprintf('%s: %s → %s', $csv_field, $current_value, $processed_value);
            }
        }
        
        // Handle bubble craps data
        $this->update_bubble_craps_fields($casino_id, $csv_data, $changes);
        
        return $changes;
    }
    
    /**
     * Update bubble craps specific fields
     */
    private function update_bubble_craps_fields($casino_id, $csv_data, &$changes) {
        $bubble_craps = $csv_data['Bubble Craps'] ?? '';
        $crapless = $csv_data['Crapless'] ?? '';
        $rtw = $csv_data['Roll To Win'] ?? '';
        
        // Determine bubble craps types
        $types = array();
        
        if (!empty($bubble_craps) && $bubble_craps !== 'No') {
            $types[] = 'single';
        }
        
        if (!empty($crapless) && $crapless !== 'No') {
            $types[] = 'crapless';
        }
        
        if (!empty($rtw) && $rtw !== 'No') {
            $types[] = 'rtw';
        }
        
        // Update category
        $category_term = empty($types) ? 'No Bubble Craps (or unknown)' : 'Has Bubble Craps';
        $this->update_casino_category($casino_id, $category_term, $changes);
        
        // Update types
        if (!empty($types)) {
            update_post_meta($casino_id, '_custom-checkbox', $types);
            $changes[] = 'Bubble Craps Types: ' . implode(', ', $types);
            
            // Update tags
            $tag_mappings = array(
                'single' => 'Single Bubble Machine',
                'crapless' => 'Crapless Bubble Craps', 
                'rtw' => 'Roll to Win'
            );
            
            $tags_to_add = array();
            foreach ($types as $type) {
                if (isset($tag_mappings[$type])) {
                    $tags_to_add[] = $tag_mappings[$type];
                }
            }
            
            if (!empty($tags_to_add)) {
                $this->update_casino_tags($casino_id, $tags_to_add, $changes);
            }
        } else {
            // No bubble craps
            update_post_meta($casino_id, '_custom-checkbox', array('none'));
            $this->update_casino_tags($casino_id, array('No Bubble Craps'), $changes);
        }
    }
    
    /**
     * Update casino category
     */
    private function update_casino_category($casino_id, $category_name, &$changes) {
        $term = get_term_by('name', $category_name, 'at_biz_dir-categories');
        
        if ($term) {
            $result = wp_set_post_terms($casino_id, array($term->term_id), 'at_biz_dir-categories');
            if (!is_wp_error($result)) {
                $changes[] = 'Category: ' . $category_name;
            }
        }
    }
    
    /**
     * Update casino tags
     */
    private function update_casino_tags($casino_id, $tag_names, &$changes) {
        // Get existing tags
        $existing_tags = wp_get_post_terms($casino_id, 'at_biz_dir-tags', array('fields' => 'names'));
        
        // Remove existing bubble craps related tags
        $bubble_craps_tags = array(
            'Single Bubble Machine',
            'Crapless Bubble Craps',
            'Roll to Win',
            'Stadium Bubble Craps',
            'Casino Wizard',
            'No Bubble Craps'
        );
        
        $filtered_tags = array_diff($existing_tags, $bubble_craps_tags);
        
        // Add new tags
        $new_tags = array_merge($filtered_tags, $tag_names);
        
        $result = wp_set_post_terms($casino_id, $new_tags, 'at_biz_dir-tags');
        if (!is_wp_error($result)) {
            $changes[] = 'Tags: +' . implode(', +', $tag_names);
        }
    }
    
    /**
     * Add item to review queue
     */
    private function add_to_review_queue($csv_data, $reason) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cdi_review_queue';
        
        $wpdb->insert(
            $table_name,
            array(
                'casino_name' => $csv_data['Casino'] ?? 'Unknown',
                'csv_data' => json_encode($csv_data),
                'reason' => $reason,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Resolve queue item
     */
    public function resolve_queue_item($queue_id, $action, $casino_id = 0) {
        global $wpdb;
        
        $table_name