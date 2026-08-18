<?php
/**
 * Mises à jour du plugin depuis les releases GitHub.
 *
 * Le plugin n'est pas sur wordpress.org : sans ce module, WordPress n'a AUCUN
 * moyen de savoir qu'une version existe, et une marchande reste indéfiniment
 * sur son ancienne version. Un correctif livré ici ne l'atteint donc jamais,
 * ce qui est précisément la raison pour laquelle la garde équivalente vit AUSSI
 * côté serveur : ce module la complète, il ne la remplace pas.
 *
 * Se branche sur les points d'extension standards de WordPress : la mise à jour
 * se fait depuis Extensions puis Mises à jour, comme n'importe quel plugin,
 * sans rien installer d'autre.
 *
 * Le dépôt est PUBLIC : aucun jeton n'est nécessaire. On vise l'asset au nom
 * STABLE `wp-houla.zip` (et non `wp-houla-1.6.2.zip`), dont l'archive contient
 * déjà un dossier racine `wp-houla/`, ce que WordPress exige pour remplacer le
 * dossier en place.
 *
 * @package WP_Houla
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WP_Houla_Updater {

    /** Dépôt GitHub public hébergeant les releases. */
    const REPO = 'Hou-la/wp-houla';

    /** Asset au nom STABLE, indépendant du numéro de version. */
    const ASSET = 'wp-houla.zip';

    /** Clé du cache de la réponse GitHub. */
    const TRANSIENT = 'wphoula_latest_release';

    /**
     * Durée du cache.
     *
     * L'API GitHub non authentifiée est limitée à 60 requêtes par heure et par
     * IP. WordPress interroge les mises à jour très souvent : sans ce cache,
     * une boutique un peu active épuiserait le quota et n'aurait plus aucune
     * information de mise à jour, y compris pour ses autres plugins.
     */
    const CACHE_TTL = 21600; // 6 h

    /** @var string Chemin du plugin, ex. wp-houla/wp-houla.php */
    private $basename;

    /** @var string Version installée. */
    private $version;

    public function __construct( $basename, $version ) {
        $this->basename = $basename;
        $this->version  = $version;
    }

    /** Branche les filtres. Appelé par le chargeur du plugin. */
    public function register() {
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
        add_filter( 'plugins_api', array( $this, 'plugin_details' ), 10, 3 );
        add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
        add_action( 'upgrader_process_complete', array( $this, 'flush_cache' ), 10, 0 );
    }

    /**
     * Dernière release publiée, ou null.
     *
     * Ne lève jamais : une panne de GitHub ne doit pas empêcher l'écran des
     * extensions de s'afficher.
     *
     * @return array|null
     */
    private function get_latest_release() {
        $cached = get_transient( self::TRANSIENT );
        if ( is_array( $cached ) ) {
            return $cached;
        }
        // Un échec est mémorisé brièvement sous forme de chaîne, pour ne pas
        // marteler GitHub à chaque écran d'admin quand il est indisponible.
        if ( 'none' === $cached ) {
            return null;
        }

        $response = wp_remote_get(
            'https://api.github.com/repos/' . self::REPO . '/releases/latest',
            array(
                'timeout' => 10,
                'headers' => array(
                    'Accept' => 'application/vnd.github+json',
                    // GitHub refuse les requêtes sans User-Agent.
                    'User-Agent' => 'wp-houla/' . $this->version,
                ),
            )
        );

        if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
            set_transient( self::TRANSIENT, 'none', 900 );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
            set_transient( self::TRANSIENT, 'none', 900 );
            return null;
        }

        // Une pré-release ou un brouillon ne doit jamais être proposé à une
        // boutique en production.
        if ( ! empty( $body['prerelease'] ) || ! empty( $body['draft'] ) ) {
            set_transient( self::TRANSIENT, 'none', self::CACHE_TTL );
            return null;
        }

        $zip_url = '';
        if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
            foreach ( $body['assets'] as $asset ) {
                if ( isset( $asset['name'] ) && self::ASSET === $asset['name'] ) {
                    $zip_url = isset( $asset['browser_download_url'] ) ? $asset['browser_download_url'] : '';
                    break;
                }
            }
        }

        // Sans cet asset, on n'installe RIEN. Le tarball généré automatiquement
        // par GitHub a un dossier racine horodaté du genre
        // Hou-la-wp-houla-a1b2c3, qui renommerait le dossier du plugin et le
        // désactiverait au passage.
        if ( empty( $zip_url ) ) {
            set_transient( self::TRANSIENT, 'none', self::CACHE_TTL );
            return null;
        }

        $release = array(
            'version'      => ltrim( (string) $body['tag_name'], 'v' ),
            'zip_url'      => $zip_url,
            'changelog'    => isset( $body['body'] ) ? (string) $body['body'] : '',
            'published_at' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
            'html_url'     => isset( $body['html_url'] ) ? (string) $body['html_url'] : '',
        );
        set_transient( self::TRANSIENT, $release, self::CACHE_TTL );
        return $release;
    }

    /** Annonce la mise à jour à WordPress (pastille et écran Mises à jour). */
    public function inject_update( $transient ) {
        if ( ! is_object( $transient ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $transient;
        }
        if ( ! version_compare( $release['version'], $this->version, '>' ) ) {
            return $transient;
        }

        $transient->response[ $this->basename ] = (object) array(
            'id'          => self::REPO,
            'slug'        => dirname( $this->basename ),
            'plugin'      => $this->basename,
            'new_version' => $release['version'],
            'url'         => $release['html_url'],
            'package'     => $release['zip_url'],
            'tested'      => get_bloginfo( 'version' ),
            'icons'       => array(),
            'banners'     => array(),
        );

        return $transient;
    }

    /**
     * Alimente la fiche « Voir les détails ». Sans ça, WordPress affiche une
     * erreur en cliquant dessus pour un plugin hors wordpress.org.
     */
    public function plugin_details( $result, $action, $args ) {
        if ( 'plugin_information' !== $action ) {
            return $result;
        }
        if ( empty( $args->slug ) || dirname( $this->basename ) !== $args->slug ) {
            return $result;
        }

        $release = $this->get_latest_release();
        if ( ! $release ) {
            return $result;
        }

        return (object) array(
            'name'          => 'Hou.la',
            'slug'          => $args->slug,
            'version'       => $release['version'],
            'author'        => '<a href="https://hou.la">Hou.la</a>',
            'homepage'      => 'https://hou.la',
            'download_link' => $release['zip_url'],
            'last_updated'  => $release['published_at'],
            'sections'      => array(
                'changelog' => wpautop( esc_html( $release['changelog'] ) ),
            ),
        );
    }

    /**
     * Garantit que le dossier extrait s'appelle bien wp-houla.
     *
     * WordPress remplace le plugin par le dossier trouvé dans l'archive. Si son
     * nom diffère, l'ancien dossier reste en place et le plugin se retrouve
     * DÉSACTIVÉ après la mise à jour, avec deux copies sur le disque. Notre
     * archive est déjà correcte ; ce filet couvre le jour où une release serait
     * construite autrement.
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $args = array() ) {
        if ( empty( $args['plugin'] ) || $args['plugin'] !== $this->basename ) {
            return $source;
        }

        $expected = trailingslashit( $remote_source ) . dirname( $this->basename );
        if ( untrailingslashit( $source ) === untrailingslashit( $expected ) ) {
            return $source;
        }

        global $wp_filesystem;
        if ( ! $wp_filesystem || ! $wp_filesystem->move( $source, $expected ) ) {
            // On ne bloque pas la mise à jour : au pire le dossier garde son nom.
            return $source;
        }
        return trailingslashit( $expected );
    }

    /**
     * Vide le cache après une mise à jour, pour que la pastille disparaisse
     * tout de suite au lieu d'attendre l'expiration.
     */
    public function flush_cache() {
        delete_transient( self::TRANSIENT );
    }
}
