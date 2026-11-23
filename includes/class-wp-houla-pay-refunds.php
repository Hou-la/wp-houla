<?php
/**
 * Remboursement d'une commande Hou.la depuis WooCommerce, par Hou.la Pay
 * (Trello #324).
 *
 * Le bouton natif « Rembourser X € via Hou.la Pay » appelle
 * `process_refund()` de la passerelle (Wp_Houla_Gateway), qui délègue ici.
 * Hou.la rembourse VRAIMENT la cliente, sur sa carte d'origine, par la route
 * `POST /ecommerce/orders/:id/refund` (API, même chemin que le studio).
 *
 * Déroulé, à l'intérieur de `wc_create_refund()` :
 *  1. WooCommerce crée et enregistre son remboursement, puis déclenche
 *     `woocommerce_create_refund` juste avant : `capture_refund()` le retient ;
 *  2. `process()` envoie à Hou.la le numéro de ce remboursement (clé de
 *     dédoublonnage), le montant, la devise, le motif et les lignes, SOUS le
 *     verrou de la commande (le même que les webhooks) ;
 *  3. succès : le remboursement WooCommerce reçoit `_houla_refund_id` (id de
 *     la demande Hou.la) avant que le verrou soit rendu. Le webhook
 *     `order.refunded` que Hou.la envoie aussitôt attend le verrou, reçoit un
 *     409, et sa relance trouve ce remboursement : rien n'est créé deux fois
 *     (Wp_Houla_Orders::do_refund_order). `woocommerce_order_refunded`, qui
 *     suit, ne le déclare pas non plus à Hou.la
 *     (Wp_Houla_Refund_Notice::on_order_refunded) ;
 *  4. échec : WP_Error avec le message de Hou.la en français. WooCommerce
 *     l'affiche, supprime son remboursement et ne note rien.
 *
 * La remise en stock reste celle de WooCommerce (case « Remettre en stock »),
 * resynchronisée vers Hou.la comme tout changement de stock : Hou.la ne
 * remet rien en stock pour ce remboursement.
 *
 * @since      1.6.12
 * @package    Wp_Houla
 * @subpackage Wp_Houla/includes
 */

class Wp_Houla_Pay_Refunds {

    /** Moyen de paiement des commandes Hou.la importées (Wp_Houla_Orders). */
    const GATEWAY_ID = 'houla_pay';

    /** Méta du remboursement WooCommerce : id de la demande Hou.la. */
    const REFUND_ID_META = '_houla_refund_id';

    /**
     * Méta de la commande : dernier remboursement à l'issue INCONNUE (pas de
     * réponse de Hou.la, ou panne) : { amount_cents, at }. Tant qu'il n'est
     * pas confirmé (webhook order.refunded, qui la retire) et pendant
     * UNCERTAIN_TTL secondes, un nouveau remboursement est refusé : sinon la
     * vendeuse qui recommence rembourserait deux fois.
     */
    const UNCERTAIN_META = '_houla_pay_uncertain';

    /** 15 minutes : le temps que Hou.la vérifie chez Stripe et relance son webhook. */
    const UNCERTAIN_TTL = 900;

    /** @var Wp_Houla_Pay_Refunds|null */
    private static $instance = null;

    /**
     * Remboursement en cours de création, par commande (crochet
     * `woocommerce_create_refund`), le temps de l'appel à la passerelle.
     *
     * @var array<int, object>
     */
    private static $pending = array();

    /** @var Wp_Houla_Api */
    private $api;

    /** @var Wp_Houla_Auth */
    private $auth;

    /** @var Wp_Houla_Orders */
    private $orders;

    public function __construct( $api = null, $auth = null, $orders = null ) {
        $this->api    = $api ? $api : new Wp_Houla_Api();
        $this->auth   = $auth ? $auth : new Wp_Houla_Auth();
        $this->orders = $orders ? $orders : new Wp_Houla_Orders();
    }

    /** @return Wp_Houla_Pay_Refunds */
    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /** Tests : instance injectée, ou remise à zéro. */
    public static function set_instance( $instance ) {
        self::$instance = $instance;
        self::$pending  = array();
    }

    // =====================================================================
    // Crochets
    // =====================================================================

