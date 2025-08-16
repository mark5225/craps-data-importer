<?php
/**
 * Casino matching logic for Craps Data Importer
 */

class CDI_Matcher {
    
    /**
     * Find matching casino by name with similarity scoring
     */
    public function find_casino_match($casino_name, $min_similarity = 80) {
        if (empty($casino_name)) {
            return array('casino' => null, 'similarity' => 0);
        }
        
        $clean_name = $this->clean_casino_name($casino_name);
        
        // Try exact match first
        $exact_match = $this->find_exact_match($clean_name);
        if ($exact_match) {
            return array('casino' => $exact_match, 'similarity' => 100);
        }
        
        // Try fuzzy matching
        $fuzzy_result = $this->find_fuzzy_match($clean_name, $min_similarity);
        
        return $fuzzy_result;
    }
    
    /**
     * Search casinos by name for manual matching
     */
    public function search_casinos($search_term, $limit = 10) {
        if (empty($search_term) || strlen($search_term) < 2) {
            return array();
        }
        
        global $wpdb;
        
        $search_term = '%' . $wpdb->esc_like($search_term) . '%';
        
        $posts = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, p.post_title, pm.meta_value as location
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_location'
             WHERE p.post_type = 'at_biz_dir' 
             AND p.post_status = 'publish'
             AND p.post_title LIKE %s
             ORDER BY p.post_title ASC
             LIMIT %d",
            $search_term,
            $limit
        ));
        
        $results = array();
        foreach ($posts as $post) {
            // Get location from taxonomy if not in meta
            if (empty($post->location)) {
                $locations = wp_get_post_terms($post->ID, 'at_biz_dir-location', array('fields' => 'names'));
                $post->location = !empty($locations) ? implode(', ', $locations) : '';
            }
            
            $results[] = array(
                'ID' => $post->ID,
                'post_title' => $post->post_title,
                'location' => $post->location
            );
        }
        
        return $results;
    }
    
    /**
     * Find exact match
     */
    private function find_exact_match($clean_name) {
        global $wpdb;
        
        // Try multiple variations
        $variations = $this->get_name_variations($clean_name);
        
        foreach ($variations as $variation) {
            $post = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$wpdb->posts} 
                 WHERE post_type = 'at_biz_dir' 
                 AND post_status = 'publish'
                 AND post_title = %s",
                $variation
            ));
            
            if ($post) {
                return $post;
            }
        }
        
        return null;
    }
    
    /**
     * Find fuzzy match using similarity algorithms
     */
    private function find_fuzzy_match($clean_name, $min_similarity) {
        global $wpdb;
        
        $posts = $wpdb->get_results(
            "SELECT * FROM {$wpdb->posts} 
             WHERE post_type = 'at_biz_dir' 
             AND post_status = 'publish'
             ORDER BY post_title"
        );
        
        $best_match = null;
        $best_similarity = 0;
        
        $variations = $this->get_name_variations($clean_name);
        
        foreach ($posts as $post) {
            $post_variations = $this->get_name_variations($this->clean_casino_name($post->post_title));
            
            foreach ($variations as $search_var) {
                foreach ($post_variations as $post_var) {
                    $similarity = $this->calculate_similarity($search_var, $post_var);
                    
                    if ($similarity > $best_similarity) {
                        $best_similarity = $similarity;
                        $best_match = $post;
                    }
                }
            }
        }
        
        if ($best_similarity >= $min_similarity) {
            return array('casino' => $best_match, 'similarity' => $best_similarity);
        }
        
        return array('casino' => null, 'similarity' => $best_similarity);
    }
    
    /**
     * Clean casino name for matching
     */
    private function clean_casino_name($name) {
        // Remove apostrophes and quotes
        $name = str_replace(["'", "'", "`", '"'], '', $name);
        
        // Remove common casino suffixes
        $suffixes = array(
            ' Casino', ' Hotel', ' Resort', ' & Casino', 
            ' Hotel & Casino', ' Casino & Hotel', ' Gaming',
            ' Casino Resort', ' Hotel and Casino'
        );
        $name = str_ireplace($suffixes, '', $name);
        
        // Normalize common words
        $name = str_ireplace(['&', ' and '], ' ', $name);
        
        // Normalize spacing
        $name = preg_replace('/\s+/', ' ', trim($name));
        
        // Normalize common abbreviations
        $abbreviations = array(
            'St.' => 'Street',
            'Ave.' => 'Avenue', 
            'Blvd.' => 'Boulevard',
            'Dr.' => 'Drive',
            'Mt.' => 'Mount',
            'Ft.' => 'Fort'
        );
        
        foreach ($abbreviations as $abbr => $full) {
            $name = str_ireplace($abbr, $full, $name);
        }
        
        return $name;
    }
    
    /**
     * Get name variations for better matching
     */
    private function get_name_variations($name) {
        $variations = array();
        
        // Original cleaned name
        $variations[] = $name;
        
        // Lowercase version
        $variations[] = strtolower($name);
        
        // Title case version
        $variations[] = ucwords(strtolower($name));
        
        // Without common words
        $common_words = array('the', 'at', 'in', 'on', 'of', 'and', 'or');
        $without_common = $name;
        foreach ($common_words as $word) {
            $without_common = preg_replace('/\b' . preg_quote($word) . '\b/i', '', $without_common);
        }
        $without_common = preg_replace('/\s+/', ' ', trim($without_common));
        if (!empty($without_common)) {
            $variations[] = $without_common;
        }
        
        // Acronym version (first letters of each word)
        $words = explode(' ', $name);
        if (count($words) > 1) {
            $acronym = '';
            foreach ($words as $word) {
                if (!empty($word)) {
                    $acronym .= strtoupper($word[0]);
                }
            }
            if (strlen($acronym) > 1) {
                $variations[] = $acronym;
            }
        }
        
        // Remove duplicates
        $variations = array_unique($variations);
        
        // Remove empty values
        $variations = array_filter($variations, function($v) {
            return !empty(trim($v));
        });
        
        return $variations;
    }
    
    /**
     * Calculate similarity between two strings
     */
    private function calculate_similarity($str1, $str2) {
        if (empty($str1) || empty($str2)) {
            return 0;
        }
        
        // Exact match
        if (strcasecmp($str1, $str2) === 0) {
            return 100;
        }
        
        // Multiple similarity algorithms for better results
        $similarities = array();
        
        // Levenshtein distance
        $lev_sim = $this->levenshtein_similarity($str1, $str2);
        $similarities[] = $lev_sim;
        
        // Similar text
        $sim_text = 0;
        similar_text(strtolower($str1), strtolower($str2), $sim_text);
        $similarities[] = $sim_text;
        
        // Jaro-Winkler if available
        if (function_exists('jaro_winkler_similarity')) {
            $jw_sim = jaro_winkler_similarity($str1, $str2) * 100;
            $similarities[] = $jw_sim;
        }
        
        // Soundex comparison
        if (soundex($str1) === soundex($str2)) {
            $similarities[] = 85; // Boost for phonetic similarity
        }
        
        // Metaphone comparison
        if (function_exists('metaphone')) {
            if (metaphone($str1) === metaphone($str2)) {
                $similarities[] = 80; // Boost for phonetic similarity
            }
        }
        
        // Word overlap similarity
        $word_sim = $this->word_overlap_similarity($str1, $str2);
        $similarities[] = $word_sim;
        
        // Return the maximum similarity score
        return max($similarities);
    }
    
    /**
     * Levenshtein similarity as percentage
     */
    private function levenshtein_similarity($str1, $str2) {
        $len1 = strlen($str1);
        $len2 = strlen($str2);
        
        if ($len1 === 0 && $len2 === 0) {
            return 100;
        }
        
        if ($len1 === 0 || $len2 === 0) {
            return 0;
        }
        
        $distance = levenshtein(strtolower($str1), strtolower($str2));
        $max_len = max($len1, $len2);
        
        return (1 - ($distance / $max_len)) * 100;
    }
    
    /**
     * Word overlap similarity
     */
    private function word_overlap_similarity($str1, $str2) {
        $words1 = array_filter(explode(' ', strtolower($str1)));
        $words2 = array_filter(explode(' ', strtolower($str2)));
        
        if (empty($words1) || empty($words2)) {
            return 0;
        }
        
        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));
        
        if (empty($union)) {
            return 0;
        }
        
        // Jaccard index as percentage
        return (count($intersection) / count($union)) * 100;
    }
    
    /**
     * Check if names are likely the same casino
     */
    public function are_names_likely_same($name1, $name2, $threshold = 85) {
        $similarity = $this->calculate_similarity(
            $this->clean_casino_name($name1),
            $this->clean_casino_name($name2)
        );
        
        return $similarity >= $threshold;
    }
    
    /**
     * Get casino location for context matching
     */
    private function get_casino_location($casino_id) {
        // Try meta field first
        $location = get_post_meta($casino_id, '_location', true);
        
        if (empty($location)) {
            // Try taxonomy
            $locations = wp_get_post_terms($casino_id, 'at_biz_dir-location', array('fields' => 'names'));
            $location = !empty($locations) ? implode(', ', $locations) : '';
        }
        
        return $location;
    }
    
    /**
     * Enhanced matching with location context
     */
    public function find_casino_match_with_location($casino_name, $location_hint = '', $min_similarity = 80) {
        $basic_result = $this->find_casino_match($casino_name, $min_similarity);
        
        // If we have a good match and location hint, verify location
        if ($basic_result['casino'] && !empty($location_hint) && $basic_result['similarity'] < 95) {
            $casino_location = $this->get_casino_location($basic_result['casino']->ID);
            
            if (!empty($casino_location)) {
                $location_similarity = $this->calculate_similarity($location_hint, $casino_location);
                
                // Boost similarity if location matches
                if ($location_similarity > 70) {
                    $basic_result['similarity'] = min(100, $basic_result['similarity'] + 10);
                }
                // Reduce similarity if location clearly doesn't match
                elseif ($location_similarity < 30) {
                    $basic_result['similarity'] = max(0, $basic_result['similarity'] - 15);
                }
            }
        }
        
        return $basic_result;
    }
    
    /**
     * Batch match multiple casino names
     */
    public function batch_match_casinos($casino_names, $min_similarity = 80) {
        $results = array();
        
        foreach ($casino_names as $index => $name) {
            $match_result = $this->find_casino_match($name, $min_similarity);
            $results[$index] = array(
                'input_name' => $name,
                'matched_casino' => $match_result['casino'],
                'similarity' => $match_result['similarity'],
                'status' => $match_result['casino'] ? 'matched' : 'no_match'
            );
        }
        
        return $results;
    }
    
    /**
     * Get matching statistics for reporting
     */
    public function get_matching_stats($casino_names, $min_similarity = 80) {
        $batch_results = $this->batch_match_casinos($casino_names, $min_similarity);
        
        $stats = array(
            'total' => count($batch_results),
            'matched' => 0,
            'high_confidence' => 0,
            'low_confidence' => 0,
            'no_match' => 0,
            'average_similarity' => 0
        );
        
        $total_similarity = 0;
        
        foreach ($batch_results as $result) {
            $total_similarity += $result['similarity'];
            
            if ($result['status'] === 'matched') {
                $stats['matched']++;
                
                if ($result['similarity'] >= 90) {
                    $stats['high_confidence']++;
                } else {
                    $stats['low_confidence']++;
                }
            } else {
                $stats['no_match']++;
            }
        }
        
        $stats['average_similarity'] = $stats['total'] > 0 ? 
            round($total_similarity / $stats['total'], 1) : 0;
        
        return $stats;
    }
}