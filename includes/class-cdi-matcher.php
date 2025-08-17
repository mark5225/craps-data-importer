<?php
/**
 * CDI_Matcher - Casino matching functionality
 */

if (!defined('ABSPATH')) {
    exit;
}

class CDI_Matcher {
    
    /**
     * Search for casinos by name
     */
    public function search_casinos($search_term) {
        if (empty($search_term)) {
            return array();
        }
        
        $args = array(
            'post_type' => 'at_biz_dir',
            'post_status' => 'publish',
            's' => $search_term,
            'posts_per_page' => 20,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_category',
                    'value' => 'casino',
                    'compare' => 'LIKE'
                ),
                array(
                    'key' => '_listing_category',
                    'value' => 'casino',
                    'compare' => 'LIKE'
                )
            )
        );
        
        $query = new WP_Query($args);
        $results = array();
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $post_id = get_the_ID();
                
                $results[] = array(
                    'id' => $post_id,
                    'title' => get_the_title(),
                    'permalink' => get_permalink(),
                    'location' => get_post_meta($post_id, '_location', true),
                    'address' => get_post_meta($post_id, '_address', true)
                );
            }
            wp_reset_postdata();
        }
        
        return $results;
    }
    
    /**
     * Find casino by name with enhanced fuzzy matching (from your original script)
     */
    public function find_casino_by_name($casino_name, $location_hint = '') {
        if (empty($casino_name)) {
            return null;
        }
        
        // Use the enhanced matching logic from your original script
        $match_result = $this->find_existing_casino_with_debug($casino_name, $location_hint);
        
        if ($match_result['post']) {
            return $match_result['post']->ID;
        }
        
        return null;
    }
    
    /**
     * Enhanced fuzzy matching with debug info and location context (from your original script)
     */
    private function find_existing_casino_with_debug($casino_name, $location_hint = '') {
        $debug_info = array(
            'original_name' => $casino_name,
            'location_hint' => $location_hint,
            'search_attempts' => array(),
            'best_match' => null,
            'similarity_score' => 0,
            'match_method' => 'none'
        );
        
        // Create search variations with better normalization
        $search_variations = array(
            $casino_name,
            $this->clean_casino_name($casino_name),
            str_replace(array('&', 'and'), '', $casino_name),
            preg_replace('/\s+/', ' ', trim($casino_name)),
            str_replace(array('Casino', 'Hotel', 'Resort'), '', $casino_name),
            preg_replace('/[^\w\s]/', '', $casino_name) // Remove special characters
        );
        
        // Add location-specific variations if we have location context
        if (!empty($location_hint)) {
            $search_variations[] = $casino_name . ' ' . $location_hint;
            $search_variations[] = $this->clean_casino_name($casino_name) . ' ' . $location_hint;
        }
        
        // Remove duplicates and empty strings
        $search_variations = array_unique(array_filter($search_variations));
        
        $best_match = null;
        $best_similarity = 0;
        $match_method = 'none';
        $location_boost_applied = false;
        
        foreach ($search_variations as $index => $search_term) {
            if (empty($search_term)) continue;
            
            $debug_info['search_attempts'][] = array(
                'variation' => $search_term,
                'method' => 'exact_title',
                'results' => 0
            );
            
            // 1. Try exact title match first
            $posts = get_posts(array(
                'post_type' => 'at_biz_dir',
                'post_status' => 'publish',
                'title' => $search_term,
                'numberposts' => 1
            ));
            
            if (!empty($posts)) {
                $debug_info['search_attempts'][count($debug_info['search_attempts']) - 1]['results'] = 1;
                $debug_info['best_match'] = $posts[0];
                $debug_info['similarity_score'] = 100;
                $debug_info['match_method'] = 'exact_title';
                return array(
                    'post' => $posts[0],
                    'debug' => $debug_info,
                    'matching_info' => "Exact title match: '{$posts[0]->post_title}' (100% similarity)"
                );
            }
            
            // 2. Try fuzzy search with similarity scoring
            $posts = get_posts(array(
                'post_type' => 'at_biz_dir',
                'post_status' => 'publish',
                's' => $search_term,
                'numberposts' => 30 // Increased for better location matching
            ));
            
            $debug_info['search_attempts'][count($debug_info['search_attempts']) - 1]['results'] = count($posts);
            
            foreach ($posts as $post) {
                // Calculate similarity percentage
                $similarity = 0;
                similar_text(strtolower($casino_name), strtolower($post->post_title), $similarity);
                
                // Also try similarity with cleaned names
                $clean_original = $this->clean_casino_name($casino_name);
                $clean_found = $this->clean_casino_name($post->post_title);
                $clean_similarity = 0;
                similar_text(strtolower($clean_original), strtolower($clean_found), $clean_similarity);
                
                // Use the higher similarity score
                $final_similarity = max($similarity, $clean_similarity);
                
                // LOCATION CONTEXT BOOST: If we have location hint and post matches location, boost similarity
                if (!empty($location_hint) && $final_similarity >= 60) {
                    // Get post location/terms to check for location match
                    $post_locations = wp_get_post_terms($post->ID, 'at_biz_dir-location', array('fields' => 'names'));
                    $post_content_lower = strtolower($post->post_title . ' ' . $post->post_content);
                    $location_hint_lower = strtolower($location_hint);
                    
                    $location_match_found = false;
                    if (!empty($post_locations)) {
                        foreach ($post_locations as $post_location) {
                            if (stripos($post_location, $location_hint) !== false) {
                                $location_match_found = true;
                                break;
                            }
                        }
                    }
                    
                    // Also check if location hint appears in post content
                    if (!$location_match_found && stripos($post_content_lower, $location_hint_lower) !== false) {
                        $location_match_found = true;
                    }
                    
                    if ($location_match_found) {
                        $final_similarity = min(100, $final_similarity + 10); // 10% boost for location match
                        $location_boost_applied = true;
                    }
                }
                
                if ($final_similarity > $best_similarity && $final_similarity >= 70) { // Raised threshold
                    $best_similarity = $final_similarity;
                    $best_match = $post;
                    $match_method = ($clean_similarity > $similarity) ? 'fuzzy_cleaned' : 'fuzzy_original';
                    if ($location_boost_applied) {
                        $match_method .= '_location_boost';
                    }
                }
                
                // Also check for substring matches (useful for "Casino Name" vs "Casino Name Hotel")
                if (stripos($post->post_title, $search_term) !== false || 
                    stripos($search_term, $post->post_title) !== false) {
                    
                    $substring_similarity = min(
                        (strlen($search_term) / strlen($post->post_title)) * 100,
                        (strlen($post->post_title) / strlen($search_term)) * 100
                    );
                    
                    // Apply location boost to substring matches too
                    if (!empty($location_hint) && $substring_similarity >= 60) {
                        $post_locations = wp_get_post_terms($post->ID, 'at_biz_dir-location', array('fields' => 'names'));
                        if (!empty($post_locations)) {
                            foreach ($post_locations as $post_location) {
                                if (stripos($post_location, $location_hint) !== false) {
                                    $substring_similarity = min(100, $substring_similarity + 10);
                                    break;
                                }
                            }
                        }
                    }
                    
                    if ($substring_similarity > $best_similarity && $substring_similarity >= 70) {
                        $best_similarity = $substring_similarity;
                        $best_match = $post;
                        $match_method = 'substring';
                    }
                }
            }
        }
        
        // Update debug info with best match
        if ($best_match) {
            $debug_info['best_match'] = $best_match;
            $debug_info['similarity_score'] = round($best_similarity, 1);
            $debug_info['match_method'] = $match_method;
            
            // RAISED THRESHOLD: Only return matches above 70% to reduce false positives
            if ($best_similarity >= 70) {
                $location_text = $location_boost_applied ? ' (location verified)' : '';
                $matching_info = "Found: '{$best_match->post_title}' ({$debug_info['similarity_score']}% similarity via {$match_method}){$location_text}";
                return array(
                    'post' => $best_match,
                    'debug' => $debug_info,
                    'matching_info' => $matching_info
                );
            }
        }
        
        // No good match found
        $debug_info['similarity_score'] = $best_similarity > 0 ? round($best_similarity, 1) : 0;
        $debug_info['match_method'] = 'no_match';
        
        $best_sim_text = $best_similarity > 0 ? ", best similarity: " . round($best_similarity, 1) . "%" : "";
        
        return array(
            'post' => null,
            'debug' => $debug_info,
            'matching_info' => "No match found (searched " . count($search_variations) . " variations{$best_sim_text})"
        );
    }
    
    /**
     * Clean casino name for better matching
     */
    private function clean_casino_name($name) {
        // Remove apostrophes and various punctuation that cause matching issues
        $name = str_replace(array("'", "'", "`", '"'), '', $name);
        
        // Remove common casino suffixes that interfere with matching
        $name = str_replace(array(' Casino', ' Hotel', ' Resort', ' & Casino', ' Hotel & Casino', ' Casino & Hotel'), '', $name);
        
        // Normalize common words
        $name = str_replace(array('&'), 'and', $name);
        
        // Normalize spacing
        $name = preg_replace('/\s+/', ' ', trim($name));
        
        // Normalize common abbreviations
        $name = str_replace(array('St.', 'St '), 'Street ', $name);
        $name = str_replace(array('Ave.', 'Ave '), 'Avenue ', $name);
        
        return trim($name);
    }
    
    /**
     * Resolve a queue item
     */
    public function resolve_queue_item($queue_id, $action, $casino_id = null) {
        // This would interact with a queue system
        // For now, just return success
        
        cdi_log("Resolving queue item {$queue_id} with action {$action}" . ($casino_id ? " and casino ID {$casino_id}" : ""));
        
        return array(
            'success' => true,
            'message' => 'Queue item resolved successfully'
        );
    }
    
    /**
     * Get casino data for updating
     */
    public function get_casino_data($casino_id) {
        if (!cdi_validate_casino_id($casino_id)) {
            return null;
        }
        
        $post = get_post($casino_id);
        if (!$post) {
            return null;
        }
        
        // Get all meta data
        $meta_data = get_post_meta($casino_id);
        
        return array(
            'id' => $casino_id,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'meta' => $meta_data,
            'permalink' => get_permalink($casino_id)
        );
    }
    
    /**
     * Update casino with CSV data
     */
    public function update_casino_data($casino_id, $csv_row) {
        if (!cdi_validate_casino_id($casino_id)) {
            return false;
        }
        
        $updated_fields = array();
        
        // Map CSV columns to meta fields
        $field_mapping = array(
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
        
        foreach ($field_mapping as $csv_column => $meta_key) {
            if (isset($csv_row[$csv_column]) && !empty($csv_row[$csv_column])) {
                $old_value = get_post_meta($casino_id, $meta_key, true);
                $new_value = sanitize_text_field($csv_row[$csv_column]);
                
                if ($old_value !== $new_value) {
                    update_post_meta($casino_id, $meta_key, $new_value);
                    $updated_fields[] = $csv_column;
                    
                    cdi_log("Updated {$meta_key} for casino {$casino_id}: '{$old_value}' -> '{$new_value}'");
                }
            }
        }
        
        // Update last modified time
        update_post_meta($casino_id, '_cdi_last_updated', current_time('mysql'));
        
        return $updated_fields;
    }
}