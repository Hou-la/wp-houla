<?php
/**
 * Order metabox on the WooCommerce order edit screen.
 *
 * Displays:
 * - Hou.la order sync status
 * - Shipping label actions: generate, download PDF, cancel
 *
 * Works with both HPOS (woocommerce_page_wc-orders) and legacy
 * post-based orders (shop_order).
 *
 * @since      1.5.0
 * @package    Wp_Houla
 * @subpackage Wp_Houla/includes
 */

class Wp_Houla_Order_Metabox {

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
     * Register the metabox on the WooCommerce order edit screen.
     */
    public function register_metabox() {
        if ( ! $this->auth->is_connected() ) {
            return;
        }

        $metabox_title = '<img src="' . esc_url( WPHOULA_URL . 'admin/images/houla-icon.svg' ) . '" width="20" height="20" style="vertical-align:middle;margin-right:4px;" alt="">'
                       . '<span style="vertical-align:middle;">' . esc_html__( 'Hou.la — Shipping', 'wp-houla' ) . '</span>';

        // HPOS compatible screen
        $screen = wc_get_container()->get( \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class )->custom_orders_table_usage_is_enabled()
            ? wc_get_page_screen_id( 'shop-order' )
            : 'shop_order';

        add_meta_box(
            'wphoula_order_shipping_metabox',
            $metabox_title,
            array( $this, 'render' ),
            $screen,
            'side',
            'default'
        );
    }

    // =====================================================================
    // AJAX handlers
    // =====================================================================

