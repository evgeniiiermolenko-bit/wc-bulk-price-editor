<?php
/**
 * Plugin Name: WooCommerce Bulk Price Editor
 * Description: Bulk edit product prices with filtering
 * Version: 1.6.1
 * Author: Evgenii
 * Text Domain: wc-bulk-price-editor
 * Domain Path: /languages
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
        
        // Load translations
        load_plugin_textdomain('wc-bulk-price-editor', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // AJAX actions
        add_action('wp_ajax_wc_bulk_price_test', array($this, 'ajax_test'));
        add_action('wp_ajax_wc_bulk_price_filter', array($this, 'ajax_filter_products'));
        add_action('wp_ajax_wc_bulk_price_load_categories', array($this, 'ajax_load_categories'));
        add_action('wp_ajax_wc_bulk_price_simple_update', array($this, 'ajax_simple_update'));
        add_action('wp_ajax_wc_bulk_price_batch_update', array($this, 'ajax_batch_update'));
        add_action('wp_ajax_wc_bulk_price_cleanup_db', array($this, 'ajax_cleanup_database'));
        
        add_action('admin_init', array($this, 'check_woocommerce'));
    }

    public function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
        }
    }

    public function woocommerce_missing_notice() {
        // Notice temporarily hidden
        // echo '<div class="error"><p><strong>' . esc_html__('WooCommerce Bulk Price Editor', 'wc-bulk-price-editor') . '</strong> ' . esc_html__('requires WooCommerce to be installed and active.', 'wc-bulk-price-editor') . '</p></div>';
    }

    public function add_admin_menu() {
        add_submenu_page(
            'woocommerce',
            __('Bulk Price Editor', 'wc-bulk-price-editor'),
            __('Bulk Price Editor', 'wc-bulk-price-editor'),
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
        wp_enqueue_script('wc-bulk-price-editor', plugin_dir_url(__FILE__) . 'assets/bulk-price-editor.js', array('jquery'), '1.5.1', true);
        wp_enqueue_style('wc-bulk-price-editor', plugin_dir_url(__FILE__) . 'assets/bulk-price-editor.css', array(), '1.5.1');
        
        $nonce = wp_create_nonce('wc_bulk_price_nonce');
        $ajax_url = admin_url('admin-ajax.php');
        
        wp_add_inline_script('wc-bulk-price-editor', "
        var wcBulkPrice = {
            ajaxurl: '$ajax_url',
            nonce: '$nonce'
        };
        ", 'before');

        wp_add_inline_style('wp-admin', '
        .select-all-section { margin: 20px 0 0 0; border: 1px solid #ddd; border-radius: 4px; }
        .product-list { margin: 0; border: 1px solid #ddd; border-top: none; max-height: 400px; overflow-y: auto; }
        .product-item { padding: 10px; border-bottom: 1px solid #eee; display: flex; align-items: center; }
        .product-item:hover { background-color: #f9f9f9; }
        .product-checkbox { margin-right: 10px; }
        .product-info { flex: 1; }
        .product-price { margin-left: auto; font-weight: bold; }
        .price-update-section { margin-top: 20px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd; }
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
        $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
        
        $args = array(
            'post_type' => array('product', 'product_variation'),
            'post_status' => 'publish',
            'posts_per_page' => 150,
        );
        
        // Add search term if provided
        if (!empty($search_word)) {
            $args['s'] = $search_word;
        }
        
        // Add category filter if provided
        if ($category_id > 0) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => $category_id,
                    'include_children' => true
                )
            );
        }
        
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

    public function ajax_load_categories() {
        if (!wp_verify_nonce($_POST['nonce'], 'wc_bulk_price_nonce')) {
            wp_send_json_error(array('message' => 'Nonce failed'));
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        // Get all product categories
        $categories = get_terms(array(
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC'
        ));
        
        if (is_wp_error($categories) || empty($categories)) {
            wp_send_json_error(array('message' => 'No categories found'));
        }
        
        $html = '<option value="">All Categories</option>';
        foreach ($categories as $category) {
            $html .= sprintf(
                '<option value="%d">%s</option>',
                $category->term_id,
                esc_html($category->name)
            );
        }
        
        wp_send_json_success(array('html' => $html));
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
            } elseif ($sale_action === 'decrease_percent' && $sale_value != 0) {
                // Calculate sale price as a percentage decrease from the NEW regular price
                $base_regular = ($regular_action !== 'none' && abs($new_regular - $current_regular) > 0.01) ? $new_regular : $current_regular;
                if ($base_regular > 0) {
                    $new_sale = $base_regular * (1 - ($sale_value / 100));
                    $new_sale = max(0, $new_sale); // Don't go below 0
                    error_log("Setting sale price as {$sale_value}% decrease from regular ({$base_regular}): $new_sale");
                } else {
                    wp_send_json_error(array('message' => 'Cannot calculate sale price by percentage when regular price is 0'));
                }
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

    public function ajax_batch_update() {
        if (!wp_verify_nonce($_POST['nonce'], 'wc_bulk_price_nonce')) {
            wp_send_json_error(array('message' => 'Nonce failed'));
        }
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }
        
        $product_ids = explode(',', sanitize_text_field($_POST['product_ids']));
        $regular_action = sanitize_text_field($_POST['regular_price_action']);
        $regular_value = floatval($_POST['regular_price_value']);
        $sale_action = sanitize_text_field($_POST['sale_price_action']);
        $sale_value = floatval($_POST['sale_price_value']);
        
        if (empty($product_ids)) {
            wp_send_json_error(array('message' => 'No products specified'));
        }
        
        $successful = 0;
        $failed = 0;
        
        foreach ($product_ids as $product_id) {
            $product_id = intval($product_id);
            if (!$product_id) continue;
            
            // Call the update logic for each product
            try {
                global $wpdb;
                
                // Check if this is a variation or simple product
                $post_type = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d",
                    $product_id
                ));
                
                if (!$post_type || !in_array($post_type, array('product', 'product_variation'))) {
                    $failed++;
                    continue;
                }
                
                // Get current prices
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
                
                $new_regular = $current_regular;
                $new_sale = $current_sale;
                $changes_made = false;
                
                // Calculate new regular price
                if ($regular_action === 'set' && $regular_value > 0) {
                    $new_regular = $regular_value;
                } elseif ($regular_action === 'increase_percent' && $regular_value != 0) {
                    if ($current_regular > 0) {
                        $new_regular = $current_regular * (1 + ($regular_value / 100));
                    } else {
                        $failed++;
                        continue;
                    }
                } elseif ($regular_action === 'decrease_percent' && $regular_value != 0) {
                    if ($current_regular > 0) {
                        $new_regular = $current_regular * (1 - ($regular_value / 100));
                        $new_regular = max(0, $new_regular);
                    }
                }
                
                // Calculate new sale price
                if ($sale_action === 'set' && $sale_value > 0) {
                    $new_sale = $sale_value;
                } elseif ($sale_action === 'decrease_percent' && $sale_value != 0) {
                    // Calculate sale price as a percentage decrease from the NEW regular price
                    $base_regular = ($regular_action !== 'none' && abs($new_regular - $current_regular) > 0.01) ? $new_regular : $current_regular;
                    if ($base_regular > 0) {
                        $new_sale = $base_regular * (1 - ($sale_value / 100));
                        $new_sale = max(0, $new_sale);
                    } else {
                        $failed++;
                        continue;
                    }
                } elseif ($sale_action === 'clear_sale') {
                    $new_sale = 0;
                }
                
                // Update regular price if needed
                if ($regular_action !== 'none' && abs($new_regular - $current_regular) > 0.01) {
                    $formatted_price = number_format($new_regular, 2, '.', '');
                    
                    $existing = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_regular_price'",
                        $product_id
                    ));
                    
                    if ($existing) {
                        $wpdb->update(
                            $wpdb->postmeta,
                            array('meta_value' => $formatted_price),
                            array('post_id' => $product_id, 'meta_key' => '_regular_price'),
                            array('%s'),
                            array('%d', '%s')
                        );
                    } else {
                        $wpdb->insert(
                            $wpdb->postmeta,
                            array(
                                'post_id' => $product_id,
                                'meta_key' => '_regular_price',
                                'meta_value' => $formatted_price
                            ),
                            array('%d', '%s', '%s')
                        );
                    }
                    $changes_made = true;
                }
                
                // Update sale price if needed
                if ($sale_action === 'clear_sale' && $current_sale > 0) {
                    $wpdb->delete(
                        $wpdb->postmeta,
                        array('post_id' => $product_id, 'meta_key' => '_sale_price'),
                        array('%d', '%s')
                    );
                    $changes_made = true;
                    
                } elseif ($sale_action !== 'none' && $sale_action !== 'clear_sale' && abs($new_sale - $current_sale) > 0.01) {
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
                    $changes_made = true;
                }
                
                // Update display price if changes were made
                if ($changes_made) {
                    $final_regular = ($regular_action !== 'none' && abs($new_regular - $current_regular) > 0.01) ? $new_regular : $current_regular;
                    $final_sale = ($sale_action === 'clear_sale') ? 0 : (($sale_action !== 'none' && abs($new_sale - $current_sale) > 0.01) ? $new_sale : $current_sale);
                    
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
                    
                    // Update parent if this is a variation
                    if ($post_type === 'product_variation') {
                        $parent_id = $wpdb->get_var($wpdb->prepare(
                            "SELECT post_parent FROM {$wpdb->posts} WHERE ID = %d",
                            $product_id
                        ));
                        
                        if ($parent_id) {
                            wp_cache_delete($parent_id, 'post_meta');
                            wc_delete_product_transients($parent_id);
                            
                            $parent_product = wc_get_product($parent_id);
                            if ($parent_product && $parent_product->get_type() === 'variable') {
                                $parent_product->sync($parent_id);
                            }
                        }
                    }
                    
                    wp_cache_delete($product_id, 'post_meta');
                    wc_delete_product_transients($product_id);
                    
                    $successful++;
                } else {
                    // No changes needed, still count as successful
                    $successful++;
                }
                
            } catch (Exception $e) {
                error_log("ERROR in batch update for product $product_id: " . $e->getMessage());
                $failed++;
            }
        }
        
        if ($successful > 0) {
            wp_send_json_success(array(
                'message' => "Batch processed: $successful successful, $failed failed"
            ));
        } else {
            wp_send_json_error(array(
                'message' => "Batch failed: $failed products failed"
            ));
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
            <h1><?php esc_html_e('WooCommerce Bulk Price Editor', 'wc-bulk-price-editor'); ?></h1>

            <div class="filter-section">
                <h2><?php esc_html_e('Filter Products', 'wc-bulk-price-editor'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><label for="search_word"><?php esc_html_e('Search Word/Name', 'wc-bulk-price-editor'); ?></label></th>
                        <td>
                            <input type="text" id="search_word" name="search_word" placeholder="<?php esc_attr_e('Enter product name', 'wc-bulk-price-editor'); ?>" class="regular-text" />
                            <p class="description"><?php esc_html_e('Search for products containing this word', 'wc-bulk-price-editor'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="category_id"><?php esc_html_e('Product Category', 'wc-bulk-price-editor'); ?></label></th>
                        <td>
                            <select id="category_id" name="category_id" class="regular-text">
                                <option value=""><?php esc_html_e('All Categories', 'wc-bulk-price-editor'); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e('Filter by product category', 'wc-bulk-price-editor'); ?></p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="button" id="test-ajax" class="button-secondary" value="<?php esc_attr_e('Test AJAX', 'wc-bulk-price-editor'); ?>" />
                    <input type="button" id="filter-products" class="button-primary" value="<?php esc_attr_e('Filter Products', 'wc-bulk-price-editor'); ?>" />
                    <input type="button" id="cleanup-database" class="button-secondary" value="<?php esc_attr_e('Speed Up Database', 'wc-bulk-price-editor'); ?>" style="background: #d63638; border-color: #d63638; color: white;" />
                </p>
            </div>

            <div class="results-section" id="results-section" style="display: none;">
                <h2><?php esc_html_e('Products', 'wc-bulk-price-editor'); ?></h2>
                <div id="product-results"></div>

                <div class="price-update-section">
                    <h3><?php esc_html_e('Update Prices', 'wc-bulk-price-editor'); ?></h3>
                    <table class="form-table">
                        <tr>
                            <th><label for="regular_price_action"><?php esc_html_e('Regular Price', 'wc-bulk-price-editor'); ?></label></th>
                            <td>
                                <select id="regular_price_action">
                                    <option value="none"><?php esc_html_e('Do Not Change', 'wc-bulk-price-editor'); ?></option>
                                    <option value="set"><?php esc_html_e('Set Fixed Price', 'wc-bulk-price-editor'); ?></option>
                                    <option value="increase_percent"><?php esc_html_e('Increase by %', 'wc-bulk-price-editor'); ?></option>
                                    <option value="decrease_percent"><?php esc_html_e('Decrease by %', 'wc-bulk-price-editor'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr class="regular-price-value-row">
                            <th><label for="regular_price_value"><?php esc_html_e('Value', 'wc-bulk-price-editor'); ?></label></th>
                            <td><input type="number" id="regular_price_value" step="0.01" min="0" placeholder="<?php esc_attr_e('Enter price or percentage', 'wc-bulk-price-editor'); ?>" /></td>
                        </tr>
                        <tr>
                            <th><label for="sale_price_action"><?php esc_html_e('Sale Price', 'wc-bulk-price-editor'); ?></label></th>
                            <td>
                                <select id="sale_price_action">
                                    <option value="none"><?php esc_html_e('Do Not Change', 'wc-bulk-price-editor'); ?></option>
                                    <option value="set"><?php esc_html_e('Set Fixed Price', 'wc-bulk-price-editor'); ?></option>
                                    <option value="decrease_percent"><?php esc_html_e('Decrease by % (from Regular)', 'wc-bulk-price-editor'); ?></option>
                                    <option value="clear_sale"><?php esc_html_e('Clear Sale Price', 'wc-bulk-price-editor'); ?></option>
                                </select>
                            </td>
                        </tr>
                        <tr class="sale-price-value-row">
                            <th><label for="sale_price_value"><?php esc_html_e('Value', 'wc-bulk-price-editor'); ?></label></th>
                            <td><input type="number" id="sale_price_value" step="0.01" min="0" placeholder="<?php esc_attr_e('Enter sale price', 'wc-bulk-price-editor'); ?>" /></td>
                        </tr>
                    </table>
                    
                    <p class="submit">
                        <input type="button" id="update-prices" class="button-primary" value="<?php esc_attr_e('Update Prices', 'wc-bulk-price-editor'); ?>" />
                    </p>
                    
                    <div id="update-results" style="display: none;">
                        <h4><?php esc_html_e('Results', 'wc-bulk-price-editor'); ?></h4>
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