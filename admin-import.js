jQuery(document).ready(function($) {
    const importBtn = $('#nws-import-btn');
    const clearBtn = $('#nws-clear-btn');
    const statusDiv = $('#nws-import-status');
    const progressDiv = $('#nws-import-progress');
    const progressBar = $('#nws-progress-bar');
    const progressText = $('#nws-progress-text');
    const statsDiv = $('#nws-import-stats');
    
    // Import button click
    importBtn.on('click', function() {
        if (!confirm('This will import NWS location data. This may take a few minutes. Continue?')) {
            return;
        }
        
        // Disable buttons
        importBtn.prop('disabled', true);
        clearBtn.prop('disabled', true);
        
        // Reset UI
        statusDiv.hide().removeClass('notice-success notice-error');
        statsDiv.hide();
        progressDiv.show();
        progressBar.val(0);
        progressText.text('Fetching data from NWS...');
        
        // Perform import
        $.ajax({
            url: nwsImport.ajax_url,
            type: 'POST',
            data: {
                action: 'nws_import_data',
                nonce: nwsImport.nonce
            },
            success: function(response) {
                if (response.success) {
                    progressBar.val(100);
                    progressText.text('Import complete!');
                    
                    // Show stats
                    $('#ugc-count').text(response.data.ugc_created);
                    $('#same-count').text(response.data.same_created);
                    $('#total-count').text(response.data.total_processed);
                    statsDiv.show();
                    
                    // Show success message
                    statusDiv
                        .addClass('notice-success')
                        .find('p')
                        .text('Data imported successfully!')
                        .end()
                        .show();
                    
                    setTimeout(function() {
                        progressDiv.hide();
                    }, 2000);
                } else {
                    showError(response.data.message || 'Import failed');
                }
            },
            error: function(xhr, status, error) {
                showError('AJAX error: ' + error);
            },
            complete: function() {
                importBtn.prop('disabled', false);
                clearBtn.prop('disabled', false);
            }
        });
    });
    
    // Clear button click
    clearBtn.on('click', function() {
        if (!confirm('This will delete ALL UGC and SAME code posts. This action cannot be undone. Continue?')) {
            return;
        }
        
        // Disable buttons
        importBtn.prop('disabled', true);
        clearBtn.prop('disabled', true);
        
        // Reset UI
        statusDiv.hide().removeClass('notice-success notice-error');
        statsDiv.hide();
        progressDiv.show();
        progressBar.val(50);
        progressText.text('Deleting posts...');
        
        // Perform clear
        $.ajax({
            url: nwsImport.ajax_url,
            type: 'POST',
            data: {
                action: 'nws_clear_data',
                nonce: nwsImport.nonce
            },
            success: function(response) {
                if (response.success) {
                    progressBar.val(100);
                    progressText.text('Clear complete!');
                    
                    // Show success message
                    statusDiv
                        .addClass('notice-success')
                        .find('p')
                        .text(response.data.message)
                        .end()
                        .show();
                    
                    setTimeout(function() {
                        progressDiv.hide();
                        statsDiv.hide();
                        $('#ugc-count').text('0');
                        $('#same-count').text('0');
                        $('#total-count').text('0');
                    }, 2000);
                } else {
                    showError(response.data.message || 'Clear failed');
                }
            },
            error: function(xhr, status, error) {
                showError('AJAX error: ' + error);
            },
            complete: function() {
                importBtn.prop('disabled', false);
                clearBtn.prop('disabled', false);
            }
        });
    });
    
    function showError(message) {
        progressDiv.hide();
        statusDiv
            .addClass('notice-error')
            .find('p')
            .text('Error: ' + message)
            .end()
            .show();
    }
});