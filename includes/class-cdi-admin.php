<?php
/**
 * CDI_Admin - Admin interface for Craps Data Importer
 */

if (!defined('ABSPATH')) {
    exit;
}

class CDI_Admin {
    
    public function __construct() {
        // Initialize admin hooks if needed
    }
    
    /**
     * Render main admin page
     */
    public function render_main_page() {
        $step = isset($_GET['step']) ? sanitize_text_field($_GET['step']) : 'upload';
        
        switch ($step) {
            case 'preview':
                $this->render_review_page();
                break;
            case 'import':
                $this->render_import_results_page();
                break;
            default:
                $this->render_upload_page();
                break;
        }
    }
    
    /**
     * Render upload page
     */
    private function render_upload_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Craps Data Importer', 'craps-data-importer'); ?></h1>
            
            <div class="cdi-upload-section">
                <div class="postbox">
                    <h2 class="hndle"><?php _e('Upload CSV File', 'craps-data-importer'); ?></h2>
                    <div class="inside">
                        <form id="csv-upload-form" enctype="multipart/form-data">
                            <table class="form-table">
                                <tr>
                                    <th scope="row">
                                        <label for="csv_file"><?php _e('CSV File', 'craps-data-importer'); ?></label>
                                    </th>
                                    <td>
                                        <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                                        <p class="description">
                                            <?php _e('Select a CSV file containing craps data. Maximum file size: 15MB.', 'craps-data-importer'); ?>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <p class="submit">
                                <button type="submit" class="button button-primary">
                                    <?php _e('Upload and Preview', 'craps-data-importer'); ?>
                                </button>
                            </p>
                        </form>
                    </div>
                </div>
                
                <div class="postbox">
                    <h2 class="hndle"><?php _e('File Requirements', 'craps-data-importer'); ?></h2>
                    <div class="inside">
                        <ul>
                            <li><?php _e('CSV format only (.csv)', 'craps-data-importer'); ?></li>
                            <li><?php _e('First row should contain column headers', 'craps-data-importer'); ?></li>
                            <li><?php _e('Expected columns: Downtown Casino, WeekDay Min, WeekNight Min, etc.', 'craps-data-importer'); ?></li>
                            <li><?php _e('Maximum file size: 15MB', 'craps-data-importer'); ?></li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
        
