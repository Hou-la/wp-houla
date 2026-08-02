<?php
/**
 * Automatic short link generation for WordPress posts.
 *
 * Like wp-bitly but using the Hou.la API:
 * - Hooks into pre_get_shortlink to generate Hou.la short links
 * - Works on all public post types (posts, pages, products, ...)
 * - Stores the short link in post_meta (_wphoula_shortlink)
 * - Also generates a QR code URL stored in _wphoula_qrcode
 * - Provides a [wphoula] shortcode
 *
 * @since      1.0.0
 * @package    Wp_Houla
 * @subpackage Wp_Houla/includes
 */

class Wp_Houla_Shortlink {

    /** @var Wp_Houla_Api */
    private $api;

    /** @var Wp_Houla_Auth */
    private $auth;

    /** @var Wp_Houla_Options */
    private $options;

    public function __construct() {
        $this->api     = new Wp_Houla_Api();
        $this->auth    = new Wp_Houla_Auth();
        $this->options = new Wp_Houla_Options();
    }

    // =====================================================================
    // Shortlink generation
    // =====================================================================

    /**
     * Generate a Hou.la short link for a given post.
     *
     * Calls POST /link to create the short link + QR code.
     *
     * @param int  $post_id The post ID.
     * @param bool $force   Force regeneration even if one exists.
     * @return string|false Short link URL or false on failure.
     */
    public function generate_shortlink( $post_id, $force = false ) {
        $this->log( 'generate_shortlink() called for post #' . $post_id . ' (force=' . ( $force ? 'true' : 'false' ) . ')' );

        if ( ! $this->auth->is_connected() ) {
            $this->log( 'Skipped post #' . $post_id . ': not connected to Hou.la' );
            return false;
        }

        $status = get_post_status( $post_id );
        // Only published/future/private posts
        if ( ! in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
            $this->log( 'Skipped post #' . $post_id . ': status is "' . $status . '"' );
            return false;
        }

        // Check if already exists (and not forcing)
        $existing = get_post_meta( $post_id, '_wphoula_shortlink', true );
        if ( ! empty( $existing ) && ! $force ) {
            return $existing;
        }

        $permalink = get_permalink( $post_id );
        $title     = get_the_title( $post_id );

        $result = $this->api->post( '/link', array(
            'url'   => $permalink,
            'title' => $title,
        ) );

        if ( is_wp_error( $result ) ) {
            $this->log( 'Failed to generate shortlink for post #' . $post_id . ': ' . $result->get_error_message() );
            return false;
        }

        $shortlink = isset( $result['shortUrl'] ) ? $result['shortUrl'] : '';
        $link_id   = isset( $result['id'] ) ? $result['id'] : '';

        if ( empty( $shortlink ) ) {
            $this->log( 'API returned no shortUrl for post #' . $post_id . '. Response: ' . wp_json_encode( $result ) );
            return false;
        }

        // Store in post meta
        update_post_meta( $post_id, '_wphoula_shortlink', $shortlink );
        update_post_meta( $post_id, '_wphoula_link_id', $link_id );

        // Fetch QR code images from API (SVG styled + PNG fallback)
        if ( ! empty( $link_id ) ) {
            $qr = $this->fetch_qrcode( $link_id );
            if ( $qr && ! empty( $qr['svg'] ) ) {
                update_post_meta( $post_id, '_wphoula_qrcode', $qr['svg'] );
                update_post_meta( $post_id, '_wphoula_qrcode_svg', $qr['svg'] );
                update_post_meta( $post_id, '_wphoula_qrcode_png', $qr['png'] ?? '' );
            } elseif ( $qr && ! empty( $qr['png'] ) ) {
                update_post_meta( $post_id, '_wphoula_qrcode', $qr['png'] );
                update_post_meta( $post_id, '_wphoula_qrcode_png', $qr['png'] );
                delete_post_meta( $post_id, '_wphoula_qrcode_svg' );
            } else {
                $this->log( 'Failed to fetch QR code for post #' . $post_id );
                delete_post_meta( $post_id, '_wphoula_qrcode' );
                delete_post_meta( $post_id, '_wphoula_qrcode_svg' );
                delete_post_meta( $post_id, '_wphoula_qrcode_png' );
            }
        }

        $this->log( 'Shortlink generated for post #' . $post_id . ': ' . $shortlink );

        return $shortlink;
    }

    // =====================================================================
    // QR code generation
    // =====================================================================

