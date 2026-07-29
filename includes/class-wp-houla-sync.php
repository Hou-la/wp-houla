<?php
/**
 * WooCommerce product synchronization with Hou.la.
 *
 * Listens to WooCommerce lifecycle hooks and pushes product
 * data to the Hou.la API so they can be listed on bio pages.
 *
 * Hooks handled:
 * - woocommerce_new_product          -> create on Hou.la
 * - woocommerce_update_product       -> update on Hou.la
 * - woocommerce_before_delete_product -> delete on Hou.la
 * - woocommerce_trash_product        -> delete on Hou.la
 * - woocommerce_product_set_stock    -> update stock on Hou.la
 * - woocommerce_variation_set_stock  -> update stock on Hou.la
 *
 * @since      1.0.0
 * @package    Wp_Houla
 * @subpackage Wp_Houla/includes
 */

class Wp_Houla_Sync {

    /** @var Wp_Houla_Api */
    private $api;

    /** @var Wp_Houla_Options */
    private $options;

    /** @var Wp_Houla_Auth */
    private $auth;

    /** @var bool Prevent recursive hooks during sync. */
    private static $syncing = false;

    public function __construct() {
        $this->api     = new Wp_Houla_Api();
        $this->options = new Wp_Houla_Options();
        $this->auth    = new Wp_Houla_Auth();
    }

    // =====================================================================
    // Custom WooCommerce order statuses
    // =====================================================================

