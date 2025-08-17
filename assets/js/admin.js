/**
 * Admin JavaScript for Craps Data Importer
 * FIXED VERSION - Matches the WordPress localization
 */

jQuery(document).ready(function($) {
    
    var CdiAdmin = {
        
        init: function() {
            this.bindEvents();
            this.checkConfig();
        },
        
        bindEvents: function() {
            // File upload form
            $('#csv-upload-form').on('submit', this.handleFileUpload.bind(this));
            
            // Import form
            $('#import-form').on('submit', this.handleImport.bind(this));
            
            // Search functionality
            $('.cdi-search').on('input', this.handleSearch.bind(this));
            
            // Queue resolution
            $('.resolve-queue-item').on('click', this.handleResolveQueue.bind(this));
        },
        
        checkConfig: function() {
            // FIXED: Check for the correct variable name that WordPress creates
            if (typeof window.cdiAjax === 'undefined') {
                console.error('CDI Admin: cdiAjax object not found. Check if script is properly localized.');
                this.showNotice('error', 'JavaScript configuration error. Please refresh the page.');
            } else {
                console.log('CDI Admin: cdiAjax object found:', window.cdiAjax);
            }
        },
        
        // Handle file upload
        handleFileUpload: function(form) {
            var self = this;
            var $form = $(form);
            
            console.log('CDI Admin: Starting file upload...');
            
            // Check if file is selected
            var fileInput = $form.find('input[type="file"]')[0];
            if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                console.error('CDI Admin: No file selected');
                self.showNotice('error', 'Please select a file to upload.');
                return;
            }
            
            var file = fileInput.files[0];
            console.log('CDI Admin: File selected:', file.name, 'Size:', file.size, 'Type:', file.type);
            
            // Basic file validation
            if (!file.name.toLowerCase().endsWith('.csv')) {
                console.error('CDI Admin: Invalid file type:', file.name);
                self.showNotice('error', 'Please select a CSV file.');
                return;
            }
            
            // Check file size (15MB max)
            var maxSize = 15 * 1024 * 1024; // 15MB
            if (file.size > maxSize) {
                console.error('CDI Admin: File too large:', file.size);
                self.showNotice('error', 'File is too large. Maximum size is 15MB.');
                return;
            }
            
            var formData = new FormData(form);
            formData.append('action', 'cdi_upload_csv');
            
            // FIXED: Check for the correct variable name
            if (typeof window.cdiAjax !== 'undefined' && window.cdiAjax.nonce) {
                formData.append('nonce', window.cdiAjax.nonce);
                console.log('CDI Admin: Nonce added:', window.cdiAjax.nonce);
            } else {
                console.error('CDI Admin: No nonce available!');
                self.showNotice('error', 'Security token missing. Please refresh the page.');
                return;
            }
            
            var $submitButton = $form.find('button[type="submit"]');
            var originalText = $submitButton.text();
            $submitButton.prop('disabled', true).text('Processing...');
            
            console.log('CDI Admin: Sending AJAX request...');
            console.log('CDI Admin: Ajax URL:', window.cdiAjax ? window.cdiAjax.ajaxurl : '/wp-admin/admin-ajax.php');
            
            $.ajax({
                url: window.cdiAjax ? window.cdiAjax.ajaxurl : '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: function() {
                    var xhr = new window.XMLHttpRequest();
                    
                    // Upload progress
                    xhr.upload.addEventListener('progress', function(evt) {
                        if (evt.lengthComputable) {
                            var percentComplete = evt.loaded / evt.total;
                            var percent = Math.round(percentComplete * 100);
                            $submitButton.text('Uploading... ' + percent + '%');
                            console.log('CDI Admin: Upload progress:', percent + '%');
                        }
                    }, false);
                    
                    return xhr;
                },
                success: function(response) {
                    console.log('CDI Admin: Upload response:', response);
                    
                    if (response && response.success) {
                        console.log('CDI Admin: Upload successful');
                        
                        if (response.data && response.data.redirect) {
                            console.log('CDI Admin: Redirecting to:', response.data.redirect);
                            window.location.href = response.data.redirect;
                        } else {
                            self.showNotice('success', 'File uploaded successfully');
                            $submitButton.prop('disabled', false).text(originalText);
                        }
                    } else {
                        console.error('CDI Admin: Upload failed:', response);
                        var errorMessage = 'Upload failed';
                        
                        if (response && response.data) {
                            if (typeof response.data === 'string') {
                                errorMessage += ': ' + response.data;
                            } else if (response.data.message) {
                                errorMessage += ': ' + response.data.message;
                            }
                        }
                        
                        self.showNotice('error', errorMessage);
                        $submitButton.prop('disabled', false).text(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('CDI Admin: AJAX Error:', status, error);
                    console.error('CDI Admin: Response Text:', xhr.responseText);
                    
                    var errorMessage = 'Upload failed';
                    
                    if (xhr.status === 413 || xhr.responseText.indexOf('413') > -1) {
                        errorMessage += ': File too large. Please use a smaller file.';
                    } else if (xhr.status === 0) {
                        errorMessage += ': Network error. Please check your connection.';
                    } else if (xhr.status >= 500) {
                        errorMessage += ': Server error. Please try again.';
                    } else {
                        errorMessage += ': ' + error;
                    }
                    
                    self.showNotice('error', errorMessage);
                    $submitButton.prop('disabled', false).text(originalText);
                }
            });
        },
        
        // Handle import processing
        handleImport: function(e) {
            e.preventDefault();
            
            var self = this;
            var $form = $(e.target);
            var $button = $form.find('button[type="submit"]');
            var originalText = $button.text();
            
            $button.prop('disabled', true).text('Processing Import...');
            
            var formData = new FormData(e.target);
            formData.append('action', 'cdi_process_import');
            
            if (window.cdiAjax && window.cdiAjax.nonce) {
                formData.append('nonce', window.cdiAjax.nonce);
            }
            
            $.ajax({
                url: window.cdiAjax ? window.cdiAjax.ajaxurl : '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                success: function(response) {
                    if (response.success) {
                        self.showNotice('success', response.data.message || 'Import completed successfully');
                        
                        if (response.data.redirect) {
                            setTimeout(function() {
                                window.location.href = response.data.redirect;
                            }, 1500);
                        } else {
                            $button.prop('disabled', false).text(originalText);
                        }
                    } else {
                        self.showNotice('error', 'Import failed: ' + response.data);
                        $button.prop('disabled', false).text(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('CDI Admin: Import error:', status, error);
                    self.showNotice('error', 'Import failed: ' + error);
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },
        
        // Handle search functionality
        handleSearch: function(e) {
            var searchTerm = $(e.target).val();
            var $results = $('.cdi-search-results');
            
            if (searchTerm.length < 3) {
                $results.empty();
                return;
            }
            
            if (window.cdiAjax && window.cdiAjax.nonce) {
                $.ajax({
                    url: window.cdiAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cdi_search_casino',
                        search: searchTerm,
                        nonce: window.cdiAjax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            // Display search results
                            var html = '';
                            $.each(response.data, function(i, casino) {
                                html += '<div class="search-result" data-id="' + casino.id + '">';
                                html += '<strong>' + casino.title + '</strong><br>';
                                html += '<small>' + casino.location + '</small>';
                                html += '</div>';
                            });
                            $results.html(html);
                        }
                    }
                });
            }
        },
        
        // Handle queue resolution
        handleResolveQueue: function(e) {
            e.preventDefault();
            
            var $button = $(e.target);
            var queueId = $button.data('queue-id');
            var action = $button.data('action');
            
            if (window.cdiAjax && window.cdiAjax.nonce) {
                $.ajax({
                    url: window.cdiAjax.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'cdi_resolve_queue_item',
                        queue_id: queueId,
                        action: action,
                        nonce: window.cdiAjax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            $button.closest('tr').fadeOut();
                        }
                    }
                });
            }
        },
        
        // Show notice messages
        showNotice: function(type, message) {
            var $notice = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            
            // Remove existing notices
            $('.wrap .notice').remove();
            
            // Add new notice
            $('.wrap h1').after($notice);
            
            // Auto-dismiss success notices
            if (type === 'success') {
                setTimeout(function() {
                    $notice.fadeOut();
                }, 5000);
            }
            
            // Make dismissible
            $notice.on('click', '.notice-dismiss', function() {
                $notice.fadeOut();
            });
            
            // Add dismiss button if not present
            if (!$notice.find('.notice-dismiss').length) {
                $notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
            }
        },
        
        // Utility function to get AJAX URL
        getAjaxUrl: function() {
            return window.cdiAjax ? window.cdiAjax.ajaxurl : '/wp-admin/admin-ajax.php';
        },
        
        // Utility function to get nonce
        getNonce: function() {
            return window.cdiAjax ? window.cdiAjax.nonce : '';
        }
    };
    
    // Initialize the admin interface
    CdiAdmin.init();
    
    // Make it globally available for debugging
    window.CdiAdmin = CdiAdmin;
    
    console.log('CDI Admin: JavaScript loaded and initialized');
});