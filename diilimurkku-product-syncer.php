<?php
/**
 * Plugin Name: Diilimurkku Product Sync for WooCommerce
 * Description: Seamlessly integrates your WooCommerce store with Diilimurkku's platform to ensure your product data stays up-to-date.
 * Version: 1.0.1
 * Author: Diilimurkku
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

class PriceComparisonExporter {
    // Plugin variables
    private $option_name = 'price_comparison_settings';
    private $api_key = '';
    private $api_endpoint = 'https://cron-stg.diilimurkku.fi/tasks/sync-from-woocommerce';
    
    public function __construct() {
        // Register necessary hooks
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Load settings
        $this->load_settings();
        
        // Add settings menu
        add_action('admin_menu', array($this, 'add_admin_menu'));
        
        // Register settings
        add_action('admin_init', array($this, 'register_settings'));
        
        // Add cron jobs
        add_action('price_comparison_export_event', array($this, 'export_and_send_products'));
        
        // Add admin notices
        add_action('admin_notices', array($this, 'admin_notices'));
    }
    
    /**
     * Plugin activation
     */
    public function activate() {
        wp_clear_scheduled_hook('price_comparison_export_event');
        
        wp_schedule_event(strtotime('today 02:00:00'), 'daily', 'price_comparison_export_event');
        
        // Default settings
        $default_settings = array(
            'api_key' => ''
        );
        
        add_option($this->option_name, $default_settings);
    }
    
    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled event
        wp_clear_scheduled_hook('price_comparison_export_event');
    }
    
    /**
     * Load settings from database
     */
    private function load_settings() {
        $settings = get_option($this->option_name, array());
        if (is_array($settings) && isset($settings['api_key'])) {
            $this->api_key = $settings['api_key'];
        } else {
            $this->api_key = '';
        }
    }
    
    /**
     * Add menu to admin panel
     */
    public function add_admin_menu() {
        add_menu_page(
            'Diilimurkku Sync Settings',
            'Diilimurkku Sync',
            'manage_options',
            'price-comparison-exporter',
            array($this, 'settings_page'),
            plugins_url('assets/icon.png', __FILE__),
            99
        );
        
        add_action('admin_head', array($this, 'admin_menu_icon_style'));
    }
    
    public function admin_menu_icon_style() {
        echo '<style>
            #adminmenu .toplevel_page_price-comparison-exporter img {
                width: 20px;
                height: 20px;
                padding-top: 7px; 
            }
        </style>';
    }
    
    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'price_comparison_exporter',
            $this->option_name,
            array($this, 'sanitize_settings')
        );
        
        add_settings_section(
            'price_comparison_exporter_section',
            'Diilimurkku Sync Settings',
            array($this, 'settings_section_callback'),
            'price-comparison-exporter'
        );
        
        add_settings_field(
            'api_key',
            'API Key',
            array($this, 'api_key_render'),
            'price-comparison-exporter',
            'price_comparison_exporter_section'
        );
    }
    
    /**
     * Sanitize settings
     */
    public function sanitize_settings($input) {
        $new_input = array();
        
        if (isset($input['api_key'])) {
            $new_input['api_key'] = sanitize_text_field($input['api_key']);
            
            // Set transient for success message if API key is provided and not empty
            if (!empty(trim($new_input['api_key']))) {
                set_transient('diilimurkku_settings_saved', true, 5);
            }
        } else {
            $new_input['api_key'] = '';
        }
        
        return $new_input;
    }
    
    /**
     * Settings section description
     */
    public function settings_section_callback() {
        echo '<p>Enter the API key required to synchronize your products with Diilimurkku platform.</p>';
    }
    
    /**
     * API Key field
     */
    public function api_key_render() {
        $settings = get_option($this->option_name);
        $api_key_value = '';
        if (is_array($settings) && isset($settings['api_key'])) {
            $api_key_value = $settings['api_key'];
        }
        ?>
        <input type="text" name="<?php echo esc_attr($this->option_name); ?>[api_key]" value="<?php echo esc_attr($api_key_value); ?>" class="regular-text">
        <p class="description">Enter the API Key provided by Diilimurkku service.</p>
        <?php
    }
    
    /**
     * Admin notices
     */
    public function admin_notices() {
        if (get_transient('diilimurkku_settings_saved')) {
            echo '<div class="notice notice-success is-dismissible">
                <p><strong>Settings saved successfully!</strong> Your products will be automatically synchronized with Diilimurkku daily. Please check your Diilimurkku dashboard tomorrow to verify that your products have been successfully integrated into the platform.</p>
            </div>';
            delete_transient('diilimurkku_settings_saved');
        }
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        ?>
        <div class="wrap">
            <h1>Diilimurkku Sync Settings</h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('price_comparison_exporter');
                do_settings_sections('price-comparison-exporter');
                submit_button();
                ?>
                
                <hr>
                
                <h2>Synchronization Information</h2>
                <p>Your products are automatically synchronized with Diilimurkku every day at 2:00 AM. The system will export all published products from your WooCommerce store and send them to the Diilimurkku platform.</p>
                <p><strong>Note:</strong> Make sure to configure your API key above to enable automatic synchronization.</p>
            </form>
        </div>
        <?php
    }
    
    /**
     * Export and send products
     */
    public function export_and_send_products() {
        // Check for API Key
        if (empty($this->api_key)) {
            error_log('No API Key provided for product export.');
            return false;
        }
        
        // Get all products
        $products_data = $this->get_all_products();
        
        // Convert to JSON
        $json_data = json_encode($products_data);
        
        // Create temporary file
        $temp_file = get_temp_dir() . 'wc_products_export_' . time() . '.json';
        file_put_contents($temp_file, $json_data);
        
        // Compress file
        $zip_file = get_temp_dir() . 'wc_products_export_' . time() . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zip_file, ZipArchive::CREATE) === TRUE) {
            $zip->addFile($temp_file, 'products.json');
            $zip->close();
            
            // Send file to API
            $result = $this->send_to_api($zip_file);
            
            // Delete temporary files
            unlink($temp_file);
            unlink($zip_file);
            
            return $result;
        } else {
            error_log('Error compressing product file.');
            unlink($temp_file);
            return false;
        }
    }
    
    /**
     * Get all products from WooCommerce
     */
    private function get_all_products() {
        $products = [];
        
        // Ensure WooCommerce exists
        if (!function_exists('WC')) {
            return $products;
        }
        
        // Get all products
        $args = [
            'status' => 'publish',
            'limit' => -1,
        ];
        
        $all_products = wc_get_products($args);
        
        foreach ($all_products as $product) {
            // Basic product information
            $product_data = [
                'id' => $product->get_id(),
                'name' => $product->get_name(),
                'slug' => $product->get_slug(),
                'permalink' => get_permalink($product->get_id()),
                'date_created' => $product->get_date_created() ? $product->get_date_created()->format('Y-m-d H:i:s') : null,
                'date_modified' => $product->get_date_modified() ? $product->get_date_modified()->format('Y-m-d H:i:s') : null,
                'type' => $product->get_type(),
                'status' => $product->get_status(),
                'featured' => $product->get_featured(),
                'catalog_visibility' => $product->get_catalog_visibility(),
                'description' => $product->get_description(),
                'short_description' => $product->get_short_description(),
                'sku' => $product->get_sku(),
                'price' => $product->get_price(),
                'regular_price' => $product->get_regular_price(),
                'sale_price' => $product->get_sale_price(),
                'date_on_sale_from' => $product->get_date_on_sale_from() ? $product->get_date_on_sale_from()->format('Y-m-d H:i:s') : null,
                'date_on_sale_to' => $product->get_date_on_sale_to() ? $product->get_date_on_sale_to()->format('Y-m-d H:i:s') : null,
                'total_sales' => $product->get_total_sales(),
                'tax_status' => $product->get_tax_status(),
                'tax_class' => $product->get_tax_class(),
                'manage_stock' => $product->get_manage_stock(),
                'stock_quantity' => $product->get_stock_quantity(),
                'stock_status' => $product->get_stock_status(),
                'backorders' => $product->get_backorders(),
                'sold_individually' => $product->get_sold_individually(),
                'weight' => $product->get_weight(),
                'dimensions' => [
                    'length' => $product->get_length(),
                    'width' => $product->get_width(),
                    'height' => $product->get_height(),
                ],
                'shipping_class' => $product->get_shipping_class(),
                'shipping_class_id' => $product->get_shipping_class_id(),
                'reviews_allowed' => $product->get_reviews_allowed(),
                'average_rating' => $product->get_average_rating(),
                'rating_count' => $product->get_rating_count(),
                'related_ids' => wc_get_related_products($product->get_id()),
                'upsell_ids' => $product->get_upsell_ids(),
                'cross_sell_ids' => $product->get_cross_sell_ids(),
                'parent_id' => $product->get_parent_id(),
                'categories' => $this->get_product_categories($product),
                'tags' => $this->get_product_tags($product),
                'attributes' => $this->get_product_attributes($product),
                'default_attributes' => $product->get_default_attributes(),
                'variations' => [],
                'menu_order' => $product->get_menu_order(),
                'virtual' => $product->get_virtual(),
                'downloadable' => $product->get_downloadable(),
                'purchase_note' => $product->get_purchase_note(),
                'images' => $this->get_product_images($product),
            ];
            
            // If the product is variable, add variations
            if ($product->is_type('variable')) {
                $variations = $product->get_available_variations();
                
                foreach ($variations as $variation_data) {
                    $variation_id = $variation_data['variation_id'];
                    $variation = wc_get_product($variation_id);
                    
                    $product_data['variations'][] = [
                        'id' => $variation->get_id(),
                        'attributes' => $variation->get_variation_attributes(),
                        'price' => $variation->get_price(),
                        'regular_price' => $variation->get_regular_price(),
                        'sale_price' => $variation->get_sale_price(),
                        'date_on_sale_from' => $variation->get_date_on_sale_from() ? $variation->get_date_on_sale_from()->format('Y-m-d H:i:s') : null,
                        'date_on_sale_to' => $variation->get_date_on_sale_to() ? $variation->get_date_on_sale_to()->format('Y-m-d H:i:s') : null,
                        'stock_quantity' => $variation->get_stock_quantity(),
                        'stock_status' => $variation->get_stock_status(),
                        'weight' => $variation->get_weight(),
                        'dimensions' => [
                            'length' => $variation->get_length(),
                            'width' => $variation->get_width(),
                            'height' => $variation->get_height(),
                        ],
                        'image' => $this->get_product_images($variation),
                        'sku' => $variation->get_sku(),
                    ];
                }
            }
            
            // Add to products array
            $products[] = $product_data;
        }
        
        return [
            'store_info' => [
                'name' => get_bloginfo('name'),
                'url' => get_site_url(),
                'export_date' => current_time('mysql'),
                'products_count' => count($products),
            ],
            'products' => $products
        ];
    }
    
    /**
     * Get product categories
     */
    private function get_product_categories($product) {
        $categories = [];
        $terms = get_the_terms($product->get_id(), 'product_cat');
        
        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $categories[] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'permalink' => get_term_link($term),
                ];
            }
        }
        
        return $categories;
    }
    
    /**
     * Get product tags
     */
    private function get_product_tags($product) {
        $tags = [];
        $terms = get_the_terms($product->get_id(), 'product_tag');
        
        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $tags[] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'permalink' => get_term_link($term),
                ];
            }
        }
        
        return $tags;
    }
    
    /**
     * Get product attributes
     */
    private function get_product_attributes($product) {
        $attributes = [];
        $product_attributes = $product->get_attributes();
        
        if (!empty($product_attributes)) {
            foreach ($product_attributes as $attribute_name => $attribute) {
                $attribute_data = [
                    'name' => wc_attribute_label($attribute_name),
                    'position' => $attribute->get_position(),
                    'visible' => $attribute->get_visible(),
                    'variation' => $attribute->get_variation(),
                    'options' => [],
                ];
                
                if ($attribute->is_taxonomy()) {
                    $attribute_taxonomy = $attribute->get_taxonomy_object();
                    $attribute_values = wc_get_product_terms($product->get_id(), $attribute->get_name(), ['fields' => 'all']);
                    
                    foreach ($attribute_values as $attribute_value) {
                        $attribute_data['options'][] = [
                            'id' => $attribute_value->term_id,
                            'name' => $attribute_value->name,
                            'slug' => $attribute_value->slug,
                        ];
                    }
                } else {
                    $attribute_data['options'] = $attribute->get_options();
                }
                
                $attributes[$attribute_name] = $attribute_data;
            }
        }
        
        return $attributes;
    }
    
    /**
     * Get product images
     */
    private function get_product_images($product) {
        $images = [];
        
        // Main image
        $image_id = $product->get_image_id();
        if ($image_id) {
            $image_url = wp_get_attachment_url($image_id);
            $images[] = [
                'id' => $image_id,
                'src' => $image_url,
                'alt' => get_post_meta($image_id, '_wp_attachment_image_alt', true),
                'position' => 0,
            ];
        }
        
        // Gallery images
        $gallery_image_ids = $product->get_gallery_image_ids();
        if (!empty($gallery_image_ids)) {
            $position = 1;
            foreach ($gallery_image_ids as $gallery_image_id) {
                $image_url = wp_get_attachment_url($gallery_image_id);
                $images[] = [
                    'id' => $gallery_image_id,
                    'src' => $image_url,
                    'alt' => get_post_meta($gallery_image_id, '_wp_attachment_image_alt', true),
                    'position' => $position,
                ];
                $position++;
            }
        }
        
        return $images;
    }
    
    /**
     * Send file to API
     */
    private function send_to_api($zip_file) {
        if (!file_exists($zip_file)) {
            error_log('ZIP file for upload does not exist.');
            return false;
        }

        error_log('Attempting to send file to API endpoint: ' . $this->api_endpoint);

        $ch = curl_init();
        $data = [
            'file' => new CURLFile($zip_file, 'application/zip', basename($zip_file)),
        ];

        curl_setopt($ch, CURLOPT_URL, $this->api_endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-Key: ' . $this->api_key,
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error) {
            error_log('cURL error: ' . $error);
            return false;
        }

        if ($http_code >= 200 && $http_code < 300) {
            error_log('Products successfully sent. Response: ' . $response);
            return true;
        } else {
            error_log("Error sending products. Code: $http_code, Response: $response");
            return false;
        }
    }
}

// Initialize plugin
$price_comparison_exporter = new PriceComparisonExporter();