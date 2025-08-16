<?php
/**
 * Matcher Class - Enhanced fuzzy matching with location context
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CDI_Matcher {
    
    private $similarity_threshold;
    private $location_boost;
    private $debug_mode;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->similarity_threshold = floatval(get_option('cdi_similarity_threshold', 70));
        $this->location_boost = floatval(get_option('cdi_location_boost', 10));
        $this->debug_mode = defined('WP_DEBUG') && WP_DEBUG;
    }
    
    /**
     * Find existing casino with enhanced debugging
     */
    public function find_casino($casino_name, $location_hint = '', $debug = false) {
        $debug_info = array(
            'original_name' => $casino_name,
            'location_hint' => $location_hint,
            'search_attempts' => array(),
            'best_match' => null,
            'similarity_score' => 0,
            'match_method' => 'none',
            'location_boost_applied' => false
        );
        
        // Create search variations
        $search_variations = $this->generate_search_variations($casino_name, $location_hint);
        
        $best_match = null;
        $best_similarity = 0;
        $match_method = 'none';
        $location_boost_applied = false;
        
        foreach ($search_variations as $index => $search_term) {
            if (empty($search_term)) continue;
            
            $attempt = array(
                'variation' => $search_term,
                'method' => 'exact_title',
                'results' => 0,
                'matches' => array()
            );
            
            // Try exact title match first
            $posts = get_posts(array(
                'post_type' => 'at_biz_dir',
                'post_status' => 'publish',
                'title' => $search_term,
                'numberposts' => 1
            ));
            
            if (!empty($posts)) {
                $attempt['results'] = 1;
                $attempt['matches'][] = array(
                    'post' => $posts[0],
                    'similarity' => 100,
                    'method' => 'exact_title'
                );
                
                $debug_info['search_attempts'][] = $attempt;
                $debug_info['best_match'] = $posts[0];
                $debug_info['similarity_score'] = 100;
                $debug_info['match_method'] = 'exact_title';
                
                return array(
                    'post' => $posts[0],
                    'debug' => $debug_info,
                    'matching_info' => sprintf(__("Exact title match: '%s' (100%% similarity)", 'craps-data-importer'), $posts[0]->post_title)
                );
            }
            
            // Try fuzzy search
            $fuzzy_results = $this->fuzzy_search($search_term, $casino_name, $location_hint);
            $attempt['results'] = count($fuzzy_results['posts']);
            $attempt['method'] = 'fuzzy_search';
            
            foreach ($fuzzy_results['posts'] as $post_data) {
                $attempt['matches'][] = $post_data;
                
                if ($post_data['similarity'] > $best_similarity) {
                    $best_similarity = $post_data['similarity'];
                    $best_match = $post_data['post'];
                    $match_method = $post_data['method'];
                    $location_boost_applied = $post_data['location_boost'] ?? false;
                }
            }
            
            $debug_info['search_attempts'][] = $attempt;
        }
        
        // Update debug info with best match
        if ($best_match) {
            $debug_info['best_match'] = $best_match;
            $debug_info['similarity_score'] = round($best_similarity, 1);
            $debug_info['match_method'] = $match_method;
            $debug_info['location_boost_applied'] = $location_boost_applied;
            
            // Only return matches above threshold
            if ($best_similarity >= $this->similarity_threshold) {
                $location_text = $location_boost_applied ? __(' (location verified)', 'craps-data-importer') : '';
                $matching_info = sprintf(
                    __("Found: '%s' (%s%% similarity via %s)%s", 'craps-data-importer'),
                    $best_match->post_title,
                    $debug_info['similarity_score'],
                    $match_method,
                    $location_text
                );
                
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
        
        $best_sim_text = $best_similarity > 0 ? sprintf(__(', best similarity: %s%%', 'craps-data-importer'), round($best_similarity, 1)) : '';
        
        return array(
            'post' => null,
            'debug' => $debug_info,
            'matching_info' => sprintf(
                __('No match found (searched %d variations%s) - Requires manual review', 'craps-data-importer'),
                count($search_variations),
                $best_sim_text
            )
        );
    }
    
    /**
     * Generate search variations for casino name
     */
    private function generate_search_variations($casino_name, $location_hint = '') {
        $variations = array(
            $casino_name,
            $this->clean_casino_name($casino_name),
            str_replace(array('&', 'and'), '', $casino_name),
            preg_replace('/\s+/', ' ', trim($casino_name)),
            str_replace(array('Casino', 'Hotel', 'Resort'), '', $casino_name),
            preg_replace('/[^\w\s]/', '', $casino_name) // Remove special characters
        );
        
        // Add location-specific variations if we have location context
        if (!empty($location_hint)) {
            $variations[] = $casino_name . ' ' . $location_hint;
            $variations[] = $this->clean_casino_name($casino_name) . ' ' . $location_hint;
            $variations[] = $location_hint . ' ' . $casino_name;
        }
        
        // Remove duplicates and empty strings
        $variations = array_unique(array_filter($variations, function($v) {
            return !empty(trim($v));
        }));
        
        return $variations;
    }
    
    /**
     * Perform fuzzy search on casino database
     */
    private function fuzzy_search($search_term, $original_name, $location_hint = '') {
        $posts = get_posts(array(
            'post_type' => 'at_biz_dir',
            'post_status' => 'publish',
            's' => $search_term,
            'numberposts' => 30 // Increased for better location matching
        ));
        
        $results = array();
        
        foreach ($posts as $post) {
            $similarity_data = $this->calculate_similarity($post, $original_name, $search_term, $location_hint);
            
            if ($similarity_data['similarity'] >= 50) { // Lower threshold for consideration
                $results[] = array(
                    'post' => $post,
                    'similarity' => $similarity_data['similarity'],
                    'method' => $similarity_data['method'],
                    'location_boost' => $similarity_data['location_boost']
                );
            }
        }
        
        // Sort by similarity descending
        usort($results, function($a, $b) {
            return $b['similarity'] <=> $a['similarity'];
        });
        
        return array('posts' => $results);
    }
    
    /**
     * Calculate similarity between casino names with location context
     */
    private function calculate_similarity($post, $original_name, $search_term, $location_hint = '') {
        $post_title = $post->post_title;
        
        // Calculate different similarity scores
        $similarities = array();
        
        // Direct similarity
        similar_text(strtolower($original_name), strtolower($post_title), $similarity);
        $similarities['direct'] = $similarity;
        
        // Cleaned name similarity
        $clean_original = $this->clean_casino_name($original_name);
        $clean_found = $this->clean_casino_name($post_title);
        similar_text(strtolower($clean_original), strtolower($clean_found), $clean_similarity);
        $similarities['cleaned'] = $clean_similarity;
        
        // Search term similarity
        similar_text(strtolower($search_term), strtolower($post_title), $search_similarity);
        $similarities['search_term'] = $search_similarity;
        
        // Substring matching
        $substring_similarity = $this->calculate_substring_similarity($search_term, $post_title);
        $similarities['substring'] = $substring_similarity;
        
        // Get the best similarity score
        $best_similarity = max($similarities);
        $method = array_search($best_similarity, $similarities);
        
        // Apply location boost if applicable
        $location_boost_applied = false;
        if (!empty($location_hint) && $best_similarity >= 60) {
            $location_match = $this->check_location_match($post, $location_hint);
            if ($location_match) {
                $best_similarity = min(100, $best_similarity + $this->location_boost);
                $location_boost_applied = true;
                $method .= '_location_boost';
            }
        }
        
        return array(
            'similarity' => $best_similarity,
            'method' => $method,
            'location_boost' => $location_boost_applied,
            'raw_similarities' => $similarities
        );
    }
    
    /**
     * Calculate substring similarity
     */
    private function calculate_substring_similarity($search_term, $post_title) {
        $search_lower = strtolower($search_term);
        $title_lower = strtolower($post_title);
        
        // Check if one contains the other
        if (strpos($title_lower, $search_lower) !== false || strpos($search_lower, $title_lower) !== false) {
            return min(
                (strlen($search_term) / strlen($post_title)) * 100,
                (strlen($post_title) / strlen($search_term)) * 100
            );
        }
        
        return 0;
    }
    
    /**
     * Check if location matches casino's location data
     */
    private function check_location_match($post, $location_hint) {
        $location_lower = strtolower($location_hint);
        
        // Check post taxonomy terms
        $post_locations = wp_get_post_terms($post->ID, 'at_biz_dir-location', array('fields' => 'names'));
        if (!empty($post_locations) && !is_wp_error($post_locations)) {
            foreach ($post_locations as $post_location) {
                if (stripos($post_location, $location_hint) !== false || 
                    stripos($location_hint, $post_location) !== false) {
                    return true;
                }
            }
        }
        
        // Check post content and title
        $post_content_lower = strtolower($post->post_title . ' ' . $post->post_content);
        if (stripos($post_content_lower, $location_lower) !== false) {
            return true;
        }
        
        // Check meta fields that might contain location info
        $address = get_post_meta($post->ID, '_address', true);
        if ($address && stripos(strtolower($address), $location_lower) !== false) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Clean casino name for better matching
     */
    private function clean_casino_name($name) {
        // Remove apostrophes and quotes
        $name = str_replace(array("'", "'", "`", '"'), '', $name);
        
        // Remove common casino suffixes
        $suffixes = array(' Casino', ' Hotel', ' Resort', ' & Casino', ' Hotel & Casino', ' Casino & Hotel', ' Gaming');
        $name = str_ireplace($suffixes, '', $name);
        
        // Normalize common words
        $name = str_replace('&', 'and', $name);
        
        // Normalize spacing
        $name = preg_replace('/\s+/', ' ', trim($name));
        
        // Normalize common abbreviations
        $abbreviations = array(
            'St.' => 'Street',
            'St ' => 'Street ',
            'Ave.' => 'Avenue',
            'Ave ' => 'Avenue ',
            'Blvd.' => 'Boulevard',
            'Blvd ' => 'Boulevard ',
            'Dr.' => 'Drive',
            'Dr ' => 'Drive '
        );
        
        foreach ($abbreviations as $abbr => $full) {
            $name = str_ireplace($abbr, $full, $name);
        }
        
        return trim($name);
    }
    
    /**
     * Batch find casinos for multiple names
     */
    public function batch_find_casinos($casino_data, $progress_callback = null) {
        $results = array();
        $total = count($casino_data);
        $processed = 0;
        
        foreach ($casino_data as $row) {
            $casino_name = $row[array_keys($row)[0]] ?? 'Unknown';
            $location = $row['Location'] ?? $row['State'] ?? '';
            
            $match_result = $this->find_casino($casino_name, $location);
            $results[] = array(
                'row_data' => $row,
                'match_result' => $match_result
            );
            
            $processed++;
            
            // Call progress callback if provided
            if ($progress_callback && is_callable($progress_callback)) {
                call_user_func($progress_callback, $processed, $total, $casino_name);
            }
        }
        
        return $results;
    }
    
    /**
     * Analyze matching performance for a dataset
     */
    public function analyze_matching_performance($results) {
        $stats = array(
            'total' => count($results),
            'exact_matches' => 0,
            'high_similarity' => 0, // 90%+
            'medium_similarity' => 0, // 70-89%
            'low_similarity' => 0, // 50-69%
            'no_match' => 0,
            'location_boosts' => 0,
            'method_breakdown' => array(),
            'failed_matches' => array()
        );
        
        foreach ($results as $result) {
            $debug = $result['match_result']['debug'] ?? array();
            $similarity = $debug['similarity_score'] ?? 0;
            $method = $debug['match_method'] ?? 'unknown';
            
            // Count by similarity range
            if ($similarity >= 100) {
                $stats['exact_matches']++;
            } elseif ($similarity >= 90) {
                $stats['high_similarity']++;
            } elseif ($similarity >= 70) {
                $stats['medium_similarity']++;
            } elseif ($similarity >= 50) {
                $stats['low_similarity']++;
            } else {
                $stats['no_match']++;
                $stats['failed_matches'][] = array(
                    'casino' => $result['row_data'][array_keys($result['row_data'])[0]] ?? 'Unknown',
                    'similarity' => $similarity
                );
            }
            
            // Count location boosts
            if (!empty($debug['location_boost_applied'])) {
                $stats['location_boosts']++;
            }
            
            // Method breakdown
            if (!isset($stats['method_breakdown'][$method])) {
                $stats['method_breakdown'][$method] = 0;
            }
            $stats['method_breakdown'][$method]++;
        }
        
        // Calculate success rate
        $stats['success_rate'] = $stats['total'] > 0 ? 
            round((($stats['exact_matches'] + $stats['high_similarity']) / $stats['total']) * 100, 1) : 0;
        
        return $stats;
    }
    
    /**
     * Get similarity threshold
     */
    public function get_similarity_threshold() {
        return $this->similarity_threshold;
    }
    
    /**
     * Set similarity threshold
     */
    public function set_similarity_threshold($threshold) {
        $this->similarity_threshold = floatval($threshold);
    }
    
    /**
     * Get location boost value
     */
    public function get_location_boost() {
        return $this->location_boost;
    }
    
    /**
     * Set location boost value
     */
    public function set_location_boost($boost) {
        $this->location_boost = floatval($boost);
    }
}