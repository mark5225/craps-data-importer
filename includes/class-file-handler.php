<?php
/**
 * File Handler Class - Upload and parsing functionality
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CDI_File_Handler {
    
    private $allowed_types;
    private $max_file_size;
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->allowed_types = explode(',', get_option('cdi_allowed_file_types', 'csv,xlsx,xls'));
        $this->max_file_size = $this->parse_size(get_option('cdi_max_upload_size', '5MB'));
    }
    
    /**
     * Handle file upload from form
     */
    public function handle_upload($file_data) {
        try {
            // Validate file
            $validation = $this->validate_file($file_data);
            if (!$validation['success']) {
                return $validation;
            }
            
            // Parse file based on type
            $file_extension = strtolower(pathinfo($file_data['name'], PATHINFO_EXTENSION));
            
            switch ($file_extension) {
                case 'csv':
                    $parsed_data = $this->parse_csv($file_data['tmp_name']);
                    break;
                case 'xlsx':
                case 'xls':
                    return array(
                        'success' => false,
                        'error' => __('Excel files not yet supported. Please export as CSV from Google Sheets.', 'craps-data-importer')
                    );
                default:
                    return array(
                        'success' => false,
                        'error' => __('Unsupported file type.', 'craps-data-importer')
                    );
            }
            
            if (empty($parsed_data)) {
                return array(
                    'success' => false,
                    'error' => __('No data found in file or parsing failed.', 'craps-data-importer')
                );
            }
            
            // Store parsed data
            $upload_id = $this->store_upload_data($parsed_data, $file_data['name']);
            
            return array(
                'success' => true,
                'upload_id' => $upload_id,
                'data' => $parsed_data,
                'message' => __('File uploaded and parsed successfully.', 'craps-data-importer')
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => sprintf(__('Upload error: %s', 'craps-data-importer'), $e->getMessage())
            );
        }
    }
    
    /**
     * Validate uploaded file
     */
    private function validate_file($file_data) {
        // Check for upload errors
        if ($file_data['error'] !== UPLOAD_ERR_OK) {
            $error_messages = array(
                UPLOAD_ERR_INI_SIZE => __('File is larger than the server allows.', 'craps-data-importer'),
                UPLOAD_ERR_FORM_SIZE => __('File is larger than the form allows.', 'craps-data-importer'),
                UPLOAD_ERR_PARTIAL => __('File was only partially uploaded.', 'craps-data-importer'),
                UPLOAD_ERR_NO_FILE => __('No file was uploaded.', 'craps-data-importer'),
                UPLOAD_ERR_NO_TMP_DIR => __('Missing temporary folder.', 'craps-data-importer'),
                UPLOAD_ERR_CANT_WRITE => __('Failed to write file to disk.', 'craps-data-importer'),
                UPLOAD_ERR_EXTENSION => __('File upload stopped by extension.', 'craps-data-importer')
            );
            
            $error = $error_messages[$file_data['error']] ?? __('Unknown upload error.', 'craps-data-importer');
            return array('success' => false, 'error' => $error);
        }
        
        // Check file size
        if ($file_data['size'] > $this->max_file_size) {
            return array(
                'success' => false,
                'error' => sprintf(
                    __('File is too large. Maximum size allowed: %s', 'craps-data-importer'),
                    size_format($this->max_file_size)
                )
            );
        }
        
        // Check file extension
        $file_extension = strtolower(pathinfo($file_data['name'], PATHINFO_EXTENSION));
        if (!in_array($file_extension, $this->allowed_types)) {
            return array(
                'success' => false,
                'error' => sprintf(
                    __('Invalid file type. Allowed types: %s', 'craps-data-importer'),
                    implode(', ', $this->allowed_types)
                )
            );
        }
        
        // Check if file exists and is readable
        if (!file_exists($file_data['tmp_name']) || !is_readable($file_data['tmp_name'])) {
            return array(
                'success' => false,
                'error' => __('File is not readable.', 'craps-data-importer')
            );
        }
        
        return array('success' => true);
    }
    
    /**
     * Parse CSV file
     */
    private function parse_csv($file_path) {
        $csv_content = file_get_contents($file_path);
        
        if (empty($csv_content)) {
            throw new Exception(__('CSV file is empty.', 'craps-data-importer'));
        }
        
        // Detect encoding and convert if needed
        $encoding = mb_detect_encoding($csv_content, ['UTF-8', 'UTF-16', 'Windows-1252', 'ISO-8859-1'], true);
        if ($encoding && $encoding !== 'UTF-8') {
            $csv_content = mb_convert_encoding($csv_content, 'UTF-8', $encoding);
        }
        
        // Parse CSV content
        $lines = $this->str_getcsv_custom($csv_content, "\n");
        if (empty($lines)) {
            throw new Exception(__('No lines found in CSV file.', 'craps-data-importer'));
        }
        
        // Get headers from first line
        $headers = $this->str_getcsv_custom(array_shift($lines));
        if (empty($headers)) {
            throw new Exception(__('No headers found in CSV file.', 'craps-data-importer'));
        }
        
        // Clean headers
        $headers = array_map('trim', $headers);
        $headers = $this->normalize_headers($headers);
        
        // Parse data rows
        $data = array();
        $row_number = 2; // Start at 2 since we removed headers
        
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                $row_number++;
                continue;
            }
            
            try {
                $row_data = $this->str_getcsv_custom($line);
                
                // Ensure row has same number of columns as headers
                while (count($row_data) < count($headers)) {
                    $row_data[] = '';
                }
                
                // Create associative array
                $row_assoc = array_combine($headers, array_slice($row_data, 0, count($headers)));
                
                // Clean and validate row data
                $row_assoc = $this->clean_row_data($row_assoc);
                
                // Skip completely empty rows
                if (!$this->is_empty_row($row_assoc)) {
                    $row_assoc['_row_number'] = $row_number;
                    $data[] = $row_assoc;
                }
                
            } catch (Exception $e) {
                // Log problematic row but continue processing
                error_log("CDI: Error parsing row {$row_number}: " . $e->getMessage());
            }
            
            $row_number++;
        }
        
        if (empty($data)) {
            throw new Exception(__('No valid data rows found in CSV file.', 'craps-data-importer'));
        }
        
        // Return structured data
        return array(
            'Community Data' => array(
                'headers' => $headers,
                'data' => $data,
                'total_rows' => count($data),
                'source_file' => basename($file_path)
            )
        );
    }
    
    /**
     * Custom CSV parser to handle various CSV formats
     */
    private function str_getcsv_custom($input, $delimiter = ',', $enclosure = '"', $escape = '\\') {
        $fp = fopen('php://temp', 'r+');
        fputs($fp, $input);
        rewind($fp);
        
        $data = array();
        while (($row = fgetcsv($fp, 0, $delimiter, $enclosure, $escape)) !== false) {
            $data[] = $row;
        }
        fclose($fp);
        
        return count($data) === 1 ? $data[0] : $data;
    }
    
    /**
     * Normalize header names for consistent processing
     */
    private function normalize_headers($headers) {
        $normalized = array();
        $header_map = array(
            'casino' => 'Casino',
            'casino name' => 'Casino',
            'name' => 'Casino',
            'bubble craps' => 'Bubble Craps',
            'bubble' => 'Bubble Craps',
            'has bubble craps' => 'Bubble Craps',
            'min bet' => 'Min Bet',
            'minimum bet' => 'Min Bet',
            'minimum' => 'Min Bet',
            'rewards' => 'Rewards',
            'rewards program' => 'Rewards',
            'player rewards' => 'Rewards',
            'location' => 'Location',
            'state' => 'Location',
            'region' => 'Location',
            'city' => 'Location',
            'address' => 'Address',
            'phone' => 'Phone',
            'website' => 'Website',
            'url' => 'Website'
        );
        
        foreach ($headers as $header) {
            $clean_header = strtolower(trim($header));
            $clean_header = preg_replace('/[^\w\s]/', '', $clean_header);
            $clean_header = preg_replace('/\s+/', ' ', $clean_header);
            
            if (isset($header_map[$clean_header])) {
                $normalized[] = $header_map[$clean_header];
            } else {
                // Capitalize each word for unmapped headers
                $normalized[] = ucwords(str_replace(['_', '-'], ' ', $header));
            }
        }
        
        return $normalized;
    }
    
    /**
     * Clean and validate row data
     */
    private function clean_row_data($row) {
        $cleaned = array();
        
        foreach ($row as $key => $value) {
            // Clean value
            $value = trim($value);
            $value = str_replace(["\r", "\n"], ' ', $value);
            $value = preg_replace('/\s+/', ' ', $value);
            
            // Normalize common values
            switch ($key) {
                case 'Bubble Craps':
                    $value = $this->normalize_boolean_value($value);
                    break;
                case 'Min Bet':
                    $value = $this->normalize_currency_value($value);
                    break;
                case 'Rewards':
                    $value = $this->normalize_rewards_value($value);
                    break;
                case 'Phone':
                    $value = $this->normalize_phone_value($value);
                    break;
                case 'Website':
                    $value = $this->normalize_url_value($value);
                    break;
            }
            
            $cleaned[$key] = $value;
        }
        
        return $cleaned;
    }
    
    /**
     * Normalize boolean values (Yes/No, 1/0, etc.)
     */
    private function normalize_boolean_value($value) {
        $value = strtolower(trim($value));
        
        $yes_values = array('yes', 'y', '1', 'true', 'has', 'available', 'confirmed');
        $no_values = array('no', 'n', '0', 'false', 'none', 'unavailable', 'not available');
        
        if (in_array($value, $yes_values)) {
            return 'Yes';
        } elseif (in_array($value, $no_values)) {
            return 'No';
        }
        
        return ucfirst($value);
    }
    
    /**
     * Normalize currency values
     */
    private function normalize_currency_value($value) {
        if (empty($value)) {
            return '';
        }
        
        $value = trim($value);
        
        // Extract numeric value
        preg_match('/(\d+)/', $value, $matches);
        if (isset($matches[1])) {
            $amount = intval($matches[1]);
            
            // Standard format
            if ($amount >= 5) {
                if (strpos($value, '+') !== false || strpos($value, 'plus') !== false) {
                    return '
            ' . $amount . ' +';
                } else {
                    return '
            ' . $amount;
                }
            } else {
                return '
            ' . $amount;
            }
        }
        
        // Return original if no number found
        return $value;
    }
    
    /**
     * Normalize rewards values
     */
    private function normalize_rewards_value($value) {
        $value = strtolower(trim($value));
        
        if (in_array($value, array('yes', 'y', '1', 'true', 'earns', 'gives points'))) {
            return 'Yes';
        } elseif (in_array($value, array('no', 'n', '0', 'false', 'no points'))) {
            return 'No';
        } elseif (in_array($value, array('unknown', 'unclear', '?', 'not sure'))) {
            return 'Unknown';
        }
        
        return ucfirst($value);
    }
    
    /**
     * Normalize phone values
     */
    private function normalize_phone_value($value) {
        if (empty($value)) {
            return '';
        }
        
        // Remove all non-digit characters except +
        $phone = preg_replace('/[^\d+]/', '', $value);
        
        // Format US phone numbers
        if (strlen($phone) === 10) {
            return '(' . substr($phone, 0, 3) . ') ' . substr($phone, 3, 3) . '-' . substr($phone, 6);
        } elseif (strlen($phone) === 11 && substr($phone, 0, 1) === '1') {
            return '+1 (' . substr($phone, 1, 3) . ') ' . substr($phone, 4, 3) . '-' . substr($phone, 7);
        }
        
        return $value;
    }
    
    /**
     * Normalize URL values
     */
    private function normalize_url_value($value) {
        if (empty($value)) {
            return '';
        }
        
        $value = trim($value);
        
        // Add protocol if missing
        if (!preg_match('/^https?:\/\//', $value)) {
            $value = 'https://' . $value;
        }
        
        return $value;
    }
    
    /**
     * Check if row is completely empty
     */
    private function is_empty_row($row) {
        foreach ($row as $key => $value) {
            if ($key === '_row_number') {
                continue;
            }
            if (!empty(trim($value))) {
                return false;
            }
        }
        return true;
    }
    
    /**
     * Store upload data in WordPress options
     */
    private function store_upload_data($data, $filename) {
        $upload_id = 'cdi_' . uniqid();
        
        $upload_record = array(
            'id' => $upload_id,
            'filename' => $filename,
            'data' => $data,
            'timestamp' => current_time('mysql'),
            'user_id' => get_current_user_id(),
            'stats' => $this->calculate_upload_stats($data)
        );
        
        update_option('cdi_excel_data', $upload_record);
        update_option('cdi_last_upload_id', $upload_id);
        
        return $upload_id;
    }
    
    /**
     * Calculate statistics for uploaded data
     */
    private function calculate_upload_stats($data) {
        $stats = array(
            'total_records' => 0,
            'sheets' => array(),
            'bubble_craps_count' => 0,
            'locations' => array()
        );
        
        foreach ($data as $sheet_name => $sheet_data) {
            $sheet_stats = array(
                'records' => count($sheet_data['data']),
                'bubble_craps' => 0,
                'locations' => array()
            );
            
            foreach ($sheet_data['data'] as $row) {
                $stats['total_records']++;
                
                // Count bubble craps
                $bubble_value = $row['Bubble Craps'] ?? '';
                if ($bubble_value === 'Yes') {
                    $stats['bubble_craps_count']++;
                    $sheet_stats['bubble_craps']++;
                }
                
                // Track locations
                $location = $row['Location'] ?? $sheet_name;
                if (!empty($location)) {
                    if (!isset($stats['locations'][$location])) {
                        $stats['locations'][$location] = 0;
                    }
                    $stats['locations'][$location]++;
                    
                    if (!isset($sheet_stats['locations'][$location])) {
                        $sheet_stats['locations'][$location] = 0;
                    }
                    $sheet_stats['locations'][$location]++;
                }
            }
            
            $stats['sheets'][$sheet_name] = $sheet_stats;
        }
        
        return $stats;
    }
    
    /**
     * Get stored upload data
     */
    public function get_upload_data($upload_id = null) {
        if ($upload_id) {
            $stored_data = get_option('cdi_excel_data');
            if ($stored_data && $stored_data['id'] === $upload_id) {
                return $stored_data;
            }
            return null;
        }
        
        return get_option('cdi_excel_data');
    }
    
    /**
     * Clear stored upload data
     */
    public function clear_upload_data() {
        delete_option('cdi_excel_data');
        delete_option('cdi_last_upload_id');
        delete_option('cdi_import_progress');
    }
    
    /**
     * Parse size string to bytes (e.g., "5MB" to bytes)
     */
    private function parse_size($size) {
        $size = trim($size);
        $last = strtolower(substr($size, -1));
        $size = floatval($size);
        
        switch ($last) {
            case 'g':
                $size *= 1024;
            case 'm':
                $size *= 1024;
            case 'k':
                $size *= 1024;
        }
        
        return intval($size);
    }
    
    /**
     * Get file upload limits from server
     */
    public function get_upload_limits() {
        return array(
            'max_upload_size' => wp_max_upload_size(),
            'max_post_size' => $this->parse_size(ini_get('post_max_size')),
            'max_execution_time' => ini_get('max_execution_time'),
            'memory_limit' => $this->parse_size(ini_get('memory_limit')),
            'allowed_types' => $this->allowed_types
        );
    }
    
    /**
     * Validate server can handle imports
     */
    public function check_server_capabilities() {
        $limits = $this->get_upload_limits();
        $warnings = array();
        
        if ($limits['max_upload_size'] < $this->parse_size('2MB')) {
            $warnings[] = sprintf(
                __('Upload size limit is very low (%s). Consider increasing upload_max_filesize.', 'craps-data-importer'),
                size_format($limits['max_upload_size'])
            );
        }
        
        if ($limits['memory_limit'] < $this->parse_size('64MB')) {
            $warnings[] = sprintf(
                __('Memory limit is low (%s). Large imports may fail.', 'craps-data-importer'),
                size_format($limits['memory_limit'])
            );
        }
        
        if ($limits['max_execution_time'] > 0 && $limits['max_execution_time'] < 30) {
            $warnings[] = sprintf(
                __('Execution time limit is low (%ds). Large imports may timeout.', 'craps-data-importer'),
                $limits['max_execution_time']
            );
        }
        
        return array(
            'limits' => $limits,
            'warnings' => $warnings
        );
    }
}
            '