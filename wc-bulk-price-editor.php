<?php
/**
 * Plugin Name: WooCommerce Bulk Price Editor
 * Description: Bulk edit product prices with filtering
 * Version: 1.5.1
 * Author: Evgenii
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Check if WooCommerce is active
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

class WC_Bulk_Price_Editor {

    public function __construct() {
        add_action('init', array($this, 'init'));
    }

    public function init() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        // AJAX actions
        add_action('wp_ajax_wc_bulk_price_test', array($this, 'ajax_test'));
        add_action('wp_ajax_wc_bulk_price_filter', array($this, 'ajax_filter_products'));
        add_action('wp_ajax_wc_bulk_price_simple_update', array($this, 'ajax_simple_update'));
        add_action('wp_ajax_wc_bulk_price_cleanup_db', array($this, 'ajax_cleanup_database'));
        
        add_action('admin_init', array($this, 'check_woocommerce'));
    }

    public function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
        }
    }

    public function woocommerce_missing_notice() {
        echo '<div class="error"><p><strong>WooCommerce Bulk Price Editor</strong> requires WooCommerce to be installed and active.</p></div>';
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            'Bulk Price Editor',
            'Bulk Price Editor',
            'manage_woocommerce',
            'wc-bulk-price-editor',
            array($this, 'admin_page')
        );
    }

    public function enqueue_scripts($hook) {
        if ($hook !== 'woocommerce_page_wc-bulk-price-editor') {
            return;
        }

        wp_enqueue_script('jquery');
        
        $nonce = wp_create_nonce('wc_bulk_price_nonce');
        $ajax_url = admin_url('admin-ajax.php');
        
        wp_add_inline_script('jquery', "
        var wcBulkPrice = {
            ajaxurl: '$ajax_url',
            nonce: '$nonce'
        };
        
        jQuery(document).ready(function($) {
            console.log('Plugin loaded');
            
            // Test AJAX
            $('#test-ajax').click(function() {
                testAjax();
            });
            
            setTimeout(testAjax, 1000);
            
            // Database cleanup
            $('#cleanup-database').click(function() {
                if (!confirm('This will clean up unnecessary database entries to speed up your site. Continue?')) {
                    return;
                }
                
                $(this).prop('disabled', true).val('Cleaning...');
                
                $.ajax({
                    url: wcBulkPrice.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wc_bulk_price_cleanup_db',
                        nonce: wcBulkPrice.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('✅ Database cleanup completed! ' + response.data.message);
                        } else {
                            alert('❌ Cleanup failed: ' + response.data.message);
                        }
                    },
                    error: function() {
                        alert('❌ Cleanup request failed');
                    },
                    complete: function() {
                        $('#cleanup-database').prop('disabled', false).val('Speed Up Database');
                    }
                });
            });
            
            function testAjax() {
                $.ajax({
                    url: wcBulkPrice.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wc_bulk_price_test',
                        nonce: wcBulkPrice.nonce
                    },
                    success: function(response) {
                        console.log('✅ AJAX Success:', response);
                        alert('AJAX Working!');
                    },
                    error: function(xhr, status, error) {
                        console.error('❌ AJAX Error:', {status: xhr.status, error: error});
                        alert('AJAX Failed: ' + xhr.status);
                    }
                });
            }
            
            // Filter products
            $('#filter-products').click(function() {
                var data = {
                    action: 'wc_bulk_price_filter',
                    nonce: wcBulkPrice.nonce,
                    search_word: $('#search_word').val()
                };
                
                $.post(wcBulkPrice.ajaxurl, data, function(response) {
                    if (response.success) {
                        $('#product-results').html(response.data.html);
                        $('#results-section').show();
                        
                        // Update select all checkbox state
                        updateSelectAllState();
                    } else {
                        alert('Filter error: ' + response.data.message);
                    }
                }).fail(function(xhr) {
                    alert('Filter request failed: ' + xhr.status);
                });
            });
            
            // Select/Deselect All functionality
            $(document).on('change', '#select-all-products', function() {
                var isChecked = $(this).is(':checked');
                $('.product-checkbox').prop('checked', isChecked);
                updateSelectedCount();
            });
            
            // Update select-all state when individual checkboxes change
            $(document).on('change', '.product-checkbox', function() {
                updateSelectAllState();
                updateSelectedCount();
            });
            
            function updateSelectAllState() {
                var totalCheckboxes = $('.product-checkbox').length;
                var checkedCheckboxes = $('.product-checkbox:checked').length;
                var selectAllCheckbox = $('#select-all-products');
                
                if (checkedCheckboxes === 0) {
                    selectAllCheckbox.prop('indeterminate', false);
                    selectAllCheckbox.prop('checked', false);
                } else if (checkedCheckboxes === totalCheckboxes) {
                    selectAllCheckbox.prop('indeterminate', false);
                    selectAllCheckbox.prop('checked', true);
                } else {
                    selectAllCheckbox.prop('indeterminate', true);
                    selectAllCheckbox.prop('checked', false);
                }
            }
            
            function updateSelectedCount() {
                var selectedCount = $('.product-checkbox:checked').length;
                var totalCount = $('.product-checkbox').length;
                $('#selected-count').html('<strong>' + selectedCount + ' of ' + totalCount + ' products selected</strong>');
            }
            
            // Update prices individually
            $('#update-prices').click(function() {
                var selectedProducts = [];
                $('.product-checkbox:checked').each(function() {
                    selectedProducts.push($(this).val());
                });
                
                if (selectedProducts.length === 0) {
                    alert('Please select at least one product first');
                    return;
                }
                
                var regularAction = $('#regular_price_action').val();
                var saleAction = $('#sale_price_action').val();
                
                if (regularAction === 'none' && saleAction === 'none') {
                    alert('Please select at least one price action');
                    return;
                }
                
                // FIXED: Add validation for required values
                if (regularAction !== 'none' && regularAction !== 'clear_sale') {
                    var regularValue = parseFloat($('#regular_price_value').val());
                    if (!regularValue || regularValue <= 0) {
                        alert('Please enter a valid regular price value');
                        return;
                    }
                }
                
                if (saleAction === 'set') {
                    var saleValue = parseFloat($('#sale_price_value').val());
                    if (!saleValue || saleValue <= 0) {
                        alert('Please enter a valid sale price value');
                        return;
                    }
                }
                
                if (!confirm('Update prices for ' + selectedProducts.length + ' selected products?')) {
                    return;
                }
                
                $('#update-prices').prop('disabled', true).val('Processing...');
                $('#update-message').html('<p>Processing ' + selectedProducts.length + ' products...</p>');
                $('#update-results').show();
                
                var successCount = 0;
                var errorCount = 0;
                
                processProducts(selectedProducts, 0, regularAction, saleAction, successCount, errorCount);
            });
            
            function processProducts(productIds, currentIndex, regularAction, saleAction, successCount, errorCount) {
                if (currentIndex >= productIds.length) {
                    $('#update-prices').prop('disabled', false).val('Update Prices');
                    $('#update-message').append('<p><strong>Completed: ' + successCount + ' successful, ' + errorCount + ' failed</strong></p>');
                    if (successCount > 0) {
                        setTimeout(function() { 
                            $('#filter-products').click(); 
                        }, 1000);
                    }
                    return;
                }
                
                var productId = productIds[currentIndex];
                
                $('#update-message').append('<p>Processing product ' + (currentIndex + 1) + ' of ' + productIds.length + '...</p>');
                
                var data = {
                    action: 'wc_bulk_price_simple_update',
                    nonce: wcBulkPrice.nonce,
                    product_id: productId,
                    regular_price_action: regularAction,
                    regular_price_value: $('#regular_price_value').val() || '0',
                    sale_price_action: saleAction,
                    sale_price_value: $('#sale_price_value').val() || '0'
                };
                
                $.ajax({
                    url: wcBulkPrice.ajaxurl,
                    type: 'POST',
                    data: data,
                    timeout: 15000,
                    success: function(response) {
                        if (response && response.success) {
                            $('#update-message').append('<p>✅ Product ' + productId + ': ' + response.data.message + '</p>');
                            successCount++;
                        } else {
                            $('#update-message').append('<p>❌ Product ' + productId + ': Failed - ' + (response.data ? response.data.message : 'Unknown error') + '</p>');
                            errorCount++;
                        }
                        
                        setTimeout(function() {
                            processProducts(productIds, currentIndex + 1, regularAction, saleAction, successCount, errorCount);
                        }, 500);
                    },
                    error: function(xhr, status, error) {
                        $('#update-message').append('<p>❌ Product ' + productId + ': ' + error + '</p>');
                        errorCount++;
                        
                        setTimeout(function() {
                            processProducts(productIds, currentIndex + 1, regularAction, saleAction, successCount, errorCount);
                        }, 1000);
                    }
                });
            }
            
            // Show/hide value inputs
            $('#regular_price_action, #sale_price_action').change(function() {
                var action = $(this).val();
                var isRegular = $(this).attr('id') === 'regular_price_action';
                var valueRow = isRegular ? '.regular-price-value-row' : '.sale-price-value-row';
                
                if (action === 'none' || action === 'clear_sale') {
                    $(valueRow).hide();
                } else {
                    $(valueRow).show();
                }
            });
        });
        ");

        wp_add_inline_style('wp-admin', '
        .select-all-section { margin: 20px 0 0 0; border: 1px solid #ddd; border-radius: 4px; }
        .product-list { margin: 0; border: 1px solid #ddd; border-top: none; max-height: 400px; overflow-y: auto; }
        .product-item { padding: 10px; border-bottom: 1px solid #eee; display: flex; align-items: center; }
        .product-item:hover { background-color: #f9f9f9; }
        .product-checkbox { margin-right: 10px; }
        .product-info { flex: 1; }
        .product-price { margin-left: auto; font-weight: bold; }
        .price-update-section { margin-top: 20px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; }
        .regular-price-value-row, .sale-price-value-row { display: none; }
        #selected-count { font-size: 12px; }
        ');
    }

    public function ajax_test() {
        if (!wp_verify_nonce($_POST['nonce'], 'wc_bulk_price_nonce')) {
            wp_send_json_error(array('message' => 'Nonce failed'));
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        wp_send_json_success(array('message' => 'AJAX is working!'));
    }

    public function ajax_filter_products() {
        if (!wp_verify_nonce($_POST['nonce'], 'wc_bulk_price_nonce')) {
            wp_send_json_error(array('message' => 'Nonce failed'));
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $search_word = sanitize_text_field($_POST['search_word']);
        
        $args = array(
            'post_type' => array('product', 'product_variation'),
            'post_status' => 'publish',
            'posts_per_page' => 150,
            's' => $search_word
        );
        
        $products = get_posts($args);
        
        if (empty($products)) {
            wp_send_json_error(array('message' => 'No products found'));
        }
        
        $html = '<div class="select-all-section" style="padding: 10px; background: #f0f0f1; border-bottom: 2px solid #ddd; margin-bottom: 10px;">
            <label style="font-weight: bold; display: flex; align-items: center; gap: 8px;">
                <input type="checkbox" id="select-all-products" checked> 
                Select/Deselect All Products
            </label>
            <div id="selected-count" style="margin-top: 5px; color: #666;"></div>
        </div>
        <div class="product-list">';
        foreach ($products as $product_post) {
            $product = wc_get_product($product_post->ID);
            if (!$product) continue;
            
            $product_type = $product->get_type();
            $regular_price = $product->get_regular_price();
            $sale_price = $product->get_sale_price();
            
            // Handle variable products - show variations
            if ($product_type === 'variable') {
                $variations = $product->get_available_variations();
                if (!empty($variations)) {
                    foreach ($variations as $variation_data) {
                        $variation = wc_get_product($variation_data['variation_id']);
                        if (!$variation) continue;
                        
                        $var_regular = $variation->get_regular_price();
                        $var_sale = $variation->get_sale_price();
                        
                        $price_display = $var_regular ? wc_price($var_regular) : 'No price';
                        if ($var_sale) {
                            $price_display .= ' (Sale: ' . wc_price($var_sale) . ')';
                        }
                        
                        // Get variation attributes for display
                        $attributes = array();
                        foreach ($variation_data['attributes'] as $attr_name => $attr_value) {
                            $attributes[] = $attr_value;
                        }
                        $attr_string = !empty($attributes) ? ' - ' . implode(', ', $attributes) : '';
                        
                        $html .= sprintf(
                            '<div class="product-item">
                                <input type="checkbox" class="product-checkbox" value="%d" data-type="variation" checked>
                                <div class="product-info">
                                    <strong>%s%s</strong><br>
                                    <small>Variation ID: %d (Parent: %d)</small>
                                </div>
                                <div class="product-price">%s</div>
                            </div>',
                            $variation->get_id(),
                            esc_html($product->get_name()),
                            esc_html($attr_string),
                            $variation->get_id(),
                            $product->get_id(),
                            $price_display
                        );
                    }
                }
            } else {
                // Simple products and other types
                $price_display = $regular_price ? wc_price($regular_price) : 'No price';
                if ($sale_price) {
                    $price_display .= ' (Sale: ' . wc_price($sale_price) . ')';
                }
                
                $html .= sprintf(
                    '<div class="product-item">
                        <input type="checkbox" class="product-checkbox" value="%d" data-type="%s" checked>
                        <div class="product-info">
                            <strong>%s</strong><br>
                            <small>%s ID: %d</small>
                        </div>
                        <div class="product-price">%s</div>
                    </div>',
                    $product->get_id(),
                    $product_type,
                    esc_html($product->get_name()),
                    ucfirst($product_type),
                    $product->get_id(),
                    $price_display
                );
            }
        }
        $html .= '</div>';
        
        wp_send_json_success(array('html' => $html, 'count' => count($products)));
    }

    public function ajax_simple_update() {
        // Faster response without unnecessary WordPress overhead
        if (!wp_verify_nonce($_POST['nonce'], 'wc_bulk_price_nonce')) {
            wp_send_json_error(array('message' => 'Nonce failed'));
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $product_id = intval($_POST['product_id']);
        if (!$product_id) {
            wp_send_json_error(array('message' => 'Invalid product ID'));
        }
        
        $regular_action = sanitize_text_field($_POST['regular_price_action']);
        $regular_value = floatval($_POST['regular_price_value']);
        $sale_action = sanitize_text_field($_POST['sale_price_action']);
        $sale_value = floatval($_POST['sale_price_value']);
        
        try {
            global $wpdb;
            
            // Check if this is a variation or simple product
            $post_type = $wpdb->get_var($wpdb->prepare(
                "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d",
                $product_id
            ));
            
            if (!$post_type || !in_array($post_type, array('product', 'product_variation'))) {
                wp_send_json_error(array('message' => 'Invalid product type'));
            }
            
            // Get current prices - works for both simple products and variations
            $current_regular_row = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_regular_price'",
                $product_id
            ));
            
            $current_sale_row = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_sale_price'",
                $product_id
            ));
            
            $current_regular = floatval($current_regular_row ?: 0);
            $current_sale = floatval($current_sale_row ?: 0);
            
            // DEBUG: Log current values
            $product_type_display = ($post_type === 'product_variation') ? 'Variation' : 'Product';
            error_log("$product_type_display $product_id - Current Regular: $current_regular, Current Sale: $current_sale");
            error_log("Actions - Regular: $regular_action ($regular_value), Sale: $sale_action ($sale_value)");
            
            $new_regular = $current_regular;
            $new_sale = $current_sale;
            $changes_made = false;
            $debug_info = array();
            
            // Calculate new regular price
            if ($regular_action === 'set' && $regular_value > 0) {
                $new_regular = $regular_value;
                error_log("Setting regular price to: $new_regular");
            } elseif ($regular_action === 'increase_percent' && $regular_value != 0) {
                if ($current_regular > 0) {
                    $new_regular = $current_regular * (1 + ($regular_value / 100));
                    error_log("Increasing regular price by {$regular_value}%: $current_regular -> $new_regular");
                } else {
                    wp_send_json_error(array('message' => 'Cannot increase price by percentage when current price is 0'));
                }
            } elseif ($regular_action === 'decrease_percent' && $regular_value != 0) {
                if ($current_regular > 0) {
                    $new_regular = $current_regular * (1 - ($regular_value / 100));
                    $new_regular = max(0, $new_regular); // Don't go below 0
                    error_log("Decreasing regular price by {$regular_value}%: $current_regular -> $new_regular");
                }
            }
            
            // Calculate new sale price
            if ($sale_action === 'set' && $sale_value > 0) {
                $new_sale = $sale_value;
                error_log("Setting sale price to: $new_sale");
            } elseif ($sale_action === 'clear_sale') {
                $new_sale = 0;
                error_log("Clearing sale price");
            }
            
            // Check if regular price needs updating
            if ($regular_action !== 'none' && abs($new_regular - $current_regular) > 0.01) {
                $formatted_price = number_format($new_regular, 2, '.', '');
                
                // Use simple UPDATE or INSERT
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_regular_price'",
                    $product_id
                ));
                
                if ($existing) {
                    $result = $wpdb->update(
                        $wpdb->postmeta,
                        array('meta_value' => $formatted_price),
                        array('post_id' => $product_id, 'meta_key' => '_regular_price'),
                        array('%s'),
                        array('%d', '%s')
                    );
                } else {
                    $result = $wpdb->insert(
                        $wpdb->postmeta,
                        array(
                            'post_id' => $product_id,
                            'meta_key' => '_regular_price',
                            'meta_value' => $formatted_price
                        ),
                        array('%d', '%s', '%s')
                    );
                }
                
                if ($result !== false) {
                    $debug_info[] = "Regular: " . number_format($current_regular, 2) . " → " . number_format($new_regular, 2);
                    $changes_made = true;
                    error_log("Successfully updated regular price for $product_type_display $product_id");
                } else {
                    error_log("Failed to update regular price for $product_type_display $product_id");
                }
            }
            
            // Check if sale price needs updating
            if ($sale_action === 'clear_sale' && $current_sale > 0) {
                $wpdb->delete(
                    $wpdb->postmeta,
                    array('post_id' => $product_id, 'meta_key' => '_sale_price'),
                    array('%d', '%s')
                );
                $debug_info[] = "Sale: CLEARED";
                $changes_made = true;
                
            } elseif ($sale_action === 'set' && $sale_value > 0 && abs($new_sale - $current_sale) > 0.01) {
                $formatted_price = number_format($new_sale, 2, '.', '');
                
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_sale_price'",
                    $product_id
                ));
                
                if ($existing) {
                    $wpdb->update(
                        $wpdb->postmeta,
                        array('meta_value' => $formatted_price),
                        array('post_id' => $product_id, 'meta_key' => '_sale_price'),
                        array('%s'),
                        array('%d', '%s')
                    );
                } else {
                    $wpdb->insert(
                        $wpdb->postmeta,
                        array(
                            'post_id' => $product_id,
                            'meta_key' => '_sale_price',
                            'meta_value' => $formatted_price
                        ),
                        array('%d', '%s', '%s')
                    );
                }
                
                $debug_info[] = "Sale: " . number_format($current_sale, 2) . " → " . number_format($new_sale, 2);
                $changes_made = true;
            }
            
            // Update display price (_price field) - IMPORTANT for both products and variations
            if ($changes_made) {
                $final_regular = ($regular_action !== 'none' && abs($new_regular - $current_regular) > 0.01) ? $new_regular : $current_regular;
                $final_sale = ($sale_action === 'clear_sale') ? 0 : (($sale_action === 'set' && $sale_value > 0) ? $new_sale : $current_sale);
                
                $display_price = ($final_sale > 0) ? $final_sale : $final_regular;
                $formatted_display = number_format($display_price, 2, '.', '');
                
                $existing_price = $wpdb->get_var($wpdb->prepare(
                    "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_price'",
                    $product_id
                ));
                
                if ($existing_price) {
                    $wpdb->update(
                        $wpdb->postmeta,
                        array('meta_value' => $formatted_display),
                        array('post_id' => $product_id, 'meta_key' => '_price'),
                        array('%s'),
                        array('%d', '%s')
                    );
                } else {
                    $wpdb->insert(
                        $wpdb->postmeta,
                        array(
                            'post_id' => $product_id,
                            'meta_key' => '_price',
                            'meta_value' => $formatted_display
                        ),
                        array('%d', '%s', '%s')
                    );
                }
                
                // For variations, we also need to update the parent variable product's price range
                if ($post_type === 'product_variation') {
                    $parent_id = $wpdb->get_var($wpdb->prepare(
                        "SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d",
                        $product_id
                    ));
                    
                    if ($parent_id) {
                        // Clear the parent product's cached price range so it recalculates
                        wp_cache_delete($parent_id, 'post_meta');
                        wc_delete_product_transients($parent_id);
                        
                        // Force parent product to sync its price range
                        $parent_product = wc_get_product($parent_id);
                        if ($parent_product && $parent_product->get_type() === 'variable') {
                            $parent_product->sync($parent_id);
                        }
                        
                        error_log("Updated parent variable product $parent_id price range");
                    }
                }
                
                // Clear cache for this product/variation
                wp_cache_delete($product_id, 'post_meta');
                wc_delete_product_transients($product_id);
                
                $message = implode(', ', $debug_info);
                error_log("SUCCESS: $product_type_display $product_id updated - $message");
                
                wp_send_json_success(array(
                    'message' => $message,
                    'updates' => count($debug_info)
                ));
            } else {
                error_log("NO CHANGES: $product_type_display $product_id - Regular action: $regular_action, Sale action: $sale_action");
                wp_send_json_success(array(
                    'message' => "No changes needed (Current: " . number_format($current_regular, 2) . ")",
                    'updates' => 0
                ));
            }
            
        } catch (Exception $e) {
            error_log("ERROR updating product $product_id: " . $e->getMessage());
            wp_send_json_error(array('message' => 'Error: ' . $e->getMessage()));
        }
    }

    // Database cleanup to improve performance
    public function ajax_cleanup_database() {
        if (!wp_verify_nonce($_POST['nonce'], 'wc_bulk_price_nonce')) {
            wp_send_json_error(array('message' => 'Nonce failed'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Insufficient permissions'));
        }
        
        try {
            global $wpdb;
            
            $cleaned = 0;
            
            // Clean expired transients (major performance killer)
            $expired_transients = $wpdb->query("
                DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE '_transient_timeout_%' 
                AND option_value < UNIX_TIMESTAMP()
            ");
            $cleaned += $expired_transients;
            
            // Remove the corresponding transient data
            $wpdb->query("
                DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE '_transient_%' 
                AND option_name NOT LIKE '_transient_timeout_%'
                AND option_name NOT IN (
                    SELECT REPLACE(option_name, '_transient_timeout_', '_transient_')
                    FROM {$wpdb->options} 
                    WHERE option_name LIKE '_transient_timeout_%'
                )
            ");
            
            // Clean orphaned site transients
            $wpdb->query("
                DELETE FROM {$wpdb->options} 
                WHERE option_name LIKE '_site_transient_%' 
                AND option_name NOT LIKE '_site_transient_timeout_%'
                AND option_name NOT IN (
                    SELECT REPLACE(option_name, '_site_transient_timeout_', '_site_transient_')
                    FROM {$wpdb->options} 
                    WHERE option_name LIKE '_site_transient_timeout_%'
                )
            ");
            
            // Clear specific problematic transients that are causing slow queries
            $problem_transients = array(
                '_transient_wp_rocket_pricing',
                '_transient_wp_rocket_customer_data',
                '_transient_woocommerce_marketplace_promotions_v2',
                '_transient_wc_block_product_filter_attribute_default_attribute',
                '_transient_as-post-store-dependencies-met',
                '_site_transient_fs_garbage_collection'
            );
            
            foreach ($problem_transients as $transient) {
                delete_transient(str_replace('_transient_', '', $transient));
                delete_site_transient(str_replace('_site_transient_', '', $transient));
                $cleaned += 2;
            }
            
            // Optimize wp_options table
            $wpdb->query("OPTIMIZE TABLE {$wpdb->options}");
            
            // Clear object cache
            if (function_exists('wp_cache_flush')) {
                wp_cache_flush();
            }
            
            wp_send_json_success(array(
                'message' => "Cleaned $cleaned database entries. Your site should be faster now!"
            ));
            
        } catch (Exception $e) {
            wp_send_json_error(array('message' => 'Cleanup failed: ' . $e->getMessage()));
        }
    }

    public function admin_page() {
        ?>
        <div class="wrap">
            <h1>WooCommerce Bulk Price Editor (Fixed)</h1>

            <div class="filter-section">
                <h2>Filter Products</h2>
                <table class="form-table">
                    <tr>
                        <th><label for="search_word">Search Word/Name</label></th>
                        <td>
                            <input type="text" id="search_word" name="search_word" placeholder="Enter product name" class="regular-text" />
                            <p class="description">Search for products containing this word</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="button" id="test-ajax" class="button-secondary" value="Test AJAX" />
                    <input type="button" id="filter-products" class="button-primary" value="Filter Products" />
                    <input type="button" id="cleanup-database" class="button-secondary" value="Speed Up Database" style="background: #d63638; border-color: #d63638; color: white;" />
                </p>
            </div>

            <div class="results-section" id="results-section" style="display: none;">
                <h2>Products</h2>
                <div id="product-results"></div>

                <div class="price-update-section">
                    <h3>Update Prices</h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="regular_price_action">Regular Price</label></th>
                            <td>
                                <select id="regular_price_action">
                                    <option value="none">Do Not Change</option>
                                    <option value="set">Set Fixed Price</option>
                                    <option value="increase_percent">Increase by %</option>
                                    <option value="decrease_percent">Decrease by %</option>
                                </select>
                            </td>
                        </tr>
                        <tr class="regular-price-value-row">
                            <th><label for="regular_price_value">Value</label></th>
                            <td><input type="number" id="regular_price_value" step="0.01" min="0" placeholder="Enter price or percentage" /></td>
                        </tr>
                        <tr>
                            <th><label for="sale_price_action">Sale Price</label></th>
                            <td>
                                <select id="sale_price_action">
                                    <option value="none">Do Not Change</option>
                                    <option value="set">Set Fixed Price</option>
                                    <option value="clear_sale">Clear Sale Price</option>
                                </select>
                            </td>
                        </tr>
                        <tr class="sale-price-value-row">
                            <th><label for="sale_price_value">Value</label></th>
                            <td><input type="number" id="sale_price_value" step="0.01" min="0" placeholder="Enter sale price" /></td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="button" id="update-prices" class="button-primary" value="Update Prices" />
                    </p>
                    
                    <div id="update-results" style="display: none;">
                        <h4>Results</h4>
                        <div id="update-message"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}

// Initialize the plugin
new WC_Bulk_Price_Editor();
?>