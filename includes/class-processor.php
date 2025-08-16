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
                        'casino' => $row['Casino Name'] ?? $row['Downtown Casino'] ?? $row[array_keys($row)[0]] ?? 'Unknown',
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
        // Get casino name from the correct CSV column
        $casino_name = $row['Casino Name'] ?? $row['Downtown Casino'] ?? $row[array_keys($row)[0]] ?? 'Unknown';
        $spreadsheet_location = $row['Location'] ?? $row['City'] ?? $row['Region'] ?? '';
        
        // Skip empty rows
        if (empty(trim($casino_name))) {
            return array(
                'casino' => 'Empty Row',
                'spreadsheet_location' => $sheet_name,
                'matching' => 'Skipped empty row',
                'action' => 'No casino name provided',
                'action_type' => 'skipped',
                'changes' => array(),
                'success' => true
            );
        }
        
        // Find matching casino
        $matches = $this->matcher->find_matches($casino_name, $spreadsheet_location);
        $best_match = !empty($matches) ? $matches[0] : null;
        
        $matching_info = $this->build_matching_info($matches, $casino_name, $spreadsheet_location);
        
        // Decide action based on strategy and matches
        if ($best_match && $best_match['similarity'] >= get_option('cdi_similarity_threshold', 70)) {
            // High confidence match - update existing casino
            return $this->update_existing_casino($best_match, $row, $sheet_name, $session_id, $matching_info);
            
        } elseif ($strategy === 'create_and_update') {
            // Create new casino or add to review queue
            if ($new_casino_action === 'auto_create') {
                return $this->create_new_casino($row, $sheet_name, $session_id, $matching_info);
            } else {
                return $this->add_to_review_queue($row, $sheet_name, $session_id, $matching_info, 'New casino requiring review');
            }
            
        } else {
            // Updates only strategy - skip new casinos
            return array(
                'casino' => $casino_name,
                'spreadsheet_location' => $spreadsheet_location,
                'matching' => $matching_info,
                'action' => 'Skipped - no matching casino found (updates-only mode)',
                'action_type' => 'skipped',
                'changes' => array(),
                'success' => true
            );
        }
    }
    
    /**
     * Update existing casino
     */
    private function update_existing_casino($match, $row, $sheet_name, $session_id, $matching_info) {
        $casino_id = $match['id'];
        $casino_name = $row['Casino Name'] ?? $row['Downtown Casino'] ?? $row[array_keys($row)[0]] ?? 'Unknown';
        $changes = array();
        
        try {
            // Map CSV fields to post meta
            $field_mappings = $this->get_field_mappings();
            
            foreach ($field_mappings as $csv_field => $meta_field) {
                if (!isset($row[$csv_field])) continue;
                
                $new_value = trim($row[$csv_field]);
                if (empty($new_value)) continue;
                
                $current_value = get_post_meta($casino_id, $meta_field, true);
                
                // Only update if value has changed
                if ($current_value !== $new_value) {
                    update_post_meta($casino_id, $meta_field, $new_value);
                    $changes[] = sprintf('%s: "%s" → "%s"', 
                                       $csv_field, 
                                       $current_value ?: '(empty)', 
                                       $new_value);
                }
            }
            
            // Handle bubble craps data and taxonomies  
            $this->update_casino_bubble_craps_data($casino_id, $row, $changes);
            
            // Update post content if comments provided
            if (isset($row['Comments']) && !empty($row['Comments'])) {
                $post = get_post($casino_id);
                if ($post && $post->post_content !== $row['Comments']) {
                    wp_update_post(array(
                        'ID' => $casino_id,
                        'post_content' => $row['Comments']
                    ));
                    $changes[] = 'Updated description/comments';
                }
            }
            
            // Log the update
            $this->log_import_action($session_id, 'updated', $casino_name, $casino_id, $sheet_name, $matching_info, $changes);
            
            $action_text = !empty($changes) ? 
                           sprintf('Updated %d fields', count($changes)) : 
                           'No changes needed';
            
            return array(
                'casino' => $casino_name,
                'spreadsheet_location' => $row['Location'] ?? $sheet_name,
                'matching' => $matching_info,
                'action' => $action_text,
                'action_type' => !empty($changes) ? 'updated' : 'skipped',
                'changes' => $changes,
                'success' => true,
                'casino_id' => $casino_id,
                'casino_url' => get_permalink($casino_id)
            );
            
        } catch (Exception $e) {
            // Log error
            $this->log_import_action($session_id, 'error', $casino_name, $casino_id, $sheet_name, $matching_info, array('Error: ' . $e->getMessage()));
            
            throw $e;
        }
    }
    
    /**
     * Create new casino
     */
    private function create_new_casino($row, $sheet_name, $session_id, $matching_info) {
        $casino_name = $row['Casino Name'] ?? $row['Downtown Casino'] ?? $row[array_keys($row)[0]] ?? 'Unknown';
        
        try {
            // Create new post
            $post_data = array(
                'post_title' => $casino_name,
                'post_content' => $row['Comments'] ?? $row['Description'] ?? '',
                'post_status' => 'publish',
                'post_type' => 'at_biz_dir',
                'post_author' => get_current_user_id()
            );
            
            $casino_id = wp_insert_post($post_data);
            
            if (is_wp_error($casino_id)) {
                throw new Exception('Failed to create post: ' . $casino_id->get_error_message());
            }
            
            // Set directory type meta
            update_post_meta($casino_id, '_directory_type', 'casino');
            
            // Add meta fields
            $field_mappings = $this->get_field_mappings();
            $changes = array('Created new casino listing');
            
            foreach ($field_mappings as $csv_field => $meta_field) {
                if (!isset($row[$csv_field])) continue;
                
                $value = trim($row[$csv_field]);
                if (!empty($value)) {
                    update_post_meta($casino_id, $meta_field, $value);
                    $changes[] = sprintf('%s: "%s"', $csv_field, $value);
                }
            }
            
            // Handle bubble craps data and taxonomies
            $this->update_casino_bubble_craps_data($casino_id, $row, $changes);
            
            // Set location taxonomy if available
            if (!empty($row['Location'])) {
                $this->set_casino_location($casino_id, $row['Location']);
            }
            
            // Log the creation
            $this->log_import_action($session_id, 'created', $casino_name, $casino_id, $sheet_name, $matching_info, $changes);
            
            return array(
                'casino' => $casino_name,
                'spreadsheet_location' => $row['Location'] ?? $sheet_name,
                'matching' => $matching_info,
                'action' => 'Created new listing',
                'action_type' => 'created',
                'changes' => $changes,
                'success' => true,
                'casino_id' => $casino_id,
                'casino_url' => get_permalink($casino_id)
            );
            
        } catch (Exception $e) {
            // Log error
            $this->log_import_action($session_id, 'error', $casino_name, 0, $sheet_name, $matching_info, array('Error: ' . $e->getMessage()));
            
            throw $e;
        }
    }
    
    /**
     * Add casino to review queue
     */
    private function add_to_review_queue($row, $sheet_name, $session_id, $matching_info, $reason) {
        global $wpdb;
        
        $casino_name = $row['Casino Name'] ?? $row['Downtown Casino'] ?? $row[array_keys($row)[0]] ?? 'Unknown';
        $location = $row['Location'] ?? $row['City'] ?? $row['Region'] ?? '';
        
        try {
            $queue_data = array(
                'casino_name' => $casino_name,
                'region' => $location,
                'spreadsheet_data' => wp_json_encode($row),
                'reason' => $reason,
                'status' => 'pending',
                'created_at' => current_time('mysql')
            );
            
            $result = $wpdb->insert(
                $wpdb->prefix . 'cdi_review_queue',
                $queue_data,
                array('%s', '%s', '%s', '%s', '%s', '%s')
            );
            
            if ($result === false) {
                throw new Exception('Failed to add item to review queue');
            }
            
            // Log the queue addition
            $this->log_import_action($session_id, 'queued', $casino_name, 0, $sheet_name, $matching_info, array('Added to review queue: ' . $reason));
            
            return array(
                'casino' => $casino_name,
                'spreadsheet_location' => $location,
                'matching' => $matching_info,
                'action' => 'Added to review queue',
                'action_type' => 'queued',
                'changes' => array('Reason: ' . $reason),
                'success' => true
            );
            
        } catch (Exception $e) {
            // Log error
            $this->log_import_action($session_id, 'error', $casino_name, 0, $sheet_name, $matching_info, array('Error: ' . $e->getMessage()));
            
            throw $e;
        }
    }
    
    /**
     * Build matching information string
     */
    private function build_matching_info($matches, $casino_name, $location) {
        if (empty($matches)) {
            return 'No matches found';
        }
        
        $best_match = $matches[0];
        $info = sprintf('%d%% match with "%s"', 
                       $best_match['similarity'], 
                       $best_match['title']);
        
        if (count($matches) > 1) {
            $info .= sprintf(' (+ %d other matches)', count($matches) - 1);
        }
        
        return $info;
    }
    
    /**
     * Get field mappings between CSV columns and Directorist meta fields - UPDATED for Craps data
     */
    private function get_field_mappings() {
        return array(
            // Standard fields
            'Phone' => '_phone',
            'Website' => '_website',
            'Address' => '_address',
            'Location' => '_location',
            'Comments' => '_notes',
            'Coordinates' => '_coordinates',
            'Last Update' => '_last_update',
            
            // Craps-specific bet minimums (radio fields)
            'WeekDay Min' => '_custom-radio-2',      // Weekday Minimum Bet – Craps Table
            'WeekNight Min' => '_custom-radio-7',    // Weeknight Minimum Bet – Craps Table  
            'Weekend Min' => '_custom-radio-8',      // Weekend Day Minimum Bet – Craps Table
            'Weekend Night Min' => '_custom-radio-9', // Weekend Night Minimum Bet – Craps Table
            
            // Other radio fields
            'Rewards' => '_custom-radio-5',          // Rewards Affiliation
            
            // Text fields  
            'Max Odds' => '_custom-text-4',          // Custom text field for odds
            'Field Pay' => '_custom-text-5',         // Custom text field for field pay
            'Dividers Per Side' => '_custom-text-6', // Custom text field
            'RTW Mins' => '_custom-text-7',          // Roll to Win minimums
            
            // Checkbox fields will be handled separately in update_casino_bubble_craps_data()
            // 'Bubble Craps' => '_custom-checkbox',     // Bubble craps types
            // 'Sidebet' => '_custom-checkbox-2',        // Craps Table Sidebets
        );
    }
    
    /**
     * Update casino bubble craps data, categories, tags and custom fields
     */
    private function update_casino_bubble_craps_data($casino_id, $row, &$changes) {
        // Analyze bubble craps data from CSV
        $has_bubble_craps = false;
        $bubble_types = array();
        
        // Check Bubble Craps column
        if (isset($row['Bubble Craps']) && ($row['Bubble Craps'] === 'Yes' || $row['Bubble Craps'] === '1')) {
            $has_bubble_craps = true;
        }
        
        // Check Crapless column
        if (isset($row['Crapless']) && ($row['Crapless'] === 'Yes' || $row['Crapless'] === '1')) {
            $has_bubble_craps = true;
            $bubble_types[] = 'crapless';
        }
        
        // Check Roll To Win column  
        if (isset($row['Roll To Win']) && ($row['Roll To Win'] === 'Yes' || $row['Roll To Win'] === '1')) {
            $has_bubble_craps = true;
            $bubble_types[] = 'rtw';
        }
        
        // Update categories
        if ($has_bubble_craps) {
            $this->set_casino_category($casino_id, 'Has Bubble Craps');
            $changes[] = 'Set category: Has Bubble Craps';
        } else {
            $this->set_casino_category($casino_id, 'No Bubble Craps (or unknown)');
            $changes[] = 'Set category: No Bubble Craps';
        }
        
        // Update tags based on bubble craps types
        $this->update_casino_bubble_tags($casino_id, $bubble_types, $changes);
        
        // Update custom checkbox field for bubble craps types
        if (!empty($bubble_types)) {
            update_post_meta($casino_id, '_custom-checkbox', $bubble_types);
            $changes[] = 'Updated bubble craps types: ' . implode(', ', $bubble_types);
        } else {
            update_post_meta($casino_id, '_custom-checkbox', array('none'));
            $changes[] = 'Set bubble craps types: none';
        }
        
        // Update sidebets if provided
        if (isset($row['Sidebet']) && !empty(trim($row['Sidebet']))) {
            $sidebets = $this->parse_sidebet_value($row['Sidebet']);
            if (!empty($sidebets)) {
                update_post_meta($casino_id, '_custom-checkbox-2', $sidebets);
                $changes[] = 'Updated sidebets: ' . implode(', ', $sidebets);
            }
        }
    }
    
    /**
     * Set casino category (taxonomy)
     */
    private function set_casino_category($casino_id, $category_name) {
        $category_taxonomy = 'at_biz_dir-categories';
        
        if (!taxonomy_exists($category_taxonomy)) {
            return false;
        }
        
        $term = get_term_by('name', $category_name, $category_taxonomy);
        
        if (!$term) {
            // Create new category term
            $term_result = wp_insert_term($category_name, $category_taxonomy);
            if (!is_wp_error($term_result)) {
                $term_id = $term_result['term_id'];
                wp_set_post_terms($casino_id, array($term_id), $category_taxonomy);
            }
        } else {
            wp_set_post_terms($casino_id, array($term->term_id), $category_taxonomy);
        }
        
        return true;
    }
    
    /**
     * Update casino bubble craps tags
     */
    private function update_casino_bubble_tags($casino_id, $bubble_types, &$changes) {
        $tags_taxonomy = 'at_biz_dir-tags';
        
        if (!taxonomy_exists($tags_taxonomy)) {
            return;
        }
        
        // Remove existing bubble craps tags
        $existing_tags = wp_get_post_terms($casino_id, $tags_taxonomy, array('fields' => 'names'));
        $bubble_tag_patterns = array('Bubble Craps', 'Crapless', 'Roll to Win', 'Stadium');
        
        $tags_to_keep = array();
        if (!is_wp_error($existing_tags)) {
            foreach ($existing_tags as $tag) {
                $keep_tag = true;
                foreach ($bubble_tag_patterns as $pattern) {
                    if (stripos($tag, $pattern) !== false) {
                        $keep_tag = false;
                        break;
                    }
                }
                if ($keep_tag) {
                    $tags_to_keep[] = $tag;
                }
            }
        }
        
        // Add new bubble craps tags
        $new_tags = $tags_to_keep;
        
        if (empty($bubble_types)) {
            $new_tags[] = 'No Bubble Craps';
        } else {
            foreach ($bubble_types as $type) {
                switch ($type) {
                    case 'crapless':
                        $new_tags[] = 'Crapless Bubble Craps';
                        break;
                    case 'rtw':
                        $new_tags[] = 'Roll to Win';
                        break;
                    case 'stadium':
                        $new_tags[] = 'Stadium Bubble Craps';
                        break;
                    case 'single':
                        $new_tags[] = 'Single Bubble Machine';
                        break;
                }
            }
        }
        
        // Set the updated tags
        wp_set_post_terms($casino_id, $new_tags, $tags_taxonomy);
        $changes[] = 'Updated tags: ' . implode(', ', $new_tags);
    }
    
    /**
     * Parse sidebet value into array of sidebet options
     */
    private function parse_sidebet_value($sidebet_string) {
        if (empty($sidebet_string)) return array();
        
        $sidebets = array();
        $sidebet_string = strtolower(trim($sidebet_string));
        
        // Map common sidebet indicators to standard values
        $sidebet_map = array(
            'fire' => 'Fire Bet',
            'small' => 'All Small',
            'tall' => 'All Tall', 
            'make' => 'Make \'Em All',
            'sharp' => 'Sharp Shooter',
            'repeat' => 'Repeater Bets'
        );
        
        foreach ($sidebet_map as $indicator => $sidebet_name) {
            if (strpos($sidebet_string, $indicator) !== false) {
                $sidebets[] = $sidebet_name;
            }
        }
        
        // If no specific sidebets detected but value exists, mark as "Other"
        if (empty($sidebets) && !empty($sidebet_string) && $sidebet_string !== 'no' && $sidebet_string !== 'none') {
            $sidebets[] = 'Other';
        }
        
        return array_unique($sidebets);
    }
    
    /**
     * Set casino location taxonomy
     */
    private function set_casino_location($casino_id, $location) {
        // Try to find or create location term
        $location_taxonomy = 'at_biz_dir-location';
        
        if (!taxonomy_exists($location_taxonomy)) {
            return; // Taxonomy doesn't exist
        }
        
        $term = get_term_by('name', $location, $location_taxonomy);
        
        if (!$term) {
            // Create new location term
            $term_result = wp_insert_term($location, $location_taxonomy);
            if (!is_wp_error($term_result)) {
                $term_id = $term_result['term_id'];
                wp_set_post_terms($casino_id, array($term_id), $location_taxonomy);
            }
        } else {
            wp_set_post_terms($casino_id, array($term->term_id), $location_taxonomy);
        }
    }
    
    /**
     * Log import action
     */
    private function log_import_action($session_id, $action_type, $casino_name, $casino_id, $sheet_name, $matching_info, $changes) {
        global $wpdb;
        
        $log_data = array(
            'import_session' => $session_id,
            'action_type' => $action_type,
            'casino_name' => $casino_name,
            'casino_id' => $casino_id > 0 ? $casino_id : null,
            'spreadsheet_location' => $sheet_name,
            'matching_info' => $matching_info,
            'changes_made' => is_array($changes) ? wp_json_encode($changes) : $changes,
            'status' => 'completed',
            'created_at' => current_time('mysql')
        );
        
        $wpdb->insert(
            $wpdb->prefix . 'cdi_import_logs',
            $log_data,
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
        );
    }
    
    /**
     * Get review queue items
     */
    public function get_review_queue($status = 'pending', $limit = 100) {
        global $wpdb;
        
        $sql = "SELECT * FROM {$wpdb->prefix}cdi_review_queue 
                WHERE status = %s 
                ORDER BY created_at DESC 
                LIMIT %d";
        
        return $wpdb->get_results($wpdb->prepare($sql, $status, $limit));
    }
    
    /**
     * Process review queue item
     */
    public function process_review_item($item_id, $action) {
        global $wpdb;
        
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}cdi_review_queue WHERE id = %d",
            $item_id
        ));
        
        if (!$item) {
            return array(
                'success' => false,
                'error' => __('Review item not found.', 'craps-data-importer')
            );
        }
        
        $spreadsheet_data = json_decode($item->spreadsheet_data, true);
        
        try {
            if ($action === 'approve') {
                // Create new casino
                $result = $this->create_new_casino($spreadsheet_data, 'Review Queue', 'manual_review');
                $message = sprintf(__('Casino "%s" has been created successfully.', 'craps-data-importer'), $item->casino_name);
                
            } elseif (strpos($action, 'link_') === 0) {
                // Link to existing casino
                $casino_id = intval(str_replace('link_', '', $action));
                $result = $this->update_existing_casino(
                    array('id' => $casino_id, 'title' => get_the_title($casino_id), 'similarity' => 100),
                    $spreadsheet_data,
                    'Review Queue',
                    'manual_review',
                    'Manual link from review queue'
                );
                $message = sprintf(__('Data has been linked to existing casino "%s".', 'craps-data-importer'), get_the_title($casino_id));
                
            } elseif ($action === 'reject') {
                $message = sprintf(__('Casino "%s" has been rejected and removed from queue.', 'craps-data-importer'), $item->casino_name);
                
            } else {
                throw new Exception(__('Invalid action specified.', 'craps-data-importer'));
            }
            
            // Update queue item status
            $wpdb->update(
                $wpdb->prefix . 'cdi_review_queue',
                array(
                    'status' => $action === 'reject' ? 'rejected' : 'processed',
                    'processed_at' => current_time('mysql'),
                    'assigned_casino_id' => isset($result['casino_id']) ? $result['casino_id'] : null
                ),
                array('id' => $item_id),
                array('%s', '%s', '%d'),
                array('%d')
            );
            
            return array(
                'success' => true,
                'message' => $message
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Clear review queue
     */
    public function clear_review_queue() {
        global $wpdb;
        
        $wpdb->query("DELETE FROM {$wpdb->prefix}cdi_review_queue");
    }
    
    /**
     * Get import logs
     */
    public function get_import_logs($session_id = null, $limit = 100) {
        global $wpdb;
        
        $where = '';
        $params = array();
        
        if ($session_id) {
            $where = 'WHERE import_session = %s';
            $params[] = $session_id;
        }
        
        $sql = "SELECT * FROM {$wpdb->prefix}cdi_import_logs $where ORDER BY created_at DESC LIMIT %d";
        $params[] = $limit;
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    /**
     * Get import statistics
     */
    public function get_import_statistics($days = 30) {
        global $wpdb;
        
        $since = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        $stats = $wpdb->get_results($wpdb->prepare("
            SELECT 
                action_type,
                COUNT(*) as count,
                DATE(created_at) as date
            FROM {$wpdb->prefix}cdi_import_logs 
            WHERE created_at >= %s
            GROUP BY action_type, DATE(created_at)
            ORDER BY created_at DESC
        ", $since));
        
        $summary = array(
            'total' => 0,
            'updated' => 0,
            'created' => 0,
            'queued' => 0,
            'errors' => 0,
            'by_date' => array()
        );
        
        foreach ($stats as $stat) {
            $summary['total'] += $stat->count;
            $summary[$stat->action_type] = ($summary[$stat->action_type] ?? 0) + $stat->count;
            
            if (!isset($summary['by_date'][$stat->date])) {
                $summary['by_date'][$stat->date] = array();
            }
            $summary['by_date'][$stat->date][$stat->action_type] = $stat->count;
        }
        
        return $summary;
    }
    
    /**
     * Send completion notification
     */
    private function send_completion_notification($results) {
        $email = get_option('cdi_notification_email');
        if (empty($email)) {
            return;
        }
        
        $subject = sprintf(__('Craps Data Import Complete - %d items processed', 'craps-data-importer'), 
                          array_sum(array($results['updated'], $results['created'], $results['queued'], $results['skipped'])));
        
        $message = sprintf(__("Import session %s has completed.\n\nResults:\n", 'craps-data-importer'), $results['session_id']);
        $message .= sprintf(__("- Updated: %d\n", 'craps-data-importer'), $results['updated']);
        $message .= sprintf(__("- Created: %d\n", 'craps-data-importer'), $results['created']);
        $message .= sprintf(__("- Queued for review: %d\n", 'craps-data-importer'), $results['queued']);
        $message .= sprintf(__("- Skipped: %d\n", 'craps-data-importer'), $results['skipped']);
        
        if ($results['errors'] > 0) {
            $message .= sprintf(__("- Errors: %d\n", 'craps-data-importer'), $results['errors']);
        }
        
        if ($results['queued'] > 0) {
            $message .= sprintf(__("\nPlease review queued items at: %s", 'craps-data-importer'), 
                               admin_url('admin.php?page=craps-review-queue'));
        }
        
        wp_mail($email, $subject, $message);
    }
    
    /**
     * Cleanup old data
     */
    public function cleanup_old_data($days = 30) {
        global $wpdb;
        
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // Clean old logs
        $deleted_logs = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}cdi_import_logs WHERE created_at < %s",
            $cutoff_date
        ));
        
        // Clean old processed review queue items
        $deleted_queue = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}cdi_review_queue WHERE status != 'pending' AND processed_at < %s",
            $cutoff_date
        ));
        
        return array(
            'logs_deleted' => $deleted_logs,
            'queue_items_deleted' => $deleted_queue
        );
    }
}