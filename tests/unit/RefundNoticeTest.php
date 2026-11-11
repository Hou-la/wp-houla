<?php
/**
 * Unit tests for Wp_Houla_Refund_Notice (Trello #157).
 *
 * Un remboursement saisi dans WooCommerce sur une commande Hou.la ne rend
 * aucun argent : la vendeuse est prévenue (note, encart avec le lien vers le
 * studio), Hou.la le garde dans l'historique sans toucher à l'argent.
 */

namespace WpHoula\Tests\Unit;

use WpHoula\Tests\TestCase;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-refund-notice.php';

class RefundNoticeTest extends TestCase {

    /** @var array<int, object> */
    private $orders = array();

    /** @var object */
    private $api;

    /** @var object */
    private $auth;

    protected function setUp(): void {
        parent::setUp();
        \Wp_Houla_Refund_Notice::$applying_houla_refund = false;
        $this->orders = array();
        $this->api    = new class() {
            public $calls    = array();
            public $response = array();
            public function post( $endpoint, array $body = array(), array $overrides = array() ) {
                $this->calls[] = array( $endpoint, $body );
                return $this->response;
            }
        };
        $this->auth = new class() {
            public function is_connected() { return true; }
        };
        $orders = &$this->orders;
        Functions\when( 'wc_get_order' )->alias( function ( $id ) use ( &$orders ) {
            return isset( $orders[ $id ] ) ? $orders[ $id ] : false;
        } );
        Functions\when( 'is_wp_error' )->justReturn( false );
        Functions\when( 'current_time' )->justReturn( '2026-11-11 10:00:00' );
    }

    protected function tearDown(): void {
        \Wp_Houla_Refund_Notice::$applying_houla_refund = false;
        parent::tearDown();
    }

    private function order( array $meta = array(), array $refunds = array(), $total_refunded = 0.0 ) {
        return new class( $meta, $refunds, $total_refunded ) {
            public $meta;
            public $notes = array();
            public $saved = 0;
            private $refunds;
            private $total_refunded;
            public function __construct( $meta, $refunds, $total_refunded ) {
                $this->meta           = $meta;
                $this->refunds        = $refunds;
                $this->total_refunded = $total_refunded;
            }
            public function get_meta( $key ) { return isset( $this->meta[ $key ] ) ? $this->meta[ $key ] : ''; }
            public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
            public function add_order_note( $note ) { $this->notes[] = $note; }
            public function save() { $this->saved++; }
            public function get_total_refunded() { return $this->total_refunded; }
            public function get_currency() { return 'EUR'; }
            public function get_refunds() { return $this->refunds; }
            public function get_amount() { return isset( $this->meta['_amount'] ) ? $this->meta['_amount'] : 0; }
        };
    }

    private function notice() {
        return new \Wp_Houla_Refund_Notice( $this->api, $this->auth );
    }

    private function houlaOrderWithManualRefund() {
        $refund             = $this->order( array( '_amount' => 5.9 ) );
        $order              = $this->order( array( '_houla_order_id' => 'abc-123' ), array( $refund ), 5.9 );
        $this->orders[ 10 ] = $order;
        $this->orders[ 77 ] = $refund;
        return $order;
    }

    public function test_manual_refund_is_declared_to_houla_and_warns_the_seller_with_the_studio_link() {
        Functions\when( 'current_user_can' )->justReturn( true );
        $order               = $this->houlaOrderWithManualRefund();
        $this->api->response = array( 'refund' => array(
            'money_moved'      => false,
            'studio_order_url' => 'https://hou.la/studio/shop/orders/abc-123',
        ) );

        $this->notice()->on_order_refunded( 10, 77 );

        $this->assertSame( '/ecommerce/orders/abc-123/refund-declaration', $this->api->calls[0][0] );
        $this->assertSame( array( 'wc_refund_id' => 77, 'amount_cents' => 590, 'wc_total_refunded_cents' => 590 ), $this->api->calls[0][1] );
        $this->assertCount( 1, $order->meta['_houla_refund_warnings'] );
        $this->assertSame( 77, $order->meta['_houla_refund_warnings'][0]['wc_refund_id'] );
        $this->assertStringContainsString( 'AUCUN argent', $order->notes[0] );
        $this->assertStringContainsString( 'https://hou.la/studio/shop/orders/abc-123', $order->notes[0] );
        $this->assertStringContainsString( '5,90 €', $order->notes[0] );
    }

    public function test_the_same_refund_is_signalled_only_once() {
        Functions\when( 'current_user_can' )->justReturn( true );
        $order               = $this->houlaOrderWithManualRefund();
        $this->api->response = array( 'refund' => array( 'money_moved' => false ) );

        $this->notice()->on_order_refunded( 10, 77 );
        $this->notice()->on_order_refunded( 10, 77 );

        $this->assertCount( 1, $order->meta['_houla_refund_warnings'] );
        $this->assertCount( 1, $order->notes );
    }

    public function test_a_refund_sent_by_houla_is_neither_declared_nor_signalled() {
        Functions\when( 'current_user_can' )->justReturn( true );
        $order = $this->houlaOrderWithManualRefund();
        \Wp_Houla_Refund_Notice::$applying_houla_refund = true;

        $this->notice()->on_order_refunded( 10, 77 );

        $this->assertSame( array(), $this->api->calls );
        $this->assertSame( array(), $order->notes );
    }

