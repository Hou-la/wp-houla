<?php
// Hardening: never allow this diagnostic/dev script to run via a web request.
if ( php_sapi_name() !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
/**
 * Diagnostic: check wphouladev cron (dev plugin uses renamed identifiers).
 */
chdir( '/var/www/vhosts/lucky-geek.com/httpdocs' );
require_once 'wp-load.php';

echo "=== WP-Houla DEV Cron Diagnostic ===" . PHP_EOL;

// Clean up wrongly-scheduled event from previous diagnostic
wp_clear_scheduled_hook( 'wphoula_cron_sync' );

// Check dev hook
$ts = wp_next_scheduled( 'wphouladev_cron_sync' );
echo "wphouladev_cron_sync scheduled: " . ( $ts ? date( 'Y-m-d H:i:s', $ts ) . ' (in ' . round(($ts - time())/60) . ' min)' : 'NO' ) . PHP_EOL;

// Check if the action callback is registered
echo "wphouladev_cron_sync has callbacks: " . ( has_action( 'wphouladev_cron_sync' ) ? 'YES (' . has_action( 'wphouladev_cron_sync' ) . ')' : 'NO' ) . PHP_EOL;

// Check custom schedules
$schedules = wp_get_schedules();
echo "wphouladev_every_6h exists: " . ( isset( $schedules['wphouladev_every_6h'] ) ? 'YES' : 'NO' ) . PHP_EOL;

// If not scheduled, schedule it now with the correct name
if ( ! $ts ) {
    echo "Scheduling wphouladev_cron_sync with wphouladev_every_6h..." . PHP_EOL;
    $result = wp_schedule_event( time() + 60, 'wphouladev_every_6h', 'wphouladev_cron_sync' );
    if ( is_wp_error( $result ) ) {
        echo "  Error: " . $result->get_error_message() . PHP_EOL;
    } elseif ( $result === false ) {
        echo "  Returned false" . PHP_EOL;
    } else {
        $ts2 = wp_next_scheduled( 'wphouladev_cron_sync' );
        echo "  ✅ Scheduled at: " . date( 'Y-m-d H:i:s', $ts2 ) . PHP_EOL;
    }
}

// List all wphoula-related cron events
echo PHP_EOL . "All wphoula cron events:" . PHP_EOL;
$crons = _get_cron_array();
foreach ( $crons as $timestamp => $hooks ) {
    foreach ( $hooks as $hook => $events ) {
        if ( strpos( $hook, 'wphoula' ) !== false ) {
            $schedule = reset( $events );
            echo "  $hook @ " . date( 'Y-m-d H:i:s', $timestamp ) . " (schedule: " . ( $schedule['schedule'] ?? 'one-time' ) . ")" . PHP_EOL;
        }
    }
}
