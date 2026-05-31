<?php
/**
 * PHPUnit bootstrap file for WP-Houla tests.
 *
 * Uses Brain\Monkey to mock WordPress functions without loading WordPress.
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define WordPress constants used by the plugin.
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', '/tmp/wordpress/' );
}
if ( ! defined( 'WPINC' ) ) {
    define( 'WPINC', 'wp-includes' );
}
if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
    define( 'WP_PLUGIN_DIR', '/tmp/wordpress/wp-content/plugins' );
}
if ( ! defined( 'SECURE_AUTH_KEY' ) ) {
    define( 'SECURE_AUTH_KEY', 'test-secure-auth-key-for-phpunit-testing-only' );
}

// Plugin constants.
if ( ! defined( 'WPHOULA_VERSION' ) ) {
    define( 'WPHOULA_VERSION', '1.0.0' );
}
if ( ! defined( 'WPHOULA_DIR' ) ) {
    define( 'WPHOULA_DIR', dirname( __DIR__ ) );
}
if ( ! defined( 'WPHOULA_URL' ) ) {
    define( 'WPHOULA_URL', 'https://example.com/wp-content/plugins/wp-houla/' );
}
if ( ! defined( 'WPHOULA_BASENAME' ) ) {
    define( 'WPHOULA_BASENAME', 'wp-houla/wp-houla.php' );
}
if ( ! defined( 'WPHOULA_OPTIONS' ) ) {
    define( 'WPHOULA_OPTIONS', 'wphoula-options' );
}
if ( ! defined( 'WPHOULA_AUTHORIZED' ) ) {
    define( 'WPHOULA_AUTHORIZED', 'wphoula-authorized' );
}
if ( ! defined( 'WPHOULA_API_URL' ) ) {
    define( 'WPHOULA_API_URL', 'https://hou.la' );
}
if ( ! defined( 'WPHOULA_OAUTH_URL' ) ) {
    define( 'WPHOULA_OAUTH_URL', 'https://app.hou.la/oauth/authorize' );
}
if ( ! defined( 'WPHOULA_OAUTH_TOKEN_URL' ) ) {
    define( 'WPHOULA_OAUTH_TOKEN_URL', WPHOULA_API_URL . '/api/oauth/token' );
}
if ( ! defined( 'WPHOULA_OAUTH_CLIENT_ID' ) ) {
    define( 'WPHOULA_OAUTH_CLIENT_ID', 'wp-houla' );
}
if ( ! defined( 'WPHOULA_LOG' ) ) {
    define( 'WPHOULA_LOG', '/tmp/wp-houla-debug.txt' );
}

// =========================================================================
// Plugin helper functions (normally defined in wp-houla.php)
// =========================================================================

// wphoula_is_woocommerce_active() is NOT defined here.
// Brain\Monkey requires that the function does NOT exist natively
// so that Functions\when() / Functions\expect() can intercept it.
// Each test must stub it via Brain\Monkey\Functions\when().

// =========================================================================
// WordPress mock classes (global namespace — loaded once for all tests)
// =========================================================================

if ( ! class_exists( 'WP_Error' ) ) {
    class WP_Error {
        private $code;
        private $message;
        private $data;

        public function __construct( $code = '', $message = '', $data = '' ) {
            $this->code    = $code;
            $this->message = $message;
            $this->data    = $data;
        }

        public function get_error_code() { return $this->code; }
        public function get_error_message() { return $this->message; }
        public function get_error_data() { return $this->data; }
    }
}

if ( ! class_exists( 'WP_REST_Response' ) ) {
    class WP_REST_Response {
        public $data;
        public $status;

        public function __construct( $data = array(), $status = 200 ) {
            $this->data   = $data;
            $this->status = $status;
        }

        public function get_data() { return $this->data; }
        public function get_status() { return $this->status; }
    }
}

if ( ! class_exists( 'WP_REST_Server' ) ) {
    class WP_REST_Server {
        const CREATABLE = 'POST';
    }
}

if ( ! class_exists( 'WC_Order_Item_Fee' ) ) {
    /**
     * Minimal WooCommerce WC_Order_Item_Fee mock for tests.
     */
    class WC_Order_Item_Fee {
        private $name       = '';
        private $amount     = 0;
        private $total      = 0;
        private $tax_status = 'taxable';

        public function set_name( $name ) { $this->name = $name; }
        public function set_amount( $amount ) { $this->amount = $amount; }
        public function set_total( $total ) { $this->total = $total; }
        public function set_tax_status( $status ) { $this->tax_status = $status; }

        public function get_name() { return $this->name; }
        public function get_amount() { return $this->amount; }
        public function get_total() { return $this->total; }
        public function get_tax_status() { return $this->tax_status; }
    }
}

if ( ! class_exists( 'WC_Order_Item_Shipping' ) ) {
    /**
     * Minimal WooCommerce WC_Order_Item_Shipping mock for tests.
     */
    class WC_Order_Item_Shipping {
        private $method_title = '';
        private $method_id    = '';
        private $total        = 0;

        public function set_method_title( $title ) { $this->method_title = $title; }
        public function set_method_id( $id ) { $this->method_id = $id; }
        public function set_total( $total ) { $this->total = $total; }

        public function get_method_title() { return $this->method_title; }
        public function get_method_id() { return $this->method_id; }
        public function get_total() { return $this->total; }
    }
}
