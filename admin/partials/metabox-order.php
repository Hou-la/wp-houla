<?php
/**
 * Order shipping metabox template.
 *
 * Rendered inside the Hou.la metabox on the WooCommerce order edit screen.
 * Provides: generate label, download PDF, cancel label.
 *
 * Available variables:
 *   $wc_order_id    - int    WooCommerce order ID
 *   $houla_order_id - string Hou.la order UUID (or empty)
 *   $has_label      - bool   Whether a label exists (cached)
 *   $nonce          - string wphoula_order_metabox nonce
 *
 * @since      1.5.0
 * @package    Wp_Houla
 * @subpackage Wp_Houla/admin/partials
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<div class="wphoula-order-metabox"
     data-order-id="<?php echo esc_attr( $wc_order_id ); ?>"
     data-houla-order-id="<?php echo esc_attr( $houla_order_id ); ?>"
     data-nonce="<?php echo esc_attr( $nonce ); ?>">

    <?php if ( empty( $houla_order_id ) ) : ?>

        <p class="wphoula-order-metabox__notice">
            <span class="dashicons dashicons-info-outline"></span>
            <?php esc_html_e( 'This order is not linked to Hou.la.', 'wp-houla' ); ?>
        </p>

    <?php else : ?>

        <!-- Status line -->
        <p class="wphoula-order-metabox__status">
            <span class="dashicons dashicons-yes-alt" style="color:#22c55e;"></span>
            <?php esc_html_e( 'Synced with Hou.la', 'wp-houla' ); ?>
        </p>

        <!-- Loading indicator (shown during AJAX) -->
        <div class="wphoula-order-metabox__loading" id="wphoula-label-loading" style="display:none;">
            <span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
            <?php esc_html_e( 'Processing…', 'wp-houla' ); ?>
        </div>

        <!-- Message area -->
        <div id="wphoula-label-message" style="display:none;" class="wphoula-order-metabox__message"></div>

        <!-- Label actions: No label state -->
        <div id="wphoula-no-label" style="<?php echo $has_label ? 'display:none;' : ''; ?>">
            <p class="description"><?php esc_html_e( 'Generate a shipping label via Sendcloud.', 'wp-houla' ); ?></p>
            <div class="wphoula-order-metabox__format">
                <label>
                    <input type="radio" name="wphoula_label_format" value="10x15" checked> 10×15 cm
                </label>
                <label style="margin-left: 12px;">
                    <input type="radio" name="wphoula_label_format" value="a4"> A4
                </label>
            </div>
            <p>
                <button type="button" class="button button-primary" id="wphoula-generate-label" style="width:100%;">
                    <span class="dashicons dashicons-tag" style="vertical-align:middle;margin-right:4px;"></span>
                    <?php esc_html_e( 'Generate label', 'wp-houla' ); ?>
                </button>
            </p>
            <p>
                <button type="button" class="button" id="wphoula-generate-and-print" style="width:100%;">
                    <span class="dashicons dashicons-printer" style="vertical-align:middle;margin-right:4px;"></span>
                    <?php esc_html_e( 'Generate & Print (Hou.la Print)', 'wp-houla' ); ?>
                </button>
            </p>
        </div>

        <!-- Label actions: Has label state -->
        <div id="wphoula-has-label" style="<?php echo $has_label ? '' : 'display:none;'; ?>">
            <p class="wphoula-order-metabox__status wphoula-order-metabox__status--label">
                <span class="dashicons dashicons-media-document" style="color:#3b82f6;"></span>
                <?php esc_html_e( 'Shipping label ready', 'wp-houla' ); ?>
            </p>
            <p>
                <button type="button" class="button button-primary" id="wphoula-print-label" style="width:100%;">
                    <span class="dashicons dashicons-printer" style="vertical-align:middle;margin-right:4px;"></span>
                    <?php esc_html_e( 'Print label (Hou.la Print)', 'wp-houla' ); ?>
                </button>
            </p>
            <p>
                <button type="button" class="button" id="wphoula-download-label" style="width:100%;">
                    <span class="dashicons dashicons-download" style="vertical-align:middle;margin-right:4px;"></span>
                    <?php esc_html_e( 'Download label (PDF)', 'wp-houla' ); ?>
                </button>
            </p>
            <p>
                <button type="button" class="button button-link-delete" id="wphoula-cancel-label" style="width:100%;">
                    <span class="dashicons dashicons-no-alt" style="vertical-align:middle;margin-right:4px;"></span>
                    <?php esc_html_e( 'Cancel label', 'wp-houla' ); ?>
                </button>
            </p>
        </div>

        <!-- Refresh status button -->
        <p style="margin-top:8px;">
            <button type="button" class="button button-small" id="wphoula-refresh-label-status" style="width:100%;">
                <span class="dashicons dashicons-update" style="vertical-align:middle;margin-right:4px;"></span>
                <?php esc_html_e( 'Refresh status', 'wp-houla' ); ?>
            </button>
        </p>

    <?php endif; ?>
</div>

<style>
    .wphoula-order-metabox__notice {
        color: #666;
        font-style: italic;
    }
    .wphoula-order-metabox__status {
        margin: 4px 0 8px;
    }
    .wphoula-order-metabox__status--label {
        background: #eff6ff;
        padding: 6px 10px;
        border-radius: 4px;
        border-left: 3px solid #3b82f6;
    }
    .wphoula-order-metabox__format {
        margin: 8px 0;
    }
    .wphoula-order-metabox__loading {
        padding: 8px 0;
        color: #666;
    }
    .wphoula-order-metabox__message {
        padding: 6px 10px;
        border-radius: 4px;
        margin: 8px 0;
    }
    .wphoula-order-metabox__message--success {
        background: #ecfdf5;
        color: #065f46;
        border-left: 3px solid #22c55e;
    }
    .wphoula-order-metabox__message--error {
        background: #fef2f2;
        color: #991b1b;
        border-left: 3px solid #ef4444;
    }
</style>

<script>
(function($) {
    'use strict';

    var $box      = $('.wphoula-order-metabox');
    var orderId   = $box.data('order-id');
    var nonce     = $box.data('nonce');
    var $loading  = $('#wphoula-label-loading');
    var $msg      = $('#wphoula-label-message');
    var $noLabel  = $('#wphoula-no-label');
    var $hasLabel = $('#wphoula-has-label');

    function showLoading() {
        $loading.show();
        $msg.hide();
    }

    function hideLoading() {
        $loading.hide();
    }

    function showMessage(text, type) {
        $msg.text(text)
            .removeClass('wphoula-order-metabox__message--success wphoula-order-metabox__message--error')
            .addClass('wphoula-order-metabox__message--' + type)
            .show();
    }

    function switchToHasLabel() {
        $noLabel.hide();
        $hasLabel.show();
    }

    function switchToNoLabel() {
        $noLabel.show();
        $hasLabel.hide();
    }

    // Generate label
    $('#wphoula-generate-label').on('click', function() {
        var format = $('input[name="wphoula_label_format"]:checked').val();
        showLoading();
        $.post(ajaxurl, {
            action: 'wphoula_generate_label',
            order_id: orderId,
            format: format,
            nonce: nonce
        }, function(response) {
            hideLoading();
            if (response.success) {
                showMessage(response.data.message, 'success');
                switchToHasLabel();
            } else {
                showMessage(response.data || 'Error', 'error');
            }
        }).fail(function() {
            hideLoading();
            showMessage('Network error', 'error');
        });
    });

    // Download label
    $('#wphoula-download-label').on('click', function() {
        showLoading();
        $.post(ajaxurl, {
            action: 'wphoula_get_label_url',
            order_id: orderId,
            nonce: nonce
        }, function(response) {
            hideLoading();
            if (response.success && response.data.download_url) {
                window.open(response.data.download_url, '_blank');
            } else {
                showMessage(response.data || 'Error', 'error');
            }
        }).fail(function() {
            hideLoading();
            showMessage('Network error', 'error');
        });
    });

    // Cancel label
    $('#wphoula-cancel-label').on('click', function() {
        if (!confirm('<?php echo esc_js( __( 'Cancel this shipping label? This cannot be undone.', 'wp-houla' ) ); ?>')) {
            return;
        }
        showLoading();
        $.post(ajaxurl, {
            action: 'wphoula_cancel_label',
            order_id: orderId,
            nonce: nonce
        }, function(response) {
            hideLoading();
            if (response.success) {
                showMessage(response.data.message, 'success');
                switchToNoLabel();
            } else {
                showMessage(response.data || 'Error', 'error');
            }
        }).fail(function() {
            hideLoading();
            showMessage('Network error', 'error');
        });
    });

    // Generate & Print (auto-generates label then sends to houla-print)
    $('#wphoula-generate-and-print').on('click', function() {
        var format = $('input[name="wphoula_label_format"]:checked').val();
        showLoading();
        // Step 1: generate label
        $.post(ajaxurl, {
            action: 'wphoula_generate_label',
            order_id: orderId,
            format: format,
            nonce: nonce
        }, function(response) {
            if (response.success) {
                switchToHasLabel();
                // Step 2: send to printer
                $.post(ajaxurl, {
                    action: 'wphoula_print_label',
                    order_id: orderId,
                    nonce: nonce
                }, function(printResponse) {
                    hideLoading();
                    if (printResponse.success) {
                        showMessage(printResponse.data.message, 'success');
                    } else {
                        showMessage(printResponse.data || 'Print error', 'error');
                    }
                }).fail(function() {
                    hideLoading();
                    showMessage('<?php echo esc_js( __( 'Label generated but print failed. You can download the PDF instead.', 'wp-houla' ) ); ?>', 'error');
                });
            } else {
                hideLoading();
                showMessage(response.data || 'Error', 'error');
            }
        }).fail(function() {
            hideLoading();
            showMessage('Network error', 'error');
        });
    });

    // Print label (send to houla-print desktop agent)
    $('#wphoula-print-label').on('click', function() {
        showLoading();
        $.post(ajaxurl, {
            action: 'wphoula_print_label',
            order_id: orderId,
            nonce: nonce
        }, function(response) {
            hideLoading();
            if (response.success) {
                showMessage(response.data.message, 'success');
            } else {
                showMessage(response.data || 'Error', 'error');
            }
        }).fail(function() {
            hideLoading();
            showMessage('Network error', 'error');
        });
    });

    // Refresh status
    $('#wphoula-refresh-label-status').on('click', function() {
        showLoading();
        $.post(ajaxurl, {
            action: 'wphoula_check_label_status',
            order_id: orderId,
            nonce: nonce
        }, function(response) {
            hideLoading();
            if (response.success) {
                if (response.data.has_label) {
                    switchToHasLabel();
                    showMessage('<?php echo esc_js( __( 'Label is ready.', 'wp-houla' ) ); ?>', 'success');
                } else {
                    switchToNoLabel();
                    showMessage('<?php echo esc_js( __( 'No label yet.', 'wp-houla' ) ); ?>', 'success');
                }
            } else {
                showMessage(response.data || 'Error', 'error');
            }
        }).fail(function() {
            hideLoading();
            showMessage('Network error', 'error');
        });
    });

})(jQuery);
</script>