    /**
     * Register the custom 'wc-open-cart' order status in WooCommerce.
     * Called via 'init' hook.
     *
     * Uses both register_post_status() (legacy) and the wc_order_statuses filter
     * (HPOS) to ensure the status works in all WooCommerce storage backends.
     */
    public function register_custom_order_statuses() {
        register_post_status( 'wc-open-cart', array(
            'label'                     => _x( 'Panier ouvert', 'Order status', 'wp-houla' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            /* translators: %s: number of orders */
            'label_count'               => _n_noop(
                'Panier ouvert <span class="count">(%s)</span>',
                'Panier ouvert <span class="count">(%s)</span>',
                'wp-houla'
            ),
        ) );

        register_post_status( 'wc-abandoned-cart', array(
            'label'                     => _x( 'Panier abandonné', 'Order status', 'wp-houla' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'Panier abandonné <span class="count">(%s)</span>',
                'Panier abandonné <span class="count">(%s)</span>',
                'wp-houla'
            ),
        ) );

        // « En cours de livraison » : WooCommerce n'a pas de statut natif entre
        // « En cours » et « Terminée ». Sans ça, un colis expédié tombait en
        // « Terminée » (sémantiquement faux). Toujours présent tant que le plugin
        // est actif → indépendant du transporteur.
        register_post_status( 'wc-houla-shipping', array(
            'label'                     => _x( 'En cours de livraison (Hou.la)', 'Order status', 'wp-houla' ),
            'public'                    => true,
            'exclude_from_search'       => false,
            'show_in_admin_all_list'    => true,
            'show_in_admin_status_list' => true,
            'label_count'               => _n_noop(
                'En cours de livraison (Hou.la) <span class="count">(%s)</span>',
                'En cours de livraison (Hou.la) <span class="count">(%s)</span>',
                'wp-houla'
            ),
        ) );
    }

    /**
     * Add custom statuses to WooCommerce order statuses dropdown.
     * This filter is the primary mechanism for HPOS-compatible custom statuses.
     *
     * @param array $statuses Existing statuses.
     * @return array Modified statuses.
     */
    public function add_custom_order_statuses( $statuses ) {
        // Insert after 'wc-pending' so it appears near the top of the status list
        $new_statuses = array();
        foreach ( $statuses as $key => $label ) {
            $new_statuses[ $key ] = $label;
            if ( 'wc-pending' === $key ) {
                $new_statuses['wc-open-cart']      = _x( 'Panier ouvert', 'Order status', 'wp-houla' );
                $new_statuses['wc-abandoned-cart']  = _x( 'Panier abandonné', 'Order status', 'wp-houla' );
            }
            // « En cours de livraison » se place entre « En cours » et « Terminée ».
            if ( 'wc-processing' === $key ) {
                $new_statuses['wc-houla-shipping'] = _x( 'En cours de livraison (Hou.la)', 'Order status', 'wp-houla' );
            }
        }
        // Fallback: add at the end if the anchor statuses weren't found
        if ( ! isset( $new_statuses['wc-open-cart'] ) ) {
            $new_statuses['wc-open-cart']      = _x( 'Panier ouvert', 'Order status', 'wp-houla' );
            $new_statuses['wc-abandoned-cart']  = _x( 'Panier abandonné', 'Order status', 'wp-houla' );
        }
        if ( ! isset( $new_statuses['wc-houla-shipping'] ) ) {
            $new_statuses['wc-houla-shipping'] = _x( 'En cours de livraison (Hou.la)', 'Order status', 'wp-houla' );
        }
        return $new_statuses;
    }

    // =====================================================================
    // Multi-workspace routing (category_workspace_map)
    // =====================================================================

    /**
     * Resolve the workspace overrides for a product based on its categories.
     *
     * Looks up the category_workspace_map option to find which workspace
     * should receive this product. Returns API overrides (api_key, workspace_id)
     * and optional per-workspace price adjustment.
     *
     * @param WC_Product $product WooCommerce product.
     * @return array|null Null if no workspace mapping found (use default).
     *   Otherwise: {
     *     workspace_id: string,
     *     workspace_name: string,
     *     api_key: string (decrypted),
     *     overrides: array (for Wp_Houla_Api calls),
     *     price_adjustment_type: string,
     *     price_adjustment_value: float,
     *   }
     */
    private function resolve_workspace_for_product( $product ) {
        $ws_map = $this->options->get( 'category_workspace_map' );
        if ( empty( $ws_map ) || ! is_array( $ws_map ) ) {
            return null;
        }

        $cat_ids = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
        if ( is_wp_error( $cat_ids ) || empty( $cat_ids ) ) {
            return null;
        }

        // Find the first matching category in the workspace map
        foreach ( $cat_ids as $cat_id ) {
            if ( isset( $ws_map[ $cat_id ] ) && ! empty( $ws_map[ $cat_id ]['workspace_id'] ) ) {
                $entry = $ws_map[ $cat_id ];
                $api_key = ! empty( $entry['api_key'] )
                    ? Wp_Houla_Options::decrypt( $entry['api_key'] )
                    : null;

                if ( empty( $api_key ) ) {
                    $this->log( 'Workspace map: category ' . $cat_id . ' has no API key, skipping override.' );
                    continue;
                }

                return array(
                    'workspace_id'           => $entry['workspace_id'],
                    'workspace_name'         => $entry['workspace_name'] ?? '',
                    'api_key'                => $api_key,
                    'overrides'              => array(
                        'api_key'      => $api_key,
                        'workspace_id' => $entry['workspace_id'],
                    ),
                    'price_adjustment_type'  => $entry['price_adjustment_type'] ?? 'none',
                    'price_adjustment_value' => (float) ( $entry['price_adjustment_value'] ?? 0 ),
                );
            }
        }

        return null;
    }

    /**
     * Format a product with optional workspace-specific price adjustment.
     *
     * @param WC_Product  $product   WooCommerce product.
     * @param array|null  $ws_info   Workspace info from resolve_workspace_for_product().
     * @return array Formatted product data.
     */
    private function format_product_for_workspace( $product, $ws_info = null ) {
        // Temporarily override price adjustment if workspace has its own
        $orig_type  = null;
        $orig_value = null;

        if ( $ws_info && $ws_info['price_adjustment_type'] !== 'none' && $ws_info['price_adjustment_value'] > 0 ) {
            $orig_type  = $this->options->get( 'price_adjustment_type' );
            $orig_value = $this->options->get( 'price_adjustment_value' );
            $this->options->set_many( array(
                'price_adjustment_type'  => $ws_info['price_adjustment_type'],
                'price_adjustment_value' => $ws_info['price_adjustment_value'],
            ) );
        }

        $data = $this->format_product( $product );

        // Restore original price adjustment
        if ( $orig_type !== null ) {
            $this->options->set_many( array(
                'price_adjustment_type'  => $orig_type,
                'price_adjustment_value' => $orig_value,
            ) );
        }

        return $data;
    }

    // =====================================================================
    // Hook callbacks (called from class-wp-houla.php)
    // =====================================================================

    /**
     * Triggered when a new WooCommerce product is published.
     *
     * @param int        $product_id WooCommerce product ID.
     * @param WC_Product $product    Product object.
     */
    public function on_product_created( $product_id, $product ) {
        if ( self::$syncing || ! $this->should_sync() ) {
            return;
        }

        $sync_on_publish = $this->options->get( 'sync_on_publish' );
        if ( ! $sync_on_publish ) {
            return;
        }

        // Category filter check
        if ( ! $this->should_sync_product( $product ) ) {
            return;
        }

        self::$syncing = true;
        $ws_info       = $this->resolve_workspace_for_product( $product );
        $data          = $this->format_product_for_workspace( $product, $ws_info );
        $overrides     = $ws_info ? $ws_info['overrides'] : array();
        $result        = $this->api->post( '/ecommerce/products', $data, $overrides );

        if ( ! is_wp_error( $result ) && isset( $result['id'] ) ) {
            update_post_meta( $product_id, '_wphoula_product_id', $result['id'] );
            update_post_meta( $product_id, '_wphoula_synced', 1 );
            update_post_meta( $product_id, '_wphoula_sync_at', current_time( 'mysql' ) );
            if ( $ws_info ) {
                update_post_meta( $product_id, '_wphoula_workspace_id', $ws_info['workspace_id'] );
            }
            $this->increment_counter( 'products_synced' );
            $this->log( 'Product #' . $product_id . ' created on Hou.la (ID: ' . $result['id'] . ')' . ( $ws_info ? ' [ws:' . $ws_info['workspace_name'] . ']' : '' ) . '.' );
        } else {
            $msg = is_wp_error( $result ) ? $result->get_error_message() : 'Unknown error';
            $this->log( 'Failed to create product #' . $product_id . ': ' . $msg );
        }

        self::$syncing = false;
    }

    /**
     * Triggered when a WooCommerce product is updated.
     *
     * @param int        $product_id WooCommerce product ID.
     * @param WC_Product $product    Product object.
     */
    public function on_product_updated( $product_id, $product ) {
        if ( self::$syncing || ! $this->should_sync() ) {
            return;
        }

        // Category filter check
        if ( ! $this->should_sync_product( $product ) ) {
            return;
        }

        $houla_id = get_post_meta( $product_id, '_wphoula_product_id', true );
        if ( empty( $houla_id ) ) {
            // Not yet synced - create it if auto_sync is on
            if ( $this->options->get( 'auto_sync' ) ) {
                $this->on_product_created( $product_id, $product );
            }
            return;
        }

        self::$syncing = true;
        $ws_info       = $this->resolve_workspace_for_product( $product );
        $data          = $this->format_product_for_workspace( $product, $ws_info );
        $overrides     = $ws_info ? $ws_info['overrides'] : array();
        $result        = $this->api->patch( '/ecommerce/products/' . $houla_id, $data, $overrides );

        if ( ! is_wp_error( $result ) ) {
            update_post_meta( $product_id, '_wphoula_sync_at', current_time( 'mysql' ) );
            if ( $ws_info ) {
                update_post_meta( $product_id, '_wphoula_workspace_id', $ws_info['workspace_id'] );
            }
            $this->log( 'Product #' . $product_id . ' updated on Hou.la' . ( $ws_info ? ' [ws:' . $ws_info['workspace_name'] . ']' : '' ) . '.' );
        } else {
            $this->log( 'Failed to update product #' . $product_id . ': ' . $result->get_error_message() );
        }

        self::$syncing = false;
    }

    /**
     * Triggered when a WooCommerce product is deleted or trashed.
     *
     * @param int $product_id WooCommerce product ID.
     */
    public function on_product_deleted( $product_id ) {
        if ( self::$syncing || ! $this->should_sync() ) {
            return;
        }

        $houla_id = get_post_meta( $product_id, '_wphoula_product_id', true );
        if ( empty( $houla_id ) ) {
            return;
        }

        self::$syncing = true;

        // Resolve workspace overrides for the delete call
        $overrides = array();
        $stored_ws = get_post_meta( $product_id, '_wphoula_workspace_id', true );
        if ( ! empty( $stored_ws ) ) {
            $ws_map = $this->options->get( 'category_workspace_map' );
            if ( is_array( $ws_map ) ) {
                foreach ( $ws_map as $entry ) {
                    if ( isset( $entry['workspace_id'] ) && $entry['workspace_id'] === $stored_ws && ! empty( $entry['api_key'] ) ) {
                        $api_key = Wp_Houla_Options::decrypt( $entry['api_key'] );
                        if ( $api_key ) {
                            $overrides = array( 'api_key' => $api_key, 'workspace_id' => $stored_ws );
                        }
                        break;
                    }
                }
            }
        }

        $result = $this->api->delete( '/ecommerce/products/' . $houla_id, $overrides );

        if ( ! is_wp_error( $result ) ) {
            delete_post_meta( $product_id, '_wphoula_product_id' );
            delete_post_meta( $product_id, '_wphoula_synced' );
            delete_post_meta( $product_id, '_wphoula_sync_at' );
            delete_post_meta( $product_id, '_wphoula_workspace_id' );
            $this->log( 'Product #' . $product_id . ' deleted from Hou.la.' );
        } else {
            $this->log( 'Failed to delete product #' . $product_id . ': ' . $result->get_error_message() );
        }

        self::$syncing = false;
    }

    /**
     * Triggered when product stock changes.
     *
     * @param WC_Product $product Product object.
     */
    public function on_stock_changed( $product ) {
        if ( self::$syncing || ! $this->should_sync() ) {
            return;
        }

        $product_id = $product->get_id();

        // For variations, sync the parent product
        if ( $product->is_type( 'variation' ) ) {
            $parent_id = $product->get_parent_id();
            $parent    = wc_get_product( $parent_id );
            if ( $parent ) {
                $product_id = $parent_id;
                $product    = $parent;
            }
        }

        // Category filter check
        if ( ! $this->should_sync_product( $product ) ) {
            return;
        }

        $houla_id = get_post_meta( $product_id, '_wphoula_product_id', true );
        if ( empty( $houla_id ) ) {
            return;
        }

        self::$syncing = true;

        // Resolve workspace overrides for the stock update
        $overrides = array();
        $stored_ws = get_post_meta( $product_id, '_wphoula_workspace_id', true );
        if ( ! empty( $stored_ws ) ) {
            $ws_map = $this->options->get( 'category_workspace_map' );
            if ( is_array( $ws_map ) ) {
                foreach ( $ws_map as $entry ) {
                    if ( isset( $entry['workspace_id'] ) && $entry['workspace_id'] === $stored_ws && ! empty( $entry['api_key'] ) ) {
                        $api_key = Wp_Houla_Options::decrypt( $entry['api_key'] );
                        if ( $api_key ) {
                            $overrides = array( 'api_key' => $api_key, 'workspace_id' => $stored_ws );
                        }
                        break;
                    }
                }
            }
        }

        $result = $this->api->patch( '/ecommerce/products/' . $houla_id . '/stock', array(
            'stock_quantity' => $product->get_stock_quantity(),
            'stock_status'   => $product->get_stock_status(),
            'manage_stock'   => $product->get_manage_stock(),
        ), $overrides );

        if ( ! is_wp_error( $result ) ) {
            update_post_meta( $product_id, '_wphoula_sync_at', current_time( 'mysql' ) );
        }

        self::$syncing = false;
    }

    // =====================================================================
    // Batch sync
    // =====================================================================

    /**
     * Build the WC query args for batch sync (shared by all batch methods).
     *
     * @return array
     */
    private function build_batch_query_args() {
        $query_args = array(
            'status' => 'publish',
            'return' => 'objects',
        );

        $sync_categories = $this->options->get( 'sync_categories' );
        if ( ! empty( $sync_categories ) && is_array( $sync_categories ) ) {
            $slugs = array();
            foreach ( $sync_categories as $cat_id ) {
                $term = get_term( absint( $cat_id ), 'product_cat' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $slugs[] = $term->slug;
                }
            }
            if ( ! empty( $slugs ) ) {
                $query_args['category'] = $slugs;
            }
        }

        return $query_args;
    }

    /**
     * Count how many products will be synced.
     *
     * @return int
     */
    public function count_products_to_sync() {
        $args = $this->build_batch_query_args();
        $args['limit']  = -1;
        $args['return'] = 'ids';
        $ids = wc_get_products( $args );
        return count( $ids );
    }

    /**
     * Sync a single page of products.
     *
     * @param int $page Page number (1-based).
     * @return array { synced, errors, page, has_more }
     */
    public function batch_sync_page( $page = 1 ) {
        if ( ! $this->should_sync() ) {
            return array( 'synced' => 0, 'errors' => 0, 'page' => $page, 'has_more' => false, 'message' => 'Not connected' );
        }

        // Register connection on first page only and reset running counters
        if ( $page === 1 ) {
            $this->register_connection();
            set_transient( 'wphoula_batch_running_synced', 0, HOUR_IN_SECONDS );
            set_transient( 'wphoula_batch_running_errors', 0, HOUR_IN_SECONDS );
        }

        $limit = 20;
        $args  = $this->build_batch_query_args();
        $args['limit'] = $limit;
        $args['page']  = $page;

        $products = wc_get_products( $args );

        $synced = 0;
        $errors = 0;

        // Group products by workspace (default workspace vs mapped workspaces)
        // Each group: { overrides: array, new: [{data, wc_id}], update: [{data, wc_id, houla_id}] }
        $groups = array();

        foreach ( $products as $product ) {
            $product_id = $product->get_id();
            $houla_id   = get_post_meta( $product_id, '_wphoula_product_id', true );
            $ws_info    = $this->resolve_workspace_for_product( $product );

            $group_key  = $ws_info ? $ws_info['workspace_id'] : '_default';

            if ( ! isset( $groups[ $group_key ] ) ) {
                $groups[ $group_key ] = array(
                    'overrides' => $ws_info ? $ws_info['overrides'] : array(),
                    'ws_info'   => $ws_info,
                    'new'       => array(),
                    'update'    => array(),
                );
            }

            $data = $this->format_product_for_workspace( $product, $ws_info );

            if ( $houla_id ) {
                $groups[ $group_key ]['update'][] = array( 'wc_id' => $product_id, 'houla_id' => $houla_id, 'data' => $data );
            } else {
                $groups[ $group_key ]['new'][] = array( 'wc_id' => $product_id, 'data' => $data );
            }
        }

        // Process each workspace group
        foreach ( $groups as $group_key => $group ) {
            $overrides = $group['overrides'];
            $ws_info   = $group['ws_info'];

            // 1. Batch-create new products
            if ( ! empty( $group['new'] ) ) {
                $new_data  = array_map( function( $item ) { return $item['data']; }, $group['new'] );
                $new_wcids = array_map( function( $item ) { return $item['wc_id']; }, $group['new'] );

                $batch_result = $this->api->post( '/ecommerce/products/batch', array( 'products' => $new_data ), $overrides );

                if ( is_wp_error( $batch_result ) ) {
                    $error_msg = $batch_result->get_error_message();
                    $this->log( 'Batch create error' . ( $group_key !== '_default' ? ' [ws:' . $group_key . ']' : '' ) . ': ' . $error_msg );

                    if ( $this->is_connection_error( $batch_result ) ) {
                        return array(
                            'synced'           => $synced,
                            'errors'           => $errors + count( $group['new'] ),
                            'page'             => $page,
                            'has_more'         => false,
                            'connection_error' => true,
                            'error_message'    => $error_msg,
                        );
                    }

                    $errors += count( $group['new'] );
                } else {
                    $details = isset( $batch_result['details'] ) ? $batch_result['details'] : array();
                    foreach ( $details as $idx => $detail ) {
                        $wc_id = isset( $new_wcids[ $idx ] ) ? $new_wcids[ $idx ] : null;
                        if ( ! $wc_id ) { continue; }

                        if ( isset( $detail['error'] ) ) {
                            $errors++;
                            $this->log( 'Batch create error for WC #' . $wc_id . ': ' . $detail['error'] );
                        } else {
                            if ( isset( $detail['id'] ) ) {
                                update_post_meta( $wc_id, '_wphoula_product_id', $detail['id'] );
                            }
                            update_post_meta( $wc_id, '_wphoula_synced', 1 );
                            update_post_meta( $wc_id, '_wphoula_sync_at', current_time( 'mysql' ) );
                            if ( $ws_info ) {
                                update_post_meta( $wc_id, '_wphoula_workspace_id', $ws_info['workspace_id'] );
                            }
                            $synced++;
                        }
                    }
                }
            }

            // 2. Batch-update existing products
            if ( ! empty( $group['update'] ) ) {
                $update_data  = array_map( function( $item ) { return $item['data']; }, $group['update'] );
                $update_wcids = array_map( function( $item ) { return $item['wc_id']; }, $group['update'] );

                $batch_result = $this->api->post( '/ecommerce/products/batch', array( 'products' => $update_data ), $overrides );

                if ( is_wp_error( $batch_result ) ) {
                    $error_msg = $batch_result->get_error_message();
                    $this->log( 'Batch update error' . ( $group_key !== '_default' ? ' [ws:' . $group_key . ']' : '' ) . ': ' . $error_msg );

                    if ( $this->is_connection_error( $batch_result ) ) {
                        return array(
                            'synced'           => $synced,
                            'errors'           => $errors + count( $group['update'] ),
                            'page'             => $page,
                            'has_more'         => false,
                            'connection_error' => true,
                            'error_message'    => $error_msg,
                        );
                    }

                    $errors += count( $group['update'] );
                } else {
                    $details = isset( $batch_result['details'] ) ? $batch_result['details'] : array();
                    foreach ( $details as $idx => $detail ) {
                        $wc_id = isset( $update_wcids[ $idx ] ) ? $update_wcids[ $idx ] : null;
                        if ( ! $wc_id ) { continue; }

                        if ( isset( $detail['error'] ) ) {
                            $errors++;
                            $this->log( 'Batch update error for WC #' . $wc_id . ': ' . $detail['error'] );
                        } else {
                            update_post_meta( $wc_id, '_wphoula_synced', 1 );
                            update_post_meta( $wc_id, '_wphoula_sync_at', current_time( 'mysql' ) );
                            if ( $ws_info ) {
                                update_post_meta( $wc_id, '_wphoula_workspace_id', $ws_info['workspace_id'] );
                            }
                            $synced++;
                        }
                    }
                }
            }
        }

        // Accumulate running totals across pages
        $running_synced = (int) get_transient( 'wphoula_batch_running_synced' ) + $synced;
        $running_errors = (int) get_transient( 'wphoula_batch_running_errors' ) + $errors;
        set_transient( 'wphoula_batch_running_synced', $running_synced, HOUR_IN_SECONDS );
        set_transient( 'wphoula_batch_running_errors', $running_errors, HOUR_IN_SECONDS );

        $has_more = count( $products ) === $limit;

        // On last page: persist final counts to options
        if ( ! $has_more ) {
            $this->options->set( 'products_synced', $running_synced );
            $this->options->set( 'last_full_sync', current_time( 'mysql' ) );
            delete_transient( 'wphoula_batch_running_synced' );
            delete_transient( 'wphoula_batch_running_errors' );
        }

        return array( 'synced' => $synced, 'errors' => $errors, 'page' => $page, 'has_more' => $has_more );
    }

    /**
     * Full synchronization of all published WooCommerce products.
     * Called via WP-Cron or admin AJAX.
     *
     * Respects the sync_categories option: when set, only syncs
     * products belonging to the specified WooCommerce categories.
     *
     * @return array Summary (synced, skipped, errors).
     */
    public function batch_sync() {
        if ( ! $this->should_sync() ) {
            return array( 'synced' => 0, 'skipped' => 0, 'errors' => 0, 'message' => 'Not connected' );
        }

        // Register the connection with webhook info before syncing
        $this->register_connection();

        // Prevent concurrent batch syncs
        $lock = get_transient( 'wphoula_batch_sync_lock' );
        if ( $lock ) {
            return array( 'synced' => 0, 'skipped' => 0, 'errors' => 0, 'message' => 'Sync already in progress' );
        }
        set_transient( 'wphoula_batch_sync_lock', true, 600 ); // 10 min lock

        $page    = 1;
        $limit   = 50;
        $synced  = 0;
        $skipped = 0;
        $errors  = 0;

        // Category filtering
        $sync_categories = $this->options->get( 'sync_categories' );
        $query_args = array(
            'status' => 'publish',
            'limit'  => $limit,
            'page'   => $page,
            'return' => 'objects',
        );

        // Filter by WooCommerce categories if configured
        // wc_get_products 'category' expects slugs, so convert term IDs to slugs
        if ( ! empty( $sync_categories ) && is_array( $sync_categories ) ) {
            $slugs = array();
            foreach ( $sync_categories as $cat_id ) {
                $term = get_term( absint( $cat_id ), 'product_cat' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $slugs[] = $term->slug;
                }
            }
            if ( ! empty( $slugs ) ) {
                $query_args['category'] = $slugs;
            }
        }

        do {
            $query_args['page'] = $page;
            $products = wc_get_products( $query_args );

            foreach ( $products as $product ) {
                $product_id = $product->get_id();
                $houla_id   = get_post_meta( $product_id, '_wphoula_product_id', true );
                $ws_info    = $this->resolve_workspace_for_product( $product );
                $data       = $this->format_product_for_workspace( $product, $ws_info );
                $overrides  = $ws_info ? $ws_info['overrides'] : array();

                if ( $houla_id ) {
                    $result = $this->api->patch( '/ecommerce/products/' . $houla_id, $data, $overrides );
                } else {
                    $result = $this->api->post( '/ecommerce/products', $data, $overrides );
                }

                if ( is_wp_error( $result ) ) {
                    $errors++;
                    $this->log( 'Batch sync error for product #' . $product_id . ': ' . $result->get_error_message() );
                    continue;
                }

                if ( ! $houla_id && isset( $result['id'] ) ) {
                    update_post_meta( $product_id, '_wphoula_product_id', $result['id'] );
                }

                update_post_meta( $product_id, '_wphoula_synced', 1 );
                update_post_meta( $product_id, '_wphoula_sync_at', current_time( 'mysql' ) );
                if ( $ws_info ) {
                    update_post_meta( $product_id, '_wphoula_workspace_id', $ws_info['workspace_id'] );
                }
                $synced++;
            }

            $page++;
        } while ( count( $products ) === $limit );

        // Update counters
        $this->options->set( 'products_synced', $synced );
        $this->options->set( 'last_full_sync', current_time( 'mysql' ) );

        delete_transient( 'wphoula_batch_sync_lock' );

        $this->log( "Batch sync complete: $synced synced, $skipped skipped, $errors errors." );

        return compact( 'synced', 'skipped', 'errors' );
    }

    // =====================================================================
    // Product formatting
    // =====================================================================

    /**
     * Format a WooCommerce product into the Hou.la API payload.
     *
     * @param WC_Product $product
     * @return array
     */
    /**
     * Resolve the product brand (marque) for Google Shopping.
     *
     * Tries, in order: WooCommerce native brands (product_brand, WC 9.6+),
     * then the most common third-party brand taxonomies. Returns the first
     * term name found, or empty string. Filterable via `wp_houla_product_brand`.
     *
     * @param WC_Product $product
     * @return string
     */
    private function get_product_brand( $product ) {
        $product_id = $product->get_id();
        $taxonomies = array( 'product_brand', 'pwb-brand', 'yith_product_brand', 'pa_brand' );
        $brand      = '';

        foreach ( $taxonomies as $taxonomy ) {
            if ( ! taxonomy_exists( $taxonomy ) ) {
                continue;
            }
            $terms = wp_get_post_terms( $product_id, $taxonomy, array( 'fields' => 'names' ) );
            if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
                $brand = (string) $terms[0];
                break;
            }
        }

        /**
         * Allow stores with a custom brand source (ACF field, meta, etc.) to override.
         *
         * @param string     $brand   Detected brand name (may be empty).
         * @param WC_Product $product The product being synced.
         */
        $brand = apply_filters( 'wp_houla_product_brand', $brand, $product );

        return is_string( $brand ) ? substr( $brand, 0, 70 ) : '';
    }

