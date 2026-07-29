<?php
/**
 * Manage plugin options stored in wp_options.
 *
 * @since      1.0.0
 * @package    Wp_Houla
 * @subpackage Wp_Houla/includes
 */

class Wp_Houla_Options {

    /** @var array */
    private $_options = array();

    public function __construct() {
        $this->populate_options();
    }

    /**
     * Merge saved options with defaults.
     */
    public function populate_options() {
        $defaults = apply_filters( 'wphoula_default_options', array(
            'version'            => WPHOULA_VERSION,
            // OAuth tokens (encrypted in DB)
            'access_token'       => '',
            'refresh_token'      => '',
            'token_expires_at'   => 0,
            // API Key (persistent auth — survives OAuth token expiry)
            'api_key'            => '',  // Encrypted houla_sk_... key
            // Connected workspace info
            'workspace_id'       => '',
            'workspace_name'     => '',
            'user_email'         => '',
            // Sync settings
            'auto_sync'          => true,
            'sync_on_publish'    => true,
            'sync_categories'    => array(),  // WooCommerce category IDs to sync (empty = all)
            'last_full_sync'     => 0,
            'products_synced'    => 0,
            // Webhook
            'webhook_secret'     => '',
            // Orders
            'orders_received'    => 0,
            'last_order_at'      => 0,
            // Debug / Dev mode
            'debug'              => false,
            'api_url'            => '',  // Custom API URL (empty = production https://hou.la)
            // Price adjustment
            'price_adjustment_type'  => 'none',  // none, percent_up, percent_down, fixed_up, fixed_down
            'price_adjustment_value' => 0,
            // Category -> Collection mapping
            'category_collection_map' => array(),  // cat_term_id => collection_id
            // Category -> Workspace mapping (multi-workspace routing for dropshipping)
            // Structure: cat_term_id => { workspace_id, workspace_name, api_key (encrypted), price_adjustment_type, price_adjustment_value }
            'category_workspace_map' => array(),
            // Post type filtering (default: post, page, product)
            'allowed_post_types' => array( 'post', 'page', 'product' ),
            // Order status concordance (wc-slug => houla_status)
            'order_status_map'   => array(
                'wc-pending'        => 'pending',
                'wc-on-hold'        => 'pending',
                'wc-open-cart'      => 'open_cart',
                'wc-processing'     => 'processing',
                'wc-houla-shipping' => 'shipped',
                'wc-completed'      => 'delivered',
                'wc-cancelled'      => 'cancelled',
                'wc-failed'         => 'cancelled',
                'wc-refunded'       => 'refunded',
                'wc-checkout-draft' => 'pending',
            ),
            // Tracking sync
            'sync_tracking'      => true,
            // Product identifiers meta mapping (gtin/isbn => WC meta_key)
            'identifier_meta_map' => array(
                'gtin'  => '',
                'isbn' => '',
            ),
            // Whether the connected workspace has a shop enabled
            'workspace_has_shop'  => true,
        ) );

        $this->_options = wp_parse_args( get_option( WPHOULA_OPTIONS, array() ), $defaults );

        // Migrate: ensure order_status_map contains all required native WC statuses.
        // wp_parse_args does NOT merge nested arrays, so existing installs may be missing
        // statuses added in later versions (open-cart, pending, failed, checkout-draft).
        $this->ensure_required_status_mappings();
    }

    /**
     * Add any missing native WC status mappings without overwriting user customizations.
     */
    private function ensure_required_status_mappings() {
        $required = array(
            'wc-pending'        => 'pending',
            'wc-on-hold'        => 'pending',
            'wc-open-cart'      => 'open_cart',
            'wc-processing'     => 'processing',
            'wc-houla-shipping' => 'shipped',
            'wc-completed'      => 'delivered',
            'wc-cancelled'      => 'cancelled',
            'wc-failed'         => 'cancelled',
            'wc-refunded'       => 'refunded',
            'wc-checkout-draft' => 'pending',
        );

        $map = $this->_options['order_status_map'];
        if ( ! is_array( $map ) ) {
            $map = array();
        }

        $changed = false;
        foreach ( $required as $wc_slug => $houla_status ) {
            if ( ! array_key_exists( $wc_slug, $map ) ) {
                $map[ $wc_slug ] = $houla_status;
                $changed = true;
            }
        }

        if ( $changed ) {
            $this->_options['order_status_map'] = $map;
            update_option( WPHOULA_OPTIONS, $this->_options );
        }
    }

    /**
     * Get a single option value.
     *
     * @param string $key
     * @return mixed
     */
    public function get( $key ) {
        if ( ! array_key_exists( $key, $this->_options ) ) {
            return null;
        }
        return $this->_options[ $key ];
    }

    /**
     * Set a single option and persist.
     *
     * @param string $key
     * @param mixed  $value
     */
    public function set( $key, $value ) {
        $this->_options[ $key ] = $value;
        $this->save();
    }

    /**
     * Set multiple options at once and persist.
     *
     * @param array $pairs Key-value pairs.
     */
    public function set_many( array $pairs ) {
        foreach ( $pairs as $key => $value ) {
            $this->_options[ $key ] = $value;
        }
        $this->save();
    }

    /**
     * Persist current options to the database.
     */
    private function save() {
        update_option( WPHOULA_OPTIONS, $this->_options );
    }

    /**
     * Delete all plugin options.
     */
    public static function delete_all() {
        delete_option( WPHOULA_OPTIONS );
        delete_option( WPHOULA_AUTHORIZED );
    }

    // =====================================================================
    // Token encryption helpers
    // =====================================================================

    /**
     * Encrypt a value using the WP SECURE_AUTH_KEY.
     *
     * @param string $value
     * @return string Base64-encoded encrypted value.
     */
    public static function encrypt( $value ) {
        if ( empty( $value ) ) {
            return '';
        }

        $key = self::get_encryption_key();
        $iv  = openssl_random_pseudo_bytes( openssl_cipher_iv_length( 'aes-256-cbc' ) );

        $encrypted = openssl_encrypt( $value, 'aes-256-cbc', $key, 0, $iv );

        return base64_encode( $iv . '::' . $encrypted );
    }

    /**
     * Decrypt a previously encrypted value.
     *
     * @param string $value Base64-encoded encrypted value.
     * @return string
     */
    public static function decrypt( $value ) {
        if ( empty( $value ) ) {
            return '';
        }

        $key       = self::get_encryption_key();
        $data      = base64_decode( $value );
        $iv_length = openssl_cipher_iv_length( 'aes-256-cbc' );

        // Minimum size: IV (16 bytes) + separator '::' (2 bytes) + at least 1 byte of ciphertext.
        if ( false === $data || strlen( $data ) < $iv_length + 3 ) {
            return $value; // Not encrypted, return as-is.
        }

        // Extract IV by known length (avoids corruption when IV bytes contain '::').
        $iv        = substr( $data, 0, $iv_length );
        $encrypted = substr( $data, $iv_length + 2 ); // Skip '::' separator.

        $decrypted = openssl_decrypt( $encrypted, 'aes-256-cbc', $key, 0, $iv );

        return ( false !== $decrypted ) ? $decrypted : '';
    }

    /**
     * Derive the encryption key from WordPress salts.
     *
     * @return string
     */
    private static function get_encryption_key() {
        $salt = defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : 'wp-houla-default-key';
        return hash( 'sha256', $salt, true );
    }
}
