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
                $this->render_preview_page();
                break;
            case 'import':
                $this->render_import_page();
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
                            <li><?php _e('Expected columns: Casino Name, WeekDay Min, WeekNight Min, etc.', 'craps-data-importer'); ?></li>
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
     * Render preview page
     */
    private function render_preview_page() {
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
        
        ?>
        <div class="wrap">
            <h1><?php _e('Preview CSV Data', 'craps-data-importer'); ?></h1>
            
            <div class="notice notice-info">
                <p>
                    <strong><?php _e('File:', 'craps-data-importer'); ?></strong> <?php echo esc_html($csv_data['filename']); ?><br>
                    <strong><?php _e('Rows:', 'craps-data-importer'); ?></strong> <?php echo count($csv_data['data']); ?><br>
                    <strong><?php _e('Uploaded:', 'craps-data-importer'); ?></strong> <?php echo esc_html($csv_data['uploaded_at']); ?>
                </p>
            </div>
            
            <div class="postbox">
                <h2 class="hndle"><?php _e('Column Headers', 'craps-data-importer'); ?></h2>
                <div class="inside">
                    <p><?php _e('Detected columns:', 'craps-data-importer'); ?></p>
                    <ul>
                        <?php foreach ($csv_data['headers'] as $header): ?>
                            <li><code><?php echo esc_html($header); ?></code></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
            
            <div class="postbox">
                <h2 class="hndle"><?php _e('Sample Data (First 5 Rows)', 'craps-data-importer'); ?></h2>
                <div class="inside">
                    <div style="overflow-x: auto;">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <?php foreach ($csv_data['headers'] as $header): ?>
                                        <th><?php echo esc_html($header); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $sample_data = array_slice($csv_data['data'], 0, 5);
                                foreach ($sample_data as $row): 
                                ?>
                                    <tr>
                                        <?php foreach ($csv_data['headers'] as $header): ?>
                                            <td><?php echo esc_html($row[$header] ?? ''); ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <form id="import-form">
                <?php wp_nonce_field('cdi_nonce', 'nonce'); ?>
                <p class="submit">
                    <button type="submit" class="button button-primary button-large">
                        <?php _e('Process Import', 'craps-data-importer'); ?>
                    </button>
                    <a href="<?php echo admin_url('admin.php?page=craps-data-importer'); ?>" class="button">
                        <?php _e('← Upload Different File', 'craps-data-importer'); ?>
                    </a>
                </p>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#import-form').on('submit', function(e) {
                e.preventDefault();
                
                var $button = $(this).find('button[type="submit"]');
                $button.prop('disabled', true).text('Processing...');
                
                var formData = new FormData(this);
                formData.append('action', 'cdi_process_import');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            alert('Import completed successfully!');
                            window.location.href = '<?php echo admin_url("admin.php?page=craps-data-importer"); ?>';
                        } else {
                            alert('Import failed: ' + response.data);
                            $button.prop('disabled', false).text('Process Import');
                        }
                    },
                    error: function() {
                        alert('Import failed. Please try again.');
                        $button.prop('disabled', false).text('Process Import');
                    }
                });
            });
        });
        </script>
        <?php
    }
    
    /**
     * Render import results page
     */
    private function render_import_page() {
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
     * Render review queue page
     */
    public function render_review_page() {
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