<?php
/**
 * Plugin Name: Craps Data Importer
 * Plugin URI: https://bubble-craps.com
 * Description: Import and manage craps casino data from community spreadsheets with enhanced fuzzy matching and detailed reporting
 * Version: 1.0.0
 * Author: Bubble-Craps.com
 * License: GPL v2 or later
 * Text Domain: craps-data-importer
 * Domain Path: /languages
 * 
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
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
define('CDI_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Main Plugin Bootstrap Class
 */
class CrapsDataImporter {
    
    private static $instance = null;
    
    /**
     * Get singleton instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Constructor - Initialize the plugin
     */
    private function __construct() {
        add_action('plugins_loaded', array($this, 'init'));
        
        // Activation and deactivation hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
    }
    
    /**
     * Initialize plugin after WordPress loads
     */
    public function init() {
        // Load text domain for translations
        load_plugin_textdomain('craps-data-importer', false, dirname(CDI_PLUGIN_BASENAME) . '/languages');
        
        // Check minimum requirements
        if (!$this->check_requirements()) {
            return;
        }
        
        // Load required files
        $this->load_files();
        
        // Initialize main plugin class
        CDI_Main::get_instance();
    }
    
    /**
     * Load all required plugin files
     */
    private function load_files() {
        $files = array(
            'includes/functions.php',
            'includes/class-main.php',
            'includes/class-file-handler.php',
            'includes/class-matcher.php',
            'includes/class-processor.php',
            'includes/class-admin.php'
        );
        
        foreach ($files as $file) {
            $file_path = CDI_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                add_action('admin_notices', function() use ($file) {
                    echo '<div class="notice notice-error"><p>';
                    echo sprintf(__('Craps Data Importer: Missing required file %s', 'craps-data-importer'), $file);
                    echo '</p></div>';
                });
            }
        }
    }
    
    /**
     * Check minimum WordPress and PHP requirements
     */
    private function check_requirements() {
        global $wp_version;
        
        $min_wp_version = '5.0';
        $min_php_version = '7.4';
        
        $errors = array();
        
        // Check WordPress version
        if (version_compare($wp_version, $min_wp_version, '<')) {
            $errors[] = sprintf(
                __('WordPress %s or higher is required. You are running version %s.', 'craps-data-importer'),
                $min_wp_version,
                $wp_version
            );
        }
        
        // Check PHP version
        if (version_compare(PHP_VERSION, $min_php_version, '<')) {
            $errors[] = sprintf(
                __('PHP %s or higher is required. You are running version %s.', 'craps-data-importer'),
                $min_php_version,
                PHP_VERSION
            );
        }
        
        // Check if Directorist is active (our target post type)
        if (!post_type_exists('at_biz_dir')) {
            $errors[] = __('Directorist plugin is required for this importer to work.', 'craps-data-importer');
        }
        
        // Display errors if any
        if (!empty($errors)) {
            add_action('admin_notices', function() use ($errors) {
                echo '<div class="notice notice-error"><p>';
                echo '<strong>' . __('Craps Data Importer:', 'craps-data-importer') . '</strong><br>';
                echo implode('<br>', $errors);
                echo '</p></div>';
            });
            return false;
        }
        
        return true;
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Create database tables and set default options
        if (class_exists('CDI_Main')) {
            CDI_Main::activate();
        }
        
        // Set activation flag for any one-time setup
        add_option('cdi_activation_time', current_time('mysql'));
        add_option('cdi_version', CDI_VERSION);
        
        // Clear any caches
        wp_cache_flush();
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events if any
        wp_clear_scheduled_hook('cdi_cleanup_old_data');
        
        // Clear caches
        wp_cache_flush();
    }
}

// Initialize the plugin
CrapsDataImporter::get_instance();