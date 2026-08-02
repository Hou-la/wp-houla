<?php
// Hardening: never allow this diagnostic/dev script to run via a web request.
if ( php_sapi_name() !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Diagnostic: check API connection state for wp-houla-dev.
 */
chdir( '/var/www/vhosts/lucky-geek.com/httpdocs' );
require_once 'wp-load.php';

$opts = get_option( 'wphouladev-options', array() );

echo "=== WP-Houla DEV Connection State ===" . PHP_EOL;
echo "Dev mode: " . ( defined( 'WPHOULADEV_DEV_MODE' ) && WPHOULADEV_DEV_MODE ? 'YES' : 'NO' ) . PHP_EOL;
echo "Effective URL: " . wphouladev_get_api_url() . PHP_EOL;
echo "Stored api_url: " . ( $opts['api_url'] ?? 'NOT SET' ) . PHP_EOL;
echo "API key: " . ( empty( $opts['api_key'] ) ? 'EMPTY' : substr( $opts['api_key'], 0, 20 ) . '...' ) . PHP_EOL;
echo "Access token: " . ( empty( $opts['access_token'] ) ? 'EMPTY' : substr( $opts['access_token'], 0, 20 ) . '...' ) . PHP_EOL;
echo "Refresh token: " . ( empty( $opts['refresh_token'] ) ? 'EMPTY' : 'SET (' . strlen( $opts['refresh_token'] ) . ' chars)' ) . PHP_EOL;
echo PHP_EOL;

// Try the actual API call
echo "=== Testing API Call ===" . PHP_EOL;
$api = new Wp_Houladev_Api();
$result = $api->get( '/ecommerce/products?platform=woocommerce' );

if ( is_wp_error( $result ) ) {
    echo "ERROR: " . $result->get_error_code() . ' - ' . $result->get_error_message() . PHP_EOL;
    $data = $result->get_error_data();
    if ( $data ) {
        echo "Error data: " . print_r( $data, true ) . PHP_EOL;
    }
} else {
    $count = is_array( $result ) ? count( $result ) : 0;
    echo "SUCCESS: " . $count . " products returned" . PHP_EOL;
    if ( $count > 0 ) {
        echo "First product: " . ( $result[0]['title'] ?? 'N/A' ) . " (ID: " . ( $result[0]['id'] ?? 'N/A' ) . ")" . PHP_EOL;
    }
}

// Also check local post_meta count
echo PHP_EOL . "=== Local WP Meta Count ===" . PHP_EOL;
global $wpdb;
$meta_key = '_wphouladev_product_id';
$synced_count = $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
    $meta_key
) );
echo "Products with '{$meta_key}' meta: {$synced_count}" . PHP_EOL;

$synced_flag_count = $wpdb->get_var( $wpdb->prepare(
    "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
    '_wphouladev_synced'
) );
echo "Products with '_wphouladev_synced=1' meta: {$synced_flag_count}" . PHP_EOL;
