<?php
/**
 * Unit tests for Wp_Houla_Options.
 */

namespace WpHoula\Tests\Unit;

use WpHoula\Tests\TestCase;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-options.php';

class OptionsTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        Functions\when( 'get_option' )->justReturn( array() );
        // Do NOT stub update_option/delete_option here: tests use expect()
        // on them and Brain\Monkey does not allow when() + expect().
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, $args );
        } );
        Functions\when( 'apply_filters' )->returnArg( 2 );
    }

    public function test_get_returns_default_values(): void {
        $options = new \Wp_Houla_Options();

        $this->assertEquals( WPHOULA_VERSION, $options->get( 'version' ) );
        $this->assertEquals( '', $options->get( 'access_token' ) );
        $this->assertEquals( true, $options->get( 'auto_sync' ) );
        $this->assertEquals( true, $options->get( 'sync_on_publish' ) );
        $this->assertEquals( false, $options->get( 'debug' ) );
        $this->assertEquals( 0, $options->get( 'token_expires_at' ) );
    }

    public function test_get_returns_null_for_unknown_key(): void {
        $options = new \Wp_Houla_Options();
        $this->assertNull( $options->get( 'nonexistent_key' ) );
    }

    public function test_set_updates_value_and_saves(): void {
        Functions\expect( 'update_option' )
            ->once()
            ->with( WPHOULA_OPTIONS, \Mockery::type( 'array' ) )
            ->andReturn( true );

        $options = new \Wp_Houla_Options();
        $options->set( 'debug', true );

        $this->assertTrue( $options->get( 'debug' ) );
    }

    public function test_set_many_updates_multiple_values(): void {
        Functions\expect( 'update_option' )
            ->once()
            ->andReturn( true );

        $options = new \Wp_Houla_Options();
        $options->set_many( array(
            'workspace_id'   => 'ws-123',
            'workspace_name' => 'Test Workspace',
            'user_email'     => 'test@example.com',
        ) );

        $this->assertEquals( 'ws-123', $options->get( 'workspace_id' ) );
        $this->assertEquals( 'Test Workspace', $options->get( 'workspace_name' ) );
        $this->assertEquals( 'test@example.com', $options->get( 'user_email' ) );
    }

    /**
     * Banc du 2026-11-09 : une synchro longue (une seule requête, jusqu'à
     * 30 min) écrivait à la fin TOUT le tableau lu à son démarrage ; le filtre
     * de catégories enregistré pendant ce temps était annulé.
     */
    public function test_set_keeps_the_settings_saved_by_another_request(): void {
        $db = array( 'sync_categories' => array( 727 ), 'products_synced' => 3 );
        Functions\when( 'get_option' )->alias( function () use ( &$db ) { return $db; } );
        $written = null;
        Functions\when( 'update_option' )->alias( function ( $name, $value ) use ( &$written ) {
            $written = $value;
            return true;
        } );

        $sync_request = new \Wp_Houla_Options();          // la synchro démarre
        $db['sync_categories'] = array( 906 );              // la vendeuse enregistre un autre filtre
        $sync_request->set( 'products_synced', 600 );       // la synchro finit

        $this->assertSame( array( 906 ), $written['sync_categories'] );
        $this->assertSame( 600, $written['products_synced'] );
    }

    public function test_delete_all_removes_options(): void {
        Functions\expect( 'delete_option' )
            ->once()
            ->with( WPHOULA_OPTIONS )
            ->andReturn( true );

        Functions\expect( 'delete_option' )
            ->once()
            ->with( WPHOULA_AUTHORIZED )
            ->andReturn( true );

        \Wp_Houla_Options::delete_all();
    }

    public function test_encrypt_returns_empty_for_empty_input(): void {
        $this->assertEquals( '', \Wp_Houla_Options::encrypt( '' ) );
    }

    public function test_decrypt_returns_empty_for_empty_input(): void {
        $this->assertEquals( '', \Wp_Houla_Options::decrypt( '' ) );
    }

    public function test_encrypt_decrypt_roundtrip(): void {
        $original = 'my-secret-access-token-12345';
        $encrypted = \Wp_Houla_Options::encrypt( $original );

        $this->assertNotEquals( $original, $encrypted );
        $this->assertNotEmpty( $encrypted );

        $decrypted = \Wp_Houla_Options::decrypt( $encrypted );
        $this->assertEquals( $original, $decrypted );
    }

    public function test_encrypt_produces_different_ciphertexts(): void {
        $value = 'same-value';
        $encrypted1 = \Wp_Houla_Options::encrypt( $value );
        $encrypted2 = \Wp_Houla_Options::encrypt( $value );

        // Different IVs should produce different ciphertexts.
        $this->assertNotEquals( $encrypted1, $encrypted2 );

        // Both should decrypt to the same value.
        $this->assertEquals( $value, \Wp_Houla_Options::decrypt( $encrypted1 ) );
        $this->assertEquals( $value, \Wp_Houla_Options::decrypt( $encrypted2 ) );
    }

    public function test_decrypt_returns_original_if_not_encrypted(): void {
        $plain = 'not-encrypted-at-all';
        $result = \Wp_Houla_Options::decrypt( $plain );
        $this->assertEquals( $plain, $result );
    }

    public function test_populated_from_existing_options(): void {
        Functions\when( 'get_option' )->justReturn( array(
            'workspace_id' => 'ws-existing',
            'debug'        => true,
        ) );

        $options = new \Wp_Houla_Options();
        $this->assertEquals( 'ws-existing', $options->get( 'workspace_id' ) );
        $this->assertTrue( $options->get( 'debug' ) );
        // Defaults still fill missing keys.
        $this->assertEquals( true, $options->get( 'auto_sync' ) );
    }
}
