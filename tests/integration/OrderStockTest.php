<?php
/**
 * Stock des commandes reçues de Hou.la (1.6.9).
 *
 * Banc du 2026-11-09 (WordPress 7.1 + WooCommerce 11.2 réels) : une vente de
 * 1 unité sur Hou.la, pendant un live par exemple, retirait 2 unités du stock
 * WooCommerce (le module à la main, puis WooCommerce au passage « En cours »),
 * et le module renvoyait ce stock faux à Hou.la. Un article ajouté à un panier
 * ouvert ne retirait rien, puis la clôture du panier retirait tout une 2e fois.
 *
 * Ces tests vérifient les décisions du module ; la mécanique de WooCommerce
 * (wc_reduce_stock_levels, wc_increase_stock_levels) est prouvée sur le banc.
 */

namespace WpHoula\Tests\Integration;

use WpHoula\Tests\TestCase;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-options.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-orders.php';

/** Produit dont le stock est géré. */
class StockProduct {
    private $id;
    public function __construct( $id ) { $this->id = $id; }
    public function get_id() { return $this->id; }
    public function get_manage_stock() { return true; }
    public function managing_stock() { return true; }
    public function get_formatted_name() { return 'Produit #' . $this->id; }
}

/** Commande dont les lignes sont de vraies lignes produit (WC_Order_Item_Product). */
class StockOrder {
    public $meta   = array();
    public $items  = array();
    public $status = '';
    public $notes  = array();
    private $next  = 1;

    public function get_id() { return 300; }
    public function add_product( $product, $qty, $args = array() ) { $this->items[ $this->next++ ] = new \WC_Order_Item_Product( $product, $qty ); }
    public function add_item( $item ) { $this->items[ $this->next++ ] = $item; }
    public function get_items( $types = 'line_item' ) {
        if ( 'line_item' === $types ) {
            return array_filter( $this->items, function ( $i ) { return $i instanceof \WC_Order_Item_Product; } );
        }
        return $this->items;
    }
    public function remove_item( $id ) { unset( $this->items[ $id ] ); }
    public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
    public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
    public function get_meta( $k = '', $single = true ) { return $this->meta[ $k ] ?? ''; }
    public function save_meta_data() {}
    public function save() {}
    public function set_address( $d, $t ) {}
    public function set_billing_first_name( $n ) {}
    public function get_billing_first_name() { return ''; }
    public function get_billing_last_name() { return ''; }
    public function set_payment_method( $m ) {}
    public function set_payment_method_title( $t ) {}
    public function set_currency( $c ) {}
    public function set_transaction_id( $t ) {}
    public function set_date_paid( $d ) {}
    public function calculate_totals() {}
    public function set_total( $t ) {}
    public function get_total() { return 36.0; }
    public function set_status( $s, $note = '' ) { $this->status = $s; }
    public function get_status() { return $this->status; }
    public function add_order_note( $n, $customer = 0 ) { $this->notes[] = $n; }
}

class OrderStockTest extends TestCase {

    /** @var array stock moves: [product id, quantity, 'increase'|'decrease'] */
    private $moves = array();

