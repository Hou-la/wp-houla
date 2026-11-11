<?php
/**
 * Remboursement saisi dans WooCommerce sur une commande Hou.la (Trello #157).
 *
 * L'extension ne déclare aucune passerelle de paiement : sur une commande
 * payée sur Hou.la, WooCommerce ne propose que le remboursement « manuel »,
 * qui ne rend AUCUN argent à la cliente. Décision du propriétaire
 * (2026-11-11) : on ne l'ignore pas, on PRÉVIENT la vendeuse :
 *  - un encart dans la commande WooCommerce, avec le lien vers la commande
 *    dans le studio Hou.la, où le remboursement rend vraiment l'argent ;
 *  - une note de commande à chaque remboursement manuel ;
 *  - le remboursement est déclaré à Hou.la, qui le garde dans l'historique
 *    de la commande SANS déplacer d'argent ni toucher au stock (WooCommerce
 *    gère sa remise en stock, qui se synchronise comme tout changement de
 *    stock).
 *
 * Seul un compte qui a la capacité WooCommerce de gestion des commandes
 * (`edit_shop_orders`) voit l'encart et déclare le remboursement à Hou.la.
 *
 * @since      1.6.11
 * @package    Wp_Houla
 * @subpackage Wp_Houla/includes
 */

class Wp_Houla_Refund_Notice {

    /** Remboursements manuels signalés : liste de { wc_refund_id, amount, currency, at }. */
    const WARNINGS_META = '_houla_refund_warnings';

    /** Adresse de la commande dans le studio Hou.la (donnée par l'API). */
    const STUDIO_URL_META = '_houla_studio_order_url';

    /** Repli quand l'API n'a pas (encore) donné l'adresse. */
    const STUDIO_BASE = 'https://hou.la/studio/shop/orders/';

    /** Capacité WooCommerce de gestion des commandes. */
    const CAPABILITY = 'edit_shop_orders';

    /**
     * Vrai pendant qu'un remboursement ENVOYÉ PAR Hou.la (`order.refunded`)
     * est appliqué : ni la note, ni la déclaration, ni le statut ne repartent
     * vers Hou.la. `wc_create_refund()` déclenche les crochets avant que le
     * remboursement porte `_houla_refund_id` : ce drapeau est la seule
     * marque disponible à cet instant.
     *
     * @var bool
     */
    public static $applying_houla_refund = false;

    /** @var Wp_Houla_Api */
    private $api;

    /** @var Wp_Houla_Auth */
    private $auth;

    public function __construct( $api = null, $auth = null ) {
        $this->api  = $api ? $api : new Wp_Houla_Api();
        $this->auth = $auth ? $auth : new Wp_Houla_Auth();
    }

    // =====================================================================
    // Crochet : woocommerce_order_refunded
    // =====================================================================

    /**
     * @param int $order_id  Commande WooCommerce.
     * @param int $refund_id Remboursement WooCommerce créé.
     */
    public function on_order_refunded( $order_id, $refund_id ) {
        if ( self::$applying_houla_refund ) {
            return;
        }
        if ( ! $this->auth->is_connected() ) {
            return;
        }
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        $houla_order_id = (string) $order->get_meta( '_houla_order_id' );
        if ( '' === $houla_order_id ) {
            return;
        }
        $refund = $refund_id ? wc_get_order( $refund_id ) : null;
        // Remboursement venu de Hou.la (rejoué hors du drapeau) : rien à signaler.
        if ( $refund && '' !== (string) $refund->get_meta( '_houla_refund_id' ) ) {
            return;
        }
        $amount = $refund ? abs( (float) $refund->get_amount() ) : 0.0;

        $info = null;
        if ( current_user_can( self::CAPABILITY ) ) {
            $result = $this->api->post(
                '/ecommerce/orders/' . rawurlencode( $houla_order_id ) . '/refund-declaration',
                array(
                    'wc_refund_id'            => (int) $refund_id,
                    'amount_cents'            => (int) round( $amount * 100 ),
                    'wc_total_refunded_cents' => (int) round( (float) $order->get_total_refunded() * 100 ),
                )
            );
            if ( ! is_wp_error( $result ) && isset( $result['refund'] ) && is_array( $result['refund'] ) ) {
                $info = $result['refund'];
            }
        } else {
            $order->add_order_note( __( 'Remboursement saisi par un compte sans droit de gestion des commandes : il n\'a pas été transmis à Hou.la.', 'wp-houla' ) );
        }

        // Hou.la a déjà rendu au moins ce que WooCommerce compte : la cliente
        // a son argent, ce remboursement ne fait que l'inscrire ici.
        if ( $info && ! empty( $info['money_moved'] ) ) {
            $order->add_order_note( sprintf(
                /* translators: 1: refund amount, 2: amount refunded by Hou.la */
                __( 'Remboursement de %1$s saisi dans WooCommerce : Hou.la a déjà rendu %2$s à la cliente.', 'wp-houla' ),
                $this->format_amount( $amount, $order ),
                $this->format_amount( ( isset( $info['houla_refunded_cents'] ) ? (int) $info['houla_refunded_cents'] : 0 ) / 100, $order )
            ) );
            $order->save();
            return;
        }

        $this->warn( $order, $amount, $info, (int) $refund_id );
    }

