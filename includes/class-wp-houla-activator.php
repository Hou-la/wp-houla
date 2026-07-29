<?php
/**
 * Activation routines.
 *
 * @since      1.0.0
 * @package    Wp_Houla
 * @subpackage Wp_Houla/includes
 */

class Wp_Houla_Activator {

    /**
     * Runs on plugin activation.
     *
     * - Checks PHP/WP version requirements
     * - Generates a webhook secret if not present
     * - Flushes rewrite rules for our REST endpoint
     */
    public static function activate() {
        if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
            deactivate_plugins( WPHOULA_BASENAME );
            wp_die(
                esc_html__( 'Hou.la Pay requires PHP 7.4 or higher.', 'wp-houla' ),
                'Plugin Activation Error',
                array( 'back_link' => true )
            );
        }

        // Generate webhook secret on first activation
        $options = get_option( WPHOULA_OPTIONS, array() );
        if ( empty( $options['webhook_secret'] ) ) {
            $options['webhook_secret'] = wp_generate_password( 64, false );
        }

        // Migrate order status mapping: add missing native WC statuses
        self::migrate_order_status_map( $options );

        update_option( WPHOULA_OPTIONS, $options );

        // Ensure log directory exists
        $log_dir = WPHOULA_DIR . '/log';
        if ( ! is_dir( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
            file_put_contents( $log_dir . '/.htaccess', 'deny from all' );
        }

        // Flush rewrite rules so the REST endpoint is accessible
        flush_rewrite_rules();

        // Schedule automatic WP-Cron sync (every 6 hours by default)
        if ( ! wp_next_scheduled( 'wphoula_cron_sync' ) ) {
            wp_schedule_event( time() + 60, 'wphoula_every_6h', 'wphoula_cron_sync' );
        }
    }

    /**
     * Ensure the order_status_map contains all native WC statuses.
     * Only adds missing entries — never overwrites user's custom mappings.
     *
     * @param array &$options Plugin options (by reference).
     */
    private static function migrate_order_status_map( array &$options ) {
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

        if ( ! isset( $options['order_status_map'] ) || ! is_array( $options['order_status_map'] ) ) {
            $options['order_status_map'] = $required;
            return;
        }

        foreach ( $required as $wc_slug => $houla_status ) {
            if ( ! array_key_exists( $wc_slug, $options['order_status_map'] ) ) {
                $options['order_status_map'][ $wc_slug ] = $houla_status;
            }
        }
    }
}