    protected function setUp(): void {
        parent::setUp();
        $this->moves = array();
        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'apply_filters' )->returnArg( 2 );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) { return array_merge( $defaults, $args ); } );
        Functions\when( 'is_wp_error' )->alias( function ( $t ) { return $t instanceof \WP_Error; } );
        Functions\when( 'current_time' )->justReturn( '2026-11-09 21:00:00' );
        Functions\when( 'wc_get_order_statuses' )->justReturn( array( 'wc-processing' => 'Processing', 'wc-open-cart' => 'Open cart', 'wc-cancelled' => 'Cancelled' ) );
        Functions\when( 'wc_get_product' )->alias( function ( $id ) { return new StockProduct( (int) $id ); } );
        Functions\when( 'wc_update_product_stock' )->alias( function ( $product, $qty, $op ) {
            $this->moves[] = array( $product->get_id(), $qty, $op );
            return 0;
        } );
    }

    private function item( $order, $product_id ) {
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            if ( $item->get_product_id() === $product_id ) {
                return $item;
            }
        }
        return null;
    }

    public function test_paid_order_takes_the_stock_once_through_woocommerce() {
        $order = new StockOrder();
        Functions\when( 'wc_create_order' )->justReturn( $order );
        Functions\when( 'wc_get_orders' )->justReturn( array() );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        Functions\expect( 'wc_reduce_stock_levels' )->once()->with( 300 );

        ( new \Wp_Houla_Orders() )->create_order( array(
            'houla_order_id' => 'HL-LIVE-1',
            'items'          => array( array( 'external_id' => '41', 'quantity' => 1, 'price' => 12 ) ),
            'wc_status'      => 'processing',
        ) );

        // Avant : wc_update_product_stock(41, 1, 'decrease') ici, PUIS WooCommerce
        // retirait encore 1 au passage « processing ».
        $this->assertSame( array(), $this->moves );
        $this->assertSame( '1', $order->meta['_houla_stock_v2'] );
    }

    public function test_item_added_to_an_open_cart_takes_only_its_own_stock() {
        $order = new StockOrder();
        $order->meta = array( '_houla_order_id' => 'HL-CART-1', '_houla_stock_v2' => '1' );
        $order->add_product( new StockProduct( 41 ), 1 );
        $this->item( $order, 41 )->update_meta_data( '_reduced_stock', 1 ); // déjà retiré
        Functions\when( 'wc_get_orders' )->justReturn( array( 300 ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        ( new \Wp_Houla_Orders() )->update_order( array(
            'houla_order_id' => 'HL-CART-1',
            'items'          => array(
                array( 'external_id' => '41', 'quantity' => 1, 'price' => 12 ),
                array( 'external_id' => '42', 'quantity' => 2, 'price' => 12 ),
            ),
            'wc_status'      => 'open-cart',
        ) );

        // Avant : rien retiré pour le 42, puis tout retiré une 2e fois à la clôture.
        $this->assertSame( array( array( 42, 2, 'decrease' ) ), $this->moves );
        $this->assertSame( 1, $this->item( $order, 41 )->get_meta( '_reduced_stock' ) );
        $this->assertSame( 2, $this->item( $order, 42 )->get_meta( '_reduced_stock' ) );
    }

    public function test_quantity_lowered_or_item_removed_gives_the_units_back() {
        $order = new StockOrder();
        $order->meta = array( '_houla_order_id' => 'HL-CART-2', '_houla_stock_v2' => '1' );
        $order->add_product( new StockProduct( 41 ), 3 );
        $order->add_product( new StockProduct( 42 ), 1 );
        $this->item( $order, 41 )->update_meta_data( '_reduced_stock', 3 );
        $this->item( $order, 42 )->update_meta_data( '_reduced_stock', 1 );
        Functions\when( 'wc_get_orders' )->justReturn( array( 300 ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        ( new \Wp_Houla_Orders() )->update_order( array(
            'houla_order_id' => 'HL-CART-2',
            'items'          => array( array( 'external_id' => '41', 'quantity' => 1, 'price' => 12 ) ),
        ) );

        $this->assertSame( array( array( 41, 2, 'increase' ), array( 42, 1, 'increase' ) ), $this->moves );
    }

    /**
     * Commande créée avant la 1.6.9 : le stock a été retiré à la main, sans la
     * comptabilité de WooCommerce. La clôture du panier ne doit pas le retirer
     * une 2e fois.
     */
    public function test_order_created_before_1_6_9_is_not_reduced_twice() {
        $order = new StockOrder();
        $order->meta = array( '_houla_order_id' => 'HL-OLD-1' );
        $order->add_product( new StockProduct( 41 ), 1 );
        Functions\when( 'wc_get_orders' )->justReturn( array( 300 ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        ( new \Wp_Houla_Orders() )->update_order_status( array( 'houla_order_id' => 'HL-OLD-1', 'wc_status' => 'processing' ) );

        $this->assertSame( 1, $this->item( $order, 41 )->get_meta( '_reduced_stock' ) );
        $this->assertSame( '1', $order->meta['_houla_stock_v2'] );
        $this->assertSame( 'processing', $order->status );
        $this->assertSame( array(), $this->moves );
    }

    public function test_refund_puts_back_the_items_hou_la_restocks() {
        $order = new StockOrder();
        $order->meta = array( '_houla_order_id' => 'HL-REF-1', '_houla_stock_v2' => '1' );
        $order->add_product( new StockProduct( 41 ), 2 );
        $this->item( $order, 41 )->update_meta_data( '_reduced_stock', 2 );
        Functions\when( 'wc_get_orders' )->justReturn( array( 300 ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        Functions\when( 'wc_create_refund' )->justReturn( (object) array( 'id' => 9 ) );
        Functions\when( 'wc_price' )->returnArg( 1 );

        ( new \Wp_Houla_Orders() )->refund_order( array(
            'houla_order_id' => 'HL-REF-1',
            'refund_amount'  => 12,
            'restock_items'  => true,
            'items'          => array( array( 'external_id' => '41', 'quantity' => 1 ) ),
        ) );

        // Avant : wc_create_refund() sans lignes ne remettait rien en stock.
        $this->assertSame( array( array( 41, 1, 'increase' ) ), $this->moves );
        $this->assertSame( 1, $this->item( $order, 41 )->get_meta( '_reduced_stock' ) );
    }

    public function test_refund_without_restock_moves_nothing() {
        $order = new StockOrder();
        $order->meta = array( '_houla_order_id' => 'HL-REF-2', '_houla_stock_v2' => '1' );
        $order->add_product( new StockProduct( 41 ), 2 );
        $this->item( $order, 41 )->update_meta_data( '_reduced_stock', 2 );
        Functions\when( 'wc_get_orders' )->justReturn( array( 300 ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        Functions\when( 'wc_create_refund' )->justReturn( (object) array( 'id' => 9 ) );
        Functions\when( 'wc_price' )->returnArg( 1 );

        ( new \Wp_Houla_Orders() )->refund_order( array(
            'houla_order_id' => 'HL-REF-2',
            'refund_amount'  => 12,
            'restock_items'  => false,
            'items'          => array( array( 'external_id' => '41', 'quantity' => 1 ) ),
        ) );

        $this->assertSame( array(), $this->moves );
    }
}