    /**
     * Commande passée à « Remboursée » dans WooCommerce SANS remboursement
     * saisi (statut changé à la main) : la réponse de Hou.la dit qu'aucun
     * argent n'a été rendu. Les remboursements saisis passent par
     * `on_order_refunded`, qui les signale déjà.
     *
     * @param WC_Order   $order Commande WooCommerce.
     * @param array|null $info  Bloc `refund` de la réponse de Hou.la.
     */
    public function on_refunded_status_without_money( $order, $info ) {
        if ( self::$applying_houla_refund || ! is_array( $info ) || ! empty( $info['money_moved'] ) ) {
            return;
        }
        if ( $this->has_manual_refund( $order ) ) {
            return;
        }
        $this->warn( $order, 0.0, $info, 0 );
    }

    // =====================================================================
    // Encart dans la commande WooCommerce
    // =====================================================================

    /**
     * HTML de l'encart : vide pour une commande qui ne vient pas de Hou.la,
     * ou pour un compte qui ne gère pas les commandes.
     *
     * @param WC_Order $order Commande WooCommerce.
     * @return string
     */
    public function render( $order ) {
        if ( ! $order || '' === (string) $order->get_meta( '_houla_order_id' ) ) {
            return '';
        }
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return '';
        }
        $url      = $this->studio_url( $order );
        $warnings = $this->warnings( $order );

