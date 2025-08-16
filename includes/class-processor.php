<?php
/**
 * Processor Class - Import processing and review queue management
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CDI_Processor {
    
    private $matcher;
    private $batch_size;
    
    /**
     * Constructor
     */
    public function __construct($matcher) {
        $this->matcher = $matcher;
        $this->batch_size = intval(get_option('cdi_batch_size', 50));
    }
    
    /**
     * Process import with detailed reporting
     */
    public function process_import($data, $selected_sheets, $strategy, $new_casino_action) {
        $session_id = 'import_' . uniqid();
        
        $results = array(
            'session_id' => $session_id,
            'updated' => 0,
            'created' => 0,
            'queued' => 0,
            'skipped' => 0,
            'errors' => 0,
            'details' => array()
        );
        
        foreach ($selected_sheets as $sheet_name) {
            if (!isset($data[$sheet_name]['data'])) continue;
            
            foreach ($data[$sheet_name]['data'] as $row) {
                try {
                    $detail = $this->process_single_casino($row, $sheet_name, $strategy, $new_casino_action, $session_id);
                    $results['details'][] = $detail;
                    $results[$detail['action_type']]++;
                    
                } catch (Exception $e) {
                    $error_detail = array(
                        'casino' => $row[array_keys($row)[0]] ?? 'Unknown',
                        'spreadsheet_location' => $sheet_name,
                        'matching' => 'Error occurred during processing',
                        'action' => 'Processing error: ' . $e->getMessage(),
                        'action_type' => 'errors',
                        'changes' => array('Error: ' . $e->getMessage()),
                        'success' => false
                    );
                    
                    $results['details'][] = $error_detail;
                    $results['errors']++;
                    
                    // Log error
                    error_log("CDI Processing Error: " . $e->getMessage());
                }
            }
        }
        
        // Send notification if configured
        $this->send_completion_notification($results);
        
        return $results;
    }
    
    /**
     * Process a single casino record
     */
    private function process_single_casino($row, $sheet_name, $strategy, $new_casino_action, $session_id) {
        $casino_name = $row[array_keys($row)[0]] ?? 'Unknown';
        $spreadsheet_location = $row['Location'] ?? $sheet_name;
        
        // Find existing casino
        $match_result = $this->matcher->find_casino($casino_name, $spreadsheet_location);
        $existing_casino = $match_result['post'];
        $matching_info = $match_result['matching_info'];
        
        $detail_record = array(
            'casino' => $casino_name,
            'spreadsheet_location' => $spreadsheet_location,
            'matching' => $matching_info,
            'changes' => array(),
            'success' => true
        );
        
        if ($existing_casino) {
            // Casino exists - check if changes are needed
            $changes_needed = $this->analyze_needed_changes($existing_casino, $row);
            
            if (empty($changes_needed)) {
                // No changes needed
                $detail_record['action'] = __('No changes needed', 'craps-data-importer');
                $detail_record['action_type'] = 'skipped';
                $detail_record['changes'] = array();
                
            } else {
                // Changes needed
                if ($strategy === 'review_queue') {
                    // Send to review queue
                    $this->add_to_review_queue($row, $sheet_name, 'Update existing: ' . $existing_casino->post_title, $existing_casino->ID);
                    $detail_record['action'] = __('Sent to review queue', 'craps-data-importer');
                    $detail_record['action_type'] = 'queued';
                    $detail_record['changes'] = $changes_needed;
                    
                } else {
                    // Apply updates directly
                    $applied_changes = $this->update_existing_casino($existing_casino, $row);
                    $detail_record['action'] = __('Updated existing casino', 'craps-data-importer');
                    $detail_record['action_type'] = 'updated';
                    $detail_record['changes'] = $applied_changes;
                    
                    // Log the action
                    CDI_Main::get_instance()->log_import_action(
                        $session_id,
                        'updated',
                        $casino_name,
                        array(
                            'casino_id' => $existing_casino->ID,
                            'location' => $spreadsheet_location,
                            'matching_info' => $matching_info,
                            'changes' => $applied_changes
                        )
                    );
                }
            }
            
        } else {
            // Casino doesn't exist
            if ($strategy === 'updates_only') {
                // Skip new casinos in updates-only mode
                $detail_record['action'] = __('Skipped (updates only mode)', 'craps-data-importer');
                $detail_record['action_type'] = 'skipped';
                $detail_record['changes'] = array();
                
            } elseif ($new_casino_action === 'auto_create') {
                // Create new casino
                $new_casino_id = $this->create_new_casino($row);
                if ($new_casino_id) {
                    $detail_record['action'] = sprintf(__('Created new casino (ID: %d)', 'craps-data-importer'), $new_casino_id);
                    $detail_record['action_type'] = 'created';
                    $detail_record['changes'] = array(__('New casino listing created', 'craps-data-importer'));
                    
                    // Update matching info
                    $new_post = get_post($new_casino_id);
                    if ($new_post) {
                        $detail_record['matching'] = sprintf(__("Created new listing: '%s' (ID: %d)", 'craps-data-importer'), $new_post->post_title, $new_casino_id);
                    }
                    
                    // Log the action
                    CDI_Main::get_instance()->log_import_action(
                        $session_id,
                        'created',
                        $casino_name,
                        array(
                            'casino_id' => $new_casino_id,
                            'location' => $spreadsheet_location,
                            'matching_info' => $detail_record['matching'],
                            'changes' => $detail_record['changes']
                        )
                    );
                    
                } else {
                    $detail_record['action'] = __('Failed to create casino', 'craps-data-importer');
                    $detail_record['action_type'] = 'errors';
                    $detail_record['changes'] = array(__('Error occurred during creation', 'craps-data-importer'));
                    $detail_record['success'] = false;
                }
                
            } elseif ($new_casino_action === 'skip') {
                // Skip new casinos
                $detail_record['action'] = __('Skipped (new casino)', 'craps-data-importer');
                $detail_record['action_type'] = 'skipped';
                $detail_record['changes'] = array();
                
            } else {
                // Send to review queue
                $this->add_to_review_queue($row, $sheet_name, __('New casino - requires approval', 'craps-data-importer'));
                $detail_record['action'] = __('Sent to review queue (new casino)', 'craps-data-importer');
                $detail_record['action_type'] = 'queued';
                $detail_record['changes'] = array(__('New casino requires manual review', 'craps-data-importer'));
            }
        }
        
        return $detail_record;
    }
    
    /**
     * Analyze what changes are needed for existing casino
     */
    private function analyze_needed_changes($existing_casino, $row) {
        $changes_needed = array();
        $post_id = $existing_casino->ID;
        
        $spreadsheet_bubble = $row['Bubble Craps'] ?? '';
        
        if ($spreadsheet_bubble === 'Yes') {
            // Should have bubble craps
            $current_categories = wp_get_post_terms($post_id, 'at_biz_dir-category', array('fields' => 'names'));
            if (is_wp_error($current_categories)) {
                $current_categories = array();
            }
            
            if (!in_array('Has Bubble Craps', $current_categories)) {
                $changes_needed[] = __('Add "Has Bubble Craps" category', 'craps-data-importer');
            }
            
            $current_types = get_post_meta($post_id, '_custom-checkbox', true);
            if (empty($current_types) || (is_array($current_types) && in_array('none', $current_types))) {
                $changes_needed[] = __('Set bubble craps type', 'craps-data-importer');
            }
            
            $spreadsheet_min_bet = $row['Min Bet'] ?? '';
            if ($spreadsheet_min_bet && $spreadsheet_min_bet !== 'Unknown') {
                $current_min_bet = get_post_meta($post_id, '_custom-radio-3', true);
                if (in_array($current_min_bet, array('N/A or Unknown', '', null), true)) {
                    $changes_needed[] = __('Set minimum bet', 'craps-data-importer');
                }
            }
            
        } elseif ($spreadsheet_bubble === 'No') {
            // Should not have bubble craps
            $current_categories = wp_get_post_terms($post_id, 'at_biz_dir-category', array('fields' => 'names'));
            if (is_wp_error($current_categories)) {
                $current_categories = array();
            }
            
            $has_no_bubble = in_array('No Bubble Craps (or unknown)', $current_categories) || 
                             in_array('No Bubble Craps', $current_categories);
            
            if (!$has_no_bubble) {
                $changes_needed[] = __('Set "No Bubble Craps" category', 'craps-data-importer');
            }
            
            $current_types = get_post_meta($post_id, '_custom-checkbox', true);
            if (!empty($current_types) && !in_array('none', $current_types)) {
                $changes_needed[] = __('Clear bubble craps data', 'craps-data-importer');
            }
        }
        
        // Check rewards program
        $spreadsheet_rewards = $row['Rewards'] ?? '';
        if ($spreadsheet_rewards && $spreadsheet_rewards !== 'Unknown') {
            $current_rewards = get_post_meta($post_id, '_custom-radio', true);
            if (in_array($current_rewards, array('Unknown', '', null), true)) {
                $changes_needed[] = __('Set rewards program', 'craps-data-importer');
            }
        }
        
        return $changes_needed;
    }
    
    /**
     * Update existing casino with community data
     */
    private function update_existing_casino($casino, $row) {
        $changes = array();
        $post_id = $casino->ID;
        
        $bubble_craps = $row['Bubble Craps'] ?? '';
        
        if ($bubble_craps === 'Yes') {
            // Set has bubble craps category
            $term = get_term_by('name', 'Has Bubble Craps', 'at_biz_dir-category');
            if ($term) {
                $result = wp_set_post_terms($post_id, array($term->term_id), 'at_biz_dir-category', false);
                if ($result && !is_wp_error($result)) {
                    $changes[] = __('Category: Has Bubble Craps', 'craps-data-importer');
                }
            }
            
            // Set bubble craps type if not set
            $current_types = get_post_meta($post_id, '_custom-checkbox', true);
            if (empty($current_types) || (is_array($current_types) && in_array('none', $current_types))) {
                update_post_meta($post_id, '_custom-checkbox', 'single');
                $changes[] = __('Bubble craps type: Single Machine', 'craps-data-importer');
            }
            
            // Set minimum bet if provided
            $min_bet = $row['Min Bet'] ?? '';
            if ($min_bet && $min_bet !== 'Unknown') {
                $current_min_bet = get_post_meta($post_id, '_custom-radio-3', true);
                if (in_array($current_min_bet, array('N/A or Unknown', '', null), true)) {
                    update_post_meta($post_id, '_custom-radio-3', $min_bet);
                    $changes[] = sprintf(__('Minimum bet: %s', 'craps-data-importer'), $min_bet);
                }
            }
            
        } elseif ($bubble_craps === 'No') {
            // Set no bubble craps category
            $term = get_term_by('name', 'No Bubble Craps (or unknown)', 'at_biz_dir-category');
            if (!$term) {
                $term = get_term_by('name', 'No Bubble Craps', 'at_biz_dir-category');
            }
            
            if ($term) {
                $result = wp_set_post_terms($post_id, array($term->term_id), 'at_biz_dir-category', false);
                if ($result && !is_wp_error($result)) {
                    $changes[] = __('Category: No Bubble Craps', 'craps-data-importer');
                }
            }
            
            // Clear bubble craps data
            $current_types = get_post_meta($post_id, '_custom-checkbox', true);
            if (!empty($current_types) && !in_array('none', $current_types)) {
                delete_post_meta($post_id, '_custom-checkbox');
                delete_post_meta($post_id, '_custom-radio-3');
                $changes[] = __('Cleared bubble craps data', 'craps-data-importer');
            }
        }
        
        // Update rewards program
        $rewards = $row['Rewards'] ?? '';
        if (!empty($rewards) && $rewards !== 'Unknown') {
            $current_rewards = get_post_meta($post_id, '_custom-radio', true);
            if (in_array($current_rewards, array('Unknown', '', null), true)) {
                update_post_meta($post_id, '_custom-radio', $rewards);
                $changes[] = sprintf(__('Rewards: %s', 'craps-data-importer'), $rewards);
            }
        }
        
        // Update metadata if changes were made
        if (!empty($changes)) {
            update_post_meta($post_id, '_cdi_last_update', current_time('mysql'));
            update_post_meta($post_id, '_cdi_data_source', 'community_spreadsheet');
            update_post_meta($post_id, '_custom-text-6', current_time('F j, Y')); // Last updated field
            $changes[] = __('Updated metadata', 'craps-data-importer');
        }
        
        return $changes;
    }
    
    /**
     * Create new casino from community data
     */
    private function create_new_casino($row) {
        $casino_name = $row[array_keys($row)[0]] ?? 'Unknown Casino';
        $location = $row['Location'] ?? '';
        
        $post_data = array(
            'post_title' => $casino_name,
            'post_content' => sprintf(__('Casino data imported from community spreadsheet. Location: %s', 'craps-data-importer'), $location),
            'post_status' => 'publish',
            'post_type' => 'at_biz_dir',
            'post_author' => get_current_user_id()
        );
        
        $post_id = wp_insert_post($post_data);
        
        if ($post_id && !is_wp_error($post_id)) {
            // Apply community data to new casino
            $this->update_existing_casino((object)array('ID' => $post_id), $row);
            
            // Set location if provided
            if (!empty($location)) {
                $location_term = get_term_by('name', $location, 'at_biz_dir-location');
                if (!$location_term) {
                    // Create location term if it doesn't exist
                    $location_term = wp_insert_term($location, 'at_biz_dir-location');
                    if (!is_wp_error($location_term)) {
                        $location_term = get_term($location_term['term_id']);
                    }
                }
                
                if ($location_term && !is_wp_error($location_term)) {
                    wp_set_post_terms($post_id, array($location_term->term_id), 'at_biz_dir-location');
                }
            }
            
            return $post_id;
        }
        
        return false;
    }
    
    /**
     * Add item to review queue
     */
    private function add_to_review_queue($row, $sheet_name, $reason = '', $existing_casino_id = null) {
        global $wpdb;
        
        $casino_name = $row[array_keys($row)[0]] ?? 'Unknown';
        $bubble_craps = $row['Bubble Craps'] ?? 'Unknown';
        $rewards = $row['Rewards'] ?? 'Unknown';
        $location = $row['Location'] ?? $sheet_name;
        
        $queue_data = array(
            'casino_name' => $casino_name,
            'region' => $location,
            'spreadsheet_data' => wp_json_encode($row),
            'reason' => $reason,
            'status' => 'pending',
            'assigned_casino_id' => $existing_casino_id
        );
        
        $result = $wpdb->insert(
            $wpdb->prefix . 'cdi_review_queue',
            $queue_data,
            array('%s', '%s', '%s', '%s', '%s', '%d')
        );
        
        return $result ? $wpdb->insert_id : false;
    }
    
    /**
     * Get review queue items
     */
    public function get_review_queue($status = 'pending', $limit = 50) {
        global $wpdb;
        
        $sql = "SELECT * FROM {$wpdb->prefix}cdi_review_queue WHERE status = %s ORDER BY created_at DESC LIMIT %d";
        return $wpdb->get_results($wpdb->prepare($sql, $status, $limit));
    }
    
    /**
     * Process review queue item
     */
    public function process_review_item($item_id, $action, $casino_id = null) {
        global $wpdb;
        
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cdi_review_queue WHERE id = %d",
            $item_id
        ));
        
        if (!$item) {
            return array('success' => false, 'message' => __('Review item not found', 'craps-data-importer'));
        }
        
        $row_data = json_decode($item->spreadsheet_data, true);
        
        switch ($action) {
            case 'approve_create':
                $new_id = $this->create_new_casino($row_data);
                if ($new_id) {
                    $this->mark_review_item_processed($item_id);
                    return array('success' => true, 'message' => sprintf(__('Created casino: %s (ID: %d)', 'craps-data-importer'), $item->casino_name, $new_id));
                } else {
                    return array('success' => false, 'message' => __('Failed to create casino', 'craps-data-importer'));
                }
                
            case 'approve_update':
                if ($item->assigned_casino_id) {
                    $existing = get_post($item->assigned_casino_id);
                } else {
                    $match_result = $this->matcher->find_casino($item->casino_name, $item->region);
                    $existing = $match_result['post'];
                }
                
                if ($existing) {
                    $changes = $this->update_existing_casino($existing, $row_data);
                    $this->mark_review_item_processed($item_id);
                    return array('success' => true, 'message' => sprintf(__('Updated casino: %s', 'craps-data-importer'), $item->casino_name));
                } else {
                    return array('success' => false, 'message' => __('Casino not found for update', 'craps-data-importer'));
                }
                
            case 'manual_link':
                if (!$casino_id) {
                    return array('success' => false, 'message' => __('No casino ID provided', 'craps-data-importer'));
                }
                
                $manual_post = get_post($casino_id);
                if ($manual_post && $manual_post->post_type === 'at_biz_dir') {
                    $changes = $this->update_existing_casino($manual_post, $row_data);
                    $this->mark_review_item_processed($item_id);
                    return array('success' => true, 'message' => sprintf(__('Linked and updated: %s → %s', 'craps-data-importer'), $item->casino_name, $manual_post->post_title));
                } else {
                    return array('success' => false, 'message' => __('Invalid casino ID', 'craps-data-importer'));
                }
                
            case 'reject':
                $this->mark_review_item_processed($item_id, 'rejected');
                return array('success' => true, 'message' => sprintf(__('Rejected: %s', 'craps-data-importer'), $item->casino_name));
                
            default:
                return array('success' => false, 'message' => __('Invalid action', 'craps-data-importer'));
        }
    }
    
    /**
     * Mark review item as processed
     */
    private function mark_review_item_processed($item_id, $status = 'processed') {
        global $wpdb;
        
        return $wpdb->update(
            $wpdb->prefix . 'cdi_review_queue',
            array(
                'status' => $status,
                'processed_at' => current_time('mysql')
            ),
            array('id' => $item_id),
            array('%s', '%s'),
            array('%d')
        );
    }
    
    /**
     * Clear review queue items
     */
    public function clear_review_queue($status = 'processed') {
        global $wpdb;
        
        return $wpdb->delete(
            $wpdb->prefix . 'cdi_review_queue',
            array('status' => $status),
            array('%s')
        );
    }
    
    /**
     * Send completion notification
     */
    private function send_completion_notification($results) {
        $email_enabled = get_option('cdi_notification_email');
        if (!$email_enabled) {
            return;
        }
        
        $total_processed = $results['updated'] + $results['created'] + $results['queued'] + $results['skipped'];
        
        $subject = sprintf(__('Craps Data Import Completed - %d Records Processed', 'craps-data-importer'), $total_processed);
        
        $message = sprintf(__("Import completed successfully!\n\nResults:\n- Updated: %d\n- Created: %d\n- Queued for Review: %d\n- Skipped (no changes): %d\n- Errors: %d", 'craps-data-importer'),
            $results['updated'],
            $results['created'],
            $results['queued'],
            $results['skipped'],
            $results['errors']
        );
        
        CDI_Main::get_instance()->send_notification($subject, $message, array(
            'session_id' => $results['session_id'],
            'total_processed' => $total_processed
        ));
    }
}