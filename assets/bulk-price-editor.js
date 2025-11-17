jQuery(document).ready(function($) {
    let batchProcessing = false;
    let successfulBatches = 0;
    let totalBatches = 0;
    
    // Load categories on page load
    loadCategories();
    
    // Test AJAX connection on page load
    setTimeout(function() {
        console.log('Page loaded, testing AJAX...');
        testAjaxConnection();
    }, 1000);
    
    // Manual test button
    $('#test-ajax').click(function() {
        console.log('Manual AJAX test triggered');
        testAjaxConnection();
    });
    
    function testAjaxConnection() {
        console.log('Testing AJAX with:', {
            url: wcBulkPrice.ajaxurl,
            nonce: wcBulkPrice.nonce
        });
        
        $.ajax({
            url: wcBulkPrice.ajaxurl,
            type: 'POST',
            data: {
                action: 'wc_bulk_price_test',
                nonce: wcBulkPrice.nonce
            },
            success: function(response) {
                console.log('✅ AJAX Test Success:', response);
                alert('AJAX Connection Working! Check console for details.');
            },
            error: function(xhr, status, error) {
                console.error('❌ AJAX Test Failed:', {
                    status: xhr.status,
                    statusText: xhr.statusText,
                    responseText: xhr.responseText,
                    error: error
                });
                alert('AJAX Test Failed! Status: ' + xhr.status + '. Check console for details.');
            }
        });
    }
    
    // Toggle filter rows based on selection and load data dynamically
    $('#filter_type').change(function() {
        var filterType = $(this).val();
        $('#name_filter_row, #attribute_filter_row, #category_filter_row').hide();
        $('#' + filterType + '_filter_row').show();
        
        // Load attributes or categories dynamically only when needed
        if (filterType === 'attribute' && $('#attribute_name option').length <= 1) {
            loadAttributes();
        } else if (filterType === 'category' && $('#category_id option').length <= 1) {
            loadCategories();
        }
    });

    // Function to update price unit based on action for a given prefix (regular_ or sale_)
    function updatePriceUnit(prefix) {
        var action = $('#' + prefix + 'price_action').val();
        var unit = '$';
        var valueRow = $('.' + prefix + 'price-value-row');

        if (action.includes('percent')) {
            unit = '%';
            valueRow.show();
        } else if (action === 'set' || action.includes('amount')) {
            unit = '$';
            valueRow.show();
        } else if (action === 'clear_sale' || action === 'none') {
            valueRow.hide(); // Hide value input if action is 'none' or 'clear_sale'
        }
        $('#' + prefix + 'price_unit').text(unit);
    }

    // Initialize units on load
    updatePriceUnit('regular_');
    updatePriceUnit('sale_');

    // Update regular price unit based on action
    $('#regular_price_action').change(function() {
        updatePriceUnit('regular_');
    });

    // Update sale price unit based on action
    $('#sale_price_action').change(function() {
        updatePriceUnit('sale_');
    });

    // Filter products with our optimized backend
    $('#filter-products').click(function() {
        var formData = {
            action: 'wc_bulk_price_filter',
            nonce: wcBulkPrice.nonce,
            search_word: $('#search_word').val(),
            category_id: $('#category_id').val(),
            limit: 100 // Limit results for performance
        };

        $.ajax({
            url: wcBulkPrice.ajaxurl,
            type: 'POST',
            data: formData,
            beforeSend: function() {
                $('#filter-products').prop('disabled', true).val('Filtering...');
            },
            success: function(response) {
                if (response.success) {
                    $('#product-results').html(response.data.html);
                    $('#results-section').show();
                    $('#update-results').hide(); // Hide previous update results
                    
                    // Initialize "Select All" functionality
                    $('#select-all-products').change(function() {
                        $('.product-checkbox').prop('checked', $(this).is(':checked'));
                        updateSelectedCount();
                    });
                    
                    // Update count when individual checkboxes change
                    $(document).on('change', '.product-checkbox', function() {
                        updateSelectedCount();
                        updateSelectAllState();
                    });
                    
                    updateSelectedCount();
                } else {
                    alert('Error: ' + (response.data.message || 'Unknown error'));
                    $('#results-section').hide(); // Hide if no products found
                }
            },
            error: function(xhr, status, error) {
                console.error('Filter error:', error);
                alert('An error occurred while filtering products: ' + error);
            },
            complete: function() {
                $('#filter-products').prop('disabled', false).val('Filter Products');
            }
        });
    });

    // Batch update prices for better performance
    $('#update-prices').click(function() {
        if (batchProcessing) {
            return;
        }
        
        var selectedProducts = [];
        $('.product-checkbox:checked').each(function() {
            selectedProducts.push($(this).val());
        });

        if (selectedProducts.length === 0) {
            alert('Please select at least one product to update.');
            return;
        }

        var regularAction = $('#regular_price_action').val();
        var saleAction = $('#sale_price_action').val();

        if (regularAction === 'none' && saleAction === 'none') {
            alert('Please select at least one price action (regular or sale).');
            return;
        }

        // Validation for values if action is not 'none' or 'clear_sale'
        var regularValue = $('#regular_price_value').val();
        var saleValue = $('#sale_price_value').val();
        
        if (regularAction !== 'none' && regularAction !== 'clear_sale' && regularValue === '') {
            alert('Please enter a value for Regular Price.');
            return;
        }
        if (saleAction !== 'none' && saleAction !== 'clear_sale' && saleValue === '') {
            alert('Please enter a value for Sale Price.');
            return;
        }
        
        // Convert to float for numerical comparison
        regularValue = parseFloat(regularValue) || 0;
        saleValue = parseFloat(saleValue) || 0;

        // Client-side validation: Sale price cannot be greater than regular price if both are set to a fixed value
        if (regularAction === 'set' && saleAction === 'set' && saleValue > regularValue) {
            alert('Sale price cannot be greater than regular price.');
            return;
        }

        // Process in batches of 20 for better performance and reliability
        batchProcessing = true;
        successfulBatches = 0;
        totalBatches = Math.ceil(selectedProducts.length / 20);
        
        $('#update-prices').prop('disabled', true).val('Processing...');
        $('#price-spinner').addClass('is-active');
        $('#progress-bar').show();
        $('#update-message').html(''); // Clear previous messages
        $('#update-results').show();
        
        console.log('Starting batch processing:', {
            totalProducts: selectedProducts.length,
            totalBatches: totalBatches,
            regularAction: regularAction,
            saleAction: saleAction
        });
        
        processBatch(selectedProducts, 0, 20, regularAction, saleAction);
    });

    function processBatch(productIds, startIndex, batchSize, regularAction, saleAction) {
        var batch = productIds.slice(startIndex, startIndex + batchSize);
        var currentBatch = Math.floor(startIndex / batchSize) + 1;
        var progress = Math.round((startIndex / productIds.length) * 100);
        
        $('#progress-text').text('Processing batch ' + currentBatch + ' of ' + totalBatches + ' (' + (startIndex + 1) + '-' + Math.min(startIndex + batchSize, productIds.length) + ' of ' + productIds.length + ' products)');
        $('#progress-fill').css('width', progress + '%');

        if (batch.length === 0) {
            // Processing complete - check if any batches were successful
            batchProcessing = false;
            $('#update-prices').prop('disabled', false).val('Update Prices');
            $('#price-spinner').removeClass('is-active');
            $('#progress-bar').hide();
            
            if (successfulBatches > 0) {
                $('#update-message').append('<p class="success-message"><strong>✓ Processing completed! ' + successfulBatches + ' of ' + totalBatches + ' batches processed successfully.</strong></p>');
                // Refresh the product list to show updated prices
                setTimeout(function() {
                    $('#filter-products').click();
                }, 1000);
            } else {
                $('#update-message').append('<p class="error-message"><strong>✗ No batches were processed successfully. Please check the errors above and try again.</strong></p>');
            }
            return;
        }

        var formData = {
            action: 'wc_bulk_price_batch_update',
            nonce: wcBulkPrice.nonce,
            product_ids: batch.join(','),
            regular_price_action: regularAction,
            regular_price_value: $('#regular_price_value').val() || '0',
            sale_price_action: saleAction,
            sale_price_value: $('#sale_price_value').val() || '0'
        };

        console.log('Sending batch ' + currentBatch + ':', formData);

        $.ajax({
            url: wcBulkPrice.ajaxurl,
            type: 'POST',
            data: formData,
            timeout: 60000, // 60 second timeout
            success: function(response) {
                console.log('Batch ' + currentBatch + ' response:', response);
                
                if (response && response.success) {
                    successfulBatches++;
                    $('#update-message').append('<p class="success-message">✓ Batch ' + currentBatch + ': ' + (response.data.message || 'Batch processed successfully') + '</p>');
                } else {
                    console.error('Batch ' + currentBatch + ' response error:', response);
                    $('#update-message').append('<p class="error-message">✗ Batch ' + currentBatch + ' error: ' + (response && response.data && response.data.message ? response.data.message : 'Server returned invalid response') + '</p>');
                }
                
                // Process next batch
                setTimeout(function() {
                    processBatch(productIds, startIndex + batchSize, batchSize, regularAction, saleAction);
                }, 1000);
            },
            error: function(xhr, status, error) {
                console.error('Batch ' + currentBatch + ' AJAX Error:', {
                    xhr: xhr,
                    status: status,
                    error: error,
                    responseText: xhr.responseText,
                    readyState: xhr.readyState
                });
                
                var errorMsg = 'Connection failed';
                if (xhr.status === 0) {
                    errorMsg = 'Network error - check if server is running';
                } else if (xhr.status === 403) {
                    errorMsg = 'Permission denied (403)';
                } else if (xhr.status === 404) {
                    errorMsg = 'AJAX endpoint not found (404)';
                } else if (xhr.status === 500) {
                    errorMsg = 'Server error (500) - check server logs';
                } else if (xhr.responseText) {
                    try {
                        var errorResponse = JSON.parse(xhr.responseText);
                        errorMsg = errorResponse.data ? errorResponse.data.message : 'Server error';
                    } catch(e) {
                        errorMsg = 'Server returned invalid response';
                    }
                } else if (status === 'timeout') {
                    errorMsg = 'Request timed out - try smaller batches';
                } else if (error) {
                    errorMsg = error;
                }
                
                $('#update-message').append('<p class="error-message">✗ Batch ' + currentBatch + ' failed: ' + errorMsg + ' (HTTP ' + xhr.status + ')</p>');
                
                // Continue with next batch despite error
                setTimeout(function() {
                    processBatch(productIds, startIndex + batchSize, batchSize, regularAction, saleAction);
                }, 2000);
            }
        });
    }

    function updateSelectedCount() {
        var selectedCount = $('.product-checkbox:checked').length;
        var totalCount = $('.product-checkbox').length;
        $('#selected-count').text(selectedCount + ' of ' + totalCount + ' products selected');
    }

    function updateSelectAllState() {
        var totalCheckboxes = $('.product-checkbox').length;
        var checkedCheckboxes = $('.product-checkbox:checked').length;
        
        if (checkedCheckboxes === 0) {
            $('#select-all-products').prop('indeterminate', false).prop('checked', false);
        } else if (checkedCheckboxes === totalCheckboxes) {
            $('#select-all-products').prop('indeterminate', false).prop('checked', true);
        } else {
            $('#select-all-products').prop('indeterminate', true);
        }
    }
    
    // Dynamic loading functions
    function loadAttributes() {
        $('#attribute_name').html('<option value="">Loading...</option>');
        
        $.ajax({
            url: wcBulkPrice.ajaxurl,
            type: 'POST',
            data: {
                action: 'wc_bulk_price_load_attributes',
                nonce: wcBulkPrice.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#attribute_name').html(response.data.html);
                } else {
                    $('#attribute_name').html('<option value="">Error loading attributes</option>');
                }
            },
            error: function() {
                $('#attribute_name').html('<option value="">Error loading attributes</option>');
            }
        });
    }
    
    function loadCategories() {
        $('#category_id').html('<option value="">Loading...</option>');
        
        $.ajax({
            url: wcBulkPrice.ajaxurl,
            type: 'POST',
            data: {
                action: 'wc_bulk_price_load_categories',
                nonce: wcBulkPrice.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#category_id').html(response.data.html);
                } else {
                    $('#category_id').html('<option value="">Error loading categories</option>');
                }
            },
            error: function() {
                $('#category_id').html('<option value="">Error loading categories</option>');
            }
        });
    }
});