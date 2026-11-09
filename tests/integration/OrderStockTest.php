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

/** Drapeau « stock déjà retiré » de WooCommerce (get/set_stock_reduced). */
class StockDataStore {
    public $reduced = false;
    public function get_stock_reduced( $id ) { return $this->reduced; }
    public function set_stock_reduced( $id, $value ) { $this->reduced = (bool) $value; }
}

/** Commande dont les lignes sont de vraies lignes produit (WC_Order_Item_Product). */
class StockOrder {
    public $store;
    /** @var array[] méta de la commande au moment de chaque save() */
    public $saved_meta = array();
    public function __construct() { $this->store = new StockDataStore(); }
    public function get_data_store() { return $this->store; }
    public function add_variation( $variation_id, $parent_id, $qty ) { $this->items[ $this->next++ ] = new \WC_Order_Item_Product( new StockProduct( $variation_id ), $qty, $parent_id ); }
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
    public function save() { $this->saved_meta[] = $this->meta; }
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
        $order->status = 'open-cart';
        $order->store->reduced = true;
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
        $order->status = 'open-cart';
        $order->store->reduced = true;
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
        $order->status = 'open-cart';
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

    // ── Relecture de sécurité du 2026-11-09 ─────────────────────────────

    /**
     * Commande annulée : WooCommerce a rendu le stock (`_reduced_stock`
     * effacé, drapeau à faux). Un order.paid relancé par Hou.la
     * (retryFailedOrderWebhooks, resyncFailedOrders) avec wc_status
     * « cancelled » repasse par update_order : il ne doit RIEN retirer.
     */
    public function test_replayed_webhook_of_a_cancelled_order_takes_nothing() {
        $order = new StockOrder();
        $order->meta   = array( '_houla_order_id' => 'HL-ANN-1', '_houla_stock_v2' => '1' );
        $order->status = 'cancelled';
        $order->add_product( new StockProduct( 41 ), 2 ); // rendu : plus de _reduced_stock
        Functions\when( 'wc_get_orders' )->justReturn( array( 300 ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        ( new \Wp_Houla_Orders() )->create_order( array(
            'houla_order_id' => 'HL-ANN-1',
            'items'          => array( array( 'external_id' => '41', 'quantity' => 2, 'price' => 12 ) ),
            'wc_status'      => 'cancelled',
        ) );

        // Avant : wc_update_product_stock(41, 2, 'decrease'), jamais rendu.
        $this->assertSame( array(), $this->moves );
    }

    public function test_order_arriving_cancelled_is_never_reduced() {
        $order = new StockOrder();
        Functions\when( 'wc_create_order' )->justReturn( $order );
        Functions\when( 'wc_get_orders' )->justReturn( array() );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        Functions\expect( 'wc_reduce_stock_levels' )->never();

        ( new \Wp_Houla_Orders() )->create_order( array(
            'houla_order_id' => 'HL-ANN-2',
            'items'          => array( array( 'external_id' => '41', 'quantity' => 1, 'price' => 12 ) ),
            'wc_status'      => 'cancelled',
        ) );

        $this->assertSame( array(), $this->moves );
    }

    public function test_update_that_cancels_the_order_gives_the_stock_back_once() {
        $order = new StockOrder();
        $order->meta   = array( '_houla_order_id' => 'HL-ANN-3', '_houla_stock_v2' => '1' );
        $order->status = 'processing';
        $order->store->reduced = true;
        $order->add_product( new StockProduct( 41 ), 2 );
        $this->item( $order, 41 )->update_meta_data( '_reduced_stock', 2 );
        Functions\when( 'wc_get_orders' )->justReturn( array( 300 ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        ( new \Wp_Houla_Orders() )->update_order( array(
            'houla_order_id' => 'HL-ANN-3',
            'items'          => array( array( 'external_id' => '41', 'quantity' => 2, 'price' => 12 ) ),
            'wc_status'      => 'cancelled',
        ) );

        // Rendu ici, et drapeau baissé : WooCommerce ne rendra pas une 2e fois.
        $this->assertSame( array( array( 41, 2, 'increase' ) ), $this->moves );
        $this->assertFalse( $order->store->reduced );
        $this->assertSame( 'cancelled', $order->status );
    }

    public function test_update_after_a_partial_refund_does_not_take_the_refunded_units_again() {
        $order = new StockOrder();
        $order->meta   = array( '_houla_order_id' => 'HL-REF-3', '_houla_stock_v2' => '1' );
        $order->status = 'processing';
        $order->store->reduced = true;
        $order->add_product( new StockProduct( 41 ), 2 );
        $this->item( $order, 41 )->update_meta_data( '_reduced_stock', 1 );          // 1 unité remise en stock
        $this->item( $order, 41 )->update_meta_data( '_restock_refunded_items', 1 ); // par le remboursement
        Functions\when( 'wc_get_orders' )->justReturn( array( 300 ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        ( new \Wp_Houla_Orders() )->update_order( array(
            'houla_order_id' => 'HL-REF-3',
            'items'          => array( array( 'external_id' => '41', 'quantity' => 2, 'price' => 12 ) ),
        ) );

        $this->assertSame( array(), $this->moves );
        $this->assertSame( 1, $this->item( $order, 41 )->get_meta( '_reduced_stock' ) );
    }

    private function variationsOrder( $houla_id ) {
        $order = new StockOrder();
        $order->meta   = array( '_houla_order_id' => $houla_id, '_houla_stock_v2' => '1' );
        $order->status = 'processing';
        $order->store->reduced = true;
        $order->add_variation( 1301, 1201, 1 ); // Bleu
        $order->add_variation( 1302, 1201, 2 ); // Rouge
        foreach ( $order->get_items( 'line_item' ) as $item ) {
            $item->update_meta_data( '_reduced_stock', $item->get_quantity() );
        }
        Functions\when( 'wc_get_orders' )->justReturn( array( 300 ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        Functions\when( 'wc_create_refund' )->justReturn( (object) array( 'id' => 9 ) );
        Functions\when( 'wc_price' )->returnArg( 1 );
        return $order;
    }

    /** API d'avant le 2026-11-09 : l'id du PARENT seul, sans variante. */
    public function test_refund_naming_only_the_parent_of_several_variations_restocks_nothing() {
        $this->variationsOrder( 'HL-VAR-1' );

        ( new \Wp_Houla_Orders() )->refund_order( array(
            'houla_order_id' => 'HL-VAR-1',
            'refund_amount'  => 36,
            'restock_items'  => true,
            'items'          => array( array( 'external_id' => '1201', 'quantity' => 1 ) ),
        ) );

        // Avant : la 1re ligne trouvée (Bleu) était remise en stock, au hasard.
        $this->assertSame( array(), $this->moves );
    }

    public function test_refund_with_the_variant_id_restocks_that_variation() {
        $this->variationsOrder( 'HL-VAR-2' );

        ( new \Wp_Houla_Orders() )->refund_order( array(
            'houla_order_id' => 'HL-VAR-2',
            'refund_amount'  => 24,
            'restock_items'  => true,
            'items'          => array( array( 'external_id' => '1201', 'external_variant_id' => '1302', 'quantity' => 2 ) ),
        ) );

        $this->assertSame( array( array( 1302, 2, 'increase' ) ), $this->moves );
    }

    private function fakeDb( $lock_answer ) {
        return new class( $lock_answer ) {
            public $queries = array();
            private $answer;
            public function __construct( $a ) { $this->answer = $a; }
            public function prepare( $q, ...$a ) { return vsprintf( str_replace( array( '%s', '%d' ), array( "'%s'", '%d' ), $q ), $a ); }
            public function get_var( $q ) { $this->queries[] = $q; return 0 === strpos( $q, 'SELECT GET_LOCK' ) ? $this->answer : '1'; }
        };
    }

    public function test_webhook_is_refused_while_another_one_updates_the_same_order() {
        $order = new StockOrder();
        $order->meta   = array( '_houla_order_id' => 'HL-LOCK-1', '_houla_stock_v2' => '1' );
        $order->status = 'open-cart';
        $order->store->reduced = true;
        Functions\when( 'wc_get_orders' )->justReturn( array( 300 ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        $GLOBALS['wpdb'] = $this->fakeDb( '0' );

        $r = ( new \Wp_Houla_Orders() )->update_order( array(
            'houla_order_id' => 'HL-LOCK-1',
            'items'          => array( array( 'external_id' => '42', 'quantity' => 2, 'price' => 12 ) ),
        ) );
        unset( $GLOBALS['wpdb'] );

        // Avant : aucun verrou, le 2e webhook retirait 2 unités de plus.
        $this->assertInstanceOf( \WP_Error::class, $r );
        $this->assertSame( 'wphoula_order_busy', $r->get_error_code() );
        $this->assertSame( array(), $this->moves );
    }

    public function test_lock_is_taken_and_released_around_the_update() {
        $order = new StockOrder();
        $order->meta   = array( '_houla_order_id' => 'HL-LOCK-2', '_houla_stock_v2' => '1' );
        $order->status = 'open-cart';
        $order->store->reduced = true;
        Functions\when( 'wc_get_orders' )->justReturn( array( 300 ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        $db = $this->fakeDb( '1' );
        $GLOBALS['wpdb'] = $db;

        ( new \Wp_Houla_Orders() )->create_order( array( // doublon → update_order : verrou ré-entrant
            'houla_order_id' => 'HL-LOCK-2',
            'items'          => array( array( 'external_id' => '42', 'quantity' => 2, 'price' => 12 ) ),
        ) );
        unset( $GLOBALS['wpdb'] );

        $this->assertCount( 2, $db->queries );
        $this->assertStringStartsWith( 'SELECT GET_LOCK', $db->queries[0] );
        $this->assertStringStartsWith( 'SELECT RELEASE_LOCK', $db->queries[1] );
        $this->assertSame( array( array( 42, 2, 'decrease' ) ), $this->moves );
    }

    /** Une commande 1.6.9 interrompue après son 1er save() n'est pas prise pour une ancienne. */
    public function test_new_order_is_marked_1_6_9_before_its_first_save() {
        $order = new StockOrder();
        Functions\when( 'wc_create_order' )->justReturn( $order );
        Functions\when( 'wc_get_orders' )->justReturn( array() );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        ( new \Wp_Houla_Orders() )->create_order( array(
            'houla_order_id' => 'HL-V2-1',
            'items'          => array( array( 'external_id' => '41', 'quantity' => 1, 'price' => 12 ) ),
            'wc_status'      => 'open-cart',
        ) );

        $this->assertSame( '1', $order->saved_meta[0]['_houla_stock_v2'] ?? null );
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
