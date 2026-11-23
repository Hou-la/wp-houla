<?php
/**
 * Unit tests for Wp_Houla_Pay_Refunds (Trello #324).
 *
 * Le bouton « Rembourser X € via Hou.la Pay » de WooCommerce demande à Hou.la
 * de rembourser VRAIMENT la cliente. Ce qui est vérifié ici : ce qui part
 * vers Hou.la (numéro du remboursement WooCommerce, montant, lignes), sous le
 * verrou de la commande ; la marque `_houla_refund_id` posée AVANT de rendre
 * le verrou ; le message affiché et l'absence de note en cas d'échec ; le
 * blocage d'un second remboursement après une issue inconnue.
 */

namespace WpHoula\Tests\Unit;

use WpHoula\Tests\TestCase;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-refund-notice.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-pay-refunds.php';

class PayRefundsTest extends TestCase {

    /** @var array<int, object> */
    private $orders_by_id = array();

    /** @var object */
    private $api;

    /** @var object */
    private $auth;

    /** @var object */
    private $locks;

    protected function setUp(): void {
        parent::setUp();
        $this->orders_by_id = array();
        $locks              = new class() {
            public $busy    = false;
            public $held    = false;
            public $keys    = array();
            public function with_order_lock( $key, $callback ) {
                if ( $this->busy ) {
                    return new \WP_Error( 'wphoula_order_busy', 'busy', array( 'status' => 409 ) );
                }
                $this->keys[] = $key;
                $this->held   = true;
                try {
                    return call_user_func( $callback );
                } finally {
                    $this->held = false;
                }
            }
        };
        $this->locks = $locks;
        $this->api   = new class( $locks ) {
            public $calls    = array();
            public $response = array( 'success' => true, 'refund_request_id' => 'dem-1', 'stripe_refund_id' => 're_123' );
            public $locked_during_call = null;
            private $locks;
            public function __construct( $locks ) { $this->locks = $locks; }
            public function post( $endpoint, array $body = array(), array $overrides = array() ) {
                $this->calls[]            = array( $endpoint, $body );
                $this->locked_during_call = $this->locks->held;
                return $this->response;
            }
        };
        $this->auth = new class() {
            public $connected = true;
            public function is_connected() { return $this->connected; }
        };
        $orders = &$this->orders_by_id;
        Functions\when( 'wc_get_order' )->alias( function ( $id ) use ( &$orders ) {
            return isset( $orders[ $id ] ) ? $orders[ $id ] : false;
        } );
        Functions\when( 'is_wp_error' )->alias( function ( $v ) { return $v instanceof \WP_Error; } );
        \Wp_Houla_Pay_Refunds::set_instance( null );
    }

    protected function tearDown(): void {
        \Wp_Houla_Pay_Refunds::set_instance( null );
        parent::tearDown();
    }

    private function item( $product_id, $variation_id, $qty, $total, $tax = 0.0, $meta = array() ) {
        return new class( $product_id, $variation_id, $qty, $total, $tax, $meta ) {
            private $p; private $v; private $q; private $t; private $tax; private $meta;
            public function __construct( $p, $v, $q, $t, $tax, $meta ) { $this->p = $p; $this->v = $v; $this->q = $q; $this->t = $t; $this->tax = $tax; $this->meta = $meta; }
            public function get_product_id() { return $this->p; }
            public function get_variation_id() { return $this->v; }
            public function get_quantity() { return $this->q; }
            public function get_total() { return $this->t; }
            public function get_total_tax() { return $this->tax; }
            public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
        };
    }

    private function order( $id = 10, array $meta = array( '_houla_order_id' => 'h-order-1' ), array $items = array() ) {
        $order = new class( $id, $meta, $items ) {
            public $meta; public $notes = array(); public $refunds = array(); private $id; private $items;
            public function get_refunds() { return $this->refunds; }
            public function __construct( $id, $meta, $items ) { $this->id = $id; $this->meta = $meta; $this->items = $items; }
            public function get_id() { return $this->id; }
            public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
            public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
            public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
            public function save_meta_data() {}
            public function add_order_note( $note ) { $this->notes[] = $note; }
            public function get_currency() { return 'EUR'; }
            public function get_item( $id ) { return isset( $this->items[ $id ] ) ? $this->items[ $id ] : false; }
        };
        $this->orders_by_id[ $id ] = $order;
        return $order;
    }

