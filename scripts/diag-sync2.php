<?php
// Hardening: never allow this diagnostic/dev script to run via a web request.
if ( php_sapi_name() !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
require_once '/var/www/vhosts/lucky-geek.com/httpdocs/wp-load.php';

$opts = get_option('wphouladev-options', array());

echo '=== CATEGORY COLLECTION MAP ===' . PHP_EOL;
$map = isset($opts['category_collection_map']) ? $opts['category_collection_map'] : array();
echo 'Count: ' . count($map) . PHP_EOL;
if (!empty($map)) {
    foreach ($map as $k => $v) {
        $term = get_term($k, 'product_cat');
        $name = ($term && !is_wp_error($term)) ? $term->name : '???';
        echo "  WC cat $k ($name) => Houla collection $v" . PHP_EOL;
    }
}

echo PHP_EOL . '=== SYNC CATEGORIES (filter) ===' . PHP_EOL;
$filter = isset($opts['sync_categories']) ? $opts['sync_categories'] : array();
echo 'Count: ' . count($filter) . PHP_EOL;

echo PHP_EOL . '=== FULL OPTIONS KEYS ===' . PHP_EOL;
foreach ($opts as $k => $v) {
    if (in_array($k, ['access_token', 'refresh_token', 'api_key', 'webhook_secret'])) {
        echo "  $k => [REDACTED]" . PHP_EOL;
    } elseif (is_array($v)) {
        echo "  $k => array(" . count($v) . " items)" . PHP_EOL;
    } else {
        $display = strlen($v) > 80 ? substr($v, 0, 80) . '...' : $v;
        echo "  $k => $display" . PHP_EOL;
    }
}

echo PHP_EOL . '=== API CONNECTIVITY CHECK ===' . PHP_EOL;
$api_url = isset($opts['api_url']) ? $opts['api_url'] : '';
echo 'API URL: ' . $api_url . PHP_EOL;
if ($api_url) {
    $test = wp_remote_get($api_url . '/api/health', array('timeout' => 10, 'sslverify' => false));
    if (is_wp_error($test)) {
        echo 'API UNREACHABLE: ' . $test->get_error_message() . PHP_EOL;
    } else {
        echo 'API status: ' . wp_remote_retrieve_response_code($test) . PHP_EOL;
        echo 'API response: ' . substr(wp_remote_retrieve_body($test), 0, 200) . PHP_EOL;
    }
}

echo PHP_EOL . '=== PRODUCTS WITH HOULA ID ===' . PHP_EOL;
global $wpdb;
$synced = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
        '_wphouladev_product_id'
    )
);
echo 'Products with Houla ID: ' . $synced . PHP_EOL;

echo PHP_EOL . '=== SAMPLE SYNCED PRODUCTS ===' . PHP_EOL;
$samples = $wpdb->get_results(
    $wpdb->prepare(
        "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != '' LIMIT 5",
        '_wphouladev_product_id'
    )
);
foreach ($samples as $s) {
    $product = wc_get_product($s->post_id);
    $name = $product ? $product->get_name() : '???';
    echo "  WC #{$s->post_id} ($name) => Houla ID: {$s->meta_value}" . PHP_EOL;
}

echo PHP_EOL . 'Done.' . PHP_EOL;
