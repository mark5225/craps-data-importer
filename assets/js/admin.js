// WordPress-compatible jQuery wrapper
(function($) {
    'use strict';
    
    // Wait for DOM ready
    $(document).ready(function() {
        console.log('CDI Admin JS loaded - jQuery version:', $.fn.jquery);
        
        // Initialize the plugin
        CDI.init();
    });

    // Main CDI object
    window.CDI = {
        
        // Initialize all functionality
        init: function() {
            console.log('CDI initializing...');
            this.bindEvents();
            this.initializeComponents();
        },
        
        // Bind event handlers
        bindEvents: function() {
            var self = this;
            
            console.log('Binding events...');
            
            // File upload form
            $(document).on('submit', '#cdi-upload-form', function(e) {
                console.log('Form submission intercepted');
                e.preventDefault();
                self.handleFileUpload(this);
            });
            
            // Import processing
            $(document).on('click', '#cdi-start-import', function(e) {
                e.preventDefault();
                self.processImport();
            });
            
            // Similarity threshold slider
            $(document).on('input', '#similarity_threshold', function() {
                $('#threshold_value').text($(this).val() + '%');
            });
            
            // Casino search
            $(document).on('click', '.cdi-search-casino', function(e) {
                e.preventDefault();
                var casinoName = $(this).data('casino-name');
                self.openCasinoSearchModal(casinoName);
            });
            
            // Modal close
            $(document).on('click', '.cdi-modal-close, .cdi-modal-overlay', function(e) {
                e.preventDefault();
                self.closeModal();
            });
            
            // Search result selection
            $(document).on('click', '.cdi-search-result', function() {
                $('.cdi-search-result').removeClass('selected');
                $(this).addClass('selected');
            });
            
            // Queue item resolution
            $(document).on('click', '.cdi-resolve-item', function(e) {
                e.preventDefault();
                self.resolveQueueItem(this);
            });
            
            // Skip queue item
            $(document).on('click', '.cdi-skip-item', function(e) {
                e.preventDefault();
                self.skipQueueItem(this);
            });
            
            console.log('Events bound successfully');
        },
        
        // Initialize components
        initializeComponents: function() {
            console.log('Initializing components...');
            
            // Auto-start import if on import step
            if (window.location.href.indexOf('step=import') > -1) {
                console.log('Auto-starting import...');
                this.processImport();
            }
            
            // Initialize tooltips if available
            if (typeof $.fn.tooltip === 'function') {
                $('[data-toggle="tooltip"]').tooltip();
            }
            
            // Check if cdiAjax is available
            if (typeof window.cdiAjax === 'undefined') {
                console.error('cdiAjax object not found! Check if script is properly localized.');
                this.showNotice('error', 'JavaScript configuration error. Please refresh the page.');
            } else {
                console.log('cdiAjax object found:', window.cdiAjax);
            }
        },
        
        // Handle file upload
        handleFileUpload: function(form) {
            var self = this;
            var $form = $(form);
            
            console.log('Starting file upload...');
            
            // Check if file is selected
            var fileInput = $form.find('input[type="file"]')[0];
            if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
                console.error('No file selected');
                self.showNotice('error', 'Please select a file to upload.');
                return;
            }
            
            var file = fileInput.files[0];
            console.log('File selected:', file.name, 'Size:', file.size, 'Type:', file.type);
            
            // Basic file validation
            if (!file.name.toLowerCase().endsWith('.csv')) {
                console.error('Invalid file type:', file.name);
                self.showNotice('error', 'Please select a CSV file.');
                return;
            }
            
            // Check file size (15MB max)
            var maxSize = 15 * 1024 * 1024; // 15MB
            if (file.size > maxSize) {
                console.error('File too large:', file.size);
                self.showNotice('error', 'File is too large. Maximum size is 15MB.');
                return;
            }
            
            var formData = new FormData(form);
            formData.append('action', 'cdi_upload_csv');
            
            // Check if nonce is available
            if (typeof window.cdiAjax !== 'undefined' && window.cdiAjax.nonce) {
                formData.append('nonce', window.cdiAjax.nonce);
                console.log('Nonce added:', window.cdiAjax.nonce);
            } else {
                console.error('No nonce available!');
                self.showNotice('error', 'Security token missing. Please refresh the page.');
                return;
            }
            
            var $submitButton = $form.find('button[type="submit"]');
            $submitButton.prop('disabled', true).text('Processing...');
            
            console.log('Sending AJAX request...');
            console.log('Ajax URL:', window.cdiAjax ? window.cdiAjax.ajaxurl : '/wp-admin/admin-ajax.php');
            
            $.ajax({
                url: window.cdiAjax ? window.cdiAjax.ajaxurl : '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 60000, // 60 second timeout
                beforeSend: function() {
                    console.log('AJAX request started');
                },
                success: function(response) {
                    console.log('AJAX success response:', response);
                    
                    if (response && response.success) {
                        self.showNotice('success', response.data.message || 'File uploaded successfully');
                        
                        if (response.data.redirect) {
                            console.log('Redirecting to:', response.data.redirect);
                            setTimeout(function() {
                                window.location.href = response.data.redirect;
                            }, 1000); // Small delay to show success message
                        } else {
                            console.log('No redirect URL provided, response data:', response.data);
                            // Show some results or next steps
                            if (response.data.rows_found) {
                                self.showNotice('success', 'Found ' + response.data.rows_found + ' rows of data');
                            }
                        }
                    } else {
                        console.error('Upload failed:', response);
                        var errorMessage = 'Upload failed';
                        if (response && response.data && response.data.message) {
                            errorMessage = response.data.message;
                        } else if (typeof response === 'string') {
                            errorMessage = 'Server error: ' + response.substring(0, 100);
                        }
                        self.showNotice('error', errorMessage);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX error:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        readyState: xhr.readyState,
                        status_code: xhr.status
                    });
                    
                    var errorMessage = 'Upload failed: ';
                    if (status === 'timeout') {
                        errorMessage += 'Request timed out. Please try a smaller file.';
                    } else if (xhr.status === 413) {
                        errorMessage += 'File too large. Please try a smaller file.';
                    } else if (xhr.status === 500) {
                        errorMessage += 'Server error. Check the server logs.';
                    } else if (xhr.status === 0) {
                        errorMessage += 'Network error. Check your connection.';
                    } else {
                        errorMessage += error || 'Unknown error (Status: ' + xhr.status + ')';
                    }
                    
                    self.showNotice('error', errorMessage);
                },
                complete: function() {
                    console.log('AJAX request completed');
                    $submitButton.prop('disabled', false).text('📤 Upload & Preview');
                }
            });
        },
        
        // Process import with progress tracking
        processImport: function() {
            var self = this;
            var settings = this.getImportSettings();
            
            console.log('Starting import process with settings:', settings);
            
            // Show progress bar
            $('#cdi-import-progress').show();
            $('#cdi-import-results').hide();
            
            this.updateProgress(0, 'Starting import...');
            
            $.ajax({
                url: window.cdiAjax.ajaxurl,
                type: 'POST',
                data: {
                    action: 'cdi_process_import',
                    nonce: window.cdiAjax.nonce,
                    auto_update: settings.auto_update,
                    similarity_threshold: settings.similarity_threshold,
                    update_existing: settings.update_existing
                },
                success: function(response) {
                    console.log('Import response:', response);
                    if (response.success) {
                        self.updateProgress(100, 'Import complete!');
                        self.showImportResults(response.data);
                    } else {
                        self.showNotice('error', response.data.message || 'Import failed');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Import error:', xhr, status, error);
                    self.showNotice('error', 'Import failed: ' + error);
                }
            });
            
            // Simulate progress for better UX
            this.simulateProgress();
        },
        
        // Get import settings from form
        getImportSettings: function() {
            return {
                auto_update: $('#cdi-settings-form input[name="auto_update"]').is(':checked'),
                similarity_threshold: $('#similarity_threshold').val() || 80,
                update_existing: $('#cdi-settings-form input[name="update_existing"]').is(':checked')
            };
        },
        
        // Update progress bar
        updateProgress: function(percent, message) {
            $('.cdi-progress-fill').css('width', percent + '%');
            $('#cdi-progress-text').text(message);
            console.log('Progress:', percent + '%', message);
        },
        
        // Simulate progress for better UX
        simulateProgress: function() {
            var progress = 0;
            var messages = [
                'Parsing CSV data...',
                'Matching casino names...',
                'Updating records...',
                'Finalizing import...'
            ];
            
            var interval = setInterval(function() {
                progress += Math.random() * 15;
                if (progress > 90) {
                    clearInterval(interval);
                    return;
                }
                
                var messageIndex = Math.floor((progress / 100) * messages.length);
                window.CDI.updateProgress(progress, messages[messageIndex] || 'Processing...');
            }, 800);
        },
        
        // Show import results
        showImportResults: function(results) {
            console.log('Showing import results:', results);
            $('#cdi-import-progress').hide();
            $('#cdi-import-results').show();
            
            var html = '<div class="cdi-results-grid">';
            
            html += '<div class="cdi-result-card success">';
            html += '<span class="stat-number">' + (results.updated || 0) + '</span>';
            html += '<span class="stat-label">Updated Casinos</span>';
            html += '<p>Successfully matched and updated</p>';
            html += '</div>';
            
            html += '<div class="cdi-result-card warning">';
            html += '<span class="stat-number">' + (results.queued || 0) + '</span>';
            html += '<span class="stat-label">Review Queue</span>';
            html += '<p>Items requiring manual review</p>';
            html += '</div>';
            
            html += '<div class="cdi-result-card info">';
            html += '<span class="stat-number">' + (results.processed || 0) + '</span>';
            html += '<span class="stat-label">Total Processed</span>';
            html += '<p>Out of ' + (results.total_rows || 0) + ' rows</p>';
            html += '</div>';
            
            if (results.errors && results.errors.length > 0) {
                html += '<div class="cdi-result-card error">';
                html += '<span class="stat-number">' + results.errors.length + '</span>';
                html += '<span class="stat-label">Errors</span>';
                html += '<p>Items with processing errors</p>';
                html += '</div>';
            }
            
            html += '</div>';
            
            if (results.errors && results.errors.length > 0) {
                html += '<div class="cdi-notice cdi-notice-warning">';
                html += '<h4>Processing Errors:</h4>';
                html += '<ul>';
                results.errors.forEach(function(error) {
                    html += '<li>' + error + '</li>';
                });
                html += '</ul>';
                html += '</div>';
            }
            
            $('#cdi-results-content').html(html);
        },
        
        // Show notice message
        showNotice: function(type, message) {
            console.log('Showing notice:', type, message);
            
            var noticeClass = 'cdi-notice-' + type;
            var notice = '<div class="cdi-notice ' + noticeClass + '">' + this.escapeHtml(message) + '</div>';
            
            // Remove existing notices
            $('.cdi-notice').remove();
            
            // Add new notice
            if ($('.wrap h1').length > 0) {
                $('.wrap h1').after(notice);
            } else if ($('.wrap').length > 0) {
                $('.wrap').prepend(notice);
            } else {
                $('body').prepend('<div class="wrap">' + notice + '</div>');
            }
            
            // Auto-remove after 10 seconds (longer for debugging)
            setTimeout(function() {
                $('.cdi-notice').fadeOut();
            }, 10000);
            
            // Scroll to top to show notice
            $('html, body').animate({ scrollTop: 0 }, 'fast');
        },
        
        // Placeholder functions for features not yet implemented
        openCasinoSearchModal: function(casinoName) {
            console.log('Opening casino search modal for:', casinoName);
            // Will implement later
        },
        
        closeModal: function() {
            console.log('Closing modal');
            $('.cdi-modal').remove();
        },
        
        resolveQueueItem: function(button) {
            console.log('Resolving queue item');
            // Will implement later
        },
        
        skipQueueItem: function(button) {
            console.log('Skipping queue item');
            // Will implement later
        },
        
        // Utility functions
        escapeHtml: function(text) {
            var map = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#039;'
            };
            return String(text).replace(/[&<>"']/g, function(m) { return map[m]; });
        },
        
        formatNumber: function(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }
    };

})(jQuery); // Pass jQuery to our wrapper function