    /**
     * Filtre `woocommerce_payment_gateways` : déclare la passerelle.
     *
     * @param array $gateways Passerelles déclarées.
     * @return array
     */
    public static function register_gateway( $gateways ) {
        if ( ! class_exists( 'WC_Payment_Gateway' ) ) {
            return $gateways;
        }
        require_once __DIR__ . '/class-wp-houla-gateway.php';
        $gateways   = is_array( $gateways ) ? $gateways : array();
        $gateways[] = 'Wp_Houla_Gateway';
        return $gateways;
    }

    /**
     * Action `woocommerce_create_refund` : retient le remboursement que
     * WooCommerce va demander à la passerelle de payer.
     *
     * @param object $refund Remboursement WooCommerce (pas encore enregistré).
     * @param array  $args   Arguments de wc_create_refund().
     */
    public static function capture_refund( $refund, $args ) {
        if ( ! is_object( $refund ) || ! is_array( $args ) || empty( $args['refund_payment'] ) ) {
            return;
        }
        $order_id = method_exists( $refund, 'get_parent_id' ) ? (int) $refund->get_parent_id() : 0;
        if ( $order_id <= 0 && isset( $args['order_id'] ) ) {
            $order_id = (int) $args['order_id'];
        }
        if ( $order_id > 0 ) {
            self::$pending[ $order_id ] = $refund;
        }
    }

    // =====================================================================
    // Passerelle
    // =====================================================================

    /**
     * @param object $order Commande WooCommerce.
     * @return bool
     */
    public function can_refund( $order ) {
        if ( ! is_object( $order ) || '' === (string) $order->get_meta( '_houla_order_id' ) ) {
            return false;
        }
        return (bool) $this->auth->is_connected();
    }

    /**
     * Rembourse la cliente par Hou.la.
     *
     * @param int        $order_id Commande WooCommerce.
     * @param float|null $amount   Montant à rembourser.
     * @param string     $reason   Motif saisi.
     * @return bool|WP_Error
     */
    public function process( $order_id, $amount, $reason = '' ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return $this->error( __( 'Commande introuvable.', 'wp-houla' ) );
        }
        $houla_order_id = (string) $order->get_meta( '_houla_order_id' );
        if ( '' === $houla_order_id ) {
            return $this->error( __( 'Cette commande ne vient pas de Hou.la : Hou.la Pay ne peut pas la rembourser.', 'wp-houla' ) );
        }
        if ( ! $this->auth->is_connected() ) {
            return $this->error( __( 'WP-Houla n\'est plus connecté à Hou.la. Reconnectez-le (menu Hou.la), puis recommencez. Rien n\'a été remboursé.', 'wp-houla' ) );
        }
        $amount_cents = (int) round( (float) $amount * 100 );
        if ( $amount_cents <= 0 ) {
            return $this->error( __( 'Montant à rembourser invalide. Rien n\'a été remboursé.', 'wp-houla' ) );
        }
        $uncertain = $order->get_meta( self::UNCERTAIN_META );
        if ( is_array( $uncertain ) && isset( $uncertain['at'] ) && ( time() - (int) $uncertain['at'] ) < self::UNCERTAIN_TTL ) {
            return $this->error( sprintf(
                /* translators: 1: amount, 2: studio URL */
                __( 'Le remboursement précédent de %1$s n'a pas encore été confirmé par Hou.la. Attendez qu'il apparaisse ici (quelques minutes) ou vérifiez dans le studio avant d'en faire un autre : %2$s. Rien n'a été remboursé cette fois.', 'wp-houla' ),
                $this->format_amount( ( isset( $uncertain['amount_cents'] ) ? (int) $uncertain['amount_cents'] : 0 ) / 100, $order ),
                ( new Wp_Houla_Refund_Notice( $this->api, $this->auth ) )->studio_url( $order )
            ) );
        }
        $refund = $this->pending_refund( (int) $order->get_id(), $amount_cents );
        if ( ! $refund ) {
            return $this->error( __( 'Remboursement WooCommerce introuvable : recommencez depuis la commande. Rien n\'a été remboursé.', 'wp-houla' ) );
        }

