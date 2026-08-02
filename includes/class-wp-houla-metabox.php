<?php
/**
 * Product metabox on the WooCommerce product edit screen.
 *
 * Displays:
 * - Hou.la sync status (synced / not synced)
 * - Last sync date
 * - Stats from Hou.la API (views, clicks, sales, revenue)
 * - Manual sync / unsync buttons
 *
 * @since      1.0.0
 * @package    Wp_Houla
 * @subpackage Wp_Houla/includes
 */

class Wp_Houla_Metabox {

    /** @var Wp_Houla_Api */
    private $api;

    /** @var Wp_Houla_Auth */
    private $auth;

    public function __construct() {
        $this->api  = new Wp_Houla_Api();
        $this->auth = new Wp_Houla_Auth();
    }

    // =====================================================================
    // Registration
    // =====================================================================

    /**
     * Register the metabox on the product edit screen.
     */
    public function register_metabox() {
        if ( ! $this->auth->is_connected() ) {
            return;
        }

        $metabox_title = '<img src="' . esc_url( WPHOULA_URL . 'admin/images/houla-icon.svg' ) . '" width="20" height="20" style="vertical-align:middle;margin-right:4px;" alt="">'
                       . '<span style="vertical-align:middle;">' . esc_html__( 'Hou.la — Product Sync', 'wp-houla' ) . '</span>';

        add_meta_box(
            'wphoula_product_metabox',
            $metabox_title,
            array( $this, 'render' ),
            'product',
            'side',
            'default'
        );
    }

    // =====================================================================
    // AJAX handlers
    // =====================================================================

    /**
     * Manual sync: push this product to Hou.la (AJAX).
     */
    public function ajax_sync_product() {
        check_ajax_referer( 'wphoula_metabox', 'nonce' );

        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $product_id = absint( $_POST['product_id'] ?? 0 );
        $product    = wc_get_product( $product_id );

        if ( ! $product ) {
            wp_send_json_error( __( 'Product not found.', 'wp-houla' ) );
        }

        $sync = new Wp_Houla_Sync();
        $houla_id = get_post_meta( $product_id, '_wphoula_product_id', true );

        if ( $houla_id ) {
            $sync->on_product_updated( $product_id, $product );
        } else {
            $sync->on_product_created( $product_id, $product );
        }

        $new_houla_id = get_post_meta( $product_id, '_wphoula_product_id', true );
        $sync_at      = get_post_meta( $product_id, '_wphoula_sync_at', true );

        wp_send_json_success( array(
            'houla_id' => $new_houla_id,
            'sync_at'  => $sync_at,
        ) );
    }

    /**
     * Unsync: remove Hou.la link from this product (AJAX).
     */
    public function ajax_unsync_product() {
        check_ajax_referer( 'wphoula_metabox', 'nonce' );

        if ( ! current_user_can( 'edit_products' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $product_id = absint( $_POST['product_id'] ?? 0 );
        $houla_id   = get_post_meta( $product_id, '_wphoula_product_id', true );

        if ( $houla_id ) {
            $this->api->delete( '/ecommerce/products/' . $houla_id );
        }

        delete_post_meta( $product_id, '_wphoula_product_id' );
        delete_post_meta( $product_id, '_wphoula_synced' );
        delete_post_meta( $product_id, '_wphoula_sync_at' );

        wp_send_json_success();
    }

    // =====================================================================
    // Render
    // =====================================================================

    /**
     * Render the metabox content.
     *
     * @param WP_Post $post Current post object.
     */
    public function render( $post ) {
        $product_id = $post->ID;
        $houla_id   = get_post_meta( $product_id, '_wphoula_product_id', true );
        $synced     = get_post_meta( $product_id, '_wphoula_synced', true );
        $sync_at    = get_post_meta( $product_id, '_wphoula_sync_at', true );
        $nonce      = wp_create_nonce( 'wphoula_metabox' );

        include plugin_dir_path( dirname( __FILE__ ) ) . 'admin/partials/metabox-product.php';
    }

    // =====================================================================
    // Stats (fetched via AJAX for performance)
    // =====================================================================

    /**
     * AJAX: Fetch product stats from Hou.la API.
     */
    public function ajax_get_stats() {
        check_ajax_referer( 'wphoula_metabox', 'nonce' );

        if ( ! current_user_can( 'edit_products' ) && ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $product_id = absint( $_POST['product_id'] ?? 0 );
        $houla_id   = get_post_meta( $product_id, '_wphoula_product_id', true );

        if ( empty( $houla_id ) ) {
            wp_send_json_error( __( 'Product not synced.', 'wp-houla' ) );
        }

        $stats = $this->api->get( '/ecommerce/products/' . $houla_id . '/stats' );

        if ( is_wp_error( $stats ) ) {
            wp_send_json_error( $stats->get_error_message() );
        }

        wp_send_json_success( $stats );
    }
}
