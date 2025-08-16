<?php
/**
 * Plugin Name: Craps Data Importer
 * Description: Import CSV data for craps tables and bubble craps machines with smart casino matching
 * Version: 1.0.0
 * Author: Bubble Craps Team
 * Text Domain: craps-data-importer
 * Domain Path: /languages
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('CDI_VERSION', '1.0.0');
define('CDI_PLUGIN_FILE', __FILE__);
define('CDI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CDI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('CDI_INCLUDES_DIR', CDI_PLUGIN_DIR . 'includes/');

/**
 * Main plugin class
 */
class CrapsDataImporter {
    
    private static $instance = null;
    private $admin;
    private $processor;
    private $matcher;
    
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
        add_action('wp_ajax_cdi_upload_csv', array($this, 'handle_ajax_upload'));
        add_action('wp_ajax_cdi_preview_data', array($this, 'handle_ajax_preview'));
        add_action('wp_ajax_cdi_process_import', array($this, 'handle_ajax_import'));
        add_action('wp_ajax_cdi_search_casino', array($this, 'handle_ajax_search'));
        add_action('wp_ajax_cdi_resolve_queue_item', array($this, 'handle_ajax_resolve'));
        
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Load required files
     */
    private function load_dependencies() {
        require_once CDI_INCLUDES_DIR . 'class-cdi-admin.php';
        require_once CDI_INCLUDES_DIR . 'class-cdi-processor.php';
        require_once CDI_INCLUDES_DIR . 'class-cdi-matcher.php';
        require_once CDI_INCLUDES_DIR . 'cdi-functions.php';
        
        $this->admin = new CDI_Admin();
        $this->processor = new CDI_Processor();
        $this->matcher = new CDI_Matcher();
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
            wp_die(__('Unauthorized access', 'craps-data-importer'));
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
            wp_die(__('Unauthorized access', 'craps-data-importer'));
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
            wp_die(__('Unauthorized access', 'craps-data-importer'));
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
            wp_die(__('Unauthorized access', 'craps-data-importer'));
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
            wp_die(__('Unauthorized access', 'craps-data-importer'));
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
        // Create review queue table
        global $wpdb;
        
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
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clean up scheduled events if any
        wp_clear_scheduled_hook('cdi_cleanup_old_imports');
        
        // Clean up transients
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