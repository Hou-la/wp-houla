<?php
/**
 * REST API endpoint for receiving webhooks from Hou.la.
 *
 * Registers: POST /wp-json/wp-houla/v1/webhook
 *
 * Verifies HMAC-SHA256 signature using the shared webhook secret,
 * then dispatches to the appropriate handler based on event type.
 *
 * Supported events:
 * - order.paid      -> creates a WooCommerce order
 * - order.refunded  -> marks WooCommerce order as refunded
 * - product.updated -> optional reverse sync
 *
 * @since      1.0.0
 * @package    Wp_Houla
 * @subpackage Wp_Houla/includes
 */

class Wp_Houla_Webhook {

    /** @var Wp_Houla_Orders */
    private $orders;

    /** @var Wp_Houla_Options */
    private $options;

    public function __construct() {
        $this->options = new Wp_Houla_Options();
    }

    // =====================================================================
    // REST route registration (called via rest_api_init)
    // =====================================================================

    /**
     * Register the webhook endpoint.
     */
    public function register_routes() {
        register_rest_route( 'wp-houla/v1', '/webhook', array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => array( $this, 'handle_webhook' ),
            'permission_callback' => array( $this, 'verify_signature' ),
        ) );
    }

    // =====================================================================
    // Signature verification
    // =====================================================================

    /**
     * Verify the HMAC-SHA256 signature from the X-Houla-Signature header.
     *
     * @param WP_REST_Request $request
     * @return bool|WP_Error
     */
    public function verify_signature( $request ) {
        $signature = $request->get_header( 'X-Houla-Signature' );

        if ( empty( $signature ) ) {
            $this->log( 'Webhook rejected: missing X-Houla-Signature header.' );
            return new WP_Error(
                'wphoula_missing_signature',
                __( 'Missing signature header.', 'wp-houla' ),
                array( 'status' => 401 )
            );
        }

        $secret = $this->options->get( 'webhook_secret' );
        if ( empty( $secret ) ) {
            $this->log( 'Webhook rejected: no webhook secret configured.' );
            return new WP_Error(
                'wphoula_no_secret',
                __( 'Webhook secret not configured.', 'wp-houla' ),
                array( 'status' => 500 )
            );
        }

        $body    = $request->get_body();
        $hash    = hash_hmac( 'sha256', $body, $secret );
        $prefix  = 'sha256=';

        // Support both "sha256=<hash>" and raw "<hash>" formats
        $received = $signature;
        if ( strpos( $received, $prefix ) === 0 ) {
            $received = substr( $received, strlen( $prefix ) );
        }

        if ( ! hash_equals( $hash, $received ) ) {
            $this->log( 'Webhook rejected: invalid signature.' );
            return new WP_Error(
                'wphoula_invalid_signature',
                __( 'Invalid signature.', 'wp-houla' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    // =====================================================================
    // Webhook handler
    // =====================================================================

    /**
     * Handle an incoming webhook from Hou.la.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_webhook( $request ) {
        $payload   = $request->get_json_params();
        $event     = isset( $payload['event'] ) ? sanitize_text_field( $payload['event'] ) : '';
        $data      = isset( $payload['data'] ) ? $payload['data'] : array();
        $test_mode = ! empty( $payload['test_mode'] );

        $this->log( 'Webhook received: event=' . $event . ( $test_mode ? ' [TEST MODE]' : '' ) );

        // Propagate test_mode flag into data for handlers
        $data['_test_mode'] = $test_mode;

        if ( empty( $event ) || empty( $data ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => 'Missing event or data.',
            ), 400 );
        }

        // Lazy-load Orders handler
        if ( null === $this->orders ) {
            $this->orders = new Wp_Houla_Orders();
        }

        switch ( $event ) {
            case 'order.paid':
                $result = $this->orders->create_order( $data );
                break;

            case 'order.updated':
                $result = $this->orders->update_order( $data );
                break;

            case 'order.refunded':
                $result = $this->orders->refund_order( $data );
                break;

            case 'order.status_changed':
                $result = $this->orders->update_order_status( $data );
                break;

            case 'product.updated':
                $result = $this->handle_product_updated( $data );
                break;

            default:
                $this->log( 'Webhook: unknown event type "' . $event . '".' );
                return new WP_REST_Response( array(
                    'success' => false,
                    'message' => 'Unknown event type.',
                ), 400 );
        }

        if ( is_wp_error( $result ) ) {
            return new WP_REST_Response( array(
                'success' => false,
                'message' => $result->get_error_message(),
            ), 422 );
        }

        return new WP_REST_Response( array(
            'success'          => true,
            'externalOrderId'  => isset( $result['order_id'] ) ? $result['order_id'] : null,
            // URL d'édition CALCULÉE PAR LA BOUTIQUE, jamais devinée par Hou.la :
            // elle diffère entre le stockage HPOS et l'ancien stockage `post`,
            // et seul le site sait lequel est actif. Hou.la la mémorise pour
            // offrir à la vendeuse un lien direct depuis son détail de commande.
            'externalOrderUrl' => $this->order_edit_url( $result ),
        ), 200 );
    }

    /**
     * URL d'édition de la commande WooCommerce qui vient d'être créée / mise à jour.
     *
     * Renvoie null plutôt qu'une URL approximative : côté Hou.la, l'absence
     * d'URL déclenche un repli sur une recherche par numéro, alors qu'un lien
     * faux mènerait la vendeuse sur un 404.
     *
     * @param array $result Résultat du gestionnaire d'événement.
     * @return string|null
     */
    private function order_edit_url( $result ) {
        if ( ! isset( $result['order_id'] ) || ! $result['order_id'] ) {
            return null;
        }
        if ( ! function_exists( 'wc_get_order' ) ) {
            return null;
        }
        $order = wc_get_order( $result['order_id'] );
        if ( ! $order || ! method_exists( $order, 'get_edit_order_url' ) ) {
            return null;
        }
        $url = $order->get_edit_order_url();
        return $url ? $url : null;
    }

    // =====================================================================
    // Event handlers
    // =====================================================================

    /**
     * Handle product.updated reverse sync.
     * Optional: update local WooCommerce product data from Hou.la.
     *
     * @param array $data Webhook payload data.
     * @return array|WP_Error
     */
    private function handle_product_updated( $data ) {
        $external_id = isset( $data['external_id'] ) ? absint( $data['external_id'] ) : 0;
        if ( ! $external_id ) {
            return new WP_Error( 'wphoula_missing_id', 'Missing external_id in payload.' );
        }

        $product = wc_get_product( $external_id );
        if ( ! $product ) {
            return new WP_Error( 'wphoula_product_not_found', 'Product #' . $external_id . ' not found.' );
        }

        // Currently we only log the event. Future: reverse-sync fields.
        $this->log( 'Product #' . $external_id . ' updated on Hou.la (reverse sync not implemented).' );

        return array( 'product_id' => $external_id );
    }

    // =====================================================================
    // Logging
    // =====================================================================

    /**
     * Log a debug message.
     *
     * @param string $message
     */
    private function log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WP-Houla Webhook] ' . $message );
        }
    }
}
