<?php
/**
 * Passerelle « Hou.la Pay » (Trello #324).
 *
 * Les commandes Hou.la importées portent le moyen de paiement `houla_pay`
 * (Wp_Houla_Orders). Déclarer une passerelle sous ce nom suffit pour que
 * WooCommerce affiche, dans la commande, son bouton natif
 * « Rembourser X € via Hou.la Pay », comme pour Stripe ou PayPal.
 *
 * Elle n'est JAMAIS proposée au paiement sur le site (`is_available()` faux) :
 * la cliente a payé sur Hou.la. Elle ne sait que rembourser, par
 * Wp_Houla_Pay_Refunds.
 *
 * Ce fichier n'est chargé que depuis le filtre `woocommerce_payment_gateways`,
 * quand la classe WC_Payment_Gateway existe.
 *
 * @since      1.6.12
 * @package    Wp_Houla
 * @subpackage Wp_Houla/includes
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Wp_Houla_Gateway extends WC_Payment_Gateway {

    public function __construct() {
        $this->id                 = Wp_Houla_Pay_Refunds::GATEWAY_ID;
        $this->method_title       = 'Hou.la Pay';
        $this->title              = 'Hou.la Pay';
        $this->method_description = __( 'Commandes payées sur Hou.la (Stripe). Ce moyen de paiement n\'est jamais proposé sur votre site : il sert uniquement à rembourser une commande Hou.la depuis WooCommerce, avec le bouton « Rembourser via Hou.la Pay ». L\'argent repart vers la carte de votre cliente.', 'wp-houla' );
        $this->has_fields         = false;
        $this->supports           = array( 'refunds' );
        $this->form_fields        = array();
        $this->enabled            = 'no';
    }

    /** Jamais proposée au paiement sur le site. */
    public function is_available() {
        return false;
    }

    /** Aucun paiement ne passe par ici. */
    public function process_payment( $order_id ) {
        return array( 'result' => 'failure' );
    }

    /**
     * Le bouton « Rembourser via Hou.la Pay » n'apparaît que sur une commande
     * venue de Hou.la, quand l'extension est connectée.
     *
     * @param WC_Order $order Commande WooCommerce.
     * @return bool
     */
    public function can_refund_order( $order ) {
        return Wp_Houla_Pay_Refunds::instance()->can_refund( $order );
    }

    /**
     * @param int        $order_id Commande WooCommerce.
     * @param float|null $amount   Montant à rembourser.
     * @param string     $reason   Motif saisi.
     * @return bool|WP_Error
     */
    public function process_refund( $order_id, $amount = null, $reason = '' ) {
        return Wp_Houla_Pay_Refunds::instance()->process( $order_id, $amount, $reason );
    }
}
