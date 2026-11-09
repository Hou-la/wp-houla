<?php
/**
 * Unit tests for product sync guards in Wp_Houla_Sync.
 *
 * Cas réel : giamory.com #43452, passé de « Bijoux dorés » à « Bijoux argentés »
 * le 2026-10-04. Le filtre de catégories l'a figé sur Hou.la (titre, prix,
 * description) et le bouton « Re-synchroniser » répondait « succès » sans rien
 * envoyer. Sa copie avait aussi hérité du lien court du produit d'origine.
 *
 * Cas réel du 2026-10-13 (giamory.com) : 49 produits à la corbeille ou supprimés
 * dans WooCommerce, toujours en vente sur Hou.la. Les suppressions partaient
 * vers un identifiant Hou.la périmé (404, 14 fois depuis le 2026-09-29), et la
 * corbeille de l'administration ne passe pas par les crochets WooCommerce.
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
        $this->payloads  = array();
        $this->post_types = array();

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
        Functions\when( 'delete_post_meta' )->alias( function ( $id, $key ) {
            unset( $this->meta[ $id ][ $key ] );
            return true;
        } );
        Functions\when( 'get_post_type' )->alias( function ( $id ) {
            return $this->post_types[ $id ] ?? 'product';
        } );
    }

    /** @var array<int, string> post type by ID (default: product) */
    private $post_types = array();

    /**
     * Build the sync service with in-memory options, auth and API doubles.
     *
     * @param array      $options    Plugin options.
     * @param bool       $connected  Whether the site is connected to Hou.la.
     * @param mixed      $api_result What every API call returns, or a callable
     *                               ( $method, $endpoint ) that decides.
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
            private function answer( $m, $e, $d = array() ) { $this->t->recordCall( $m, $e, $d ); return is_callable( $this->r ) ? call_user_func( $this->r, $m, $e ) : $this->r; }
            public function post( $endpoint, $data = array(), $o = array() ) { return $this->answer( 'POST', $endpoint, $data ); }
            public function patch( $endpoint, $data = array(), $o = array() ) { return $this->answer( 'PATCH', $endpoint, $data ); }
            public function delete( $endpoint, $o = array() ) { return $this->answer( 'DELETE', $endpoint ); }
        } );

        return $sync;
    }

    public function recordCall( $method, $endpoint, $data = array() ) {
        $this->calls[]    = array( $method, $endpoint );
        $this->payloads[] = $data;
    }

    /** @var array payloads sent, in the order of $calls */
    private $payloads = array();

    /** @return object the options double of a sync built by makeSync */
    private function optionsOf( $sync ) {
        $prop = new \ReflectionProperty( $sync, 'options' );
        if ( PHP_VERSION_ID < 80100 ) {
            $prop->setAccessible( true );
        }
        return $prop->getValue( $sync );
    }

    private function callPrivate( $object, $method, ...$args ) {
        $m = new \ReflectionMethod( $object, $method );
        if ( PHP_VERSION_ID < 80100 ) {
            $m->setAccessible( true );
        }
        return $m->invoke( $object, ...$args );
    }

    private function makeProduct( $id, $weight = '' ) {
        return new class( $id, $weight ) {
            private $id;
            private $weight;
            public function __construct( $id, $weight ) { $this->id = $id; $this->weight = $weight; }
            public function get_id() { return $this->id; }
            public function get_weight() { return $this->weight; }
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

    /**
     * Les Perles de Jo (2026-11-08) : tout le catalogue déjà sur Hou.la, puis
     * 2 catégories cochées. Jusqu'à la 1.6.7, un produit lié passait TOUJOURS
     * le filtre : les ~600 produits restaient en vente.
     */
    public function test_product_already_on_houla_outside_the_filter_is_out_of_scope() {
        $sync = $this->makeSync( array( 'sync_categories' => array( 727 ) ) );
        $this->cats[43452] = array( 906 );
        $this->meta[43452]['_wphoula_product_id'] = 'f8c0dfb9';

        $this->assertFalse( $this->callPrivate( $sync, 'should_sync_product', 43452 ) );
    }

    /**
     * Sorti du filtre, il n'est ni figé (giamory #43452) ni supprimé : il part
     * en brouillon, avec ses données à jour.
     */
    public function test_update_of_linked_product_outside_the_filter_takes_it_offline() {
        $sync = $this->makeSync( array( 'sync_categories' => array( 727 ), 'auto_sync' => true ) );
        $this->cats[43452] = array( 906 );
        $this->meta[43452]['_wphoula_product_id'] = 'f8c0dfb9';

        $sync->on_product_updated( 43452, $this->makeProduct( 43452 ) );

        $this->assertSame( array( array( 'PATCH', '/ecommerce/products/f8c0dfb9' ) ), $this->calls );
        $this->assertSame( 'draft', $this->payloads[0]['status'] );
    }

    /** Contre-témoin : dans le filtre, il reste publié. */
    public function test_update_of_linked_product_inside_the_filter_stays_active() {
        $sync = $this->makeSync( array( 'sync_categories' => array( 727 ), 'auto_sync' => true ) );
        $this->cats[43452] = array( 727 );
        $this->meta[43452]['_wphoula_product_id'] = 'f8c0dfb9';

        $sync->on_product_updated( 43452, $this->makeProduct( 43452 ) );

        $this->assertSame( 'active', $this->payloads[0]['status'] );
    }

    /** Contre-témoin : sans lien Hou.la et hors filtre, rien ne part. */
    public function test_update_of_unlinked_product_outside_the_filter_sends_nothing() {
        $sync = $this->makeSync( array( 'sync_categories' => array( 727 ), 'auto_sync' => true, 'sync_on_publish' => true ) );
        $this->cats[43445] = array( 906 );

        $sync->on_product_updated( 43445, $this->makeProduct( 43445 ) );

        $this->assertSame( array(), $this->calls );
    }

    // ── Products chosen one by one ──────────────────────────────────────

    public function test_product_chosen_one_by_one_is_in_scope_outside_the_categories() {
        $sync = $this->makeSync( array( 'sync_categories' => array( 727 ), 'sync_products' => array( '43445' ) ) );
        $this->cats[43445] = array( 906 );

        $this->assertTrue( $this->callPrivate( $sync, 'should_sync_product', 43445 ) );
    }

    public function test_products_chosen_without_category_limit_the_scope_to_them() {
        $sync = $this->makeSync( array( 'sync_categories' => array(), 'sync_products' => array( 43445 ) ) );
        $this->cats[43446] = array( 727 );

        $this->assertTrue( $this->callPrivate( $sync, 'should_sync_product', 43445 ) );
        $this->assertFalse( $this->callPrivate( $sync, 'should_sync_product', 43446 ) );
    }

    /** Contre-témoin : aucun filtre = tout le catalogue. */
    public function test_no_filter_at_all_syncs_everything() {
        $sync = $this->makeSync( array( 'sync_categories' => array(), 'sync_products' => array() ) );

        $this->assertTrue( $this->callPrivate( $sync, 'should_sync_product', 43446 ) );
    }

    public function test_manual_sync_outside_the_filter_adds_the_product_to_the_selection() {
        $sync = $this->makeSync( array( 'sync_categories' => array( 727 ), 'sync_products' => array() ) );
        $this->cats[43445] = array( 906 );

        $sync->sync_product_now( 43445, $this->makeProduct( 43445 ) );

        $this->assertSame( array( 43445 ), $this->optionsOf( $sync )->get( 'sync_products' ) );
    }

    /** Contre-témoin : sans filtre, l'ajouter réduirait le catalogue à ce seul produit. */
    public function test_manual_sync_without_filter_keeps_the_selection_empty() {
        $sync = $this->makeSync( array( 'sync_categories' => array(), 'sync_products' => array() ) );

        $sync->sync_product_now( 43445, $this->makeProduct( 43445 ) );

        $this->assertSame( array(), $this->optionsOf( $sync )->get( 'sync_products' ) );
    }

    // ── Batch sync and the filter ───────────────────────────────────────

    public function test_batch_sends_linked_products_outside_the_filter_as_drafts_and_never_creates_them() {
        $sync = $this->makeSync( array( 'sync_categories' => array( 727 ), 'auto_sync' => true ), true, array( 'details' => array( array( 'id' => 'x' ) ) ) );
        $this->cats[1] = array( 727 ); // in the filter, not yet on Hou.la
        $this->cats[2] = array( 906 ); // left the filter, on Hou.la
        $this->meta[2]['_wphoula_product_id'] = 'p2';
        $this->cats[3] = array( 906 ); // outside, not on Hou.la
        $products = array( $this->makeProduct( 1 ), $this->makeProduct( 2 ), $this->makeProduct( 3 ) );
        if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
            define( 'HOUR_IN_SECONDS', 3600 );
        }
        Functions\when( 'wc_get_products' )->alias( function ( $args ) use ( $products ) {
            return ( $args['return'] ?? '' ) === 'ids' ? array( 1 ) : $products;
        } );
        Functions\when( 'get_term' )->justReturn( (object) array( 'slug' => 'perles' ) );
        Functions\when( 'get_posts' )->justReturn( array( 2 ) );
        Functions\when( 'get_transient' )->justReturn( 0 );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'delete_transient' )->justReturn( true );

        $sync->batch_sync_page( 2 );

        $this->assertSame( array( array( 'POST', '/ecommerce/products/batch' ), array( 'POST', '/ecommerce/products/batch' ) ), $this->calls );
        $created = $this->payloads[0]['products'];
        $updated = $this->payloads[1]['products'];
        $this->assertSame( array( '1' ), array_column( $created, 'external_id' ) );
        $this->assertSame( 'active', $created[0]['status'] );
        $this->assertSame( array( '2' ), array_column( $updated, 'external_id' ) );
        $this->assertSame( 'draft', $updated[0]['status'] );
    }

    // ── Weight unit (2026-11-08) ────────────────────────────────────────

    public function test_weight_in_grams_is_sent_in_kilograms() {
        $this->assertSame( '0.075', \Wp_Houla_Sync::weight_to_kg( '75', 'g' ) );
        $this->assertSame( '1.5', \Wp_Houla_Sync::weight_to_kg( '1500', 'g' ) );
    }

    public function test_weight_in_pounds_and_ounces_is_sent_in_kilograms() {
        $this->assertSame( '0.453592', \Wp_Houla_Sync::weight_to_kg( '1', 'lbs' ) );
        $this->assertSame( '0.02835', \Wp_Houla_Sync::weight_to_kg( '1', 'oz' ) );
        $this->assertSame( '0.226796', \Wp_Houla_Sync::weight_to_kg( '8', 'oz' ) );
    }

    /** Contre-témoin : en kg, la valeur repart à l'identique (l'empreinte de modération ne bouge pas). */
    public function test_weight_in_kilograms_is_sent_unchanged() {
        $this->assertSame( '0.50', \Wp_Houla_Sync::weight_to_kg( '0.50', 'kg' ) );
        $this->assertSame( '', \Wp_Houla_Sync::weight_to_kg( '', 'g' ) );
    }

    public function test_product_payload_carries_the_weight_in_kilograms() {
        $sync = $this->makeSync( array() );
        Functions\when( 'get_option' )->alias( function ( $key ) {
            return 'woocommerce_weight_unit' === $key ? 'g' : '';
        } );

        $data = $this->callPrivate( $sync, 'format_product', $this->makeProduct( 43445, '75' ) );

        // Avant : '75', lu comme 75 kg par Hou.la.
        $this->assertSame( '0.075', $data['weight'] );
        $this->assertSame( 'kg', $data['weight_unit'] );
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

    // ── Trash / delete (2026-10-13) ─────────────────────────────────────

    private function notFound() {
        return new \WP_Error( 'wphoula_api_error', 'Product with id/external_id not found', array( 'status' => 404 ) );
    }

    public function test_trash_with_stale_link_deletes_by_woocommerce_id() {
        $sync = $this->makeSync( array( 'auto_sync' => true ), true, function ( $m, $e ) {
            return '/ecommerce/products/449eb9fe' === $e ? $this->notFound() : null;
        } );
        $this->meta[41416]['_wphoula_product_id'] = '449eb9fe';

        $sync->on_post_removed( 41416 );

        // Avant la 1.6.6 : un seul DELETE, 404, et le produit restait en vente.
        $this->assertSame(
            array( array( 'DELETE', '/ecommerce/products/449eb9fe' ), array( 'DELETE', '/ecommerce/products/41416' ) ),
            $this->calls
        );
        $this->assertArrayNotHasKey( '_wphoula_product_id', $this->meta[41416] );
    }

    /** Contre-témoin : lien juste, une seule suppression. */
    public function test_trash_with_valid_link_deletes_once() {
        $sync = $this->makeSync( array( 'auto_sync' => true ), true, null );
        $this->meta[41084]['_wphoula_product_id'] = '020040d3';

        $sync->on_post_removed( 41084 );

        $this->assertSame( array( array( 'DELETE', '/ecommerce/products/020040d3' ) ), $this->calls );
        $this->assertArrayNotHasKey( '_wphoula_product_id', $this->meta[41084] );
    }

    /** Contre-témoin : Hou.la injoignable, le lien est gardé (rien n'est perdu). */
    public function test_trash_when_api_fails_keeps_the_link() {
        $sync = $this->makeSync( array( 'auto_sync' => true ), true, new \WP_Error( 'wphoula_api_error', 'Boom', array( 'status' => 503 ) ) );
        $this->meta[41084]['_wphoula_product_id'] = '020040d3';

        $sync->on_post_removed( 41084 );

        $this->assertCount( 1, $this->calls );
        $this->assertSame( '020040d3', $this->meta[41084]['_wphoula_product_id'] );
    }

    public function test_product_absent_from_houla_under_both_ids_is_unlinked() {
        $sync = $this->makeSync( array( 'auto_sync' => true ), true, function () {
            return $this->notFound();
        } );
        $this->meta[41416]['_wphoula_product_id'] = '449eb9fe';

        $sync->on_post_removed( 41416 );

        $this->assertCount( 2, $this->calls );
        $this->assertArrayNotHasKey( '_wphoula_product_id', $this->meta[41416] );
    }

    /** Contre-témoin : une variation, une page ou une commande ne touche pas Hou.la. */
    public function test_removing_something_else_than_a_product_sends_nothing() {
        $sync = $this->makeSync( array( 'auto_sync' => true ) );
        $this->post_types[43453] = 'product_variation';
        $this->meta[43453]['_wphoula_product_id'] = 'x';

        $sync->on_post_removed( 43453 );

        $this->assertSame( array(), $this->calls );
    }

    public function test_update_refreshes_a_stale_link() {
        $sync = $this->makeSync( array(), true, array( 'id' => 'b57c1e2a' ) );
        $this->meta[41416]['_wphoula_product_id'] = '449eb9fe';

        $sync->sync_product_now( 41416, $this->makeProduct( 41416 ) );

        // Avant la 1.6.6 : le lien périmé restait, et la suppression suivante échouait.
        $this->assertSame( 'b57c1e2a', $this->meta[41416]['_wphoula_product_id'] );
    }

    /** Contre-témoin : réponse sans identifiant (erreur, corps vide) → lien intact. */
    public function test_update_without_id_keeps_the_link() {
        $sync = $this->makeSync( array(), true, array() );
        $this->meta[41416]['_wphoula_product_id'] = '449eb9fe';

        $sync->sync_product_now( 41416, $this->makeProduct( 41416 ) );

        $this->assertSame( '449eb9fe', $this->meta[41416]['_wphoula_product_id'] );
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

    // ── Produits supprimés par la vendeuse sur Hou.la (1.6.9) ───────────
    // « Les Perles de Jo », 2026-11-09 : 659 produits supprimés depuis le
    // studio Hou.la ; chaque « Synchroniser » répondait succès sans rien faire.

    private function stubBatchQuery( array $products, array $linked ) {
        if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
            define( 'HOUR_IN_SECONDS', 3600 );
        }
        Functions\when( 'wc_get_products' )->alias( function ( $args ) use ( $products ) {
            return ( $args['return'] ?? '' ) === 'ids' ? array_map( function ( $p ) { return $p->get_id(); }, $products ) : $products;
        } );
        Functions\when( 'get_term' )->justReturn( (object) array( 'slug' => 'perles' ) );
        Functions\when( 'get_posts' )->justReturn( $linked );
        Functions\when( 'get_transient' )->justReturn( 0 );
        Functions\when( 'set_transient' )->justReturn( true );
        Functions\when( 'delete_transient' )->justReturn( true );
    }

    public function test_batch_reports_a_product_deleted_on_houla_instead_of_counting_it_synced() {
        $sync = $this->makeSync( array( 'auto_sync' => true ), true, array(
            'synced'    => 1,
            'withdrawn' => 1,
            'details'   => array(
                array( 'external_id' => '1201', 'id' => 'h1201', 'withdrawn' => true ),
                array( 'external_id' => '1202', 'id' => 'h1202' ),
            ),
        ) );
        $this->meta[1201] = array( '_wphoula_product_id' => 'h1201', '_wphoula_synced' => 1 );
        $this->meta[1202] = array( '_wphoula_product_id' => 'h1202', '_wphoula_synced' => 1 );
        $this->stubBatchQuery( array( $this->makeProduct( 1201 ), $this->makeProduct( 1202 ) ), array() );

        $r = $sync->batch_sync_page( 2 );

        $this->assertSame( 1, $r['synced'] );
        $this->assertSame( 1, $r['withdrawn'] );
        $this->assertArrayNotHasKey( '_wphoula_synced', $this->meta[1201] );
        $this->assertSame( 1, $this->meta[1201]['_wphoula_withdrawn'] );
        $this->assertSame( 1, $this->meta[1202]['_wphoula_synced'] );
        $this->assertArrayNotHasKey( '_wphoula_withdrawn', $this->meta[1202] );
    }

    /** Contre-témoin : une API qui ne renvoie pas encore `withdrawn` (prod au 2026-11-09). */
    public function test_batch_with_an_older_api_behaves_as_before() {
        $sync = $this->makeSync( array( 'auto_sync' => true ), true, array(
            'synced'  => 1,
            'details' => array( array( 'external_id' => '1201', 'id' => 'h1201' ) ),
        ) );
        $this->meta[1201] = array( '_wphoula_product_id' => 'h1201' );
        $this->stubBatchQuery( array( $this->makeProduct( 1201 ) ), array() );

        $r = $sync->batch_sync_page( 2 );

        $this->assertSame( 1, $r['synced'] );
        $this->assertSame( 0, $r['withdrawn'] );
        $this->assertSame( 1, $this->meta[1201]['_wphoula_synced'] );
    }

    public function test_product_restored_on_houla_loses_its_withdrawn_mark() {
        $sync = $this->makeSync( array( 'auto_sync' => true ), true, array(
            'details' => array( array( 'external_id' => '1201', 'id' => 'h1201' ) ),
        ) );
        $this->meta[1201] = array( '_wphoula_product_id' => 'h1201', '_wphoula_withdrawn' => 1 );
        $this->stubBatchQuery( array( $this->makeProduct( 1201 ) ), array() );

        $sync->batch_sync_page( 2 );

        $this->assertArrayNotHasKey( '_wphoula_withdrawn', $this->meta[1201] );
        $this->assertSame( 1, $this->meta[1201]['_wphoula_synced'] );
    }

    public function test_automatic_update_of_a_product_deleted_on_houla_marks_it() {
        $sync = $this->makeSync( array( 'auto_sync' => true ), true, array( 'id' => 'h1201', 'withdrawn' => true ) );
        $this->meta[1201] = array( '_wphoula_product_id' => 'h1201', '_wphoula_synced' => 1 );

        $sync->on_product_updated( 1201, $this->makeProduct( 1201 ) );

        $this->assertSame( array( array( 'PATCH', '/ecommerce/products/h1201' ) ), $this->calls );
        $this->assertArrayNotHasKey( 'restore_withdrawn', $this->payloads[0] );
        $this->assertSame( 1, $this->meta[1201]['_wphoula_withdrawn'] );
        $this->assertArrayNotHasKey( '_wphoula_synced', $this->meta[1201] );
    }

    public function test_restore_sends_only_the_products_inside_the_filter_with_restore_withdrawn() {
        $sync = $this->makeSync( array( 'sync_categories' => array( 727 ) ), true, array(
            'details' => array(
                array( 'external_id' => '1201', 'id' => 'h1201' ),
                array( 'external_id' => '1202', 'id' => 'h1202' ),
            ),
        ) );
        $this->cats[1201] = array( 727 );
        $this->cats[1202] = array( 727 );
        $this->cats[1203] = array( 906 ); // hors filtre : ne revient pas
        foreach ( array( 1201, 1202, 1203 ) as $id ) {
            $this->meta[ $id ] = array( '_wphoula_product_id' => 'h' . $id, '_wphoula_withdrawn' => 1 );
        }
        Functions\when( 'get_posts' )->justReturn( array( 1201, 1202, 1203 ) );
        Functions\when( 'wc_get_product' )->alias( function ( $id ) {
            return $this->makeProduct( $id );
        } );

        $this->assertSame( array( 'withdrawn' => 3, 'restorable' => 2 ), $sync->count_withdrawn_products() );
        $r = $sync->restore_withdrawn_products( 20 );

        $this->assertSame( array( array( 'POST', '/ecommerce/products/batch' ) ), $this->calls );
        $sent = $this->payloads[0]['products'];
        $this->assertSame( array( '1201', '1202' ), array_column( $sent, 'external_id' ) );
        $this->assertSame( array( true, true ), array_column( $sent, 'restore_withdrawn' ) );
        $this->assertSame( 2, $r['restored'] );
        $this->assertSame( 0, $r['remaining'] );
        $this->assertSame( 1, $r['skipped_out_of_filter'] );
        $this->assertArrayNotHasKey( '_wphoula_withdrawn', $this->meta[1201] );
        $this->assertSame( 1, $this->meta[1203]['_wphoula_withdrawn'] );
    }

    public function test_restore_refused_by_houla_keeps_the_product_listed_and_stops() {
        $sync = $this->makeSync( array(), true, array(
            'details' => array( array( 'external_id' => '1201', 'id' => 'h1201', 'withdrawn' => true ) ),
        ) );
        $this->meta[1201] = array( '_wphoula_product_id' => 'h1201', '_wphoula_withdrawn' => 1 );
        Functions\when( 'get_posts' )->justReturn( array( 1201 ) );
        Functions\when( 'wc_get_product' )->alias( function ( $id ) {
            return $this->makeProduct( $id );
        } );

        $r = $sync->restore_withdrawn_products( 20 );

        $this->assertSame( 0, $r['restored'] );
        $this->assertSame( 1, $r['still_withdrawn'] );
        $this->assertSame( 1, $r['remaining'] ); // la page s'arrête : 0 restauré ce tour-ci
        $this->assertSame( 1, $this->meta[1201]['_wphoula_withdrawn'] );
    }

    /**
     * Relecture du 2026-11-09 : un lot de 20 entièrement refusé arrêtait la
     * remise en ligne, et la page renvoyait toujours les 20 MÊMES produits.
     */
    public function test_restore_moves_past_a_refused_chunk_to_the_next_products() {
        $sync = $this->makeSync( array(), true, function ( $m, $e ) {
            return array( 'details' => array_map( function ( $p ) {
                return '1201' === $p['external_id'] || '1202' === $p['external_id']
                    ? array( 'external_id' => $p['external_id'], 'id' => 'h' . $p['external_id'], 'withdrawn' => true )
                    : array( 'external_id' => $p['external_id'], 'id' => 'h' . $p['external_id'] );
            }, end( $this->payloads )['products'] ) );
        } );
        foreach ( array( 1201, 1202, 1203 ) as $id ) {
            $this->meta[ $id ] = array( '_wphoula_product_id' => 'h' . $id, '_wphoula_withdrawn' => 1 );
        }
        Functions\when( 'get_posts' )->alias( function () {
            return array_values( array_filter( array( 1201, 1202, 1203 ), function ( $id ) {
                return ! empty( $this->meta[ $id ]['_wphoula_withdrawn'] );
            } ) );
        } );
        Functions\when( 'wc_get_product' )->alias( function ( $id ) {
            return $this->makeProduct( $id );
        } );

        $r1 = $sync->restore_withdrawn_products( 2, 0 );
        $r2 = $sync->restore_withdrawn_products( 2, $r1['next_offset'] );

        $this->assertSame( 0, $r1['restored'] );
        $this->assertSame( 2, $r1['still_withdrawn'] );
        $this->assertTrue( $r1['has_more'] );
        $this->assertSame( 2, $r1['next_offset'] );
        // 2e appel : APRÈS les deux refusés (avant : les 2 mêmes, puis arrêt).
        $this->assertSame( array( '1203' ), array_column( $this->payloads[1]['products'], 'external_id' ) );
        $this->assertSame( 1, $r2['restored'] );
        $this->assertFalse( $r2['has_more'] );
        $this->assertSame( 2, $r2['remaining'] );
    }

    // ── Crochets de création (2026-11-09) ───────────────────────────────

    public function test_new_product_inside_the_filter_is_created_and_linked() {
        $sync = $this->makeSync( array( 'auto_sync' => true, 'sync_on_publish' => true, 'sync_categories' => array( 727 ) ) );
        $this->cats[1300] = array( 727 );

        $sync->on_product_created( 1300, $this->makeProduct( 1300 ) );

        $this->assertSame( array( array( 'POST', '/ecommerce/products' ) ), $this->calls );
        $this->assertSame( 'houla-new', $this->meta[1300]['_wphoula_product_id'] );
        $this->assertSame( 1, $this->meta[1300]['_wphoula_synced'] );
    }

    public function test_new_product_is_not_created_when_sync_on_publish_is_off() {
        $sync = $this->makeSync( array( 'auto_sync' => true, 'sync_on_publish' => false ) );

        $sync->on_product_created( 1300, $this->makeProduct( 1300 ) );

        $this->assertSame( array(), $this->calls );
    }

    public function test_first_save_of_an_unlinked_product_creates_it() {
        // Écran d'administration : brouillon automatique, puis « Publier » =
        // woocommerce_update_product sur un produit sans lien Hou.la.
        $sync = $this->makeSync( array( 'auto_sync' => true, 'sync_on_publish' => true ) );

        $sync->on_product_updated( 1301, $this->makeProduct( 1301 ) );

        $this->assertSame( array( array( 'POST', '/ecommerce/products' ) ), $this->calls );
    }

    public function test_product_entering_the_filter_is_created() {
        $sync = $this->makeSync( array( 'auto_sync' => true, 'sync_on_publish' => true, 'sync_categories' => array( 727 ) ) );
        $this->cats[1302] = array( 727 ); // vient d'être rangé dans une catégorie cochée

        $sync->on_product_updated( 1302, $this->makeProduct( 1302 ) );

        $this->assertSame( array( array( 'POST', '/ecommerce/products' ) ), $this->calls );
        $this->assertSame( 'active', $this->payloads[0]['status'] );
    }

    // ── Stock (2026-11-09) ──────────────────────────────────────────────

    private function makeVariable( $id, array $children ) {
        return new class( $id, $children ) {
            private $id;
            private $children;
            public function __construct( $id, $children ) { $this->id = $id; $this->children = $children; }
            public function get_id() { return $this->id; }
            public function is_type( $t ) { return 'variable' === $t; }
            public function get_children() { return $this->children; }
            public function get_stock_quantity() { return null; }
            public function get_stock_status() { return 'instock'; }
            public function get_manage_stock() { return false; }
        };
    }

    private function makeVariation( $id, $parent, $qty ) {
        return new class( $id, $parent, $qty ) {
            private $id;
            private $parent;
            private $qty;
            public function __construct( $id, $parent, $qty ) { $this->id = $id; $this->parent = $parent; $this->qty = $qty; }
            public function get_id() { return $this->id; }
            public function get_parent_id() { return $this->parent; }
            public function is_type( $t ) { return 'variation' === $t; }
            public function managing_stock() { return null !== $this->qty; }
            public function get_stock_quantity() { return $this->qty; }
        };
    }

    public function test_variation_stock_change_sends_the_stock_of_each_variation() {
        $sync   = $this->makeSync( array( 'auto_sync' => true ) );
        $parent = $this->makeVariable( 1400, array( 1401, 1402 ) );
        $bleu   = $this->makeVariation( 1401, 1400, 1 );
        $rouge  = $this->makeVariation( 1402, 1400, null ); // stock non géré = illimité
        Functions\when( 'wc_get_product' )->alias( function ( $id ) use ( $parent, $bleu, $rouge ) {
            return array( 1400 => $parent, 1401 => $bleu, 1402 => $rouge )[ $id ] ?? false;
        } );
        $this->meta[1400]['_wphoula_product_id'] = 'h1400';

        $sync->on_stock_changed( $bleu );

        $this->assertSame( array( array( 'PATCH', '/ecommerce/products/h1400/stock' ) ), $this->calls );
        $this->assertSame( array(
            array( 'external_variant_id' => '1401', 'stock_quantity' => 1 ),
            array( 'external_variant_id' => '1402', 'stock_quantity' => null ),
        ), $this->payloads[0]['variant_stocks'] );
    }

    public function test_simple_product_stock_change_sends_no_variant_stocks() {
        $sync    = $this->makeSync( array( 'auto_sync' => true ) );
        $product = $this->makeProduct( 1500 );
        $this->meta[1500]['_wphoula_product_id'] = 'h1500';

        $sync->on_stock_changed( $product );

        $this->assertSame( array( array( 'PATCH', '/ecommerce/products/h1500/stock' ) ), $this->calls );
        $this->assertArrayNotHasKey( 'variant_stocks', $this->payloads[0] );
    }

    public function test_stock_change_of_a_product_outside_the_filter_is_not_sent() {
        $sync = $this->makeSync( array( 'auto_sync' => true, 'sync_categories' => array( 727 ) ) );
        $this->cats[1500] = array( 906 );
        $this->meta[1500]['_wphoula_product_id'] = 'h1500';

        $sync->on_stock_changed( $this->makeProduct( 1500 ) );

        $this->assertSame( array(), $this->calls );
    }
}
