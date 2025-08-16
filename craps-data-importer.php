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
 * Main Plugin Bootstrap Class - MINIMAL VERSION FOR DEBUGGING
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
        
        // DEBUGGING: Let's see what files exist
        $this->check_files();
        
        // Load required files
        $this->load_files();
        
        // Initialize main plugin class only if all files loaded
        if (class_exists('CDI_Main')) {
            CDI_Main::get_instance();
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>CDI_Main class not found. Check file loading.</p></div>';
            });
        }
    }
    
    /**
     * Check which files exist - DEBUGGING
     */
    private function check_files() {
        $files = array(
            'includes/functions.php',
            'includes/class-main.php',
            'includes/class-file-handler.php',
            'includes/class-matcher.php',
            'includes/class-processor.php',
            'includes/class-admin.php'
        );
        
        $missing = array();
        foreach ($files as $file) {
            $file_path = CDI_PLUGIN_DIR . $file;
            if (!file_exists($file_path)) {
                $missing[] = $file;
            }
        }
        
        if (!empty($missing)) {
            add_action('admin_notices', function() use ($missing) {
                echo '<div class="notice notice-error"><p><strong>Missing files:</strong><br>' . implode('<br>', $missing) . '</p></div>';
            });
        }
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
            }
        }
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        // Simple activation - just set a flag
        add_option('cdi_activation_time', current_time('mysql'));
        add_option('cdi_version', CDI_VERSION);
        
        // Try to activate main class if it exists
        if (class_exists('CDI_Main')) {
            CDI_Main::activate();
        }
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Simple cleanup
        wp_clear_scheduled_hook('cdi_cleanup_old_data');
        wp_cache_flush();
    }
}

// Initialize the plugin
CrapsDataImporter::get_instance();<?php
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
 * Main Plugin Bootstrap Class - SIMPLIFIED
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