<?php
// Hardening: never allow this diagnostic/dev script to run via a web request.
if ( php_sapi_name() !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
require_once '/var/www/vhosts/lucky-geek.com/httpdocs/wp-load.php';

echo '=== SYNC STATUS ===' . PHP_EOL;
$status = get_transient('wphouladev_bg_sync_status');
print_r($status);

echo PHP_EOL . '=== SYNC NONCE ===' . PHP_EOL;
$nonce = get_transient('wphouladev_bg_sync_nonce');
var_dump($nonce);

echo PHP_EOL . '=== PLUGIN OPTIONS ===' . PHP_EOL;
$opts = get_option('wphouladev-options', array());
echo 'products_synced: ' . (isset($opts['products_synced']) ? $opts['products_synced'] : 'N/A') . PHP_EOL;
echo 'last_full_sync: ' . (isset($opts['last_full_sync']) ? $opts['last_full_sync'] : 'N/A') . PHP_EOL;
echo 'api_url: ' . (isset($opts['api_url']) ? $opts['api_url'] : 'N/A') . PHP_EOL;
echo 'access_token present: ' . (isset($opts['access_token']) && !empty($opts['access_token']) ? 'YES' : 'NO') . PHP_EOL;

echo PHP_EOL . '=== CATEGORY MAPPINGS ===' . PHP_EOL;
$mappings = get_option('wphouladev_category_mappings', array());
echo 'Count: ' . count($mappings) . PHP_EOL;
if (!empty($mappings)) {
    foreach (array_slice($mappings, 0, 10, true) as $k => $v) {
        echo "  WC cat $k => Houla collection $v" . PHP_EOL;
    }
    if (count($mappings) > 10) echo '  ... and ' . (count($mappings) - 10) . ' more' . PHP_EOL;
}

echo PHP_EOL . '=== CATEGORY FILTER ===' . PHP_EOL;
$filter = get_option('wphouladev_sync_categories', array());
echo 'Count: ' . count($filter) . PHP_EOL;
if (!empty($filter)) {
    foreach (array_slice($filter, 0, 5) as $catId) {
        $term = get_term($catId, 'product_cat');
        echo "  $catId => " . ($term && !is_wp_error($term) ? $term->name : '???') . PHP_EOL;
    }
}

echo PHP_EOL . '=== LOCK FILE ===' . PHP_EOL;
$lock_file = sys_get_temp_dir() . '/wphoula_sync_' . md5(ABSPATH) . '.lock';
echo 'Path: ' . $lock_file . PHP_EOL;
echo 'Exists: ' . (file_exists($lock_file) ? 'YES (' . filesize($lock_file) . ' bytes, age: ' . (time() - filemtime($lock_file)) . 's)' : 'NO') . PHP_EOL;

echo PHP_EOL . '=== WC PRODUCTS COUNT ===' . PHP_EOL;
$total = wp_count_posts('product');
echo 'Published: ' . $total->publish . PHP_EOL;
echo 'Draft: ' . $total->draft . PHP_EOL;

echo PHP_EOL . '=== HOULA SYNCED PRODUCTS (via postmeta) ===' . PHP_EOL;
global $wpdb;
$synced_count = $wpdb->get_var(
    "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta} WHERE meta_key = '_wphouladev_product_id' AND meta_value != ''"
);
echo 'Products with Houla ID: ' . $synced_count . PHP_EOL;

echo PHP_EOL . '=== PHP CONFIG ===' . PHP_EOL;
echo 'max_execution_time: ' . ini_get('max_execution_time') . PHP_EOL;
echo 'memory_limit: ' . ini_get('memory_limit') . PHP_EOL;

echo PHP_EOL . '=== RECENT ERROR LOG ===' . PHP_EOL;
$error_log = '/var/www/vhosts/lucky-geek.com/httpdocs/wp-content/debug.log';
if (file_exists($error_log)) {
    $lines = file($error_log);
    $last = array_slice($lines, -30);
    echo implode('', $last);
} else {
    $error_log2 = '/var/log/php-fpm/error.log';
    if (file_exists($error_log2)) {
        $lines = file($error_log2);
        $last = array_slice($lines, -20);
        echo implode('', $last);
    } else {
        echo 'No debug.log found' . PHP_EOL;
    }
}

echo PHP_EOL . 'Done.' . PHP_EOL;
