<?php
/**
 * Metabox for all public post types (pages, posts, CPTs).
 *
 * Shows:
 * - Hou.la short link (copyable)
 * - QR code image
 * - Click stats (loaded via AJAX)
 * - Regenerate button
 *
 * This is separate from the WooCommerce product metabox
 * (class-wp-houla-metabox.php) which handles product sync.
 *
 * @since      1.0.0
 * @package    Wp_Houla
 * @subpackage Wp_Houla/includes
 */

class Wp_Houla_Post_Metabox {

    /** @var Wp_Houla_Api */
    private $api;

    /** @var Wp_Houla_Auth */
    private $auth;

    public function __construct() {
        $this->api  = new Wp_Houla_Api();
        $this->auth = new Wp_Houla_Auth();
    }

    // =====================================================================
    // Registration
    // =====================================================================

    /**
     * Register the Hou.la metabox on all public post types.
     */
    public function register_metabox() {
        if ( ! $this->auth->is_connected() ) {
            return;
        }

        $all_types = get_post_types( array( 'public' => true ) );

        // Respect allowed_post_types setting
        $options = new Wp_Houla_Options();
        $saved = $options->get( 'allowed_post_types' );
        if ( is_array( $saved ) && ! empty( $saved ) ) {
            $all_types = array_intersect( $all_types, $saved );
        }

        $post_types = apply_filters( 'wphoula_allowed_post_types', array_values( $all_types ) );

        foreach ( $post_types as $type ) {
            $metabox_title = '<img src="' . esc_url( WPHOULA_URL . 'admin/images/houla-icon.svg' ) . '" width="20" height="20" style="vertical-align:middle;margin-right:4px;" alt="">'
                           . '<span style="vertical-align:middle;">' . esc_html__( 'Hou.la', 'wp-houla' ) . '</span>';

            add_meta_box(
                'wphoula_post_metabox',
                $metabox_title,
                array( $this, 'render' ),
                $type,
                'side',
                'default'
            );
        }
    }

    // =====================================================================
    // Render
    // =====================================================================

    /**
     * Render the metabox content.
     *
     * @param WP_Post $post Current post object.
     */
    public function render( $post ) {
        $post_id    = $post->ID;
        $shortlink  = get_post_meta( $post_id, '_wphoula_shortlink', true );
        $link_id    = get_post_meta( $post_id, '_wphoula_link_id', true );
        $qrcode     = get_post_meta( $post_id, '_wphoula_qrcode', true );
        $qrcode_svg = get_post_meta( $post_id, '_wphoula_qrcode_svg', true );
        $qrcode_png = get_post_meta( $post_id, '_wphoula_qrcode_png', true );
        $nonce      = wp_create_nonce( 'wphoula_post_metabox' );

        ?>
        <div class="wphoula-post-metabox" data-post-id="<?php echo esc_attr( $post_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">

            <?php if ( $shortlink ) : ?>

                <!-- Short link -->
                <div class="wphoula-shortlink-row">
                    <input type="text" readonly value="<?php echo esc_attr( $shortlink ); ?>"
                           class="wphoula-shortlink-input" id="wphoula-shortlink-input">
                    <button type="button" class="button button-small" id="wphoula-copy-link"
                            title="<?php esc_attr_e( 'Copy', 'wp-houla' ); ?>">
                        <span class="dashicons dashicons-clipboard"></span>
                    </button>
                </div>

                <!-- QR Code -->
                <?php if ( $qrcode ) : ?>
                    <div class="wphoula-qrcode-preview">
                        <img src="<?php echo esc_attr( $qrcode ); ?>"
                             alt="QR Code" width="140" height="140"
                             class="wphoula-qrcode-img">
                        <div class="wphoula-qrcode-downloads">
                            <?php if ( $qrcode_svg ) : ?>
                                <a href="<?php echo esc_attr( $qrcode_svg ); ?>" download="qrcode.svg" class="button button-small">
                                    <span class="dashicons dashicons-download"></span> SVG
                                </a>
                            <?php endif; ?>
                            <?php if ( $qrcode_png ) : ?>
                                <a href="<?php echo esc_attr( $qrcode_png ); ?>" download="qrcode.png" class="button button-small">
                                    <span class="dashicons dashicons-download"></span> PNG
                                </a>
                            <?php endif; ?>
                            <?php if ( ! $qrcode_svg && ! $qrcode_png ) : ?>
                                <a href="<?php echo esc_attr( $qrcode ); ?>" download="qrcode" class="button button-small">
                                    <span class="dashicons dashicons-download"></span>
                                    <?php esc_html_e( 'Download QR', 'wp-houla' ); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Click stats (loaded via AJAX) -->
                <div class="wphoula-post-stats" id="wphoula-post-stats" style="display:none;">
                    <table class="wphoula-stats-table">
                        <tr>
                            <td><?php esc_html_e( 'Total clicks', 'wp-houla' ); ?></td>
                            <td class="wphoula-stat-value" id="wphoula-post-stat-clicks">-</td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'Today', 'wp-houla' ); ?></td>
                            <td class="wphoula-stat-value" id="wphoula-post-stat-today">-</td>
                        </tr>
                        <tr>
                            <td><?php esc_html_e( 'QR scans', 'wp-houla' ); ?></td>
                            <td class="wphoula-stat-value" id="wphoula-post-stat-qr">-</td>
                        </tr>
                    </table>
                    <!-- 7-day performance sparkline -->
                    <div class="wphoula-chart-wrapper" id="wphoula-chart-wrapper" style="display:none; margin-top: 8px;">
                        <p class="wphoula-chart-label"><?php esc_html_e( '7-day performance', 'wp-houla' ); ?></p>
                        <canvas id="wphoula-sparkline" width="260" height="60"></canvas>
                    </div>
                </div>