    public function test_a_refund_carrying_a_houla_refund_id_is_ignored() {
        Functions\when( 'current_user_can' )->justReturn( true );
        $order                                            = $this->houlaOrderWithManualRefund();
        $this->orders[ 77 ]->meta['_houla_refund_id'] = 'req-1';

        $this->notice()->on_order_refunded( 10, 77 );

        $this->assertSame( array(), $this->api->calls );
        $this->assertSame( array(), $order->notes );
    }

    public function test_an_order_that_does_not_come_from_houla_is_ignored() {
        Functions\when( 'current_user_can' )->justReturn( true );
        $this->orders[ 10 ] = $this->order();

        $this->notice()->on_order_refunded( 10, 77 );

        $this->assertSame( array(), $this->api->calls );
    }

    public function test_an_account_that_cannot_manage_orders_does_not_declare_but_the_seller_is_still_warned() {
        Functions\when( 'current_user_can' )->justReturn( false );
        $order = $this->houlaOrderWithManualRefund();

        $this->notice()->on_order_refunded( 10, 77 );

        $this->assertSame( array(), $this->api->calls );
        $this->assertStringContainsString( 'sans droit de gestion des commandes', $order->notes[0] );
        $this->assertStringContainsString( 'https://hou.la/studio/shop/orders/abc-123', $order->notes[1] );
    }

    public function test_money_already_returned_by_houla_is_not_a_warning() {
        Functions\when( 'current_user_can' )->justReturn( true );
        $order               = $this->houlaOrderWithManualRefund();
        $this->api->response = array( 'refund' => array( 'money_moved' => true, 'houla_refunded_cents' => 590 ) );

        $this->notice()->on_order_refunded( 10, 77 );

        $this->assertArrayNotHasKey( '_houla_refund_warnings', $order->meta );
        $this->assertStringContainsString( 'Hou.la a déjà rendu 5,90 €', $order->notes[0] );
    }

    public function test_refunded_status_without_any_refund_record_warns() {
        $order = $this->order( array( '_houla_order_id' => 'abc-123' ) );

        $this->notice()->on_refunded_status_without_money( $order, array( 'money_moved' => false ) );

        $this->assertCount( 1, $order->meta['_houla_refund_warnings'] );
        $this->assertStringContainsString( 'passée à « Remboursée »', $order->notes[0] );
    }

    public function test_refunded_status_with_a_manual_refund_is_left_to_the_refund_hook() {
        $order = $this->houlaOrderWithManualRefund();

        $this->notice()->on_refunded_status_without_money( $order, array( 'money_moved' => false ) );

        $this->assertSame( array(), $order->notes );
    }

    public function test_refunded_status_with_money_moved_says_nothing() {
        $order = $this->order( array( '_houla_order_id' => 'abc-123' ) );

        $this->notice()->on_refunded_status_without_money( $order, array( 'money_moved' => true ) );

        $this->assertSame( array(), $order->notes );
    }

    public function test_the_notice_shows_the_studio_button_to_order_managers_only() {
        $order = $this->order( array( '_houla_order_id' => 'abc-123' ) );

        Functions\when( 'current_user_can' )->justReturn( false );
        $this->assertSame( '', $this->notice()->render( $order ) );

        Functions\when( 'current_user_can' )->justReturn( true );
        $html = $this->notice()->render( $order );
        $this->assertStringContainsString( 'Rembourser dans le studio Hou.la', $html );
        $this->assertStringContainsString( 'href="https://hou.la/studio/shop/orders/abc-123"', $html );
        $this->assertStringNotContainsString( 'Remboursement sans argent rendu', $html );
    }

    public function test_the_notice_lists_the_refunds_that_returned_no_money() {
        Functions\when( 'current_user_can' )->justReturn( true );
        $order = $this->order( array(
            '_houla_order_id'        => 'abc-123',
            '_houla_refund_warnings' => array( array( 'wc_refund_id' => 77, 'amount' => 5.9, 'at' => '2026-11-11 10:00:00' ) ),
        ) );

        $html = $this->notice()->render( $order );

        $this->assertStringContainsString( 'Remboursement sans argent rendu', $html );
        $this->assertStringContainsString( '5,90 €', $html );
    }

    public function test_no_notice_on_an_order_that_does_not_come_from_houla() {
        Functions\when( 'current_user_can' )->justReturn( true );
        $this->assertSame( '', $this->notice()->render( $this->order() ) );
    }

    public function test_a_studio_url_outside_hou_la_is_never_used() {
        $order = $this->order( array( '_houla_order_id' => 'abc-123' ) );

        $this->assertSame(
            'https://hou.la/studio/shop/orders/abc-123',
            $this->notice()->studio_url( $order, array( 'studio_order_url' => 'https://evil.example/studio' ) )
        );
        $this->assertSame(
            'https://dev.hou.la/studio/shop/orders/abc-123',
            $this->notice()->studio_url( $order, array( 'studio_order_url' => 'https://dev.hou.la/studio/shop/orders/abc-123' ) )
        );
        $this->assertSame(
            'https://hou.la/studio/shop/orders/abc-123',
            $this->notice()->studio_url( $order, array( 'studio_order_url' => 'http://hou.la/studio/shop/orders/abc-123' ) )
        );
    }
}
