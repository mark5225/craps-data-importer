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
        $settings = array(
            'auto_update' => isset($_POST['auto_update']) ? intval($_POST['auto_update']) : 1,
            'similarity_threshold' => isset($_POST['similarity_threshold']) ? intval($_POST['similarity_threshold']) : 80,
            'update_existing' => isset($_POST['update_existing']) ? intval($_POST['update_existing']) : 1
        );
        
        // Initialize results
        $results = array(
            'updated' => 0,
            'created' => 0,
            'skipped' => 0,
            'queued' => 0,
            'errors' => 0,
            'details' => array()
        );
        
        // Process each row
        foreach ($csv_data['data'] as $index => $row) {
            $casino_name = $this->extract_casino_name_from_row($row);
            
            // Check for row-specific action
            $row_action = isset($row_actions[$index]) ? $row_actions[$index] : 'auto';
            
            if ($row_action === 'skip') {
                $results['skipped']++;
                $results['details'][] = array(
                    'casino' => $casino_name,
                    'action' => 'skipped',
                    'message' => 'Skipped by user'
                );
                continue;
            }
            
            if ($row_action === 'queue') {
                $queue_result = $this->add_to_review_queue($casino_name, $row);
                if ($queue_result['success']) {
                    $results['queued']++;
                } else {
                    $results['errors']++;
                }
                $results['details'][] = $queue_result;
                continue;
            }
            
            // Process the casino update
            $result = $this->process_casino_update($casino_name, $row, $settings);
            
            if ($result['success']) {
                if ($result['action'] === 'created') {
                    $results['created']++;
                } else {
                    $results['updated']++;
                }
                $results['details'][] = $result;
            } else {
                $results['errors']++;
                $results['details'][] = $result;
            }
        }
        
        // Save import history
        $this->save_import_history($csv_data, $results, $settings);
        
        // Return results
        wp_send_json_success(array(
            'stats' => $results,
            'message' => sprintf(
                'Import complete: %d updated, %d created, %d skipped, %d queued for review, %d errors',
                $results['updated'],
                $results['created'], 
                $results['skipped'],
                $results['queued'],
                $results['errors']
            )
        ));
    }
    
    /**
     * Process updating a single casino
     */
    private function process_casino_update($casino_name, $csv_row, $settings) {
        // Find matching casino
        $match_result = $this->find_casino_match($casino_name);
        $casino_post = $match_result['casino'];
        $similarity = $match_result['similarity'];
        
        $changes_made = array();
        $action = 'updated';
        
        // If no match found and similarity is too low, create new casino
        if (!$casino_post || $similarity < $settings['similarity_threshold']) {
            if ($settings['auto_update']) {
                $casino_post = $this->create_new_casino($casino_name, $csv_row);
                $action = 'created';
                $changes_made[] = 'Created new casino listing';
            } else {
                return array(
                    'success' => false,
                    'casino' => $casino_name,
                    'action' => 'error',
                    'message' => 'No match found and auto-create disabled'
                );
            }
        }
        
        if (!$casino_post) {
            return array(
                'success' => false,
                'casino' => $casino_name,
                'action' => 'error',
                'message' => 'Failed to create or find casino'
            );
        }
        
        // Update custom fields
        foreach ($csv_row as $field => $value) {
            if (empty($value) || $field === $this->get_casino_name_field($csv_row)) {
                continue; // Skip empty values and casino name field
            }
            
            $meta_key = 'cdi_' . sanitize_key($field);
            $old_value = get_post_meta($casino_post->ID, $meta_key, true);
            
            if ($old_value !== $value) {
                update_post_meta($casino_post->ID, $meta_key, sanitize_text_field($value));
                $changes_made[] = sprintf('%s: %s → %s', 
                    $this->get_field_display_name($field), 
                    $old_value ?: '(empty)', 
                    $value
                );
            }
        }
        
        return array(
            'success' => true,
            'casino' => $casino_name,
            'action' => $action,
            'message' => $action === 'created' ? 'New casino created' : 'Casino updated',
            'changes' => $changes_made,
            'casino_id' => $casino_post->ID
        );
    }
    
    /**
     * Create a new casino listing
     */
    private function create_new_casino($casino_name, $csv_row) {
        $post_data = array(
            'post_title' => $casino_name,
            'post_content' => 'Auto-created by Craps Data Importer',
            'post_status' => 'publish',
            'post_type' => 'at_biz_dir',
            'post_author' => get_current_user_id()
        );
        
        $post_id = wp_insert_post($post_data);
        
        if (is_wp_error($post_id)) {
            error_log('CDI: Failed to create casino: ' . $post_id->get_error_message());
            return null;
        }
        
        // Set basic meta fields
        update_post_meta($post_id, '_phone', '');
        update_post_meta($post_id, '_website', '');
        update_post_meta($post_id, '_address', '');
        
        // Add default category
        wp_set_post_terms($post_id, array('casino'), 'at_biz_dir-categories');
        
        return get_post($post_id);
    }
    
    /**
     * Add casino to review queue
     */
    private function add_to_review_queue($casino_name, $csv_row) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cdi_review_queue';
        
        $result = $wpdb->insert(
            $table_name,
            array(
                'casino_name' => $casino_name,
                'csv_data' => json_encode($csv_row),
                'reason' => 'Manual review requested',
                'status' => 'pending',
                'created_at' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            return array(
                'success' => false,
                'casino' => $casino_name,
                'action' => 'error',
                'message' => 'Failed to add to review queue: ' . $wpdb->last_error
            );
        }
        
        cdi_log('Added casino to review queue: ' . $casino_name);
        
        return array(
            'success' => true,
            'casino' => $casino_name,
            'action' => 'queued',
            'message' => 'Added to manual review queue'
        );
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
                'filename' => $csv_data['filename'] ?? 'CSV Import',
                'total_rows' => count($csv_data['data']),
                'processed_rows' => $results['updated'] + $results['created'] + $results['skipped'],
                'updated_casinos' => $results['updated'],
                'queued_items' => $results['queued'],
                'import_settings' => json_encode($settings),
                'import_date' => current_time('mysql')
            ),
            array('%s', '%d', '%d', '%d', '%d', '%s', '%s')
        );
    }
    
    /**
     * Resolve a queue item
     */
    public function resolve_queue_item($queue_id, $action, $casino_id = 0) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'cdi_review_queue';
        
        // Get the queue item
        $queue_item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE id = %d",
            $queue_id
        ));
        
        if (!$queue_item) {
            throw new Exception('Queue item not found');
        }
        
        $csv_data = json_decode($queue_item->csv_data, true);
        
        switch ($action) {
            case 'approve':
                // Process the casino update
                $settings = array(
                    'auto_update' => 1,
                    'similarity_threshold' => 50,
                    'update_existing' => 1
                );
                
                $result = $this->process_casino_update($queue_item->casino_name, $csv_data, $settings);
                
                if ($result['success']) {
                    // Mark as resolved
                    $wpdb->update(
                        $table_name,
                        array('status' => 'resolved'),
                        array('id' => $queue_id),
                        array('%s'),
                        array('%d')
                    );
                    
                    return array('success' => true, 'message' => 'Casino processed successfully');
                } else {
                    return array('success' => false, 'message' => $result['message']);
                }
                
            case 'reject':
                // Mark as rejected
                $wpdb->update(
                    $table_name,
                    array('status' => 'rejected'),
                    array('id' => $queue_id),
                    array('%s'),
                    array('%d')
                );
                
                return array('success' => true, 'message' => 'Item rejected');
                
            case 'link':
                if (!$casino_id) {
                    throw new Exception('Casino ID required for linking');
                }
                
                // Update the specified casino with CSV data
                $casino_post = get_post($casino_id);
                if (!$casino_post) {
                    throw new Exception('Casino not found');
                }
                
                // Update fields
                foreach ($csv_data as $field => $value) {
                    if (!empty($value)) {
                        $meta_key = 'cdi_' . sanitize_key($field);
                        update_post_meta($casino_id, $meta_key, sanitize_text_field($value));
                    }
                }
                
                // Mark as resolved
                $wpdb->update(
                    $table_name,
                    array('status' => 'resolved'),
                    array('id' => $queue_id),
                    array('%s'),
                    array('%d')
                );
                
                return array('success' => true, 'message' => 'Casino linked and updated');
                
            default:
                throw new Exception('Invalid action');
        }
    }
    
    /**
     * Preview CSV data (used by admin interface)
     */
    public function preview_csv_data() {
        $csv_data = get_transient('cdi_csv_data');
        if (!$csv_data) {
            throw new Exception('No CSV data found');
        }
        
        // Add matching information for preview
        $preview_data = array();
        foreach ($csv_data['data'] as $index => $row) {
            $casino_name = $this->extract_casino_name_from_row($row);
            $match_result = $this->find_casino_match($casino_name);
            
            $preview_data[] = array(
                'index' => $index,
                'casino_name' => $casino_name,
                'csv_data' => $row,
                'match' => $match_result['casino'],
                'similarity' => $match_result['similarity']
            );
        }
        
        return array(
            'headers' => $csv_data['headers'],
            'preview_data' => $preview_data,
            'filename' => $csv_data['filename']
        );
    }
    
    /**
     * Find matching casino (same as admin class)
     */
    private function find_casino_match($casino_name) {
        if (empty($casino_name)) {
            return array('casino' => null, 'similarity' => 0);
        }
        
        // Clean the casino name for better matching
        $clean_name = $this->clean_casino_name($casino_name);
        
        // Try exact match first
        $exact_match = get_posts(array(
            'post_type' => 'at_biz_dir',
            'post_status' => 'publish',
            'title' => $casino_name,
            'numberposts' => 1
        ));
        
        if (!empty($exact_match)) {
            return array('casino' => $exact_match[0], 'similarity' => 100);
        }
        
        // Try fuzzy search
        $search_posts = get_posts(array(
            'post_type' => 'at_biz_dir',
            'post_status' => 'publish',
            's' => $clean_name,
            'numberposts' => 20
        ));
        
        $best_match = null;
        $best_similarity = 0;
        
        foreach ($search_posts as $post) {
            $similarity = $this->calculate_similarity($clean_name, $this->clean_casino_name($post->post_title));
            
            if ($similarity > $best_similarity) {
                $best_similarity = $similarity;
                $best_match = $post;
            }
        }
        
        return array('casino' => $best_match, 'similarity' => round($best_similarity));
    }
    
    /**
     * Helper methods
     */
    private function clean_casino_name($name) {
        $name = strtolower(trim($name));
        $remove_words = array('casino', 'hotel', 'resort', 'las vegas', 'the ', ' the', '&', 'and');
        $name = str_replace($remove_words, '', $name);
        return preg_replace('/\s+/', ' ', trim($name));
    }
    
    private function calculate_similarity($str1, $str2) {
        if (empty($str1) || empty($str2)) {
            return 0;
        }
        $similarity = 0;
        similar_text(strtolower($str1), strtolower($str2), $similarity);
        return $similarity;
    }
    
    private function extract_casino_name_from_row($row) {
        foreach ($row as $value) {
            if (!empty(trim($value))) {
                return trim($value);
            }
        }
        return 'Unknown Casino';
    }
    
    private function get_casino_name_field($row) {
        // Assume first non-empty field is casino name
        foreach ($row as $field => $value) {
            if (!empty(trim($value))) {
                return $field;
            }
        }
        return array_keys($row)[0];
    }
    
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
}