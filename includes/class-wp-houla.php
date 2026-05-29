<?php
/**
 * The core plugin class.
 *
 * Loads dependencies, sets locale, registers admin hooks and WooCommerce hooks.
 *
 * @since      1.0.0
 * @package    Wp_Houla
 * @subpackage Wp_Houla/includes
 */

class Wp_Houla {

    /** @var Wp_Houla_Loader */
    protected $loader;

    /** @var string */
    protected $plugin_name;

    /** @var string */
    protected $version;

    public function __construct() {
        $this->version     = defined( 'WPHOULA_VERSION' ) ? WPHOULA_VERSION : '1.0.0';
        $this->plugin_name = 'wp-houla';

        $this->load_dependencies();
        $this->set_locale();
        $this->define_admin_hooks();
        $this->define_shortlink_hooks();
        $this->define_rest_routes();

        // WooCommerce features only when WooCommerce is active
        if ( wphoula_is_woocommerce_active() ) {
            $this->define_woocommerce_hooks();
        }

        // Cron sync — always register the hook (even if WooCommerce is temporarily disabled)
        $this->define_cron_hooks();
    }

    // =====================================================================
    // Dependencies
    // =====================================================================

    private function load_dependencies() {
        $base = plugin_dir_path( dirname( __FILE__ ) ) . 'includes/';

        require_once $base . 'class-wp-houla-loader.php';
        require_once $base . 'class-wp-houla-i18n.php';
        require_once $base . 'class-wp-houla-options.php';
        require_once $base . 'class-wp-houla-auth.php';
        require_once $base . 'class-wp-houla-api.php';
        require_once $base . 'class-wp-houla-sync.php';
        require_once $base . 'class-wp-houla-orders.php';
        require_once $base . 'class-wp-houla-webhook.php';
        require_once $base . 'class-wp-houla-metabox.php';
        require_once $base . 'class-wp-houla-order-metabox.php';
        require_once $base . 'class-wp-houla-shortlink.php';
        require_once $base . 'class-wp-houla-post-metabox.php';
        require_once $base . 'class-wp-houla-activator.php';
        require_once $base . 'class-wp-houla-deactivator.php';

        require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-wp-houla-admin.php';

        $this->loader = new Wp_Houla_Loader();
    }

    // =====================================================================
    // Locale
    // =====================================================================

    private function set_locale() {
        $i18n = new Wp_Houla_i18n();
        $this->loader->add_action( 'plugins_loaded', $i18n, 'load_plugin_textdomain' );
    }

    // =====================================================================
    // Admin hooks
    // =====================================================================

