<?php
/**
 * Integration test: full webhook flow (order.paid -> WooCommerce order creation).
 *
 * These tests mock WooCommerce functions to simulate the complete pipeline
 * without requiring a live WordPress/WooCommerce installation.
 */

namespace WpHoula\Tests\Integration;

use WpHoula\Tests\TestCase;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-options.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-orders.php';

/**
 * Minimal mock for WC_Order.
 */
class MockWcOrder {
    private $id = 100;
    private $meta = array();
    private $items = array();
    private $status = '';
    private $payment_method = '';
    private $payment_method_title = '';
    private $transaction_id = '';
    private $currency = 'EUR';
    private $billing = array();
    private $shipping = array();

    public function get_id() { return $this->id; }
    public function get_total() { return 59.99; }

    public function add_product( $product, $qty, $args = array() ) {
        $this->items[] = array( 'product' => $product, 'qty' => $qty, 'args' => $args );
    }

    public function add_item( $item ) { $this->items[] = $item; }
    public function set_address( $data, $type ) { $this->{$type} = $data; }
    public function set_payment_method( $method ) { $this->payment_method = $method; }
    public function set_payment_method_title( $title ) { $this->payment_method_title = $title; }
    public function set_currency( $c ) { $this->currency = $c; }
    public function set_transaction_id( $id ) { $this->transaction_id = $id; }
    public function set_date_paid( $date ) {}
    public function set_status( $status, $note = '' ) { $this->status = $status; }
    public function update_meta_data( $key, $value ) { $this->meta[ $key ] = $value; }
    public function calculate_totals() {}
    public function set_total( $total ) {}
    public function save() {}
    /** @var string[] Notes CLIENT, c'est-à-dire les e-mails envoyés à l'acheteuse. */
    public $customer_notes = array();
    public function add_order_note( $note, $is_customer_note = 0, $added_by_user = false ) {
        if ( $is_customer_note ) {
            $this->customer_notes[] = $note;
        }
    }
    // Le nom de facturation est réellement porté par le mock : c'est lui que
    // WooCommerce affiche comme titre de commande, et le préfixe pseudo ne doit
    // surtout pas déborder sur l'adresse d'expédition (étiquette transporteur).
    public function set_billing_first_name( $name ) { $this->billing['first_name'] = $name; }
    public function get_billing_first_name() { return $this->billing['first_name'] ?? ''; }
    public function get_billing_last_name() { return $this->billing['last_name'] ?? ''; }
    public function get_address( $type = 'billing' ) { return $this->{$type}; }
    public function remove_item( $item_id ) { unset( $this->items[ $item_id ] ); }
    public function delete_meta_data( $key ) { unset( $this->meta[ $key ] ); }
    public function save_meta_data() {}

    public function get_meta( $key = '', $single = true ) {
        if ( '' === $key ) { return $this->meta; }
        return $this->meta[ $key ] ?? '';
    }
    public function get_status() { return $this->status; }
    public function get_items() { return $this->items; }
    public function get_payment_method() { return $this->payment_method; }
}

/**
 * Minimal mock for WC_Product.
 */
class MockWcProduct {
    private $id;
    private $manage_stock;

    public function __construct( $id, $manage_stock = false ) {
        $this->id = $id;
        $this->manage_stock = $manage_stock;
    }

    public function get_id() { return $this->id; }
    public function get_manage_stock() { return $this->manage_stock; }
}

class OrderFlowTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        Functions\when( 'get_option' )->justReturn( array() );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, $args );
        } );
        Functions\when( 'apply_filters' )->returnArg( 2 );
        Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        } );
        Functions\when( 'current_time' )->justReturn( '2026-02-22 14:30:00' );
        // create_order() validates the target status against the registered
        // WooCommerce order statuses (array_keys( wc_get_order_statuses() )).
        Functions\when( 'wc_get_order_statuses' )->justReturn( array(
            'wc-pending'    => 'Pending payment',
            'wc-processing' => 'Processing',
            'wc-on-hold'    => 'On hold',
            'wc-completed'  => 'Completed',
            'wc-cancelled'  => 'Cancelled',
            'wc-refunded'   => 'Refunded',
            'wc-failed'     => 'Failed',
            'wc-open-cart'  => 'Open cart',
        ) );
    }

    public function test_create_order_full_flow(): void {
        $mockOrder   = new MockWcOrder();
        $mockProduct = new MockWcProduct( 42 );

        Functions\when( 'wc_create_order' )->justReturn( $mockOrder );
        Functions\when( 'wc_get_product' )->justReturn( $mockProduct );
        Functions\when( 'wc_get_orders' )->justReturn( array() );
        Functions\when( 'wc_update_product_stock' )->justReturn( true );

        $orders = new \Wp_Houla_Orders();
        $result = $orders->create_order( array(
            'houla_order_id' => 'houla-order-001',
            'transaction_id' => 'pi_stripe_123',
            'currency'       => 'EUR',
            'paid_at'        => '2026-02-22T14:30:00Z',
            'items'          => array(
                array(
                    'external_id' => '42',
                    'quantity'    => 2,
                    'price'       => 29.99,
                ),
            ),
            'customer'  => array(
                'first_name' => 'Jean',
                'last_name'  => 'Dupont',
                'email'      => 'jean@example.com',
                'phone'      => '+33600000000',
                'address'    => array(
                    'line1'    => '10 rue de la Paix',
                    'line2'    => 'Bat A',
                    'city'     => 'Paris',
                    'state'    => 'IDF',
                    'postcode' => '75002',
                    'country'  => 'FR',
                ),
            ),
        ) );

        $this->assertIsArray( $result );
        $this->assertArrayHasKey( 'order_id', $result );
        $this->assertEquals( 100, $result['order_id'] );

        // Verify order meta was set.
        $meta = $mockOrder->get_meta();
        $this->assertEquals( 'houla-order-001', $meta['_houla_order_id'] );
        $this->assertEquals( 'pi_stripe_123', $meta['_houla_transaction_id'] );

        // Verify payment method.
        $this->assertEquals( 'houla_pay', $mockOrder->get_payment_method() );
        $this->assertEquals( 'processing', $mockOrder->get_status() );
    }

    /**
     * Pendant un live, la vendeuse identifie sa cliente par son PSEUDO ; le
     * transporteur, lui, exige l'identité civile. Les deux coexistent donc :
     * pseudo entre parenthèses sur la FACTURATION (= titre de la commande),
     * identité civile seule sur l'EXPÉDITION (= étiquette).
     */
    public function test_create_order_prefixes_billing_name_with_pseudo_but_not_shipping(): void {
        $mockOrder = new MockWcOrder();

        Functions\when( 'wc_create_order' )->justReturn( $mockOrder );
        Functions\when( 'wc_get_product' )->justReturn( false );
        Functions\when( 'wc_get_orders' )->justReturn( array() );

        $orders = new \Wp_Houla_Orders();
        $orders->create_order( array(
            'houla_order_id' => 'houla-order-pseudo',
            'items'          => array( array( 'external_id' => '1', 'quantity' => 1, 'price' => 10.0 ) ),
            'customer'       => array(
                'first_name'   => 'haddaoui',
                'last_name'    => '',
                'display_name' => 'Choupot',
                'address'      => array( 'line1' => '77 allee Georges askinazi', 'city' => 'Boulogne' ),
            ),
        ) );

        $this->assertEquals( '(Choupot) haddaoui', $mockOrder->get_billing_first_name() );

        $shipping = $mockOrder->get_address( 'shipping' );
        $this->assertEquals( 'haddaoui', $shipping['first_name'] );
        $this->assertStringNotContainsString( 'Choupot', $shipping['first_name'] );
    }

    /**
     * update_order() rejoue apply_buyer_identity() à chaque resynchronisation :
     * sans dé-préfixage, on empilerait « (Choupot) (Choupot) haddaoui ».
     */
    public function test_resync_does_not_stack_the_pseudo_prefix(): void {
        $mockOrder = new MockWcOrder();

        Functions\when( 'wc_get_order' )->justReturn( $mockOrder );
        Functions\when( 'wc_get_product' )->justReturn( false );
        Functions\when( 'wc_get_orders' )->justReturn( array( 100 ) );

        $orders  = new \Wp_Houla_Orders();
        $payload = array(
            'houla_order_id' => 'houla-order-pseudo',
            'items'          => array( array( 'external_id' => '1', 'quantity' => 1, 'price' => 10.0 ) ),
            'customer'       => array(
                'first_name'   => 'haddaoui',
                'display_name' => 'Choupot',
            ),
        );

        $orders->update_order( $payload );
        $orders->update_order( $payload );
        $orders->update_order( $payload );

        $this->assertEquals( '(Choupot) haddaoui', $mockOrder->get_billing_first_name() );
    }

    /**
     * Achat en live sans aucune identité civile : le pseudo tient lieu de
     * client, mais sans parenthèses — il n'y a rien à distinguer.
     */
    public function test_pseudo_alone_when_no_civil_name(): void {
        $mockOrder = new MockWcOrder();

        Functions\when( 'wc_create_order' )->justReturn( $mockOrder );
        Functions\when( 'wc_get_product' )->justReturn( false );
        Functions\when( 'wc_get_orders' )->justReturn( array() );

        $orders = new \Wp_Houla_Orders();
        $orders->create_order( array(
            'houla_order_id' => 'houla-order-anon',
            'items'          => array( array( 'external_id' => '1', 'quantity' => 1, 'price' => 10.0 ) ),
            'customer'       => array( 'display_name' => 'Choupot' ),
        ) );

        $this->assertEquals( 'Choupot', $mockOrder->get_billing_first_name() );
    }

    public function test_create_order_rejects_missing_order_id(): void {
        $orders = new \Wp_Houla_Orders();
        $result = $orders->create_order( array(
            'items' => array( array( 'external_id' => '1', 'quantity' => 1, 'price' => 10 ) ),
        ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'wphoula_invalid_order', $result->get_error_code() );
    }

    public function test_create_order_rejects_missing_items(): void {
        $orders = new \Wp_Houla_Orders();
        $result = $orders->create_order( array(
            'houla_order_id' => 'houla-order-002',
        ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
    }

    public function test_create_order_detects_duplicate(): void {
        // A duplicate triggers the upsert path: create_order() -> update_order(),
        // which loads the existing order via wc_get_order() and re-adds items.
        Functions\when( 'wc_get_orders' )->justReturn( array( 50 ) );
        Functions\when( 'wc_get_order' )->justReturn( new MockWcOrder() );
        Functions\when( 'wc_get_product' )->justReturn( new MockWcProduct( 1 ) );

        $orders = new \Wp_Houla_Orders();
        $result = $orders->create_order( array(
            'houla_order_id' => 'houla-order-duplicate',
            'items'          => array( array( 'external_id' => '1', 'quantity' => 1, 'price' => 10 ) ),
        ) );

        $this->assertIsArray( $result );
        $this->assertEquals( 50, $result['order_id'] );
    }

    public function test_create_order_skips_unknown_product(): void {
        $mockOrder = new MockWcOrder();

        Functions\when( 'wc_create_order' )->justReturn( $mockOrder );
        Functions\when( 'wc_get_product' )->justReturn( null ); // Product not found.
        Functions\when( 'wc_get_orders' )->justReturn( array() );

        $orders = new \Wp_Houla_Orders();
        $result = $orders->create_order( array(
            'houla_order_id' => 'houla-order-003',
            'items'          => array(
                array( 'external_id' => '999', 'quantity' => 1, 'price' => 10 ),
            ),
        ) );

        $this->assertIsArray( $result );
        // Product not found → added as a custom WC_Order_Item_Fee fallback.
        $items = $mockOrder->get_items();
        $this->assertCount( 1, $items );
        $this->assertInstanceOf( \WC_Order_Item_Fee::class, $items[0] );
        $this->assertEquals( 10, $items[0]->get_total() );
    }

    public function test_create_order_with_shipping(): void {
        $mockOrder   = new MockWcOrder();
        $mockProduct = new MockWcProduct( 1 );
        $shippingAdded = false;

        Functions\when( 'wc_create_order' )->justReturn( $mockOrder );
        Functions\when( 'wc_get_product' )->justReturn( $mockProduct );
        Functions\when( 'wc_get_orders' )->justReturn( array() );

        $orders = new \Wp_Houla_Orders();
        $result = $orders->create_order( array(
            'houla_order_id' => 'houla-order-004',
            'items'          => array(
                array( 'external_id' => '1', 'quantity' => 1, 'price' => 25 ),
            ),
            'shipping'  => array(
                'label'  => 'Colissimo',
                'method' => 'flat_rate',
                'amount' => 5.90,
            ),
            'customer' => array( 'first_name' => 'Test', 'last_name' => 'User', 'email' => 'test@test.com' ),
        ) );

        $this->assertIsArray( $result );
        // Should have product item + shipping item.
        $items = $mockOrder->get_items();
        $this->assertGreaterThanOrEqual( 1, count( $items ) );
    }

    public function test_refund_order_rejects_missing_id(): void {
        $orders = new \Wp_Houla_Orders();
        $result = $orders->refund_order( array() );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'wphoula_invalid_refund', $result->get_error_code() );
    }

    public function test_refund_order_returns_error_for_not_found(): void {
        Functions\when( 'wc_get_orders' )->justReturn( array() );

        $orders = new \Wp_Houla_Orders();
        $result = $orders->refund_order( array( 'houla_order_id' => 'nonexistent' ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertEquals( 'wphoula_order_not_found', $result->get_error_code() );
    }

    public function test_refund_order_success(): void {
        $mockOrder  = new MockWcOrder();
        $mockRefund = (object) array( 'id' => 10 );

        Functions\when( 'wc_get_orders' )->justReturn( array( 100 ) );
        Functions\when( 'wc_get_order' )->justReturn( $mockOrder );
        Functions\when( 'wc_create_refund' )->justReturn( $mockRefund );

        $orders = new \Wp_Houla_Orders();
        $result = $orders->refund_order( array(
            'houla_order_id' => 'houla-order-001',
            'reason'         => 'Customer request',
        ) );

        $this->assertIsArray( $result );
        $this->assertEquals( 100, $result['order_id'] );
        $this->assertEquals( 'refunded', $mockOrder->get_status() );
    }

    // =====================================================================
    // E-mail « en cours de livraison » : une fois par envoi
    // =====================================================================

    private function shippedPayload( $tracking = 'XM022896292TS' ): array {
        return array(
            'houla_order_id'  => '47061371-a594-493a-951c-07654057da26',
            'wc_status'       => 'houla-shipping',
            'carrier'         => 'chronopost',
            'tracking_number' => $tracking,
            'tracking_url'    => 'https://tracking.example/' . $tracking,
        );
    }

    private function processingOrder(): MockWcOrder {
        $mockOrder = new MockWcOrder();
        $mockOrder->set_status( 'processing' );
        Functions\when( 'wc_get_orders' )->justReturn( array( 100 ) );
        Functions\when( 'wc_get_order' )->justReturn( $mockOrder );
        Functions\when( 'esc_url_raw' )->returnArg( 1 );
        return $mockOrder;
    }

    public function test_shipping_email_is_sent_when_the_order_enters_delivery(): void {
        $mockOrder = $this->processingOrder();

        ( new \Wp_Houla_Orders() )->update_order_status( $this->shippedPayload() );

        $this->assertEquals( 'houla-shipping', $mockOrder->get_status() );
        $this->assertCount( 1, $mockOrder->customer_notes );
        $this->assertStringContainsString( 'XM022896292TS', $mockOrder->customer_notes[0] );
    }

    /**
     * Cas réel : WC #42762, trois e-mails identiques le 21/08 (étiquette puis
     * transition canonique, puis un troisième envoi du même colis).
     */
    public function test_repeated_shipped_messages_for_the_same_parcel_send_one_email(): void {
        $mockOrder = $this->processingOrder();
        $orders    = new \Wp_Houla_Orders();

        $orders->update_order_status( $this->shippedPayload() );
        $orders->update_order_status( $this->shippedPayload() );
        $orders->update_order_status( $this->shippedPayload() );

        $this->assertCount( 1, $mockOrder->customer_notes );
    }

    public function test_a_new_tracking_number_sends_a_new_email(): void {
        $mockOrder = $this->processingOrder();
        $orders    = new \Wp_Houla_Orders();

        $orders->update_order_status( $this->shippedPayload( 'FIRST123' ) );
        $orders->update_order_status( $this->shippedPayload( 'SECOND456' ) );

        $this->assertCount( 2, $mockOrder->customer_notes );
        $this->assertStringContainsString( 'SECOND456', $mockOrder->customer_notes[1] );
    }
}
