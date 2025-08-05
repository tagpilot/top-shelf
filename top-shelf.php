<?php
/**
 * Plugin Name: Top Shelf
 * Plugin URI: https://wordpress.org/plugins/top-shelf/
 * Description: Adds a quick search overlay with fuzzy search for WooCommerce products
 * Version: 1.0.1
 * Author: tagconcierge
 * Author URI: https://profiles.wordpress.org/tagconcierge/
 * License: GPLv2 or later
 * License URI: http://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: top-shelf
 * Domain Path: /languages
 * Requires at least: 5.1.0
 * Tested up to: 6.6.2
 * Requires PHP: 7.0
 * WC requires at least: 3.0
 * WC tested up to: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TopShelfSearch {
    public function __construct() {
        // Check if WooCommerce is active
        if (!$this->is_woocommerce_active()) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }

        // Register scripts and styles
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));

        // Add menu item via filter
        add_filter('wp_nav_menu_items', array($this, 'add_search_icon_to_menu'), 10, 2);
        add_filter('wp_nav_menu_primary_items', array($this, 'add_search_icon_to_menu'), 10, 2);

        // Register REST API endpoint
        add_action('rest_api_init', array($this, 'register_search_endpoint'));

        // Add overlay HTML to footer
        add_action('wp_footer', array($this, 'add_search_overlay'));

        // Load text domain for translations
        add_action('init', array($this, 'load_textdomain'));
    }

    /**
     * Check if WooCommerce is active
     */
    private function is_woocommerce_active() {
        return class_exists('WooCommerce');
    }

    /**
     * Display admin notice if WooCommerce is not active
     */
    public function woocommerce_missing_notice() {
        echo '<div class="notice notice-error"><p>';
        printf(
            /* translators: %s: WooCommerce plugin link */
            esc_html__('Top Shelf requires WooCommerce to be installed and active. %s', 'top-shelf'),
            '<a href="' . esc_url(admin_url('plugin-install.php?s=woocommerce&tab=search&type=term')) . '">' . esc_html__('Install WooCommerce', 'top-shelf') . '</a>'
        );
        echo '</p></div>';
    }

    /**
     * Load plugin text domain for translations
     */
    public function load_textdomain() {
        load_plugin_textdomain('top-shelf', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function enqueue_scripts() {
        wp_enqueue_style(
            'top-shelf',
            plugins_url('top-shelf.css', __FILE__),
            array(),
            '1.0.4'
        );

        wp_enqueue_script(
            'fuse-js',
            'https://cdnjs.cloudflare.com/ajax/libs/fuse.js/6.6.2/fuse.min.js',
            array(),
            '6.6.2',
            true
        );

        wp_enqueue_script(
            'top-shelf',
            plugins_url('top-shelf.js', __FILE__),
            array('jquery', 'fuse-js'),
            '1.0.4',
            true
        );

        wp_localize_script('top-shelf', 'topShelf', array(
            'restUrl' => rest_url('top-shelf/v1/search'),
            'nonce' => wp_create_nonce('wp_rest')
        ));
    }

    public function add_search_icon_to_menu($items, $args) {
        // Add only to primary menu
        if ($args->theme_location == 'primary') {
            $search_icon = '<li class="menu-item search-icon">
                <a href="#" class="top-shelf-toggle">
                    <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"></circle>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                    </svg>
                </a>
            </li>';
            $items .= $search_icon;
        }
        return $items;
    }

    public function register_search_endpoint() {
        register_rest_route('top-shelf/v1', '/search', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_search_request'),
            'permission_callback' => '__return_true',
            'args' => array(
                'term' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                    'validate_callback' => function($param) {
                        return !empty($param) && strlen($param) >= 2;
                    }
                )
            )
        ));
    }

    public function handle_search_request($request) {
        $search_term = sanitize_text_field($request->get_param('term'));

        if (empty($search_term)) {
            return new WP_Error('no_term', 'Search term is required', array('status' => 400));
        }

        $args = array(
            'post_type' => 'product',
            'post_status' => 'publish',
            'posts_per_page' => 10,
            's' => $search_term,
            'meta_key' => 'total_sales',
            'orderby' => array(
                'meta_value_num' => 'DESC',
                'modified' => 'DESC'
            ),
            'tax_query' => array(
                array(
                    'taxonomy' => 'product_visibility',
                    'field'    => 'name',
                    'terms'    => 'exclude-from-search',
                    'operator' => 'NOT IN',
                )
            )
        );

        $query = new WP_Query($args);
        $products = array();

        if ($query->have_posts()) {
            while ($query->have_posts()) {
                $query->the_post();
                $product = wc_get_product(get_the_ID());

                if (!$product) continue;

                $stock_status = $product->get_stock_status();
                $is_in_stock = $product->is_in_stock();

                // Get detailed stock information
                $stock_text = __('Out of stock', 'top-shelf');
                if ($is_in_stock) {
                    if ($product->managing_stock()) {
                        $stock_qty = $product->get_stock_quantity();
                        $stock_text = $stock_qty > 0 ? __('In stock', 'top-shelf') : __('Out of stock', 'top-shelf');
                    } else {
                        $stock_text = __('In stock', 'top-shelf');
                    }
                }

                $products[] = array(
                    'id' => get_the_ID(),
                    'title' => get_the_title(),
                    'permalink' => get_permalink(),
                    'thumbnail' => get_the_post_thumbnail_url(get_the_ID(), 'thumbnail'),
                    'price' => $product->get_price_html(),
                    'stock_status' => $stock_text,
                    'is_in_stock' => $is_in_stock
                );
            }
        }

        wp_reset_postdata();
        return rest_ensure_response($products);
    }

    public function add_search_overlay() {
        ?>
        <div class="top-shelf-overlay">
            <div class="top-shelf-container">
                <div class="top-shelf-header">
                    <input type="text" class="top-shelf-input" placeholder="<?php esc_attr_e('Search...', 'top-shelf'); ?>">
                    <button class="top-shelf-close">×</button>
                </div>
                <div class="top-shelf-results"></div>
            </div>
        </div>
        <?php
    }
}

// Initialize plugin
new TopShelfSearch();