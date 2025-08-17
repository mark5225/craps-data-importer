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
     * Process the import based on form selections
     */
    public function process_import() {
        // Verify nonce
        if (!wp_verify_nonce($_POST['nonce'], 'cdi_nonce')) {
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
            if (empty($casino_name)) {
                $results['skipped']++;
                continue;
            }
            
            // Determine action for this row
            $action = 'update'; // Default action
            if (isset($row_actions[$index])) {
                $action = $row_actions[$index];
            }
            
            // Process based on action
            switch ($action) {
                case 'update':
                    $result = $this->process_casino_update($casino_name, $row, $settings);
                    break;
                case 'skip':
                    $results['skipped']++;
                    $results['details'][] = array(
                        'casino' => $casino_name,
                        'action' => 'Skipped',
                        'message' => 'Skipped by user selection'
                    );
                    continue 2;
                case 'review':
                    $result = $this->add_to_review_queue($casino_name, $row);
                    $results['queued']++;
                    break;
                default:
                    $results['errors']++;
                    continue 2;
            }
            
            // Add to results
            if ($result['success']) {
                if ($result['action'] === 'updated') {
                    $results['updated']++;
                } elseif ($result['action'] === 'created') {
                    $results['created']++;
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
        
        // Update fields
        $field_updates = $this->update_casino_fields($casino_post->ID, $csv_row);
        $changes_made = array_merge($changes_made, $field_updates);
        
        return array(
            'success' => true,
            'casino' => $casino_name,
            'action' => $action,
            'casino_id' => $casino_post->ID,
            'similarity' => $similarity,
            'changes' => $changes_made,
            'message' => sprintf('%s: %d changes made', ucfirst($action), count($changes_made))
        );
    }
    
    /**
     * Update casino fields from CSV data
     */
    private function update_casino_fields($casino_id, $csv_row) {
        $changes = array();
        
        // Field mapping from CSV to WordPress meta
        $field_mapping = array(
            'Bubble Craps' => 'bubble_craps_minimum',
            'WeekDay Min' => 'weekday_minimum',
            'WeekNight Min' => 'weeknight_minimum',
            'WeekendMin' => 'weekend_minimum',
            'WeekendnightMin' => 'weekend_night_minimum',
            'Rewards' => 'rewards_program',
            'Sidebet' => 'side_bets'
        );
        
        foreach ($field_mapping as $csv_field => $meta_key) {
            if (isset($csv_row[$csv_field]) && !empty(trim($csv_row[$csv_field]))) {
                $new_value = trim($csv_row[$csv_field]);
                $current_value = get_post_meta($casino_id, $meta_key, true);
                
                // Only update if different
                if ($current_value !== $new_value) {
                    update_post_meta($casino_id, $meta_key, $new_value);
                    $changes[] = sprintf('Updated %s: "%s" → "%s"', 
                        $this->get_field_display_name($csv_field), 
                        $current_value ? $current_value : 'Empty',
                        $new_value
                    );
                }
            }
        }
        
        // Handle Bubble Craps status categories
        if (isset($csv_row['Bubble Craps'])) {
            $bc_value = trim($csv_row['Bubble Craps']);
            $category_changes = $this->update_bubble_craps_categories($casino_id, $bc_value);
            $changes = array_merge($changes, $category_changes);
        }
        
        return $changes;
    }
    
    /**
     * Update Bubble Craps categories based on CSV value
     */
    private function update_bubble_craps_categories($casino_id, $bc_value) {
        $changes = array();
        
        // Determine if casino has bubble craps
        $has_bubble_craps = !empty($bc_value) && 
                           strtolower($bc_value) !== 'no' && 
                           strtolower($bc_value) !== 'none' &&
                           strtolower($bc_value) !== 'removed';
        
        // Get current categories
        $current_categories = wp_get_post_terms($casino_id, 'at_biz_dir-categories', array('fields' => 'slugs'));
        if (is_wp_error($current_categories)) {
            $current_categories = array();
        }
        
        $new_categories = $current_categories;
        
        // Remove existing bubble craps categories
        $bc_categories = array('has-bubble-craps', 'no-bubble-craps');
        $new_categories = array_diff($new_categories, $bc_categories);
        
        // Add appropriate bubble craps category
        if ($has_bubble_craps) {
            $new_categories[] = 'has-bubble-craps';
            $changes[] = 'Added "Has Bubble Craps" category';
        } else {
            $new_categories[] = 'no-bubble-craps';
            $changes[] = 'Added "No Bubble Craps" category';
        }
        
        // Update categories
        $result = wp_set_post_terms($casino_id, $new_categories, 'at_biz_dir-categories');
        if (is_wp_error($result)) {
            error_log('CDI: Failed to update categories for casino ' . $casino_id . ': ' . $result->get_error_message());
        }
        
        return $changes;
    }
    
    /**
     * Create new casino post
     */
    private function create_new_casino($casino_name, $csv_row) {
        $post_data = array(
            'post_title' => $casino_name,
            'post_content' => 'Auto-imported casino from CSV data.',
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
                'status' => 'pending',
                'created_date' => current_time('mysql')
            ),
            array('%s', '%s', '%s', '%s')
        );
        
        if ($result === false) {
            return array(
                'success' => false,
                'casino' => $casino_name,
                'action' => 'error',
                'message' => 'Failed to add to review queue'
            );
        }
        
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
                'filename' => 'CSV Import',
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
     * Helper methods (same as admin class)
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