    private function format_product( $product ) {
        $data = array(
            'external_id'     => (string) $product->get_id(),
            'platform'        => 'woocommerce',
            'site_url'        => get_site_url(),
            'name'            => $product->get_name(),
            'description'     => wp_strip_all_tags( $product->get_short_description() ?: $product->get_description() ),
            'url'             => $product->get_permalink(),
            'price'           => (float) $product->get_price(),
            'regular_price'   => (float) $product->get_regular_price(),
            'currency'        => get_woocommerce_currency(),
            'status'          => $product->get_status() === 'publish' ? 'active' : 'draft',
            'stock_status'    => $product->get_stock_status(),
            'manage_stock'    => $product->get_manage_stock(),
            'sku'             => $product->get_sku(),
            'brand'           => $this->get_product_brand( $product ),
            'weight'          => $product->get_weight(),
            'type'            => $product->get_type(),
            'virtual'         => $product->is_virtual(),
            'downloadable'    => $product->is_downloadable(),
        );

        // Apply price adjustment
        $adj_type  = $this->options->get( 'price_adjustment_type' );
        $adj_value = (float) $this->options->get( 'price_adjustment_value' );
        if ( $adj_type !== 'none' && $adj_value > 0 ) {
            $data['price']         = $this->adjust_price( $data['price'], $adj_type, $adj_value );
            $data['regular_price'] = $this->adjust_price( $data['regular_price'], $adj_type, $adj_value );
        }

        // Sale price
        $sale_price = $product->get_sale_price();
        if ( $sale_price !== '' ) {
            $adjusted = (float) $sale_price;
            if ( $adj_type !== 'none' && $adj_value > 0 ) {
                $adjusted = $this->adjust_price( $adjusted, $adj_type, $adj_value );
            }
            $data['sale_price'] = $adjusted;
        }

        // Stock quantity
        if ( $product->get_manage_stock() ) {
            $data['stock_quantity'] = $product->get_stock_quantity();
        }

        // Images
        $image_id = $product->get_image_id();
        if ( $image_id ) {
            $data['image'] = wp_get_attachment_url( $image_id );
        }

        $gallery_ids = $product->get_gallery_image_ids();
        if ( ! empty( $gallery_ids ) ) {
            $data['gallery'] = array_filter( array_map( 'wp_get_attachment_url', $gallery_ids ) );
        }

        // Categories
        $categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
        if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
            $data['categories'] = $categories;
        }

