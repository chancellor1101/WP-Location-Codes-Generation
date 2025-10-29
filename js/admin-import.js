jQuery(document).ready(function($) {
    const importBtn = $('#nws-import-btn');
    const clearBtn = $('#nws-clear-btn');
    const polygonBtn = $('#nws-polygon-btn');
    const statusDiv = $('#nws-import-status');
    const progressDiv = $('#nws-import-progress');
    const progressBar = $('#nws-progress-bar');
    const progressText = $('#nws-progress-text');
    const statsDiv = $('#nws-import-stats');
    
    const polygonStatusDiv = $('#nws-polygon-status');
    const polygonProgressDiv = $('#nws-polygon-progress');
    const polygonProgressBar = $('#nws-polygon-bar');
    const polygonProgressText = $('#nws-polygon-text');
    const polygonStatsDiv = $('#nws-polygon-stats');
    
    let totalPolygonRecords = 0;
    let processedPolygons = 0;
    let totalUpdated = 0;
    let totalNotFound = 0;
    
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
                    $('#error-count').text(response.data.error_count || 0);
                    statsDiv.show();
                    
                    // Show errors if any
                    if (response.data.errors && response.data.errors.length > 0) {
                        const errorHtml = '<ul>' + 
                            response.data.errors.slice(0, 50).map(function(err) {
                                return '<li>' + err + '</li>';
                            }).join('') + 
                            (response.data.errors.length > 50 ? '<li><em>... and ' + (response.data.errors.length - 50) + ' more</em></li>' : '') +
                            '</ul>';
                        $('#nws-error-content').html(errorHtml);
                        $('#nws-error-log').show();
                    }
                    
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
    
    // Polygon import button click
    polygonBtn.on('click', function() {
        if (!confirm('This will download and process a large shapefile (~50MB). This may take 5-10 minutes. Continue?')) {
            return;
        }
        
        // Disable buttons
        importBtn.prop('disabled', true);
        clearBtn.prop('disabled', true);
        polygonBtn.prop('disabled', true);
        
        // Reset UI
        polygonStatusDiv.hide().removeClass('notice-success notice-error');
        polygonStatsDiv.hide();
        polygonProgressDiv.show();
        polygonProgressBar.val(0);
        polygonProgressText.text('Downloading shapefile...');
        
        const simplify = $('#nws-simplify-polygons').is(':checked');
        
        // Start polygon import
        $.ajax({
            url: nwsImport.ajax_url,
            type: 'POST',
            data: {
                action: 'nws_import_polygons',
                nonce: nwsImport.nonce,
                simplify: simplify
            },
            success: function(response) {
                if (response.success) {
                    totalPolygonRecords = response.data.total_records;
                    processedPolygons = 0;
                    totalUpdated = 0;
                    totalNotFound = 0;
                    
                    polygonProgressText.text('Processing polygons (0/' + totalPolygonRecords + ')...');
                    
                    // Start batch processing
                    processPolygonBatch(0);
                } else {
                    showPolygonError(response.data.message || 'Failed to initialize polygon import');
                }
            },
            error: function(xhr, status, error) {
                showPolygonError('AJAX error: ' + error);
            }
        });
    });
    
    function processPolygonBatch(offset) {
        $.ajax({
            url: nwsImport.ajax_url,
            type: 'POST',
            data: {
                action: 'nws_process_polygon_batch',
                nonce: nwsImport.nonce,
                offset: offset,
                batch_size: 50
            },
            success: function(response) {
                if (response.success) {
                    processedPolygons += response.data.processed;
                    totalUpdated += response.data.updated;
                    totalNotFound += response.data.not_found;
                    
                    // Update progress
                    const percent = Math.round((processedPolygons / totalPolygonRecords) * 100);
                    polygonProgressBar.val(percent);
                    polygonProgressText.text('Processing polygons (' + processedPolygons + '/' + totalPolygonRecords + ')...');
                    
                    if (response.data.is_complete) {
                        // Complete!
                        polygonProgressBar.val(100);
                        polygonProgressText.text('Polygon import complete!');
                        
                        // Show stats
                        $('#polygon-count').text(totalUpdated);
                        $('#zones-updated').text(totalUpdated);
                        $('#zones-not-found').text(totalNotFound);
                        polygonStatsDiv.show();
                        
                        // Show success message
                        polygonStatusDiv
                            .addClass('notice-success')
                            .find('p')
                            .text('Polygons imported successfully!')
                            .end()
                            .show();
                        
                        setTimeout(function() {
                            polygonProgressDiv.hide();
                        }, 2000);
                        
                        // Re-enable buttons
                        importBtn.prop('disabled', false);
                        clearBtn.prop('disabled', false);
                        polygonBtn.prop('disabled', false);
                    } else {
                        // Continue with next batch
                        processPolygonBatch(response.data.next_offset);
                    }
                } else {
                    showPolygonError(response.data.message || 'Batch processing failed');
                }
            },
            error: function(xhr, status, error) {
                showPolygonError('AJAX error during batch processing: ' + error);
            }
        });
    }
    
    function showPolygonError(message) {
        polygonProgressDiv.hide();
        polygonStatusDiv
            .addClass('notice-error')
            .find('p')
            .text('Error: ' + message)
            .end()
            .show();
        
        // Re-enable buttons
        importBtn.prop('disabled', false);
        clearBtn.prop('disabled', false);
        polygonBtn.prop('disabled', false);
    }
});