    /**
     * Fetch QR code in both SVG (styled) and PNG (basic) formats.
     *
     * The API automatically applies the user's default template styles
     * (rounded dots, custom corners, gradients) when no custom colors are specified.
     * The styled result is an SVG. We also fetch a basic PNG for compatibility.
     *
     * @param string $link_id UUID of the created link.
     * @return array|false  Array with 'svg' and 'png' data URLs, or false on failure.
     */
    private function fetch_qrcode( $link_id ) {
        $result = array( 'svg' => '', 'png' => '' );

        // 1) Styled QR code (SVG) — uses default template automatically
        $svg_result = $this->api->get( '/link/' . $link_id . '/qrcode', array( 'width' => '300' ) );
        if ( ! is_wp_error( $svg_result ) && ! empty( $svg_result['dataUrl'] ) ) {
            $result['svg'] = $svg_result['dataUrl'];
        }

        // 2) Basic PNG QR code — pass explicit format=png with white colors to force basic rendering
        $png_result = $this->api->get( '/link/' . $link_id . '/qrcode', array(
            'format' => 'png',
            'width'  => '300',
            'darkColor'  => '#000000',
            'lightColor' => '#FFFFFF',
        ) );
        if ( ! is_wp_error( $png_result ) && ! empty( $png_result['dataUrl'] ) ) {
            $result['png'] = $png_result['dataUrl'];
        }

        if ( empty( $result['svg'] ) && empty( $result['png'] ) ) {
            $this->log( 'QR code API returned no data for link ' . $link_id );
            return false;
        }

        return $result;
    }

    // =====================================================================
    // WordPress shortlink filter
    // =====================================================================

    /**
     * Filter for pre_get_shortlink. Returns the Hou.la short link
     * instead of the default WordPress one.
     *
     * @param string $shortlink  Default shortlink (or false).
     * @param int    $id         Post ID or 0.
     * @param string $context    Context (e.g. 'post').
     * @param bool   $allow_slugs Whether to allow slugs.
     * @return string|false
     */
    public function filter_get_shortlink( $shortlink, $id, $context, $allow_slugs ) {
        // Skip bulk edits
        if ( isset( $_GET['bulk_edit'] ) ) {
            return $shortlink;
        }

        // Skip autosaves
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return $shortlink;
        }

        if ( ! $this->auth->is_connected() ) {
            return $shortlink;
        }

        // Resolve post ID
        $post_id = $id;
        if ( 0 === $post_id ) {
            $post = get_post();
            if ( is_object( $post ) && ! empty( $post->ID ) ) {
                $post_id = $post->ID;
            }
        }

        if ( ! $post_id || wp_is_post_revision( $post_id ) ) {
            return $shortlink;
        }

        // Check allowed post types
        $allowed_types = $this->get_allowed_post_types();
        if ( ! in_array( get_post_type( $post_id ), $allowed_types, true ) ) {
            return $shortlink;
        }

        // Try to get existing shortlink
        $houla_link = get_post_meta( $post_id, '_wphoula_shortlink', true );

        // Generate if not found and post is published
        if ( empty( $houla_link ) && in_array( get_post_status( $post_id ), array( 'publish', 'future', 'private' ), true ) ) {
            $houla_link = $this->generate_shortlink( $post_id );
        }

