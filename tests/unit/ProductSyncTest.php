<?php
/**
 * Unit tests for product sync guards in Wp_Houla_Sync.
 *
 * Cas réel : giamory.com #43452, passé de « Bijoux dorés » à « Bijoux argentés »
 * le 2026-10-04. Le filtre de catégories l'a figé sur Hou.la (titre, prix,
 * description) et le bouton « Re-synchroniser » répondait « succès » sans rien
 * envoyer. Sa copie avait aussi hérité du lien court du produit d'origine.
 */

namespace WpHoula\Tests\Unit;

use WpHoula\Tests\TestCase;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-sync.php';

class ProductSyncTest extends TestCase {

    /** @var array<int, array<string, mixed>> post meta by product ID */
    private $meta = array();

    /** @var array<int, int[]> product categories by product ID */
    private $cats = array();

    /** @var array<int, int[]> category ancestors */
    private $ancestors = array();

    /** @var array API calls made: [method, endpoint] */
    private $calls = array();

    protected function setUp(): void {
        parent::setUp();
        $this->meta      = array();
        $this->cats      = array();
        $this->ancestors = array();
        $this->calls     = array();

        Functions\when( 'get_post_meta' )->alias( function ( $id, $key = '', $single = false ) {
            return $this->meta[ $id ][ $key ] ?? '';
        } );
        Functions\when( 'update_post_meta' )->alias( function ( $id, $key, $value ) {
            $this->meta[ $id ][ $key ] = $value;
            return true;
        } );
        Functions\when( 'wp_get_post_terms' )->alias( function ( $id, $tax, $args = array() ) {
            if ( 'product_cat' !== $tax ) {
                return array();
            }
            return ( $args['fields'] ?? '' ) === 'ids' ? ( $this->cats[ $id ] ?? array() ) : array();
        } );
        Functions\when( 'get_ancestors' )->alias( function ( $id ) {
            return $this->ancestors[ $id ] ?? array();
        } );
        Functions\when( 'current_time' )->justReturn( '2026-10-07 18:00:00' );
        Functions\when( 'get_site_url' )->justReturn( 'https://giamory.com' );
        Functions\when( 'get_woocommerce_currency' )->justReturn( 'EUR' );
        Functions\when( 'get_option' )->justReturn( '' );
        Functions\when( 'wp_get_attachment_url' )->justReturn( false );
        Functions\when( 'taxonomy_exists' )->justReturn( false );
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) {
            return $value;
        } );
        Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
        Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        } );
    }

    /**
     * Build the sync service with in-memory options, auth and API doubles.
     *
     * @param array      $options    Plugin options.
     * @param bool       $connected  Whether the site is connected to Hou.la.
     * @param mixed      $api_result What every API call returns.
     */
    private function makeSync( array $options, $connected = true, $api_result = array( 'id' => 'houla-new' ) ) {
        $ref  = new \ReflectionClass( \Wp_Houla_Sync::class );
        $sync = $ref->newInstanceWithoutConstructor();

        $test = $this;
        $set  = function ( $name, $value ) use ( $ref, $sync ) {
            $prop = $ref->getProperty( $name );
            if ( PHP_VERSION_ID < 80100 ) {
                $prop->setAccessible( true );
            }
            $prop->setValue( $sync, $value );
        };

        $set( 'options', new class( $options ) {
            private $o;
            public function __construct( $o ) { $this->o = $o; }
            public function get( $key ) { return $this->o[ $key ] ?? null; }
            public function set( $key, $value ) { $this->o[ $key ] = $value; }
            public function set_many( $values ) { $this->o = array_merge( $this->o, $values ); }
        } );
        $set( 'auth', new class( $connected ) {
            private $c;
            public function __construct( $c ) { $this->c = $c; }
            public function is_connected() { return $this->c; }
        } );
        $set( 'api', new class( $test, $api_result ) {
            private $t;
            private $r;
            public function __construct( $t, $r ) { $this->t = $t; $this->r = $r; }
            public function post( $endpoint, $data = array(), $o = array() ) { $this->t->recordCall( 'POST', $endpoint ); return $this->r; }
            public function patch( $endpoint, $data = array(), $o = array() ) { $this->t->recordCall( 'PATCH', $endpoint ); return $this->r; }
        } );

        return $sync;
    }

    public function recordCall( $method, $endpoint ) {
        $this->calls[] = array( $method, $endpoint );
    }

    private function callPrivate( $object, $method, ...$args ) {
        $m = new \ReflectionMethod( $object, $method );
        if ( PHP_VERSION_ID < 80100 ) {
            $m->setAccessible( true );
        }
        return $m->invoke( $object, ...$args );
    }

    private function makeProduct( $id ) {
        return new class( $id ) {
            private $id;
            public function __construct( $id ) { $this->id = $id; }
            public function get_id() { return $this->id; }
            public function get_type() { return 'simple'; }
            public function is_type( $t ) { return 'simple' === $t; }
            public function get_name() { return 'Mystery Bubble Tea ( Bijoux silver)'; }
            public function get_status() { return 'publish'; }
            public function __call( $name, $args ) {
                if ( in_array( $name, array( 'get_gallery_image_ids', 'get_children', 'get_attributes' ), true ) ) {
                    return array();
                }
                return strpos( $name, 'is_' ) === 0 ? false : '';
            }
        };
    }

    // ── Category filter ─────────────────────────────────────────────────

    public function test_product_already_on_houla_stays_synced_outside_the_filter() {
        $sync = $this->makeSync( array( 'sync_categories' => array( 727 ) ) );
        $this->cats[43452] = array( 906 );
        $this->meta[43452]['_wphoula_product_id'] = 'f8c0dfb9';

        $this->assertTrue( $this->callPrivate( $sync, 'should_sync_product', 43452 ) );
    }

    /** Contre-témoin : sans lien Hou.la, le filtre garde la porte d'entrée. */
    public function test_new_product_outside_the_filter_is_not_synced() {
        $sync = $this->makeSync( array( 'sync_categories' => array( 727 ) ) );
        $this->cats[43445] = array( 906 );

        $this->assertFalse( $this->callPrivate( $sync, 'should_sync_product', 43445 ) );
    }

    public function test_product_in_a_child_of_a_filtered_category_is_synced() {
        $sync = $this->makeSync( array( 'sync_categories' => array( 906 ) ) );
        $this->cats[43729]      = array( 925 );
        $this->ancestors[925]   = array( 906 );

        $this->assertTrue( $this->callPrivate( $sync, 'should_sync_product', 43729 ) );
    }

    // ── Manual sync button ──────────────────────────────────────────────

    public function test_manual_sync_ignores_filter_and_auto_sync() {
        $sync = $this->makeSync( array( 'sync_categories' => array( 727 ), 'auto_sync' => false ) );
        $this->cats[43452] = array( 906 );
        $this->meta[43452]['_wphoula_product_id'] = 'f8c0dfb9';

        $result = $sync->sync_product_now( 43452, $this->makeProduct( 43452 ) );

        $this->assertNotInstanceOf( \WP_Error::class, $result );
        $this->assertSame( array( array( 'PATCH', '/ecommerce/products/f8c0dfb9' ) ), $this->calls );
        $this->assertSame( '2026-10-07 18:00:00', $this->meta[43452]['_wphoula_sync_at'] );
    }

    public function test_manual_sync_reports_api_failure() {
        $error = new \WP_Error( 'wphoula_api_error', 'Boom' );
        $sync  = $this->makeSync( array(), true, $error );
        $this->meta[43452]['_wphoula_product_id'] = 'f8c0dfb9';
        $this->meta[43452]['_wphoula_sync_at']    = '2026-10-04 21:28:22';

        $result = $sync->sync_product_now( 43452, $this->makeProduct( 43452 ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( '2026-10-04 21:28:22', $this->meta[43452]['_wphoula_sync_at'] );
    }

    public function test_manual_sync_when_disconnected_sends_nothing() {
        $sync = $this->makeSync( array(), false );
        $this->meta[43452]['_wphoula_product_id'] = 'f8c0dfb9';

        $result = $sync->sync_product_now( 43452, $this->makeProduct( 43452 ) );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( array(), $this->calls );
    }

    public function test_manual_sync_of_unlinked_product_creates_and_links_it() {
        $sync = $this->makeSync( array( 'sync_categories' => array( 727 ) ) );
        $this->cats[43445] = array( 906 );

        $result = $sync->sync_product_now( 43445, $this->makeProduct( 43445 ) );

        $this->assertNotInstanceOf( \WP_Error::class, $result );
        $this->assertSame( array( array( 'POST', '/ecommerce/products' ) ), $this->calls );
        $this->assertSame( 'houla-new', $this->meta[43445]['_wphoula_product_id'] );
    }

    // ── Duplicate ───────────────────────────────────────────────────────

    public function test_duplicate_does_not_copy_houla_meta() {
        $sync    = $this->makeSync( array() );
        $exclude = $sync->exclude_meta_from_duplicate( array( '_other_plugin' ) );

        foreach ( array( '_wphoula_product_id', '_wphoula_sync_at', '_wphoula_link_id', '_wphoula_shortlink', '_wphoula_qrcode' ) as $key ) {
            $this->assertContains( $key, $exclude );
        }
        $this->assertContains( '_other_plugin', $exclude );
    }
}
