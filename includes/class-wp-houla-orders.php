<?php
/**
 * WooCommerce order creation from Hou.la webhook payloads.
 *
 * When a buyer completes a purchase through a Hou.la bio page,
 * Hou.la sends an order.paid webhook. This class:
 * - Creates a WooCommerce order via wc_create_order()
 * - Maps products by external_id (WooCommerce product ID)
 * - Sets billing/shipping from the webhook payload
 * - Marks payment as "houla_pay"
 * - Takes the stock once, with WooCommerce's bookkeeping (reduce_order_stock)
 * - Stores Hou.la metadata (_houla_order_id, _houla_transaction_id)
 *
 * @since      1.0.0
 * @package    Wp_Houla
 * @subpackage Wp_Houla/includes
 */

class Wp_Houla_Orders {

    /** @var Wp_Houla_Options */
    private $options;

    public function __construct() {
        $this->options = new Wp_Houla_Options();
    }

    // =====================================================================
    // Order creation
    // =====================================================================

    /**
     * Create a WooCommerce order from a Hou.la order.paid webhook.
     *
     * Expected $data structure:
     * {
     *   "houla_order_id":    "ord_abc123",
     *   "transaction_id":    "pi_xyz456",
     *   "customer": {
     *     "email":      "buyer@example.com",
     *     "first_name": "John",
     *     "last_name":  "Doe",
     *     "phone":      "+33612345678",
     *     "address": {
     *       "line1":       "12 rue de la Paix",
     *       "line2":       "Apt 3",
     *       "city":        "Paris",
     *       "state":       "IDF",
     *       "postcode":    "75002",
     *       "country":     "FR"
     *     }
     *   },
     *   "items": [
     *     { "external_id": "42", "quantity": 2, "price": 29.90, "variation_id": null },
     *     { "external_id": "77", "quantity": 1, "price": 15.00, "variation_id": "83" }
     *   ],
     *   "shipping": {
     *     "method":  "flat_rate",
     *     "amount":  5.90,
     *     "label":   "Livraison standard"
     *   },
     *   "total":    74.80,
     *   "currency": "EUR",
     *   "paid_at":  "2025-01-15T14:30:00Z"
     * }
     *
     * @param array $data Parsed webhook payload.
     * @return array|WP_Error Array with 'order_id' on success, WP_Error on failure.
     */
    public function create_order( $data ) {
        return $this->locked( $data, 'do_create_order' );
    }