    private function refund( $id, $order_id, $amount, array $items = array() ) {
        $locks = $this->locks;
        return new class( $id, $order_id, $amount, $items, $locks ) {
            public $meta = array(); public $saved = 0; public $stamped_under_lock = null;
            private $id; private $order_id; private $amount; private $items; private $locks;
            public function __construct( $id, $order_id, $amount, $items, $locks ) { $this->id = $id; $this->order_id = $order_id; $this->amount = $amount; $this->items = $items; $this->locks = $locks; }
            public function get_id() { return $this->id; }
            public function get_parent_id() { return $this->order_id; }
            public function get_amount() { return $this->amount; }
            public function get_items( $type = 'line_item' ) { return $this->items; }
            public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
            public function update_meta_data( $key, $value ) {
                $this->meta[ $key ] = $value;
                if ( '_houla_refund_id' === $key ) { $this->stamped_under_lock = $this->locks->held; }
            }
            public function save() { $this->saved++; }
        };
    }

    private function service() {
        return new \Wp_Houla_Pay_Refunds( $this->api, $this->auth, $this->locks );
    }

    // =====================================================================

    public function test_can_refund_only_a_houla_order_while_connected() {
        $s = $this->service();
        $this->assertTrue( $s->can_refund( $this->order( 10 ) ) );
        $this->assertFalse( $s->can_refund( $this->order( 11, array() ) ) );
        $this->auth->connected = false;
        $this->assertFalse( $s->can_refund( $this->order( 12 ) ) );
    }

    public function test_success_sends_the_woocommerce_refund_and_stamps_it_under_the_lock() {
        $order  = $this->order( 10, array( '_houla_order_id' => 'h-order-1' ), array(
            7 => $this->item( 77, 83, 2, 20.0 ),
        ) );
        $refund = $this->refund( 555, 10, 12.5, array(
            $this->item( 77, 83, -1, -10.0, -2.5, array( '_refunded_item_id' => 7 ) ),
        ) );
        \Wp_Houla_Pay_Refunds::capture_refund( $refund, array( 'refund_payment' => true, 'order_id' => 10 ) );

        $result = $this->service()->process( 10, 12.5, ' Taille ' );

        $this->assertTrue( $result );
        $this->assertCount( 1, $this->api->calls );
        list( $endpoint, $body ) = $this->api->calls[0];
        $this->assertSame( '/ecommerce/orders/h-order-1/refund', $endpoint );
        $this->assertSame( 555, $body['wc_refund_id'] );
        $this->assertSame( 1250, $body['amount_cents'] );
        $this->assertSame( 'EUR', $body['currency'] );
        $this->assertSame( 'Taille', $body['reason'] );
        $this->assertSame( array( array( 'external_id' => '77', 'external_variant_id' => '83', 'quantity' => 1, 'amount_cents' => 1250 ) ), $body['items'] );
        $this->assertTrue( $this->api->locked_during_call, 'Hou.la est appelé sous le verrou de la commande' );
        $this->assertSame( 'dem-1', $refund->meta['_houla_refund_id'] );
        $this->assertTrue( $refund->stamped_under_lock, 'la marque est posée avant de rendre le verrou' );
        $this->assertSame( 1, $refund->saved );
        $this->assertCount( 1, $order->notes );
        $this->assertStringContainsString( 'Hou.la Pay', $order->notes[0] );
        $this->assertStringContainsString( 're_123', $order->notes[0] );
    }

    public function test_no_captured_refund_nothing_is_sent() {
        $this->order( 10 );
        $result = $this->service()->process( 10, 5.0 );
        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertCount( 0, $this->api->calls );
    }

