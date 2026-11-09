<?php
/**
 * Rattachement des commandes Hou.la à la cliente WooCommerce (1.6.10, Trello #146).
 *
 * Retour des Perles de Jo (2026-11-09) : une cliente déjà inscrite sur le site,
 * avec le MÊME e-mail, commande sur Hou.la ; la commande arrivait en invitée
 * (customer_id jamais posé) et la liste Clients de WooCommerce montrait une
 * autre fiche. Ces tests vérifient les décisions du module ; la mécanique de
 * WooCommerce (wc_customer_lookup, e-mails) est prouvée sur le banc fsn2.
 */

namespace WpHoula\Tests\Integration;

use WpHoula\Tests\TestCase;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-options.php';
require_once dirname( __DIR__, 2 ) . '/includes/class-wp-houla-orders.php';

/** Compte WordPress minimal. */
class LinkUser {
    public $ID;
    public $roles;
    /** @var string[] capacités ajoutées à la main, hors rôle */
    public $extra_caps = array();
    public $user_email = '';
    /** UTC, comme dans wp_users */
    public $user_registered = '2026-01-01 10:00:00';
    public function __construct( $id, array $roles, $email = '' ) { $this->ID = $id; $this->roles = $roles; $this->user_email = $email; }
}

/** Drapeau « stock déjà retiré » de WooCommerce. */
class LinkDataStore {
    public function get_stock_reduced( $id ) { return false; }
    public function set_stock_reduced( $id, $value ) {}
}

/** Commande WooCommerce : porte le client, les notes et la facturation. */
class LinkOrder {
    public $customer_id = 0;
    public $meta        = array();
    public $notes       = array();
    public $billing     = array();
    public $status      = 'pending';
    public $items       = array();
    /** Création de la commande (UTC). */
    public $created     = '2026-11-09 20:00:00';

    public function get_date_created() { return new \DateTime( $this->created, new \DateTimeZone( 'UTC' ) ); }

    public function get_id() { return 500; }
    public function get_customer_id() { return $this->customer_id; }
    public function set_customer_id( $id ) { $this->customer_id = (int) $id; }
    public function get_data_store() { return new LinkDataStore(); }
    public function add_product( $product, $qty, $args = array() ) {}
    public function add_item( $item ) {}
    public function get_items( $types = 'line_item' ) { return array(); }
    public function remove_item( $id ) {}
    public function set_address( $data, $type ) { if ( 'billing' === $type ) { $this->billing = $data; } }
    public function set_billing_first_name( $n ) { $this->billing['first_name'] = $n; }
    public function get_billing_first_name() { return $this->billing['first_name'] ?? ''; }
    public function get_billing_last_name() { return $this->billing['last_name'] ?? ''; }
    public function set_payment_method( $m ) {}
    public function set_payment_method_title( $t ) {}
    public function set_currency( $c ) {}
    public function set_transaction_id( $t ) {}
    public function set_date_paid( $d ) {}
    public function calculate_totals() {}
    public function set_total( $t ) {}
    public function set_status( $s, $note = '' ) { $this->status = $s; }
    public function get_status() { return $this->status; }
    public function update_meta_data( $k, $v ) { $this->meta[ $k ] = $v; }
    public function delete_meta_data( $k ) { unset( $this->meta[ $k ] ); }
    public function get_meta( $k = '', $single = true ) { return $this->meta[ $k ] ?? ''; }
    public function save_meta_data() {}
    public function save() {}
    public function add_order_note( $note, $is_customer_note = 0 ) { $this->notes[] = $note; }
    public function get_refunds() { return array(); }
}

class CustomerLinkTest extends TestCase {

    /** @var LinkUser[] comptes du site, par e-mail EXACT (en minuscules) */
    private $users = array();

    /** @var array réglages enregistrés de l'extension */
    private $settings = array();

    /** @var array réglages WooCommerce (get_option) */
    private $wc = array();

    /** @var bool[] appartenance au site (multisite), par ID de compte */
    private $members = array();

    /** @var array[] appels à wc_create_new_customer */
    private $created = array();