        return $houla_link ?: $shortlink;
    }

    // =====================================================================
    // Save post hook
    // =====================================================================

    /**
     * Generate shortlink when a post is published.
     * Hooked to save_post with priority 20 (after other plugins).
     *
     * @param int     $post_id Post ID.
     * @param WP_Post $post    Post object.
     * @param bool    $update  Whether this is an update.
     */
    public function on_save_post( $post_id, $post, $update ) {
        // Skip autosaves
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }

        // Skip revisions
        if ( wp_is_post_revision( $post_id ) ) {
            return;
        }

        if ( ! $this->auth->is_connected() ) {
            $this->log( 'on_save_post: skipped post #' . $post_id . ' — not connected' );
            return;
        }

        // Only for allowed post types
        $allowed_types = $this->get_allowed_post_types();
        if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
            $this->log( 'on_save_post: skipped post #' . $post_id . ' — type "' . $post->post_type . '" not in allowed types [' . implode( ', ', $allowed_types ) . ']' );
            return;
        }

        // Only for published posts
        if ( 'publish' !== $post->post_status ) {
            $this->log( 'on_save_post: skipped post #' . $post_id . ' — status is "' . $post->post_status . '"' );
            return;
        }

        $this->log( 'on_save_post: generating shortlink for post #' . $post_id . ' (type=' . $post->post_type . ')' );

        // Generate (won't overwrite if already exists)
        $this->generate_shortlink( $post_id );
    }

    /**
     * Generate shortlink when a post transitions to 'publish'.
     * This is more reliable than save_post for Gutenberg/block editor
     * where REST API saves may fire save_post before the status change.
     *
     * Hooked to transition_post_status.
     *
     * @param string  $new_status New post status.
     * @param string  $old_status Old post status.
     * @param WP_Post $post       Post object.
     */
    public function on_transition_post_status( $new_status, $old_status, $post ) {
        // Only when transitioning TO publish
        if ( 'publish' !== $new_status ) {
            return;
        }

        // Skip if already publish → publish (save_post handles that)
        if ( 'publish' === $old_status ) {
            return;
        }

        if ( ! $this->auth->is_connected() ) {
            return;
        }

        // Only for allowed post types
        $allowed_types = $this->get_allowed_post_types();
        if ( ! in_array( $post->post_type, $allowed_types, true ) ) {
            return;
        }

        // Generate (won't overwrite if already exists)
        $this->generate_shortlink( $post->ID );
    }

    /**
     * Generate shortlink when a WooCommerce product is created or updated.
     * WooCommerce products don't always trigger save_post reliably,
     * so we hook into woocommerce_new_product / woocommerce_update_product.
     *
     * @param int        $product_id Product (post) ID.
     * @param WC_Product $product    WooCommerce product object (optional).
     */
    public function on_woocommerce_product_save( $product_id, $product = null ) {
        if ( ! $this->auth->is_connected() ) {
            return;
        }

        $allowed_types = $this->get_allowed_post_types();
        if ( ! in_array( 'product', $allowed_types, true ) ) {
            return;
        }

        $post = get_post( $product_id );
        if ( ! $post || 'publish' !== $post->post_status ) {
            return;
        }

        // Generate (won't overwrite if already exists)
        $this->generate_shortlink( $product_id );
    }

    // =====================================================================
    // Shortcode [wphoula]
    // =====================================================================

    /**
     * Register the [wphoula] shortcode.
     */
    public function register_shortcode() {
        add_shortcode( 'wphoula', array( $this, 'render_shortcode' ) );
    }

    /**
     * Render the [wphoula] shortcode.
     *
     * Usage: [wphoula] or [wphoula text="Click here" post_id="42"]
     *
     * @param array $atts Shortcode attributes.
     * @return string HTML output.
     */
    public function render_shortcode( $atts = array() ) {
        $defaults = array(
            'text'    => '',
            'title'   => '',
            'before'  => '',
            'after'   => '',
            'post_id' => get_the_ID(),
            'qrcode'  => false,
        );

        $atts = shortcode_atts( $defaults, $atts, 'wphoula' );

        $post_id = absint( $atts['post_id'] );
        if ( ! $post_id ) {
            return '';
        }

        // QR code mode
        if ( $atts['qrcode'] ) {
            $qr_url = get_post_meta( $post_id, '_wphoula_qrcode', true );
            if ( ! empty( $qr_url ) ) {
                return sprintf(
                    '<img src="%s" alt="%s" class="wphoula-qrcode" width="200" height="200">',
                    esc_url( $qr_url ),
                    esc_attr( get_the_title( $post_id ) )
                );
            }
            return '';
        }

        // Short link mode
        $shortlink = $this->filter_get_shortlink( '', $post_id, 'post', false );
        if ( empty( $shortlink ) ) {
            return '';
        }

        $text  = ! empty( $atts['text'] ) ? $atts['text'] : $shortlink;
        $title = ! empty( $atts['title'] ) ? $atts['title'] : get_the_title( $post_id );

        $output = sprintf(
            '<a rel="shortlink" href="%s" title="%s">%s</a>',
            esc_url( $shortlink ),
            esc_attr( $title ),
            esc_html( $text )
        );

        return wp_kses_post( $atts['before'] ) . $output . wp_kses_post( $atts['after'] );
    }

    // =====================================================================
    // Utilities
    // =====================================================================

    /**
     * Get the list of post types that should have shortlinks generated.
     *
     * @return array
     */
    private function get_allowed_post_types() {
        $all_types = get_post_types( array( 'public' => true ) );
        $all_types = array_values( $all_types );

        // Check for user-configured post types
        $options = new Wp_Houla_Options();
        $saved = $options->get( 'allowed_post_types' );
        if ( is_array( $saved ) && ! empty( $saved ) ) {
            $all_types = array_intersect( $all_types, $saved );
        }

        return apply_filters( 'wphoula_allowed_post_types', array_values( $all_types ) );
    }

    /**
     * Log a debug message.
     * Always logs when WP_DEBUG is enabled.
     * Also logs to a dedicated wp-houla.log file when WP_DEBUG_LOG is enabled.
     *
     * @param string $message
     */
    private function log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WP-Houla Shortlink] ' . $message );
        }

        // Also log to a dedicated plugin log file
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            $log_file = WPHOULA_DIR . '/debug.log';
            $timestamp = gmdate( 'Y-m-d H:i:s' );
            file_put_contents( $log_file, '[' . $timestamp . '] ' . $message . "\n", FILE_APPEND );
        }
    }
}