    /**
     * @see create_order() — runs under the order lock.
     * @param array $data Parsed webhook payload.
     * @return array|WP_Error
     */
    private function do_create_order( $data ) {
        // Validate required fields
        if ( empty( $data['houla_order_id'] ) || empty( $data['items'] ) ) {
            return new WP_Error( 'wphoula_invalid_order', 'Missing houla_order_id or items.' );
        }

        // Check for duplicate order — upsert: update if already exists
        $existing = $this->find_order_by_houla_id( $data['houla_order_id'] );
        if ( $existing ) {
            $this->log( 'Duplicate order found: ' . $data['houla_order_id'] . ' (WC #' . $existing . '). Updating items.' );
            return $this->update_order( $data );
        }

        try {
            $order = wc_create_order();

            // ----------------------------------------------------------
            // Line items
            // ----------------------------------------------------------
            foreach ( $data['items'] as $item ) {
                $product_id   = absint( $item['external_id'] );
                $variation_id = ! empty( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
                $quantity     = max( 1, absint( $item['quantity'] ) );
                $price        = isset( $item['price'] ) ? floatval( $item['price'] ) : 0;

                $product = wc_get_product( $variation_id ?: $product_id );
                if ( ! $product ) {
                    // Product not found in WooCommerce - add as a custom line item
                    // so the order total stays correct
                    $item_name = ! empty( $item['name'] ) ? sanitize_text_field( $item['name'] ) : 'Hou.la Product #' . $product_id;
                    $this->log( 'Product #' . $product_id . ' not found in WC, adding as custom fee: ' . $item_name );
                    $fee = new WC_Order_Item_Fee();
                    $fee->set_name( $item_name . ' (x' . $quantity . ')' );
                    $fee->set_amount( $price * $quantity );
                    $fee->set_total( $price * $quantity );
                    $fee->set_tax_status( 'none' );
                    $order->add_item( $fee );
                } else {
                    $order->add_product( $product, $quantity, array(
                        'subtotal' => $price * $quantity,
                        'total'    => $price * $quantity,
                    ) );
                }

                // Stock: taken ONCE, after the save (reduce_order_stock).
                // Until 1.6.8 it was decreased here by hand, then WooCommerce
                // decreased it AGAIN when the order entered « processing » or
                // « on-hold » (wc_maybe_reduce_stock_levels): a product sold
                // once on Hou.la, during a live for instance, lost 2 units in
                // WooCommerce, and the module pushed that wrong stock back to
                // Hou.la (banc du 2026-11-09 : 10 → 8 pour 1 vente).
            }

            // ----------------------------------------------------------
            // Customer / Billing / Shipping
            // ----------------------------------------------------------
            $customer         = isset( $data['customer'] ) ? $data['customer'] : array();
            $address          = isset( $customer['address'] ) ? $customer['address'] : array();
            $billing_address  = isset( $customer['billing_address'] ) ? $customer['billing_address'] : array();

            // Billing: prefer dedicated billing_address if provided, fall back to shipping address
            $billing = array(
                'first_name' => $this->safe( $billing_address, 'first_name', $this->safe( $customer, 'first_name' ) ),
                'last_name'  => $this->safe( $billing_address, 'last_name', $this->safe( $customer, 'last_name' ) ),
                'email'      => $this->safe( $customer, 'email' ),
                'phone'      => $this->safe( $customer, 'phone' ),
                'address_1'  => $this->safe( $billing_address, 'line1', $this->safe( $address, 'line1' ) ),
                'address_2'  => $this->safe( $billing_address, 'line2', $this->safe( $address, 'line2' ) ),
                'city'       => $this->safe( $billing_address, 'city', $this->safe( $address, 'city' ) ),
                'state'      => $this->safe( $billing_address, 'state', $this->safe( $address, 'state' ) ),
                'postcode'   => $this->safe( $billing_address, 'postcode', $this->safe( $address, 'postcode' ) ),
                'country'    => $this->safe( $billing_address, 'country', $this->safe( $address, 'country' ) ),
            );

            // Shipping: always from the shipping address
            $shipping = array(
                'first_name' => $this->safe( $customer, 'first_name' ),
                'last_name'  => $this->safe( $customer, 'last_name' ),
                'address_1'  => $this->safe( $address, 'line1' ),
                'address_2'  => $this->safe( $address, 'line2' ),
                'city'       => $this->safe( $address, 'city' ),
                'state'      => $this->safe( $address, 'state' ),
                'postcode'   => $this->safe( $address, 'postcode' ),
                'country'    => $this->safe( $address, 'country' ),
            );

            $order->set_address( $billing, 'billing' );
            $order->set_address( $shipping, 'shipping' );

            // Buyer workspace identity (pseudo / display name / avatar).
            $this->apply_buyer_identity( $order, $customer );

            // Attribute this order to Hou.la in the WooCommerce "Origin" column.
            $this->apply_order_origin( $order );

            // Sendcloud service point (Mondial Relay / locker) metadata — set on BOTH
            // create AND update so re-synced/merged orders also carry it.
            $this->apply_service_point( $order, $customer );

            // Cliente déjà connue du site : la commande lui est rattachée
            // (Trello #146). Création seulement : un compte peut être créé ici
            // si la vendeuse l'a demandé.
            $this->attach_customer( $order, $customer, true, isset( $data['paid_at'] ) ? (string) $data['paid_at'] : '' );

            // ----------------------------------------------------------
            // Shipping
            // ----------------------------------------------------------
            if ( ! empty( $data['shipping'] ) ) {
                $shipping_item = new WC_Order_Item_Shipping();
                $shipping_item->set_method_title( $this->safe( $data['shipping'], 'label', 'Houla Shipping' ) );
                $shipping_item->set_method_id( $this->safe( $data['shipping'], 'method', 'flat_rate' ) );
                $shipping_item->set_total( floatval( $data['shipping']['amount'] ?? 0 ) );
                $order->add_item( $shipping_item );
            }

            // ----------------------------------------------------------
            // Payment & metadata
            // ----------------------------------------------------------
            $order->set_payment_method( 'houla_pay' );
            $order->set_payment_method_title( 'Hou.la Pay (Stripe)' );

            if ( ! empty( $data['currency'] ) ) {
                $order->set_currency( strtoupper( $data['currency'] ) );
            }

            // Store Hou.la metadata
            $order->update_meta_data( '_houla_order_id', sanitize_text_field( $data['houla_order_id'] ) );
            $order->update_meta_data( '_houla_sync_status', 'synced' );
            $order->update_meta_data( '_houla_sync_at', current_time( 'mysql' ) );
            // Created by 1.6.9 or later: never taken for a pre-1.6.9 order by
            // adopt_legacy_stock(), even if this request dies before the stock
            // is taken below.
            $order->update_meta_data( '_houla_stock_v2', '1' );
            if ( ! empty( $data['transaction_id'] ) ) {
                $order->set_transaction_id( sanitize_text_field( $data['transaction_id'] ) );
                $order->update_meta_data( '_houla_transaction_id', sanitize_text_field( $data['transaction_id'] ) );
            }

            // Auction metadata: flag the order as an auction purchase so the
            // seller understands why the price differs from the listed price.
            if ( ! empty( $data['purchase_origin'] ) && $data['purchase_origin'] === 'live_auction' ) {
                $order->update_meta_data( '_houla_purchase_origin', 'live_auction' );
                if ( ! empty( $data['auction_id'] ) ) {
                    $order->update_meta_data( '_houla_auction_id', sanitize_text_field( $data['auction_id'] ) );
                }
                $order->add_order_note(
                    '🔨 Commande issue d\'une enchère en direct sur Hou.la. '
                    . 'Le prix correspond au montant remporté aux enchères et peut différer du prix catalogue.'
                );
            }

            // Test mode indicator
            if ( ! empty( $data['_test_mode'] ) ) {
                $order->update_meta_data( '_houla_test_mode', '1' );
                $order->add_order_note( '⚠️ Commande de test (Hou.la mode développement)' );

                // Prefix billing name so it's immediately visible in WooCommerce admin
                $order->set_billing_first_name( '[TEST] ' . $order->get_billing_first_name() );
            }
            if ( ! empty( $data['paid_at'] ) ) {
                $order->set_date_paid( $data['paid_at'] );
            }

            // Recalculate totals, then force the total from the webhook payload
            // to ensure it matches the Hou.la order even if some products were not found in WC
            $order->calculate_totals();
            if ( ! empty( $data['total'] ) ) {
                $order->set_total( floatval( $data['total'] ) );
            }

            // Prevent the on_order_status_changed hook from syncing back to Hou.la
            // (this order just came FROM Hou.la, no need to echo it back)
            $order->update_meta_data( '_houla_skip_sync', '1' );

            // Use the WC status from the webhook payload if available (respects open-cart vs processing)
            $initial_status = ! empty( $data['wc_status'] ) ? sanitize_text_field( $data['wc_status'] ) : 'processing';
            $this->log( 'Setting initial status: "' . $initial_status . '" (from wc_status field: ' . ( ! empty( $data['wc_status'] ) ? $data['wc_status'] : 'none' ) . ')' );

            // Validate that our custom status is registered before setting it
            $valid_statuses = array_keys( wc_get_order_statuses() );
            if ( ! in_array( 'wc-' . $initial_status, $valid_statuses, true ) ) {
                $this->log( 'WARNING: Status "wc-' . $initial_status . '" is NOT registered. Valid: ' . implode( ', ', $valid_statuses ) );
            }

            $order->set_status( $initial_status, __( 'Order received via Hou.la Pay.', 'wp-houla' ) );

            // Add detailed order note with full item breakdown
            $note_lines = array();
            $note_lines[] = __( 'Commande reçue de Hou.la Pay.', 'wp-houla' );
            $note_lines[] = '';
            $customer = isset( $data['customer'] ) ? $data['customer'] : array();
            if ( ! empty( $customer['email'] ) ) {
                $name_parts = array_filter( array(
                    isset( $customer['first_name'] ) ? $customer['first_name'] : '',
                    isset( $customer['last_name'] ) ? $customer['last_name'] : '',
                ) );
                $note_lines[] = 'Client : ' . ( ! empty( $name_parts ) ? implode( ' ', $name_parts ) . ' — ' : '' ) . sanitize_email( $customer['email'] );
            }
            // Buyer's Hou.la workspace identity (display name + @handle).
            if ( ! empty( $customer['display_name'] ) || ! empty( $customer['social_handle'] ) ) {
                $ident = ! empty( $customer['display_name'] ) ? sanitize_text_field( $customer['display_name'] ) : '';
                if ( ! empty( $customer['social_handle'] ) ) {
                    $ident .= ' (@' . sanitize_text_field( $customer['social_handle'] ) . ')';
                }
                $note_lines[] = 'Profil Hou.la : ' . trim( $ident );
            }
            // Sendcloud service point (Mondial Relay / locker) — where the parcel ships.
            if ( ! empty( $customer['service_point'] ) && is_array( $customer['service_point'] ) ) {
                $sp = $customer['service_point'];
                $sp_bits = array();
                if ( ! empty( $sp['method'] ) ) { $sp_bits[] = sanitize_text_field( $sp['method'] ); }
                if ( ! empty( $sp['name'] ) )   { $sp_bits[] = sanitize_text_field( $sp['name'] ); }
                if ( ! empty( $sp['id'] ) )     { $sp_bits[] = '#' . sanitize_text_field( $sp['id'] ); }
                if ( ! empty( $sp_bits ) ) {
                    $note_lines[] = 'Point relais : ' . implode( ' — ', $sp_bits );
                }
                // The relay meta is written by apply_service_point() (create AND update).
            }
            $note_lines[] = '';
            foreach ( $data['items'] as $item ) {
                $item_name  = ! empty( $item['name'] ) ? sanitize_text_field( $item['name'] ) : 'Product #' . absint( $item['external_id'] );
                $qty        = max( 1, absint( $item['quantity'] ) );
                $price      = isset( $item['price'] ) ? number_format( floatval( $item['price'] ), 2, ',', '' ) . ' €' : '-';
                $line_total = isset( $item['price'] ) ? number_format( floatval( $item['price'] ) * $qty, 2, ',', '' ) . ' €' : '-';
                $note_lines[] = '• ' . $item_name . ' — x' . $qty . ' — ' . $price . '/u — Total: ' . $line_total;
            }
            if ( ! empty( $data['shipping']['amount'] ) ) {
                $note_lines[] = '';
                $note_lines[] = 'Frais de port : ' . number_format( floatval( $data['shipping']['amount'] ), 2, ',', '' ) . ' €';
            }
            if ( ! empty( $data['total'] ) ) {
                $note_lines[] = 'Total : ' . number_format( floatval( $data['total'] ), 2, ',', '' ) . ' €';
            }
            if ( ! empty( $data['transaction_id'] ) ) {
                $note_lines[] = 'Réf. paiement : ' . sanitize_text_field( $data['transaction_id'] );
            }
            $order->add_order_note( implode( "\n", $note_lines ) );

            $order->save();

            // Clear the skip-sync flag now that the save is complete
            $order->delete_meta_data( '_houla_skip_sync' );
            $order->save_meta_data();

            // Verify the status was actually applied (WC may reject unknown statuses)
            $actual_status = $order->get_status();
            if ( $actual_status !== $initial_status ) {
                $this->log( 'WARNING: Status mismatch! Requested "' . $initial_status . '" but got "' . $actual_status . '". Attempting direct DB update.' );
                // Force status via direct update as fallback
                global $wpdb;
                $hpos = class_exists( 'Automattic\WooCommerce\Utilities\OrderUtil' )
                     && \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();
                if ( $hpos ) {
                    $wpdb->update(
                        $wpdb->prefix . 'wc_orders',
                        array( 'status' => 'wc-' . $initial_status ),
                        array( 'id' => $order->get_id() )
                    );
                } else {
                    $wpdb->update(
                        $wpdb->posts,
                        array( 'post_status' => 'wc-' . $initial_status ),
                        array( 'ID' => $order->get_id() )
                    );
                }
                // Clear WC object cache
                clean_post_cache( $order->get_id() );
                $this->log( 'Forced status to "wc-' . $initial_status . '" via direct DB update for WC #' . $order->get_id() );
            }

            // Stock taken once (open cart included), but never for an order
            // that arrives cancelled, refunded or failed (a webhook replayed by
            // Hou.la for such an order would take stock nobody gives back).
            if ( $this->status_holds_stock( $initial_status ) ) {
                $this->reduce_order_stock( $order->get_id() );
            }

            // Update counters
            $this->increment_counter( 'orders_received' );
            $this->options->set( 'last_order_at', current_time( 'mysql' ) );

            $this->log( 'Order created: WC #' . $order->get_id() . ' from Hou.la ' . $data['houla_order_id'] . ' (status: ' . $initial_status . ').' );

            return array( 'order_id' => $order->get_id() );

        } catch ( \Exception $e ) {
            $this->log( 'Order creation failed: ' . $e->getMessage() );
            return new WP_Error( 'wphoula_order_failed', $e->getMessage() );
        }
    }

    // =====================================================================
    // Order update (upsert — replace items on existing WC order)
    // =====================================================================

    /**
     * Update an existing WooCommerce order with fresh items from Hou.la.
     *
     * Used when:
     * - A buyer adds items to an open cart (merge path)
     * - A seller re-syncs an order from the Hou.la dashboard
     * - An order.paid webhook fires for an already-synced order
     *
     * Replaces all line items, shipping, totals, and customer info.
     *
     * @param array $data Parsed webhook payload (same structure as create_order).
     * @return array|WP_Error Array with 'order_id' on success, WP_Error on failure.
     */
    public function update_order( $data ) {
        return $this->locked( $data, 'do_update_order' );
    }

    /**
     * @see update_order() — runs under the order lock.
     * @param array $data Parsed webhook payload.
     * @return array|WP_Error
     */
    private function do_update_order( $data ) {
        if ( empty( $data['houla_order_id'] ) || empty( $data['items'] ) ) {
            return new WP_Error( 'wphoula_invalid_order', 'Missing houla_order_id or items.' );
        }

        $order_id = $this->find_order_by_houla_id( $data['houla_order_id'] );
        if ( ! $order_id ) {
            // Order doesn't exist yet — fall back to create
            return $this->create_order( $data );
        }

        try {
            $order = wc_get_order( $order_id );
            if ( ! $order ) {
                return new WP_Error( 'wphoula_order_not_found', 'WC order #' . $order_id . ' not found.' );
            }

            // Stock already taken for this order, per product (see
            // reduce_order_stock): the items are replaced below, so only the
            // DIFFERENCE is applied afterwards (apply_stock_difference).
            //
            // ⚠️ Only while the order HOLDS stock. A cancelled order had its
            // stock given back by WooCommerce (`_reduced_stock` erased): a
            // replayed order.paid / order.updated (retryFailedOrderWebhooks,
            // resyncFailedOrders) would otherwise take the whole quantity again,
            // and nothing would ever give it back (relecture du 2026-11-09).
            $this->adopt_legacy_stock( $order );
            $status_now  = $order->get_status();
            $status_next = ! empty( $data['wc_status'] ) ? sanitize_text_field( $data['wc_status'] ) : $status_now;
            $holds_now   = $this->is_stock_reduced( $order ) && $this->status_holds_stock( $status_now );
            $holds_next  = $this->status_holds_stock( $status_next );
            $taken       = $holds_now ? $this->stock_taken( $order ) : array();
            $restocked   = $this->stock_restocked( $order );

            // ----------------------------------------------------------
            // Remove ALL existing line items (products, fees, shipping)
            // ----------------------------------------------------------
            foreach ( $order->get_items( array( 'line_item', 'fee', 'shipping' ) ) as $item_id => $item ) {
                $order->remove_item( $item_id );
            }

            // ----------------------------------------------------------
            // Re-add line items from payload
            // ----------------------------------------------------------
            foreach ( $data['items'] as $item ) {
                $product_id   = absint( $item['external_id'] );
                $variation_id = ! empty( $item['variation_id'] ) ? absint( $item['variation_id'] ) : 0;
                $quantity     = max( 1, absint( $item['quantity'] ) );
                $price        = isset( $item['price'] ) ? floatval( $item['price'] ) : 0;

                $product = wc_get_product( $variation_id ?: $product_id );
                if ( ! $product ) {
                    $item_name = ! empty( $item['name'] ) ? sanitize_text_field( $item['name'] ) : 'Hou.la Product #' . $product_id;
                    $this->log( 'Product #' . $product_id . ' not found in WC, adding as custom fee: ' . $item_name );
                    $fee = new WC_Order_Item_Fee();
                    $fee->set_name( $item_name . ' (x' . $quantity . ')' );
                    $fee->set_amount( $price * $quantity );
                    $fee->set_total( $price * $quantity );
                    $fee->set_tax_status( 'none' );
                    $order->add_item( $fee );
                } else {
                    $order->add_product( $product, $quantity, array(
                        'subtotal' => $price * $quantity,
                        'total'    => $price * $quantity,
                    ) );
                }
            }

            // Stock: an item added to an open cart (live) was never taken
            // from WooCommerce until 1.6.8, and the units already taken were
            // taken AGAIN when the cart closed. Only the difference moves now.
            if ( $holds_now && $holds_next ) {
                $this->apply_stock_difference( $order, $taken, $restocked );
            } elseif ( $holds_now ) {
                // Cancelled / refunded by this update: what was taken comes
                // back here (the old lines carried the bookkeeping).
                $this->release_stock( $order, $taken );
            }

            // ----------------------------------------------------------
            // Re-add shipping
            // ----------------------------------------------------------
            if ( ! empty( $data['shipping'] ) ) {
                $shipping_item = new WC_Order_Item_Shipping();
                $shipping_item->set_method_title( $this->safe( $data['shipping'], 'label', 'Houla Shipping' ) );
                $shipping_item->set_method_id( $this->safe( $data['shipping'], 'method', 'flat_rate' ) );
                $shipping_item->set_total( floatval( $data['shipping']['amount'] ?? 0 ) );
                $order->add_item( $shipping_item );
            }

            // ----------------------------------------------------------
            // Update customer / billing (may have changed)
            // ----------------------------------------------------------
            $customer = isset( $data['customer'] ) ? $data['customer'] : array();
            $address  = isset( $customer['address'] ) ? $customer['address'] : array();

            $billing = array(
                'first_name' => $this->safe( $customer, 'first_name' ),
                'last_name'  => $this->safe( $customer, 'last_name' ),
                'email'      => $this->safe( $customer, 'email' ),
                'phone'      => $this->safe( $customer, 'phone' ),
                'address_1'  => $this->safe( $address, 'line1' ),
                'address_2'  => $this->safe( $address, 'line2' ),
                'city'       => $this->safe( $address, 'city' ),
                'state'      => $this->safe( $address, 'state' ),
                'postcode'   => $this->safe( $address, 'postcode' ),
                'country'    => $this->safe( $address, 'country' ),
            );

            $order->set_address( $billing, 'billing' );
            $order->set_address( $billing, 'shipping' );

            // Buyer workspace identity (pseudo / display name / avatar).
            $this->apply_buyer_identity( $order, $customer );

            // Attribute this order to Hou.la in the WooCommerce "Origin" column.
            $this->apply_order_origin( $order );

            // Sendcloud service point (Mondial Relay / locker) metadata — set on BOTH
            // create AND update so re-synced/merged orders also carry it.
            $this->apply_service_point( $order, $customer );

            // Commande invitée dont l'e-mail correspond désormais à un compte
            // client : rattachée. Jamais de création de compte sur une mise à
            // jour, et une commande déjà rattachée ne change pas de compte.
            $this->attach_customer( $order, $customer, false, isset( $data['paid_at'] ) ? (string) $data['paid_at'] : '' );

            // ----------------------------------------------------------
            // Update metadata
            // ----------------------------------------------------------
            $order->update_meta_data( '_houla_sync_status', 'synced' );
            $order->update_meta_data( '_houla_sync_at', current_time( 'mysql' ) );
            if ( ! empty( $data['transaction_id'] ) ) {
                $order->set_transaction_id( sanitize_text_field( $data['transaction_id'] ) );
                $order->update_meta_data( '_houla_transaction_id', sanitize_text_field( $data['transaction_id'] ) );
            }

            // Recalculate totals, then force the total from the webhook payload
            $order->calculate_totals();
            if ( ! empty( $data['total'] ) ) {
                $order->set_total( floatval( $data['total'] ) );
            }

            // Update WC status if the payload carries a Hou.la status (e.g. open-cart vs processing)
            if ( ! empty( $data['wc_status'] ) ) {
                $new_wc_status = sanitize_text_field( $data['wc_status'] );
                if ( $order->get_status() !== $new_wc_status ) {
                    $order->update_meta_data( '_houla_skip_sync', '1' );
                    $order->set_status( $new_wc_status, __( 'Status updated via Hou.la sync.', 'wp-houla' ) );
                }
            }

            // Build detailed order note with item breakdown
            $note_lines = array();
            $note_lines[] = __( 'Commande reçue de Hou.la Pay. Order items updated via Hou.la sync.', 'wp-houla' );
            $note_lines[] = '';
            foreach ( $data['items'] as $item ) {
                $item_name = ! empty( $item['name'] ) ? sanitize_text_field( $item['name'] ) : 'Product #' . absint( $item['external_id'] );
                $qty       = max( 1, absint( $item['quantity'] ) );
                $price     = isset( $item['price'] ) ? number_format( floatval( $item['price'] ), 2, ',', '' ) . ' €' : '-';
                $line_total = isset( $item['price'] ) ? number_format( floatval( $item['price'] ) * $qty, 2, ',', '' ) . ' €' : '-';
                $note_lines[] = '• ' . $item_name . ' — x' . $qty . ' — ' . $price . '/u — Total: ' . $line_total;
            }
            if ( ! empty( $data['shipping']['amount'] ) ) {
                $note_lines[] = '';
                $note_lines[] = 'Frais de port : ' . number_format( floatval( $data['shipping']['amount'] ), 2, ',', '' ) . ' €';
            }
            if ( ! empty( $data['total'] ) ) {
                $note_lines[] = 'Total : ' . number_format( floatval( $data['total'] ), 2, ',', '' ) . ' €';
            }
            $order->add_order_note( implode( "\n", $note_lines ) );
            $order->save();

            // Clear skip-sync flag if it was set
            if ( $order->get_meta( '_houla_skip_sync' ) === '1' ) {
                $order->delete_meta_data( '_houla_skip_sync' );
                $order->save_meta_data();
            }

            // The order holds stock again (cancelled order paid again, or a
            // creation interrupted before its stock was taken): take it once.
            if ( ! $holds_now && $holds_next ) {
                $this->reduce_order_stock( $order_id );
            }

            $this->log( 'Order updated: WC #' . $order_id . ' from Hou.la ' . $data['houla_order_id'] . ' (' . count( $data['items'] ) . ' items).' );

            return array( 'order_id' => $order_id );

        } catch ( \Exception $e ) {
            $this->log( 'Order update failed: ' . $e->getMessage() );
            return new WP_Error( 'wphoula_order_update_failed', $e->getMessage() );
        }
    }

    // =====================================================================
    // Order refund
    // =====================================================================

    /**
     * Handle an order.refunded webhook.
     *
     * Expected $data structure:
     * {
     *   "houla_order_id":  "ord_abc123",
     *   "refund_amount":   10.00,
     *   "currency":        "EUR",
     *   "reason":          "Customer requested refund",
     *   "restock_items":   true,
     *   "items":           [{ "external_id": "123", "quantity": 1 }]
     * }
     *
     * Supports both full and partial refunds. If refund_amount is less than
     * the order total, a partial WooCommerce refund is created.
     *
     * @param array $data Parsed webhook payload.
     * @return array|WP_Error
     */
    public function refund_order( $data ) {
        return $this->locked( $data, 'do_refund_order' );
    }

    /**
     * @see refund_order() — runs under the order lock.
     * @param array $data Parsed webhook payload.
     * @return array|WP_Error
     */
    private function do_refund_order( $data ) {
        if ( empty( $data['houla_order_id'] ) ) {
            return new WP_Error( 'wphoula_invalid_refund', 'Missing houla_order_id.' );
        }

        $order_id = $this->find_order_by_houla_id( $data['houla_order_id'] );
        if ( ! $order_id ) {
            return new WP_Error( 'wphoula_order_not_found', 'Order not found for ' . $data['houla_order_id'] . '.' );
        }

        $order  = wc_get_order( $order_id );
        $this->adopt_legacy_stock( $order );
        $reason = isset( $data['reason'] ) ? sanitize_text_field( $data['reason'] ) : __( 'Refunded via Hou.la.', 'wp-houla' );

        // Determine refund amount: use webhook value or fall back to order total
        $refund_amount = isset( $data['refund_amount'] ) ? floatval( $data['refund_amount'] ) : floatval( $order->get_total() );

        // Determine restock: defaults to true if not specified
        $restock = isset( $data['restock_items'] ) ? (bool) $data['restock_items'] : true;

        // Same refund received twice (Hou.la replays a webhook whose answer
        // came too late, but which WooCommerce had applied): recorded once.
        // The order lock serializes two simultaneous copies.
        $refund_id = isset( $data['refund_id'] ) ? sanitize_text_field( (string) $data['refund_id'] ) : '';
        if ( '' !== $refund_id && method_exists( $order, 'get_refunds' ) ) {
            foreach ( $order->get_refunds() as $existing ) {
                if ( $refund_id === (string) $existing->get_meta( '_houla_refund_id' ) ) {
                    $this->log( 'Refund ' . $refund_id . ' already recorded on WC #' . $order_id . ': ignored.' );
                    return array( 'order_id' => $order_id, 'duplicate' => true );
                }
            }
        }

        // ⚠️ ÉCHO (Trello #157). wc_create_refund() passe lui-même une commande
        // remboursée en totalité à « Remboursée », sur une commande qu'il
        // relit, et déclenche woocommerce_order_refunded avant que le
        // remboursement porte `_houla_refund_id`. Sans ces deux marques, ce
        // remboursement venu de Hou.la repartait vers Hou.la (`wc_status=
        // refunded`) et était signalé à la vendeuse comme « sans argent ».
        $order->update_meta_data( '_houla_skip_sync', '1' );
        $order->save();
        Wp_Houla_Refund_Notice::$applying_houla_refund = true;
        try {
            // Create the refund
            $refund = wc_create_refund( array(
                'amount'         => $refund_amount,
                'reason'         => $reason,
                'order_id'       => $order_id,
                'refund_payment' => false, // Payment was already refunded on Stripe side
                'restock_items'  => $restock,
            ) );
        } finally {
            Wp_Houla_Refund_Notice::$applying_houla_refund = false;
        }

        if ( is_wp_error( $refund ) ) {
            $this->log( 'Refund failed for WC #' . $order_id . ': ' . $refund->get_error_message() );
            $order->delete_meta_data( '_houla_skip_sync' );
            $order->save_meta_data();
            return $refund;
        }

        if ( '' !== $refund_id && is_object( $refund ) && method_exists( $refund, 'update_meta_data' ) ) {
            $refund->update_meta_data( '_houla_refund_id', $refund_id );
            $refund->save();
        }

        // Hou.la puts these items back in ITS stock (restoreStockForOrder) and
        // lists them in `items`. wc_create_refund() restocks nothing without
        // refund lines, so WooCommerce kept them out of stock until 1.6.8.
        if ( $restock && ! empty( $data['items'] ) && is_array( $data['items'] ) ) {
            $this->restock_items( $order, $data['items'] );
        }

        // Only set status to refunded if full refund (amount >= order total).
        // La méta `_houla_skip_sync` est encore posée : ce passage ne repart
        // pas vers Hou.la ; elle est retirée juste après.
        if ( $refund_amount >= floatval( $order->get_total() ) ) {
            $order->set_status( 'refunded', $reason );
        } else {
            $order->add_order_note( sprintf(
                /* translators: %s: refund amount */
                __( 'Partial refund of %s via Hou.la.', 'wp-houla' ),
                wc_price( $refund_amount )
            ) );
        }
        $order->save();
        $order->delete_meta_data( '_houla_skip_sync' );
        $order->save_meta_data();

        $this->log( 'Order WC #' . $order_id . ' refunded ' . $refund_amount . ' ' . ( isset( $data['currency'] ) ? $data['currency'] : '' ) . ' (Hou.la ' . $data['houla_order_id'] . ').' );

        return array( 'order_id' => $order_id );
    }

    // =====================================================================
    // Order status update (from Hou.la webhook)
    // =====================================================================

    /**
     * Default concordance: Hou.la status → WooCommerce status.
     * Used as fallback when no custom mapping is configured.
     */
    private static $default_houla_to_wc = array(
        'pending'             => 'on-hold',
        'open_cart'           => 'open-cart',
        'paid'                => 'processing',
        'processing'          => 'processing',
        'shipped'             => 'houla-shipping',
        // Les deux phases du retour cote Hou.la tombent sur le meme statut WC :
        // le plugin n'en expose qu'un. La nuance « en cours de retour » vit cote
        // Hou.la, et dans les deux cas la cliente n'a pas recu son colis.
        'return_in_transit'   => 'houla-returned',
        'returned'            => 'houla-returned',
        'delivered'           => 'completed',
        'cancelled'           => 'cancelled',
        'abandoned'           => 'cancelled',
        'refunded'            => 'refunded',
        'partially_refunded'  => 'refunded',
    );

    /**
     * Get the Hou.la → WC status mapping from the saved concordance table.
     * The concordance is stored as wc-slug => houla_status, so we invert it.
     *
     * @return array Hou.la status => WC status (without wc- prefix).
     */
    private function get_houla_to_wc_map() {
        $saved_map = $this->options->get( 'order_status_map' );
        if ( ! is_array( $saved_map ) || empty( $saved_map ) ) {
            return self::$default_houla_to_wc;
        }

        // Invert: 'wc-xxx' => houla_status becomes houla_status => 'xxx'
        $inverted = array();
        foreach ( $saved_map as $wc_slug => $houla_status ) {
            $wc_key = preg_replace( '/^wc-/', '', $wc_slug );
            // If multiple WC statuses map to the same Hou.la status, keep the last one
            $inverted[ $houla_status ] = $wc_key;
        }

        return $inverted;
    }

    /**
     * Handle an order.status_changed webhook.
     *
     * Expected $data structure:
     * {
     *   "houla_order_id": "ord_abc123",
     *   "wc_status":      "completed",
     *   "carrier":        "colissimo",
     *   "tracking_number": "6A12345678"
     * }
     *
     * @param array $data Parsed webhook payload.
     * @return array|WP_Error
     */
    public function update_order_status( $data ) {
        return $this->locked( $data, 'do_update_order_status' );
    }

    /**
     * @see update_order_status() — runs under the order lock.
     * @param array $data Parsed webhook payload.
     * @return array|WP_Error
     */
    private function do_update_order_status( $data ) {
        if ( empty( $data['houla_order_id'] ) || empty( $data['wc_status'] ) ) {
            return new WP_Error( 'wphoula_invalid_status', 'Missing houla_order_id or wc_status.' );
        }

        $order_id = $this->find_order_by_houla_id( $data['houla_order_id'] );
        if ( ! $order_id ) {
            return new WP_Error( 'wphoula_order_not_found', 'Order not found for ' . $data['houla_order_id'] . '.' );
        }

        $order     = wc_get_order( $order_id );
        $wc_status = sanitize_text_field( $data['wc_status'] );

        // An order created before 1.6.9 had its stock taken by hand: without
        // this, WooCommerce takes it again when the cart closes (open-cart →
        // processing).
        $this->adopt_legacy_stock( $order );

        // Lus AVANT d'être écrasés plus bas : ils disent si l'acheteuse a déjà
        // été prévenue de CET envoi (cf. l'e-mail « en cours de livraison »).
        $previous_status   = $order->get_status();
        $previous_tracking = (string) $order->get_meta( '_houla_tracking_number' );

        // Set a flag to prevent infinite loop (this status change came from Hou.la,
        // so the woocommerce_order_status_changed hook should NOT send it back)
        $order->update_meta_data( '_houla_skip_sync', '1' );

        // Build detailed status note
        $status_label = ucfirst( str_replace( '-', ' ', $wc_status ) );
        $note_lines   = array();
        $note_lines[] = sprintf(
            __( 'Statut modifié via Hou.la : %s → %s.', 'wp-houla' ),
            ucfirst( str_replace( '-', ' ', $order->get_status() ) ),
            $status_label
        );

        // Store carrier/tracking if provided
        if ( ! empty( $data['carrier'] ) ) {
            $order->update_meta_data( '_houla_carrier', sanitize_text_field( $data['carrier'] ) );
            $note_lines[] = 'Transporteur : ' . sanitize_text_field( $data['carrier'] );
        }
        if ( ! empty( $data['tracking_number'] ) ) {
            $order->update_meta_data( '_houla_tracking_number', sanitize_text_field( $data['tracking_number'] ) );
            $note_lines[] = 'N° de suivi : ' . sanitize_text_field( $data['tracking_number'] );
        }
        if ( ! empty( $data['tracking_url'] ) ) {
            $note_lines[] = 'Suivi : ' . esc_url( $data['tracking_url'] );
        }

        $order->set_status( $wc_status, implode( "\n", $note_lines ) );
        $order->save();

        // Clear the skip flag after save (the hook has already fired at this point)
        $order->delete_meta_data( '_houla_skip_sync' );
        $order->save_meta_data();

        // Back to a status that holds stock (e.g. cancelled → open cart):
        // WooCommerce only takes it on processing / on-hold / completed.
        if ( $this->status_holds_stock( $wc_status ) && ! $this->is_stock_reduced( $order ) ) {
            $this->reduce_order_stock( $order_id );
        }

        // « En cours de livraison (Hou.la) » est un statut CUSTOM → WooCommerce
        // n'envoie AUCUN email client natif (contrairement à « Terminée »). Pour
        // que l'acheteur soit quand même notifié avec le suivi, on ajoute une note
        // CLIENT : add_order_note(..., true) déclenche l'email « Note ajoutée à
        // votre commande » et y inclut le n° + lien de suivi. On ne le fait qu'au
        // passage en livraison, et seulement si le suivi est présent (lien actif).
        //
        // UNE FOIS PAR ENVOI. Hou.la peut redire « expédiée » pour le même colis
        // (étiquette + transition canonique, webhooks transporteur en double) :
        // chaque message ajoutait une note client, donc un e-mail. Constaté sur
        // giamory.com le 2026-09-25 : 59 commandes sur 70 avaient valu de 2 à 6
        // e-mails identiques à l'acheteuse. On ne prévient donc que si la commande
        // ENTRE en livraison, ou si le numéro de suivi a changé (nouvelle étiquette).
        $new_tracking     = ! empty( $data['tracking_number'] ) ? sanitize_text_field( $data['tracking_number'] ) : '';
        $already_notified = 'houla-shipping' === $previous_status && $new_tracking === $previous_tracking;
        if ( 'houla-shipping' === $wc_status && ! $already_notified && ( ! empty( $data['tracking_number'] ) || ! empty( $data['tracking_url'] ) ) ) {
            $customer_lines = array( __( 'Votre commande est en cours de livraison.', 'wp-houla' ) );
            if ( ! empty( $data['carrier'] ) ) {
                $customer_lines[] = sprintf( __( 'Transporteur : %s', 'wp-houla' ), sanitize_text_field( $data['carrier'] ) );
            }
            if ( ! empty( $data['tracking_number'] ) ) {
                $customer_lines[] = sprintf( __( 'N° de suivi : %s', 'wp-houla' ), sanitize_text_field( $data['tracking_number'] ) );
            }
            if ( ! empty( $data['tracking_url'] ) ) {
                $customer_lines[] = sprintf( __( 'Suivi : %s', 'wp-houla' ), esc_url_raw( $data['tracking_url'] ) );
            }
            $order->add_order_note( implode( "\n", $customer_lines ), true );
        }

        $this->log( 'Order WC #' . $order_id . ' status changed to "' . $wc_status . '" (Hou.la ' . $data['houla_order_id'] . ').' );

        return array( 'order_id' => $order_id );
    }

    // =====================================================================
    // Order lock (one webhook at a time per Hou.la order)
    // =====================================================================

    /** @var array<string,int> Hou.la orders whose lock this request holds (re-entrant). */
    private static $held_locks = array();

    /** Seconds a webhook waits for the order lock (Hou.la gives up after 15). */
    const LOCK_WAIT = 5;

    /**
     * Run a webhook handler under a lock per Hou.la order.
     *
     * Two identical order.updated received together (a retry by Hou.la while
     * the first one is still running) both read the stock already taken,
     * then both applied the difference: banc du 2026-11-09, 5 essais sur 5,
     * 2 unités ajoutées au panier = 4 retirées. Two order.paid together could
     * also create the order twice. MySQL GET_LOCK is atomic and released when
     * the connection ends, even if PHP dies.
     *
     * @param array  $data   Webhook payload.
     * @param string $method Handler to run.
     * @return array|WP_Error
     */
    private function locked( $data, $method ) {
        $key = is_array( $data ) && ! empty( $data['houla_order_id'] ) ? (string) $data['houla_order_id'] : '';
        if ( '' === $key ) {
            return $this->$method( $data ); // The handler rejects it.
        }
        if ( ! $this->acquire_lock( $key ) ) {
            $this->log( 'Order ' . $key . ' busy: another webhook is updating it.' );
            return new WP_Error( 'wphoula_order_busy', 'Order ' . $key . ' is being updated by another request, retry later.', array( 'status' => 409 ) );
        }
        try {
            return $this->$method( $data );
        } finally {
            $this->release_lock( $key );
        }
    }

    /**
     * @param string $key Hou.la order ID.
     * @return bool
     */
    private function acquire_lock( $key ) {
        if ( isset( self::$held_locks[ $key ] ) ) {
            self::$held_locks[ $key ]++;
            return true;
        }
        global $wpdb;
        if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
            return true; // No database (unit tests).
        }
        // 5 s, bien en dessous des 15 s après lesquelles Hou.la abandonne un
        // envoi (AbortSignal.timeout) : sinon Hou.la renonçait, ce webhook
        // s'appliquait quand même, puis Hou.la le renvoyait.
        $got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $this->lock_name( $key ), self::LOCK_WAIT ) );
        if ( '1' !== (string) $got ) {
            return false;
        }
        self::$held_locks[ $key ] = 1;
        return true;
    }

    /**
     * @param string $key Hou.la order ID.
     */
    private function release_lock( $key ) {
        if ( ! isset( self::$held_locks[ $key ] ) ) {
            return;
        }
        if ( --self::$held_locks[ $key ] > 0 ) {
            return;
        }
        unset( self::$held_locks[ $key ] );
        global $wpdb;
        if ( is_object( $wpdb ) && method_exists( $wpdb, 'get_var' ) ) {
            $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->lock_name( $key ) ) );
        }
    }

    /**
     * @param string $key Hou.la order ID.
     * @return string Lock name (64 characters at most, one per site and order).
     */
    private function lock_name( $key ) {
        return 'wphoula_' . substr( md5( ABSPATH . '|' . $key ), 0, 40 );
    }

    // =====================================================================
    // Stock (one reduction per unit, WooCommerce bookkeeping)
    // =====================================================================

    /** WooCommerce statuses whose order does NOT hold stock. */
    private static $statuses_without_stock = array( 'cancelled', 'refunded', 'failed', 'trash', 'checkout-draft' );

    /**
     * @param string $status WooCommerce status, with or without `wc-`.
     * @return bool Whether an order in this status keeps its units out of stock.
     */
    private function status_holds_stock( $status ) {
        return ! in_array( preg_replace( '/^wc-/', '', (string) $status ), self::$statuses_without_stock, true );
    }

    /**
     * WooCommerce's « stock already reduced » flag (false once a cancellation
     * gave the stock back).
     *
     * @param WC_Order $order WooCommerce order.
     * @return bool
     */
    private function is_stock_reduced( $order ) {
        if ( ! method_exists( $order, 'get_data_store' ) ) {
            return '1' === (string) $order->get_meta( '_order_stock_reduced' );
        }
        return (bool) $order->get_data_store()->get_stock_reduced( $order->get_id() );
    }

    /**
     * @param WC_Order $order WooCommerce order.
     * @param bool     $value Flag value.
     */
    private function flag_stock_reduced( $order, $value = true ) {
        if ( method_exists( $order, 'get_data_store' ) ) {
            $order->get_data_store()->set_stock_reduced( $order->get_id(), $value );
        } else {
            $order->update_meta_data( '_order_stock_reduced', $value ? '1' : '0' );
        }
    }

    /**
     * Take the order's stock ONCE, with WooCommerce's own bookkeeping: each
     * line keeps `_reduced_stock`, and the order is flagged, so neither a later
     * « processing » / « on-hold » nor a second call takes it again, and a
     * cancellation gives back exactly what was taken (wc_increase_stock_levels).
     *
     * @param int $order_id WooCommerce order ID.
     */
    private function reduce_order_stock( $order_id ) {
        if ( ! function_exists( 'wc_reduce_stock_levels' ) ) {
            return;
        }
        wc_reduce_stock_levels( $order_id ); // Skips lines already taken.
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $this->flag_stock_reduced( $order );
        $order->update_meta_data( '_houla_stock_v2', '1' );
        $order->save_meta_data();
    }

    /**
     * Orders created before 1.6.9: create_order() took the stock by hand,
     * without WooCommerce's bookkeeping. Record it now (`_reduced_stock` = the
     * line quantity), so WooCommerce does not take it a second time when the
     * cart closes, and a cancellation gives it back. A cancelled, refunded or
     * failed order holds nothing: only marked as seen.
     *
     * Limit: an item ADDED to an open cart before 1.6.9 was never taken; it is
     * recorded as taken too (the module cannot tell it apart), so the stock of
     * such an item stays one sale too high, as it already was.
     *
     * @param WC_Order $order WooCommerce order.
     */
    private function adopt_legacy_stock( $order ) {
        if ( ! $order || '1' === (string) $order->get_meta( '_houla_stock_v2' ) || ! $order->get_meta( '_houla_order_id' ) ) {
            return;
        }
        if ( $this->status_holds_stock( $order->get_status() ) ) {
            foreach ( $order->get_items( 'line_item' ) as $item ) {
                if ( ! $item instanceof WC_Order_Item_Product ) {
                    continue;
                }
                $product = $item->get_product();
                if ( '' === (string) $item->get_meta( '_reduced_stock', true ) && $product && $product->managing_stock() ) {
                    $item->update_meta_data( '_reduced_stock', $item->get_quantity() );
                    $item->save();
                }
            }
            $this->flag_stock_reduced( $order );
        }
        $order->update_meta_data( '_houla_stock_v2', '1' );
        $order->save_meta_data();
    }

    /**
     * @param WC_Order_Item_Product $item Order line.
     * @return int The variation when there is one, else the product.
     */
    private function stock_key( $item ) {
        return $item->get_variation_id() ? (int) $item->get_variation_id() : (int) $item->get_product_id();
    }

    /**
     * Units already taken from the WooCommerce stock, per product (the
     * variation when there is one).
     *
     * @param WC_Order $order WooCommerce order.
     * @return array product or variation ID => units
     */
    private function stock_taken( $order ) {
        return $this->sum_line_meta( $order, '_reduced_stock' );
    }

    /**
     * Units put back in stock by a refund (`_restock_refunded_items`, the
     * WooCommerce meta), per product: they must not be taken again when the
     * order is updated afterwards.
     *
     * @param WC_Order $order WooCommerce order.
     * @return array product or variation ID => units
     */
    private function stock_restocked( $order ) {
        return $this->sum_line_meta( $order, '_restock_refunded_items' );
    }

    /**
     * @param WC_Order $order WooCommerce order.
     * @param string   $meta  Line meta key.
     * @return array product or variation ID => sum
     */
    private function sum_line_meta( $order, $meta ) {
        $sum = array();
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }
            $key         = $this->stock_key( $item );
            $sum[ $key ] = ( isset( $sum[ $key ] ) ? $sum[ $key ] : 0 ) + (int) $item->get_meta( $meta, true );
        }
        return $sum;
    }

    /**
     * After the items of an order were replaced: move the stock by the
     * DIFFERENCE with what was already taken, and record the new lines as
     * taken (`_reduced_stock`), so WooCommerce never takes them again. Units a
     * refund put back stay out of the order (`_restock_refunded_items`).
     *
     * @param WC_Order $order     WooCommerce order (new items added, not saved).
     * @param array    $taken     stock_taken() BEFORE the items were replaced.
     * @param array    $restocked stock_restocked() BEFORE the items were replaced.
     */
    private function apply_stock_difference( $order, array $taken, array $restocked = array() ) {
        $wanted   = array();
        $products = array();
        $left     = $restocked; // Refunded units still to carry over, per key.
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( ! $item instanceof WC_Order_Item_Product ) {
                continue;
            }
            $product = $item->get_product();
            if ( ! $product || ! $product->managing_stock() ) {
                continue;
            }
            $key  = $this->stock_key( $item );
            $qty  = (int) $item->get_quantity();
            $back = min( $qty, isset( $left[ $key ] ) ? (int) $left[ $key ] : 0 );
            if ( $back > 0 ) {
                $left[ $key ] -= $back;
                $item->update_meta_data( '_restock_refunded_items', $back );
            }
            $wanted[ $key ]   = ( isset( $wanted[ $key ] ) ? $wanted[ $key ] : 0 ) + $qty - $back;
            $products[ $key ] = $product;
            if ( $qty - $back > 0 ) {
                $item->update_meta_data( '_reduced_stock', $qty - $back );
            }
        }
        foreach ( $wanted as $key => $qty ) {
            $diff = $qty - ( isset( $taken[ $key ] ) ? $taken[ $key ] : 0 );
            if ( $diff > 0 ) {
                wc_update_product_stock( $products[ $key ], $diff, 'decrease' );
            } elseif ( $diff < 0 ) {
                wc_update_product_stock( $products[ $key ], -$diff, 'increase' );
            }
        }
        // Products no longer in the order: their units come back.
        foreach ( $taken as $key => $units ) {
            if ( $units > 0 && ! isset( $wanted[ $key ] ) ) {
                $product = wc_get_product( $key );
                if ( $product && $product->managing_stock() ) {
                    wc_update_product_stock( $product, $units, 'increase' );
                }
            }
        }
        $this->flag_stock_reduced( $order );
        $order->update_meta_data( '_houla_stock_v2', '1' );
    }

    /**
     * The order stops holding stock (cancelled or refunded by an update):
     * give back what was taken, and clear the flag so WooCommerce does not
     * try to give it back a second time.
     *
     * @param WC_Order $order WooCommerce order (items already replaced).
     * @param array    $taken stock_taken() BEFORE the items were replaced.
     */
    private function release_stock( $order, array $taken ) {
        foreach ( $taken as $key => $units ) {
            if ( $units <= 0 ) {
                continue;
            }
            $product = wc_get_product( $key );
            if ( $product && $product->managing_stock() ) {
                wc_update_product_stock( $product, $units, 'increase' );
            }
        }
        $this->flag_stock_reduced( $order, false );
    }

    /**
     * Put back in stock the items of a refund, within what was taken for each
     * line. An item names its line by `external_variant_id` (API since
     * 2026-11-09); without it, `external_id` must designate ONE variation:
     * when it is the parent of several sold variations, nothing is put back
     * rather than the wrong variation.
     *
     * @param WC_Order $order WooCommerce order.
     * @param array    $items Refunded items from Hou.la.
     */
    private function restock_items( $order, array $items ) {
        $notes = array();
        $lines = array();
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( $item instanceof WC_Order_Item_Product ) {
                $lines[] = $item;
            }
        }
        foreach ( $items as $wanted ) {
            $product_id = isset( $wanted['external_id'] ) ? absint( $wanted['external_id'] ) : 0;
            $variant_id = isset( $wanted['external_variant_id'] ) ? absint( $wanted['external_variant_id'] ) : 0;
            $left       = isset( $wanted['quantity'] ) ? max( 0, absint( $wanted['quantity'] ) ) : 0;
            if ( ( ! $product_id && ! $variant_id ) || ! $left ) {
                continue;
            }
            $matching = array_values( array_filter( $lines, function ( $item ) use ( $product_id, $variant_id ) {
                if ( $variant_id ) {
                    return (int) $item->get_variation_id() === $variant_id;
                }
                return (int) $item->get_product_id() === $product_id || (int) $item->get_variation_id() === $product_id;
            } ) );
            $variations = array_unique( array_map( function ( $item ) { return (int) $item->get_variation_id(); }, $matching ) );
            if ( ! $variant_id && count( $variations ) > 1 ) {
                $notes[] = sprintf( __( 'produit #%d : plusieurs variations vendues, aucune remise en stock (variation non précisée par Hou.la)', 'wp-houla' ), $product_id );
                continue;
            }
            foreach ( $matching as $item ) {
                if ( $left <= 0 ) {
                    break;
                }
                $taken   = (int) $item->get_meta( '_reduced_stock', true );
                $product = $item->get_product();
                $n       = min( $left, $taken );
                if ( $n <= 0 || ! $product || ! $product->managing_stock() ) {
                    continue;
                }
                $new = wc_update_product_stock( $product, $n, 'increase' );
                // Same bookkeeping as wc_restock_refunded_items().
                if ( $taken - $n > 0 ) {
                    $item->update_meta_data( '_reduced_stock', $taken - $n );
                } else {
                    $item->delete_meta_data( '_reduced_stock' );
                }
                $item->update_meta_data( '_restock_refunded_items', (int) $item->get_meta( '_restock_refunded_items', true ) + $n );
                $item->save();
                $left   -= $n;
                $notes[] = $product->get_formatted_name() . ' +' . $n . ' (' . $new . ')';
            }
        }
        if ( $notes ) {
            $order->add_order_note( __( 'Stock remis après remboursement Hou.la :', 'wp-houla' ) . ' ' . implode( ', ', $notes ) );
        }
    }

    // =====================================================================
    // Lookup
    // =====================================================================

    /**
     * Find a WooCommerce order ID by Hou.la order ID.
     *
     * @param string $houla_order_id
     * @return int|false WooCommerce order ID or false.
     */
    public function find_order_by_houla_id( $houla_order_id ) {
        // Statuts réels uniquement : une commande à la CORBEILLE ne doit pas
        // compter comme existante. Sinon chaque resynchronisation la retrouve,
        // part sur update_order(), répond 200 avec un externalOrderId… et le
        // marchand ne voit jamais rien réapparaître dans WooCommerce.
        // wc_get_order_statuses() exclut trash / auto-draft / checkout-draft,
        // et le défaut implicite de wc_get_orders() diffère entre HPOS et le
        // stockage historique — d'où le passage explicite.
        $orders = wc_get_orders( array(
            'meta_key'   => '_houla_order_id',
            'meta_value' => sanitize_text_field( $houla_order_id ),
            'status'     => array_keys( wc_get_order_statuses() ),
            'limit'      => 1,
            'return'     => 'ids',
        ) );

        return ! empty( $orders ) ? $orders[0] : false;
    }

    // =====================================================================
    // Utilities
    // =====================================================================

    /**
     * Safely get a value from an array.
     *
     * @param array  $arr
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    private function safe( $arr, $key, $default = '' ) {
        return isset( $arr[ $key ] ) ? sanitize_text_field( $arr[ $key ] ) : $default;
    }

    /**
     * Persist and surface the buyer's Hou.la workspace identity.
     *
     * Hou.la buyers are identified by their workspace (display name, @handle,
     * avatar), not by a shipping name — on live / open-cart purchases there is
     * often no name or address at all. WooCommerce has no native field for a
     * pseudo or avatar, so we store them as order meta (rendered in the Hou.la
     * order metabox) and fall the billing name back to the display name so the
     * order is never attributed to a blank "Invité" customer.
     *
     * Le pseudo est préfixé au nom de FACTURATION — « (Choupot) haddaoui » —
     * parce que c'est ce nom que WooCommerce affiche comme titre de commande :
     * pendant un live, la vendeuse ne connaît sa cliente que par son pseudo
     * (chat, cadeaux, dashboard), mais l'expédition a besoin de l'identité
     * civile. Les deux informations sont donc portées séparément.
     *
     * ⚠️ JAMAIS sur l'adresse d'EXPÉDITION : elle alimente les étiquettes
     * transporteur (GLS, Colissimo, Mondial Relay), et un point relais remet le
     * colis contre pièce d'identité — un pseudo dessus et le colis repart en
     * retour. Cette méthode ne touche que la facturation, et elle s'exécute
     * APRÈS `set_address(..., 'shipping')` dans create_order comme dans
     * update_order : l'expédition reste intacte.
     *
     * @param WC_Order $order
     * @param array    $customer Webhook customer block.
     */
    private function apply_buyer_identity( $order, $customer ) {
        $display_name = $this->safe( $customer, 'display_name' );
        $handle       = $this->safe( $customer, 'social_handle' );
        $slug         = $this->safe( $customer, 'workspace_slug' );
        $avatar       = ! empty( $customer['avatar_url'] ) ? esc_url_raw( $customer['avatar_url'] ) : '';

        if ( $display_name !== '' ) {
            $order->update_meta_data( '_houla_buyer_display_name', $display_name );

            $first = $order->get_billing_first_name();
            $last  = $order->get_billing_last_name();

            if ( $first === '' && $last === '' ) {
                // Aucune identité civile captée : le pseudo tient lieu de client,
                // sans parenthèses (il n'y a rien à distinguer).
                $order->set_billing_first_name( $display_name );
            } elseif ( apply_filters( 'wphoula_prefix_billing_name_with_pseudo', true, $order, $customer ) ) {
                // Idempotent : update_order() rejoue ce bloc à CHAQUE
                // resynchronisation, sans quoi on empilerait
                // « (Choupot) (Choupot) haddaoui ».
                $prefix = '(' . $display_name . ')';
                $first  = $this->strip_pseudo_prefix( $first );
                $order->set_billing_first_name( trim( $prefix . ' ' . $first ) );
            }
        }
        if ( $handle !== '' ) {
            $order->update_meta_data( '_houla_buyer_handle', $handle );
        }
        if ( $slug !== '' ) {
            $order->update_meta_data( '_houla_buyer_slug', $slug );
        }
        if ( $avatar !== '' ) {
            $order->update_meta_data( '_houla_buyer_avatar', $avatar );
        }
    }

    /**
     * Retire un préfixe pseudo « (…) » déjà présent en tête du prénom de
     * facturation, quel qu'en soit le contenu — le displayName d'un workspace
     * peut changer entre deux synchronisations, donc on ne peut pas se contenter
     * de comparer au pseudo courant.
     *
     * @param string $first_name
     * @return string
     */
    private function strip_pseudo_prefix( $first_name ) {
        // Le modificateur /u renvoie null sur une chaîne UTF-8 invalide : on
        // garde alors le nom d'origine plutôt que de le vider.
        $stripped = preg_replace( '/^\s*\([^)]*\)\s*/u', '', $first_name );

        return trim( null === $stripped ? $first_name : $stripped );
    }

    /**
     * Attribute the order to Hou.la for the native WooCommerce "Origine" column.
     *
     * That column (WooCommerce Order Attribution) reads _wc_order_attribution_source_type
     * and _wc_order_attribution_utm_source and formats them via get_origin_label(). An
     * order created programmatically via wc_create_order() sets neither, so it shows
     * "Inconnu"/"Unknown". We stamp them so it renders "Hou.la" (bare, via the companion
     * label filter registered in Wp_Houla_Admin) or "Referral: Hou.la" otherwise.
     * update_meta_data() is HPOS-safe (writes wc_orders_meta or postmeta).
     *
     * @param WC_Order $order
     */
    private function apply_order_origin( $order ) {
        $order->update_meta_data( '_wc_order_attribution_source_type', 'referral' );
        $order->update_meta_data( '_wc_order_attribution_utm_source', 'Hou.la' );
        $order->update_meta_data( '_wc_order_attribution_utm_medium', 'marketplace' );
        $order->update_meta_data( '_wc_order_attribution_referrer', 'https://hou.la' );
    }

    /**
     * Persist the Sendcloud service point (Mondial Relay / locker) metadata on the
     * order — the relay ID/carrier/method the buyer selected. Called for BOTH create
     * and update so re-synced / open-cart-merged orders carry it too (the relay
     * delivery address itself arrives via customer.address → WC shipping address).
     *
     * @param WC_Order $order
     * @param array    $customer Webhook customer block.
     */
    private function apply_service_point( $order, $customer ) {
        if ( empty( $customer['service_point'] ) || ! is_array( $customer['service_point'] ) ) {
            return;
        }
        $sp = $customer['service_point'];
        if ( ! empty( $sp['id'] ) ) {
            $order->update_meta_data( '_houla_service_point_id', sanitize_text_field( $sp['id'] ) );
        }
        if ( ! empty( $sp['carrier'] ) ) {
            $order->update_meta_data( '_houla_service_point_carrier', sanitize_text_field( $sp['carrier'] ) );
        }
        if ( ! empty( $sp['method'] ) ) {
            $order->update_meta_data( '_houla_shipping_method', sanitize_text_field( $sp['method'] ) );
        }
    }

    // =====================================================================
    // Cliente WooCommerce (Trello #146, 1.6.10)
    // =====================================================================

    /**
     * Rattache la commande à la cliente WooCommerce qui a le même e-mail,
     * si la vendeuse l'a activé (`link_existing_accounts`, DÉSACTIVÉ par défaut).
     *
     * Jusqu'à la 1.6.9, toute commande Hou.la était une commande INVITÉE
     * (customer_id jamais posé) : WooCommerce affichait « Invité », la commande
     * n'apparaissait pas dans « Mon compte », et la liste Clients (Analytics,
     * wc_customer_lookup) recevait les coordonnées Hou.la à la place du profil
     * de la cliente, ou une 2e fiche (retour des Perles de Jo, 2026-11-09).
     *
     * ⚠️ WooCommerce ne prouve PAS l'e-mail d'un compte (banc fsn2, 2026-11-10,
     * relecture de sécurité) : l'inscription « Mon compte » connecte la personne
     * aussitôt, même quand le mot de passe est généré et envoyé par e-mail
     * (WC_Form_Handler::process_registration → wc_set_customer_auth_cookie) ;
     * « Détails du compte » change l'e-mail sans confirmation ; les extensions
     * de connexion sociale créent des comptes à partir d'e-mails non vérifiés.
     * Un tiers qui a un compte au nom de l'e-mail d'une acheteuse lirait donc
     * dans « Mon compte » l'adresse, le téléphone et les articles de ses
     * commandes Hou.la rattachées. D'où un réglage à activer en connaissance
     * de cause, et ces défenses quand il est actif :
     *  - simple compte client seulement (is_linkable_customer) ;
     *  - compte ouvert AVANT la commande : date la plus ancienne entre
     *    `paid_at` (charge utile) et la création de la commande WooCommerce,
     *    à la création comme à la mise à jour (resynchronisation, repli de
     *    update_order vers create_order) ; une date d'inscription vide ou nulle
     *    est refusée ;
     *  - compte dont l'e-mail a été modifié après sa création refusé
     *    (méta posée par flag_email_change, crochet profile_update : seuls les
     *    changements faits APRÈS l'installation de la 1.6.10 sont connus) ;
     *  - détachement si l'e-mail de la commande ne correspond plus au compte.
     * Le profil de la cliente n'est jamais modifié.
     *
     * Aucun compte : commande invitée. Un compte n'est créé que si la vendeuse
     * a coché `create_customer_account`, et seulement à la CRÉATION.
     *
     * @param WC_Order $order
     * @param array    $customer     Bloc `customer` du webhook.
     * @param bool     $allow_create Vrai à la création de la commande seulement.
     * @param string   $paid_at      `paid_at` de la charge utile ('' si absent).
     */
    private function attach_customer( $order, $customer, $allow_create, $paid_at = '' ) {
        $email = $this->normalize_email( isset( $customer['email'] ) ? $customer['email'] : '' );

        if ( ! $allow_create ) {
            $this->detach_if_email_changed( $order, $email );
        }
        if ( (int) $order->get_customer_id() > 0 || '' === $email ) {
            return;
        }

        $user = get_user_by( 'email', $email );
        if ( $user ) {
            if ( ! $this->options->get( 'link_existing_accounts' ) ) {
                $this->note_customer_once( $order, 'option-off', __( 'Un compte client du site a le même e-mail. La commande n\'y est pas rattachée : le rattachement automatique est désactivé dans les réglages de l\'extension (onglet Commandes).', 'wp-houla' ) );
                return;
            }
            if ( ! $this->is_linkable_customer( $user ) ) {
                $this->note_customer_once( $order, 'not-a-customer', __( 'Cet e-mail est celui d\'un compte du site qui n\'est pas un compte client (administrateur, gestionnaire…). Par sécurité, la commande n\'y est pas rattachée : elle reste une commande invitée.', 'wp-houla' ) );
                $this->log( 'Hou.la buyer email matches non-customer user #' . $user->ID . ': order kept as guest.' );
                return;
            }
            if ( get_user_meta( $user->ID, '_wphoula_email_changed_at', true ) ) {
                $this->note_customer_once( $order, 'email-changed', __( 'Un compte client a le même e-mail, mais cet e-mail a été modifié après la création du compte. Par sécurité, la commande n\'y est pas rattachée : elle reste une commande invitée.', 'wp-houla' ) );
                $this->log( 'Customer #' . $user->ID . ' changed its email: not attached.' );
                return;
            }
            if ( ! $this->registered_before_order( $user, $order, $paid_at ) ) {
                $this->note_customer_once( $order, 'account-too-recent', __( 'Un compte client a le même e-mail, mais il a été ouvert après cette commande (ou sa date d\'ouverture est inconnue). Par sécurité, la commande n\'y est pas rattachée : elle reste une commande invitée.', 'wp-houla' ) );
                $this->log( 'Customer #' . $user->ID . ' registered after the order: not attached.' );
                return;
            }
            $order->set_customer_id( $user->ID );
            $this->note_customer_once( $order, 'linked', __( 'Commande rattachée au compte client qui a le même e-mail (réglage de l\'extension). Son profil n\'a pas été modifié.', 'wp-houla' ) );
            $this->log( 'Order for Hou.la buyer attached to existing customer #' . $user->ID . '.' );
            return;
        }

        if ( ! $allow_create || ! $this->options->get( 'create_customer_account' ) ) {
            return; // Commande invitée : aucun compte, aucun e-mail de mot de passe.
        }

        $user_id = $this->create_customer_account( $email, $customer );
        if ( is_wp_error( $user_id ) ) {
            $this->note_customer_once( $order, 'account-failed', sprintf(
                /* translators: %s: WooCommerce error message */
                __( 'Le compte client n\'a pas pu être créé (%s) : la commande reste une commande invitée.', 'wp-houla' ),
                wp_strip_all_tags( $user_id->get_error_message() )
            ) );
            $this->log( 'Customer account creation failed: ' . $user_id->get_error_code() );
            return;
        }

        $order->set_customer_id( $user_id );
        $this->note_customer_once( $order, 'account-created', __( 'Compte client créé pour cette nouvelle acheteuse Hou.la (réglage de l\'extension). WooCommerce lui a envoyé son e-mail de bienvenue.', 'wp-houla' ) );
        $this->log( 'Customer account #' . $user_id . ' created for a new Hou.la buyer.' );
    }

    /**
     * Crochet `profile_update` : retient qu'un compte a changé d'e-mail
     * (« Détails du compte », profil WordPress, REST…), pour ne plus jamais
     * lui rattacher de commande Hou.la. Ne voit que les changements faits
     * après l'installation de l'extension en 1.6.10.
     *
     * @param int     $user_id
     * @param WP_User $old_user_data Compte AVANT la modification.
     */
    public static function flag_email_change( $user_id, $old_user_data = null ) {
        if ( ! is_object( $old_user_data ) || ! isset( $old_user_data->user_email ) ) {
            return;
        }
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        if ( strtolower( trim( (string) $old_user_data->user_email ) ) !== strtolower( trim( (string) $user->user_email ) ) ) {
            update_user_meta( $user_id, '_wphoula_email_changed_at', time() );
        }
    }

    /**
     * Le compte a-t-il été ouvert avant la commande ? Référence : la date la
     * plus ancienne entre `paid_at` (Hou.la) et la création de la commande
     * WooCommerce. `user_registered` est en UTC ; vide, nul ou à
     * « 0000-00-00 » (horodatage ≤ 0) : refusé.
     *
     * @param WP_User  $user
     * @param WC_Order $order
     * @param string   $paid_at
     * @return bool
     */
    private function registered_before_order( $user, $order, $paid_at = '' ) {
        $reg = ! empty( $user->user_registered ) ? strtotime( $user->user_registered . ' UTC' ) : false;
        if ( false === $reg || $reg <= 0 ) {
            return false;
        }

        $refs    = array();
        $created = method_exists( $order, 'get_date_created' ) ? $order->get_date_created() : null;
        if ( $created ) {
            $refs[] = (int) $created->getTimestamp();
        }
        if ( is_string( $paid_at ) && '' !== $paid_at ) {
            $paid = strtotime( $paid_at );
            if ( false !== $paid && $paid > 0 ) {
                $refs[] = $paid;
            }
        }
        if ( empty( $refs ) ) {
            return false;
        }
        return $reg < min( $refs );
    }

    /**
     * Détache une commande que l'extension avait rattachée (à un compte
     * existant ou créé par elle) quand son e-mail de facturation ne correspond
     * plus à ce compte : la commande redevient invitée, avec une note. Une
     * commande rattachée à la main par la vendeuse n'est jamais touchée.
     *
     * @param WC_Order $order
     * @param string   $email E-mail normalisé reçu de Hou.la ('' si absent).
     */
    private function detach_if_email_changed( $order, $email ) {
        $user_id = (int) $order->get_customer_id();
        if ( $user_id <= 0 || ! in_array( $order->get_meta( '_houla_customer_link' ), array( 'linked', 'account-created' ), true ) ) {
            return;
        }
        $user = get_user_by( 'id', $user_id );
        if ( $user && '' !== $email && $this->normalize_email( $user->user_email ) === $email ) {
            return;
        }
        $order->set_customer_id( 0 );
        $this->note_customer_once( $order, 'unlinked', __( 'L\'e-mail de la commande ne correspond plus au compte client auquel elle était rattachée : la commande redevient une commande invitée.', 'wp-houla' ) );
        $this->log( 'Order detached from customer #' . $user_id . ' (email changed).' );
    }

    /**
     * Crée le compte client d'une nouvelle acheteuse (option activée).
     *
     * wc_create_new_customer() génère l'identifiant et le mot de passe, puis
     * déclenche `woocommerce_created_customer` : WooCommerce envoie alors son
     * e-mail « Nouveau compte » (lien pour choisir le mot de passe), s'il est
     * activé dans WooCommerce > Réglages > E-mails. Le profil reçoit le prénom
     * et le nom CIVILS de la commande, jamais le pseudo Hou.la.
     *
     * @param string $email    E-mail normalisé.
     * @param array  $customer Bloc `customer` du webhook.
     * @return int|WP_Error
     */
    private function create_customer_account( $email, $customer ) {
        if ( ! function_exists( 'wc_create_new_customer' ) ) {
            return new WP_Error( 'wphoula_no_wc', 'WooCommerce indisponible.' );
        }
        $first = $this->safe( $customer, 'first_name' );
        $last  = $this->safe( $customer, 'last_name' );

        $user_id = wc_create_new_customer( $email, '', '', array(
            'first_name' => $first,
            'last_name'  => $last,
        ) );
        if ( is_wp_error( $user_id ) ) {
            return $user_id;
        }

        // Coordonnées de facturation du profil : uniquement ce que la
        // cliente a donné pour CETTE commande, pour un compte qui vient de
        // naître (aucun profil existant n'est jamais réécrit).
        if ( class_exists( 'WC_Customer' ) ) {
            try {
                $wc_customer = new WC_Customer( $user_id );
                $wc_customer->set_billing_email( $email );
                $wc_customer->set_billing_first_name( $first );
                $wc_customer->set_billing_last_name( $last );
                $wc_customer->save();
            } catch ( \Exception $e ) {
                $this->log( 'Customer profile not completed: ' . $e->getMessage() );
            }
        }

        return (int) $user_id;
    }

    /**
     * Un compte ne reçoit une commande Hou.la que s'il est un simple compte
     * client : tous ses rôles sont dans la liste autorisée (`customer` par
     * défaut) et il n'a aucune capacité d'administration ou d'édition. Sur un
     * multisite, il doit aussi être membre de CE site (un super-administrateur
     * sans rôle ici est refusé).
     *
     * @param WP_User $user
     * @return bool
     */
    private function is_linkable_customer( $user ) {
        $roles   = array_values( (array) $user->roles );
        $allowed = (array) apply_filters( 'wphoula_linkable_customer_roles', array( 'customer' ) );

        if ( empty( $roles ) || array_diff( $roles, $allowed ) ) {
            return false;
        }
        foreach ( array( 'manage_options', 'manage_woocommerce', 'edit_shop_orders', 'edit_posts', 'list_users', 'edit_users', 'create_users', 'promote_users', 'delete_users' ) as $cap ) {
            if ( user_can( $user, $cap ) ) {
                return false;
            }
        }
        if ( function_exists( 'is_multisite' ) && is_multisite() && ! is_user_member_of_blog( $user->ID ) ) {
            return false;
        }
        return true;
    }

    /**
     * E-mail comparable : sans espaces autour, en minuscules, valide.
     *
     * @param mixed $raw
     * @return string '' si absent ou invalide.
     */
    private function normalize_email( $raw ) {
        if ( ! is_string( $raw ) ) {
            return '';
        }
        $email = strtolower( trim( $raw ) );
        if ( '' === $email || ! is_email( $email ) ) {
            return '';
        }
        return $email;
    }

    /**
     * Note privée de rattachement, une seule fois par décision : update_order()
     * repasse ici à chaque resynchronisation (panier ouvert, relance).
     *
     * @param WC_Order $order
     * @param string   $decision
     * @param string   $note
     */
    private function note_customer_once( $order, $decision, $note ) {
        if ( $order->get_meta( '_houla_customer_link' ) === $decision ) {
            return;
        }
        $order->update_meta_data( '_houla_customer_link', $decision );
        $order->add_order_note( $note );
    }

    /**
     * Increment a numeric counter in options.
     *
     * @param string $key
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
            error_log( '[WP-Houla Orders] ' . $message );
        }
    }
}
