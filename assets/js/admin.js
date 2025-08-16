jQuery(document).ready(function($) {
    'use strict';
    
    // Initialize the plugin
    CDI.init();
});

// Main CDI object
var CDI = {
    
    // Initialize all functionality
    init: function() {
        this.bindEvents();
        this.initializeComponents();
    },
    
    // Bind event handlers
    bindEvents: function() {
        var self = this;
        
        // File upload form
        $(document).on('submit', '#cdi-upload-form', function(e) {
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
    },
    
    // Initialize components
    initializeComponents: function() {
        // Auto-start import if on import step
        if (window.location.href.indexOf('step=import') > -1) {
            this.processImport();
        }
        
        // Initialize tooltips if available
        if (typeof $.fn.tooltip === 'function') {
            $('[data-toggle="tooltip"]').tooltip();
        }
    },
    
    // Handle file upload
    handleFileUpload: function(form) {
        var self = this;
        var $form = $(form);
        var formData = new FormData(form);
        
        formData.append('action', 'cdi_upload_csv');
        formData.append('nonce', cdiAjax.nonce);
        
        $form.find('button[type="submit"]').prop('disabled', true).text(cdiAjax.strings.processing);
        
        $.ajax({
            url: cdiAjax.ajaxurl,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    self.showNotice('success', response.data.message);
                    if (response.data.redirect) {
                        window.location.href = response.data.redirect;
                    }
                } else {
                    self.showNotice('error', response.data.message || cdiAjax.strings.upload_error);
                }
            },
            error: function(xhr, status, error) {
                self.showNotice('error', 'Upload failed: ' + error);
            },
            complete: function() {
                $form.find('button[type="submit"]').prop('disabled', false).text('📤 Upload & Preview');
            }
        });
    },
    
    // Process import with progress tracking
    processImport: function() {
        var self = this;
        var settings = this.getImportSettings();
        
        // Show progress bar
        $('#cdi-import-progress').show();
        $('#cdi-import-results').hide();
        
        this.updateProgress(0, 'Starting import...');
        
        $.ajax({
            url: cdiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'cdi_process_import',
                nonce: cdiAjax.nonce,
                auto_update: settings.auto_update,
                similarity_threshold: settings.similarity_threshold,
                update_existing: settings.update_existing
            },
            success: function(response) {
                if (response.success) {
                    self.updateProgress(100, cdiAjax.strings.complete);
                    self.showImportResults(response.data);
                } else {
                    self.showNotice('error', response.data.message || 'Import failed');
                }
            },
            error: function(xhr, status, error) {
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
            similarity_threshold: $('#similarity_threshold').val(),
            update_existing: $('#cdi-settings-form input[name="update_existing"]').is(':checked')
        };
    },
    
    // Update progress bar
    updateProgress: function(percent, message) {
        $('.cdi-progress-fill').css('width', percent + '%');
        $('#cdi-progress-text').text(message);
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
            CDI.updateProgress(progress, messages[messageIndex] || 'Processing...');
        }, 800);
    },
    
    // Show import results
    showImportResults: function(results) {
        $('#cdi-import-progress').hide();
        $('#cdi-import-results').show();
        
        var html = '<div class="cdi-results-grid">';
        
        html += '<div class="cdi-result-card success">';
        html += '<span class="stat-number">' + results.updated + '</span>';
        html += '<span class="stat-label">Updated Casinos</span>';
        html += '<p>Successfully matched and updated</p>';
        html += '</div>';
        
        html += '<div class="cdi-result-card warning">';
        html += '<span class="stat-number">' + results.queued + '</span>';
        html += '<span class="stat-label">Review Queue</span>';
        html += '<p>Items requiring manual review</p>';
        html += '</div>';
        
        html += '<div class="cdi-result-card info">';
        html += '<span class="stat-number">' + results.processed + '</span>';
        html += '<span class="stat-label">Total Processed</span>';
        html += '<p>Out of ' + results.total_rows + ' rows</p>';
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
    
    // Open casino search modal
    openCasinoSearchModal: function(casinoName) {
        var self = this;
        
        // Create modal HTML
        var modalHtml = '<div class="cdi-modal active">';
        modalHtml += '<div class="cdi-modal-content">';
        modalHtml += '<div class="cdi-modal-header">';
        modalHtml += '<h3>🔍 Find Casino: ' + casinoName + '</h3>';
        modalHtml += '<button class="cdi-modal-close">&times;</button>';
        modalHtml += '</div>';
        modalHtml += '<div class="cdi-modal-body">';
        modalHtml += '<input type="text" id="casino-search" class="regular-text" placeholder="Search casinos..." value="' + casinoName + '">';
        modalHtml += '<div id="search-results"></div>';
        modalHtml += '</div>';
        modalHtml += '<div class="cdi-modal-actions">';
        modalHtml += '<button class="button cdi-modal-close">Cancel</button>';
        modalHtml += '<button class="button button-primary" id="select-casino">Select Casino</button>';
        modalHtml += '</div>';
        modalHtml += '</div>';
        modalHtml += '</div>';
        
        $('body').append(modalHtml);
        
        // Auto-search
        setTimeout(function() {
            self.searchCasinos(casinoName);
        }, 100);
        
        // Bind search events
        $('#casino-search').on('input', function() {
            var searchTerm = $(this).val();
            if (searchTerm.length > 2) {
                self.searchCasinos(searchTerm);
            }
        });
        
        // Bind selection
        $('#select-casino').on('click', function() {
            var selectedResult = $('.cdi-search-result.selected');
            if (selectedResult.length > 0) {
                var casinoId = selectedResult.data('casino-id');
                self.resolveQueueItemWithCasino(casinoId);
                self.closeModal();
            } else {
                alert('Please select a casino first.');
            }
        });
    },
    
    // Search casinos via AJAX
    searchCasinos: function(searchTerm) {
        var self = this;
        
        $('#search-results').html('<p>Searching...</p>');
        
        $.ajax({
            url: cdiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'cdi_search_casino',
                nonce: cdiAjax.nonce,
                search: searchTerm
            },
            success: function(response) {
                if (response.success) {
                    self.displaySearchResults(response.data);
                } else {
                    $('#search-results').html('<p>Search failed. Please try again.</p>');
                }
            },
            error: function() {
                $('#search-results').html('<p>Search failed. Please try again.</p>');
            }
        });
    },
    
    // Display search results
    displaySearchResults: function(results) {
        var html = '';
        
        if (results.length === 0) {
            html = '<p>No casinos found. Try a different search term.</p>';
        } else {
            results.forEach(function(casino) {
                html += '<div class="cdi-search-result" data-casino-id="' + casino.id + '">';
                html += '<div class="casino-title">' + casino.title + '</div>';
                if (casino.location) {
                    html += '<div class="casino-meta">📍 ' + casino.location + '</div>';
                }
                html += '<div class="similarity-score">' + Math.round(casino.similarity) + '%</div>';
                html += '</div>';
            });
        }
        
        $('#search-results').html(html);
    },
    
    // Resolve queue item with selected casino
    resolveQueueItemWithCasino: function(casinoId) {
        // This would be called from the queue management page
        // Implementation depends on the specific queue item context
        console.log('Resolving with casino ID:', casinoId);
    },
    
    // Resolve queue item
    resolveQueueItem: function(button) {
        var $button = $(button);
        var queueId = $button.closest('.cdi-queue-item').data('queue-id');
        var casinoId = $('.cdi-search-result.selected').data('casino-id');
        
        if (!casinoId) {
            alert('Please select a casino first.');
            return;
        }
        
        this.processQueueAction(queueId, 'accept', casinoId);
    },
    
    // Skip queue item
    skipQueueItem: function(button) {
        var $button = $(button);
        var queueId = $button.closest('.cdi-queue-item').data('queue-id');
        
        if (confirm('Are you sure you want to skip this item?')) {
            this.processQueueAction(queueId, 'skip');
        }
    },
    
    // Process queue action
    processQueueAction: function(queueId, action, casinoId) {
        var self = this;
        
        $.ajax({
            url: cdiAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'cdi_resolve_queue_item',
                nonce: cdiAjax.nonce,
                queue_id: queueId,
                action: action,
                casino_id: casinoId || 0
            },
            success: function(response) {
                if (response.success) {
                    self.showNotice('success', response.data.message);
                    // Remove the queue item from display
                    $('.cdi-queue-item[data-queue-id="' + queueId + '"]').fadeOut();
                } else {
                    self.showNotice('error', response.data.message || 'Action failed');
                }
            },
            error: function() {
                self.showNotice('error', 'Action failed. Please try again.');
            }
        });
    },
    
    // Close modal
    closeModal: function() {
        $('.cdi-modal').remove();
    },
    
    // Show notice message
    showNotice: function(type, message) {
        var noticeClass = 'cdi-notice-' + type;
        var notice = '<div class="cdi-notice ' + noticeClass + '">' + message + '</div>';
        
        // Remove existing notices
        $('.cdi-notice').remove();
        
        // Add new notice
        $('.wrap h1').after(notice);
        
        // Auto-remove after 5 seconds
        setTimeout(function() {
            $('.cdi-notice').fadeOut();
        }, 5000);
        
        // Scroll to top to show notice
        $('html, body').animate({ scrollTop: 0 }, 'fast');
    },
    
    // Utility function to escape HTML
    escapeHtml: function(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    },
    
    // Utility function to format numbers
    formatNumber: function(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
    }
};