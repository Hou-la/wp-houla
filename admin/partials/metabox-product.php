<?php
/**
 * Product metabox template.
 *
 * Rendered inside the Hou.la metabox on the WooCommerce product edit screen.
 *
 * Available variables:
 *   $product_id - int
 *   $houla_id   - string|empty
 *   $synced     - string (1 or empty)
 *   $sync_at    - string (datetime or empty)
 *   $nonce      - string (wphoula_metabox nonce)
 *
 * @since      1.0.0
 * @package    Wp_Houla
 * @subpackage Wp_Houla/admin/partials
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Shortlink + QR code (also available on products)
$shortlink = get_post_meta( $product_id, '_wphoula_shortlink', true );
$qrcode    = get_post_meta( $product_id, '_wphoula_qrcode', true );
?>

<div class="wphoula-metabox" data-product-id="<?php echo esc_attr( $product_id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>">

    <?php if ( $synced && $houla_id ) : ?>

        <!-- Synced state -->
        <div class="wphoula-metabox__status wphoula-metabox__status--synced">
            <span class="dashicons dashicons-yes-alt"></span>
            <?php esc_html_e( 'Synced with Hou.la', 'wp-houla' ); ?>
        </div>

        <?php if ( $sync_at ) : ?>
            <p class="wphoula-metabox__date">
                <?php
                printf(
                    /* translators: %s: datetime */
                    esc_html__( 'Last sync: %s', 'wp-houla' ),
                    esc_html( $sync_at )
                );
                ?>
            </p>
        <?php endif; ?>

        <!-- Stats (loaded via AJAX) -->
        <div class="wphoula-metabox__stats" id="wphoula-stats" style="display:none;">
            <table class="wphoula-stats-table">
                <tr>
                    <td><?php esc_html_e( 'Views', 'wp-houla' ); ?></td>
                    <td class="wphoula-stat-value" id="wphoula-stat-views">-</td>
                </tr>
                <tr>
                    <td><?php esc_html_e( 'Clicks', 'wp-houla' ); ?></td>
                    <td class="wphoula-stat-value" id="wphoula-stat-clicks">-</td>
                </tr>
                <tr>
                    <td><?php esc_html_e( 'Sales', 'wp-houla' ); ?></td>
                    <td class="wphoula-stat-value" id="wphoula-stat-sales">-</td>
                </tr>
                <tr>
                    <td><?php esc_html_e( 'Revenue', 'wp-houla' ); ?></td>
                    <td class="wphoula-stat-value" id="wphoula-stat-revenue">-</td>
                </tr>
            </table>
        </div>

        <!-- Actions -->
        <div class="wphoula-metabox__actions">
            <button type="button" class="button button-small" id="wphoula-resync">
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e( 'Re-sync', 'wp-houla' ); ?>
            </button>
            <button type="button" class="button button-small button-link-delete" id="wphoula-unsync">
                <?php esc_html_e( 'Unsync', 'wp-houla' ); ?>
            </button>
        </div>

        <!-- Short link + QR code section -->
        <?php if ( $shortlink ) : ?>
            <hr class="wphoula-divider">
            <div class="wphoula-shortlink-row">
                <input type="text" readonly value="<?php echo esc_attr( $shortlink ); ?>"
                       class="wphoula-shortlink-input" id="wphoula-shortlink-input">
                <button type="button" class="button button-small" id="wphoula-copy-link"
                        title="<?php esc_attr_e( 'Copy', 'wp-houla' ); ?>">
                    <span class="dashicons dashicons-clipboard"></span>
                </button>
            </div>
            <?php
                $qrcode_svg = get_post_meta( $product_id, '_wphoula_qrcode_svg', true );
                $qrcode_png = get_post_meta( $product_id, '_wphoula_qrcode_png', true );
            ?>
            <?php if ( $qrcode ) : ?>
                <div class="wphoula-qrcode-preview">
                    <img src="<?php echo esc_attr( $qrcode ); ?>" alt="QR Code" width="120" height="120" class="wphoula-qrcode-img">
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
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>

    <?php else : ?>

        <!-- Not synced state -->
        <div class="wphoula-metabox__status wphoula-metabox__status--not-synced">
            <span class="dashicons dashicons-minus"></span>
            <?php esc_html_e( 'Not synced with Hou.la', 'wp-houla' ); ?>
        </div>

        <p class="description">
            <?php esc_html_e( 'Push this product to Hou.la to display it on your bio page.', 'wp-houla' ); ?>
        </p>

        <div class="wphoula-metabox__actions">
            <button type="button" class="button button-primary button-small" id="wphoula-sync-now">
                <span class="dashicons dashicons-upload"></span>
                <?php esc_html_e( 'Sync to Hou.la', 'wp-houla' ); ?>
            </button>
        </div>

    <?php endif; ?>

    <span class="spinner wphoula-metabox__spinner" id="wphoula-spinner"></span>
</div>