        $html  = '<div class="wphoula-refund-notice' . ( $warnings ? ' wphoula-refund-notice--warning' : '' ) . '"';
        $html .= ' style="margin:8px 0;padding:8px;border-left:4px solid ' . ( $warnings ? '#d63638' : '#2271b1' ) . ';background:#f6f7f7;">';
        if ( $warnings ) {
            $html .= '<p><strong>' . esc_html__( 'Remboursement sans argent rendu', 'wp-houla' ) . '</strong></p>';
            foreach ( $warnings as $w ) {
                $amount = isset( $w['amount'] ) ? (float) $w['amount'] : 0.0;
                $html  .= '<p>' . esc_html( $amount > 0
                    ? sprintf(
                        /* translators: 1: refund amount, 2: date */
                        __( '%1$s remboursés dans WooCommerce le %2$s : votre cliente n\'a rien reçu.', 'wp-houla' ),
                        $this->format_amount( $amount, $order ),
                        isset( $w['at'] ) ? $w['at'] : ''
                    )
                    : sprintf(
                        /* translators: %s: date */
                        __( 'Commande passée à « Remboursée » dans WooCommerce le %s : votre cliente n\'a rien reçu.', 'wp-houla' ),
                        isset( $w['at'] ) ? $w['at'] : ''
                    ) ) . '</p>';
            }
        }
        $html .= '<p>' . esc_html__( 'Commande payée sur Hou.la : un remboursement saisi ici ne rend pas d\'argent à votre cliente.', 'wp-houla' ) . '</p>';
        $html .= '<p><a class="button" target="_blank" rel="noopener noreferrer" href="' . esc_url( $url ) . '">'
            . esc_html__( 'Rembourser dans le studio Hou.la', 'wp-houla' ) . '</a></p>';
        $html .= '</div>';
        return $html;
    }

    /**
     * Adresse de la commande dans le studio : celle de l'API si elle est
     * sûre (https, domaine hou.la), sinon celle déjà gardée, sinon le repli.
     *
     * @param WC_Order   $order Commande WooCommerce.
     * @param array|null $info  Bloc `refund` de la réponse de Hou.la.
     * @return string
     */
    public function studio_url( $order, $info = null ) {
        $candidates = array();
        if ( is_array( $info ) && ! empty( $info['studio_order_url'] ) ) {
            $candidates[] = (string) $info['studio_order_url'];
        }
        $candidates[] = (string) $order->get_meta( self::STUDIO_URL_META );
        foreach ( $candidates as $url ) {
            if ( $this->is_houla_url( $url ) ) {
                return $url;
            }
        }
        return self::STUDIO_BASE . rawurlencode( (string) $order->get_meta( '_houla_order_id' ) );
    }

    // =====================================================================
    // Interne
    // =====================================================================

    private function warn( $order, $amount, $info, $wc_refund_id ) {
        $url      = $this->studio_url( $order, $info );
        $warnings = $this->warnings( $order );
        foreach ( $warnings as $w ) {
            if ( $wc_refund_id > 0 && isset( $w['wc_refund_id'] ) && (int) $w['wc_refund_id'] === $wc_refund_id ) {
                return; // déjà signalé
            }
        }
        $warnings[] = array(
            'wc_refund_id' => $wc_refund_id,
            'amount'       => $amount,
            'at'           => current_time( 'mysql' ),
        );
        $order->update_meta_data( self::WARNINGS_META, $warnings );
        $order->update_meta_data( self::STUDIO_URL_META, $url );
        $order->add_order_note( $amount > 0
            ? sprintf(
                /* translators: 1: refund amount, 2: studio URL */
                __( 'Attention : ce remboursement de %1$s a été saisi dans WooCommerce. Il ne rend AUCUN argent à votre cliente, car la commande a été payée sur Hou.la. Pour lui rendre l\'argent, remboursez la commande dans le studio Hou.la : %2$s', 'wp-houla' ),
                $this->format_amount( $amount, $order ),
                $url
            )
            : sprintf(
                /* translators: %s: studio URL */
                __( 'Attention : la commande est passée à « Remboursée » dans WooCommerce, mais aucun argent n\'a été rendu à votre cliente, car la commande a été payée sur Hou.la. Pour lui rendre l\'argent, remboursez la commande dans le studio Hou.la : %s', 'wp-houla' ),
                $url
            ) );
        $order->save();
    }

    private function warnings( $order ) {
        $raw = $order->get_meta( self::WARNINGS_META );
        return is_array( $raw ) ? array_values( $raw ) : array();
    }

    private function has_manual_refund( $order ) {
        if ( ! method_exists( $order, 'get_refunds' ) ) {
            return false;
        }
        foreach ( $order->get_refunds() as $refund ) {
            if ( '' === (string) $refund->get_meta( '_houla_refund_id' ) ) {
                return true;
            }
        }
        return false;
    }

    private function is_houla_url( $url ) {
        if ( ! is_string( $url ) || 0 !== strpos( $url, 'https://' ) ) {
            return false;
        }
        $host = parse_url( $url, PHP_URL_HOST );
        return is_string( $host ) && ( 'hou.la' === $host || '.hou.la' === substr( $host, -7 ) );
    }

    private function format_amount( $amount, $order ) {
        $currency = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : 'EUR';
        $symbol   = ( '' === $currency || 'EUR' === $currency ) ? '€' : $currency;
        return number_format( (float) $amount, 2, ',', ' ' ) . ' ' . $symbol;
    }
}
