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
 * - Decrements stock
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

                // Decrement stock
                if ( $product && $product->get_manage_stock() ) {
                    wc_update_product_stock( $product, $quantity, 'decrease' );
                }
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
        if ( empty( $data['houla_order_id'] ) ) {
            return new WP_Error( 'wphoula_invalid_refund', 'Missing houla_order_id.' );
        }

        $order_id = $this->find_order_by_houla_id( $data['houla_order_id'] );
        if ( ! $order_id ) {
            return new WP_Error( 'wphoula_order_not_found', 'Order not found for ' . $data['houla_order_id'] . '.' );
        }

        $order  = wc_get_order( $order_id );
        $reason = isset( $data['reason'] ) ? sanitize_text_field( $data['reason'] ) : __( 'Refunded via Hou.la.', 'wp-houla' );

        // Determine refund amount: use webhook value or fall back to order total
        $refund_amount = isset( $data['refund_amount'] ) ? floatval( $data['refund_amount'] ) : floatval( $order->get_total() );

        // Determine restock: defaults to true if not specified
        $restock = isset( $data['restock_items'] ) ? (bool) $data['restock_items'] : true;

        // Create the refund
        $refund = wc_create_refund( array(
            'amount'         => $refund_amount,
            'reason'         => $reason,
            'order_id'       => $order_id,
            'refund_payment' => false, // Payment was already refunded on Stripe side
            'restock_items'  => $restock,
        ) );

        if ( is_wp_error( $refund ) ) {
            $this->log( 'Refund failed for WC #' . $order_id . ': ' . $refund->get_error_message() );
            return $refund;
        }

        // Only set status to refunded if full refund (amount >= order total)
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
        'shipped'             => 'completed',
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
        if ( empty( $data['houla_order_id'] ) || empty( $data['wc_status'] ) ) {
            return new WP_Error( 'wphoula_invalid_status', 'Missing houla_order_id or wc_status.' );
        }

        $order_id = $this->find_order_by_houla_id( $data['houla_order_id'] );
        if ( ! $order_id ) {
            return new WP_Error( 'wphoula_order_not_found', 'Order not found for ' . $data['houla_order_id'] . '.' );
        }

        $order     = wc_get_order( $order_id );
        $wc_status = sanitize_text_field( $data['wc_status'] );

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

        $this->log( 'Order WC #' . $order_id . ' status changed to "' . $wc_status . '" (Hou.la ' . $data['houla_order_id'] . ').' );

        return array( 'order_id' => $order_id );
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
        $orders = wc_get_orders( array(
            'meta_key'   => '_houla_order_id',
            'meta_value' => sanitize_text_field( $houla_order_id ),
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