                <!-- Actions -->
                <div class="wphoula-metabox__actions">
                    <button type="button" class="button button-small" id="wphoula-regenerate-link">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e( 'Regenerate', 'wp-houla' ); ?>
                    </button>
                </div>

            <?php elseif ( 'publish' === $post->post_status ) : ?>

                <!-- Not yet generated -->
                <p class="description">
                    <?php esc_html_e( 'No Hou.la short link yet.', 'wp-houla' ); ?>
                </p>
                <div class="wphoula-metabox__actions">
                    <button type="button" class="button button-primary button-small" id="wphoula-generate-link">
                        <span class="dashicons dashicons-admin-links"></span>
                        <?php esc_html_e( 'Generate short link', 'wp-houla' ); ?>
                    </button>
                </div>

            <?php else : ?>

                <p class="description">
                    <?php esc_html_e( 'A Hou.la short link will be generated when this post is published.', 'wp-houla' ); ?>
                </p>

            <?php endif; ?>

            <span class="spinner wphoula-metabox__spinner" id="wphoula-post-spinner"></span>
        </div>
        <?php
    }

    // =====================================================================
    // AJAX handlers
    // =====================================================================

    /**
     * AJAX: Generate or regenerate shortlink for a post.
     */
    public function ajax_generate_shortlink() {
        check_ajax_referer( 'wphoula_post_metabox', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        $force   = ! empty( $_POST['force'] );

        if ( ! $post_id ) {
            wp_send_json_error( __( 'Invalid post ID.', 'wp-houla' ) );
        }

        $shortlink_gen = new Wp_Houla_Shortlink();
        $shortlink     = $shortlink_gen->generate_shortlink( $post_id, $force );

        if ( ! $shortlink ) {
            wp_send_json_error( __( 'Failed to generate short link.', 'wp-houla' ) );
        }

        wp_send_json_success( array(
            'shortlink' => $shortlink,
            'qrcode'    => get_post_meta( $post_id, '_wphoula_qrcode', true ),
        ) );
    }

    /**
     * AJAX: Fetch click stats for a post's short link.
     * Uses the export/summary endpoint which includes daily breakdowns.
     */
    public function ajax_get_link_stats() {
        check_ajax_referer( 'wphoula_post_metabox', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $post_id = absint( $_POST['post_id'] ?? 0 );
        $link_id = get_post_meta( $post_id, '_wphoula_link_id', true );

        if ( empty( $link_id ) ) {
            wp_send_json_error( __( 'No link found.', 'wp-houla' ) );
        }

        // Get 7-day summary with daily breakdown
        $from = gmdate( 'Y-m-d', strtotime( '-7 days' ) );
        $to   = gmdate( 'Y-m-d' );

        $stats = $this->api->get( '/hit/export/' . $link_id, array(
            'format' => 'json',
            'from'   => $from,
            'to'     => $to,
        ) );

        if ( is_wp_error( $stats ) ) {
            wp_send_json_error( $stats->get_error_message() );
        }

        // Build response with clicks today
        $today_str    = gmdate( 'Y-m-d' );
        $clicks_today = 0;
        $qr_today     = 0;
        $by_day       = isset( $stats['byDay'] ) ? $stats['byDay'] : array();

        foreach ( $by_day as $day ) {
            if ( isset( $day['date'] ) && $day['date'] === $today_str ) {
                $clicks_today = isset( $day['clicks'] ) ? (int) $day['clicks'] : 0;
                $qr_today     = isset( $day['flashs'] ) ? (int) $day['flashs'] : 0;
            }
        }

        wp_send_json_success( array(
            'totalClicks' => isset( $stats['totalClicks'] ) ? (int) $stats['totalClicks'] : 0,
            'clicksToday' => $clicks_today,
            'qrScans'     => $qr_today,
            'byDay'       => $by_day,
        ) );
    }
}