        <style>
        .cdi-upload-section {
            max-width: 800px;
        }
        .postbox {
            margin-bottom: 20px;
        }
        .postbox h2.hndle {
            padding: 8px 12px;
            margin: 0;
            font-size: 14px;
            line-height: 1.4;
        }
        .postbox .inside {
            padding: 12px;
        }
        #csv-upload-form {
            margin: 0;
        }
        </style>
        <?php
    }
    
    /**
     * Render review page with changes analysis
     */
    private function render_review_page() {
        $csv_data = get_transient('cdi_csv_data');
        
        if (!$csv_data) {
            ?>
            <div class="wrap">
                <h1><?php _e('Craps Data Importer', 'craps-data-importer'); ?></h1>
                <div class="notice notice-error">
                    <p><?php _e('No CSV data found. Please upload a file first.', 'craps-data-importer'); ?></p>
                </div>
                <p>
                    <a href="<?php echo admin_url('admin.php?page=craps-data-importer'); ?>" class="button">
                        <?php _e('← Back to Upload', 'craps-data-importer'); ?>
                    </a>
                </p>
            </div>
            <?php
            return;
        }
        
        // Process the data to find matches and changes
        $processor = new CDI_Processor();
        $review_data = $processor->prepare_review_data($csv_data['data']);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Review Changes', 'craps-data-importer'); ?></h1>
            
            <div class="notice notice-info">
                <p>
                    <strong><?php _e('File:', 'craps-data-importer'); ?></strong> <?php echo esc_html($csv_data['filename']); ?><br>
                    <strong><?php _e('Total Rows:', 'craps-data-importer'); ?></strong> <?php echo count($csv_data['data']); ?><br>
                    <strong><?php _e('Matched Entries:', 'craps-data-importer'); ?></strong> <?php echo count(array_filter($review_data, function($item) { return $item['casino_id']; })); ?><br>
                    <strong><?php _e('New Entries:', 'craps-data-importer'); ?></strong> <?php echo count(array_filter($review_data, function($item) { return !$item['casino_id']; })); ?>
                </p>
            </div>
            
            <form id="import-form" method="post">
                <?php wp_nonce_field('cdi_nonce', 'nonce'); ?>
                
                <div class="tablenav top">
                    <div class="alignleft actions">
                        <button type="button" id="select-all-bubble" class="button">Select All Bubble Craps</button>
                        <button type="button" id="select-all-table" class="button">Select All Table Only</button>
                        <button type="button" id="select-all-updates" class="button">Select All Changes</button>
                        <button type="button" id="deselect-all" class="button">Deselect All</button>
                    </div>
                    <div class="alignright actions">
                        <button type="submit" class="button button-primary button-large">
                            <?php _e('Process Selected Changes', 'craps-data-importer'); ?>
                        </button>
                    </div>
                </div>
                
                <div class="cdi-review-container">
                    <?php 
                    // Organize casinos by type
                    $bubble_casinos = array_filter($review_data, function($item) { return $item['is_bubble_craps']; });
                    $table_only_casinos = array_filter($review_data, function($item) { return !$item['is_bubble_craps']; });
                    ?>
                    
                    <?php if (!empty($bubble_casinos)): ?>
                        <div class="casino-section">
                            <h2 class="section-header bubble-craps-header">
                                🎲 Bubble Craps Casinos (<?php echo count($bubble_casinos); ?>)
                            </h2>
                            <?php foreach ($bubble_casinos as $index => $item): ?>
                                <?php $this->render_casino_item($item, $index, 'bubble'); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($table_only_casinos)): ?>
                        <div class="casino-section">
                            <h2 class="section-header table-craps-header">
                                🎯 Table Craps Only (<?php echo count($table_only_casinos); ?>)
                            </h2>
                            <?php foreach ($table_only_casinos as $index => $item): ?>
                                <?php $this->render_casino_item($item, $index, 'table'); ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <p class="submit">
                    <button type="submit" class="button button-primary button-large">
                        <?php _e('Process Selected Changes', 'craps-data-importer'); ?>
                    </button>
                    <a href="<?php echo admin_url('admin.php?page=craps-data-importer'); ?>" class="button">
                        <?php _e('← Upload Different File', 'craps-data-importer'); ?>
                    </a>
                </p>
            </form>
        </div>
        
        <style>
        .cdi-review-container {
            margin: 20px 0;
        }
        
        .casino-section {
            margin-bottom: 40px;
        }
        
        .section-header {
            background: linear-gradient(135deg, #f1f3f4 0%, #e8eaed 100%);
            padding: 15px 20px;
            margin: 0 0 20px 0;
            border-radius: 8px;
            border-left: 5px solid #1d3557;
            font-size: 18px;
            font-weight: 600;
            color: #1d3557;
        }
        
        .bubble-craps-header {
            border-left-color: #e63946;
            background: linear-gradient(135deg, #fff5f5 0%, #fed7d7 100%);
            color: #c53030;
        }
        
        .table-craps-header {
            border-left-color: #2d3748;
            background: linear-gradient(135deg, #f7fafc 0%, #edf2f7 100%);
            color: #2d3748;
        }
        
        .cdi-review-item {
            background: white;
            border: 1px solid #ddd;
            margin-bottom: 20px;
            border-radius: 3px;
            overflow: hidden;
        }
        
        .cdi-review-item.has-match {
            border-left: 4px solid #46b450;
        }
        
        .cdi-review-item.no-match {
            border-left: 4px solid #ffb900;
        }
        
        .cdi-review-item.bubble-casino {
            border-left: 4px solid #e63946;
        }
        
        .review-header {
            background: #f9f9f9;
            padding: 15px;
            border-bottom: 1px solid #eee;
        }
        
        .review-checkbox {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
            font-size: 16px;
        }
        
        .review-checkbox input[type="checkbox"] {
            margin: 0;
        }
        
        .casino-controls {
            margin-left: auto;
            display: flex;
            gap: 10px;
        }
        
        .edit-whole-casino, .edit-individual-fields {
            font-size: 11px;
            padding: 3px 8px;
        }
        
        .status-badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-badge.matched {
            background: #d4edda;
            color: #155724;
        }
        
        .status-badge.new {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-badge.bubble {
            background: #ffe6e6;
            color: #c53030;
        }
        
        .view-entry, .edit-entry {
            color: #0073aa;
            text-decoration: none;
            font-size: 12px;
            margin-left: 10px;
        }
        
        .view-entry:hover, .edit-entry:hover {
            text-decoration: underline;
        }
        
        .changes-table, .new-entry-data {
            padding: 15px;
        }
        
        .changes-table h4, .new-entry-data h4 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #23282d;
        }
        
        .changes-table table {
            margin: 0;
        }
        
        .change-row.update td {
            background: #fff3cd;
        }
        
        .change-row.add td {
            background: #d1ecf1;
        }
        
        .current-value {
            color: #666;
            font-style: italic;
        }
        
        .new-value {
            color: #155724;
        }
        
        .change-badge {
            display: inline-block;
            padding: 2px 6px;
            border-radius: 2px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .change-badge.update {
            background: #856404;
            color: white;
        }
        
        .change-badge.add {
            background: #0c5460;
            color: white;
        }
        
        .no-changes {
            padding: 15px;
            color: #666;
            font-style: italic;
        }
        
        .tablenav {
            background: #f1f1f1;
            padding: 10px;
            margin-bottom: 20px;
            border-radius: 3px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .category-changes, .tag-changes {
            margin-top: 10px;
            padding: 10px;
            background: #f0f8ff;
            border-radius: 3px;
        }
        
        .category-changes h5, .tag-changes h5 {
            margin: 0 0 5px 0;
            color: #1d3557;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Select all bubble craps casinos
            $('#select-all-bubble').on('click', function() {
                $('.bubble-casino input[type="checkbox"]').prop('checked', true);
            });
            
            // Select all table only casinos
            $('#select-all-table').on('click', function() {
                $('.table-casino input[type="checkbox"]').prop('checked', true);
            });
            
            // Select all updates button
            $('#select-all-updates').on('click', function() {
                $('.cdi-review-item.has-match input[type="checkbox"]').prop('checked', true);
            });
            
            // Deselect all button
            $('#deselect-all').on('click', function() {
                $('.cdi-review-item input[type="checkbox"]').prop('checked', false);
            });
            
            // Form submission handled by existing JavaScript in admin.js
        });
        </script>
        <?php
    }
    
    /**
     * Render individual casino item
     */
    private function render_casino_item($item, $index, $section_type) {
        $casino_class = $item['casino_id'] ? 'has-match' : 'no-match';
        $casino_class .= $section_type === 'bubble' ? ' bubble-casino' : ' table-casino';
        ?>
        <div class="cdi-review-item <?php echo $casino_class; ?>">
            <div class="review-header">
                <label class="review-checkbox">
                    <input type="checkbox" name="process_row[]" value="<?php echo $index; ?>" 
                           <?php echo (!empty($item['changes']) || !$item['casino_id']) ? 'checked' : ''; ?>>
                    <strong><?php echo esc_html($item['casino_name']); ?></strong>
                    
                    <?php if ($item['casino_id']): ?>
                        <span class="status-badge matched">✓ Matched</span>
                        <?php if ($section_type === 'bubble'): ?>
                            <span class="status-badge bubble">🎲 Bubble</span>
                        <?php endif; ?>
                        <a href="<?php echo get_permalink($item['casino_id']); ?>" target="_blank" class="view-entry">
                            View Entry →
                        </a>
                        <a href="<?php echo get_edit_post_link($item['casino_id']); ?>" target="_blank" class="edit-entry">
                            Edit →
                        </a>
                    <?php else: ?>
                        <span class="status-badge new">+ New Entry</span>
                    <?php endif; ?>
                    
                    <div class="casino-controls">
                        <button type="button" class="button button-small edit-whole-casino" data-casino="<?php echo $index; ?>">
                            Edit Whole Casino
                        </button>
                        <button type="button" class="button button-small edit-individual-fields" data-casino="<?php echo $index; ?>">
                            Edit Fields
                        </button>
                    </div>
                </label>
            </div>
            
            <?php if (!empty($item['category_changes']) || !empty($item['tag_changes'])): ?>
                <div class="category-changes">
                    <?php if (!empty($item['category_changes'])): ?>
                        <h5>Category Changes:</h5>
                        <?php foreach ($item['category_changes'] as $cat_change): ?>
                            <p><strong><?php echo ucfirst($cat_change['type']); ?>:</strong> 
                               <?php echo esc_html($cat_change['category_name']); ?> 
                               <em>(<?php echo esc_html($cat_change['reason']); ?>)</em></p>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <?php if (!empty($item['tag_changes'])): ?>
                        <h5>Tag Changes:</h5>
                        <?php foreach ($item['tag_changes'] as $tag_change): ?>
                            <p><strong><?php echo ucfirst($tag_change['action']); ?>:</strong> 
                               <?php echo esc_html($tag_change['tag']); ?></p>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($item['casino_id'] && !empty($item['changes'])): ?>
                <div class="changes-table">
                    <h4>Proposed Changes:</h4>
                    <table class="wp-list-table widefat">
                        <thead>
                            <tr>
                                <th>Field</th>
                                <th>Current Value</th>
                                <th>New Value</th>
                                <th>Change</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php 
                            foreach ($item['changes'] as $field => $change): 
                                if (is_array($change) && isset($change['label'])):
                            ?>
                                <tr class="change-row <?php echo $change['type']; ?>">
                                    <td><strong><?php echo esc_html($change['label']); ?></strong></td>
                                    <td class="current-value">
                                        <?php if (empty($change['current'])): ?>
                                            <em>Not set</em>
                                        <?php else: ?>
                                            <?php echo esc_html($change['current']); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td class="new-value">
                                        <strong><?php echo esc_html($change['new']); ?></strong>
                                    </td>
                                    <td class="change-type">
                                        <span class="change-badge <?php echo $change['type']; ?>">
                                            <?php echo ucfirst($change['type']); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php 
                                endif;
                            endforeach; 
                            ?>
                        </tbody>
                    </table>
                </div>
            <?php elseif ($item['casino_id']): ?>
                <div class="no-changes">
                    <p><em>No changes needed - all values are up to date.</em></p>
                </div>
            <?php else: ?>
                <div class="new-entry-data">
                    <h4>New Entry Data:</h4>
                    <table class="wp-list-table widefat">
                        <tbody>
                            <?php foreach ($item['mapped_data'] as $field => $value): ?>
                                <?php if (!empty($value) && !strpos($value, '(no change)') && !strpos($value, '(no CSV data)')): ?>
                                    <tr>
                                        <td><strong><?php echo esc_html($field); ?></strong></td>
                                        <td><?php echo esc_html($value); ?></td>
                                    </tr>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
    
    /**
     * Render import results page
     */
    private function render_import_results_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Import Complete', 'craps-data-importer'); ?></h1>
            
            <div class="notice notice-success">
                <p><?php _e('CSV data has been processed successfully!', 'craps-data-importer'); ?></p>
            </div>
            
            <p>
                <a href="<?php echo admin_url('admin.php?page=craps-data-importer'); ?>" class="button button-primary">
                    <?php _e('Import Another File', 'craps-data-importer'); ?>
                </a>
                <a href="<?php echo admin_url('admin.php?page=craps-review-queue'); ?>" class="button">
                    <?php _e('Review Queue', 'craps-data-importer'); ?>
                </a>
            </p>
        </div>
        <?php
    }
    
    /**
     * Render review queue page (different from review changes page)
     */
    public function render_review_queue_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Review Queue', 'craps-data-importer'); ?></h1>
            
            <p><?php _e('Items that need manual review will appear here.', 'craps-data-importer'); ?></p>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <a href="<?php echo admin_url('admin.php?page=craps-data-importer'); ?>" class="button">
                        <?php _e('← Back to Importer', 'craps-data-importer'); ?>
                    </a>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Casino Name', 'craps-data-importer'); ?></th>
                        <th><?php _e('Issue', 'craps-data-importer'); ?></th>
                        <th><?php _e('Data', 'craps-data-importer'); ?></th>
                        <th><?php _e('Actions', 'craps-data-importer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="4"><?php _e('No items in review queue.', 'craps-data-importer'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
    
    /**
     * Render import history page
     */
    public function render_history_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Import History', 'craps-data-importer'); ?></h1>
            
            <p><?php _e('Previous import activities will be listed here.', 'craps-data-importer'); ?></p>
            
            <div class="tablenav top">
                <div class="alignleft actions">
                    <a href="<?php echo admin_url('admin.php?page=craps-data-importer'); ?>" class="button">
                        <?php _e('← Back to Importer', 'craps-data-importer'); ?>
                    </a>
                </div>
            </div>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Date', 'craps-data-importer'); ?></th>
                        <th><?php _e('File', 'craps-data-importer'); ?></th>
                        <th><?php _e('Rows Processed', 'craps-data-importer'); ?></th>
                        <th><?php _e('Status', 'craps-data-importer'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td colspan="4"><?php _e('No import history found.', 'craps-data-importer'); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <?php
    }
}