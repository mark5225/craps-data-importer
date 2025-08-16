<?php
/**
 * File Handler Class - Manages file uploads and CSV/Excel processing
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CDI_File_Handler {
    
    private $upload_dir;
    private $allowed_types;
    private $max_file_size;
    
    /**
     * Constructor - UPDATED for CSV-only support
     */
    public function __construct() {
        $wp_upload_dir = wp_upload_dir();
        $this->upload_dir = $wp_upload_dir['basedir'] . '/craps-importer/';
        $this->allowed_types = array('csv'); // CSV only
        $this->max_file_size = $this->get_max_upload_size();
        
        // Create upload directory if it doesn't exist
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
            
            // Add .htaccess for security
            $htaccess_content = "Options -Indexes\n<Files *.php>\nOrder allow,deny\nDeny from all\n</Files>";
            file_put_contents($this->upload_dir . '.htaccess', $htaccess_content);
        }
    }dir();
        $this->upload_dir = $wp_upload_dir['basedir'] . '/craps-importer/';
        $this->allowed_types = array('csv', 'xlsx', 'xls');
        $this->max_file_size = $this->get_max_upload_size();
        
        // Create upload directory if it doesn't exist
        if (!file_exists($this->upload_dir)) {
            wp_mkdir_p($this->upload_dir);
            
            // Add .htaccess for security
            $htaccess_content = "Options -Indexes\n<Files *.php>\nOrder allow,deny\nDeny from all\n</Files>";
            file_put_contents($this->upload_dir . '.htaccess', $htaccess_content);
        }
    }
    
    /**
     * Process uploaded file
     */
    public function process_upload($file_data) {
        try {
            // Validate file
            $validation_result = $this->validate_upload($file_data);
            if (!$validation_result['valid']) {
                return array(
                    'success' => false,
                    'error' => $validation_result['error']
                );
            }
            
            // Move uploaded file
            $filename = $this->generate_safe_filename($file_data['name']);
            $file_path = $this->upload_dir . $filename;
            
            if (!move_uploaded_file($file_data['tmp_name'], $file_path)) {
                throw new Exception(__('Failed to move uploaded file.', 'craps-data-importer'));
            }
            
            // Process file content
            $file_extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
            
            // Process CSV file only
            if ($file_extension === 'csv') {
                $processed_data = $this->process_csv_file($file_path);
            } else {
                throw new Exception(__('Only CSV files are supported. Please export your data as CSV.', 'craps-data-importer'));
            }
            
            // Store processed data
            $this->store_upload_data($filename, $processed_data);
            
            // Clean up original file
            unlink($file_path);
            
            return array(
                'success' => true,
                'message' => sprintf(__('Successfully processed %d rows from %d sheets.', 'craps-data-importer'), 
                                   $processed_data['stats']['total_rows'], 
                                   $processed_data['stats']['total_sheets']),
                'data' => $processed_data
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => $e->getMessage()
            );
        }
    }
    
    /**
     * Validate uploaded file
     */
    private function validate_upload($file_data) {
        // Check for upload errors
        if ($file_data['error'] !== UPLOAD_ERR_OK) {
            $error_messages = array(
                UPLOAD_ERR_INI_SIZE => __('File exceeds maximum upload size.', 'craps-data-importer'),
                UPLOAD_ERR_FORM_SIZE => __('File exceeds form maximum size.', 'craps-data-importer'),
                UPLOAD_ERR_PARTIAL => __('File was only partially uploaded.', 'craps-data-importer'),
                UPLOAD_ERR_NO_FILE => __('No file was uploaded.', 'craps-data-importer'),
                UPLOAD_ERR_NO_TMP_DIR => __('Missing temporary folder.', 'craps-data-importer'),
                UPLOAD_ERR_CANT_WRITE => __('Failed to write file to disk.', 'craps-data-importer'),
                UPLOAD_ERR_EXTENSION => __('Upload stopped by extension.', 'craps-data-importer')
            );
            
            return array(
                'valid' => false,
                'error' => $error_messages[$file_data['error']] ?? __('Unknown upload error.', 'craps-data-importer')
            );
        }
        
        // Check file size
        if ($file_data['size'] > $this->max_file_size) {
            return array(
                'valid' => false,
                'error' => sprintf(__('File size (%s) exceeds maximum allowed size (%s).', 'craps-data-importer'),
                                 size_format($file_data['size']),
                                 size_format($this->max_file_size))
            );
        }
        
        // Check file type - CSV only
        $file_extension = strtolower(pathinfo($file_data['name'], PATHINFO_EXTENSION));
        if ($file_extension !== 'csv') {
            return array(
                'valid' => false,
                'error' => __('Only CSV files are supported. Please export as CSV from Google Sheets.', 'craps-data-importer')
            );
        }
        
        // Check MIME type for CSV
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_data['tmp_name']);
        finfo_close($finfo);
        
        $allowed_mimes = array(
            'text/csv',
            'text/plain',
            'application/csv'
        );
        
        if (!in_array($mime_type, $allowed_mimes)) {
            return array(
                'valid' => false,
                'error' => sprintf(__('Invalid CSV file format detected. Got: %s', 'craps-data-importer'), $mime_type)
            );
        }
        
        return array('valid' => true);
    }
    
    /**
     * Process CSV file
     */
    private function process_csv_file($file_path) {
        $csv_content = file_get_contents($file_path);
        
        if ($csv_content === false) {
            throw new Exception(__('Unable to read CSV file.', 'craps-data-importer'));
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
        
        return array(
            'data' => array(
                'Sheet1' => array(
                    'headers' => $headers,
                    'data' => $data
                )
            ),
            'stats' => array(
                'total_sheets' => 1,
                'total_rows' => count($data),
                'valid_casinos' => $this->count_valid_casinos($data)
            )
        );
    }
    
    /**
     * Count valid casinos in data
     */
    private function count_valid_casinos($data) {
        $count = 0;
        
        foreach ($data as $row) {
            // Check if we have a casino name - handle both "Casino Name" and "Downtown Casino"
            $casino_name = $row['Casino Name'] ?? $row['Downtown Casino'] ?? '';
            if (!empty(trim($casino_name))) {
                $count++;
            }
        }
        
        return $count;
    }
    
    /**
     * Generate safe filename
     */
    private function generate_safe_filename($original_name) {
        $extension = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
        $basename = pathinfo($original_name, PATHINFO_FILENAME);
        
        // Sanitize filename
        $basename = sanitize_file_name($basename);
        $basename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $basename);
        
        // Add timestamp to avoid conflicts
        $timestamp = date('Y-m-d_H-i-s');
        
        return $basename . '_' . $timestamp . '.' . $extension;
    }
    
    /**
     * Store upload data for later processing
     */
    private function store_upload_data($filename, $processed_data) {
        $storage_data = array(
            'filename' => $filename,
            'uploaded_at' => current_time('mysql'),
            'data' => $processed_data['data'],
            'stats' => $processed_data['stats']
        );
        
        update_option('cdi_upload_data', $storage_data);
    }
    
    /**
     * Get stored upload data
     */
    public function get_upload_data() {
        return get_option('cdi_upload_data', null);
    }
    
    /**
     * Clear upload data
     */
    public function clear_upload_data() {
        delete_option('cdi_upload_data');
        
        // Clean up any temporary files
        $files = glob($this->upload_dir . '*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }
    
    /**
     * Check server capabilities
     */
    public function check_server_capabilities() {
        $capabilities = array(
            'limits' => array(
                'max_upload_size' => $this->get_max_upload_size(),
                'max_execution_time' => ini_get('max_execution_time'),
                'memory_limit' => $this->parse_size(ini_get('memory_limit')),
                'post_max_size' => $this->parse_size(ini_get('post_max_size'))
            ),
            'warnings' => array()
        );
        
        // Check for potential issues
        if ($capabilities['limits']['max_upload_size'] < (5 * 1024 * 1024)) { // Less than 5MB
            $capabilities['warnings'][] = __('Upload size limit is quite low. Large spreadsheets may fail to upload.', 'craps-data-importer');
        }
        
        if ($capabilities['limits']['max_execution_time'] > 0 && $capabilities['limits']['max_execution_time'] < 60) {
            $capabilities['warnings'][] = __('Script execution time limit is low. Large imports may timeout.', 'craps-data-importer');
        }
        
        if ($capabilities['limits']['memory_limit'] < (128 * 1024 * 1024)) { // Less than 128MB
            $capabilities['warnings'][] = __('Memory limit is low. Large spreadsheets may cause memory errors.', 'craps-data-importer');
        }
        
        // Check for required functions
        if (!function_exists('fgetcsv')) {
            $capabilities['warnings'][] = __('CSV parsing functions are not available.', 'craps-data-importer');
        }
        
        if (!extension_loaded('mbstring')) {
            $capabilities['warnings'][] = __('Multibyte string extension is not loaded. Character encoding issues may occur.', 'craps-data-importer');
        }
        
        return $capabilities;
    }
    
    /**
     * Get maximum upload size
     */
    private function get_max_upload_size() {
        $max_upload = $this->parse_size(ini_get('upload_max_filesize'));
        $max_post = $this->parse_size(ini_get('post_max_size'));
        $memory_limit = $this->parse_size(ini_get('memory_limit'));
        
        // Return the smallest limit
        $limits = array_filter(array($max_upload, $max_post, $memory_limit));
        return !empty($limits) ? min($limits) : 2 * 1024 * 1024; // Default 2MB
    }
    
    /**
     * Parse size string to bytes
     */
    private function parse_size($size) {
        if (empty($size)) return 0;
        
        $size = trim($size);
        $last = strtolower($size[strlen($size) - 1]);
        $size = (int) substr($size, 0, -1);
        
        switch ($last) {
            case 'g':
                $size *= 1024;
            case 'm':
                $size *= 1024;
            case 'k':
                $size *= 1024;
        }
        
        return $size;
    }
    
    /**
     * Export data as CSV for backup/debugging
     */
    public function export_processed_data() {
        $upload_data = $this->get_upload_data();
        
        if (!$upload_data) {
            return false;
        }
        
        $filename = 'craps_export_' . date('Y-m-d_H-i-s') . '.csv';
        $filepath = $this->upload_dir . $filename;
        
        $file = fopen($filepath, 'w');
        
        foreach ($upload_data['data'] as $sheet_name => $sheet_data) {
            if (empty($sheet_data['data'])) continue;
            
            // Write sheet header
            fwrite($file, "\n=== " . $sheet_name . " ===\n");
            
            // Write column headers
            if (!empty($sheet_data['headers'])) {
                fputcsv($file, $sheet_data['headers']);
            }
            
            // Write data rows
            foreach ($sheet_data['data'] as $row) {
                // Remove internal fields
                unset($row['_row_number']);
                fputcsv($file, $row);
            }
        }
        
        fclose($file);
        
        return $filepath;
    }
    
    /**
     * Get upload directory info
     */
    public function get_upload_dir_info() {
        return array(
            'path' => $this->upload_dir,
            'url' => wp_upload_dir()['baseurl'] . '/craps-importer/',
            'writable' => is_writable($this->upload_dir),
            'exists' => file_exists($this->upload_dir),
            'files' => $this->list_upload_files()
        );
    }
    
    /**
     * List files in upload directory
     */
    private function list_upload_files() {
        if (!file_exists($this->upload_dir)) {
            return array();
        }
        
        $files = array();
        $directory_files = scandir($this->upload_dir);
        
        foreach ($directory_files as $file) {
            if ($file === '.' || $file === '..' || $file === '.htaccess') {
                continue;
            }
            
            $filepath = $this->upload_dir . $file;
            if (is_file($filepath)) {
                $files[] = array(
                    'name' => $file,
                    'size' => filesize($filepath),
                    'modified' => filemtime($filepath),
                    'type' => pathinfo($file, PATHINFO_EXTENSION)
                );
            }
        }
        
        return $files;
    }
    
    /**
     * Clean old temporary files
     */
    public function cleanup_old_files($days = 7) {
        $cutoff_time = time() - ($days * 24 * 60 * 60);
        $files = $this->list_upload_files();
        $cleaned = 0;
        
        foreach ($files as $file) {
            if ($file['modified'] < $cutoff_time) {
                $filepath = $this->upload_dir . $file['name'];
                if (unlink($filepath)) {
                    $cleaned++;
                }
            }
        }
        
        return $cleaned;
    }
}