        $currency = method_exists( $order, 'get_currency' ) ? strtoupper( (string) $order->get_currency() ) : '';
        $body     = array(
            'wc_refund_id' => (int) $refund->get_id(),
            'amount_cents' => $amount_cents,
            'items'        => $this->refund_items( $order, $refund ),
        );
        if ( '' !== $currency ) {
            $body['currency'] = $currency;
        }
        $reason = trim( (string) $reason );
        if ( '' !== $reason ) {
            $body['reason'] = function_exists( 'mb_substr' ) ? mb_substr( $reason, 0, 500 ) : substr( $reason, 0, 500 );
        }

        $api    = $this->api;
        $result = $this->orders->with_order_lock( $houla_order_id, function () use ( $api, $houla_order_id, $body, $refund ) {
            $response = $api->post( '/ecommerce/orders/' . rawurlencode( $houla_order_id ) . '/refund', $body );
            if ( is_wp_error( $response ) ) {
                return $response;
            }
            if ( ! is_array( $response ) || empty( $response['success'] ) || empty( $response['refund_request_id'] ) ) {
                return new WP_Error( 'wphoula_refund_unexpected', 'Unexpected answer from Hou.la.', array( 'code' => 'UNEXPECTED_ANSWER' ) );
            }
            // Marque posée AVANT de rendre le verrou : le webhook order.refunded
            // de ce même remboursement la trouvera et ne créera rien.
            $refund->update_meta_data( self::REFUND_ID_META, sanitize_text_field( (string) $response['refund_request_id'] ) );
            $refund->update_meta_data( '_houla_refund_via', self::GATEWAY_ID );
            $refund->save();
            return $response;
        } );
        unset( self::$pending[ (int) $order->get_id() ] );

        if ( is_wp_error( $result ) ) {
            if ( $this->is_uncertain( $result ) ) {
                $order->update_meta_data( self::UNCERTAIN_META, array( 'amount_cents' => $amount_cents, 'at' => time() ) );
                $order->save_meta_data();
            }
            $this->log( 'Hou.la Pay refund of ' . $amount_cents . ' cents failed for WC #' . $order->get_id() . ': ' . $result->get_error_code() . ' ' . $result->get_error_message() );
            return $this->error( $this->error_message( $result, $order ) );
        }