    private function define_admin_hooks() {
        $admin = new Wp_Houla_Admin();

        // Styles & scripts
        $this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_styles' );
        $this->loader->add_action( 'admin_enqueue_scripts', $admin, 'enqueue_scripts' );

        // Menu page
        $this->loader->add_action( 'admin_menu', $admin, 'add_menu_page' );

        // Settings link on plugins page
        $this->loader->add_filter(
            'plugin_action_links_' . WPHOULA_BASENAME,
            $admin,
            'add_action_links'
        );

        // Settings notice when not connected
        $this->loader->add_action( 'admin_notices', $admin, 'display_settings_notice' );

        // Dashboard widget
        $this->loader->add_action( 'wp_dashboard_setup', $admin, 'register_dashboard_widget' );
        $this->loader->add_action( 'wp_ajax_wphoula_dashboard_stats', $admin, 'ajax_dashboard_stats' );

        // Posts list column (for all public post types)
        $this->loader->add_filter( 'manage_posts_columns', $admin, 'add_posts_column' );
        $this->loader->add_action( 'manage_posts_custom_column', $admin, 'render_posts_column', 10, 2 );
        $this->loader->add_filter( 'manage_edit-post_sortable_columns', $admin, 'sortable_posts_column' );
        $this->loader->add_filter( 'manage_pages_columns', $admin, 'add_posts_column' );
        $this->loader->add_action( 'manage_pages_custom_column', $admin, 'render_posts_column', 10, 2 );

        // Admin AJAX actions
        $this->loader->add_action( 'wp_ajax_wphoula_disconnect', $admin, 'ajax_disconnect' );
        $this->loader->add_action( 'wp_ajax_wphoula_save_settings', $admin, 'ajax_save_settings' );
        $this->loader->add_action( 'wp_ajax_wphoula_get_workspaces', $admin, 'ajax_get_workspaces' );
        $this->loader->add_action( 'wp_ajax_wphoula_switch_workspace', $admin, 'ajax_switch_workspace' );

        // WooCommerce-specific admin features
        if ( wphoula_is_woocommerce_active() ) {
            $metabox = new Wp_Houla_Metabox();

            $this->loader->add_action( 'wp_ajax_wphoula_batch_sync', $admin, 'ajax_batch_sync' );
            $this->loader->add_action( 'wp_ajax_wphoula_batch_sync_page', $admin, 'ajax_batch_sync_page' );
            $this->loader->add_action( 'wp_ajax_wphoula_batch_sync_count', $admin, 'ajax_batch_sync_count' );
            $this->loader->add_action( 'wp_ajax_wphoula_start_background_sync', $admin, 'ajax_start_background_sync' );
            $this->loader->add_action( 'wp_ajax_wphoula_bg_sync_process', $admin, 'ajax_bg_sync_process' );
            $this->loader->add_action( 'wp_ajax_wphoula_get_sync_status', $admin, 'ajax_get_sync_status' );
            $this->loader->add_action( 'wp_ajax_wphoula_cancel_sync', $admin, 'ajax_cancel_sync' );
            $this->loader->add_action( 'wp_ajax_nopriv_wphoula_bg_sync_process', $admin, 'ajax_bg_sync_process' );
            $this->loader->add_action( 'wp_ajax_wphoula_get_synced_products', $admin, 'ajax_get_synced_products' );
            $this->loader->add_action( 'wp_ajax_wphoula_get_collections', $admin, 'ajax_get_collections' );
            $this->loader->add_action( 'wp_ajax_wphoula_auto_map_collections', $admin, 'ajax_auto_map_collections' );
            $this->loader->add_action( 'wp_ajax_wphoula_get_shop_status', $admin, 'ajax_get_shop_status' );
            $this->loader->add_action( 'wp_ajax_wphoula_reset_sync', $admin, 'ajax_reset_sync' );
            $this->loader->add_action( 'wp_ajax_wphoula_get_product_meta_keys', $admin, 'ajax_get_product_meta_keys' );

            // Order resync AJAX actions
            $this->loader->add_action( 'wp_ajax_wphoula_resync_order', $admin, 'ajax_resync_order' );
            $this->loader->add_action( 'wp_ajax_wphoula_batch_resync_orders', $admin, 'ajax_batch_resync_orders' );
            $this->loader->add_action( 'wp_ajax_wphoula_order_sync_counts', $admin, 'ajax_order_sync_counts' );
            $this->loader->add_action( 'wp_ajax_wphoula_pull_orders_from_houla', $admin, 'ajax_pull_orders_from_houla' );

            // WooCommerce orders list column (sync status)
            // HPOS (High-Performance Order Storage) compatible
            $this->loader->add_filter( 'manage_woocommerce_page_wc-orders_columns', $admin, 'add_orders_column' );
            $this->loader->add_action( 'manage_woocommerce_page_wc-orders_custom_column', $admin, 'render_orders_column', 10, 2 );
            // Legacy post-based orders
            $this->loader->add_filter( 'manage_edit-shop_order_columns', $admin, 'add_orders_column' );
            $this->loader->add_action( 'manage_shop_order_posts_custom_column', $admin, 'render_orders_column', 10, 2 );

            // Metabox on WooCommerce products
            $this->loader->add_action( 'add_meta_boxes', $metabox, 'register_metabox' );

            // Order shipping metabox
            $order_metabox = new Wp_Houla_Order_Metabox();
            $this->loader->add_action( 'add_meta_boxes', $order_metabox, 'register_metabox' );
            $this->loader->add_action( 'wp_ajax_wphoula_generate_label', $order_metabox, 'ajax_generate_label' );
            $this->loader->add_action( 'wp_ajax_wphoula_get_label_url', $order_metabox, 'ajax_get_label_url' );
            $this->loader->add_action( 'wp_ajax_wphoula_download_label', $order_metabox, 'ajax_download_label' );
            $this->loader->add_action( 'wp_ajax_wphoula_cancel_label', $order_metabox, 'ajax_cancel_label' );
            $this->loader->add_action( 'wp_ajax_wphoula_print_label', $order_metabox, 'ajax_print_label' );
            $this->loader->add_action( 'wp_ajax_wphoula_check_label_status', $order_metabox, 'ajax_check_label_status' );

            // Metabox AJAX actions
            $this->loader->add_action( 'wp_ajax_wphoula_sync_product', $metabox, 'ajax_sync_product' );
            $this->loader->add_action( 'wp_ajax_wphoula_unsync_product', $metabox, 'ajax_unsync_product' );
            $this->loader->add_action( 'wp_ajax_wphoula_get_stats', $metabox, 'ajax_get_stats' );
        }

        // Post metabox (shortlink + QR code on all post types)
        $post_metabox = new Wp_Houla_Post_Metabox();
        $this->loader->add_action( 'add_meta_boxes', $post_metabox, 'register_metabox' );
        $this->loader->add_action( 'wp_ajax_wphoula_generate_shortlink', $post_metabox, 'ajax_generate_shortlink' );
        $this->loader->add_action( 'wp_ajax_wphoula_get_link_stats', $post_metabox, 'ajax_get_link_stats' );
    }

