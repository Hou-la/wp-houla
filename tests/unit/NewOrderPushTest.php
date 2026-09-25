<?php
/**
 * Unit tests for Wp_Houla_New_Order_Push.
 *
 * Cas réel : WC #42762 (giamory.com), vendue le 16/08, annoncée « You have a new
 * order! » le 25/09 au passage « En cours de livraison (Hou.la) → Terminée ».
 */

namespace Automattic\WooCommerce\Internal\PushNotifications\Services {
    if ( ! class_exists( NotificationProcessor::class ) ) {
        // Double de la classe interne de WooCommerce >= 10.7 : seule la
        // constante nous intéresse.
        class NotificationProcessor {
            const SENT_META_KEY = '_wc_push_notification_sent';
        }
    }
}

namespace WpHoula\Tests\Unit {

    use WpHoula\Tests\TestCase;

    require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-new-order-push.php';

    class NewOrderPushTest extends TestCase {

        const SENT = '_wc_push_notification_sent';

        private function makeOrder( array $meta = array() ): object {
            return new class( $meta ) {
                public $meta;
                public $saved = 0;

                public function __construct( $meta ) { $this->meta = $meta; }
                public function meta_exists( $key ) { return array_key_exists( $key, $this->meta ); }
                public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
                public function save_meta_data() { $this->saved++; }
            };
        }

        public function test_delivery_of_a_shipped_order_is_marked_as_already_announced() {
            $order = $this->makeOrder();

            ( new \Wp_Houla_New_Order_Push() )->on_order_status_changed( 42762, 'houla-shipping', 'completed', $order );

            $this->assertArrayHasKey( self::SENT, $order->meta );
            $this->assertStringStartsWith( 'houla-shipping -> completed @ ', $order->meta['_houla_new_order_push_suppressed'] );
            $this->assertSame( 1, $order->saved );
        }

        public function test_leaving_a_return_is_marked_as_already_announced() {
            $order = $this->makeOrder();

            ( new \Wp_Houla_New_Order_Push() )->on_order_status_changed( 1, 'wc-houla-returned', 'wc-processing', $order );

            $this->assertArrayHasKey( self::SENT, $order->meta );
        }

        public function test_entering_shipping_marks_the_order_for_its_future_delivery() {
            $order = $this->makeOrder();

            ( new \Wp_Houla_New_Order_Push() )->on_order_status_changed( 1, 'processing', 'houla-shipping', $order );

            $this->assertArrayHasKey( self::SENT, $order->meta );
        }

        /**
         * Contre-témoin : la clôture d'un panier ouvert est le seul moment où une
         * commande de live sonne. Elle doit rester intacte.
         */
        public function test_open_cart_close_is_left_to_woocommerce() {
            $order = $this->makeOrder();

            ( new \Wp_Houla_New_Order_Push() )->on_order_status_changed( 1, 'open-cart', 'processing', $order );

            $this->assertSame( array(), $order->meta );
            $this->assertSame( 0, $order->saved );
        }

        public function test_ordinary_transitions_are_left_to_woocommerce() {
            $order = $this->makeOrder();

            ( new \Wp_Houla_New_Order_Push() )->on_order_status_changed( 1, 'pending', 'processing', $order );
            ( new \Wp_Houla_New_Order_Push() )->on_order_status_changed( 1, 'processing', 'completed', $order );

            $this->assertSame( array(), $order->meta );
        }

        public function test_an_existing_sent_marker_is_not_overwritten() {
            $order = $this->makeOrder( array( self::SENT => '1790334644' ) );

            ( new \Wp_Houla_New_Order_Push() )->on_order_status_changed( 1, 'houla-shipping', 'completed', $order );

            $this->assertSame( array( self::SENT => '1790334644' ), $order->meta );
            $this->assertSame( 0, $order->saved );
        }

        public function test_woocommerce_without_local_push_notifications_is_left_alone() {
            $order = $this->makeOrder();

            ( new \Wp_Houla_New_Order_Push( 'Does\\Not\\Exist' ) )->on_order_status_changed( 1, 'houla-shipping', 'completed', $order );

            $this->assertSame( array(), $order->meta );
        }
    }
}
