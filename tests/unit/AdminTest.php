<?php
/**
 * Unit tests for Wp_Houla_Admin.
 */

namespace WpHoula\Tests\Unit;

use WpHoula\Tests\TestCase;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-options.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-auth.php';
require_once dirname( __DIR__, 2 ) . '/admin/class-wp-houla-admin.php';

class AdminTest extends TestCase {

    protected function setUp(): void {
        parent::setUp();

        Functions\when( 'get_option' )->alias( function ( $key, $default = false ) {
            if ( $key === WPHOULA_AUTHORIZED ) return false;
            return array();
        } );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) {
            return array_merge( $defaults, $args );
        } );
        Functions\when( 'apply_filters' )->returnArg( 2 );
        // Do NOT stub wp_enqueue_style, wp_enqueue_script, wp_localize_script,
        // add_submenu_page here: they use expect() in tests and Brain\Monkey
        // does not allow when() + expect() on the same function.
        Functions\when( 'wp_create_nonce' )->justReturn( 'test-nonce' );
        Functions\when( 'get_current_screen' )->justReturn( null );
    }

    public function test_add_menu_page_with_woocommerce(): void {
        Functions\when( 'wphoula_is_woocommerce_active' )->justReturn( true );
        Functions\expect( 'add_submenu_page' )
            ->once()
            ->with(
                'woocommerce-marketing',
                \Mockery::type( 'string' ),
                \Mockery::type( 'string' ),
                'manage_woocommerce',
                'wp-houla',
                \Mockery::type( 'array' )
            );

        $admin = new \Wp_Houla_Admin();
        $admin->add_menu_page();
    }

    public function test_add_menu_page_standalone(): void {
        Functions\when( 'wphoula_is_woocommerce_active' )->justReturn( false );
        Functions\expect( 'add_menu_page' )
            ->once()
            ->with(
                \Mockery::type( 'string' ),
                \Mockery::type( 'string' ),
                'manage_options',
                'wp-houla',
                \Mockery::type( 'array' ),
                \Mockery::type( 'string' ),
                81
            );

        $admin = new \Wp_Houla_Admin();
        $admin->add_menu_page();
    }

    public function test_add_action_links(): void {
        $admin = new \Wp_Houla_Admin();
        $links = $admin->add_action_links( array( '<a href="#">Deactivate</a>' ) );

        $this->assertCount( 2, $links );
        $this->assertStringContainsString( 'wp-houla', $links[0] );
        $this->assertStringContainsString( 'Settings', $links[0] );
    }

    public function test_enqueue_styles_on_settings_page(): void {
        Functions\expect( 'wp_enqueue_style' )
            ->once()
            ->with( 'wp-houla-admin', \Mockery::type( 'string' ), array(), WPHOULA_VERSION, 'all' );

        $admin = new \Wp_Houla_Admin();
        $admin->enqueue_styles( 'woocommerce_page_wp-houla' );
    }

    public function test_enqueue_styles_skips_other_pages(): void {
        Functions\expect( 'wp_enqueue_style' )->never();

        $admin = new \Wp_Houla_Admin();
        $admin->enqueue_styles( 'dashboard' );
    }

    public function test_enqueue_styles_on_post_edit(): void {
        Functions\expect( 'wp_enqueue_style' )->once();

        $admin = new \Wp_Houla_Admin();
        $admin->enqueue_styles( 'post.php' );
    }

    public function test_enqueue_scripts_on_settings_page(): void {
        Functions\expect( 'wp_enqueue_script' )->once();
        Functions\expect( 'wp_localize_script' )->once();

        $admin = new \Wp_Houla_Admin();
        $admin->enqueue_scripts( 'woocommerce_page_wp-houla' );
    }

    public function test_enqueue_scripts_skips_other_pages(): void {
        Functions\expect( 'wp_enqueue_script' )->never();

        $admin = new \Wp_Houla_Admin();
        $admin->enqueue_scripts( 'dashboard' );
    }

    public function test_display_settings_notice_when_not_connected(): void {
        // No screen -> no notice output.
        Functions\when( 'get_current_screen' )->justReturn( null );

        $admin = new \Wp_Houla_Admin();
        // Should not throw.
        ob_start();
        $admin->display_settings_notice();
        $output = ob_get_clean();

        $this->assertEmpty( $output );
    }

    public function test_display_settings_notice_on_plugins_page(): void {
        $screen     = (object) array( 'id' => 'plugins' );
        Functions\when( 'get_current_screen' )->justReturn( $screen );

        $admin = new \Wp_Houla_Admin();

        ob_start();
        $admin->display_settings_notice();
        $output = ob_get_clean();

        $this->assertStringContainsString( 'notice-warning', $output );
        $this->assertStringContainsString( 'wp-houla', $output );
    }

    // =====================================================================
    // 1.6.13, Trello #332 : connexion liée à l'espace choisi sur Hou.la
    // =====================================================================

    public function test_message_espace_lie_pour_changer_d_espace(): void {
        $msg = \Wp_Houla_Admin::message_espace_lie( 'OAUTH_SWITCH_WORKSPACE_REQUIRES_CONSENT', 'switch' );
        $this->assertSame( "Pour changer d'espace, déconnectez-vous puis reconnectez-vous en choisissant l'espace.", $msg );
    }

    public function test_message_espace_lie_pour_la_cle_d_un_autre_espace(): void {
        $msg = \Wp_Houla_Admin::message_espace_lie( 'OAUTH_TOKEN_WORKSPACE_MISMATCH', 'cle' );
        $this->assertStringContainsString( 'ne peut plus créer de clé pour un autre espace', $msg );
        $this->assertStringContainsString( 'déconnectez-vous puis reconnectez-vous', $msg );
        $this->assertNotNull( \Wp_Houla_Admin::message_espace_lie( 'API_KEY_WORKSPACE_MISMATCH', 'cle' ) );
    }

    // Relecture #332 : le bouton appelait l'API avec la clé du site (401), qui
    // effaçait puis recréait la clé à chaque clic. Il répond désormais sans appel.
    public function test_changer_d_espace_affiche_le_message_sans_appeler_l_api(): void {
        Functions\when( 'check_ajax_referer' )->justReturn( true );
        Functions\when( 'current_user_can' )->justReturn( true );
        Functions\expect( 'wp_remote_request' )->never();
        Functions\expect( 'wp_remote_post' )->never();
        Functions\when( 'wp_send_json_error' )->alias( function ( $message ) {
            throw new \RuntimeException( (string) $message );
        } );
        $_POST['workspace_id'] = 'ws-autre';

        try {
            ( new \Wp_Houla_Admin() )->ajax_switch_workspace();
            $this->fail( 'wp_send_json_error aurait dû être appelé' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( "Pour changer d'espace, déconnectez-vous puis reconnectez-vous en choisissant l'espace.", $e->getMessage() );
        } finally {
            unset( $_POST['workspace_id'] );
        }
    }

    // Contre-témoin : un autre refus garde le message de l'API.
    public function test_message_espace_lie_ignore_les_autres_codes(): void {
        $this->assertNull( \Wp_Houla_Admin::message_espace_lie( 'WORKSPACE_NOT_MEMBER', 'switch' ) );
        $this->assertNull( \Wp_Houla_Admin::message_espace_lie( '', 'cle' ) );
        $this->assertNull( \Wp_Houla_Admin::message_espace_lie( null, 'cle' ) );
    }
}