    // =====================================================================
    // Shortlink hooks (all post types)
    // =====================================================================

    private function define_shortlink_hooks() {
        $shortlink = new Wp_Houla_Shortlink();

        // Generate shortlink on publish
        $this->loader->add_action( 'save_post', $shortlink, 'on_save_post', 20, 3 );

        // Gutenberg/Block Editor: catch status transitions (more reliable than save_post)
        $this->loader->add_action( 'transition_post_status', $shortlink, 'on_transition_post_status', 20, 3 );

        // WooCommerce products: dedicated hooks (save_post not always reliable for products)
        if ( wphoula_is_woocommerce_active() ) {
            $this->loader->add_action( 'woocommerce_new_product', $shortlink, 'on_woocommerce_product_save', 30, 2 );
            $this->loader->add_action( 'woocommerce_update_product', $shortlink, 'on_woocommerce_product_save', 30, 2 );
        }

        // Override WordPress get_shortlink() with Hou.la link
        $this->loader->add_filter( 'pre_get_shortlink', $shortlink, 'filter_get_shortlink', 10, 4 );

        // Register [wphoula] shortcode
        $this->loader->add_action( 'init', $shortlink, 'register_shortcode' );
    }

    // =====================================================================
    // WooCommerce product sync hooks
    // =====================================================================

    private function define_woocommerce_hooks() {
        $sync = new Wp_Houla_Sync();

        // Register custom WooCommerce order statuses
        $this->loader->add_action( 'init', $sync, 'register_custom_order_statuses' );
        $this->loader->add_filter( 'wc_order_statuses', $sync, 'add_custom_order_statuses' );

        // Product lifecycle
        $this->loader->add_action( 'woocommerce_new_product', $sync, 'on_product_created', 20, 2 );
        $this->loader->add_action( 'woocommerce_update_product', $sync, 'on_product_updated', 20, 2 );
        $this->loader->add_action( 'woocommerce_before_delete_product', $sync, 'on_product_deleted', 10, 1 );
        $this->loader->add_action( 'woocommerce_trash_product', $sync, 'on_product_deleted', 10, 1 );

        // Stock changes
        $this->loader->add_action( 'woocommerce_product_set_stock', $sync, 'on_stock_changed', 10, 1 );
        $this->loader->add_action( 'woocommerce_variation_set_stock', $sync, 'on_stock_changed', 10, 1 );

        // Order status changes (WC → Hou.la)
        $this->loader->add_action( 'woocommerce_order_status_changed', $sync, 'on_order_status_changed', 10, 4 );
    }

    // =====================================================================
    // REST API routes (webhook receiver)
    // =====================================================================

    private function define_rest_routes() {
        $webhook = new Wp_Houla_Webhook();
        $auth    = new Wp_Houla_Auth();

        $this->loader->add_action( 'rest_api_init', $webhook, 'register_routes' );
        $this->loader->add_action( 'rest_api_init', $auth, 'register_routes' );
    }

    // =====================================================================
    // Run
    // =====================================================================

    public function run() {
        $this->loader->run();
    }

    // =====================================================================
    // WP-Cron scheduled sync
    // =====================================================================

    /**
     * Register cron hooks for automatic background sync.
     */
    private function define_cron_hooks() {
        // Register a custom cron interval (every 6 hours)
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

        // The actual cron action
        add_action( 'wphoula_cron_sync', array( $this, 'run_cron_sync' ) );

        // Self-heal: if cron was never scheduled (e.g. plugin updated without re-activation), schedule now
        if ( ! wp_next_scheduled( 'wphoula_cron_sync' ) ) {
            wp_schedule_event( time() + 60, 'wphoula_every_6h', 'wphoula_cron_sync' );
        }
    }

    /**
     * Add custom cron intervals.
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function add_cron_schedules( $schedules ) {
        $schedules['wphoula_every_6h'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display'  => __( 'Every 6 hours (Hou.la sync)', 'wp-houla' ),
        );
        $schedules['wphoula_every_1h'] = array(
            'interval' => HOUR_IN_SECONDS,
            'display'  => __( 'Every hour (Hou.la sync)', 'wp-houla' ),
        );
        return $schedules;
    }

    /**
     * Execute the scheduled cron sync.
     * Called by WP-Cron — runs without any user/AJAX context.
     */
    public function run_cron_sync() {
        // Bail if WooCommerce is not active
        if ( ! function_exists( 'wc_get_products' ) ) {
            return;
        }

        $sync = new Wp_Houla_Sync();
        $sync->cron_full_sync();
    }

    public function get_plugin_name() {
        return $this->plugin_name;
    }

    public function get_version() {
        return $this->version;
    }

    public function get_loader() {
        return $this->loader;
    }
}
