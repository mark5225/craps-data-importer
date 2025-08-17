<?php
/**
 * Plugin Name: Craps Data Importer
 * Description: Import CSV data for craps tables and bubble craps machines with smart casino matching
 * Version: 1.0.3
 * Author: Bubble Craps Team
 * Text Domain: craps-data-importer
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CDI_VERSION', '1.0.3');
define('CDI_PLUGIN_FILE', __FILE__);
define('CDI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CDI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CDI_INCLUDES_DIR', CDI_PLUGIN_DIR . 'includes/');
define('CDI_ASSETS_URL', CDI_PLUGIN_URL . 'assets/');

/**
 * Main plugin class
 */
class CrapsDataImporter {
    
    private static $instance = null;
    private $admin;
    private $processor;
    private $matcher;
    private $dependencies_loaded = false;
    private $missing_files = array();
    
    /**
     * Singleton pattern
     */
    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->check_and_load_dependencies();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_notices', array($this, 'admin_notices'));
        
        // Always add AJAX handlers - we'll check dependencies inside the methods
        add_action('wp_ajax_cdi_upload_csv', array($this, 'handle_ajax_upload'));
        add_action('wp_ajax_cdi_preview_data', array($this, 'handle_ajax_preview'));
        add_action('wp_ajax_cdi_process_import', array($this, 'handle_ajax_import'));
        add_action('wp_ajax_cdi_search_casino', array($this, 'handle_ajax_search'));
        add_action('wp_ajax_cdi_resolve_queue_item', array($this, 'handle_ajax_resolve'));
        
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Check for required files and load dependencies
     */
    private function check_and_load_dependencies() {
        $required_files = array(
            'cdi-functions.php' => CDI_INCLUDES_DIR . 'cdi-functions.php',
            'class-cdi-admin.php' => CDI_INCLUDES_DIR . 'class-cdi-admin.php',
            'class-cdi-processor.php' => CDI_INCLUDES_DIR . 'class-cdi-processor.php',
            'class-cdi-matcher.php' => CDI_INCLUDES_DIR . 'class-cdi-matcher.php'
        );
        
        // Check which files are missing
        $this->missing_files = array();
        foreach ($required_files as $filename => $filepath) {
            if (!file_exists($filepath) || !is_readable($filepath)) {
                $this->missing_files[] = $filename;
            }
        }
        
        // If any files are missing, don't load dependencies
        if (!empty($this->missing_files)) {
            error_log('CDI: Missing files: ' . implode(', ', $this->missing_files));
            return;
        }
        
        // Try to load all dependencies
        try {
            // Load functions first
            require_once $required_files['cdi-functions.php'];
            
            // Load classes
            require_once $required_files['class-cdi-admin.php'];
            require_once $required_files['class-cdi-processor.php'];
            require_once $required_files['class-cdi-matcher.php'];
            
            // Initialize classes
            $this->admin = new CDI_Admin();
            $this->processor = new CDI_Processor();
            $this->matcher = new CDI_Matcher();
            
            $this->dependencies_loaded = true;
            
        } catch (Exception $e) {
            error_log('CDI: Failed to load dependencies: ' . $e->getMessage());
            $this->dependencies_loaded = false;
        }
    }
    
