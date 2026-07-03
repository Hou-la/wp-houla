<?php
/**
 * Hou.la Pay - WooCommerce Integration
 *
 * @link              https://hou.la/
 * @since             1.0.0
 * @package           Wp_Houla
 *
 * @wordpress-plugin
 * Plugin Name:       Houla - Sync WooCommerce with Hou.la (Marketplace & Live Shopping)
 * Plugin URI:        https://hou.la/
 * Description:       Sync your WooCommerce products and orders both ways with Hou.la - the social network, content-creation and live-shopping marketplace. Orders placed on Hou.la flow back into WooCommerce. Short links and QR codes included as a bonus.
 * Version:           1.5.2
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Hou.la
 * Author URI:        https://hou.la/
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       wp-houla
 * Domain Path:       /languages
 *
 * WC requires at least: 7.0
 * WC tested up to:      9.0
 */

// Abort if called directly.
if ( ! defined( 'WPINC' ) ) {
    die;
}

// =========================================================================
// Constants
// =========================================================================

define( 'WPHOULA_VERSION', '1.5.2' );
define( 'WPHOULA_DIR', WP_PLUGIN_DIR . '/' . basename( dirname( __FILE__ ) ) );
define( 'WPHOULA_URL', plugins_url( '/', __FILE__ ) );
define( 'WPHOULA_BASENAME', plugin_basename( __FILE__ ) );

define( 'WPHOULA_OPTIONS', 'wphoula-options' );
define( 'WPHOULA_AUTHORIZED', 'wphoula-authorized' );

// API URLs
define( 'WPHOULA_DEFAULT_API_URL', 'https://hou.la' );
define( 'WPHOULA_DEFAULT_FRONTEND_URL', 'https://app.hou.la' );
define( 'WPHOULA_OAUTH_CLIENT_ID', 'wp-houla' );

// Legacy constants (kept for backward compat — prefer the helper functions below)
define( 'WPHOULA_API_URL', WPHOULA_DEFAULT_API_URL );
define( 'WPHOULA_OAUTH_URL', WPHOULA_DEFAULT_FRONTEND_URL . '/oauth/authorize' );
define( 'WPHOULA_OAUTH_TOKEN_URL', WPHOULA_DEFAULT_API_URL . '/api/oauth/token' );

/**
 * Check if dev mode is active.
 *
 * Activated by adding `define( 'WPHOULA_DEV_MODE', true );` in wp-config.php.
 * In dev mode, the API URL can be overridden (e.g. ngrok URL for local testing).
 * In production this is always false — the API URL is locked to https://hou.la.
 *
 * @return bool
 */
function wphoula_is_dev_mode() {
    return defined( 'WPHOULA_DEV_MODE' ) && WPHOULA_DEV_MODE === true;
}

/**
 * Get the configured API URL.
 *
 * In dev mode: reads from plugin options (Debug tab), falls back to production URL.
 * In prod mode: always returns https://hou.la (ignores any stored custom URL).
 *
 * @return string API base URL (no trailing slash).
 */
function wphoula_get_api_url() {
    if ( ! wphoula_is_dev_mode() ) {
        return WPHOULA_DEFAULT_API_URL;
    }
    $options = get_option( WPHOULA_OPTIONS, array() );
    $url = isset( $options['api_url'] ) && ! empty( $options['api_url'] )
        ? $options['api_url']
        : WPHOULA_DEFAULT_API_URL;
    return rtrim( $url, '/' );
}

/**
 * Get the OAuth authorize URL (dynamic, respects dev mode).
 *
 * This URL is opened in the user's browser, so in dev mode we replace
 * host.docker.internal with localhost (the browser runs on the host, not in Docker).
 *
 * @return string
 */
function wphoula_get_oauth_url() {
    if ( wphoula_is_dev_mode() ) {
        $base = wphoula_get_api_url();
        $base = str_replace( 'host.docker.internal', 'localhost', $base );
        return $base . '/oauth/authorize';
    }
    return WPHOULA_DEFAULT_FRONTEND_URL . '/oauth/authorize';
}

/**
 * Get the OAuth token endpoint URL (dynamic, respects dev mode).
 *
 * @return string
 */
function wphoula_get_token_url() {
    return wphoula_get_api_url() . '/api/oauth/token';
}

define( 'WPHOULA_LOG', WPHOULA_DIR . '/log/debug.txt' );

// =========================================================================
// Activation / Deactivation
// =========================================================================

function activate_wp_houla() {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wp-houla-activator.php';
    Wp_Houla_Activator::activate();
}

function deactivate_wp_houla() {
    require_once plugin_dir_path( __FILE__ ) . 'includes/class-wp-houla-deactivator.php';
    Wp_Houla_Deactivator::deactivate();
}

register_activation_hook( __FILE__, 'activate_wp_houla' );
register_deactivation_hook( __FILE__, 'deactivate_wp_houla' );

// =========================================================================
// WooCommerce detection (optional, not required)
// =========================================================================

/**
 * Check whether WooCommerce is active.
 * The plugin works without it (shortlinks + QR codes only).
 * WooCommerce features (product sync, orders, webhook) load only when present.
 *
 * @return bool
 */
function wphoula_is_woocommerce_active() {
    return class_exists( 'WooCommerce' );
}

// =========================================================================
// HPOS Compatibility Declaration
// =========================================================================

add_action( 'before_woocommerce_init', function() {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            __FILE__,
            true
        );
    }
} );

// =========================================================================
// Bootstrap
// =========================================================================

require plugin_dir_path( __FILE__ ) . 'includes/class-wp-houla.php';

function run_wp_houla() {
    $plugin = new Wp_Houla();
    $plugin->run();
}

add_action( 'plugins_loaded', 'run_wp_houla' );
