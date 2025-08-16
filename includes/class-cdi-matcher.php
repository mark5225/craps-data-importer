<?php
/**
 * Casino matching logic for Craps Data Importer
 */

if (!defined('ABSPATH')) {
    exit;
}

class CDI_Matcher {
    
    /**
     * Find matching casino for given name
     */
    public function find_casino_match($casino_name, $min_similarity = 80) {
        $casinos = $this->get_all_casinos();
        
        $best_match = null;
        $best_similarity = 0;
        
        foreach ($casinos as $casino) {
            $similarity = $this->calculate_similarity($casino_name, $casino->post_title);
            
            if ($similarity > $best_similarity && $similarity >= $min_similarity) {
                $best_similarity = $similarity;
                $best_match = $casino;
            }
        }
        
        return array(
            'casino' => $best_match,
            'similarity' => $best_similarity
        );
    }
    
    /**
     * Search casinos by name
     */
    public function search_casinos($search_term, $limit = 10) {
        if (empty($search_term)) {
            return array();
        }
        
        $args = array(
            'post_type' => 'at_biz_dir',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            's' => $search_term,
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_location',
                    'value' => $search_term,
                    'compare' => 'LIKE'
                )
            )
        );
        
        $casinos = get_posts($args);
        $results = array();
        
        foreach ($casinos as $casino) {
            $location = get_post_meta($casino->ID, '_location', true);
            $similarity = $this->calculate_similarity($search_term, $casino->post_title);
            
            $results[] = array(
                'id' => $casino->ID,
                'title' => $casino->post_title,
                'location' => $location,
                'similarity' => $similarity,
                'url' => get_permalink($casino->ID)
            );
        }
        
        // Sort by similarity
        usort($results, function($a, $b) {
            return $b['similarity'] - $a['similarity'];
        });
        
        return $results;
    }
    
    /**
     * Get all casino posts
     */
    private function get_all_casinos() {
        return get_posts(array(
            'post_type' => 'at_biz_dir',
            'post_status' => 'publish',
            'posts_per_page' => -1,
            'fields' => 'all'
        ));
    }
    
    /**
     * Calculate similarity between two strings
     */
    private function calculate_similarity($str1, $str2) {
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
        
        // Multiple similarity algorithms for better results
        $similarities = array();
        
        // Levenshtein distance
        $lev_sim = $this->levenshtein_similarity($str1, $str2);
        $similarities[] = $lev_sim;
        
        // Similar text
        $sim_text = 0;
        similar_text($str1, $str2, $sim_text);
        $similarities[] = $sim_text;
        
        // Soundex comparison
        if (soundex($str1) === soundex($str2)) {
            $similarities[] = 85; // Boost for phonetic similarity
        }
        
        // Word overlap similarity
        $word_sim = $this->word_overlap_similarity($str1, $str2);
        $similarities[] = $word_sim;
        
        // Return the maximum similarity score
        return max($similarities);
    }
    
    /**
     * Normalize string for comparison
     */
    private function normalize_string($str) {
        // Convert to lowercase
        $str = strtolower($str);
        
        // Remove common words that don't help with matching
        $common_words = array('casino', 'hotel', 'resort', 'the', 'and', 'of', 'at', 'in');
        foreach ($common_words as $word) {
            $str = preg_replace('/\b' . preg_quote($word) . '\b/', '', $str);
        }
        
        // Remove extra spaces and trim
        $str = preg_replace('/\s+/', ' ', trim($str));
        
        return $str;
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
        
        $distance = levenshtein($str1, $str2);
        $max_len = max($len1, $len2);
        
        return (1 - ($distance / $max_len)) * 100;
    }
    
    /**
     * Word overlap similarity
     */
    private function word_overlap_similarity($str1, $str2) {
        $words1 = array_filter(explode(' ', $str1));
        $words2 = array_filter(explode(' ', $str2));
        
        if (empty($words1) || empty($words2)) {
            return 0;
        }
        
        $intersection = array_intersect($words1, $words2);
        $union = array_unique(array_merge($words1, $words2));
        
        if (empty($union)) {
            return 0;
        }
        
        return (count($intersection) / count($union)) * 100;
    }
    
    /**
     * Generate name variations for better matching
     */
    private function generate_name_variations($name) {
        $variations = array($name);
        
        // Original name
        $variations[] = $name;
        
        // Lowercase version
        $variations[] = strtolower($name);
        
        // Remove punctuation
        $no_punct = preg_replace('/[^\w\s]/', '', $name);
        $variations[] = $no_punct;
        
        // Remove common words
        $common_words = array('casino', 'hotel', 'resort', 'the', 'and', 'of', 'at', 'in');
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
        
        // Remove duplicates and empty values
        $variations = array_unique(array_filter($variations, function($v) {
            return !empty(trim($v));
        }));
        
        return $variations;
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
    
    /**
     * Get casino location from various sources
     */
    private function get_casino_location($casino_id) {
        // Try meta field first
        $location = get_post_meta($casino_id, '_location', true);
        
        if (empty($location)) {
            // Try address field
            $location = get_post_meta($casino_id, '_address', true);
        }
        
        if (empty($location)) {
            // Try taxonomy
            $locations = wp_get_post_terms($casino_id, 'at_biz_dir-location', array('fields' => 'names'));
            $location = !empty($locations) ? implode(', ', $locations) : '';
        }
        
        return $location;
    }
}