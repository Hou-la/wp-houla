<?php
/**
 * Empêche l'app WooCommerce d'annoncer une VIEILLE commande Hou.la comme une
 * nouvelle vente.
 *
 * Depuis WooCommerce 10.7, la boutique envoie elle-même la notification
 * « You have a new order! » de l'app mobile (Automattic\WooCommerce\Internal\
 * PushNotifications\Triggers\NewOrderNotificationTrigger). Elle part dès qu'une
 * commande passe d'un statut ABSENT de sa liste (processing, on-hold,
 * completed, pre-order, pre-ordered, partial-payment) à un statut PRÉSENT. La
 * liste est une constante, sans filtre.
 *
 * Or nos statuts d'acheminement n'y sont pas. Pour WooCommerce, une commande
 * « En cours de livraison (Hou.la) » qui passe à « Terminée » vient donc d'être
 * PAYÉE : le téléphone de la vendeuse sonne à la livraison, des semaines après
 * la vente. Constaté sur giamory.com le 2026-09-25 : depuis la mise en route de
 * ce mécanisme (17/09), 19 commandes Hou.la ont sonné, TOUTES plus de 72 h après
 * leur création (jusqu'à 56 jours), dont 17 exactement sur « En cours de
 * livraison (Hou.la) → Terminée ». Cas signalé : WC #42762, vendue le 16/08,
 * annoncée le 25/09 à la confirmation de livraison Chronopost.
 *
 * WooCommerce n'envoie JAMAIS deux fois la notification d'une commande : il
 * écrit une méta après l'envoi et s'arrête net si elle existe déjà
 * (NotificationProcessor::process). On pose cette même méta sur toute commande
 * qui entre dans un statut d'acheminement ou qui en sort : à ce stade elle est
 * payée, en route, et la vendeuse la connaît. Entrer couvre les commandes à
 * venir ; sortir couvre celles qui attendaient déjà en livraison avant ce
 * correctif.
 *
 * La clôture d'un panier ouvert (open-cart → processing) n'est PAS touchée :
 * c'est aujourd'hui le seul moment où une commande de live sonne.
 *
 * Si WooCommerce renomme un jour cette méta, la garde devient inerte (retour au
 * comportement d'avant, une sonnerie de trop) : elle ne casse rien.
 *
 * Vérifier en prod : une commande concernée porte la méta
 * `_houla_new_order_push_suppressed` (transition + date).
 *
 * @since      1.6.4
 * @package    Wp_Houla
 * @subpackage Wp_Houla/includes
 */

class Wp_Houla_New_Order_Push {

    /**
     * Statuts Hou.la de la phase d'acheminement (sans le préfixe « wc- »).
     */
    const FULFILMENT_STATUSES = array( 'houla-shipping', 'houla-returned' );

    /**
     * Classe WooCommerce (interne, >= 10.7) qui porte la clé « déjà envoyée ».
     */
    const WC_PROCESSOR_CLASS = 'Automattic\\WooCommerce\\Internal\\PushNotifications\\Services\\NotificationProcessor';

    /**
     * Méta de traçabilité propre au plugin : quelle transition a neutralisé la
     * notification, et quand.
     */
    const SUPPRESSED_META = '_houla_new_order_push_suppressed';

    /** @var string */
    private $processor_class;

    /**
     * @param string $processor_class Classe portant SENT_META_KEY (injectable pour les tests).
     */
    public function __construct( $processor_class = self::WC_PROCESSOR_CLASS ) {
        $this->processor_class = $processor_class;
    }

    /**
     * Branché sur `woocommerce_order_status_changed` AVANT le déclencheur de
     * WooCommerce (priorité 10).
     *
     * @param int      $order_id   WooCommerce order ID.
     * @param string   $old_status Old status (without 'wc-' prefix).
     * @param string   $new_status New status (without 'wc-' prefix).
     * @param WC_Order $order      Order object.
     */
    public function on_order_status_changed( $order_id, $old_status, $new_status, $order ) {
        $old = preg_replace( '/^wc-/', '', (string) $old_status );
        $new = preg_replace( '/^wc-/', '', (string) $new_status );

        if ( ! in_array( $old, self::FULFILMENT_STATUSES, true )
            && ! in_array( $new, self::FULFILMENT_STATUSES, true ) ) {
            return;
        }

        $sent_key = $this->sent_meta_key();
        if ( null === $sent_key || ! is_object( $order ) ) {
            // WooCommerce < 10.7 : la boutique n'envoie rien, rien à empêcher.
            return;
        }

        if ( $order->meta_exists( $sent_key ) ) {
            return;
        }

        $order->update_meta_data( $sent_key, (string) time() );
        $order->update_meta_data( self::SUPPRESSED_META, $old . ' -> ' . $new . ' @ ' . gmdate( 'c' ) );
        $order->save_meta_data();
    }

    /**
     * Clé de la méta « notification déjà envoyée » de WooCommerce, ou null si
     * cette version de WooCommerce n'a pas de notifications locales.
     *
     * @return string|null
     */
    private function sent_meta_key() {
        $constant = $this->processor_class . '::SENT_META_KEY';
        if ( ! class_exists( $this->processor_class ) || ! defined( $constant ) ) {
            return null;
        }
        return constant( $constant );
    }
}
