<?php
/**
 * Plugin Name: Craps Data Importer
 * Description: Import CSV data for craps tables and bubble craps machines with smart casino matching
 * Version: 1.0.1
 * Author: Bubble Craps Team
 * Text Domain: craps-data-importer
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CDI_VERSION', '1.0.1');
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
        $this->load_dependencies();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'init'));
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // AJAX handlers
        add_action('wp_ajax_cdi_upload_csv', array($this, 'handle_ajax_upload'));
        add_action('wp_ajax_cdi_preview_data', array($this, 'handle_ajax_preview'));
        add_action('wp_ajax_cdi_process_import', array($this, 'handle_ajax_import'));
        add_action('wp_ajax_cdi_search_casino', array($this, 'handle_ajax_search'));
        add_action('wp_ajax_cdi_resolve_queue_item', array($this, 'handle_ajax_resolve'));
        
        add_action('admin_notices', array($this, 'admin_notices'));
        
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        // Load core functions first
        require_once CDI_INCLUDES_DIR . 'cdi-functions.php';
        
        // Load classes
        require_once CDI_INCLUDES_DIR . 'class-cdi-admin.php';
        require_once CDI_INCLUDES_DIR . 'class-cdi-processor.php';
        require_once CDI_INCLUDES_DIR . 'class-cdi-matcher.php';
        
        // Initialize classes
        $this->admin = new CDI_Admin();
        $this->processor = new CDI_Processor();
        $this->matcher = new CDI_Matcher();
        
        $this->dependencies_loaded = true;
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our plugin pages
        if (strpos($hook, 'craps-') === false) {
            return;
        }
        
        // Enqueue CSS
        wp_enqueue_style(
            'cdi-admin-style',
            CDI_ASSETS_URL . 'css/admin.css',
            array(),
            CDI_VERSION
        );
        
        // Enqueue JavaScript
        wp_enqueue_script(
            'cdi-admin-script',
            CDI_ASSETS_URL . 'js/admin.js',
            array('jquery'),
            CDI_VERSION,
            true
        );
        
        // Localize script
        wp_localize_script('cdi-admin-script', 'cdiAjax', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cdi_nonce'),
            'strings' => array(
                'upload_success' => __('File uploaded successfully', 'craps-data-importer'),
                'upload_error' => __('Upload failed', 'craps-data-importer'),
                'processing' => __('Processing...', 'craps-data-importer'),
                'complete' => __('Import complete', 'craps-data-importer')
            )
        ));
    }
    
    /**
     * Display admin notices
     */
    public function admin_notices() {
        if (!$this->dependencies_loaded) {
            echo '<div class="notice notice-error"><p>';
            echo '<strong>Craps Data Importer:</strong> Required plugin files are missing. ';
            echo 'Please ensure all files are properly uploaded.';
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
        if (!$this->dependencies_loaded) {
            return;
        }
        
        add_menu_page(
            __('Craps Data Importer', 'craps-data-importer'),
            __('Craps Import', 'craps-data-importer'),
            'manage_options',
            'craps-data-importer',
            array($this->admin, 'render_main_page'),
            'dashicons-upload',
            30
        );
        
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
    
    /**
     * Handle CSV upload via AJAX
     */
    public function handle_ajax_upload() {
        check_ajax_referer('cdi_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access'));
        }
        
        try {
            $result = $this->processor->handle_csv_upload();
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * Handle data preview via AJAX
     */
    public function handle_ajax_preview() {
        check_ajax_referer('cdi_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access'));
        }
        
        try {
            $result = $this->processor->preview_csv_data();
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * Handle import processing via AJAX
     */
    public function handle_ajax_import() {
        check_ajax_referer('cdi_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access'));
        }
        
        try {
            $settings = array(
                'auto_update' => filter_var($_POST['auto_update'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'similarity_threshold' => absint($_POST['similarity_threshold'] ?? 80),
                'update_existing' => filter_var($_POST['update_existing'] ?? true, FILTER_VALIDATE_BOOLEAN)
            );
            
            $result = $this->processor->process_import($settings);
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * Handle casino search via AJAX
     */
    public function handle_ajax_search() {
        check_ajax_referer('cdi_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access'));
        }
        
        $search_term = sanitize_text_field($_POST['search'] ?? '');
        $results = $this->matcher->search_casinos($search_term);
        
        wp_send_json_success($results);
    }
    
    /**
     * Handle review queue item resolution via AJAX
     */
    public function handle_ajax_resolve() {
        check_ajax_referer('cdi_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Unauthorized access'));
        }
        
        try {
            $queue_id = absint($_POST['queue_id'] ?? 0);
            $action = sanitize_text_field($_POST['action'] ?? '');
            $casino_id = absint($_POST['casino_id'] ?? 0);
            
            $result = $this->processor->resolve_queue_item($queue_id, $action, $casino_id);
            wp_send_json_success($result);
        } catch (Exception $e) {
            wp_send_json_error(array('message' => $e->getMessage()));
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        global $wpdb;
        
        // Create review queue table
        $table_name = $wpdb->prefix . 'cdi_review_queue';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE $table_name (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            casino_name varchar(255) NOT NULL,
            csv_data longtext NOT NULL,
            reason varchar(255) NOT NULL,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Create import history table
        $history_table = $wpdb->prefix . 'cdi_import_history';
        
        $sql2 = "CREATE TABLE $history_table (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            filename varchar(255) NOT NULL,
            total_rows int(11) NOT NULL,
            processed_rows int(11) NOT NULL,
            updated_casinos int(11) NOT NULL,
            queued_items int(11) NOT NULL,
            import_settings longtext,
            import_date datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY import_date (import_date)
        ) $charset_collate;";
        
        dbDelta($sql2);
        
        // Set default options
        add_option('cdi_similarity_threshold', 80);
        add_option('cdi_auto_update', 1);
        add_option('cdi_update_existing', 1);
        
        // Create assets directory if it doesn't exist
        $this->create_assets_directory();
    }
    
    /**
     * Create assets directory and files
     */
    private function create_assets_directory() {
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