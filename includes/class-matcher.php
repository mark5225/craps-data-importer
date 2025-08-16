<?php
/**
 * Matcher Class - Fuzzy matching and casino identification
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CDI_Matcher {
    
    private $similarity_threshold;
    private $location_boost;
    private $cache = array();
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->similarity_threshold = intval(get_option('cdi_similarity_threshold', 70));
        $this->location_boost = intval(get_option('cdi_location_boost', 10));
    }
    
    /**
     * Find matching casinos for a given name and location
     */
    public function find_matches($casino_name, $location = '') {
        $cache_key = md5($casino_name . '|' . $location);
        
        if (isset($this->cache[$cache_key])) {
            return $this->cache[$cache_key];
        }
        
        $matches = array();
        
        // Get all published casino posts
        $args = array(
            'post_type' => cdi_get_casino_post_type(), // Use dynamic post type
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'meta_query' => array(
                array(
                    'key' => '_directory_type',
                    'value' => 'casino',
                    'compare' => 'LIKE'
                )
            )
        );
        
        $casino_posts = get_posts($args);
        
        foreach ($casino_posts as $post) {
            $similarity_data = $this->calculate_similarity($casino_name, $location, $post);
            
            if ($similarity_data['score'] >= $this->similarity_threshold) {
                $matches[] = array(
                    'id' => $post->ID,
                    'title' => $post->post_title,
                    'location' => $this->get_casino_location($post->ID),
                    'similarity' => $similarity_data['score'],
                    'match_details' => $similarity_data['details'],
                    'url' => get_permalink($post->ID)
                );
            }
        }
        
        // Sort by similarity score (highest first)
        usort($matches, function($a, $b) {
            return $b['similarity'] - $a['similarity'];
        });
        
        $this->cache[$cache_key] = $matches;
        return $matches;
    }
    
    /**
     * Calculate similarity between spreadsheet data and existing casino
     */
    private function calculate_similarity($casino_name, $location, $post) {
        $details = array();
        $scores = array();
        
        // Name similarity (most important factor)
        $name_similarity = $this->string_similarity($casino_name, $post->post_title);
        $scores['name'] = $name_similarity;
        $details[] = sprintf('Name match: %d%% ("%s" vs "%s")', 
                           $name_similarity, 
                           $casino_name, 
                           $post->post_title);
        
        // Location similarity
        if (!empty($location)) {
            $post_location = $this->get_casino_location($post->ID);
            if (!empty($post_location)) {
                $location_similarity = $this->location_similarity($location, $post_location);
                $scores['location'] = $location_similarity;
                $details[] = sprintf('Location match: %d%% ("%s" vs "%s")', 
                               $location_similarity, 
                               $location, 
                               $post_location);
            }
        }
        
        // Calculate weighted average
        $weighted_score = $scores['name'] * 0.7; // Name is 70% of the score
        
        if (isset($scores['location'])) {
            $weighted_score = ($scores['name'] * 0.6) + ($scores['location'] * 0.4);
            
            // Apply location boost if both locations match well
            if ($scores['location'] > 80) {
                $weighted_score += $this->location_boost;
                $details[] = sprintf('Location boost applied: +%d%%', $this->location_boost);
            }
        }
        
        // Additional matching factors
        $additional_factors = $this->check_additional_factors($casino_name, $location, $post);
        if (!empty($additional_factors)) {
            $weighted_score += $additional_factors['boost'];
            $details = array_merge($details, $additional_factors['details']);
        }
        
        // Ensure score doesn't exceed 100
        $final_score = min(100, round($weighted_score));
        
        return array(
            'score' => $final_score,
            'details' => $details,
            'factors' => $scores
        );
    }
    
    /**
     * Calculate string similarity using multiple algorithms
     */
    private function string_similarity($str1, $str2) {
        if (empty($str1) || empty($str2)) {
            return 0;
        }
        
        // Normalize strings
        $str1 = $this->normalize_string($str1);
        $str2 = $this->normalize_string($str2);
        
        // Exact match
        if ($str1 === $str2) {
            return 100;
        }
        
        // Calculate multiple similarity metrics
        $similarities = array();
        
        // Levenshtein distance (converted to percentage)
        $levenshtein = levenshtein($str1, $str2);
        $max_len = max(strlen($str1), strlen($str2));
        if ($max_len > 0) {
            $similarities[] = (1 - ($levenshtein / $max_len)) * 100;
        }
        
        // Similar text percentage
        similar_text($str1, $str2, $percent);
        $similarities[] = $percent;
        
        // Jaro-Winkler similarity (if available)
        if (function_exists('jaro_winkler')) {
            $similarities[] = jaro_winkler($str1, $str2) * 100;
        }
        
        // Soundex comparison
        if (soundex($str1) === soundex($str2)) {
            $similarities[] = 85; // Boost for phonetic similarity
        }
        
        // Word-based similarity
        $word_similarity = $this->word_similarity($str1, $str2);
        if ($word_similarity > 0) {
            $similarities[] = $word_similarity;
        }
        
        // Return the highest similarity score
        return !empty($similarities) ? max($similarities) : 0;
    }
    
    /**
     * Calculate location similarity
     */
    private function location_similarity($loc1, $loc2) {
        if (empty($loc1) || empty($loc2)) {
            return 0;
        }
        
        $loc1 = $this->normalize_location($loc1);
        $loc2 = $this->normalize_location($loc2);
        
        // Exact match
        if ($loc1 === $loc2) {
            return 100;
        }
        
        // Check if one location contains the other
        if (strpos($loc1, $loc2) !== false || strpos($loc2, $loc1) !== false) {
            return 90;
        }
        
        // Split into components and check overlap
        $parts1 = array_filter(explode(' ', $loc1));
        $parts2 = array_filter(explode(' ', $loc2));
        
        $common_parts = array_intersect($parts1, $parts2);
        $total_parts = array_unique(array_merge($parts1, $parts2));
        
        if (!empty($total_parts)) {
            $overlap_percentage = (count($common_parts) / count($total_parts)) * 100;
            return $overlap_percentage;
        }
        
        // Fallback to string similarity
        return $this->string_similarity($loc1, $loc2);
    }
    
    /**
     * Word-based similarity calculation
     */
    private function word_similarity($str1, $str2) {
        $words1 = array_filter(explode(' ', $str1));
        $words2 = array_filter(explode(' ', $str2));
        
        if (empty($words1) || empty($words2)) {
            return 0;
        }
        
        $common_words = array_intersect($words1, $words2);
        $total_words = array_unique(array_merge($words1, $words2));
        
        return (count($common_words) / count($total_words)) * 100;
    }
    
    /**
     * Check additional matching factors
     */
    private function check_additional_factors($casino_name, $location, $post) {
        $boost = 0;
        $details = array();
        
        // Check for common casino name patterns
        $casino_patterns = array(
            'casino' => array('casino', 'gaming', 'resort'),
            'hotel' => array('hotel', 'inn', 'lodge'),
            'river' => array('riverboat', 'river', 'boat'),
            'tribal' => array('tribal', 'nation', 'tribe')
        );
        
        foreach ($casino_patterns as $pattern_type => $patterns) {
            $name_has_pattern = false;
            $post_has_pattern = false;
            
            foreach ($patterns as $pattern) {
                if (stripos($casino_name, $pattern) !== false) {
                    $name_has_pattern = true;
                }
                if (stripos($post->post_title, $pattern) !== false) {
                    $post_has_pattern = true;
                }
            }
            
            if ($name_has_pattern && $post_has_pattern) {
                $boost += 5;
                $details[] = sprintf('Pattern match: %s (+5%%)', $pattern_type);
            }
        }
        
        // Check meta fields for additional matches
        $meta_matches = $this->check_meta_field_matches($casino_name, $location, $post->ID);
        if ($meta_matches['boost'] > 0) {
            $boost += $meta_matches['boost'];
            $details = array_merge($details, $meta_matches['details']);
        }
        
        return array(
            'boost' => $boost,
            'details' => $details
        );
    }
    
    /**
     * Check meta field matches
     */
    private function check_meta_field_matches($casino_name, $location, $post_id) {
        $boost = 0;
        $details = array();
        
        // Check alternative names
        $alt_names = get_post_meta($post_id, '_alternative_names', true);
        if (!empty($alt_names)) {
            $alt_names_array = explode(',', $alt_names);
            foreach ($alt_names_array as $alt_name) {
                $alt_similarity = $this->string_similarity($casino_name, trim($alt_name));
                if ($alt_similarity > 80) {
                    $boost += 10;
                    $details[] = sprintf('Alternative name match: %d%% ("%s")', $alt_similarity, trim($alt_name));
                    break;
                }
            }
        }
        
        // Check address components
        $address = get_post_meta($post_id, '_address', true);
        if (!empty($address) && !empty($location)) {
            $address_similarity = $this->location_similarity($location, $address);
            if ($address_similarity > 70) {
                $boost += 5;
                $details[] = sprintf('Address match: %d%%', $address_similarity);
            }
        }
        
        return array(
            'boost' => $boost,
            'details' => $details
        );
    }
    
    /**
     * Normalize string for comparison
     */
    private function normalize_string($str) {
        // Convert to lowercase
        $str = strtolower($str);
        
        // Remove common casino suffixes/prefixes
        $remove_patterns = array(
            'casino', 'resort', 'hotel', 'gaming', 'the ', ' the',
            'riverboat', 'river', 'tribal', 'nation'
        );
        
        foreach ($remove_patterns as $pattern) {
            $str = str_replace($pattern, '', $str);
        }
        
        // Remove special characters
        $str = preg_replace('/[^\w\s]/', '', $str);
        
        // Normalize whitespace
        $str = preg_replace('/\s+/', ' ', $str);
        
        return trim($str);
    }
    
    /**
     * Normalize location string
     */
    private function normalize_location($location) {
        // Convert to lowercase
        $location = strtolower($location);
        
        // Remove common location words
        $remove_words = array('city', 'county', 'state', 'province');
        foreach ($remove_words as $word) {
            $location = str_replace($word, '', $location);
        }
        
        // Remove special characters except commas
        $location = preg_replace('/[^\w\s,]/', '', $location);
        
        // Normalize whitespace
        $location = preg_replace('/\s+/', ' ', $location);
        
        return trim($location);
    }
    
    /**
     * Get casino location from post meta
     */
    private function get_casino_location($post_id) {
        // Try different location meta fields
        $location_fields = array('_location', '_city', '_state', '_region', '_address');
        
        foreach ($location_fields as $field) {
            $location = get_post_meta($post_id, $field, true);
            if (!empty($location)) {
                return $location;
            }
        }
        
        // Fallback to taxonomies
        $terms = wp_get_post_terms($post_id, array('at_biz_dir-location', 'at_biz_dir-region'));
        if (!empty($terms) && !is_wp_error($terms)) {
            $location_names = array();
            foreach ($terms as $term) {
                $location_names[] = $term->name;
            }
            return implode(', ', $location_names);
        }
        
        return '';
    }
    
    /**
     * Search casinos by query (for AJAX)
     */
    public function search_casinos($query, $limit = 10) {
        $args = array(
            'post_type' => cdi_get_casino_post_type(), // Use dynamic post type
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            's' => $query,
            'meta_query' => array(
                array(
                    'key' => '_directory_type',
                    'value' => 'casino',
                    'compare' => 'LIKE'
                )
            )
        );
        
        $posts = get_posts($args);
        $results = array();
        
        foreach ($posts as $post) {
            $results[] = array(
                'id' => $post->ID,
                'title' => $post->post_title,
                'location' => $this->get_casino_location($post->ID),
                'url' => get_permalink($post->ID),
                'edit_url' => get_edit_post_link($post->ID)
            );
        }
        
        return $results;
    }
    
    /**
     * Get casino data for preview
     */
    public function get_casino_data($casino_id) {
        $post = get_post($casino_id);
        
        if (!$post || $post->post_type !== cdi_get_casino_post_type()) {
            return null;
        }
        
        return array(
            'id' => $post->ID,
            'title' => $post->post_title,
            'content' => $post->post_content,
            'location' => $this->get_casino_location($post->ID),
            'meta' => $this->get_relevant_meta($post->ID),
            'url' => get_permalink($post->ID),
            'edit_url' => get_edit_post_link($post->ID)
        );
    }
    
    /**
     * Get relevant meta fields for casino
     */
    private function get_relevant_meta($post_id) {
        $meta_fields = array(
            '_phone' => 'Phone',
            '_website' => 'Website', 
            '_email' => 'Email',
            '_address' => 'Address',
            '_minimum_bet' => 'Minimum Bet',
            '_maximum_bet' => 'Maximum Bet',
            '_table_limit' => 'Table Limit',
            '_hours' => 'Hours',
            '_notes' => 'Notes'
        );
        
        $meta_data = array();
        
        foreach ($meta_fields as $meta_key => $label) {
            $value = get_post_meta($post_id, $meta_key, true);
            if (!empty($value)) {
                $meta_data[$label] = $value;
            }
        }
        
        return $meta_data;
    }
    
    /**
     * Find best match for a casino
     */
    public function find_best_match($casino_name, $location = '') {
        $matches = $this->find_matches($casino_name, $location);
        
        if (empty($matches)) {
            return null;
        }
        
        $best_match = $matches[0];
        
        // Only return if confidence is high enough
        if ($best_match['similarity'] >= $this->similarity_threshold) {
            return $best_match;
        }
        
        return null;
    }
    
    /**
     * Check if two casinos are likely the same
     */
    public function are_likely_same($casino1_name, $casino1_location, $casino2_name, $casino2_location) {
        $name_similarity = $this->string_similarity($casino1_name, $casino2_name);
        $location_similarity = 0;
        
        if (!empty($casino1_location) && !empty($casino2_location)) {
            $location_similarity = $this->location_similarity($casino1_location, $casino2_location);
        }
        
        // Weight name more heavily
        $overall_similarity = ($name_similarity * 0.7) + ($location_similarity * 0.3);
        
        return $overall_similarity >= $this->similarity_threshold;
    }
    
    /**
     * Clear matching cache
     */
    public function clear_cache() {
        $this->cache = array();
    }
    
    /**
     * Get matching statistics
     */
    public function get_matching_stats() {
        return array(
            'similarity_threshold' => $this->similarity_threshold,
            'location_boost' => $this->location_boost,
            'cache_size' => count($this->cache),
            'total_casinos' => $this->get_total_casino_count()
        );
    }
    
    /**
     * Get total casino count
     */
    private function get_total_casino_count() {
        $count = wp_count_posts(cdi_get_casino_post_type()); // Use dynamic post type
        return $count->publish ?? 0;
    }
}