    public function test_refund_not_meant_for_the_gateway_is_not_captured() {
        $this->order( 10 );
        \Wp_Houla_Pay_Refunds::capture_refund( $this->refund( 556, 10, 5.0 ), array( 'refund_payment' => false ) );
        $this->assertInstanceOf( \WP_Error::class, $this->service()->process( 10, 5.0 ) );
        $this->assertCount( 0, $this->api->calls );
        // Contre-témoin : le même remboursement, à payer par la passerelle, part.
        \Wp_Houla_Pay_Refunds::capture_refund( $this->refund( 556, 10, 5.0 ), array( 'refund_payment' => true ) );
        $this->assertTrue( $this->service()->process( 10, 5.0 ) );
        $this->assertCount( 1, $this->api->calls );
    }

    public function test_amount_different_from_the_captured_refund_is_refused() {
        $this->order( 10 );
        \Wp_Houla_Pay_Refunds::capture_refund( $this->refund( 557, 10, 5.0 ), array( 'refund_payment' => true ) );
        $this->assertInstanceOf( \WP_Error::class, $this->service()->process( 10, 6.0 ) );
        $this->assertCount( 0, $this->api->calls );
    }

    public function test_refusal_shows_the_houla_reason_and_notes_nothing() {
        $order  = $this->order( 10 );
        $refund = $this->refund( 558, 10, 50.0 );
        \Wp_Houla_Pay_Refunds::capture_refund( $refund, array( 'refund_payment' => true ) );
        $this->api->response = new \WP_Error( 'wphoula_api_error', 'Invalid refund amount', array( 'status' => 400, 'code' => 'SHOP_REFUND_AMOUNT_INVALID', 'body' => array() ) );

        $result = $this->service()->process( 10, 50.0 );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertStringContainsString( 'dépasse ce qui reste à rembourser', $result->get_error_message() );
        $this->assertStringContainsString( 'Rien n\'a été remboursé', $result->get_error_message() );
        $this->assertSame( '', $refund->get_meta( '_houla_refund_id' ) );
        $this->assertCount( 0, $order->notes );
        $this->assertSame( '', $order->get_meta( '_houla_pay_uncertain' ) );
    }

    public function test_no_answer_blocks_a_second_refund_until_confirmed_or_expired() {
        $order = $this->order( 10 );
        \Wp_Houla_Pay_Refunds::capture_refund( $this->refund( 559, 10, 20.0 ), array( 'refund_payment' => true ) );
        $this->api->response = new \WP_Error( 'http_request_failed', 'cURL error 28: Operation timed out' );

        $first = $this->service()->process( 10, 20.0 );
        $this->assertStringContainsString( 'peut-être été fait', $first->get_error_message() );
        $this->assertSame( 2000, $order->meta['_houla_pay_uncertain']['amount_cents'] );

        // La vendeuse recommence aussitôt : refusé SANS appeler Hou.la.
        \Wp_Houla_Pay_Refunds::capture_refund( $this->refund( 560, 10, 20.0 ), array( 'refund_payment' => true ) );
        $second = $this->service()->process( 10, 20.0 );
        $this->assertStringContainsString( 'pas encore été confirmé', $second->get_error_message() );
        $this->assertCount( 1, $this->api->calls );

        // Contre-témoin : 15 minutes plus tard, le remboursement repart.
        $order->meta['_houla_pay_uncertain']['at'] = time() - 901;
        $this->api->response = array( 'success' => true, 'refund_request_id' => 'dem-2' );
        \Wp_Houla_Pay_Refunds::capture_refund( $this->refund( 561, 10, 20.0 ), array( 'refund_payment' => true ) );
        $this->assertTrue( $this->service()->process( 10, 20.0 ) );
        $this->assertCount( 2, $this->api->calls );
    }

    public function test_pending_verification_at_stripe_is_uncertain_too() {
        $order = $this->order( 10 );
        \Wp_Houla_Pay_Refunds::capture_refund( $this->refund( 562, 10, 8.0 ), array( 'refund_payment' => true ) );
        $this->api->response = new \WP_Error( 'wphoula_api_error', 'pending', array( 'status' => 503, 'code' => 'SHOP_REFUND_PENDING_VERIFICATION', 'body' => array() ) );
        $result = $this->service()->process( 10, 8.0 );
        $this->assertStringContainsString( 'Ne le refaites pas', $result->get_error_message() );
        $this->assertIsArray( $order->get_meta( '_houla_pay_uncertain' ) );
    }