        // Category -> Collection mapping: resolve collection_ids
        $cat_collection_map = $this->options->get( 'category_collection_map' );
        if ( ! empty( $cat_collection_map ) && is_array( $cat_collection_map ) ) {
            $cat_ids = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'ids' ) );
            if ( ! is_wp_error( $cat_ids ) && ! empty( $cat_ids ) ) {
                $collection_ids = array();
                foreach ( $cat_ids as $cat_id ) {
                    if ( isset( $cat_collection_map[ $cat_id ] ) && ! empty( $cat_collection_map[ $cat_id ] ) ) {
                        $collection_ids[] = $cat_collection_map[ $cat_id ];
                    }
                }
                $collection_ids = array_unique( $collection_ids );
                if ( ! empty( $collection_ids ) ) {
                    $data['collection_ids'] = array_values( $collection_ids );
                }
            }
        }

        // Tags
        $tags = wp_get_post_terms( $product->get_id(), 'product_tag', array( 'fields' => 'names' ) );
        if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
            $data['tags'] = $tags;
        }

        // Dimensions
        if ( $product->get_length() || $product->get_width() || $product->get_height() ) {
            $data['dimensions'] = array(
                'length' => $product->get_length(),
                'width'  => $product->get_width(),
                'height' => $product->get_height(),
                'unit'   => get_option( 'woocommerce_dimension_unit' ),
            );
        }

        // Variations (for variable products)
        if ( $product->is_type( 'variable' ) ) {
            $variations = array();
            foreach ( $product->get_children() as $variation_id ) {
                $variation = wc_get_product( $variation_id );
                if ( ! $variation ) {
                    continue;
                }
                $variations[] = array(
                    'id'             => $variation_id,
                    'sku'            => $variation->get_sku(),
                    'price'          => (float) $variation->get_price(),
                    'regular_price'  => (float) $variation->get_regular_price(),
                    'sale_price'     => $variation->get_sale_price() !== '' ? (float) $variation->get_sale_price() : null,
                    'stock_quantity' => $variation->get_stock_quantity(),
                    'stock_status'   => $variation->get_stock_status(),
                    'attributes'     => $variation->get_attributes(),
                    'image'          => wp_get_attachment_url( $variation->get_image_id() ) ?: null,
                );
            }
            $data['variations'] = $variations;
        }

        // Attributes (for simple products with visible attributes)
        $attributes = $product->get_attributes();
        if ( ! empty( $attributes ) ) {
            $formatted = array();
            foreach ( $attributes as $attr ) {
                if ( is_a( $attr, 'WC_Product_Attribute' ) && $attr->get_visible() ) {
                    $formatted[] = array(
                        'name'    => $attr->get_name(),
                        'options' => $attr->get_options(),
                    );
                }
            }
            if ( ! empty( $formatted ) ) {
                $data['attributes'] = $formatted;
            }
        }

        // Product identifiers (EAN/GTIN, ISBN) — read from mapped meta keys
        $id_meta_map = $this->options->get( 'identifier_meta_map' );
        if ( is_array( $id_meta_map ) ) {
            $product_id = $product->get_id();
            foreach ( $id_meta_map as $id_key => $meta_key ) {
                if ( empty( $meta_key ) ) {
                    continue;
                }
                $value = get_post_meta( $product_id, $meta_key, true );
                if ( ! empty( $value ) ) {
                    $data[ $id_key ] = sanitize_text_field( $value );
                }
            }
        }

        return $data;
    }

    // =====================================================================
    // Connection registration
    // =====================================================================

    /**
     * Register (or update) the ecommerce connection with Hou.la.
     * Sends the webhook callback URL and secret so Hou.la can
     * push order events (order.paid, order.refunded) back to WP.
     */
    public function register_connection() {
        $webhook_secret = $this->options->get( 'webhook_secret' );

        // Auto-generate webhook secret if not yet set
        if ( empty( $webhook_secret ) ) {
            $webhook_secret = wp_generate_password( 40, false );
            $this->options->set( 'webhook_secret', $webhook_secret );
        }

        $data = array(
            'platform'       => 'woocommerce',
            'site_url'       => get_site_url(),
            'webhook_url'    => rest_url( 'wp-houla/v1/webhook' ),
            'webhook_secret' => $webhook_secret,
            'push_orders'    => true,
            'push_refunds'   => true,
            'push_stock'     => false,
        );

        $result = $this->api->post( '/ecommerce/connect', $data );

        if ( is_wp_error( $result ) ) {
            $this->log( 'Failed to register connection: ' . $result->get_error_message() );
        } else {
            $this->log( 'Ecommerce connection registered successfully.' );
        }
    }

    // =====================================================================
    // Order status sync (WC → Hou.la)
    // =====================================================================

    /**
     * Default concordance: WooCommerce status → Hou.la status.
     * Used as fallback when no custom mapping is configured.
     */
    private static $default_wc_to_houla = array(
        'pending'        => 'pending',
        'on-hold'        => 'pending',
        'open-cart'      => 'open_cart',
        'abandoned-cart' => 'abandoned',
        'processing'     => 'processing',
        'houla-shipping' => 'shipped',
        'completed'      => 'delivered',
        'cancelled'      => 'cancelled',
        'failed'         => 'cancelled',
        'refunded'       => 'refunded',
        'checkout-draft' => 'pending',
        'trash'          => 'cancelled',
        'deleted'        => 'cancelled',
    );

    /**
     * Build the WC → Hou.la status mapping from the saved concordance table.
     * The concordance is now stored as wc-slug => houla_status, so we just strip the prefix.
     *
     * @return array WC status (without wc- prefix) => Hou.la status.
     */
    private function get_wc_to_houla_map() {
        $saved_map = $this->options->get( 'order_status_map' );
        if ( ! is_array( $saved_map ) || empty( $saved_map ) ) {
            return self::$default_wc_to_houla;
        }

        // Saved format: 'wc-xxx' => houla_status, convert to 'xxx' => houla_status
        $result = array();
        foreach ( $saved_map as $wc_slug => $houla_status ) {
            $wc_key = preg_replace( '/^wc-/', '', $wc_slug );
            $result[ $wc_key ] = $houla_status;
        }

        return $result;
    }

    /**
     * Carrier tracking URL patterns.
     * Each carrier maps to a URL template with {tracking} placeholder.
     */
    private static $carrier_tracking_urls = array(
        'colissimo'    => 'https://www.laposte.fr/outils/suivre-vos-envois?code={tracking}',
        'la_poste'     => 'https://www.laposte.fr/outils/suivre-vos-envois?code={tracking}',
        'laposte'      => 'https://www.laposte.fr/outils/suivre-vos-envois?code={tracking}',
        'chronopost'   => 'https://www.chronopost.fr/tracking-no-cms/suivi-page?listeNumerosLT={tracking}',
        'dpd'          => 'https://trace.dpd.fr/fr/trace/{tracking}',
        'gls'          => 'https://gls-group.com/FR/fr/suivi-colis?match={tracking}',
        'ups'          => 'https://www.ups.com/track?tracknum={tracking}',
        'fedex'        => 'https://www.fedex.com/fedextrack/?trknbr={tracking}',
        'dhl'          => 'https://www.dhl.com/fr-fr/home/suivi.html?tracking-id={tracking}',
        'dhl_express'  => 'https://www.dhl.com/fr-fr/home/suivi.html?tracking-id={tracking}',
        'mondialrelay' => 'https://www.mondialrelay.fr/suivi-de-colis/?NumeroExpedition={tracking}',
        'mondial_relay'=> 'https://www.mondialrelay.fr/suivi-de-colis/?NumeroExpedition={tracking}',
        'relais_colis' => 'https://www.relaiscolis.com/suivi-de-colis/index/tracking/{tracking}',
        'tnt'          => 'https://www.tnt.com/express/fr_fr/site/outils-expedition/suivi.html?searchType=con&cons={tracking}',
        'colis_prive'  => 'https://www.colisprive.fr/moncolis/pages/detailColis.aspx?numColis={tracking}',
        'lettre_suivie'=> 'https://www.laposte.fr/outils/suivre-vos-envois?code={tracking}',
        'usps'         => 'https://tools.usps.com/go/TrackConfirmAction?tLabels={tracking}',
        'royal_mail'   => 'https://www.royalmail.com/track-your-item#/tracking-results/{tracking}',
        'postnl'       => 'https://postnl.nl/tracktrace/?B={tracking}',
        'bpost'        => 'https://track.bpost.cloud/btr/web/#/search?itemCode={tracking}',
        'hermes'       => 'https://www.myhermes.co.uk/tracking/{tracking}',
        'dpd_uk'       => 'https://track.dpd.co.uk/parcels/{tracking}',
    );

    /**
     * Build a tracking URL from carrier name and tracking number.
     *
     * @param string $carrier         Carrier identifier (e.g. 'colissimo', 'dpd').
     * @param string $tracking_number Tracking number.
     * @return string|null The tracking URL, or null if carrier is unknown.
     */
    private function build_tracking_url( $carrier, $tracking_number ) {
        $carrier_key = strtolower( sanitize_key( $carrier ) );
        // Try exact match
        if ( isset( self::$carrier_tracking_urls[ $carrier_key ] ) ) {
            return str_replace( '{tracking}', rawurlencode( $tracking_number ), self::$carrier_tracking_urls[ $carrier_key ] );
        }
        // Try partial match (e.g. "colissimo_international" matches "colissimo")
        foreach ( self::$carrier_tracking_urls as $key => $url_template ) {
            if ( strpos( $carrier_key, $key ) !== false || strpos( $key, $carrier_key ) !== false ) {
                return str_replace( '{tracking}', rawurlencode( $tracking_number ), $url_template );
            }
        }
        return null;
    }

    /**
     * Extract tracking information from a WooCommerce order.
     * Supports multiple popular tracking plugins.
     *
     * @param WC_Order $order WooCommerce order.
     * @return array|null { carrier: string, tracking_number: string, tracking_url: string|null } or null.
     */
    private function extract_tracking_info( $order ) {
        $order_id = $order->get_id();

        // 1. Advanced Shipment Tracking (AST) — most popular plugin
        //    Stores data in '_wc_shipment_tracking_items' order meta (serialized array)
        $ast_items = $order->get_meta( '_wc_shipment_tracking_items' );
        if ( ! empty( $ast_items ) && is_array( $ast_items ) ) {
            $item = end( $ast_items ); // Get the last (most recent) tracking entry
            $carrier  = isset( $item['tracking_provider'] ) ? $item['tracking_provider'] : '';
            $number   = isset( $item['tracking_number'] ) ? $item['tracking_number'] : '';
            if ( ! empty( $carrier ) ) {
                $carrier = strtolower( str_replace( ' ', '_', $carrier ) );
            }
            if ( ! empty( $number ) ) {
                return array(
                    'carrier'         => $carrier ?: 'other',
                    'tracking_number' => $number,
                    'tracking_url'    => $this->build_tracking_url( $carrier, $number ),
                );
            }
        }

        // 2. WooCommerce Shipment Tracking (official WooCommerce extension)
        //    Also uses '_wc_shipment_tracking_items' — same format as AST

        // 3. YITH WooCommerce Order Tracking
        $yith_carrier = $order->get_meta( 'ywot_tracking_code' );
        $yith_name    = $order->get_meta( 'ywot_carrier_name' );
        if ( ! empty( $yith_carrier ) ) {
            $carrier = ! empty( $yith_name ) ? strtolower( str_replace( ' ', '_', $yith_name ) ) : 'other';
            return array(
                'carrier'         => $carrier,
                'tracking_number' => $yith_carrier,
                'tracking_url'    => $this->build_tracking_url( $carrier, $yith_carrier ),
            );
        }

        // 4. Generic fallback: check common meta keys
        $tracking_number = '';
        $carrier_name    = '';
        $meta_keys_tracking = array( '_tracking_number', '_shipment_tracking_number', 'tracking_number', '_wc_tracking_number' );
        $meta_keys_carrier  = array( '_tracking_provider', '_shipping_provider', 'carrier', '_carrier', '_wc_tracking_provider' );

        foreach ( $meta_keys_tracking as $key ) {
            $val = $order->get_meta( $key );
            if ( ! empty( $val ) ) {
                $tracking_number = $val;
                break;
            }
        }
        foreach ( $meta_keys_carrier as $key ) {
            $val = $order->get_meta( $key );
            if ( ! empty( $val ) ) {
                $carrier_name = strtolower( str_replace( ' ', '_', $val ) );
                break;
            }
        }

        if ( ! empty( $tracking_number ) ) {
            return array(
                'carrier'         => $carrier_name ?: 'other',
                'tracking_number' => $tracking_number,
                'tracking_url'    => $this->build_tracking_url( $carrier_name, $tracking_number ),
            );
        }

        return null;
    }

    /**
     * Triggered when a WooCommerce order status changes.
     *
     * Sends the new status + tracking info to the Hou.la API so it stays in sync.
     * Only fires for orders that have a _houla_order_id meta.
     * Skips sync if _houla_skip_sync flag is set (loop prevention).
     *
     * @param int      $order_id   WooCommerce order ID.
     * @param string   $old_status Old status (without 'wc-' prefix).
     * @param string   $new_status New status (without 'wc-' prefix).
     * @param WC_Order $order      Order object.
     */
    public function on_order_status_changed( $order_id, $old_status, $new_status, $order ) {
        if ( ! $this->auth->is_connected() ) {
            return;
        }

        // Prevent infinite loop: skip if this change came from a Hou.la webhook
        $skip = $order->get_meta( '_houla_skip_sync' );
        if ( $skip === '1' ) {
            $this->log( 'Order #' . $order_id . ' status change skipped (from Hou.la webhook).' );
            return;
        }

        // Only sync orders that were created via Hou.la
        $houla_order_id = $order->get_meta( '_houla_order_id' );
        if ( empty( $houla_order_id ) ) {
            return;
        }

        // Map WC status to Hou.la status using configurable concordance
        $wc_to_houla = $this->get_wc_to_houla_map();
        $houla_status = isset( $wc_to_houla[ $new_status ] )
            ? $wc_to_houla[ $new_status ]
            : null;

        if ( ! $houla_status ) {
            $this->log( 'Order #' . $order_id . ': no Hou.la mapping for WC status "' . $new_status . '".' );
            return;
        }

        // Build the API payload
        $payload = array( 'wc_status' => $new_status );

        // Extract and include tracking information if enabled
        if ( $this->options->get( 'sync_tracking' ) ) {
            $tracking = $this->extract_tracking_info( $order );
            if ( $tracking ) {
                $payload['carrier']         = $tracking['carrier'];
                $payload['tracking_number'] = $tracking['tracking_number'];
                if ( ! empty( $tracking['tracking_url'] ) ) {
                    $payload['tracking_url'] = $tracking['tracking_url'];
                }
                $this->log( 'Order #' . $order_id . ': tracking found — ' . $tracking['carrier'] . ' ' . $tracking['tracking_number'] );
            }
        }

        // Do NOT push a spurious close of a still-open cart back to Hou.la. Store
        // automations (virtual-product auto-complete, or shipping plugins moving paid
        // orders to 'processing') flip wc-open-cart → completed/processing with no real
        // shipment; forwarding that would close the buyer's open cart on Hou.la and force
        // them to re-pay shipping on their next purchase. A genuine shipment (tracking
        // present) or a terminal cancel/refund is still forwarded normally.
        if ( in_array( $old_status, array( 'open-cart', 'wc-open-cart' ), true ) ) {
            $is_terminal  = in_array( $houla_status, array( 'cancelled', 'refunded' ), true );
            $has_tracking = ! empty( $payload['tracking_number'] );
            if ( ! $is_terminal && ! $has_tracking ) {
                $this->log( 'Order #' . $order_id . ': suppressing spurious open-cart close (' . $old_status . ' → ' . $new_status . ', no tracking).' );
                return;
            }
        }

        $result = $this->api->patch(
            '/ecommerce/orders/' . $houla_order_id . '/status',
            $payload
        );

        if ( is_wp_error( $result ) ) {
            $order->update_meta_data( '_houla_sync_status', 'failed' );
            $order->update_meta_data( '_houla_sync_error', $result->get_error_message() );
            $order->save();
            $this->log( 'Order #' . $order_id . ' status sync failed: ' . $result->get_error_message() );
        } else {
            $order->update_meta_data( '_houla_sync_status', 'synced' );
            $order->update_meta_data( '_houla_sync_at', current_time( 'mysql' ) );
            $order->delete_meta_data( '_houla_sync_error' );
            $order->save();
            $this->log( 'Order #' . $order_id . ' status synced to Hou.la: ' . $new_status . ' → ' . $houla_status . '.' );
        }
    }

    /**
     * Manually resync a single order status to Hou.la.
     *
     * @param int $order_id WooCommerce order ID.
     * @return array { success: bool, message: string }
     */
    public function resync_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return array( 'success' => false, 'message' => 'Order not found' );
        }

        $houla_order_id = $order->get_meta( '_houla_order_id' );
        if ( empty( $houla_order_id ) ) {
            return array( 'success' => false, 'message' => 'Not a Hou.la order' );
        }

        $wc_status    = $order->get_status();
        $wc_to_houla  = $this->get_wc_to_houla_map();
        $houla_status = isset( $wc_to_houla[ $wc_status ] ) ? $wc_to_houla[ $wc_status ] : null;

        if ( ! $houla_status ) {
            return array( 'success' => false, 'message' => 'No mapping for status "' . $wc_status . '"' );
        }

        $payload = array( 'wc_status' => $wc_status );

        if ( $this->options->get( 'sync_tracking' ) ) {
            $tracking = $this->extract_tracking_info( $order );
            if ( $tracking ) {
                $payload['carrier']         = $tracking['carrier'];
                $payload['tracking_number'] = $tracking['tracking_number'];
                if ( ! empty( $tracking['tracking_url'] ) ) {
                    $payload['tracking_url'] = $tracking['tracking_url'];
                }
            }
        }

        $result = $this->api->patch(
            '/ecommerce/orders/' . $houla_order_id . '/status',
            $payload
        );

        if ( is_wp_error( $result ) ) {
            $order->update_meta_data( '_houla_sync_status', 'failed' );
            $order->update_meta_data( '_houla_sync_error', $result->get_error_message() );
            $order->save();
            return array( 'success' => false, 'message' => $result->get_error_message() );
        }

        $order->update_meta_data( '_houla_sync_status', 'synced' );
        $order->update_meta_data( '_houla_sync_at', current_time( 'mysql' ) );
        $order->delete_meta_data( '_houla_sync_error' );
        $order->save();
        return array( 'success' => true, 'message' => 'Synced' );
    }

    /**
     * Batch resync all Hou.la orders back to the API.
     * Re-pushes the current WC status for each order that has a _houla_order_id.
     *
     * @param string $filter 'all' | 'failed' - which orders to resync.
     * @return array { synced: int, failed: int, skipped: int, total: int }
     */
    public function batch_resync_orders( $filter = 'all' ) {
        if ( ! $this->auth->is_connected() ) {
            return array( 'synced' => 0, 'failed' => 0, 'skipped' => 0, 'total' => 0, 'message' => 'Not connected' );
        }

        global $wpdb;

        // Determine storage: HPOS uses wc_orders_meta, legacy uses postmeta
        $hpos = class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' )
             && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ( $hpos ) {
            $meta_table  = $wpdb->prefix . 'wc_orders_meta';
            $order_table = $wpdb->prefix . 'wc_orders';
            $id_col      = 'order_id';
            $order_id_fk = 'o.id';
            $status_cond = "AND o.status NOT IN ('trash','auto-draft')";
        } else {
            $meta_table  = $wpdb->postmeta;
            $order_table = $wpdb->posts;
            $id_col      = 'post_id';
            $order_id_fk = 'o.ID';
            $status_cond = "AND o.post_status NOT IN ('trash','auto-draft')";
        }

        if ( $filter === 'failed' ) {
            // Orders that have _houla_order_id AND _houla_sync_status = 'failed'
            $order_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT m1.{$id_col}
                 FROM {$meta_table} m1
                 INNER JOIN {$meta_table} m2 ON m1.{$id_col} = m2.{$id_col}
                 INNER JOIN {$order_table} o ON m1.{$id_col} = {$order_id_fk}
                 WHERE m1.meta_key = %s AND m2.meta_key = %s AND m2.meta_value = %s {$status_cond}",
                '_houla_order_id', '_houla_sync_status', 'failed'
            ) );
        } else {
            // All orders with _houla_order_id
            $order_ids = $wpdb->get_col( $wpdb->prepare(
                "SELECT DISTINCT m.{$id_col}
                 FROM {$meta_table} m
                 INNER JOIN {$order_table} o ON m.{$id_col} = {$order_id_fk}
                 WHERE m.meta_key = %s {$status_cond}",
                '_houla_order_id'
            ) );
        }

        if ( ! is_array( $order_ids ) ) {
            $order_ids = array();
        }
        $order_ids = array_map( 'absint', $order_ids );
        $synced  = 0;
        $failed  = 0;
        $skipped = 0;

        foreach ( $order_ids as $oid ) {
            $res = $this->resync_order( $oid );
            if ( $res['success'] ) {
                $synced++;
            } elseif ( $res['message'] === 'No mapping for status "' . wc_get_order( $oid )->get_status() . '"' ) {
                $skipped++;
            } else {
                $failed++;
            }
        }

        return array(
            'synced'  => $synced,
            'failed'  => $failed,
            'skipped' => $skipped,
            'total'   => count( $order_ids ),
        );
    }

    /**
     * Pull orders from the Hou.la API and create/update them in WooCommerce.
     *
     * Triggers the API to re-push all recent orders via webhooks.
     * Thanks to the upsert logic in create_order(), existing WC orders
     * will be updated (items replaced) and missing ones will be created.
     *
     * @param string $filter 'all' | 'failed' — which orders to re-push.
     * @return array { succeeded: int, failed: int, total: int, message: string }
     */
    public function pull_orders_from_api( $filter = 'all' ) {
        if ( ! $this->auth->is_connected() ) {
            return array( 'succeeded' => 0, 'failed' => 0, 'total' => 0, 'message' => 'Not connected' );
        }

        $endpoint = ( $filter === 'failed' ) ? '/ecommerce/orders/resync-failed' : '/ecommerce/orders/resync-all';
        $result = $this->api->post( $endpoint );

        if ( is_wp_error( $result ) ) {
            $this->log( 'Pull orders from API failed: ' . $result->get_error_message() );
            return array(
                'succeeded' => 0,
                'failed'    => 0,
                'total'     => 0,
                'message'   => $result->get_error_message(),
            );
        }

        $this->log( 'Pull orders from API: ' . ( $result['succeeded'] ?? 0 ) . ' succeeded, ' . ( $result['failed'] ?? 0 ) . ' failed out of ' . ( $result['total'] ?? 0 ) . '.' );

        return array(
            'succeeded' => $result['succeeded'] ?? 0,
            'failed'    => $result['failed'] ?? 0,
            'total'     => $result['total'] ?? 0,
            'message'   => 'OK',
        );
    }

    /**
     * Count Hou.la orders by sync status.
     *
     * @return array { total: int, synced: int, failed: int, pending: int }
     */
    public function count_houla_orders() {
        global $wpdb;

        // Determine storage: HPOS uses wc_orders_meta, legacy uses postmeta
        $hpos = class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' )
             && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

        if ( $hpos ) {
            $meta_table  = $wpdb->prefix . 'wc_orders_meta';
            $order_table = $wpdb->prefix . 'wc_orders';
            $id_col      = 'order_id';
            $status_join = "INNER JOIN {$order_table} o ON m.{$id_col} = o.id";
            $status_cond = "AND o.status NOT IN ('trash','auto-draft')";
        } else {
            $meta_table  = $wpdb->postmeta;
            $order_table = $wpdb->posts;
            $id_col      = 'post_id';
            $status_join = "INNER JOIN {$order_table} o ON m.{$id_col} = o.ID";
            $status_cond = "AND o.post_status NOT IN ('trash','auto-draft')";
        }

        // Total orders with _houla_order_id (excluding trashed)
        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT m.{$id_col}) FROM {$meta_table} m {$status_join} WHERE m.meta_key = %s {$status_cond}",
            '_houla_order_id'
        ) );

        // Synced orders
        $synced = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT m.{$id_col}) FROM {$meta_table} m {$status_join} WHERE m.meta_key = %s AND m.meta_value = %s {$status_cond}",
            '_houla_sync_status', 'synced'
        ) );

        // Failed orders
        $failed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT m.{$id_col}) FROM {$meta_table} m {$status_join} WHERE m.meta_key = %s AND m.meta_value = %s {$status_cond}",
            '_houla_sync_status', 'failed'
        ) );

        return array(
            'total'   => $total,
            'synced'  => $synced,
            'failed'  => $failed,
            'pending' => max( 0, $total - $synced - $failed ),
        );
    }

    // =====================================================================
    // Pull orders from Hou.la → WooCommerce
    // =====================================================================

    /**
     * Pull orders from Hou.la API and create/update them in WooCommerce.
     * This is the reverse of webhook push: useful when webhooks were missed
     * (e.g. WooCommerce server was down).
     *
     * @param string $since  ISO date — only pull orders updated after this date. Empty = all.
     * @return array { created: int, updated: int, failed: int, total: int, errors: string[] }
     */
    public function pull_orders_from_houla( $since = '' ) {
        if ( ! $this->auth->is_connected() ) {
            return array( 'created' => 0, 'updated' => 0, 'failed' => 0, 'total' => 0, 'errors' => array( 'Not connected' ) );
        }

        // Fetch orders from the API
        $query = array( 'limit' => '200' );
        if ( ! empty( $since ) ) {
            $query['since'] = $since;
        }

        $result = $this->api->get( '/ecommerce/orders', $query );

        if ( is_wp_error( $result ) ) {
            $this->log( 'Pull orders failed: ' . $result->get_error_message() );
            return array( 'created' => 0, 'updated' => 0, 'failed' => 0, 'total' => 0, 'errors' => array( $result->get_error_message() ) );
        }

        if ( empty( $result['orders'] ) || ! is_array( $result['orders'] ) ) {
            return array( 'created' => 0, 'updated' => 0, 'failed' => 0, 'total' => 0, 'errors' => array() );
        }

        $orders_handler = new Wp_Houla_Orders();
        $created = 0;
        $updated = 0;
        $failed  = 0;
        $errors  = array();

        foreach ( $result['orders'] as $order_data ) {
            if ( empty( $order_data['houla_order_id'] ) ) {
                continue;
            }

            try {
                // Build the payload in webhook format for create_order/update_order
                $payload = $this->format_houla_order_for_wc( $order_data );

                // Check if order already exists in WC
                $existing = $orders_handler->find_order_by_houla_id( $order_data['houla_order_id'] );

                if ( $existing ) {
                    // Update existing order
                    $res = $orders_handler->update_order( $payload );
                    if ( is_wp_error( $res ) ) {
                        $failed++;
                        $errors[] = 'Update ' . $order_data['houla_order_id'] . ': ' . $res->get_error_message();
                    } else {
                        $updated++;
                    }
                } else {
                    // Create new order
                    $res = $orders_handler->create_order( $payload );
                    if ( is_wp_error( $res ) ) {
                        $failed++;
                        $errors[] = 'Create ' . $order_data['houla_order_id'] . ': ' . $res->get_error_message();
                    } else {
                        $created++;
                    }
                }
            } catch ( \Throwable $e ) {
                $failed++;
                $errors[] = $order_data['houla_order_id'] . ': ' . $e->getMessage();
                $this->log( 'Pull order error: ' . $e->getMessage() );
            }
        }

        // Update last pull timestamp
        $this->options->set( 'last_order_pull', current_time( 'mysql' ) );

        $this->log( "Pull orders complete: $created created, $updated updated, $failed failed." );

        return array(
            'created' => $created,
            'updated' => $updated,
            'failed'  => $failed,
            'total'   => count( $result['orders'] ),
            'errors'  => $errors,
        );
    }

    /**
     * Format the Hou.la order API response into the same structure
     * that the webhook handler expects.
     *
     * @param array $order_data Order data from GET /api/ecommerce/orders.
     * @return array Formatted payload for Wp_Houla_Orders::create_order().
     */
    private function format_houla_order_for_wc( $order_data ) {
        $payload = array(
            'houla_order_id' => $order_data['houla_order_id'],
            'transaction_id' => isset( $order_data['transaction_id'] ) ? $order_data['transaction_id'] : '',
            'customer'       => isset( $order_data['customer'] ) ? $order_data['customer'] : array(),
            'items'          => isset( $order_data['items'] ) ? $order_data['items'] : array(),
            'shipping'       => isset( $order_data['shipping'] ) ? $order_data['shipping'] : null,
            'total'          => isset( $order_data['total'] ) ? $order_data['total'] : 0,
            'currency'       => isset( $order_data['currency'] ) ? $order_data['currency'] : 'EUR',
            'paid_at'        => isset( $order_data['paid_at'] ) ? $order_data['paid_at'] : '',
            'wc_status'      => isset( $order_data['wc_status'] ) ? $order_data['wc_status'] : 'processing',
        );

        // Map items: the API uses 'product_id' but WC expects 'external_id'
        if ( ! empty( $payload['items'] ) ) {
            foreach ( $payload['items'] as &$item ) {
                if ( ! isset( $item['external_id'] ) && isset( $item['product_id'] ) ) {
                    $item['external_id'] = $item['product_id'];
                }
            }
            unset( $item );
        }

        return $payload;
    }

    // =====================================================================
    // Utilities
    // =====================================================================

    /**
     * Check if a WP_Error represents a connection-level failure
     * (server unreachable, DNS failure, timeout, SSL error).
     *
     * @param WP_Error $wp_error
     * @return bool
     */
    private function is_connection_error( $wp_error ) {
        $message = $wp_error->get_error_message();

        return (
            false !== strpos( $message, 'cURL error 6' )  || // Could not resolve host
            false !== strpos( $message, 'cURL error 7' )  || // Could not connect
            false !== strpos( $message, 'cURL error 28' ) || // Connection timed out
            false !== strpos( $message, 'cURL error 35' ) || // SSL connect error
            false !== strpos( $message, 'cURL error 56' )    // Recv failure / connection reset
        );
    }

    /**
     * Can we sync? Checks auth + auto_sync option.
     *
     * @return bool
     */
    private function should_sync() {
        return $this->auth->is_connected() && $this->options->get( 'auto_sync' );
    }

    /**
     * Check if a specific product should be synced based on category filters.
     *
     * @param int|WC_Product $product Product ID or object.
     * @return bool
     */
    private function should_sync_product( $product ) {
        $sync_categories = $this->options->get( 'sync_categories' );
        if ( empty( $sync_categories ) || ! is_array( $sync_categories ) ) {
            return true; // No filter = sync everything
        }

        $product_id = is_object( $product ) ? $product->get_id() : $product;
        $product_cats = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );

        if ( is_wp_error( $product_cats ) || empty( $product_cats ) ) {
            return false; // No categories and we have a filter = skip
        }

        // Check if any of the product's categories match the filter
        return ! empty( array_intersect( $product_cats, array_map( 'absint', $sync_categories ) ) );
    }

    /**
     * Increment a numeric counter in options.
     *
     * @param string $key Option key.
     */
    private function increment_counter( $key ) {
        $val = (int) $this->options->get( $key );
        $this->options->set( $key, $val + 1 );
    }

    /**
     * Log a debug message.
     *
     * @param string $message
     */
    private function log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WP-Houla Sync] ' . $message );
        }
    }

    /**
     * Apply a price adjustment (markup or discount).
     *
     * @param float  $price    Original price.
     * @param string $type     Adjustment type (percent_up, percent_down, fixed_up, fixed_down).
     * @param float  $value    Adjustment value.
     * @return float Adjusted price (never negative).
     */
    private function adjust_price( $price, $type, $value ) {
        if ( $price <= 0 || $value <= 0 ) {
            return $price;
        }

        switch ( $type ) {
            case 'percent_up':
                return round( $price * ( 1 + $value / 100 ), 2 );
            case 'percent_down':
                return max( 0, round( $price * ( 1 - $value / 100 ), 2 ) );
            case 'fixed_up':
                return round( $price + $value, 2 );
            case 'fixed_down':
                return max( 0, round( $price - $value, 2 ) );
            default:
                return $price;
        }
    }

    // =====================================================================
    // WP-Cron full sync
    // =====================================================================

    /**
     * Run a full product sync from WP-Cron context.
     *
     * Mirrors the logic of ajax_bg_sync_process() in Wp_Houla_Admin,
     * but without AJAX context (no nonce, no wp_send_json).
     * Uses the same flock mechanism to avoid overlap with manual syncs.
     */
    public function cron_full_sync() {
        // Only sync if connected
        if ( ! $this->auth->is_connected() ) {
            $this->log( '[CronSync] Skipped: not connected.' );
            return;
        }

        // ═══ 1. ACQUIRE EXCLUSIVE LOCK ═══
        $lock_file = sys_get_temp_dir() . '/wphoula_sync_' . md5( ABSPATH ) . '.lock';
        $lock_fp   = fopen( $lock_file, 'w' );
        if ( ! $lock_fp || ! flock( $lock_fp, LOCK_EX | LOCK_NB ) ) {
            if ( $lock_fp ) {
                fclose( $lock_fp );
            }
            $this->log( '[CronSync] Skipped: another sync is already running.' );
            return;
        }

        // ═══ 2. ALLOW LONG EXECUTION ═══
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 1800 );
        }
        ignore_user_abort( true );

        $this->log( '[CronSync] Starting automatic sync...' );

        // Register connection on start
        $this->register_connection();

        $page         = 1;
        $total_synced = 0;
        $total_errors = 0;
        $max_retries  = 3;

        // ═══ 3. MAIN LOOP ═══
        while ( true ) {
            $result = null;
            for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
                $result = $this->batch_sync_page( $page );

                if ( empty( $result['connection_error'] ) ) {
                    break;
                }

                if ( $attempt < $max_retries ) {
                    sleep( $attempt * 5 );
                }
            }

            $total_synced += $result['synced'];
            $total_errors += $result['errors'];

            // Connection error after all retries → abort
            if ( ! empty( $result['connection_error'] ) ) {
                $msg = isset( $result['error_message'] ) ? $result['error_message'] : 'Connection error';
                $this->log( '[CronSync] Aborted at page ' . $page . ': ' . $msg );
                break;
            }

            if ( ! $result['has_more'] ) {
                break;
            }

            $page++;
            usleep( 500000 ); // 500ms between pages
        }

        // ═══ 4. UPDATE COUNTERS ═══
        $this->options->set_many( array(
            'products_synced' => $total_synced,
            'last_full_sync'  => current_time( 'mysql' ),
            'last_cron_sync'  => current_time( 'mysql' ),
        ) );

        $this->log( '[CronSync] Completed: ' . $total_synced . ' synced, ' . $total_errors . ' errors.' );

        // ═══ 5. RELEASE LOCK ═══
        flock( $lock_fp, LOCK_UN );
        fclose( $lock_fp );
        @unlink( $lock_file );
    }
}
