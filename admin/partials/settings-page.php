<?php
/**
 * Admin settings page template.
 *
 * Available variables:
 *   $options - Wp_Houla_Options instance
 *   $auth    - Wp_Houla_Auth instance
 *
 * @since      1.0.0
 * @package    Wp_Houla
 * @subpackage Wp_Houla/admin/partials
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$is_connected   = $auth->is_connected();
$workspace_name = $options->get( 'workspace_name' );
$user_email     = $options->get( 'user_email' );
$auto_sync      = $options->get( 'auto_sync' );
$sync_on_publish = $options->get( 'sync_on_publish' );
$debug          = $options->get( 'debug' );
$products_synced = $options->get( 'products_synced' );
$last_full_sync  = $options->get( 'last_full_sync' );
$orders_received = $options->get( 'orders_received' );
$last_order_at   = $options->get( 'last_order_at' );
$workspace_has_shop = (bool) $options->get( 'workspace_has_shop' );
?>

<div class="wrap wphoula-settings">
    <h1 class="wphoula-page-title">
        <img src="<?php echo esc_url( WPHOULA_URL . 'admin/images/houla-logo.png' ); ?>" alt="Hou.la" class="wphoula-logo-full">
    </h1>

    <?php if ( isset( $_GET['wphoula_connected'] ) && $_GET['wphoula_connected'] === '1' ) : ?>
        <div class="notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Successfully connected to Hou.la!', 'wp-houla' ); ?></p>
        </div>
    <?php endif; ?>

    <?php if ( function_exists( 'wphoula_is_dev_mode' ) && wphoula_is_dev_mode() ) : ?>
        <div class="notice notice-warning" style="border-left-color: #d63638; background: #fff2f2;">
            <p>
                <strong style="color: #d63638;">&#9888; DEV MODE</strong> &mdash;
                <?php
                printf(
                    esc_html__( 'API URL: %s', 'wp-houla' ),
                    '<code>' . esc_html( wphoula_get_api_url() ) . '</code>'
                );
                ?>
                <br>
                <small><?php esc_html_e( 'To disable: remove WPHOULA_DEV_MODE from wp-config.php and clear api_url.', 'wp-houla' ); ?></small>
            </p>
        </div>
    <?php endif; ?>

    <!-- ============================================================= -->
    <!-- Tab navigation                                                 -->
    <!-- ============================================================= -->
    <nav class="nav-tab-wrapper wphoula-tabs">
        <a href="#tab-connection" class="nav-tab nav-tab-active" data-tab="connection">
            <?php esc_html_e( 'Connection', 'wp-houla' ); ?>
        </a>
        <?php if ( $is_connected ) : ?>
        <a href="#tab-shortlinks" class="nav-tab" data-tab="shortlinks">
            <?php esc_html_e( 'Short Links & QR Code', 'wp-houla' ); ?>
        </a>
        <?php endif; ?>
        <?php if ( wphoula_is_woocommerce_active() && $workspace_has_shop ) : ?>
        <a href="#tab-sync" class="nav-tab" data-tab="sync">
            <?php esc_html_e( 'Sync. Produits', 'wp-houla' ); ?>
        </a>
        <a href="#tab-orders" class="nav-tab" data-tab="orders">
            <?php esc_html_e( 'Orders', 'wp-houla' ); ?>
        </a>
        <?php endif; ?>
        <a href="#tab-debug" class="nav-tab" data-tab="debug">
            <?php esc_html_e( 'Debug', 'wp-houla' ); ?>
        </a>
    </nav>

    <!-- ============================================================= -->
    <!-- TAB: Connection                                                -->
    <!-- ============================================================= -->
    <div class="wphoula-tab-content" id="tab-connection">
        <div class="wphoula-card">
            <h2><?php esc_html_e( 'Compte Hou.la', 'wp-houla' ); ?></h2>

            <?php if ( $is_connected ) : ?>
                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Statut', 'wp-houla' ); ?></th>
                        <td>
                            <span class="wphoula-status wphoula-status--connected">
                                <?php esc_html_e( 'Connecté', 'wp-houla' ); ?>
                            </span>
                        </td>
                    </tr>
                    <?php if ( $workspace_name ) : ?>
                    <tr>
                        <th><?php esc_html_e( 'Espace de travail', 'wp-houla' ); ?></th>
                        <td>
                            <div id="wphoula-workspace-selector" class="wphoula-workspace-selector">
                                <div class="wphoula-workspace-row">
                                    <select id="wphoula-workspace-select" disabled>
                                        <option value=""><?php echo esc_html( $workspace_name ); ?></option>
                                    </select>
                                    <button type="button" class="button button-small wphoula-btn-refresh" id="wphoula-workspace-refresh" title="<?php esc_attr_e( 'Rafraîchir la liste', 'wp-houla' ); ?>">
                                        <span class="dashicons dashicons-update"></span>
                                    </button>
                                    <span id="wphoula-workspace-loading" class="wphoula-workspace-loading">
                                        <span class="spinner is-active"></span>
                                    </span>
                                </div>
                                <div id="wphoula-switch-status" class="wphoula-switch-status"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php if ( $user_email ) : ?>
                    <tr>
                        <th><?php esc_html_e( 'E-mail', 'wp-houla' ); ?></th>
                        <td><?php echo esc_html( $user_email ); ?></td>
                    </tr>
                    <?php endif; ?>
                </table>

                <p>
                    <button type="button" class="button button-secondary" id="wphoula-disconnect">
                        <?php esc_html_e( 'Déconnexion', 'wp-houla' ); ?>
                    </button>
                </p>

            <?php else : ?>
                <p><?php esc_html_e( 'Connectez votre compte Hou.la pour générer des liens courts et QR codes sur vos articles.', 'wp-houla' ); ?></p>
                <p>
                    <a href="<?php echo esc_url( $auth->get_authorization_url() ); ?>" class="button button-primary button-hero">
                        <?php esc_html_e( 'Se connecter à Hou.la', 'wp-houla' ); ?>
                    </a>
                </p>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============================================================= -->
    <!-- TAB: Short Links & QR Code                                     -->
    <!-- ============================================================= -->
    <?php if ( $is_connected ) : ?>
    <div class="wphoula-tab-content" id="tab-shortlinks" style="display:none;">
        <div class="wphoula-card">
            <h2><?php esc_html_e( 'Shortlink Settings', 'wp-houla' ); ?></h2>
            <p class="description" style="margin-bottom: 12px;">
                <?php esc_html_e( 'Configure which content types get automatic Hou.la short links and QR codes.', 'wp-houla' ); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Post types', 'wp-houla' ); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e( 'Post types', 'wp-houla' ); ?></legend>
                            <?php
                            $allowed = $options->get( 'allowed_post_types' );
                            if ( ! is_array( $allowed ) ) {
                                $allowed = array();
                            }
                            $post_types = get_post_types( array( 'public' => true ), 'objects' );
                            foreach ( $post_types as $pt ) :
                                if ( 'attachment' === $pt->name ) continue;
                                $checked = in_array( $pt->name, $allowed, true );
                            ?>
                                <label style="display: block; margin-bottom: 4px;">
                                    <input type="checkbox" class="wphoula-post-type" value="<?php echo esc_attr( $pt->name ); ?>" <?php checked( $checked ); ?>>
                                    <?php echo esc_html( $pt->labels->name ); ?> <code>(<?php echo esc_html( $pt->name ); ?>)</code>
                                </label>
                            <?php endforeach; ?>
                            <p class="description"><?php esc_html_e( 'Short links and QR codes will be generated for selected post types.', 'wp-houla' ); ?></p>
                        </fieldset>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" class="button button-primary wphoula-save-settings-btn">
                    <?php esc_html_e( 'Save Settings', 'wp-houla' ); ?>
                </button>
            </p>
        </div>
    </div>
    <?php endif; ?>

    <!-- ============================================================= -->
    <!-- TAB: Sync (WooCommerce only)                                   -->
    <!-- ============================================================= -->
    <?php if ( wphoula_is_woocommerce_active() && $workspace_has_shop ) : ?>
    <div class="wphoula-tab-content" id="tab-sync" style="display:none;">
        <?php if ( ! $is_connected ) : ?>
        <div class="wphoula-card">
            <h2><?php esc_html_e( 'Product Sync', 'wp-houla' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Connect your account first to configure sync options.', 'wp-houla' ); ?></p>
        </div>
        <?php else : ?>
        <?php
        // Pre-fetch product categories (used in settings and mapping cards)
        $sync_categories = $options->get( 'sync_categories' );
        if ( ! is_array( $sync_categories ) ) {
            $sync_categories = array();
        }
        $product_cats = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );
        ?>
        <!-- ============================================================= -->
        <!-- Category -> Workspace mapping (multi-workspace routing)        -->
        <!-- ============================================================= -->
        <div class="wphoula-card" style="margin-top: 20px;">
            <h2><?php esc_html_e( 'Multi-Workspace Routing', 'wp-houla' ); ?></h2>
            <p class="description" style="margin-bottom: 12px;">
                <?php esc_html_e( 'Route products from specific WooCommerce categories to different Hou.la workspaces. Products in unmapped categories are synced to the default connected workspace.', 'wp-houla' ); ?>
            </p>

            <?php
            $category_workspace_map = $options->get( 'category_workspace_map' );
            if ( ! is_array( $category_workspace_map ) ) {
                $category_workspace_map = array();
            }
            ?>

            <div id="wphoula-workspace-map-container">
                <table class="wphoula-mapping-table widefat" id="wphoula-workspace-map-table">
                    <thead>
                        <tr>
                            <th style="width:25%;"><?php esc_html_e( 'WooCommerce Category', 'wp-houla' ); ?></th>
                            <th style="width:5%; text-align:center;">&#8594;</th>
                            <th style="width:25%;"><?php esc_html_e( 'Hou.la Workspace', 'wp-houla' ); ?></th>
                            <th style="width:15%;"><?php esc_html_e( 'API Key', 'wp-houla' ); ?></th>
                            <th style="width:15%;"><?php esc_html_e( 'Price Adjustment', 'wp-houla' ); ?></th>
                            <th style="width:15%;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        if ( ! empty( $product_cats ) && ! is_wp_error( $product_cats ) ) :
                            foreach ( $product_cats as $cat ) :
                                $has_mapping = isset( $category_workspace_map[ $cat->term_id ] );
                                $ws_entry    = $has_mapping ? $category_workspace_map[ $cat->term_id ] : array();
                                $ws_id       = $ws_entry['workspace_id'] ?? '';
                                $ws_name     = $ws_entry['workspace_name'] ?? '';
                                $has_key     = ! empty( $ws_entry['api_key'] );
                                $ws_adj_type = $ws_entry['price_adjustment_type'] ?? 'none';
                                $ws_adj_val  = $ws_entry['price_adjustment_value'] ?? 0;
                        ?>
                        <tr class="wphoula-ws-map-row <?php echo $has_mapping ? 'wphoula-ws-mapped' : ''; ?>"
                            data-cat-id="<?php echo esc_attr( $cat->term_id ); ?>">
                            <td>
                                <strong><?php echo esc_html( $cat->name ); ?></strong>
                                <span class="description">(<?php echo esc_html( $cat->count ); ?> <?php esc_html_e( 'products', 'wp-houla' ); ?>)</span>
                            </td>
                            <td style="text-align:center; color:#999;">&#8594;</td>
                            <td>
                                <select class="wphoula-ws-map-select" data-cat-id="<?php echo esc_attr( $cat->term_id ); ?>" style="width:100%;">
                                    <option value=""><?php esc_html_e( '— Default workspace —', 'wp-houla' ); ?></option>
                                    <?php if ( $has_mapping && $ws_id ) : ?>
                                        <option value="<?php echo esc_attr( $ws_id ); ?>" selected>
                                            <?php echo esc_html( $ws_name ?: $ws_id ); ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </td>
                            <td>
                                <?php if ( $has_mapping && $has_key ) : ?>
                                    <span class="wphoula-key-status wphoula-key-status--ok" title="<?php esc_attr_e( 'API key configured', 'wp-houla' ); ?>">
                                        <span class="dashicons dashicons-yes-alt" style="color:#00a32a;"></span>
                                        <?php esc_html_e( 'Configured', 'wp-houla' ); ?>
                                    </span>
                                <?php elseif ( $has_mapping && ! $has_key ) : ?>
                                    <span class="wphoula-key-status wphoula-key-status--missing" title="<?php esc_attr_e( 'API key missing', 'wp-houla' ); ?>">
                                        <span class="dashicons dashicons-warning" style="color:#d63638;"></span>
                                        <?php esc_html_e( 'Missing', 'wp-houla' ); ?>
                                    </span>
                                    <button type="button" class="button button-small wphoula-provision-ws-key"
                                            data-ws-id="<?php echo esc_attr( $ws_id ); ?>"
                                            data-ws-name="<?php echo esc_attr( $ws_name ); ?>"
                                            data-cat-id="<?php echo esc_attr( $cat->term_id ); ?>">
                                        <?php esc_html_e( 'Generate', 'wp-houla' ); ?>
                                    </button>
                                <?php else : ?>
                                    <span class="description">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $has_mapping ) : ?>
                                    <select class="wphoula-ws-adj-type" data-cat-id="<?php echo esc_attr( $cat->term_id ); ?>" style="width:100%;">
                                        <option value="none" <?php selected( $ws_adj_type, 'none' ); ?>><?php esc_html_e( 'None', 'wp-houla' ); ?></option>
                                        <option value="percent_up" <?php selected( $ws_adj_type, 'percent_up' ); ?>>+%</option>
                                        <option value="percent_down" <?php selected( $ws_adj_type, 'percent_down' ); ?>>-%</option>
                                        <option value="fixed_up" <?php selected( $ws_adj_type, 'fixed_up' ); ?>>+€</option>
                                        <option value="fixed_down" <?php selected( $ws_adj_type, 'fixed_down' ); ?>>-€</option>
                                    </select>
                                    <input type="number" class="wphoula-ws-adj-value small-text" data-cat-id="<?php echo esc_attr( $cat->term_id ); ?>"
                                           value="<?php echo esc_attr( $ws_adj_val ); ?>"
                                           min="0" step="0.01" style="width:60px; margin-top:4px; <?php echo $ws_adj_type === 'none' ? 'display:none;' : ''; ?>">
                                <?php else : ?>
                                    <span class="description">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ( $has_mapping ) : ?>
                                    <button type="button" class="button button-small button-link-delete wphoula-ws-map-remove" data-cat-id="<?php echo esc_attr( $cat->term_id ); ?>">
                                        <?php esc_html_e( 'Remove', 'wp-houla' ); ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php
                            endforeach;
                        endif;
                        ?>
                    </tbody>
                </table>
            </div>

            <p style="margin-top: 16px;">
                <button type="button" class="button button-primary" id="wphoula-save-workspace-map">
                    <?php esc_html_e( 'Save Workspace Mapping', 'wp-houla' ); ?>
                </button>
                <span id="wphoula-ws-map-status" style="display:none; margin-left: 8px;"></span>
            </p>
            <p class="description">
                <?php esc_html_e( 'Select a workspace for each category, then click "Save". An API key will be automatically generated for each mapped workspace. Products in unmapped categories will sync to your default connected workspace.', 'wp-houla' ); ?>
            </p>
        </div>

        <!-- ============================================================= -->
        <!-- Price adjustment                                               -->
        <!-- ============================================================= -->
        <div class="wphoula-card" style="margin-top: 20px;">
            <h2><?php esc_html_e( 'Price Adjustment', 'wp-houla' ); ?></h2>
            <p class="description" style="margin-bottom: 12px;">
                <?php esc_html_e( 'Apply a price markup or discount when syncing to Hou.la. Useful to set different prices on your bio page.', 'wp-houla' ); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Adjustment type', 'wp-houla' ); ?></th>
                    <td>
                        <?php
                        $price_adj_type  = $options->get( 'price_adjustment_type' );
                        $price_adj_value = $options->get( 'price_adjustment_value' );
                        ?>
                        <select id="wphoula-price-adj-type">
                            <option value="none" <?php selected( $price_adj_type, 'none' ); ?>>
                                <?php esc_html_e( 'No adjustment (sync original prices)', 'wp-houla' ); ?>
                            </option>
                            <option value="percent_up" <?php selected( $price_adj_type, 'percent_up' ); ?>>
                                <?php esc_html_e( 'Markup by percentage (+%)', 'wp-houla' ); ?>
                            </option>
                            <option value="percent_down" <?php selected( $price_adj_type, 'percent_down' ); ?>>
                                <?php esc_html_e( 'Discount by percentage (-%)', 'wp-houla' ); ?>
                            </option>
                            <option value="fixed_up" <?php selected( $price_adj_type, 'fixed_up' ); ?>>
                                <?php esc_html_e( 'Markup by fixed amount (+)', 'wp-houla' ); ?>
                            </option>
                            <option value="fixed_down" <?php selected( $price_adj_type, 'fixed_down' ); ?>>
                                <?php esc_html_e( 'Discount by fixed amount (-)', 'wp-houla' ); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <tr id="wphoula-price-adj-row" <?php echo $price_adj_type === 'none' ? 'style="display:none;"' : ''; ?>>
                    <th><?php esc_html_e( 'Value', 'wp-houla' ); ?></th>
                    <td>
                        <input type="number" id="wphoula-price-adj-value"
                               value="<?php echo esc_attr( $price_adj_value ); ?>"
                               min="0" step="0.01" class="small-text" style="width: 100px;">
                        <span id="wphoula-price-adj-unit">
                            <?php
                            if ( in_array( $price_adj_type, array( 'percent_up', 'percent_down' ), true ) ) {
                                echo '%';
                            } else {
                                echo esc_html( function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : 'EUR' );
                            }
                            ?>
                        </span>
                        <p class="description" id="wphoula-price-example"></p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" class="button button-primary wphoula-save-settings-btn">
                    <?php esc_html_e( 'Save Settings', 'wp-houla' ); ?>
                </button>
            </p>
        </div>

        <!-- ============================================================= -->
        <!-- Product Identifiers (EAN / ISBN) mapping                       -->
        <!-- ============================================================= -->
        <div class="wphoula-card" style="margin-top: 20px;">
            <h2><?php esc_html_e( 'Product Identifiers (EAN / ISBN)', 'wp-houla' ); ?></h2>
            <p class="description" style="margin-bottom: 12px;">
                <?php esc_html_e( 'WooCommerce does not have native EAN/ISBN fields. Select which product meta field contains these values. They are used for Google Shopping and for product grouping on the Hou.la marketplace.', 'wp-houla' ); ?>
            </p>

            <?php
            $id_meta_map = $options->get( 'identifier_meta_map' );
            if ( ! is_array( $id_meta_map ) ) {
                $id_meta_map = array( 'gtin' => '', 'isbn' => '' );
            }
            ?>

            <table class="form-table">
                <tr>
                    <th>
                        <?php esc_html_e( 'EAN / GTIN', 'wp-houla' ); ?>
                        <p class="description" style="font-weight:normal;"><?php esc_html_e( 'GTIN-8, GTIN-12 (UPC), GTIN-13 (EAN-13), GTIN-14', 'wp-houla' ); ?></p>
                    </th>
                    <td>
                        <select id="wphoula-id-meta-gtin" class="wphoula-id-meta-select" data-id-key="gtin" style="min-width: 320px;">
                            <option value=""><?php esc_html_e( '— Not configured —', 'wp-houla' ); ?></option>
                        </select>
                        <input type="hidden" id="wphoula-id-meta-gtin-saved" value="<?php echo esc_attr( $id_meta_map['gtin'] ?? '' ); ?>">
                        <span class="wphoula-id-meta-loading" style="display:none;">
                            <span class="spinner is-active" style="float:none; margin:0 4px;"></span>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>
                        <?php esc_html_e( 'ISBN', 'wp-houla' ); ?>
                        <p class="description" style="font-weight:normal;"><?php esc_html_e( 'International Standard Book Number', 'wp-houla' ); ?></p>
                    </th>
                    <td>
                        <select id="wphoula-id-meta-isbn" class="wphoula-id-meta-select" data-id-key="isbn" style="min-width: 320px;">
                            <option value=""><?php esc_html_e( '— Not configured —', 'wp-houla' ); ?></option>
                        </select>
                        <input type="hidden" id="wphoula-id-meta-isbn-saved" value="<?php echo esc_attr( $id_meta_map['isbn'] ?? '' ); ?>">
                        <span class="wphoula-id-meta-loading" style="display:none;">
                            <span class="spinner is-active" style="float:none; margin:0 4px;"></span>
                        </span>
                    </td>
                </tr>
            </table>

            <p class="description">
                <span class="dashicons dashicons-info-outline" style="font-size:16px; width:16px; height:16px; color:#2271b1; margin-right:4px;"></span>
                <?php esc_html_e( 'Common plugins: Yoast SEO, "Product GTIN for WooCommerce", "EAN for WooCommerce (WPFactory)", WooCommerce native (9.2+). The dropdown auto-detects installed plugins.', 'wp-houla' ); ?>
            </p>

            <p style="margin-top: 12px;">
                <button type="button" class="button button-primary wphoula-save-settings-btn">
                    <?php esc_html_e( 'Save Settings', 'wp-houla' ); ?>
                </button>
            </p>
        </div>

        <!-- ============================================================= -->
        <!-- Category -> Collection mapping                                 -->
        <!-- ============================================================= -->
        <div class="wphoula-card" style="margin-top: 20px;">
            <h2><?php esc_html_e( 'Category - Collection Mapping', 'wp-houla' ); ?></h2>
            <p class="description" style="margin-bottom: 12px;">
                <?php esc_html_e( 'Map your WooCommerce categories to Hou.la collections. Products will be automatically placed in the matching collection during sync.', 'wp-houla' ); ?>
            </p>

            <?php
            $cat_collection_map = $options->get( 'category_collection_map' );
            if ( ! is_array( $cat_collection_map ) ) {
                $cat_collection_map = array();
            }
            ?>

            <div class="wphoula-scroll-container" style="max-height: 400px; overflow-y: auto; border: 1px solid #c3c4c7; border-radius: 4px;">
            <table class="wphoula-mapping-table widefat" id="wphoula-mapping-table" style="border:none;">
                <thead>
                    <tr>
                        <th style="width:40%;"><?php esc_html_e( 'WooCommerce Category', 'wp-houla' ); ?></th>
                        <th style="width:10%; text-align:center;">&#8594;</th>
                        <th style="width:40%;"><?php esc_html_e( 'Hou.la Collection', 'wp-houla' ); ?></th>
                        <th style="width:10%;"></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if ( ! empty( $product_cats ) && ! is_wp_error( $product_cats ) ) :
                        foreach ( $product_cats as $cat ) :
                            $mapped_collection = isset( $cat_collection_map[ $cat->term_id ] )
                                ? $cat_collection_map[ $cat->term_id ]
                                : '';
                    ?>
                    <tr data-cat-id="<?php echo esc_attr( $cat->term_id ); ?>">
                        <td>
                            <strong><?php echo esc_html( $cat->name ); ?></strong>
                            <span class="description">(<?php echo esc_html( $cat->count ); ?> <?php esc_html_e( 'products', 'wp-houla' ); ?>)</span>
                        </td>
                        <td style="text-align:center; color:#999;">&#8594;</td>
                        <td>
                            <select class="wphoula-collection-select" data-cat-id="<?php echo esc_attr( $cat->term_id ); ?>" style="width:100%;">
                                <option value=""><?php esc_html_e( '- Not mapped -', 'wp-houla' ); ?></option>
                            </select>
                            <input type="hidden" class="wphoula-collection-value" value="<?php echo esc_attr( $mapped_collection ); ?>">
                        </td>
                        <td></td>
                    </tr>
                    <?php
                        endforeach;
                    endif;
                    ?>
                </tbody>
            </table>
            </div><!-- /.wphoula-scroll-container -->

            <p style="margin-top: 12px;">
                <button type="button" class="button button-secondary" id="wphoula-auto-map">
                    <span class="dashicons dashicons-admin-generic" style="line-height:28px;"></span>
                    <?php esc_html_e( 'Auto-create collections from categories', 'wp-houla' ); ?>
                </button>
                <span id="wphoula-automap-status" style="display:none; margin-left: 8px;"></span>
            </p>
            <p class="description">
                <?php esc_html_e( 'Auto-create will create a Hou.la collection for each WooCommerce category and map them automatically.', 'wp-houla' ); ?>
            </p>

            <p style="margin-top: 16px;">
                <button type="button" class="button button-primary wphoula-save-settings-btn">
                    <?php esc_html_e( 'Save Settings', 'wp-houla' ); ?>
                </button>
            </p>
        </div>

        <!-- ============================================================= -->
        <!-- Product Sync settings                                          -->
        <!-- ============================================================= -->
        <div class="wphoula-card" style="margin-top: 20px;">
            <h2><?php esc_html_e( 'Product Sync', 'wp-houla' ); ?></h2>

            <!-- Shop activation check (loaded via AJAX) -->
            <div id="wphoula-shop-status-banner" class="wphoula-shop-status-banner" style="display:none;">
                <span class="dashicons dashicons-warning" style="color:#d63638; margin-right:6px;"></span>
                <div>
                    <strong><?php esc_html_e( 'Shop not activated', 'wp-houla' ); ?></strong>
                    <p style="margin:4px 0 0; color:#666;">
                        <?php esc_html_e( 'Your Hou.la shop is not activated yet. Please connect Stripe on your Hou.la dashboard (Manager > Shop) before syncing products.', 'wp-houla' ); ?>
                    </p>
                </div>
            </div>

            <?php
            $cat_map = $options->get( 'category_collection_map' );
            $has_mapping = is_array( $cat_map ) && ! empty( $cat_map );
            if ( ! $has_mapping ) :
            ?>
            <div class="notice notice-info inline" style="margin: 0 0 16px; padding: 10px 14px; display:flex; align-items:flex-start; gap:8px;" id="wphoula-mapping-suggestion">
                <span class="dashicons dashicons-info" style="color:#2271b1; margin-top:2px;"></span>
                <div>
                    <strong><?php esc_html_e( 'Astuce : associez d\'abord vos catégories', 'wp-houla' ); ?></strong>
                    <p style="margin:4px 0 0; color:#555;">
                        <?php esc_html_e( 'Avant de synchroniser vos produits, nous vous recommandons d\'associer vos catégories WooCommerce aux collections Hou.la. Rendez-vous dans la section « Correspondance catégories - collections » ci-dessous et cliquez sur « Créer automatiquement les collections ».', 'wp-houla' ); ?>
                    </p>
                </div>
            </div>
            <?php endif; ?>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Auto-sync', 'wp-houla' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="wphoula-auto-sync" <?php checked( $auto_sync ); ?>>
                            <?php esc_html_e( 'Automatically sync product changes to Hou.la', 'wp-houla' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Sync on publish', 'wp-houla' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="wphoula-sync-on-publish" <?php checked( $sync_on_publish ); ?>>
                            <?php esc_html_e( 'Sync new products when they are published', 'wp-houla' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Category filter', 'wp-houla' ); ?></th>
                    <td>
                        <fieldset>
                            <legend class="screen-reader-text"><?php esc_html_e( 'Category filter', 'wp-houla' ); ?></legend>
                            <?php
                            if ( ! empty( $product_cats ) && ! is_wp_error( $product_cats ) ) :
                            ?>
                                <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                                    <input type="checkbox" id="wphoula-select-all-categories">
                                    <?php esc_html_e( 'Select all', 'wp-houla' ); ?>
                                </label>
                                <hr style="margin: 4px 0 8px;">
                                <div class="wphoula-scroll-container" style="max-height: 300px; overflow-y: auto; border: 1px solid #c3c4c7; border-radius: 4px; padding: 8px 12px;">
                            <?php
                                foreach ( $product_cats as $cat ) :
                                    $checked = in_array( (int) $cat->term_id, array_map( 'intval', $sync_categories ), true );
                            ?>
                                <label style="display: block; margin-bottom: 4px;">
                                    <input type="checkbox" class="wphoula-sync-category" value="<?php echo esc_attr( $cat->term_id ); ?>" <?php checked( $checked ); ?>>
                                    <?php echo esc_html( $cat->name ); ?>
                                    <span class="description">(<?php echo esc_html( $cat->count ); ?>)</span>
                                </label>
                            <?php
                                endforeach;
                            ?>
                                </div><!-- /.wphoula-scroll-container -->
                            <?php
                            else :
                            ?>
                                <p class="description"><?php esc_html_e( 'No WooCommerce categories found.', 'wp-houla' ); ?></p>
                            <?php endif; ?>
                            <p class="description"><?php esc_html_e( 'Select which categories to sync. Leave all unchecked to sync all products.', 'wp-houla' ); ?></p>
                        </fieldset>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" class="button button-primary" id="wphoula-save-settings">
                    <?php esc_html_e( 'Save Settings', 'wp-houla' ); ?>
                </button>
            </p>
        </div>

        <!-- ============================================================= -->
        <!-- Lancer la synchronisation                                      -->
        <!-- ============================================================= -->
        <div class="wphoula-card" style="margin-top: 20px;">
            <h2><?php esc_html_e( 'Synchroniser les produits', 'wp-houla' ); ?></h2>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Dernière synchronisation', 'wp-houla' ); ?></th>
                    <td id="wphoula-last-full-sync"><?php echo $last_full_sync ? esc_html( $last_full_sync ) : esc_html__( 'Jamais', 'wp-houla' ); ?></td>
                </tr>
            </table>

            <p>
                <button type="button" class="button button-primary" id="wphoula-batch-sync">
                    <?php esc_html_e( 'Synchroniser les produits', 'wp-houla' ); ?>
                </button>
                <button type="button" class="button" id="wphoula-cancel-sync" style="display:none; color:#b32d2e; border-color:#b32d2e;">
                    <?php esc_html_e( 'Annuler la synchronisation', 'wp-houla' ); ?>
                </button>
                <?php if ( function_exists( 'wphoula_is_dev_mode' ) && wphoula_is_dev_mode() ) : ?>
                <button type="button" class="button" id="wphoula-reset-sync" style="color:#b32d2e;border-color:#b32d2e;">
                    <?php esc_html_e( 'Réinitialiser la synchronisation', 'wp-houla' ); ?>
                </button>
                <?php endif; ?>
                <span id="wphoula-sync-status" class="wphoula-spinner" style="display:none;"></span>
            </p>

            <!-- Progress bar (hidden by default) -->
            <div id="wphoula-sync-progress" class="wphoula-progress" style="display:none;">
                <div class="wphoula-progress-bar">
                    <div class="wphoula-progress-fill" id="wphoula-progress-fill"></div>
                </div>
                <p class="wphoula-progress-text" id="wphoula-progress-text"></p>
            </div>
        </div>

        <!-- ============================================================= -->
        <!-- Synced products table                                          -->
        <!-- ============================================================= -->
        <div class="wphoula-card" style="margin-top: 20px;">
            <h2><?php esc_html_e( 'Synced Products', 'wp-houla' ); ?></h2>
            <p class="description" style="margin-bottom: 12px;">
                <?php esc_html_e( 'Products currently synchronized on Hou.la from this WooCommerce store.', 'wp-houla' ); ?>
            </p>

            <!-- Search + per-page controls -->
            <div class="wphoula-products-toolbar" style="display:flex; align-items:center; gap:12px; margin-bottom:12px; flex-wrap:wrap;">
                <input type="search" id="wphoula-products-search" placeholder="<?php esc_attr_e( 'Search products...', 'wp-houla' ); ?>" style="min-width:220px; max-width:320px;">
                <select id="wphoula-products-per-page" style="width:auto;">
                    <option value="20">20 / page</option>
                    <option value="50">50 / page</option>
                    <option value="100">100 / page</option>
                </select>
                <span id="wphoula-products-total" style="color:#666; margin-left:auto;"></span>
            </div>

            <div id="wphoula-synced-products-loading" style="padding: 20px; text-align: center;">
                <span class="spinner is-active" style="float:none; margin: 0 8px 0 0;"></span>
                <?php esc_html_e( 'Loading synced products...', 'wp-houla' ); ?>
            </div>

            <table class="wphoula-products-table widefat" id="wphoula-synced-products" style="display:none;">
                <thead>
                    <tr>
                        <th style="width:5%;">#</th>
                        <th style="width:30%;"><?php esc_html_e( 'Product', 'wp-houla' ); ?></th>
                        <th style="width:12%;"><?php esc_html_e( 'WC Price', 'wp-houla' ); ?></th>
                        <th style="width:12%;"><?php esc_html_e( 'Hou.la Price', 'wp-houla' ); ?></th>
                        <th style="width:10%;"><?php esc_html_e( 'Status', 'wp-houla' ); ?></th>
                        <th style="width:10%;"><?php esc_html_e( 'Stock', 'wp-houla' ); ?></th>
                        <th style="width:21%;"><?php esc_html_e( 'Last synced', 'wp-houla' ); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>

            <!-- Pagination controls -->
            <div id="wphoula-products-pagination" style="display:none; margin-top:12px; display:flex; align-items:center; justify-content:space-between;">
                <div>
                    <button type="button" class="button" id="wphoula-products-prev" disabled>&laquo; <?php esc_html_e( 'Previous', 'wp-houla' ); ?></button>
                    <span id="wphoula-products-page-info" style="margin:0 12px; color:#666;"></span>
                    <button type="button" class="button" id="wphoula-products-next"><?php esc_html_e( 'Next', 'wp-houla' ); ?> &raquo;</button>
                </div>
            </div>

            <div id="wphoula-synced-products-empty" style="display:none; padding: 20px; text-align: center; color: #666;">
                <?php esc_html_e( 'No products synced yet. Click "Sync All Products Now" to start.', 'wp-houla' ); ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- ============================================================= -->
    <!-- TAB: Orders (WooCommerce only)                                 -->
    <!-- ============================================================= -->
    <div class="wphoula-tab-content" id="tab-orders" style="display:none;">
        <div class="wphoula-card">
            <h2><?php esc_html_e( 'Orders via Hou.la', 'wp-houla' ); ?></h2>

            <?php if ( ! $is_connected ) : ?>
                <p class="description"><?php esc_html_e( 'Connect your account first.', 'wp-houla' ); ?></p>
            <?php else : ?>

                <table class="form-table">
                    <tr>
                        <th><?php esc_html_e( 'Webhook URL', 'wp-houla' ); ?></th>
                        <td>
                            <code><?php echo esc_html( rest_url( 'wp-houla/v1/webhook' ) ); ?></code>
                            <p class="description"><?php esc_html_e( 'This URL is automatically registered with Hou.la.', 'wp-houla' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Orders received', 'wp-houla' ); ?></th>
                        <td><strong><?php echo esc_html( $orders_received ?: '0' ); ?></strong></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e( 'Last order', 'wp-houla' ); ?></th>
                        <td><?php echo $last_order_at ? esc_html( $last_order_at ) : esc_html__( 'None yet', 'wp-houla' ); ?></td>
                    </tr>
                </table>

                <p class="description">
                    <?php esc_html_e( 'Orders placed through your Hou.la bio page appear in WooCommerce > Orders with the payment method "Hou.la Pay (Stripe)".', 'wp-houla' ); ?>
                </p>

            <?php endif; ?>
        </div>

        <?php if ( $is_connected ) : ?>
        <!-- ── Order sync status + resync ── -->
        <div class="wphoula-card" style="margin-top: 20px;">
            <h2><?php esc_html_e( 'Order Synchronization', 'wp-houla' ); ?></h2>
            <p class="description" style="margin-bottom: 12px;">
                <?php esc_html_e( 'Resynchronize order statuses between WooCommerce and Hou.la. Use this if some orders failed to sync or were created before the plugin was connected.', 'wp-houla' ); ?>
            </p>

            <div id="wphoula-order-sync-counts" style="margin-bottom: 16px;">
                <span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
                <?php esc_html_e( 'Loading...', 'wp-houla' ); ?>
            </div>

            <p>
                <button type="button" class="button" id="wphoula-resync-failed-orders" style="margin-right: 8px;" disabled>
                    <?php esc_html_e( 'Retry failed orders', 'wp-houla' ); ?>
                </button>
                <button type="button" class="button" id="wphoula-resync-all-orders" style="margin-right: 8px;" disabled>
                    <?php esc_html_e( 'Resync all orders', 'wp-houla' ); ?>
                </button>
                <button type="button" class="button button-primary" id="wphoula-pull-orders-from-houla">
                    <?php esc_html_e( 'Pull orders from Hou.la', 'wp-houla' ); ?>
                </button>
            </p>
            <p class="description" style="margin-top: 4px;">
                <?php esc_html_e( '"Pull orders" fetches all orders from Hou.la and creates/updates them in WooCommerce. Use this when orders were missed (e.g. server was offline).', 'wp-houla' ); ?>
            </p>
            <div id="wphoula-order-resync-status" style="display:none; margin-top: 8px;"></div>
        </div>

        <!-- ── Status concordance table ── -->
        <div class="wphoula-card" style="margin-top: 20px;">
            <h2><?php esc_html_e( 'Status Concordance', 'wp-houla' ); ?></h2>
            <p class="description" style="margin-bottom: 12px;">
                <?php esc_html_e( 'Define how each WooCommerce order status maps to a Hou.la status. This mapping is used in both directions (WooCommerce → Hou.la and Hou.la → WooCommerce).', 'wp-houla' ); ?>
            </p>

            <?php
            // Hou.la statuses available as dropdown options
            $houla_statuses = array(
                'pending'              => __( 'Pending', 'wp-houla' ),
                'open_cart'            => __( 'Open Cart', 'wp-houla' ),
                'paid'                 => __( 'Paid', 'wp-houla' ),
                'processing'           => __( 'Processing', 'wp-houla' ),
                'shipped'              => __( 'Shipped', 'wp-houla' ),
                'delivered'            => __( 'Delivered', 'wp-houla' ),
                'cancelled'            => __( 'Cancelled', 'wp-houla' ),
                'abandoned'            => __( 'Abandoned', 'wp-houla' ),
                'refunded'             => __( 'Refunded', 'wp-houla' ),
                'partially_refunded'   => __( 'Partially Refunded', 'wp-houla' ),
            );

            // Get all registered WooCommerce statuses
            $wc_statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();

            // Current saved mapping (wc_slug => houla_status)
            $status_map = $options->get( 'order_status_map' );
            if ( ! is_array( $status_map ) || empty( $status_map ) ) {
                // Default mapping
                $status_map = array(
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
            }
            ?>

            <table class="wphoula-concordance-table widefat">
                <thead>
                    <tr>
                        <th style="width: 45%;"><?php esc_html_e( 'WooCommerce Status', 'wp-houla' ); ?></th>
                        <th style="width: 10%; text-align:center;">→</th>
                        <th style="width: 45%;"><?php esc_html_e( 'Hou.la Status', 'wp-houla' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $wc_statuses as $wc_slug => $wc_label ) : ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html( $wc_label ); ?></strong>
                            <code style="font-size: 11px; color: #999; margin-left: 6px;"><?php echo esc_html( $wc_slug ); ?></code>
                        </td>
                        <td style="text-align:center; font-size: 18px; color: #999;">→</td>
                        <td>
                            <select class="wphoula-status-map" data-wc-status="<?php echo esc_attr( $wc_slug ); ?>">
                                <option value=""><?php esc_html_e( '— Not mapped —', 'wp-houla' ); ?></option>
                                <?php foreach ( $houla_statuses as $houla_key => $houla_label ) : ?>
                                <option value="<?php echo esc_attr( $houla_key ); ?>"
                                    <?php selected( isset( $status_map[ $wc_slug ] ) ? $status_map[ $wc_slug ] : '', $houla_key ); ?>>
                                    <?php echo esc_html( $houla_label ); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p style="margin-top: 12px;">
                <button type="button" class="button button-primary wphoula-save-settings-btn">
                    <?php esc_html_e( 'Save Settings', 'wp-houla' ); ?>
                </button>
            </p>
        </div>

        <!-- ── Tracking auto-sync settings ── -->
        <div class="wphoula-card" style="margin-top: 20px;">
            <h2><?php esc_html_e( 'Tracking Sync', 'wp-houla' ); ?></h2>
            <p class="description" style="margin-bottom: 12px;">
                <?php esc_html_e( 'When a tracking number is added to a WooCommerce order, it will be automatically sent to Hou.la so buyers can track their shipment.', 'wp-houla' ); ?>
            </p>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Auto-sync tracking', 'wp-houla' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="wphoula-sync-tracking" <?php checked( $options->get( 'sync_tracking' ) ); ?>>
                            <?php esc_html_e( 'Automatically push tracking numbers to Hou.la when added in WooCommerce', 'wp-houla' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Supported plugins', 'wp-houla' ); ?></th>
                    <td>
                        <ul style="margin: 0; list-style: disc inside;">
                            <li><?php esc_html_e( 'Advanced Shipment Tracking (AST)', 'wp-houla' ); ?></li>
                            <li><?php esc_html_e( 'WooCommerce Shipment Tracking', 'wp-houla' ); ?></li>
                            <li><?php esc_html_e( 'YITH WooCommerce Order Tracking', 'wp-houla' ); ?></li>
                            <li><?php esc_html_e( 'Or any plugin storing tracking in order meta', 'wp-houla' ); ?></li>
                        </ul>
                        <p class="description" style="margin-top: 8px;">
                            <?php esc_html_e( 'Tracking links are generated automatically for major carriers (Colissimo, DPD, GLS, Chronopost, UPS, FedEx, DHL, etc.).', 'wp-houla' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" class="button button-primary wphoula-save-settings-btn">
                    <?php esc_html_e( 'Save Settings', 'wp-houla' ); ?>
                </button>
            </p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; /* wphoula_is_woocommerce_active() && workspace_has_shop */ ?>

    <!-- ============================================================= -->
    <!-- TAB: Debug                                                     -->
    <!-- ============================================================= -->
    <div class="wphoula-tab-content" id="tab-debug" style="display:none;">
        <div class="wphoula-card">
            <h2><?php esc_html_e( 'Debug Information', 'wp-houla' ); ?></h2>

            <table class="form-table">
                <tr>
                    <th><?php esc_html_e( 'Debug logging', 'wp-houla' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" id="wphoula-debug" <?php checked( $debug ); ?>>
                            <?php esc_html_e( 'Enable debug logging (writes to wp-content/debug.log)', 'wp-houla' ); ?>
                        </label>
                    </td>
                </tr>
                <?php if ( function_exists( 'wphoula_is_dev_mode' ) && wphoula_is_dev_mode() ) : ?>
                <tr>
                    <th><?php esc_html_e( 'API URL', 'wp-houla' ); ?></th>
                    <td>
                        <?php
                        $api_url = $options->get( 'api_url' );
                        $current_url = function_exists( 'wphoula_get_api_url' ) ? wphoula_get_api_url() : WPHOULA_API_URL;
                        ?>
                        <input type="url" id="wphoula-api-url" value="<?php echo esc_attr( $api_url ); ?>"
                               placeholder="<?php echo esc_attr( WPHOULA_DEFAULT_API_URL ); ?>"
                               class="regular-text" style="width: 400px;">
                        <p class="description">
                            <?php esc_html_e( 'Custom API URL for development. Leave empty for production (https://hou.la).', 'wp-houla' ); ?>
                            <br>
                            <?php
                            printf(
                                esc_html__( 'Current: %s', 'wp-houla' ),
                                '<code>' . esc_html( $current_url ) . '</code>'
                            );
                            ?>
                        </p>
                        <p class="description" style="margin-top: 8px; color: #d63638;">
                            <?php esc_html_e( 'OAuth + all API calls will use this URL. Example: https://xxx.ngrok-free.dev', 'wp-houla' ); ?>
                        </p>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <th><?php esc_html_e( 'Plugin version', 'wp-houla' ); ?></th>
                    <td><?php echo esc_html( WPHOULA_VERSION ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'PHP version', 'wp-houla' ); ?></th>
                    <td><?php echo esc_html( PHP_VERSION ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'WordPress version', 'wp-houla' ); ?></th>
                    <td><?php echo esc_html( get_bloginfo( 'version' ) ); ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'WooCommerce version', 'wp-houla' ); ?></th>
                    <td><?php echo defined( 'WC_VERSION' ) ? esc_html( WC_VERSION ) : '---'; ?></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'REST API base', 'wp-houla' ); ?></th>
                    <td><code><?php echo esc_html( rest_url( 'wp-houla/v1/' ) ); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e( 'Webhook secret', 'wp-houla' ); ?></th>
                    <td>
                        <?php
                        $secret = $options->get( 'webhook_secret' );
                        echo $secret
                            ? '<code>' . esc_html( substr( $secret, 0, 8 ) ) . '...</code>'
                            : esc_html__( 'Not generated', 'wp-houla' );
                        ?>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" class="button button-primary" id="wphoula-save-settings">
                    <?php esc_html_e( 'Save Settings', 'wp-houla' ); ?>
                </button>
            </p>
        </div>
    </div>
</div>