    /**
     * Generate a shipping label via Hou.la API (AJAX).
     */
    public function ajax_generate_label() {
        check_ajax_referer( 'wphoula_order_metabox', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $wc_order_id = absint( $_POST['order_id'] ?? 0 );
        $format      = sanitize_text_field( $_POST['format'] ?? '10x15' );
        $order       = wc_get_order( $wc_order_id );

        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'wp-houla' ) );
        }

        $houla_order_id = $order->get_meta( '_houla_order_id' );
        if ( empty( $houla_order_id ) ) {
            wp_send_json_error( __( 'Order not synced with Hou.la.', 'wp-houla' ) );
        }

        // Call Hou.la API to generate label
        $result = $this->api->post( '/manager/shipping/labels', array(
            'orderIds'    => array( $houla_order_id ),
            'labelFormat' => $format,
        ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        // Check result
        $first = isset( $result['results'][0] ) ? $result['results'][0] : null;
        if ( $first && ! empty( $first['success'] ) ) {
            // Store the Hou.la order ID for label download reference
            $order->update_meta_data( '_houla_has_label', '1' );
            $order->save();
            wp_send_json_success( array(
                'message' => __( 'Shipping label generated successfully.', 'wp-houla' ),
            ) );
        } else {
            $error_msg = $first['error'] ?? __( 'Failed to generate label.', 'wp-houla' );
            wp_send_json_error( $error_msg );
        }
    }

    /**
     * Download the shipping label PDF via Hou.la API proxy (AJAX).
     *
     * This returns the download URL — the browser will open it in a new tab.
     */
    public function ajax_get_label_url() {
        check_ajax_referer( 'wphoula_order_metabox', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $wc_order_id = absint( $_POST['order_id'] ?? 0 );
        $order       = wc_get_order( $wc_order_id );

        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'wp-houla' ) );
        }

        $houla_order_id = $order->get_meta( '_houla_order_id' );
        if ( empty( $houla_order_id ) ) {
            wp_send_json_error( __( 'Order not synced with Hou.la.', 'wp-houla' ) );
        }

        // Build the proxy download URL (authenticated via WP admin AJAX)
        wp_send_json_success( array(
            'download_url' => admin_url( 'admin-ajax.php?action=wphoula_download_label&order_id=' . $wc_order_id . '&nonce=' . wp_create_nonce( 'wphoula_download_label' ) ),
        ) );
    }

    /**
     * Stream the label PDF to the browser (direct download, not JSON).
     */
    public function ajax_download_label() {
        // Use a separate nonce for the download URL
        if ( ! wp_verify_nonce( $_GET['nonce'] ?? '', 'wphoula_download_label' ) ) {
            wp_die( __( 'Security check failed.', 'wp-houla' ) );
        }

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_die( __( 'Permission denied.', 'wp-houla' ) );
        }

        $wc_order_id = absint( $_GET['order_id'] ?? 0 );
        $order       = wc_get_order( $wc_order_id );

        if ( ! $order ) {
            wp_die( __( 'Order not found.', 'wp-houla' ) );
        }

        $houla_order_id = $order->get_meta( '_houla_order_id' );
        if ( empty( $houla_order_id ) ) {
            wp_die( __( 'Order not synced with Hou.la.', 'wp-houla' ) );
        }

        // Fetch the PDF from the Hou.la proxy endpoint
        $auth_headers = $this->get_auth_headers();
        if ( ! $auth_headers ) {
            wp_die( __( 'Not connected to Hou.la.', 'wp-houla' ) );
        }

        $api_url  = function_exists( 'wphoula_get_api_url' ) ? wphoula_get_api_url() : WPHOULA_API_URL;
        $pdf_url  = $api_url . '/api/manager/shop/orders/' . $houla_order_id . '/shipping-label';

        $response = wp_remote_get( $pdf_url, array(
            'timeout'   => 30,
            'headers'   => array_merge( $auth_headers, array(
                'Accept'     => 'application/pdf',
                'User-Agent' => 'wp-houla/' . WPHOULA_VERSION,
                'ngrok-skip-browser-warning' => 'true',
            ) ),
            'sslverify' => ( strpos( $pdf_url, 'ngrok' ) === false && strpos( $pdf_url, 'localhost' ) === false ),
        ) );

        if ( is_wp_error( $response ) ) {
            wp_die( $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status >= 400 ) {
            wp_die( __( 'Failed to download label. Hou.la API returned status: ', 'wp-houla' ) . $status );
        }

        $pdf_body = wp_remote_retrieve_body( $response );
        $filename = 'label-' . $wc_order_id . '.pdf';

        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: inline; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $pdf_body ) );
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- binary PDF
        echo $pdf_body;
        exit;
    }

    /**
     * Print a shipping label via houla-print desktop agent (AJAX).
     *
     * Calls POST /api/manager/print/orders/:orderId/labels with kinds=['shipping_label'].
     * The API auto-generates the label if it doesn't exist yet.
     * houla-print receives the job via WebSocket and prints automatically.
     */
    public function ajax_print_label() {
        check_ajax_referer( 'wphoula_order_metabox', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $wc_order_id = absint( $_POST['order_id'] ?? 0 );
        $order       = wc_get_order( $wc_order_id );

        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'wp-houla' ) );
        }

        $houla_order_id = $order->get_meta( '_houla_order_id' );
        if ( empty( $houla_order_id ) ) {
            wp_send_json_error( __( 'Order not synced with Hou.la.', 'wp-houla' ) );
        }

        $result = $this->api->post( '/manager/print/orders/' . $houla_order_id . '/labels', array(
            'kinds' => array( 'shipping_label' ),
        ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $created = isset( $result['created'] ) ? intval( $result['created'] ) : 0;

        if ( $created > 0 ) {
            // Label was generated — update local cache
            $order->update_meta_data( '_houla_has_label', '1' );
            $order->save();

            wp_send_json_success( array(
                'message' => sprintf(
                    /* translators: %d: number of print jobs created */
                    __( 'Print job sent to Hou.la Print (%d job).', 'wp-houla' ),
                    $created
                ),
                'created' => $created,
            ) );
        } else {
            wp_send_json_error( __( 'No print job was created. Is Hou.la Print running?', 'wp-houla' ) );
        }
    }

    /**
     * Cancel a shipping label via Hou.la API (AJAX).
     */
    public function ajax_cancel_label() {
        check_ajax_referer( 'wphoula_order_metabox', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $wc_order_id = absint( $_POST['order_id'] ?? 0 );
        $order       = wc_get_order( $wc_order_id );

        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'wp-houla' ) );
        }

        $houla_order_id = $order->get_meta( '_houla_order_id' );
        if ( empty( $houla_order_id ) ) {
            wp_send_json_error( __( 'Order not synced with Hou.la.', 'wp-houla' ) );
        }

        $result = $this->api->delete( '/manager/shipping/labels/' . $houla_order_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $order->delete_meta_data( '_houla_has_label' );
        $order->save();

        wp_send_json_success( array(
            'message' => __( 'Shipping label cancelled.', 'wp-houla' ),
        ) );
    }

    /**
     * Check whether a Hou.la order has a shipping label (AJAX).
     */
    public function ajax_check_label_status() {
        check_ajax_referer( 'wphoula_order_metabox', 'nonce' );

        if ( ! current_user_can( 'edit_shop_orders' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $wc_order_id = absint( $_POST['order_id'] ?? 0 );
        $order       = wc_get_order( $wc_order_id );

        if ( ! $order ) {
            wp_send_json_error( __( 'Order not found.', 'wp-houla' ) );
        }

        $houla_order_id = $order->get_meta( '_houla_order_id' );
        if ( empty( $houla_order_id ) ) {
            wp_send_json_error( __( 'Order not synced with Hou.la.', 'wp-houla' ) );
        }

        // Fetch order detail from Hou.la to check labelUrl
        $detail = $this->api->get( '/manager/shop/orders/' . $houla_order_id );

        if ( is_wp_error( $detail ) ) {
            wp_send_json_error( $detail->get_error_message() );
        }

        $has_label = ! empty( $detail['labelUrl'] );
        $uses_sendcloud = ! empty( $detail['sendcloudCarrierCode'] );

        // Update local meta cache
        if ( $has_label ) {
            $order->update_meta_data( '_houla_has_label', '1' );
        } else {
            $order->delete_meta_data( '_houla_has_label' );
        }
        $order->save();

        wp_send_json_success( array(
            'has_label'      => $has_label,
            'uses_sendcloud' => $uses_sendcloud,
            'status'         => $detail['status'] ?? '',
        ) );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Resolve auth headers for direct HTTP calls (same logic as Wp_Houla_Api).
     */
    private function get_auth_headers() {
        $api_key = $this->auth->get_api_key();
        if ( $api_key ) {
            return array( 'X-Api-Key' => $api_key );
        }
        $token = $this->auth->get_access_token();
        if ( $token ) {
            return array( 'Authorization' => 'Bearer ' . $token );
        }
        return false;
    }

    /**
     * Log a message.
     */
    private function log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WP-Houla Order Metabox] ' . $message );
        }
    }

    // =====================================================================
    // Render
    // =====================================================================

    /**
     * Render the metabox content.
     *
     * @param WP_Post|WC_Order $post_or_order Order object (HPOS) or WP_Post (legacy).
     */
    public function render( $post_or_order ) {
        // HPOS compatibility: the argument may be a WC_Order or WP_Post
        if ( $post_or_order instanceof WC_Order ) {
            $order = $post_or_order;
        } else {
            $order = wc_get_order( $post_or_order->ID );
        }

        if ( ! $order ) {
            echo '<p>' . esc_html__( 'Order not found.', 'wp-houla' ) . '</p>';
            return;
        }

        $wc_order_id    = $order->get_id();
        $houla_order_id = $order->get_meta( '_houla_order_id' );
        $has_label       = $order->get_meta( '_houla_has_label' ) === '1';
        $nonce           = wp_create_nonce( 'wphoula_order_metabox' );

        include plugin_dir_path( dirname( __FILE__ ) ) . 'admin/partials/metabox-order.php';
    }
}
