/**
 * COMPLETE admin.js file for Craps Data Importer
 * Enhanced with investigation features
 */

(function($) {
    'use strict';

    // Main CDI object
    window.CDI = {
        
        // Initialize everything when DOM is ready
        init: function() {
            console.log('CDI Admin JS initializing...');
            this.bindEvents();
            this.initializeComponents();
        },
        
        // Bind all event handlers
        bindEvents: function() {
            var self = this;
            console.log('Binding events...');
            
            // File upload form
            $(document).on('submit', '#cdi-upload-form', function(e) {
                e.preventDefault();
                self.handleFileUpload(this);
            });
            
            // Process import button
            $(document).on('click', '#cdi-process-selected', function(e) {
                e.preventDefault();
                self.processImport();
            });
            
            // Settings form changes
            $(document).on('change', '#cdi-settings-form input', function() {
                console.log('Settings changed');
                // Auto-save settings if needed
            });
            
            // Range slider updates
            $(document).on('input', '#similarity_threshold', function() {
                $('#threshold_value').text(this.value + '%');
            });
            
            // Queue item actions
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
                xhr: function() {
                    var xhr = new window.XMLHttpRequest();
                    
                    // Upload progress
                    xhr.upload.addEventListener('progress', function(evt) {
                        if (evt.lengthComputable) {
                            var percentComplete = evt.loaded / evt.total;
                            console.log('Upload progress:', Math.round(percentComplete * 100) + '%');
                        }
                    }, false);
                    
                    return xhr;
                },
                success: function(response) {
                    console.log('Upload response:', response);
                    
                    if (response && response.success) {
                        console.log('Upload successful');
                        
                        if (response.data && response.data.redirect) {
                            console.log('Redirecting to:', response.data.redirect);
                            window.location.href = response.data.redirect;
                        } else {
                            self.showNotice('success', 'File uploaded successfully');
                        }
                    } else {
                        console.error('Upload failed:', response);
                        var errorMessage = 'Upload failed';
                        
                        if (response && response.data) {
                            if (typeof response.data === 'string') {
                                errorMessage += ': ' + response.data;
                            } else if (response.data.message) {
                                errorMessage += ': ' + response.data.message;
                            }
                        }
                        
                        self.showNotice('error', errorMessage);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', status, error);
                    console.error('Response Text:', xhr.responseText);
                    
                    var errorMessage = 'Upload failed';
                    
                    if (xhr.status === 413 || xhr.responseText.indexOf('413') > -1) {
                        errorMessage += ': File too large. Please try a smaller file.';
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
                    if (response && response.success) {
                        self.updateProgress(100, 'Import complete!');
                        self.showImportResults(response.data);
                    } else {
                        var errorMessage = 'Import failed';
                        if (response && response.data) {
                            if (typeof response.data === 'string') {
                                errorMessage = response.data;
                            } else if (response.data.message) {
                                errorMessage = response.data.message;
                            } else {
                                errorMessage = 'Import failed: ' + JSON.stringify(response.data);
                            }
                        } else if (response && response.message) {
                            errorMessage = response.message;
                        }
                        self.showNotice('error', errorMessage);
                        console.error('Import failed with response:', response);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Import error:', error);
                    self.showNotice('error', 'Import failed: ' + error);
                },
                timeout: 300000 // 5 minute timeout
            });
        },
        
        // Get import settings from form
        getImportSettings: function() {
            return {
                auto_update: $('#auto_update_high').is(':checked') ? 1 : 0,
                similarity_threshold: $('#similarity_threshold').val() || 80,
                update_existing: $('#update_existing').is(':checked') ? 1 : 0
            };
        },
        
        // Update progress bar
        updateProgress: function(percent, message) {
            $('.cdi-progress-fill').css('width', percent + '%');
            $('#cdi-progress-text').text(message);
        },
        
        // Show import results
        showImportResults: function(data) {
            $('#cdi-import-progress').hide();
            $('#cdi-import-results').show();
            
            var html = '<h3>Import Complete</h3>';
            html += '<p>Processing finished successfully.</p>';
            
            if (data && data.stats) {
                html += '<div class="cdi-stats">';
                html += '<p><strong>Updated:</strong> ' + (data.stats.updated || 0) + ' casinos</p>';
                html += '<p><strong>Queued for Review:</strong> ' + (data.stats.queued || 0) + ' items</p>';
                html += '<p><strong>Skipped:</strong> ' + (data.stats.skipped || 0) + ' rows</p>';
                html += '</div>';
            }
            
            $('#cdi-results-content').html(html);
        },
        
        // Show notification message
        showNotice: function(type, message) {
            console.log('Showing notice:', type, message);
            
            // Handle object messages by converting to string
            if (typeof message === 'object') {
                if (message && message.message) {
                    message = message.message;
                } else if (message && message.data) {
                    message = message.data;
                } else {
                    message = JSON.stringify(message);
                }
            }
            
            var noticeClass = 'notice-' + type;
            var notice = '<div class="notice ' + noticeClass + ' cdi-notice is-dismissible">';
            notice += '<p><strong>Craps Data Importer:</strong> ' + this.escapeHtml(message) + '</p>';
            notice += '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>';
            notice += '</div>';
            
            // Remove existing notices
            $('.cdi-notice').remove();
            
            // Add new notice
            if ($('.wrap').length > 0) {
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

    // ======================================
    // NEW INVESTIGATION FEATURES
    // ======================================
    
    // NEW: Mass selection functions
    window.cdiMassSelect = function(action) {
        console.log('Mass selecting all rows to:', action);
        document.querySelectorAll('.cdi-row-action').forEach(select => {
            select.value = action;
        });
        
        // Update visual feedback
        const actionText = action === 'update' ? 'Update' : 
                          action === 'skip' ? 'Skip' : 
                          action === 'review' ? 'Review' : action;
        CDI.showNotice('success', `All rows set to: ${actionText}`);
    };
    
    // NEW: Reset all selections
    window.cdiResetAllSelections = function() {
        console.log('Resetting all selections to default (update)');
        document.querySelectorAll('.cdi-row-action').forEach(select => {
            select.value = 'update';
        });
        CDI.showNotice('info', 'All selections reset to Update');
    };
    
    // NEW: Smart selection based on confidence levels
    window.cdiSmartSelect = function(selectionType) {
        console.log('Smart selecting:', selectionType);
        
        const rows = document.querySelectorAll('.cdi-match-item');
        let selectedCount = 0;
        
        rows.forEach(row => {
            const confidence = parseInt(row.dataset.confidence) || 0;
            const actionSelect = row.querySelector('.cdi-row-action');
            
            if (!actionSelect) return;
            
            switch (selectionType) {
                case 'high-confidence':
                    if (confidence >= 90) {
                        actionSelect.value = 'update';
                        selectedCount++;
                    }
                    break;
                    
                case 'low-confidence':
                    if (confidence < 70) {
                        actionSelect.value = 'review';
                        selectedCount++;
                    }
                    break;
                    
                case 'no-changes':
                    // Check if row has no relevant changes
                    const changesTable = row.querySelector('.cdi-changes-preview table');
                    if (!changesTable || changesTable.rows.length <= 1) {
                        actionSelect.value = 'skip';
                        selectedCount++;
                    }
                    break;
            }
        });
        
        const messages = {
            'high-confidence': `${selectedCount} high-confidence rows set to Update`,
            'low-confidence': `${selectedCount} low-confidence rows sent to Review`,
            'no-changes': `${selectedCount} rows with no changes set to Skip`
        };
        
        CDI.showNotice('success', messages[selectionType] || `${selectedCount} rows updated`);
    };
    
    // NEW: Show investigation modal
    window.cdiShowInvestigateModal = function(rowIndex) {
        console.log('Opening investigation modal for row:', rowIndex);
        
        // Get row data from the hidden JSON script tag
        const rowDataScript = document.querySelector(`.cdi-row-data[data-row="${rowIndex}"]`);
        if (!rowDataScript) {
            console.error('Row data not found for index:', rowIndex);
            CDI.showNotice('error', 'Row data not found');
            return;
        }
        
        let rowData;
        try {
            rowData = JSON.parse(rowDataScript.textContent);
        } catch (e) {
            console.error('Failed to parse row data:', e);
            CDI.showNotice('error', 'Failed to load row data');
            return;
        }
        
        // Get casino name (first non-empty value)
        const casinoName = Object.values(rowData).find(val => val && val.toString().trim()) || 'Unknown Casino';
        
        // Update modal content
        document.getElementById('cdi-row-number').textContent = rowIndex + 1;
        document.getElementById('cdi-casino-name').textContent = casinoName;
        
        // Build the data table
        const tableBody = document.getElementById('cdi-modal-table-body');
        tableBody.innerHTML = '';
        
        // Define relevant fields for highlighting
        const relevantFields = [
            'Bubble Craps', 'WeekDay Min', 'WeekNight Min', 'WeekendMin', 
            'WeekendnightMin', 'Rewards', 'Sidebet', 'Comments', 'Coordinates'
        ];
        
        let hasComments = false;
        let commentsContent = '';
        
        Object.entries(rowData).forEach(([field, value]) => {
            const row = tableBody.insertRow();
            
            // Field name cell
            const fieldCell = row.insertCell(0);
            fieldCell.innerHTML = `<strong>${CDI.escapeHtml(field)}</strong>`;
            
            // Value cell
            const valueCell = row.insertCell(1);
            const displayValue = value && value.toString().trim() ? value : '<em style="color: #999;">Empty</em>';
            valueCell.innerHTML = CDI.escapeHtml(displayValue);
            
            // Relevant indicator cell
            const relevantCell = row.insertCell(2);
            const isRelevant = relevantFields.includes(field);
            relevantCell.innerHTML = isRelevant ? 
                '<span style="color: #28a745;">✓ Yes</span>' : 
                '<span style="color: #999;">○ No</span>';
            
            // Highlight relevant rows
            if (isRelevant && value && value.toString().trim()) {
                row.style.backgroundColor = '#f8f9fa';
                row.style.borderLeft = '3px solid #007cba';
            }
            
            // Check for comments
            if (field.toLowerCase().includes('comment') && value && value.toString().trim()) {
                hasComments = true;
                commentsContent = value.toString();
            }
        });
        
        // Show/hide comments section
        const commentsSection = document.getElementById('cdi-comments-section');
        const commentsContentDiv = document.getElementById('cdi-comments-content');
        
        if (hasComments) {
            commentsSection.style.display = 'block';
            commentsContentDiv.textContent = commentsContent;
        } else {
            commentsSection.style.display = 'none';
        }
        
        // Show the modal
        document.getElementById('cdi-investigate-modal').style.display = 'block';
        document.body.style.overflow = 'hidden'; // Prevent background scrolling
    };
    
    // NEW: Close investigation modal
    window.cdiCloseInvestigateModal = function() {
        console.log('Closing investigation modal');
        document.getElementById('cdi-investigate-modal').style.display = 'none';
        document.body.style.overflow = ''; // Restore scrolling
    };
    
    // NEW: Copy row data to clipboard
    window.cdiCopyRowData = function() {
        console.log('Copying row data to clipboard');
        
        const tableBody = document.getElementById('cdi-modal-table-body');
        const rows = tableBody.querySelectorAll('tr');
        
        let clipboardText = 'Field\tValue\tRelevant\n';
        
        rows.forEach(row => {
            const cells = row.querySelectorAll('td');
            if (cells.length >= 3) {
                const field = cells[0].textContent.trim();
                const value = cells[1].textContent.trim();
                const relevant = cells[2].textContent.includes('Yes') ? 'Yes' : 'No';
                clipboardText += `${field}\t${value}\t${relevant}\n`;
            }
        });
        
        // Try to copy to clipboard
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(clipboardText).then(() => {
                CDI.showNotice('success', 'Row data copied to clipboard');
            }).catch(err => {
                console.error('Failed to copy to clipboard:', err);
                // Fallback: show data in a text area for manual copying
                cdiShowCopyFallback(clipboardText);
            });
        } else {
            // Fallback for older browsers
            cdiShowCopyFallback(clipboardText);
        }
    };
    
    // Helper function for copy fallback
    function cdiShowCopyFallback(text) {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.top = '50%';
        textarea.style.left = '50%';
        textarea.style.transform = 'translate(-50%, -50%)';
        textarea.style.width = '80%';
        textarea.style.height = '300px';
        textarea.style.zIndex = '99999';
        textarea.style.backgroundColor = 'white';
        textarea.style.border = '2px solid #333';
        textarea.style.padding = '10px';
        
        document.body.appendChild(textarea);
        textarea.select();
        textarea.focus();
        
        // Remove after 10 seconds
        setTimeout(() => {
            if (textarea.parentNode) {
                textarea.parentNode.removeChild(textarea);
            }
        }, 10000);
        
        CDI.showNotice('info', 'Data selected for copying. Press Ctrl+C (or Cmd+C on Mac) to copy.');
    }
    
    // NEW: Enhanced keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // ESC to close modal
        if (e.key === 'Escape') {
            const modal = document.getElementById('cdi-investigate-modal');
            if (modal && modal.style.display === 'block') {
                cdiCloseInvestigateModal();
            }
        }
        
        // Ctrl/Cmd + A to select all for update (when not in an input)
        if ((e.ctrlKey || e.metaKey) && e.key === 'a' && !e.target.matches('input, textarea, select')) {
            e.preventDefault();
            cdiMassSelect('update');
        }
        
        // Ctrl/Cmd + S to skip all (when not in an input)
        if ((e.ctrlKey || e.metaKey) && e.key === 's' && !e.target.matches('input, textarea, select')) {
            e.preventDefault();
            cdiMassSelect('skip');
        }
    });
    
    // NEW: Enhanced row filtering functions
    CDI.filterRowsByConfidence = function(minConfidence) {
        const rows = document.querySelectorAll('.cdi-match-item');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const confidence = parseInt(row.dataset.confidence) || 0;
            if (confidence >= minConfidence) {
                row.style.display = 'block';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        console.log(`Filtered to ${visibleCount} rows with ${minConfidence}+ confidence`);
        return visibleCount;
    };
    
    CDI.filterRowsByAction = function(actionType) {
        const rows = document.querySelectorAll('.cdi-match-item');
        let visibleCount = 0;
        
        rows.forEach(row => {
            const select = row.querySelector('.cdi-row-action');
            if (select && select.value === actionType) {
                row.style.display = 'block';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });
        
        console.log(`Filtered to ${visibleCount} rows with action: ${actionType}`);
        return visibleCount;
    };
    
    CDI.showAllRows = function() {
        document.querySelectorAll('.cdi-match-item').forEach(row => {
            row.style.display = 'block';
        });
        console.log('Showing all rows');
    };
    
    // NEW: Selection summary functions
    CDI.getSelectionSummary = function() {
        const selects = document.querySelectorAll('.cdi-row-action');
        const summary = {
            update: 0,
            review: 0,
            skip: 0,
            total: selects.length
        };
        
        selects.forEach(select => {
            if (summary.hasOwnProperty(select.value)) {
                summary[select.value]++;
            }
        });
        
        return summary;
    };
    
    CDI.showSelectionSummary = function() {
        const summary = CDI.getSelectionSummary();
        const message = `Selection Summary: ${summary.update} Update, ${summary.review} Review, ${summary.skip} Skip (${summary.total} total)`;
        console.log(message);
        CDI.showNotice('info', message);
        return summary;
    };
    
    // NEW: Auto-save selections to localStorage (optional feature)
    CDI.saveSelections = function() {
        const selections = {};
        document.querySelectorAll('.cdi-row-action').forEach((select, index) => {
            selections[index] = select.value;
        });
        
        try {
            localStorage.setItem('cdi_preview_selections', JSON.stringify(selections));
            console.log('Selections saved to localStorage');
        } catch (e) {
            console.warn('Could not save selections:', e);
        }
    };
    
    CDI.loadSelections = function() {
        try {
            const saved = localStorage.getItem('cdi_preview_selections');
            if (saved) {
                const selections = JSON.parse(saved);
                document.querySelectorAll('.cdi-row-action').forEach((select, index) => {
                    if (selections.hasOwnProperty(index)) {
                        select.value = selections[index];
                    }
                });
                console.log('Selections loaded from localStorage');
                CDI.showNotice('info', 'Previous selections restored');
            }
        } catch (e) {
            console.warn('Could not load selections:', e);
        }
    };
    
    // Auto-save selections when they change
    document.addEventListener('change', function(e) {
        if (e.target.matches('.cdi-row-action')) {
            CDI.saveSelections();
        }
    });
    
    // Load selections on page load
    document.addEventListener('DOMContentLoaded', function() {
        // Small delay to ensure DOM is fully ready
        setTimeout(() => {
            CDI.loadSelections();
        }, 500);
    });

    // Legacy support for the old function names used in the PHP
    window.cdiSelectAllRows = function(action) {
        if (typeof window.cdiMassSelect === 'function') {
            window.cdiMassSelect(action);
        } else {
            console.error('cdiMassSelect function not available');
        }
    };
    
    window.cdiResetSelections = function() {
        if (typeof window.cdiResetAllSelections === 'function') {
            window.cdiResetAllSelections();
        } else {
            console.error('cdiResetAllSelections function not available');
        }
    };
    
    // Initialize when DOM is ready
    $(document).ready(function() {
        CDI.init();
    });

})(jQuery); // Pass jQuery to our wrapper function