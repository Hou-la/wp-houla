<?php
/**
 * Unit tests for Wp_Houla_Activator and Wp_Houla_Deactivator.
 */

namespace WpHoula\Tests\Unit;

use WpHoula\Tests\TestCase;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-activator.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-deactivator.php';

class ActivatorTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();
        // Create the log directory so real is_dir() returns true and the
        // log-creation block is skipped in tests that don't need it.
        @\mkdir( WPHOULA_DIR . '/log', 0755, true );

        // WP-Cron scheduling functions called by activate()/deactivate()
        // (wphoula_cron_sync). Stub them so the unit tests don't hit
        // "Call to undefined function wp_next_scheduled()".
        Functions\when( 'wp_next_scheduled' )->justReturn( false );
        Functions\when( 'wp_schedule_event' )->justReturn( true );
        Functions\when( 'wp_clear_scheduled_hook' )->justReturn( true );
    }

    protected function tearDown(): void {
        // Clean up any files created during the test.
        $logDir = WPHOULA_DIR . '/log';
        @\unlink( $logDir . '/.htaccess' );
        @\rmdir( $logDir );
        parent::tearDown();
    }

    public function test_activate_creates_webhook_secret(): void {
        $savedOptions = null;

        Functions\when( 'get_option' )->justReturn( array() );
        Functions\expect( 'update_option' )
            ->once()
            ->andReturnUsing( function ( $key, $value ) use ( &$savedOptions ) {
                $savedOptions = $value;
                return true;
            } );
        Functions\when( 'flush_rewrite_rules' )->justReturn( true );
        Functions\when( 'deactivate_plugins' )->justReturn( true );
        Functions\when( 'wp_mkdir_p' )->justReturn( true );

        \Wp_Houla_Activator::activate();

        $this->assertNotNull( $savedOptions );
        $this->assertArrayHasKey( 'webhook_secret', $savedOptions );
        $this->assertNotEmpty( $savedOptions['webhook_secret'] );
    }

    public function test_activate_preserves_existing_secret(): void {
        $existingSecret = 'my-existing-secret';
        $savedOptions   = null;

        Functions\when( 'get_option' )->justReturn( array( 'webhook_secret' => $existingSecret ) );
        Functions\when( 'flush_rewrite_rules' )->justReturn( true );
        Functions\when( 'wp_mkdir_p' )->justReturn( true );

        // activate() ALWAYS persists options (it also migrates the order-status
        // map), but it must NOT regenerate an already-present webhook secret.
        Functions\expect( 'update_option' )
            ->once()
            ->andReturnUsing( function ( $key, $value ) use ( &$savedOptions ) {
                $savedOptions = $value;
                return true;
            } );

        \Wp_Houla_Activator::activate();

        $this->assertSame( $existingSecret, $savedOptions['webhook_secret'] );
    }

    public function test_activate_creates_log_directory(): void {
        // Remove the log directory so real is_dir() returns false.
        $logDir = WPHOULA_DIR . '/log';
        @\unlink( $logDir . '/.htaccess' );
        @\rmdir( $logDir );

        Functions\when( 'get_option' )->justReturn( array( 'webhook_secret' => 'existing' ) );
        Functions\when( 'flush_rewrite_rules' )->justReturn( true );
        // activate() always persists options (line 38); this test focuses on
        // the log-dir creation, so stub update_option as a no-op.
        Functions\when( 'update_option' )->justReturn( true );
        Functions\expect( 'wp_mkdir_p' )
            ->once()
            ->with( \Mockery::type( 'string' ) )
            ->andReturnUsing( function ( $path ) {
                // Actually create the directory so file_put_contents works.
                @\mkdir( $path, 0755, true );
                return true;
            } );

        \Wp_Houla_Activator::activate();

        // Verify that .htaccess was created by the real file_put_contents.
        $this->assertFileExists( $logDir . '/.htaccess' );
    }

    public function test_deactivate_cleans_transients(): void {
        Functions\expect( 'delete_transient' )
            ->once()
            ->with( 'wphoula_batch_sync_running' )
            ->andReturn( true );
        Functions\when( 'flush_rewrite_rules' )->justReturn( true );

        \Wp_Houla_Deactivator::deactivate();
    }
}