        $stripe_id = isset( $result['stripe_refund_id'] ) && is_string( $result['stripe_refund_id'] ) ? $result['stripe_refund_id'] : '';
        $order->add_order_note( sprintf(
            /* translators: 1: amount, 2: Stripe refund reference */
            __( 'Remboursement de %1$s rendu à la cliente par Hou.la Pay, sur sa carte d\'origine%2$s.', 'wp-houla' ),
            $this->format_amount( $amount_cents / 100, $order ),
            '' !== $stripe_id ? ' (Stripe ' . sanitize_text_field( $stripe_id ) . ')' : ''
        ) );
        $this->log( 'Hou.la Pay refund of ' . $amount_cents . ' cents done for WC #' . $order->get_id() . ' (Hou.la request ' . $result['refund_request_id'] . ').' );
        return true;
    }

    // =====================================================================
    // Interne
    // =====================================================================

    /**
     * Remboursement retenu pour cette commande, s'il porte bien ce montant et
     * un numéro WooCommerce (il est enregistré avant l'appel à la passerelle).
     */
    private function pending_refund( $order_id, $amount_cents ) {
        if ( ! isset( self::$pending[ $order_id ] ) ) {
            return null;
        }
        $refund = self::$pending[ $order_id ];
        if ( (int) $refund->get_id() <= 0 ) {
            return null;
        }
        if ( (int) round( abs( (float) $refund->get_amount() ) * 100 ) !== $amount_cents ) {
            return null;
        }
        if ( '' !== (string) $refund->get_meta( self::REFUND_ID_META ) ) {
            return null; // Déjà rattaché à une demande Hou.la.
        }
        return $refund;
    }

    /**
     * Lignes du remboursement : produit et variation WooCommerce de la ligne
     * d'origine, quantité, montant TTC. Hou.la ne s'en sert que pour
     * l'historique.
     */
    private function refund_items( $order, $refund ) {
        $items = array();
        if ( ! method_exists( $refund, 'get_items' ) ) {
            return $items;
        }
        foreach ( $refund->get_items( 'line_item' ) as $item ) {
            $original_id = (int) $item->get_meta( '_refunded_item_id' );
            $original    = ( $original_id && method_exists( $order, 'get_item' ) ) ? $order->get_item( $original_id ) : null;
            $source      = $original ? $original : $item;
            $product_id  = method_exists( $source, 'get_product_id' ) ? (int) $source->get_product_id() : 0;
            $variation   = method_exists( $source, 'get_variation_id' ) ? (int) $source->get_variation_id() : 0;
            $cents       = (int) round( abs( (float) $item->get_total() + (float) $item->get_total_tax() ) * 100 );
            if ( $product_id <= 0 || $cents <= 0 ) {
                continue;
            }
            $items[] = array(
                'external_id'         => (string) $product_id,
                'external_variant_id' => $variation > 0 ? (string) $variation : null,
                'quantity'            => abs( (int) $item->get_quantity() ),
                'amount_cents'        => $cents,
            );
        }
        return $items;
    }

    /**
     * Message affiché par WooCommerce quand Hou.la n'a pas remboursé.
     *
     * @param WP_Error $error Erreur de l'appel.
     * @param object   $order Commande WooCommerce.
     * @return string
     */
    public function error_message( $error, $order ) {
        $data   = $error->get_error_data();
        $code   = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
        $body   = is_array( $data ) && isset( $data['body'] ) && is_array( $data['body'] ) ? $data['body'] : array();
        $studio = ' ' . sprintf(
            /* translators: %s: studio URL */
            __( 'Commande dans le studio Hou.la : %s', 'wp-houla' ),
            ( new Wp_Houla_Refund_Notice( $this->api, $this->auth ) )->studio_url( $order )
        );
        $nothing = ' ' . __( 'Rien n\'a été remboursé.', 'wp-houla' );

        if ( 'wphoula_order_busy' === $error->get_error_code() ) {
            return __( 'Hou.la met à jour cette commande en ce moment. Réessayez dans quelques secondes.', 'wp-houla' ) . $nothing;
        }
        if ( in_array( $error->get_error_code(), array( 'wphoula_not_connected', 'wphoula_unauthorized' ), true ) ) {
            return __( 'Hou.la a refusé la connexion de cette boutique. Reconnectez WP-Houla (menu Hou.la), puis recommencez.', 'wp-houla' ) . $nothing;
        }
        if ( 'wphoula_rate_limited' === $error->get_error_code() ) {
            return __( 'Hou.la reçoit trop de demandes de cette boutique. Réessayez dans une minute.', 'wp-houla' ) . $nothing;
        }
        // Pas de réponse (délai dépassé, réseau) : Hou.la a pu rembourser.
        if ( 'wphoula_api_error' !== $error->get_error_code() && 0 === $status ) {
            return __( 'Hou.la n\'a pas répondu à temps. Le remboursement a peut-être été fait : ne le refaites pas tout de suite. S\'il a abouti, il apparaîtra ici de lui-même dans quelques minutes.', 'wp-houla' ) . $studio;
        }
        switch ( $code ) {
            case 'SHOP_REFUND_AMOUNT_INVALID':
                return __( 'Hou.la refuse ce montant : il dépasse ce qui reste à rembourser sur la commande Hou.la.', 'wp-houla' ) . $nothing . $studio;
            case 'SHOP_ORDER_ALREADY_REFUNDED':
                return __( 'Cette commande est déjà entièrement remboursée, ou annulée, sur Hou.la.', 'wp-houla' ) . $nothing . $studio;
            case 'SHOP_REFUND_OTHER_PENDING':
            case 'SHOP_REFUND_IN_PROGRESS':
                return __( 'Un autre remboursement de cette commande est en cours de vérification chez Stripe. Attendez qu\'il aboutisse (quelques minutes), puis rechargez la commande.', 'wp-houla' ) . $nothing;
            case 'SHOP_REFUND_PENDING_VERIFICATION':
                return __( 'Stripe n\'a pas encore confirmé ce remboursement. Ne le refaites pas : dès qu\'il sera confirmé, il apparaîtra ici de lui-même.', 'wp-houla' ) . $studio;
            case 'SHOP_REFUND_PARTIALLY_EXECUTED':
                $done = isset( $body['executedCents'] ) ? (int) $body['executedCents'] : 0;
                return sprintf(
                    /* translators: %s: amount actually refunded */
                    __( 'Stripe n\'a rendu qu\'une partie du montant (%s). Cette partie apparaîtra ici d\'elle-même ; le reste n\'a pas été rendu.', 'wp-houla' ),
                    $this->format_amount( $done / 100, $order )
                ) . $studio;
            case 'SHOP_REFUND_REFUSED_BY_PROVIDER':
            case 'SHOP_REFUND_FAILED_AT_PROVIDER':
                $reason = isset( $body['providerCode'] ) && is_string( $body['providerCode'] ) ? ' (' . sanitize_text_field( $body['providerCode'] ) . ')' : '';
                return __( 'Stripe a refusé le remboursement', 'wp-houla' ) . $reason . '.' . $nothing . $studio;
            case 'PAYMENT_NO_STRIPE_PAYMENT':
                return __( 'Cette commande n\'a pas de paiement par carte sur Hou.la : remboursez-la depuis le studio.', 'wp-houla' ) . $nothing . $studio;
            case 'WORKSPACE_PERMISSION_REQUIRED':
                return __( 'Le compte Hou.la relié à cette boutique n\'a pas le droit « Remboursements ». Demandez-le au propriétaire de l\'espace, ou remboursez depuis le studio.', 'wp-houla' ) . $nothing . $studio;
            case 'SHOP_ORDER_NOT_FOUND':
            case 'SHOP_ORDER_NOT_FROM_STORE':
                return __( 'Hou.la ne trouve pas cette commande dans l\'espace relié à cette boutique.', 'wp-houla' ) . $nothing;
            case 'SHOP_REFUND_CURRENCY_MISMATCH':
                return __( 'La devise de cette commande ne correspond pas à celle de la commande Hou.la.', 'wp-houla' ) . $nothing . $studio;
            case 'SHOP_REFUND_ALREADY_REFUSED':
            case 'SHOP_REFUND_KEY_REUSED':
                return __( 'Ce remboursement a déjà été traité par Hou.la. Rechargez la commande.', 'wp-houla' ) . $nothing . $studio;
        }
        // Panne côté Hou.la (500, passerelle 502/504) : issue inconnue.
        if ( $status >= 500 ) {
            return __( 'Hou.la a rencontré une erreur. Le remboursement a peut-être été fait : ne le refaites pas tout de suite. S\'il a abouti, il apparaîtra ici de lui-même dans quelques minutes.', 'wp-houla' ) . $studio;
        }
        if ( 404 === $status ) {
            return __( 'Hou.la ne permet pas encore ce remboursement depuis WooCommerce : remboursez depuis le studio.', 'wp-houla' ) . $nothing . $studio;
        }
        $message = trim( (string) $error->get_error_message() );
        return sprintf(
            /* translators: %s: Hou.la error message */
            __( 'Hou.la a refusé le remboursement : %s.', 'wp-houla' ),
            '' !== $message ? $message : 'HTTP ' . $status
        ) . $nothing . $studio;
    }

    /**
     * Issue inconnue : Hou.la a pu rembourser (pas de réponse, réponse
     * inattendue, panne 5xx, ou vérification en cours chez Stripe).
     */
    public function is_uncertain( $error ) {
        $data   = $error->get_error_data();
        $code   = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : '';
        $status = is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 0;
        if ( in_array( $error->get_error_code(), array( 'wphoula_order_busy', 'wphoula_not_connected', 'wphoula_unauthorized', 'wphoula_rate_limited' ), true ) ) {
            return false;
        }
        if ( in_array( $code, array( 'SHOP_REFUND_PENDING_VERIFICATION', 'SHOP_REFUND_PARTIALLY_EXECUTED' ), true ) ) {
            return true;
        }
        if ( 'wphoula_api_error' !== $error->get_error_code() && 0 === $status ) {
            return true;
        }
        return $status >= 500 && '' === $code;
    }

    private function error( $message ) {
        return new WP_Error( 'wphoula_refund_failed', $message );
    }

    private function format_amount( $amount, $order ) {
        $currency = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : 'EUR';
        $symbol   = ( '' === $currency || 'EUR' === strtoupper( $currency ) ) ? '€' : $currency;
        return number_format( (float) $amount, 2, ',', ' ' ) . ' ' . $symbol;
    }

    private function log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WP-Houla Pay] ' . $message );
        }
    }
}