    public function test_busy_order_sends_nothing() {
        $this->order( 10 );
        \Wp_Houla_Pay_Refunds::capture_refund( $this->refund( 563, 10, 8.0 ), array( 'refund_payment' => true ) );
        $this->locks->busy = true;
        $result = $this->service()->process( 10, 8.0 );
        $this->assertStringContainsString( 'Réessayez dans quelques secondes', $result->get_error_message() );
        $this->assertCount( 0, $this->api->calls );
    }

    public function test_disconnected_store_sends_nothing() {
        $this->order( 10 );
        \Wp_Houla_Pay_Refunds::capture_refund( $this->refund( 564, 10, 8.0 ), array( 'refund_payment' => true ) );
        $this->auth->connected = false;
        $this->assertInstanceOf( \WP_Error::class, $this->service()->process( 10, 8.0 ) );
        $this->assertCount( 0, $this->api->calls );
    }

    public function test_sends_what_woocommerce_already_refunded_without_the_current_refund() {
        $order  = $this->order( 10 );
        $before = $this->refund( 400, 10, 7.0 );
        $refund = $this->refund( 565, 10, 5.0 );
        $order->refunds = array( $refund, $before );
        \Wp_Houla_Pay_Refunds::capture_refund( $refund, array( 'refund_payment' => true ) );
        $this->assertTrue( $this->service()->process( 10, 5.0 ) );
        $this->assertSame( 700, $this->api->calls[0][1]['wc_known_refunded_cents'] );
    }

    public function test_refusal_after_a_part_was_refunded_never_says_nothing_was_refunded() {
        $order = $this->order( 10 );
        \Wp_Houla_Pay_Refunds::capture_refund( $this->refund( 566, 10, 30.0 ), array( 'refund_payment' => true ) );
        $this->api->response = new \WP_Error( 'wphoula_api_error', 'Stripe refused', array(
            'status' => 400, 'code' => 'SHOP_REFUND_REFUSED_BY_PROVIDER', 'body' => array( 'executedCents' => 2000, 'refusedCents' => 1000 ),
        ) );
        $result = $this->service()->process( 10, 30.0 );
        $this->assertStringContainsString( '20,00 €', $result->get_error_message() );
        $this->assertStringNotContainsString( 'Rien n\'a été remboursé', $result->get_error_message() );
        $this->assertIsArray( $order->get_meta( '_houla_pay_uncertain' ), 'un nouvel essai immédiat est bloqué' );
        // Contre-témoin : le même refus sans part rendue dit bien « rien n'a été remboursé », sans blocage.
        $order2 = $this->order( 11 );
        \Wp_Houla_Pay_Refunds::capture_refund( $this->refund( 567, 11, 30.0 ), array( 'refund_payment' => true ) );
        $this->api->response = new \WP_Error( 'wphoula_api_error', 'Stripe refused', array( 'status' => 400, 'code' => 'SHOP_REFUND_REFUSED_BY_PROVIDER', 'body' => array() ) );
        $result2 = $this->service()->process( 11, 30.0 );
        $this->assertStringContainsString( 'Rien n\'a été remboursé', $result2->get_error_message() );
        $this->assertSame( '', $order2->get_meta( '_houla_pay_uncertain' ) );
    }

    public function test_unconfirmed_on_store_explains_what_houla_already_refunded() {
        $this->order( 10 );
        \Wp_Houla_Pay_Refunds::capture_refund( $this->refund( 568, 10, 10.0 ), array( 'refund_payment' => true ) );
        $this->api->response = new \WP_Error( 'wphoula_api_error', 'unconfirmed', array(
            'status' => 409, 'code' => 'SHOP_REFUND_UNCONFIRMED_ON_STORE', 'body' => array( 'houlaRefundedCents' => 1500 ),
        ) );
        $result = $this->service()->process( 10, 10.0 );
        $this->assertStringContainsString( '15,00 €', $result->get_error_message() );
        $this->assertStringContainsString( 'pas encore arrivé ici', $result->get_error_message() );
    }

    public function test_gateway_is_not_registered_without_woocommerce() {
        $this->assertSame( array( 'X' ), \Wp_Houla_Pay_Refunds::register_gateway( array( 'X' ) ) );
    }
}
