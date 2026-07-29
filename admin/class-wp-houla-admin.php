<?php
/**
 * Admin area: menu page, settings, styles, scripts.
 *
 * @since      1.0.0
 * @package    Wp_Houla
 * @subpackage Wp_Houla/admin
 */

class Wp_Houla_Admin {

    /** @var Wp_Houla_Options */
    private $options;

    /** @var Wp_Houla_Auth */
    private $auth;

    /** @var string Plugin version for cache busting. */
    private $version;

    public function __construct() {
        $this->options = new Wp_Houla_Options();
        $this->auth    = new Wp_Houla_Auth();
        $this->version = WPHOULA_VERSION;
    }

    // =====================================================================
    // Enqueue
    // =====================================================================

    /**
     * Enqueue admin CSS.
     *
     * @param string $hook Current admin page.
     */
    public function enqueue_styles( $hook ) {
        // Load on our settings page + any post edit screen + dashboard + post list + WC orders (HPOS)
        if ( $this->is_our_page( $hook ) || $this->is_post_edit( $hook ) || 'index.php' === $hook || 'edit.php' === $hook || $this->is_wc_orders_page( $hook ) ) {
            wp_enqueue_style(
                'wp-houla-admin',
                WPHOULA_URL . 'admin/css/wp-houla-admin.css',
                array(),
                $this->version,
                'all'
            );
        }
    }

    /**
     * Enqueue admin JS.
     *
     * @param string $hook Current admin page.
     */
    public function enqueue_scripts( $hook ) {
        if ( $this->is_our_page( $hook ) || $this->is_post_edit( $hook ) || 'index.php' === $hook || $this->is_wc_orders_page( $hook ) ) {
            wp_enqueue_script(
                'wp-houla-admin',
                WPHOULA_URL . 'admin/js/wp-houla-admin.js',
                array( 'jquery' ),
                $this->version,
                true
            );

            wp_localize_script( 'wp-houla-admin', 'wphoulaAdmin', array(
                'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
                'nonce'           => wp_create_nonce( 'wphoula_admin' ),
                'metaboxNonce'    => wp_create_nonce( 'wphoula_metabox' ),
                'isConnected'     => $this->auth->is_connected(),
                'currencySymbol'  => function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : 'EUR',
                'i18n'            => array(
                    'syncing'     => __( 'Syncing...', 'wp-houla' ),
                    'synced'      => __( 'Synced', 'wp-houla' ),
                    'syncError'   => __( 'Sync failed', 'wp-houla' ),
                    'confirm'     => __( 'Are you sure?', 'wp-houla' ),
                    'loading'     => __( 'Loading...', 'wp-houla' ),
                    'disconnect'  => __( 'Disconnect', 'wp-houla' ),
                    'disconnecting' => __( 'Disconnecting...', 'wp-houla' ),
                    'saveSettings'  => __( 'Save Settings', 'wp-houla' ),
                    'creatingCollections' => __( 'Creating collections...', 'wp-houla' ),
                    'collectionsCreated'  => __( ' collections created, ', 'wp-houla' ),
                    'collectionsMapped'   => __( ' mapped.', 'wp-houla' ),
                    'error'               => __( 'Error', 'wp-houla' ),
                    'networkError'        => __( 'Network error', 'wp-houla' ),
                    'shopNotActive'       => __( 'Your Hou.la shop is not activated. Please connect Stripe on Hou.la first.', 'wp-houla' ),
                    'confirmResetSync'    => __( 'This will clear all sync metadata. You will need to re-sync all products. Continue?', 'wp-houla' ),
                    'resetSync'           => __( 'Reset Sync Data', 'wp-houla' ),
                    'totalOrders'         => __( 'Total Hou.la orders', 'wp-houla' ),
                    'syncedOrders'        => __( 'Synced', 'wp-houla' ),
                    'failedOrders'        => __( 'Failed', 'wp-houla' ),
                    'pendingOrders'       => __( 'Pending', 'wp-houla' ),
                    'pulling'             => __( 'Pulling orders from Hou.la...', 'wp-houla' ),
                    'xSynced'             => __( '%d synced', 'wp-houla' ),
                    'xFailed'             => __( '%d failed', 'wp-houla' ),
                    'xSkipped'            => __( '%d skipped', 'wp-houla' ),
                    'xTotal'              => __( '(%d total)', 'wp-houla' ),
                    'errorPrefix'         => __( 'Error:', 'wp-houla' ),
                    'loadingWorkspaces'   => __( 'Chargement des espaces…', 'wp-houla' ),
                    'onlyOneWorkspace'    => __( 'Vous n\'avez qu\'un seul espace de travail.', 'wp-houla' ),
                    'switchingWorkspace'  => __( 'Changement en cours…', 'wp-houla' ),
                    'provisioningKey'     => __( 'Provisionnement de la clé API…', 'wp-houla' ),
                    'keyProvisioned'      => __( 'Clé API provisionnée avec succès.', 'wp-houla' ),
                    'savingWorkspaceMap'  => __( 'Enregistrement du mapping…', 'wp-houla' ),
                    'workspaceMapSaved'   => __( 'Mapping multi-workspace enregistré.', 'wp-houla' ),
                    'selectWorkspace'     => __( 'Sélectionnez un espace', 'wp-houla' ),
                    'noMapping'           => __( '— Workspace par défaut —', 'wp-houla' ),
                    'removeMapping'       => __( 'Retirer', 'wp-houla' ),
                ),
            ) );
        }
    }

    // =====================================================================
    // Menu
    // =====================================================================

    /**
     * Register the settings page in the admin menu.
     */
    public function add_menu_page() {
        // Base64-encoded Hou.la logo for the admin menu icon.
        $icon_svg = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 466 502" fill="none">'
            . '<path fill-rule="evenodd" fill="black" d="M425.746,225.447 L382.137,276.875 L318.859,223.219 L365.715,167.961 C390.969,138.18 387.887,115.985 367.04,98.308 C346.193,80.63 321.114,78.361 295.861,108.142 L234.489,180.519 C209.235,210.3 214.812,235.568 235.659,253.245 C244.437,260.689 253.656,264.685 263.271,264.613 L209.085,328.514 C200.384,323.451 191.705,317.28 183.083,309.969 C118.755,255.421 115.937,187.502 172.505,120.792 L226.807,56.754 C283.374,-9.956 350.841,-18.277 415.168,36.27 C479.496,90.818 482.314,158.737 425.746,225.447 ZM140.185,293.353 L105.117,335.382 C79.391,365.721 83.478,387.962 104.716,405.971 C125.953,423.979 148.563,424.376 174.289,394.038 L310.804,233.046 L373.908,286.556 L240.996,443.3 C183.369,511.259 116.46,521.28 50.928,465.711 C-14.603,410.143 -15.653,342.496 41.973,274.537 L124.624,177.718 C94.04,237.107 140.185,293.353 140.185,293.353 ZM442.239,406.313 C464.678,425.341 467.444,458.957 448.416,481.397 C429.388,503.836 395.772,506.602 373.333,487.574 C350.893,468.546 348.127,434.93 367.155,412.49 C386.183,390.051 419.799,387.285 442.239,406.313 Z"/>'
            . '</svg>'
        );

