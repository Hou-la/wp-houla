<?php
// Hardening: never allow this diagnostic/dev script to run via a web request.
if ( php_sapi_name() !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Diagnostic: check if wphoula_cron_sync is scheduled.
 * Usage: php check-cron.php
 */
chdir( dirname( __DIR__, 4 ) ); // Navigate to WP root from plugins/wp-houla-dev/scripts/
if ( ! file_exists( 'wp-load.php' ) ) {
    // Fallback: try explicit path
    chdir( '/var/www/vhosts/lucky-geek.com/httpdocs' );
}
require_once 'wp-load.php';

$ts = wp_next_scheduled( 'wphoula_cron_sync' );
if ( $ts ) {
    $diff = round( ( $ts - time() ) / 60 );
    echo "✅ Cron 'wphoula_cron_sync' scheduled at: " . date( 'Y-m-d H:i:s', $ts ) . " (in {$diff} minutes)" . PHP_EOL;
} else {
    echo "❌ Cron 'wphoula_cron_sync' is NOT SCHEDULED" . PHP_EOL;
}

// Also check if the hook is registered
$crons = _get_cron_array();
$found = false;
foreach ( $crons as $timestamp => $hooks ) {
    if ( isset( $hooks['wphoula_cron_sync'] ) ) {
        $found = true;
        $schedule = reset( $hooks['wphoula_cron_sync'] );
        echo "Schedule: " . ( $schedule['schedule'] ?? 'one-time' ) . PHP_EOL;
        echo "Interval: " . ( isset( $schedule['interval'] ) ? ( $schedule['interval'] / 3600 ) . 'h' : 'N/A' ) . PHP_EOL;
        break;
    }
}
if ( ! $found ) {
    echo "No cron entry found in _get_cron_array()" . PHP_EOL;
}
