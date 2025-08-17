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
     * Find casino by name with fuzzy matching
     */
    public function find_casino_by_name($casino_name) {
        if (empty($casino_name)) {
            return null;
        }
        
        // First try exact match
        $exact_match = $this->find_exact_casino_match($casino_name);
        if ($exact_match) {
            return $exact_match;
        }
        
        // Try fuzzy matching
        return $this->find_fuzzy_casino_match($casino_name);
    }
    
    /**
     * Find exact casino match
     */
    private function find_exact_casino_match($casino_name) {
        $args = array(
            'post_type' => 'at_biz_dir',
            'post_status' => 'publish',
            'title' => $casino_name,
            'posts_per_page' => 1
        );
        
        $query = new WP_Query($args);
        
        if ($query->have_posts()) {
            $post = $query->posts[0];
            wp_reset_postdata();
            return $post->ID;
        }
        
        return null;
    }
    
    /**
     * Find fuzzy casino match using similarity
     */
    private function find_fuzzy_casino_match($casino_name) {
        $args = array(
            'post_type' => 'at_biz_dir',
            'post_status' => 'publish',
            'posts_per_page' => 100,
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
        $best_match = null;
        $best_similarity = 0;
        $threshold = cdi_get_option('similarity_threshold', 80);
        
        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $title = get_the_title();
                
                $similarity = $this->calculate_similarity($casino_name, $title);
                
                if ($similarity > $threshold && $similarity > $best_similarity) {
                    $best_similarity = $similarity;
                    $best_match = get_the_ID();
                }
            }
            wp_reset_postdata();
        }
        
        return $best_match;
    }
    
    /**
     * Calculate similarity between two strings
     */
    private function calculate_similarity($str1, $str2) {
        $str1 = strtolower(trim($str1));
        $str2 = strtolower(trim($str2));
        
        // Remove common words that might interfere with matching
        $common_words = array('casino', 'hotel', 'resort', 'the', 'las', 'vegas');
        
        foreach ($common_words as $word) {
            $str1 = str_replace($word, '', $str1);
            $str2 = str_replace($word, '', $str2);
        }
        
        $str1 = trim(preg_replace('/\s+/', ' ', $str1));
        $str2 = trim(preg_replace('/\s+/', ' ', $str2));
        
        if (empty($str1) || empty($str2)) {
            return 0;
        }
        
        // Use PHP's similar_text function
        similar_text($str1, $str2, $percent);
        
        return $percent;
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