        if ( wphoula_is_woocommerce_active() ) {
            // WooCommerce present: add under Marketing menu
            add_submenu_page(
                'woocommerce-marketing',
                __( 'Hou.la Settings', 'wp-houla' ),
                __( 'Hou.la', 'wp-houla' ),
                'manage_woocommerce',
                'wp-houla',
                array( $this, 'render_settings_page' )
            );
        } else {
            // Standalone mode: top-level menu with custom icon
            add_menu_page(
                __( 'Hou.la Settings', 'wp-houla' ),
                __( 'Hou.la', 'wp-houla' ),
                'manage_options',
                'wp-houla',
                array( $this, 'render_settings_page' ),
                $icon_svg,
                81 // Position after Settings (80)
            );
        }
    }

    /**
     * Add a "Settings" link in the plugin list.
     *
     * @param array $links Existing action links.
     * @return array
     */
    public function add_action_links( $links ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'admin.php?page=wp-houla' ),
            __( 'Settings', 'wp-houla' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    // =====================================================================
    // Settings notice
    // =====================================================================

    /**
     * Display admin notice if not connected.
     */
    public function display_settings_notice() {
        if ( $this->auth->is_connected() ) {
            return;
        }

        $screen = get_current_screen();
        $allowed_screens = array( 'plugins', 'woocommerce_page_wp-houla', 'settings_page_wp-houla', 'toplevel_page_wp-houla' );
        if ( ! $screen || ! in_array( $screen->id, $allowed_screens, true ) ) {
            return;
        }

        printf(
            '<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
            esc_html__( 'WP-Houla: Connect your Hou.la account to enable short links and QR codes.', 'wp-houla' ),
            esc_url( admin_url( 'admin.php?page=wp-houla' ) ),
            esc_html__( 'Connect now', 'wp-houla' )
        );
    }

    // =====================================================================
    // AJAX handlers
    // =====================================================================

    /**
     * AJAX: Disconnect from Hou.la.
     */
    public function ajax_disconnect() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $this->auth->disconnect();

        wp_send_json_success( array( 'message' => __( 'Disconnected from Hou.la.', 'wp-houla' ) ) );
    }

    /**
     * AJAX: List user's workspaces from the Hou.la API.
     */
    public function ajax_get_workspaces() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $api    = new Wp_Houla_Api();
        // /workspaces is JWT-only on the API — force the OAuth Bearer (the persistent
        // API key is workspace-scoped and would be rejected with 401, surfacing a false
        // "Echec de l'authentification" while orders sync fine via the API key).
        $result = $api->get( '/workspaces', array(), array( 'use_access_token' => true ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( 'API error: ' . $result->get_error_message() );
        }

        if ( ! is_array( $result ) ) {
            wp_send_json_error( 'Unexpected response type: ' . gettype( $result ) );
        }

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        $current_ws_id = $this->options->get( 'workspace_id' );

        $workspaces = array();
        if ( is_array( $result ) ) {
            foreach ( $result as $ws ) {
                $workspaces[] = array(
                    'id'        => $ws['id'] ?? '',
                    'name'      => $ws['name'] ?? '',
                    'type'      => $ws['type'] ?? 'personal',
                    'plan'      => $ws['plan'] ?? 'free',
                    'hasShop'   => ! empty( $ws['hasShop'] ),
                    'isCurrent' => ( ( $ws['id'] ?? '' ) === $current_ws_id ),
                );
            }
        }

        // Persist the current workspace's hasShop flag for PHP template rendering
        foreach ( $workspaces as $ws ) {
            if ( $ws['isCurrent'] ) {
                $this->options->set( 'workspace_has_shop', $ws['hasShop'] );
                break;
            }
        }

        wp_send_json_success( array( 'workspaces' => $workspaces ) );
    }

    /**
     * AJAX: Switch to a different workspace.
     *
     * Calls POST /api/oauth/switch-workspace on the Hou.la API via the
     * Wp_Houla_Api class (uses X-Api-Key), stores the new tokens + workspace
     * info, re-provisions the API key, and resets sync metadata.
     */
    public function ajax_switch_workspace() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $workspace_id = isset( $_POST['workspace_id'] ) ? sanitize_text_field( wp_unslash( $_POST['workspace_id'] ) ) : '';
        if ( empty( $workspace_id ) ) {
            wp_send_json_error( __( 'No workspace ID provided.', 'wp-houla' ) );
        }

        $api  = new Wp_Houla_Api();
        $body = $api->post( '/oauth/switch-workspace', array(
            'workspace_id' => $workspace_id,
        ) );

        if ( is_wp_error( $body ) ) {
            wp_send_json_error( $body->get_error_message() );
        }

        if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
            $msg = isset( $body['message'] ) ? $body['message'] : __( 'Réponse invalide de l\'API.', 'wp-houla' );
            wp_send_json_error( $msg );
        }

        // Store new tokens + workspace info
        $this->auth->store_tokens( $body );

        // Safety net: ensure workspace_id is stored from the known value,
        // even if the API response didn't include it.
        $this->options->set( 'workspace_id', $body['workspace_id'] ?? $workspace_id );
        if ( ! empty( $body['workspace_name'] ) ) {
            $this->options->set( 'workspace_name', $body['workspace_name'] );
        }

        // Clear old API key and re-provision for the new workspace
        $this->options->set( 'api_key', '' );
        $this->auth->provision_api_key();

        // Reset sync metadata (products are workspace-scoped)
        $this->options->set( 'products_synced', 0 );
        $this->options->set( 'last_full_sync', '' );

        // Persist hasShop flag sent by the JS (from the workspaces list)
        if ( isset( $_POST['has_shop'] ) ) {
            $this->options->set( 'workspace_has_shop', rest_sanitize_boolean( wp_unslash( $_POST['has_shop'] ) ) );
        }

        wp_send_json_success( array(
            'workspace_id'   => $body['workspace_id'] ?? $workspace_id,
            'workspace_name' => $body['workspace_name'] ?? '',
            'has_shop'       => $this->options->get( 'workspace_has_shop' ),
            'message'        => __( 'Espace de travail changé avec succès.', 'wp-houla' ),
        ) );
    }

    /**
     * AJAX: Trigger batch sync.
     */
    public function ajax_batch_sync() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $sync   = new Wp_Houla_Sync();
        $result = $sync->batch_sync();

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Count products to sync (for progress bar).
     */
    public function ajax_batch_sync_count() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $sync  = new Wp_Houla_Sync();
        $total = $sync->count_products_to_sync();

        wp_send_json_success( array( 'total' => $total ) );
    }

    /**
     * AJAX: Sync one page of products (for progress bar).
     */
    public function ajax_batch_sync_page() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $page = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;

        $sync   = new Wp_Houla_Sync();
        $result = $sync->batch_sync_page( $page );

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Start background sync — returns immediately, sync runs in a loopback request.
     */
    public function ajax_start_background_sync() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $sync  = new Wp_Houla_Sync();

        // Check if already running — prevent duplicate launches
        $status = get_transient( 'wphoula_bg_sync_status' );
        if ( $status && 'running' === $status['state'] ) {
            // Consider a sync "stale" if it has been running for more than 45 minutes (process likely died)
            $started = isset( $status['started'] ) ? (int) $status['started'] : 0;
            if ( $started && ( time() - $started ) < 2700 ) {
                // Still within valid window — refuse to start another
                wp_send_json_success( $status );
                return;
            }
            // Stale sync — mark as error and allow restart
            $status['state']         = 'error';
            $status['error_message'] = 'Sync timed out (stale process)';
            set_transient( 'wphoula_bg_sync_status', $status, HOUR_IN_SECONDS );
            delete_transient( 'wphoula_bg_sync_nonce' );
        }

        $total = $sync->count_products_to_sync();
        if ( 0 === $total ) {
            wp_send_json_success( array( 'state' => 'done', 'total' => 0, 'synced' => 0, 'errors' => 0 ) );
            return;
        }

        // Store initial state in transient (expires after 1 hour)
        $state = array(
            'state'   => 'running',
            'total'   => $total,
            'synced'  => 0,
            'errors'  => 0,
            'page'    => 1,
            'started' => time(),
        );
        set_transient( 'wphoula_bg_sync_status', $state, HOUR_IN_SECONDS );

        // Create a one-time nonce for the background processor
        $bg_nonce = wp_create_nonce( 'wphoula_bg_sync' );
        set_transient( 'wphoula_bg_sync_nonce', $bg_nonce, HOUR_IN_SECONDS );

        // Fire a non-blocking loopback request to process the first batch
        wp_remote_post( admin_url( 'admin-ajax.php' ), array(
            'blocking'  => false,
            'timeout'   => 0.01,
            'sslverify' => false,
            'body'      => array(
                'action' => 'wphoula_bg_sync_process',
                'nonce'  => $bg_nonce,
            ),
            'cookies'   => $_COOKIE,
        ) );

        wp_send_json_success( $state );
    }

    /**
     * AJAX: Background sync processor — called via non-blocking loopback.
     * Processes ALL remaining pages in a single long-running request.
     * Uses an exclusive file lock (flock) to guarantee only ONE instance runs at a time.
     */
    public function ajax_bg_sync_process() {
        // Verify the one-time background nonce
        $expected = get_transient( 'wphoula_bg_sync_nonce' );
        $received = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';
        if ( ! $expected || $received !== $expected ) {
            wp_send_json_error( 'Invalid background nonce' );
        }

        // Exclusive lock: only one sync process can run at a time
        $lock_file = sys_get_temp_dir() . '/wphoula_sync_' . md5( ABSPATH ) . '.lock';
        $lock_fp   = fopen( $lock_file, 'w' );
        if ( ! $lock_fp || ! flock( $lock_fp, LOCK_EX | LOCK_NB ) ) {
            // Another process already holds the lock — exit silently
            if ( $lock_fp ) {
                fclose( $lock_fp );
            }
            wp_send_json_error( 'Sync already running in another process' );
            return;
        }

        // Allow long execution — up to 30 min for large catalogs
        if ( function_exists( 'set_time_limit' ) ) {
            set_time_limit( 1800 );
        }
        ignore_user_abort( true );

        $status = get_transient( 'wphoula_bg_sync_status' );
        if ( ! $status || 'running' !== $status['state'] ) {
            flock( $lock_fp, LOCK_UN );
            fclose( $lock_fp );
            @unlink( $lock_file );
            wp_send_json_error( 'No sync in progress' );
        }

        $page = isset( $status['page'] ) ? (int) $status['page'] : 1;

        $sync = new Wp_Houla_Sync();

        // Register connection on first page
        if ( 1 === $page ) {
            $sync->register_connection();
        }

        // Process ALL pages in this single request
        $max_retries = 3;
        while ( true ) {
            // Check if cancelled
            $current_status = get_transient( 'wphoula_bg_sync_status' );
            if ( ! $current_status || 'running' !== $current_status['state'] ) {
                break;
            }

            // Retry logic per page
            $result = null;
            for ( $attempt = 1; $attempt <= $max_retries; $attempt++ ) {
                $result = $sync->batch_sync_page( $page );

                if ( empty( $result['connection_error'] ) ) {
                    break;
                }

                if ( $attempt < $max_retries ) {
                    sleep( $attempt * 5 ); // Backoff: 5s, 10s
                }
            }

            // Update running totals
            $status['synced'] += $result['synced'];
            $status['errors'] += $result['errors'];
            $status['page']    = $page;

            // Connection error after all retries — abort
            if ( ! empty( $result['connection_error'] ) ) {
                $status['state']         = 'error';
                $status['error_message'] = isset( $result['error_message'] ) ? $result['error_message'] : 'Connection error';
                set_transient( 'wphoula_bg_sync_status', $status, HOUR_IN_SECONDS );
                delete_transient( 'wphoula_bg_sync_nonce' );
                // Release the exclusive lock before returning
                flock( $lock_fp, LOCK_UN );
                fclose( $lock_fp );
                @unlink( $lock_file );
                wp_send_json_success( 'aborted at page ' . $page );
                return;
            }

            // Update transient so JS polling shows progress
            set_transient( 'wphoula_bg_sync_status', $status, HOUR_IN_SECONDS );

            if ( ! $result['has_more'] ) {
                break; // All pages done
            }

            $page++;

            // Small delay between pages to be gentle on the API
            usleep( 500000 ); // 500ms
        }

        // Finalize
        $status['state']    = 'done';
        $status['finished'] = time();
        set_transient( 'wphoula_bg_sync_status', $status, HOUR_IN_SECONDS );
        delete_transient( 'wphoula_bg_sync_nonce' );

        // Update plugin counters
        $options = new Wp_Houla_Options();
        $options->set( 'products_synced', $status['synced'] );
        $options->set( 'last_full_sync', current_time( 'mysql' ) );

        // Release the exclusive lock
        flock( $lock_fp, LOCK_UN );
        fclose( $lock_fp );
        @unlink( $lock_file );

        wp_send_json_success( 'completed: ' . $status['synced'] . ' synced' );
    }

    /**
     * AJAX: Get background sync status — called by JS polling.
     */
    public function ajax_get_sync_status() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $status = get_transient( 'wphoula_bg_sync_status' );
        if ( ! $status ) {
            wp_send_json_success( array( 'state' => 'idle' ) );
            return;
        }

        wp_send_json_success( $status );
    }

    /**
     * AJAX: Cancel a running background sync.
     */
    public function ajax_cancel_sync() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $status = get_transient( 'wphoula_bg_sync_status' );
        if ( $status && 'running' === $status['state'] ) {
            $status['state'] = 'cancelled';
            set_transient( 'wphoula_bg_sync_status', $status, HOUR_IN_SECONDS );
            delete_transient( 'wphoula_bg_sync_nonce' );
        }

        wp_send_json_success( $status );
    }

    /**
     * AJAX: Save settings.
     */
    public function ajax_save_settings() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $auto_sync       = ! empty( $_POST['auto_sync'] );
        $sync_on_publish = ! empty( $_POST['sync_on_publish'] );
        $debug           = ! empty( $_POST['debug'] );

        // Post types selection
        $allowed_post_types = array();
        if ( isset( $_POST['allowed_post_types'] ) && is_array( $_POST['allowed_post_types'] ) ) {
            $allowed_post_types = array_map( 'sanitize_key', $_POST['allowed_post_types'] );
        }

        // Category filter for product sync
        $sync_categories = array();
        if ( isset( $_POST['sync_categories'] ) && is_array( $_POST['sync_categories'] ) ) {
            $sync_categories = array_map( 'absint', $_POST['sync_categories'] );
        }

        // Custom API URL (only accepted in dev mode)
        $api_url = '';
        if ( isset( $_POST['api_url'] ) && function_exists( 'wphoula_is_dev_mode' ) && wphoula_is_dev_mode() ) {
            $api_url = esc_url_raw( trim( $_POST['api_url'] ) );
        }

        // Price adjustment
        $price_adj_type  = 'none';
        $price_adj_value = 0;
        if ( isset( $_POST['price_adjustment_type'] ) ) {
            $allowed_types = array( 'none', 'percent_up', 'percent_down', 'fixed_up', 'fixed_down' );
            $price_adj_type = sanitize_text_field( $_POST['price_adjustment_type'] );
            if ( ! in_array( $price_adj_type, $allowed_types, true ) ) {
                $price_adj_type = 'none';
            }
        }
        if ( isset( $_POST['price_adjustment_value'] ) ) {
            $price_adj_value = abs( floatval( $_POST['price_adjustment_value'] ) );
        }

        // Category -> Collection mapping
        $cat_collection_map = array();
        if ( isset( $_POST['category_collection_map'] ) && is_array( $_POST['category_collection_map'] ) ) {
            foreach ( $_POST['category_collection_map'] as $cat_id => $collection_id ) {
                $cat_id = absint( $cat_id );
                $collection_id = sanitize_text_field( $collection_id );
                if ( $cat_id > 0 && ! empty( $collection_id ) ) {
                    $cat_collection_map[ $cat_id ] = $collection_id;
                }
            }
        }

        // Order status concordance map (wc_slug => houla_status)
        $order_status_map = array();
        if ( isset( $_POST['order_status_map'] ) && is_array( $_POST['order_status_map'] ) ) {
            $valid_houla = array( 'pending', 'open_cart', 'paid', 'processing', 'shipped', 'delivered', 'cancelled', 'abandoned', 'refunded', 'partially_refunded' );
            foreach ( $_POST['order_status_map'] as $wc_slug => $houla_status ) {
                $wc_slug      = sanitize_text_field( $wc_slug );
                $houla_status = sanitize_key( $houla_status );
                if ( in_array( $houla_status, $valid_houla, true ) && ! empty( $wc_slug ) ) {
                    $order_status_map[ $wc_slug ] = $houla_status;
                }
            }
        }

        // Tracking sync
        $sync_tracking = ! empty( $_POST['sync_tracking'] );

        // Product identifiers meta mapping (gtin/isbn => WC meta_key)
        $identifier_meta_map = array( 'gtin' => '', 'isbn' => '' );
        if ( isset( $_POST['identifier_meta_map'] ) && is_array( $_POST['identifier_meta_map'] ) ) {
            $valid_ids = array( 'gtin', 'isbn' );
            foreach ( $_POST['identifier_meta_map'] as $id_key => $meta_key ) {
                $id_key   = sanitize_key( $id_key );
                $meta_key = sanitize_text_field( $meta_key );
                if ( in_array( $id_key, $valid_ids, true ) ) {
                    $identifier_meta_map[ $id_key ] = $meta_key;
                }
            }
        }

        $this->options->set_many( array(
            'auto_sync'              => $auto_sync,
            'sync_on_publish'        => $sync_on_publish,
            'debug'                  => $debug,
            'allowed_post_types'     => $allowed_post_types,
            'sync_categories'        => $sync_categories,
            'api_url'                => $api_url,
            'price_adjustment_type'  => $price_adj_type,
            'price_adjustment_value' => $price_adj_value,
            'category_collection_map' => $cat_collection_map,
            'order_status_map'       => $order_status_map,
            'sync_tracking'          => $sync_tracking,
            'identifier_meta_map'    => $identifier_meta_map,
        ) );

        wp_send_json_success( array( 'message' => __( 'Settings saved.', 'wp-houla' ) ) );
    }

    // =====================================================================
    // Multi-workspace mapping AJAX handlers
    // =====================================================================

    /**
     * AJAX: Save the category → workspace mapping.
     *
     * Receives an array of mappings: { cat_id => { workspace_id, workspace_name, api_key, price_adjustment_type, price_adjustment_value } }
     * API keys are stored encrypted.
     */
    public function ajax_save_workspace_map() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $raw_map = isset( $_POST['category_workspace_map'] ) ? $_POST['category_workspace_map'] : array();
        if ( ! is_array( $raw_map ) ) {
            $raw_map = array();
        }

        $clean_map = array();
        $allowed_adj_types = array( 'none', 'percent_up', 'percent_down', 'fixed_up', 'fixed_down' );

        foreach ( $raw_map as $cat_id => $entry ) {
            $cat_id = absint( $cat_id );
            if ( $cat_id <= 0 || ! is_array( $entry ) ) {
                continue;
            }

            $workspace_id = sanitize_text_field( $entry['workspace_id'] ?? '' );
            if ( empty( $workspace_id ) ) {
                continue;
            }

            $adj_type = sanitize_text_field( $entry['price_adjustment_type'] ?? 'none' );
            if ( ! in_array( $adj_type, $allowed_adj_types, true ) ) {
                $adj_type = 'none';
            }

            $clean_map[ $cat_id ] = array(
                'workspace_id'           => $workspace_id,
                'workspace_name'         => sanitize_text_field( $entry['workspace_name'] ?? '' ),
                'api_key'                => ! empty( $entry['api_key'] )
                                             ? Wp_Houla_Options::encrypt( sanitize_text_field( $entry['api_key'] ) )
                                             : '',
                'price_adjustment_type'  => $adj_type,
                'price_adjustment_value' => abs( floatval( $entry['price_adjustment_value'] ?? 0 ) ),
            );
        }

        $this->options->set( 'category_workspace_map', $clean_map );

        wp_send_json_success( array(
            'message' => __( 'Workspace mapping saved.', 'wp-houla' ),
            'count'   => count( $clean_map ),
        ) );
    }

    /**
     * AJAX: Provision an API key for a specific workspace.
     *
     * Uses the current OAuth token to create an internal API key
     * scoped to the given workspace_id.
     */
    public function ajax_provision_workspace_key() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $workspace_id   = isset( $_POST['workspace_id'] ) ? sanitize_text_field( wp_unslash( $_POST['workspace_id'] ) ) : '';
        $workspace_name = isset( $_POST['workspace_name'] ) ? sanitize_text_field( wp_unslash( $_POST['workspace_name'] ) ) : '';

        if ( empty( $workspace_id ) ) {
            wp_send_json_error( __( 'No workspace ID provided.', 'wp-houla' ) );
        }

        // Use OAuth token to provision a key for the target workspace
        $token = Wp_Houla_Options::decrypt( $this->options->get( 'access_token' ) );
        if ( empty( $token ) ) {
            wp_send_json_error( __( 'No access token available. Please reconnect.', 'wp-houla' ) );
        }

        $api_url   = function_exists( 'wphoula_get_api_url' ) ? wphoula_get_api_url() : WPHOULA_API_URL;
        $url       = $api_url . '/api/keys';
        $site_name = wp_parse_url( get_site_url(), PHP_URL_HOST );

        $response = wp_remote_post( $url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization'              => 'Bearer ' . $token,
                'Content-Type'               => 'application/json',
                'Accept'                     => 'application/json',
                'User-Agent'                 => 'wp-houla/' . WPHOULA_VERSION,
                'X-Workspace-Id'             => $workspace_id,
                'ngrok-skip-browser-warning' => 'true',
            ),
            'body' => wp_json_encode( array(
                'name' => 'WP-Houla Multi-WS — ' . $site_name,
                'type' => 'internal',
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ( 201 === $status || 200 === $status ) && ! empty( $body['key'] ) ) {
            wp_send_json_success( array(
                'api_key'        => $body['key'],
                'workspace_id'   => $workspace_id,
                'workspace_name' => $workspace_name,
                'message'        => __( 'API key provisioned.', 'wp-houla' ),
            ) );
        } else {
            $msg = isset( $body['message'] ) ? $body['message'] : 'HTTP ' . $status;
            wp_send_json_error( $msg );
        }
    }

    /**
     * AJAX: Discover product meta keys that may contain EAN/GTIN/ISBN values.
     * Scans postmeta for product post types, returns grouped results:
     * - known plugin meta keys (Yoast, GTIN for WC, etc.)
     * - all custom meta keys found on products (excluding internal WC/WP keys)
     */
    public function ajax_get_product_meta_keys() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        global $wpdb;

        // Known plugin meta keys for GTIN/EAN/ISBN
        $known_keys = array(
            // WooCommerce native (WC 9.2+)
            '_global_unique_id'                  => 'WooCommerce (GTIN/UPC/EAN/ISBN)',
            // Yoast SEO / WooCommerce SEO
            '_yoast_wpseo_global_identifier_gtin8'  => 'Yoast SEO (GTIN-8)',
            '_yoast_wpseo_global_identifier_gtin12' => 'Yoast SEO (GTIN-12/UPC)',
            '_yoast_wpseo_global_identifier_gtin13' => 'Yoast SEO (GTIN-13/EAN)',
            '_yoast_wpseo_global_identifier_gtin14' => 'Yoast SEO (GTIN-14)',
            '_yoast_wpseo_global_identifier_isbn'   => 'Yoast SEO (ISBN)',
            '_yoast_wpseo_global_identifier_mpn'    => 'Yoast SEO (MPN)',
            // GTIN (EAN, UPC, ISBN) for WooCommerce plugin (by Jeremias Jebbink)
            '_wpm_gtin_code'                     => 'Product GTIN for WooCommerce',
            // EAN for WooCommerce plugin (by WPFactory)
            '_alg_ean'                           => 'EAN for WooCommerce (WPFactory)',
            // WooCommerce UPC, EAN, ISBN plugin
            'hwp_product_gtin'                   => 'GTIN/UPC/EAN by Barn2',
            // Product GTIN for WC
            '_wc_gtin'                           => 'Product GTIN (WC)',
            // Germanized for WooCommerce
            '_ts_gtin'                           => 'Germanized (GTIN)',
            '_ts_mpn'                            => 'Germanized (MPN)',
        );

        // Query DB for which known keys actually exist
        $known_key_list = array_keys( $known_keys );
        $placeholders   = implode( ',', array_fill( 0, count( $known_key_list ), '%s' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $found_known = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT pm.meta_key
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type IN ('product', 'product_variation')
             AND pm.meta_key IN ({$placeholders})
             LIMIT 50",
            ...$known_key_list
        ) );

        $detected = array();
        foreach ( $found_known as $key ) {
            $detected[] = array(
                'key'   => $key,
                'label' => isset( $known_keys[ $key ] ) ? $known_keys[ $key ] : $key,
                'type'  => 'detected',
            );
        }

        // Also query for custom meta keys that might contain identifiers
        // Exclude internal WC/WP meta keys (start with _ but not known identifier keys)
        $custom_keys = $wpdb->get_col(
            "SELECT DISTINCT pm.meta_key
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE p.post_type IN ('product', 'product_variation')
             AND pm.meta_key NOT LIKE '\_%'
             AND pm.meta_key NOT IN ('total_sales')
             ORDER BY pm.meta_key ASC
             LIMIT 200"
        );

        $custom = array();
        foreach ( $custom_keys as $key ) {
            $custom[] = array(
                'key'   => $key,
                'label' => $key,
                'type'  => 'custom',
            );
        }

        // All known keys (even if not detected — for manual selection)
        $all_known = array();
        foreach ( $known_keys as $key => $label ) {
            if ( ! in_array( $key, $found_known, true ) ) {
                $all_known[] = array(
                    'key'   => $key,
                    'label' => $label . ' (' . __( 'not found', 'wp-houla' ) . ')',
                    'type'  => 'known',
                );
            }
        }

        wp_send_json_success( array(
            'detected' => $detected,
            'custom'   => $custom,
            'known'    => $all_known,
        ) );
    }

    /**
     * AJAX: Get synced products from Hou.la API (paginated).
     */
    public function ajax_get_synced_products() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $page     = isset( $_POST['page'] ) ? max( 1, intval( $_POST['page'] ) ) : 1;
        $per_page = isset( $_POST['per_page'] ) ? max( 1, min( 100, intval( $_POST['per_page'] ) ) ) : 20;
        $search   = isset( $_POST['search'] ) ? sanitize_text_field( wp_unslash( $_POST['search'] ) ) : '';

        $api    = new Wp_Houla_Api();
        $result = $api->get( '/ecommerce/products?platform=woocommerce' );

        if ( is_wp_error( $result ) ) {
            // Fallback: use local WP post_meta to show synced products
            $fallback = $this->get_synced_products_from_local( $page, $per_page, $search );
            if ( ! empty( $fallback['products'] ) ) {
                $fallback['api_error'] = $result->get_error_message();
                wp_send_json_success( $fallback );
            }
            wp_send_json_error( $result->get_error_message() );
        }

        // Enrich with WC product info
        $all_products = array();
        if ( is_array( $result ) ) {
            foreach ( $result as $product ) {
                $ext_id = isset( $product['externalProductId'] ) ? $product['externalProductId'] : '';
                $wc_price   = '';
                $wc_product = $ext_id ? wc_get_product( intval( $ext_id ) ) : null;
                if ( $wc_product ) {
                    $wc_price = $wc_product->get_price();
                }

                $price_cents  = isset( $product['priceCents'] ) ? intval( $product['priceCents'] ) : 0;
                $currency     = isset( $product['currency'] ) ? $product['currency'] : 'EUR';
                $houla_price  = $price_cents > 0 ? number_format( $price_cents / 100, 2, '.', '' ) . ' ' . $currency : '';
                $stock_qty    = isset( $product['stockQuantity'] ) ? $product['stockQuantity'] : null;
                $wc_currency  = function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ) : '';
                $wc_formatted = ( $wc_price !== '' && $wc_price !== null ) ? $wc_price . ' ' . $wc_currency : '';
                $last_synced  = isset( $product['lastSyncedAt'] ) ? $product['lastSyncedAt'] : null;

                // Format last synced date
                if ( $last_synced ) {
                    $ts = strtotime( $last_synced );
                    if ( $ts ) {
                        $last_synced = wp_date( 'd/m/Y H:i', $ts );
                    }
                }

                $all_products[] = array(
                    'id'          => $product['id'] ?? '',
                    'title'       => $product['title'] ?? '',
                    'externalId'  => $ext_id,
                    'wc_price'    => $wc_formatted,
                    'price'       => $houla_price,
                    'status'      => $product['status'] ?? 'draft',
                    'stock'       => $stock_qty,
                    'last_synced' => $last_synced,
                    'imageUrl'    => $product['imageUrl'] ?? null,
                );
            }
        }

        // Filter by search term (title or externalId)
        if ( $search !== '' ) {
            $search_lower = mb_strtolower( $search );
            $all_products = array_filter( $all_products, function( $p ) use ( $search_lower ) {
                return ( mb_strpos( mb_strtolower( $p['title'] ), $search_lower ) !== false )
                    || ( mb_strpos( mb_strtolower( $p['externalId'] ), $search_lower ) !== false );
            } );
            $all_products = array_values( $all_products );
        }

        // Pagination
        $total    = count( $all_products );
        $offset   = ( $page - 1 ) * $per_page;
        $products = array_slice( $all_products, $offset, $per_page );

        wp_send_json_success( array(
            'products' => $products,
            'total'    => $total,
            'page'     => $page,
            'per_page' => $per_page,
            'pages'    => (int) ceil( $total / $per_page ),
        ) );
    }

    /**
     * AJAX: Get collections from Hou.la API.
     */
    public function ajax_get_collections() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $api    = new Wp_Houla_Api();
        $result = $api->get( '/ecommerce/collections' );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( is_array( $result ) ? $result : array() );
    }

    /**
     * AJAX: Auto-create collections from WC categories.
     */
    public function ajax_auto_map_collections() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        if ( ! $this->auth->is_connected() ) {
            wp_send_json_error( __( 'Not connected to Hou.la. Please reconnect in the Connection tab.', 'wp-houla' ) );
        }

        // Get all WC product categories
        $product_cats = get_terms( array(
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ) );

        if ( is_wp_error( $product_cats ) || empty( $product_cats ) ) {
            wp_send_json_error( __( 'No WooCommerce categories found.', 'wp-houla' ) );
        }

        $categories = array();
        foreach ( $product_cats as $cat ) {
            $categories[] = array(
                'id'    => (string) $cat->term_id,
                'name'  => $cat->name,
                'count' => $cat->count,
            );
        }

        $api    = new Wp_Houla_Api();
        $result = $api->post( '/ecommerce/collections/auto-map', array(
            'categories' => $categories,
        ) );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        // Save the mapping to options
        $mapping = isset( $result['mapping'] ) ? $result['mapping'] : array();
        $cat_collection_map = array();
        $js_mapping         = array();
        foreach ( $mapping as $m ) {
            $cat_collection_map[ intval( $m['external_id'] ) ] = $m['collection_id'];
            $js_mapping[ $m['external_id'] ] = $m['collection_id'];
        }

        $this->options->set( 'category_collection_map', $cat_collection_map );

        wp_send_json_success( array(
            'mapping' => $js_mapping,
            'total'   => count( $mapping ),
            'created' => isset( $result['created'] ) ? $result['created'] : 0,
        ) );
    }

    // =====================================================================
    // Shop status check
    // =====================================================================

    /**
     * AJAX: Check if the Hou.la shop is activated (Stripe Connect active).
     */
    public function ajax_get_shop_status() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! $this->auth->is_connected() ) {
            wp_send_json_error( __( 'Not connected to Hou.la.', 'wp-houla' ) );
        }

        $api    = new Wp_Houla_Api();
        $result = $api->get( '/ecommerce/status' );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success( array(
            'shopActive'     => ! empty( $result['shopActive'] ),
            'stripeStatus'   => isset( $result['stripeStatus'] ) ? $result['stripeStatus'] : 'unknown',
            'chargesEnabled' => ! empty( $result['chargesEnabled'] ),
            'payoutsEnabled' => ! empty( $result['payoutsEnabled'] ),
        ) );
    }

    /**
     * AJAX: Reset all sync metadata.
     * Clears _wphoula_product_id, _wphoula_synced, _wphoula_sync_at from all products
     * AND deletes matching products on the Hou.la API (bulk-delete)
     * so the next batch sync will re-create them cleanly on Hou.la.
     */
    public function ajax_reset_sync() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        global $wpdb;

        // Step 1: Delete all synced products on Hou.la API
        $api_deleted = 0;
        $api_errors  = array();
        if ( $this->auth->is_connected() ) {
            $api    = new \Wp_Houla_Api();
            $result = $api->delete( '/ecommerce/products' );
            if ( is_wp_error( $result ) ) {
                $api_errors[] = $result->get_error_message();
            } elseif ( isset( $result['deleted'] ) ) {
                $api_deleted = (int) $result['deleted'];
            }
        }

        // Step 2: Clear local metadata
        $local_deleted = $wpdb->query(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_wphoula_product_id', '_wphoula_synced', '_wphoula_sync_at')"
        );

        // Reset counters
        $this->options->set( 'products_synced', 0 );
        $this->options->set( 'last_full_sync', '' );
        $this->options->set( 'category_collection_map', array() );

        // Delete batch sync transients
        delete_transient( 'wphoula_batch_sync_lock' );
        delete_transient( 'wphoula_batch_running_synced' );
        delete_transient( 'wphoula_batch_running_errors' );

        $message = sprintf(
            /* translators: %1$d: local entries, %2$d: API products deleted */
            __( 'Reset complete: %1$d local entries cleared, %2$d products deleted on Hou.la.', 'wp-houla' ),
            $local_deleted,
            $api_deleted
        );
        if ( ! empty( $api_errors ) ) {
            $message .= ' ' . sprintf(
                /* translators: %s: error messages */
                __( 'API errors: %s', 'wp-houla' ),
                implode( '; ', $api_errors )
            );
        }

        wp_send_json_success( array(
            'message'       => $message,
            'local_cleared' => $local_deleted,
            'api_deleted'   => $api_deleted,
            'api_errors'    => $api_errors,
        ) );
    }

    /**
     * AJAX: Resync a single order status to Hou.la.
     */
    public function ajax_resync_order() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
        if ( ! $order_id ) {
            wp_send_json_error( __( 'Invalid order ID.', 'wp-houla' ) );
        }

        $sync   = new Wp_Houla_Sync();
        $result = $sync->resync_order( $order_id );

        if ( $result['success'] ) {
            wp_send_json_success( array( 'message' => __( 'Order synced successfully.', 'wp-houla' ) ) );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * AJAX: Batch resync all Hou.la orders.
     */
    public function ajax_batch_resync_orders() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $filter = isset( $_POST['filter'] ) ? sanitize_text_field( $_POST['filter'] ) : 'all';
        if ( ! in_array( $filter, array( 'all', 'failed' ), true ) ) {
            $filter = 'all';
        }

        $sync   = new Wp_Houla_Sync();
        $result = $sync->batch_resync_orders( $filter );

        wp_send_json_success( $result );
    }

    /**
     * AJAX: Get Hou.la order counts by sync status.
     */
    public function ajax_order_sync_counts() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        try {
            $sync   = new Wp_Houla_Sync();
            $counts = $sync->count_houla_orders();
            wp_send_json_success( $counts );
        } catch ( \Throwable $e ) {
            wp_send_json_error( 'Error: ' . $e->getMessage() );
        }
    }

    /**
     * AJAX: Pull orders from Hou.la API to create/update WC orders.
     * Triggers the API to re-push all orders via webhooks.
     */
    public function ajax_pull_orders_from_houla() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $filter = isset( $_POST['filter'] ) ? sanitize_text_field( $_POST['filter'] ) : 'all';
        if ( ! in_array( $filter, array( 'all', 'failed' ), true ) ) {
            $filter = 'all';
        }

        try {
            $sync   = new Wp_Houla_Sync();
            $result = $sync->pull_orders_from_api( $filter );

            if ( $result['message'] !== 'OK' ) {
                wp_send_json_error( $result['message'] );
            }

            wp_send_json_success( $result );
        } catch ( \Throwable $e ) {
            wp_send_json_error( __( 'Error:', 'wp-houla' ) . ' ' . $e->getMessage() );
        }
    }

    // =====================================================================
    // WooCommerce orders list column
    // =====================================================================

    /**
     * Add Hou.la sync status column to WooCommerce orders list.
     *
     * @param array $columns
     * @return array
     */
    public function add_orders_column( $columns ) {
        if ( ! $this->auth->is_connected() ) {
            return $columns;
        }

        // Insert before 'order_actions' column for better placement
        $new_columns = array();
        foreach ( $columns as $key => $label ) {
            if ( $key === 'order_actions' || $key === 'wc_actions' ) {
                $new_columns['houla_sync'] = 'Hou.la';
            }
            $new_columns[ $key ] = $label;
        }

        // If order_actions was not found, append
        if ( ! isset( $new_columns['houla_sync'] ) ) {
            $new_columns['houla_sync'] = 'Hou.la';
        }

        return $new_columns;
    }

    /**
     * Render the Hou.la sync column for WooCommerce orders.
     *
     * @param string $column
     * @param int|WC_Order $order_or_id Order object (HPOS) or post ID (legacy).
     */
    public function render_orders_column( $column, $order_or_id = null ) {
        if ( $column !== 'houla_sync' ) {
            return;
        }

        // Handle both HPOS (receives WC_Order) and legacy (receives post_id)
        if ( $order_or_id instanceof \WC_Order ) {
            $order = $order_or_id;
        } elseif ( is_numeric( $order_or_id ) ) {
            $order = wc_get_order( $order_or_id );
        } else {
            global $post;
            $order = $post ? wc_get_order( $post->ID ) : null;
        }

        if ( ! $order ) {
            echo '-';
            return;
        }

        $houla_id    = $order->get_meta( '_houla_order_id' );
        $sync_status = $order->get_meta( '_houla_sync_status' );
        $sync_error  = $order->get_meta( '_houla_sync_error' );

        if ( empty( $houla_id ) ) {
            echo '<span style="color:#999;">-</span>';
            return;
        }

        $order_id = $order->get_id();

        if ( $sync_status === 'synced' ) {
            echo '<span style="color:#46b450;" title="' . esc_attr__( 'Synced', 'wp-houla' ) . '">&#10003;</span>';
        } elseif ( $sync_status === 'failed' ) {
            echo '<span style="color:#dc3232;" title="' . esc_attr( $sync_error ) . '">&#10007;</span> ';
            echo '<a href="#" class="wphoula-resync-order" data-order-id="' . esc_attr( $order_id ) . '" title="' . esc_attr__( 'Retry sync', 'wp-houla' ) . '" style="text-decoration:none;">&#8635;</a>';
        } else {
            // Pending (has houla_order_id but no sync status yet - old orders)
            echo '<span style="color:#f0b849;" title="' . esc_attr__( 'Pending sync', 'wp-houla' ) . '">&#9679;</span> ';
            echo '<a href="#" class="wphoula-resync-order" data-order-id="' . esc_attr( $order_id ) . '" title="' . esc_attr__( 'Sync now', 'wp-houla' ) . '" style="text-decoration:none;">&#8635;</a>';
        }
    }

    /**
     * Render the WooCommerce "Origine" column as a bare "Hou.la" for orders we
     * attributed to Hou.la. WooCommerce's get_origin_label() builds the label from
     * the source_type (we set 'referral' → "Referral: %s") and formats the source
     * via ucfirst(trim($source,'()')) → "Hou.la". Returning a prefix-less '%s'
     * template makes the column render just "Hou.la". Guarded to only touch our own
     * orders (utm_source === 'Hou.la'); everything else is left untouched.
     *
     * @param string $label       The origin label template (may contain %s).
     * @param string $source_type The attribution source type.
     * @param string $source      The attribution source value (utm_source).
     * @return string
     */
    public function filter_houla_origin_label( $label, $source_type, $source ) {
        if ( is_string( $source ) && strcasecmp( trim( $source ), 'hou.la' ) === 0 ) {
            return '%s';
        }
        return $label;
    }

    // =====================================================================
    // Dashboard widget
    // =====================================================================

    /**
     * Register the Hou.la dashboard widget.
     */
    public function register_dashboard_widget() {
        if ( ! $this->auth->is_connected() ) {
            return;
        }

        $widget_title = '<img src="' . esc_url( WPHOULA_URL . 'admin/images/houla-icon.svg' ) . '" width="16" height="16" style="vertical-align:text-bottom;margin-right:4px;" alt="">'
                      . esc_html__( 'Hou.la - Short Links', 'wp-houla' );

        wp_add_dashboard_widget(
            'wphoula_dashboard_widget',
            $widget_title,
            array( $this, 'render_dashboard_widget' )
        );
    }

    /**
     * Render the dashboard widget content.
     */
    public function render_dashboard_widget() {
        $nonce = wp_create_nonce( 'wphoula_admin' );
        ?>
        <div class="wphoula-dashboard-widget" data-nonce="<?php echo esc_attr( $nonce ); ?>">
            <div class="wphoula-dw-loading" id="wphoula-dw-loading">
                <span class="spinner is-active" style="float:none;margin:0 8px 0 0;"></span>
                <?php esc_html_e( 'Loading stats...', 'wp-houla' ); ?>
            </div>
            <div class="wphoula-dw-content" id="wphoula-dw-content" style="display:none;">
                <div class="wphoula-dw-stats">
                    <div class="wphoula-dw-stat">
                        <span class="wphoula-dw-stat-value" id="wphoula-dw-total-links">-</span>
                        <span class="wphoula-dw-stat-label"><?php esc_html_e( 'Short links', 'wp-houla' ); ?></span>
                    </div>
                    <div class="wphoula-dw-stat">
                        <span class="wphoula-dw-stat-value" id="wphoula-dw-total-clicks">-</span>
                        <span class="wphoula-dw-stat-label"><?php esc_html_e( 'Total clicks', 'wp-houla' ); ?></span>
                    </div>
                    <div class="wphoula-dw-stat">
                        <span class="wphoula-dw-stat-value" id="wphoula-dw-clicks-today">-</span>
                        <span class="wphoula-dw-stat-label"><?php esc_html_e( 'Clicks today', 'wp-houla' ); ?></span>
                    </div>
                </div>
                <div class="wphoula-dw-chart" id="wphoula-dw-chart" style="display:none;margin-top:12px;">
                    <p class="wphoula-chart-label"><?php esc_html_e( '7-day performance', 'wp-houla' ); ?></p>
                    <canvas id="wphoula-dw-sparkline" width="100%" height="60"></canvas>
                </div>
                <div class="wphoula-dw-top" id="wphoula-dw-top" style="display:none;margin-top:12px;">
                    <p class="wphoula-chart-label"><?php esc_html_e( 'Top links', 'wp-houla' ); ?></p>
                    <table class="wphoula-dw-table" id="wphoula-dw-table">
                        <tbody></tbody>
                    </table>
                </div>
            </div>
            <div class="wphoula-dw-footer" style="margin-top:12px;text-align:right;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-houla' ) ); ?>" class="button button-small">
                    <?php esc_html_e( 'Settings', 'wp-houla' ); ?>
                </a>
                <a href="https://hou.la/manager" target="_blank" class="button button-primary button-small" style="margin-left:4px;">
                    <?php esc_html_e( 'Open Hou.la', 'wp-houla' ); ?>
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Get dashboard widget stats.
     * Fetches recent links created by this WP site.
     */
    public function ajax_dashboard_stats() {
        check_ajax_referer( 'wphoula_admin', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-houla' ) );
        }

        $api = new Wp_Houla_Api();

        // Get links created by WordPress (sorted by clicks)
        $links = $api->get( '/manager/link', array(
            'page'   => '1',
            'limit'  => '5',
            'sort'   => 'hitsCount,DESC',
            'filter' => 'createdByType||eq||wordpress',
        ) );

        if ( is_wp_error( $links ) ) {
            wp_send_json_error( $links->get_error_message() );
        }

        $total_links  = isset( $links['total'] ) ? (int) $links['total'] : 0;
        $total_clicks = 0;
        $top_links    = array();

        $data = isset( $links['data'] ) ? $links['data'] : array();
        foreach ( $data as $link ) {
            $clicks = isset( $link['hitsCount'] ) ? (int) $link['hitsCount'] : 0;
            $total_clicks += $clicks;
            $top_links[] = array(
                'title'    => isset( $link['title'] ) ? $link['title'] : $link['key'],
                'shortUrl' => isset( $link['shortUrl'] ) ? $link['shortUrl'] : '',
                'clicks'   => $clicks,
            );
        }

        // Also get today's clicks from byDay data (if available)
        // For now, return totals from the filtered links response
        wp_send_json_success( array(
            'totalLinks'  => $total_links,
            'totalClicks' => $total_clicks,
            'clicksToday' => 0, // Would require separate hit/export call
            'topLinks'    => $top_links,
        ) );
    }

    // =====================================================================
    // Posts list column
    // =====================================================================

    /**
     * Add Hou.la column header to posts list.
     *
     * @param array $columns
     * @return array
     */
    public function add_posts_column( $columns ) {
        if ( ! $this->auth->is_connected() ) {
            return $columns;
        }

        $columns['wphoula_shortlink'] = '<img src="' . esc_url( WPHOULA_URL . 'admin/images/houla-icon.svg' ) . '" width="14" height="14" style="vertical-align:text-bottom;margin-right:2px;" alt=""> '
            . esc_html__( 'Short link', 'wp-houla' );

        return $columns;
    }

    /**
     * Render the Hou.la column content for each post.
     *
     * @param string $column_name
     * @param int    $post_id
     */
    public function render_posts_column( $column_name, $post_id ) {
        if ( 'wphoula_shortlink' !== $column_name ) {
            return;
        }

        $shortlink = get_post_meta( $post_id, '_wphoula_shortlink', true );
        $clicks    = 0;
        $link_id   = get_post_meta( $post_id, '_wphoula_link_id', true );

        if ( ! empty( $shortlink ) ) {
            echo '<div class="wphoula-col-link">';
            echo '<a href="' . esc_url( $shortlink ) . '" target="_blank" class="wphoula-col-url" title="' . esc_attr( $shortlink ) . '">';
            // Show only the path part
            $parts = wp_parse_url( $shortlink );
            echo esc_html( isset( $parts['host'] ) ? $parts['host'] : '' );
            echo esc_html( isset( $parts['path'] ) ? $parts['path'] : '' );
            echo '</a>';
            echo '</div>';
        } else {
            echo '<span class="wphoula-col-none">-</span>';
        }
    }

    /**
     * Make the Hou.la column sortable (by post meta).
     *
     * @param array $columns
     * @return array
     */
    public function sortable_posts_column( $columns ) {
        $columns['wphoula_shortlink'] = 'wphoula_shortlink';
        return $columns;
    }

    // =====================================================================
    // Settings page render
    // =====================================================================

    /**
     * Render the settings page.
     */
    public function render_settings_page() {
        // Auto-repair: re-provision API key if connected but key is missing
        if ( $this->auth->is_connected() && ! $this->auth->get_api_key() ) {
            $this->auth->provision_api_key();
        }

        $options = $this->options;
        $auth    = $this->auth;
        include plugin_dir_path( __FILE__ ) . 'partials/settings-page.php';
    }

    // =====================================================================
    // Utilities
    // =====================================================================

    /**
     * Check if the current admin page is our settings page.
     *
     * @param string $hook
     * @return bool
     */
    private function is_our_page( $hook ) {
        return in_array( $hook, array(
            'woocommerce_page_wp-houla',
            'woocommerce-marketing_page_wp-houla',
            'marketing_page_wp-houla',
            'settings_page_wp-houla',
            'toplevel_page_wp-houla',
        ), true );
    }

    /**
     * Check if the current admin page is a post/page/cpt edit page.
     *
     * @param string $hook
     * @return bool
     */
    private function is_post_edit( $hook ) {
        return in_array( $hook, array( 'post.php', 'post-new.php' ), true );
    }

    /**
     * Check if the current admin page is the WooCommerce orders list.
     *
     * @param string $hook
     * @return bool
     */
    private function is_wc_orders_page( $hook ) {
        // HPOS: woocommerce_page_wc-orders
        if ( $hook === 'woocommerce_page_wc-orders' ) {
            return true;
        }
        // Legacy: edit.php with post_type=shop_order
        if ( $hook === 'edit.php' && isset( $_GET['post_type'] ) && $_GET['post_type'] === 'shop_order' ) {
            return true;
        }
        return false;
    }
}
