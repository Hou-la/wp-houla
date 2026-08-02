<?php
// Hardening: never allow this diagnostic/dev script to run via a web request.
if ( php_sapi_name() !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Diagnostic: attempt to schedule wphoula_cron_sync and report errors.
 * Usage: cd /var/www/vhosts/lucky-geek.com/httpdocs && php /tmp/schedule-cron.php
 */
chdir( '/var/www/vhosts/lucky-geek.com/httpdocs' );
require_once 'wp-load.php';

echo "=== WP-Houla Cron Diagnostic ===" . PHP_EOL;

// Check if the custom schedules are registered
$schedules = wp_get_schedules();
echo "Custom schedules registered: " . PHP_EOL;
foreach ( $schedules as $key => $sched ) {
    if ( strpos( $key, 'wphoula' ) !== false ) {
        echo "  - $key: interval={$sched['interval']}s ({$sched['display']})" . PHP_EOL;
    }
}
if ( ! isset( $schedules['wphoula_every_6h'] ) ) {
    echo "  ⚠️  wphoula_every_6h NOT found in schedules!" . PHP_EOL;
    echo "  Available: " . implode( ', ', array_keys( $schedules ) ) . PHP_EOL;
}

// Check if already scheduled
$ts = wp_next_scheduled( 'wphoula_cron_sync' );
echo "Currently scheduled: " . ( $ts ? date( 'Y-m-d H:i:s', $ts ) : 'NO' ) . PHP_EOL;

// Try to schedule using built-in 'hourly' first
if ( ! $ts ) {
    echo "Attempting to schedule with 'hourly'..." . PHP_EOL;
    $result = wp_schedule_event( time() + 60, 'hourly', 'wphoula_cron_sync' );
    if ( is_wp_error( $result ) ) {
        echo "  ❌ Error: " . $result->get_error_message() . PHP_EOL;
    } elseif ( $result === false ) {
        echo "  ❌ Returned false" . PHP_EOL;
    } else {
        echo "  ✅ Scheduled successfully" . PHP_EOL;
        // Check it was persisted
        $ts2 = wp_next_scheduled( 'wphoula_cron_sync' );
        echo "  Verified: " . ( $ts2 ? date( 'Y-m-d H:i:s', $ts2 ) : 'NOT FOUND' ) . PHP_EOL;
    }
}

// Check plugin class loaded
echo "Wp_Houla class exists: " . ( class_exists( 'Wp_Houla' ) ? 'YES' : 'NO' ) . PHP_EOL;
echo "Wp_Houla_Sync class exists: " . ( class_exists( 'Wp_Houla_Sync' ) ? 'YES' : 'NO' ) . PHP_EOL;

// Check if action is hooked
echo "wphoula_cron_sync action has callbacks: " . ( has_action( 'wphoula_cron_sync' ) ? 'YES' : 'NO' ) . PHP_EOL;