    protected function setUp(): void {
        parent::setUp();
        $this->users    = array(
            'jo@exemple.test'      => new LinkUser( 12, array( 'customer' ), 'jo@exemple.test' ),
            'gerante@exemple.test' => new LinkUser( 1, array( 'administrator' ), 'gerante@exemple.test' ),
            'gestion@exemple.test' => new LinkUser( 3, array( 'shop_manager' ), 'gestion@exemple.test' ),
            'abonnee@exemple.test' => new LinkUser( 4, array( 'subscriber' ), 'abonnee@exemple.test' ),
        );
        $this->settings = array();
        $this->created  = array();
        $this->members  = array();
        // Site qui prouve l'e-mail : WooCommerce envoie le lien « définir le mot de passe ».
        $this->wc       = array( 'woocommerce_registration_generate_password' => 'yes' );

        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            if ( WPHOULA_OPTIONS === $name ) {
                return $this->settings;
            }
            return array_key_exists( $name, $this->wc ) ? $this->wc[ $name ] : $default;
        } );
        Functions\when( 'is_user_member_of_blog' )->alias( function ( $id ) { return ! empty( $this->members[ $id ] ); } );
        Functions\when( 'update_option' )->justReturn( true );
        Functions\when( 'wp_parse_args' )->alias( function ( $args, $defaults ) { return array_merge( $defaults, (array) $args ); } );
        Functions\when( 'apply_filters' )->returnArg( 2 );
        Functions\when( 'is_wp_error' )->alias( function ( $t ) { return $t instanceof \WP_Error; } );
        Functions\when( 'current_time' )->justReturn( '2026-11-09 23:00:00' );
        Functions\when( 'wc_get_order_statuses' )->justReturn( array( 'wc-processing' => 'Processing', 'wc-open-cart' => 'Open cart' ) );
        Functions\when( 'wc_get_product' )->justReturn( false );
        // Ne pas DÉCLARER wc_reduce_stock_levels ici : une fonction simulée
        // reste déclarée pour les tests suivants (OrderFlowTest), qui ne la
        // simulent pas. On ne la neutralise que si un test précédent l'a déclarée.
        if ( function_exists( 'wc_reduce_stock_levels' ) ) {
            Functions\when( 'wc_reduce_stock_levels' )->justReturn( null );
        }
        Functions\when( 'is_multisite' )->justReturn( false );
        Functions\when( 'wp_strip_all_tags' )->alias( function ( $s ) { return strip_tags( $s ); } );
        Functions\when( 'is_email' )->alias( function ( $e ) { return false !== filter_var( $e, FILTER_VALIDATE_EMAIL ) ? $e : false; } );
        // Recherche EXACTE : prouve que le module normalise lui-même l'e-mail.
        Functions\when( 'get_user_by' )->alias( function ( $field, $value ) {
            if ( 'id' === $field ) {
                foreach ( $this->users as $u ) {
                    if ( $u->ID === (int) $value ) {
                        return $u;
                    }
                }
                return false;
            }
            return ( 'email' === $field && isset( $this->users[ $value ] ) ) ? $this->users[ $value ] : false;
        } );
        Functions\when( 'user_can' )->alias( function ( $user, $cap ) {
            $caps = array(
                'administrator' => array( 'manage_options', 'manage_woocommerce', 'edit_shop_orders', 'edit_posts', 'list_users', 'edit_users' ),
                'shop_manager'  => array( 'manage_woocommerce', 'edit_shop_orders', 'edit_posts' ),
                'customer'      => array( 'read' ),
                'subscriber'    => array( 'read' ),
            );
            if ( in_array( $cap, $user->extra_caps, true ) ) {
                return true;
            }
            foreach ( $user->roles as $role ) {
                if ( in_array( $cap, $caps[ $role ] ?? array(), true ) ) {
                    return true;
                }
            }
            return false;
        } );
        Functions\when( 'wc_create_new_customer' )->alias( function ( $email, $username = '', $password = '', $args = array() ) {
            $this->created[] = array( $email, $username, $password, $args );
            return 77;
        } );
    }

    /** Commande neuve, aucune commande Hou.la existante. */
    private function create( $email, $status = 'processing' ) {
        $order = new LinkOrder();
        Functions\when( 'wc_create_order' )->justReturn( $order );
        Functions\when( 'wc_get_orders' )->justReturn( array() );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $result = ( new \Wp_Houla_Orders() )->create_order( array(
            'houla_order_id' => 'HL-146',
            'customer'       => array( 'email' => $email, 'first_name' => 'Josiane', 'last_name' => 'Banc', 'display_name' => 'JoLive' ),
            'items'          => array( array( 'external_id' => '41', 'quantity' => 1, 'price' => 12, 'name' => 'Perle' ) ),
            'total'          => 12,
            'wc_status'      => $status,
        ) );
        $this->assertSame( 500, $result['order_id'] );
        return $order;
    }

    public function test_existing_customer_is_attached_whatever_the_case_and_spaces() {
        $order = $this->create( '  Jo@Exemple.TEST ' );

        // Avant (1.6.9) : customer_id restait à 0, commande « Invité ».
        $this->assertSame( 12, $order->customer_id );
        $this->assertSame( 'linked', $order->meta['_houla_customer_link'] );
        $this->assertStringContainsString( 'rattachée au compte client existant', implode( "\n", $order->notes ) );
        $this->assertSame( array(), $this->created, 'aucun compte créé' );
        // La commande garde les coordonnées Hou.la ; le profil n'est jamais écrit (pas de WC_Customer ici).
        $this->assertSame( 'Jo@Exemple.TEST', $order->billing['email'] );
    }

    public function test_known_guest_stays_a_guest_order_with_her_details() {
        // Invitée connue : aucune fiche utilisateur, des commandes invitées
        // précédentes. Analytics regroupe par e-mail (prouvé sur le banc).
        $order = $this->create( 'invitee@exemple.test' );

        $this->assertSame( 0, $order->customer_id );
        $this->assertSame( 'invitee@exemple.test', $order->billing['email'] );
        $this->assertArrayNotHasKey( '_houla_customer_link', $order->meta );
        $this->assertSame( array(), $this->created );
    }

    public function test_new_buyer_gets_no_account_by_default() {
        $order = $this->create( 'nouvelle@exemple.test' );

        $this->assertSame( 0, $order->customer_id );
        $this->assertSame( array(), $this->created, 'aucun compte, donc aucun e-mail de mot de passe' );
    }

    public function test_administrator_with_the_same_email_is_never_attached() {
        $order = $this->create( 'Gerante@exemple.test' );

        $this->assertSame( 0, $order->customer_id );
        $this->assertSame( 'not-a-customer', $order->meta['_houla_customer_link'] );
        $this->assertStringContainsString( 'n\'y est pas rattachée', implode( "\n", $order->notes ) );
        $this->assertSame( array(), $this->created );
    }

    public function test_shop_manager_with_the_same_email_is_never_attached() {
        $order = $this->create( 'gestion@exemple.test' );

        $this->assertSame( 0, $order->customer_id );
        $this->assertSame( 'not-a-customer', $order->meta['_houla_customer_link'] );
    }

    public function test_customer_role_with_extra_capability_is_never_attached() {
        // Rôle « customer » mais un 2e rôle éditeur : refusé.
        $this->users['jo@exemple.test'] = new LinkUser( 12, array( 'customer', 'shop_manager' ) );
        $order = $this->create( 'jo@exemple.test' );

        $this->assertSame( 0, $order->customer_id );
    }

    public function test_option_on_creates_a_customer_account_for_a_new_buyer() {
        $this->settings = array( 'create_customer_account' => true );
        $order = $this->create( ' Nouvelle@Exemple.test' );

        $this->assertCount( 1, $this->created );
        list( $email, $username, $password, $args ) = $this->created[0];
        $this->assertSame( 'nouvelle@exemple.test', $email );
        // Identifiant et mot de passe générés par WooCommerce : c'est ce qui
        // déclenche son e-mail de bienvenue avec le lien « choisir un mot de passe ».
        $this->assertSame( '', $username );
        $this->assertSame( '', $password );
        // Identité CIVILE, jamais le pseudo Hou.la.
        $this->assertSame( array( 'first_name' => 'Josiane', 'last_name' => 'Banc' ), $args );
        $this->assertSame( 77, $order->customer_id );
        $this->assertSame( 'account-created', $order->meta['_houla_customer_link'] );
    }

    public function test_option_on_never_creates_an_account_when_one_exists() {
        $this->settings = array( 'create_customer_account' => true );
        $order = $this->create( 'jo@exemple.test' );

        $this->assertSame( array(), $this->created );
        $this->assertSame( 12, $order->customer_id );
    }

    public function test_option_on_but_account_refused_keeps_a_guest_order() {
        $this->settings = array( 'create_customer_account' => true );
        Functions\when( 'wc_create_new_customer' )->justReturn( new \WP_Error( 'registration-error', 'Captcha manquant' ) );
        $order = $this->create( 'nouvelle@exemple.test' );

        $this->assertSame( 0, $order->customer_id );
        $this->assertSame( 'account-failed', $order->meta['_houla_customer_link'] );
        $this->assertStringContainsString( 'Captcha manquant', implode( "\n", $order->notes ) );
    }

    public function test_invalid_email_attaches_nothing() {
        $order = $this->create( 'pas-un-email' );

        $this->assertSame( 0, $order->customer_id );
        $this->assertArrayNotHasKey( '_houla_customer_link', $order->meta );
    }

    /** Resynchronisation (panier ouvert, relance) : rattache une commande invitée, une seule note, aucun compte créé. */
    public function test_update_attaches_a_guest_order_once_and_never_creates_an_account() {
        $this->settings = array( 'create_customer_account' => true );
        $order = new LinkOrder();
        $order->meta = array( '_houla_order_id' => 'HL-146', '_houla_stock_v2' => '1' );
        $order->status = 'open-cart';
        Functions\when( 'wc_get_orders' )->justReturn( array( 500 ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        $payload = array(
            'houla_order_id' => 'HL-146',
            'customer'       => array( 'email' => 'JO@exemple.test' ),
            'items'          => array( array( 'external_id' => '41', 'quantity' => 1, 'price' => 12 ) ),
        );
        ( new \Wp_Houla_Orders() )->update_order( $payload );
        ( new \Wp_Houla_Orders() )->update_order( $payload );
        $this->assertSame( 12, $order->customer_id );
        $this->assertCount( 1, array_filter( $order->notes, function ( $n ) { return false !== strpos( $n, 'rattachée au compte client' ); } ) );

        // Nouvelle acheteuse sur une mise à jour : jamais de compte créé.
        $order2 = new LinkOrder();
        $order2->meta = array( '_houla_order_id' => 'HL-147', '_houla_stock_v2' => '1' );
        Functions\when( 'wc_get_order' )->justReturn( $order2 );
        ( new \Wp_Houla_Orders() )->update_order( array_merge( $payload, array( 'houla_order_id' => 'HL-147', 'customer' => array( 'email' => 'nouvelle@exemple.test' ) ) ) );
        $this->assertSame( 0, $order2->customer_id );
        $this->assertSame( array(), $this->created );
    }

    public function test_an_attached_order_never_changes_account() {
        $order = new LinkOrder();
        $order->customer_id = 99;
        $order->meta = array( '_houla_order_id' => 'HL-146', '_houla_stock_v2' => '1' );
        Functions\when( 'wc_get_orders' )->justReturn( array( 500 ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        ( new \Wp_Houla_Orders() )->update_order( array(
            'houla_order_id' => 'HL-146',
            'customer'       => array( 'email' => 'jo@exemple.test' ),
            'items'          => array( array( 'external_id' => '41', 'quantity' => 1, 'price' => 12 ) ),
        ) );

        $this->assertSame( 99, $order->customer_id );
    }

    // ─────────────────────────────────────────────────────────────────
    // Relecture de sécurité du 2026-11-09 : e-mail non prouvé, compte
    // ouvert après la commande, détachement.
    // ─────────────────────────────────────────────────────────────────

    /** Les clientes choisissent leur mot de passe : un tiers a pu s'inscrire avec cet e-mail. */
    public function test_site_that_does_not_verify_emails_never_attaches_by_default() {
        $this->wc['woocommerce_registration_generate_password'] = 'no';
        $order = $this->create( 'jo@exemple.test' );

        $this->assertSame( 0, $order->customer_id );
        $this->assertSame( 'unverified', $order->meta['_houla_customer_link'] );
        $this->assertStringContainsString( 'ne vérifie pas l\'e-mail', implode( "\n", $order->notes ) );
    }

    public function test_missing_woocommerce_setting_counts_as_not_verified() {
        $this->wc = array();
        $order = $this->create( 'jo@exemple.test' );

        $this->assertSame( 0, $order->customer_id );
        $this->assertSame( 'unverified', $order->meta['_houla_customer_link'] );
    }

    public function test_seller_can_accept_unverified_accounts_explicitly() {
        $this->wc['woocommerce_registration_generate_password'] = 'no';
        $this->settings = array( 'link_unverified_accounts' => true );
        $order = $this->create( 'jo@exemple.test' );

        $this->assertSame( 12, $order->customer_id );
        $this->assertSame( 'linked', $order->meta['_houla_customer_link'] );
    }

    public function test_site_verification_follows_the_woocommerce_setting() {
        $this->wc['woocommerce_registration_generate_password'] = 'yes';
        $this->assertTrue( \Wp_Houla_Orders::site_verifies_email() );
        $this->wc['woocommerce_registration_generate_password'] = 'no';
        $this->assertFalse( \Wp_Houla_Orders::site_verifies_email() );
    }

    public function test_subscriber_is_never_attached() {
        $order = $this->create( 'abonnee@exemple.test' );

        $this->assertSame( 0, $order->customer_id );
        $this->assertSame( 'not-a-customer', $order->meta['_houla_customer_link'] );
    }

    public function test_customer_with_edit_users_added_by_hand_is_never_attached() {
        $this->users['jo@exemple.test']->extra_caps = array( 'edit_users' );
        $order = $this->create( 'jo@exemple.test' );

        $this->assertSame( 0, $order->customer_id );
        $this->assertSame( 'not-a-customer', $order->meta['_houla_customer_link'] );
    }

    public function test_multisite_account_of_another_site_is_never_attached() {
        Functions\when( 'is_multisite' )->justReturn( true );
        $this->members = array( 12 => false );
        $order = $this->create( 'jo@exemple.test' );
        $this->assertSame( 0, $order->customer_id );

        $this->members = array( 12 => true );
        $order = $this->create( 'jo@exemple.test' );
        $this->assertSame( 12, $order->customer_id );
    }

    /** Mise à jour : un compte ouvert APRÈS la commande ne la récupère pas. */
    public function test_update_never_attaches_to_an_account_opened_after_the_order() {
        $this->users['jo@exemple.test']->user_registered = '2026-11-09 21:00:00'; // commande : 20:00
        $order = new LinkOrder();
        $order->meta = array( '_houla_order_id' => 'HL-146', '_houla_stock_v2' => '1' );
        Functions\when( 'wc_get_orders' )->justReturn( array( 500 ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );

        ( new \Wp_Houla_Orders() )->update_order( array(
            'houla_order_id' => 'HL-146',
            'customer'       => array( 'email' => 'jo@exemple.test' ),
            'items'          => array( array( 'external_id' => '41', 'quantity' => 1, 'price' => 12 ) ),
        ) );

        $this->assertSame( 0, $order->customer_id );
        $this->assertSame( 'account-too-recent', $order->meta['_houla_customer_link'] );
    }

    private function update_with_email( LinkOrder $order, $email ) {
        Functions\when( 'wc_get_orders' )->justReturn( array( 500 ) );
        Functions\when( 'wc_get_order' )->justReturn( $order );
        ( new \Wp_Houla_Orders() )->update_order( array(
            'houla_order_id' => 'HL-146',
            'customer'       => array( 'email' => $email ),
            'items'          => array( array( 'external_id' => '41', 'quantity' => 1, 'price' => 12 ) ),
        ) );
    }

    public function test_order_attached_by_the_plugin_is_detached_when_its_email_changes() {
        $order = new LinkOrder();
        $order->customer_id = 12;
        $order->meta = array( '_houla_order_id' => 'HL-146', '_houla_stock_v2' => '1', '_houla_customer_link' => 'linked' );

        $this->update_with_email( $order, ' JO@exemple.test ' ); // même adresse : rien ne bouge
        $this->assertSame( 12, $order->customer_id );

        $this->update_with_email( $order, 'autre@exemple.test' );
        $this->assertSame( 0, $order->customer_id );
        $this->assertSame( 'unlinked', $order->meta['_houla_customer_link'] );
        $this->assertStringContainsString( 'redevient une commande invitée', implode( "\n", $order->notes ) );
    }

    public function test_order_whose_account_was_created_by_the_plugin_is_detached_too() {
        $this->users['creee@exemple.test'] = new LinkUser( 77, array( 'customer' ), 'creee@exemple.test' );
        $order = new LinkOrder();
        $order->customer_id = 77;
        $order->meta = array( '_houla_order_id' => 'HL-146', '_houla_stock_v2' => '1', '_houla_customer_link' => 'account-created' );

        $this->update_with_email( $order, 'autre@exemple.test' );
        $this->assertSame( 0, $order->customer_id );
    }

    public function test_detached_order_follows_the_rules_for_its_new_email() {
        // Rattachée à 12, l'e-mail devient celui de l'administratrice : invitée, jamais l'admin.
        $order = new LinkOrder();
        $order->customer_id = 12;
        $order->meta = array( '_houla_order_id' => 'HL-146', '_houla_stock_v2' => '1', '_houla_customer_link' => 'linked' );

        $this->update_with_email( $order, 'gerante@exemple.test' );
        $this->assertSame( 0, $order->customer_id );
        $this->assertSame( 'not-a-customer', $order->meta['_houla_customer_link'] );
    }

    public function test_order_attached_by_hand_is_never_detached() {
        // Pas de _houla_customer_link : la vendeuse l'a rattachée elle-même.
        $order = new LinkOrder();
        $order->customer_id = 12;
        $order->meta = array( '_houla_order_id' => 'HL-146', '_houla_stock_v2' => '1' );

        $this->update_with_email( $order, 'autre@exemple.test' );
        $this->assertSame( 12, $order->customer_id );
    }
}