    /**
     * Display admin notices for missing files
     */
    public function admin_notices() {
        if (!empty($this->missing_files)) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Craps Data Importer:</strong> Required plugin files are missing. ';
            echo 'Missing files: <code>' . implode('</code>, <code>', $this->missing_files) . '</code>';
            echo '</p><p>';
            echo 'Please ensure these files exist in the <code>' . CDI_INCLUDES_DIR . '</code> directory.';
            echo '</p></div>';
        }
    }
    
    /**
     * Initialize plugin
     */
    public function init() {
        load_plugin_textdomain('craps-data-importer', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }
    
    /**
     * Add admin menu pages
     */
    public function add_admin_menu() {
        add_menu_page(
            __('Craps Data Importer', 'craps-data-importer'),
            __('Craps Import', 'craps-data-importer'),
            'manage_options',
            'craps-data-importer',
            array($this, 'render_main_page'),
            'dashicons-upload',
            30
        );
        
        if ($this->dependencies_loaded) {
            add_submenu_page(
                'craps-data-importer',
                __('Review Queue', 'craps-data-importer'),
                __('Review Queue', 'craps-data-importer'),
                'manage_options',
                'craps-review-queue',
                array($this->admin, 'render_review_page')
            );
            
            add_submenu_page(
                'craps-data-importer',
                __('Import History', 'craps-data-importer'),
                __('Import History', 'craps-data-importer'),
                'manage_options',
                'craps-import-history',
                array($this->admin, 'render_history_page')
            );
        }
    }
    
    /**
     * Render main page (either working interface or error page)
     */
    public function render_main_page() {
        if ($this->dependencies_loaded && $this->admin) {
            $this->admin->render_main_page();
        } else {
            $this->render_error_page();
        }
    }
    
    /**
     * Render error page when dependencies can't be loaded
     */
    private function render_error_page() {
        echo '<div class="wrap">';
        echo '<h1>Craps Data Importer - Setup Required</h1>';
        
        echo '<div class="notice notice-error inline"><p>';
        echo '<strong>Plugin files are missing or corrupted.</strong><br>';
        echo 'The following files need to be uploaded to the <code>includes/</code> directory:';
        echo '<ul>';
        
        $required_files = array('cdi-functions.php', 'class-cdi-admin.php', 'class-cdi-processor.php', 'class-cdi-matcher.php');
        foreach ($required_files as $file) {
            $status = in_array($file, $this->missing_files) ? '❌ Missing' : '✅ Found';
            echo '<li><code>' . $file . '</code> - ' . $status . '</li>';
        }
        
        echo '</ul>';
        echo '</p></div>';
        
        echo '<h2>Diagnostic Information:</h2>';
        echo '<table class="form-table">';
        echo '<tr><th>Plugin Directory</th><td><code>' . CDI_PLUGIN_DIR . '</code></td></tr>';
        echo '<tr><th>Includes Directory</th><td><code>' . CDI_INCLUDES_DIR . '</code></td></tr>';
        echo '<tr><th>Directory Exists</th><td>' . (is_dir(CDI_INCLUDES_DIR) ? '✅ Yes' : '❌ No') . '</td></tr>';
        echo '<tr><th>Directory Readable</th><td>' . (is_readable(CDI_INCLUDES_DIR) ? '✅ Yes' : '❌ No') . '</td></tr>';
        echo '</table>';
        
        if (is_dir(CDI_INCLUDES_DIR)) {
            echo '<h3>Files in includes/ directory:</h3>';
            $files = scandir(CDI_INCLUDES_DIR);
            if ($files) {
                echo '<ul>';
                foreach ($files as $file) {
                    if ($file !== '.' && $file !== '..') {
                        $filepath = CDI_INCLUDES_DIR . $file;
                        $filesize = file_exists($filepath) ? size_format(filesize($filepath)) : 'Unknown';
                        echo '<li><code>' . $file . '</code> (' . $filesize . ')</li>';
                    }
                }
                echo '</ul>';
            } else {
                echo '<p>Could not read directory contents.</p>';
            }
        }
        
        echo '<h2>Quick Fix Options:</h2>';
        echo '<ol>';
        echo '<li><strong>Download fresh files:</strong> Get the latest plugin files from your repository</li>';
        echo '<li><strong>Check file permissions:</strong> Ensure files have 644 permissions and directories have 755</li>';
        echo '<li><strong>Upload via FTP:</strong> Use FTP to upload files if WordPress upload failed</li>';
        echo '<li><strong>Check PHP errors:</strong> Look in your error logs for syntax errors in the files</li>';
        echo '</ol>';
        
        echo '<p><a href="' . admin_url('plugins.php') . '" class="button">← Back to Plugins</a></p>';
        echo '</div>';
    }
    
    /**
     * Enqueue admin assets - FIXED VERSION
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'craps-') === false) {
            return;
        }
        
        // Check if asset files exist before enqueueing
        $css_file = CDI_PLUGIN_DIR . 'assets/css/admin.css';
        $js_file = CDI_PLUGIN_DIR . 'assets/js/admin.js';
        
        if (file_exists($css_file)) {
            wp_enqueue_style(
                'cdi-admin-style',
                CDI_ASSETS_URL . 'css/admin.css',
                array(),
                CDI_VERSION
            );
        }
        
        if (file_exists($js_file)) {
            wp_enqueue_script(
                'cdi-admin-script',
                CDI_ASSETS_URL . 'js/admin.js',
                array('jquery'),
                CDI_VERSION,
                true
            );
            
            // FIXED: Changed from 'cdi_ajax' to 'cdiAjax' to match JavaScript expectations
            wp_localize_script('cdi-admin-script', 'cdiAjax', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('cdi_nonce'),
                'strings' => array(
                    'uploading' => __('Uploading...', 'craps-data-importer'),
                    'processing' => __('Processing...', 'craps-data-importer'),
                    'error' => __('Error:', 'craps-data-importer'),
                    'success' => __('Success!', 'craps-data-importer')
                )
            ));
        }
        
        // Add inline JavaScript for basic functionality if external file doesn't exist
        if (!file_exists($js_file)) {
            wp_add_inline_script('jquery', '
        jQuery(document).ready(function($) {
            $("#csv-upload-form").on("submit", function(e) {
                e.preventDefault();
                
                var formData = new FormData(this);
                formData.append("action", "cdi_upload_csv");
                formData.append("nonce", "' . wp_create_nonce('cdi_nonce') . '");
                
                $.ajax({
                    url: "' . admin_url('admin-ajax.php') . '",
                    type: "POST",
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success && response.data.redirect) {
                            window.location.href = response.data.redirect;
                        } else {
                            alert(response.data.message ? response.data.message : "Upload successful");
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("Error:", status, error);
                        alert("Upload failed: " + error);
                    }
                });
            });
        });
        ');
        }
    }
    
    /**
     * Handle CSV upload via AJAX
     */
    public function handle_ajax_upload() {
        // Log that the handler was called
        error_log('CDI: AJAX upload handler called');
        
        if (!$this->dependencies_loaded) {
            error_log('CDI: Dependencies not loaded');
            wp_send_json_error(array('message' => 'Plugin dependencies not loaded'));
            return;
        }
        
        // Check nonce
        if (!check_ajax_referer('cdi_nonce', 'nonce', false)) {
            error_log('CDI: Nonce verification failed');
            wp_send_json_error(array('message' => 'Security check failed'));
            return;
        }
        
        if (!current_user_can('manage_options')) {
            error_log('CDI: User permission check failed');
            wp_send_json_error(array('message' => 'Unauthorized access'));
            return;
        }
        
        try {
            error_log('CDI: Calling processor handle_csv_upload');
            $result = $this->processor->handle_csv_upload();
            error_log('CDI: Processor returned: ' . print_r($result, true));
            wp_send_json_success($result);
        } catch (Exception $e) {
            error_log('CDI: Exception in upload: ' . $e->getMessage());
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * Handle data preview via AJAX
     */
    public function handle_ajax_preview() {
        if (!$this->dependencies_loaded) {
            wp_send_json_error(array('message' => 'Plugin dependencies not loaded'));
            return;
        }
        
        check_ajax_referer('cdi_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access'));
            return;
        }
        
        try {
            $result = $this->processor->preview_csv_data();
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * Handle AJAX import processing
     */
    public function handle_ajax_import() {
        if (!$this->dependencies_loaded) {
            wp_send_json_error('Plugin dependencies not loaded properly');
            return;
        }
        
        // Let the processor handle the import
        $this->processor->process_import();
    }
    
    /**
     * Handle casino search via AJAX
     */
    public function handle_ajax_search() {
        if (!$this->dependencies_loaded) {
            wp_send_json_error(array('message' => 'Plugin dependencies not loaded'));
            return;
        }
        
        check_ajax_referer('cdi_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access'));
            return;
        }
        
        $search_term = sanitize_text_field($_POST['search'] ?? '');
        $results = $this->matcher->search_casinos($search_term);
        
        wp_send_json_success($results);
    }
    
    /**
     * Handle review queue item resolution via AJAX
     */
    public function handle_ajax_resolve() {
        if (!$this->dependencies_loaded) {
            wp_send_json_error(array('message' => 'Plugin dependencies not loaded'));
            return;
        }
        
        check_ajax_referer('cdi_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access'));
            return;
        }
        
        try {
            $queue_id = absint($_POST['queue_id'] ?? 0);
            $action = sanitize_text_field($_POST['action'] ?? '');
            $casino_id = absint($_POST['casino_id'] ?? 0);
            
            $result = $this->matcher->resolve_queue_item($queue_id, $action, $casino_id);
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create assets directories if they don't exist
        $css_dir = CDI_PLUGIN_DIR . 'assets/css/';
        $js_dir = CDI_PLUGIN_DIR . 'assets/js/';
        
        if (!file_exists($css_dir)) {
            wp_mkdir_p($css_dir);
        }
        
        if (!file_exists($js_dir)) {
            wp_mkdir_p($js_dir);
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        wp_clear_scheduled_hook('cdi_cleanup_old_imports');
        delete_transient('cdi_csv_data');
        delete_transient('cdi_preview_data');
    }
    
    /**
     * Get admin instance
     */
    public function get_admin() {
        return $this->admin;
    }
    
    /**
     * Get processor instance
     */
    public function get_processor() {
        return $this->processor;
    }
    
    /**
     * Get matcher instance
     */
    public function get_matcher() {
        return $this->matcher;
    }
}

/**
 * Initialize the plugin
 */
function cdi_init() {
    return CrapsDataImporter::instance();
}

// Start the plugin
cdi_init();

/**
 * Helper function to get plugin instance
 */
function cdi() {
    return CrapsDataImporter::instance();
}