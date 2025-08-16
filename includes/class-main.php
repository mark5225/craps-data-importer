<?php
/**
 * Main Plugin Class - Core functionality and coordination
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class CDI_Main {
    
    private static $instance = null;
    private $file_handler;
    private $matcher;
    private $processor;
    private $admin;
    
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
     * Constructor
     */
    private function __construct() {
        $this->init_hooks();
        $this->init_components();
    }
    
    /**
     * Initialize WordPress hooks
     */
    private function init_hooks() {
        add_action('init', array($this, 'init_database'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        
        // Schedule cleanup task
        if (!wp_next_scheduled('cdi_cleanup_old_data')) {
            wp_schedule_event(time(), 'daily', 'cdi_cleanup_old_data');
        }
        add_action('cdi_cleanup_old_data', array($this, 'cleanup_old_data'));
    }
    
    /**
     * Initialize plugin components
     */
    private function init_components() {
        $this->file_handler = new CDI_File_Handler();
        $this->matcher = new CDI_Matcher();
        $this->processor = new CDI_Processor($this->matcher);
        $this->admin = new CDI_Admin($this->file_handler, $this->matcher, $this->processor);
    }
    
    /**
     * Initialize database tables and options
     */
    public function init_database() {
        $this->create_tables();
        $this->set_default_options();
    }
    
    /**
     * Create necessary database tables
     */
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        // Import logs table
        $logs_table = $wpdb->prefix . 'cdi_import_logs';
        $logs_sql = "CREATE TABLE IF NOT EXISTS $logs_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            import_session varchar(50) NOT NULL,
            action_type varchar(50) NOT NULL,
            casino_name varchar(255) NOT NULL,
            casino_id bigint(20) unsigned NULL,
            spreadsheet_location varchar(255) NULL,
            matching_info text NULL,
            changes_made text NULL,
            status varchar(20) DEFAULT 'pending',
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY import_session (import_session),
            KEY action_type (action_type),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        // Review queue table
        $queue_table = $wpdb->prefix . 'cdi_review_queue';
        $queue_sql = "CREATE TABLE IF NOT EXISTS $queue_table (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            casino_name varchar(255) NOT NULL,
            region varchar(100) NULL,
            spreadsheet_data longtext NOT NULL,
            reason text NULL,
            status varchar(20) DEFAULT 'pending',
            assigned_casino_id bigint(20) unsigned NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            processed_at datetime NULL,
            PRIMARY KEY (id),
            KEY status (status),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($logs_sql);
        dbDelta($queue_sql);
        
        // Update database version
        update_option('cdi_db_version', CDI_VERSION);
    }
    
    /**
     * Set default plugin options
     */
    private function set_default_options() {
        $defaults = array(
            'cdi_auto_clean' => '1',
            'cdi_notification_email' => get_option('admin_email'),
            'cdi_batch_size' => 50,
            'cdi_similarity_threshold' => 70,
            'cdi_location_boost' => 10,
            'cdi_max_upload_size' => '5MB',
            'cdi_allowed_file_types' => 'csv,xlsx,xls'
        );
        
        foreach ($defaults as $option => $value) {
            if (!get_option($option)) {
                add_option($option, $value);
            }
        }
    }
    
    /**
     * Enqueue admin assets
     */
    public function enqueue_admin_assets($hook) {
        // Only load on our admin pages
        if (strpos($hook, 'craps-') === false) {
            return;
        }
        
        // Enqueue CSS
        wp_enqueue_style(
            'cdi-admin',
            CDI_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            CDI_VERSION
        );
        
        // Enqueue JavaScript
        wp_enqueue_script(
            'cdi-admin',
            CDI_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            CDI_VERSION,
            true
        );
        
        // Localize script data
        wp_localize_script('cdi-admin', 'cdiAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('cdi_admin_nonce'),
            'strings' => array(
                'confirm_clear' => __('Are you sure you want to clear all import data?', 'craps-data-importer'),
                'confirm_delete' => __('Are you sure you want to delete this item?', 'craps-data-importer'),
                'processing' => __('Processing...', 'craps-data-importer'),
                'error' => __('An error occurred. Please try again.', 'craps-data-importer'),
                'success' => __('Operation completed successfully.', 'craps-data-importer')
            )
        ));
    }
    
    /**
     * Clean up old data based on settings
     */
    public function cleanup_old_data() {
        global $wpdb;
        
        $days_to_keep = apply_filters('cdi_data_retention_days', 30);
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days_to_keep} days"));
        
        // Clean old import logs
        $wpdb->delete(
            $wpdb->prefix . 'cdi_import_logs',
            array('created_at' => $cutoff_date),
            array('%s')
        );
        
        // Clean old processed review queue items
        $wpdb->delete(
            $wpdb->prefix . 'cdi_review_queue',
            array(
                'status' => 'processed',
                'processed_at' => $cutoff_date
            ),
            array('%s', '%s')
        );
        
        // Clean up temporary import data options
        $temp_options = array(
            'cdi_excel_data',
            'cdi_import_progress',
            'cdi_last_import_results'
        );
        
        foreach ($temp_options as $option) {
            $option_data = get_option($option);
            if ($option_data && isset($option_data['timestamp'])) {
                $option_time = strtotime($option_data['timestamp']);
                if ($option_time < strtotime("-7 days")) {
                    delete_option($option);
                }
            }
        }
    }
    
    /**
     * Get plugin statistics
     */
    public function get_statistics() {
        global $wpdb;
        
        $stats = array();
        
        // Import statistics
        $stats['imports'] = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cdi_import_logs"),
            'this_month' => $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(*) FROM {$wpdb->prefix}cdi_import_logs 
                WHERE created_at >= %s
            ", date('Y-m-01 00:00:00')))
        );
        
        // Queue statistics
        $stats['queue'] = array(
            'pending' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cdi_review_queue WHERE status = 'pending'"),
            'processed' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}cdi_review_queue WHERE status = 'processed'")
        );
        
        // Casino statistics
        $stats['casinos'] = array(
            'total' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'at_biz_dir' AND post_status = 'publish'"),
            'with_bubble_craps' => $this->count_casinos_with_bubble_craps()
        );
        
        // Recent activity
        $stats['recent_activity'] = $wpdb->get_results($wpdb->prepare("
            SELECT action_type, COUNT(*) as count 
            FROM {$wpdb->prefix}cdi_import_logs 
            WHERE created_at >= %s 
            GROUP BY action_type
        ", date('Y-m-d H:i:s', strtotime('-7 days'))));
        
        return $stats;
    }
    
    /**
     * Count casinos with bubble craps
     */
    private function count_casinos_with_bubble_craps() {
        $terms = get_terms(array(
            'taxonomy' => 'at_biz_dir-category',
            'name' => array('Has Bubble Craps', 'Bubble Craps'),
            'hide_empty' => true,
            'fields' => 'ids'
        ));
        
        if (empty($terms) || is_wp_error($terms)) {
            return 0;
        }
        
        $args = array(
            'post_type' => 'at_biz_dir',
            'post_status' => 'publish',
            'tax_query' => array(
                array(
                    'taxonomy' => 'at_biz_dir-category',
                    'field' => 'term_id',
                    'terms' => $terms
                )
            ),
            'fields' => 'ids',
            'posts_per_page' => -1
        );
        
        $query = new WP_Query($args);
        return $query->found_posts;
    }
    
    /**
     * Log import action
     */
    public function log_import_action($session_id, $action_type, $casino_name, $data = array()) {
        global $wpdb;
        
        $log_data = array(
            'import_session' => $session_id,
            'action_type' => $action_type,
            'casino_name' => $casino_name,
            'casino_id' => $data['casino_id'] ?? null,
            'spreadsheet_location' => $data['location'] ?? null,
            'matching_info' => $data['matching_info'] ?? null,
            'changes_made' => is_array($data['changes']) ? wp_json_encode($data['changes']) : $data['changes'] ?? null,
            'status' => $data['status'] ?? 'completed'
        );
        
        $wpdb->insert(
            $wpdb->prefix . 'cdi_import_logs',
            $log_data,
            array('%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s')
        );
        
        return $wpdb->insert_id;
    }
    
    /**
     * Get import logs
     */
    public function get_import_logs($session_id = null, $limit = 100) {
        global $wpdb;
        
        $where = '';
        $params = array();
        
        if ($session_id) {
            $where = 'WHERE import_session = %s';
            $params[] = $session_id;
        }
        
        $sql = "SELECT * FROM {$wpdb->prefix}cdi_import_logs $where ORDER BY created_at DESC LIMIT %d";
        $params[] = $limit;
        
        return $wpdb->get_results($wpdb->prepare($sql, $params));
    }
    
    /**
     * Send notification email
     */
    public function send_notification($subject, $message, $data = array()) {
        $email = get_option('cdi_notification_email', get_option('admin_email'));
        
        if (!$email) {
            return false;
        }
        
        $headers = array('Content-Type: text/html; charset=UTF-8');
        
        $html_message = '<html><body>';
        $html_message .= '<h2>' . esc_html($subject) . '</h2>';
        $html_message .= '<p>' . nl2br(esc_html($message)) . '</p>';
        
        if (!empty($data)) {
            $html_message .= '<h3>Details:</h3><ul>';
            foreach ($data as $key => $value) {
                $html_message .= '<li><strong>' . esc_html(ucwords(str_replace('_', ' ', $key))) . ':</strong> ' . esc_html($value) . '</li>';
            }
            $html_message .= '</ul>';
        }
        
        $html_message .= '<p><a href="' . admin_url('admin.php?page=craps-data-importer') . '">View Import Dashboard</a></p>';
        $html_message .= '</body></html>';
        
        return wp_mail($email, $subject, $html_message, $headers);
    }
    
    /**
     * Static activation method for plugin activation hook
     */
    public static function activate() {
        // Create instance to trigger database creation
        $instance = self::get_instance();
        $instance->init_database();
        
        // Flush rewrite rules if needed
        flush_rewrite_rules();
    }
    
    /**
     * Get component instances
     */
    public function get_file_handler() {
        return $this->file_handler;
    }
    
    public function get_matcher() {
        return $this->matcher;
    }
    
    public function get_processor() {
        return $this->processor;
    }
    
    public function get_admin() {
        return $this->admin;
    }
}