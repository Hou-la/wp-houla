<?php
/**
 * OAuth 2.0 authentication with Hou.la API using PKCE.
 *
 * Handles:
 * - Authorization URL generation (with PKCE code_challenge)
 * - Token exchange (code -> access_token + refresh_token)
 * - Token refresh (when access_token expires)
 * - Disconnect
 * - REST callback endpoint for OAuth redirect
 *
 * @since      1.0.0
 * @package    Wp_Houla
 * @subpackage Wp_Houla/includes
 */

class Wp_Houla_Auth {

    /** @var Wp_Houla_Options */
    protected $options;

    public function __construct() {
        $this->options = new Wp_Houla_Options();
    }

    // =====================================================================
    // Status
    // =====================================================================

    /**
     * Check if the plugin is connected to Hou.la.
     *
     * @return bool
     */
    public function is_connected() {
        return (bool) get_option( WPHOULA_AUTHORIZED, false );
    }

    /**
     * Set the authorization status.
     *
     * @param bool $connected
     */
    public function set_connected( $connected = true ) {
        update_option( WPHOULA_AUTHORIZED, (bool) $connected );
    }

    // =====================================================================
    // OAuth flow
    // =====================================================================

    /**
     * Generate the authorization URL to redirect the user to Hou.la.
     * Uses PKCE (Proof Key for Code Exchange) for security.
     *
     * @return string The authorization URL.
     */
    public function get_authorization_url() {
        // Generate PKCE code_verifier (43-128 chars, URL-safe)
        $code_verifier = $this->generate_code_verifier();

        // Store code_verifier in a transient (valid 10 min)
        set_transient( 'wphoula_code_verifier', $code_verifier, 600 );

        // Generate code_challenge = base64url(sha256(code_verifier))
        $code_challenge = $this->generate_code_challenge( $code_verifier );

        // Generate state parameter (CSRF protection)
        $state = wp_generate_password( 32, false );
        set_transient( 'wphoula_oauth_state', $state, 600 );

        $params = array(
            'response_type'         => 'code',
            'client_id'             => WPHOULA_OAUTH_CLIENT_ID,
            'redirect_uri'          => $this->get_callback_url(),
            'scope'                 => 'links:read links:write ecommerce:write products:sync orders:create orders:update',
            'state'                 => $state,
            'code_challenge'        => $code_challenge,
            'code_challenge_method' => 'S256',
        );

        $oauth_url = function_exists( 'wphoula_get_oauth_url' ) ? wphoula_get_oauth_url() : WPHOULA_OAUTH_URL;
        return $oauth_url . '?' . http_build_query( $params );
    }

    /**
     * Get the OAuth callback URL (WP REST API endpoint).
     *
     * @return string
     */
    public function get_callback_url() {
        return rest_url( 'wp-houla/v1/oauth/callback' );
    }

    /**
     * Exchange authorization code for tokens.
     *
     * @param string $code            The authorization code from Hou.la.
     * @param string $code_verifier   The PKCE code_verifier.
     * @return array|WP_Error Token data or error.
     */
    public function exchange_code( $code, $code_verifier ) {
        $token_url = function_exists( 'wphoula_get_token_url' ) ? wphoula_get_token_url() : WPHOULA_OAUTH_TOKEN_URL;
        $response = wp_remote_post( $token_url, array(
            'timeout' => 30,
            'headers' => array( 'Content-Type' => 'application/json', 'ngrok-skip-browser-warning' => 'true' ),
            'body'    => wp_json_encode( array(
                'grant_type'    => 'authorization_code',
                'client_id'     => WPHOULA_OAUTH_CLIENT_ID,
                'code'          => $code,
                'redirect_uri'  => $this->get_callback_url(),
                'code_verifier' => $code_verifier,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status !== 200 || empty( $body['access_token'] ) ) {
            $msg = isset( $body['message'] ) ? $body['message'] : 'Unknown error';
            return new WP_Error( 'wphoula_token_error', $msg );
        }

        return $body;
    }

    /**
     * Refresh the access token using the refresh_token.
     *
     * @return bool True on success, false on failure.
     */
    public function refresh_token() {
        $refresh_token = Wp_Houla_Options::decrypt( $this->options->get( 'refresh_token' ) );

        if ( empty( $refresh_token ) ) {
            return false;
        }

        $token_url = function_exists( 'wphoula_get_token_url' ) ? wphoula_get_token_url() : WPHOULA_OAUTH_TOKEN_URL;
        $response = wp_remote_post( $token_url, array(
            'timeout' => 30,
            'headers' => array( 'Content-Type' => 'application/json', 'ngrok-skip-browser-warning' => 'true' ),
            'body'    => wp_json_encode( array(
                'grant_type'    => 'refresh_token',
                'client_id'     => WPHOULA_OAUTH_CLIENT_ID,
                'refresh_token' => $refresh_token,
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log( 'Token refresh failed: ' . $response->get_error_message() );
            return false;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $status !== 200 || empty( $body['access_token'] ) ) {
            $this->log( 'Token refresh returned status ' . $status );
            $this->disconnect();
            return false;
        }

        $this->store_tokens( $body );
        return true;
    }

    /**
     * Get a valid access token, refreshing if needed.
     *
     * @return string|false The access token or false.
     */
    public function get_access_token() {
        if ( ! $this->is_connected() ) {
            return false;
        }

        $expires_at = (int) $this->options->get( 'token_expires_at' );

        // Refresh 5 minutes before expiry
        if ( $expires_at > 0 && time() > ( $expires_at - 300 ) ) {
            if ( ! $this->refresh_token() ) {
                return false;
            }
            // Re-read options after refresh
            $this->options = new Wp_Houla_Options();
        }

        return Wp_Houla_Options::decrypt( $this->options->get( 'access_token' ) );
    }

    /**
     * Store tokens in options (encrypted).
     *
     * Does NOT update workspace_id / workspace_name — those are managed
     * explicitly by the OAuth callback and switch-workspace handler to
     * prevent token refresh from overwriting the user's selected workspace.
     *
     * @param array $token_data Response from the token endpoint.
     */
    public function store_tokens( array $token_data ) {
        $expires_in = isset( $token_data['expires_in'] ) ? (int) $token_data['expires_in'] : 3600;

        $this->options->set_many( array(
            'access_token'     => Wp_Houla_Options::encrypt( $token_data['access_token'] ),
            'refresh_token'    => Wp_Houla_Options::encrypt( $token_data['refresh_token'] ?? '' ),
            'token_expires_at' => time() + $expires_in,
            'user_email'       => $token_data['user_email'] ?? '',
        ) );

        $this->set_connected( true );
    }

    /**
     * Disconnect from Hou.la. Clears all tokens and authorization.
     */
    public function disconnect() {
        $this->options->set_many( array(
            'access_token'     => '',
            'refresh_token'    => '',
            'token_expires_at' => 0,
            'api_key'          => '',
            'workspace_id'     => '',
            'workspace_name'   => '',
            'user_email'       => '',
        ) );

        $this->set_connected( false );
    }

    // =====================================================================
    // REST route: OAuth callback
    // =====================================================================

    /**
     * Register the OAuth callback REST endpoint.
     */
    public function register_routes() {
        register_rest_route( 'wp-houla/v1', '/oauth/callback', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'handle_oauth_callback' ),
            'permission_callback' => '__return_true', // Public endpoint (Hou.la redirects here)
        ) );
    }

    /**
     * Handle the OAuth callback from Hou.la.
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function handle_oauth_callback( $request ) {
        $code  = sanitize_text_field( $request->get_param( 'code' ) );
        $state = sanitize_text_field( $request->get_param( 'state' ) );
        $error = sanitize_text_field( $request->get_param( 'error' ) );

        // Handle errors from Hou.la
        if ( ! empty( $error ) ) {
            $this->log( 'OAuth error: ' . $error );
            return $this->redirect_to_settings( 'oauth_error' );
        }

        // Verify state
        $stored_state = get_transient( 'wphoula_oauth_state' );
        if ( empty( $state ) || $state !== $stored_state ) {
            $this->log( 'OAuth state mismatch' );
            return $this->redirect_to_settings( 'state_mismatch' );
        }
        delete_transient( 'wphoula_oauth_state' );

        // Retrieve code_verifier
        $code_verifier = get_transient( 'wphoula_code_verifier' );
        if ( empty( $code_verifier ) ) {
            $this->log( 'OAuth code_verifier expired' );
            return $this->redirect_to_settings( 'verifier_expired' );
        }
        delete_transient( 'wphoula_code_verifier' );

        // Exchange code for tokens
        $result = $this->exchange_code( $code, $code_verifier );

        if ( is_wp_error( $result ) ) {
            $this->log( 'Token exchange failed: ' . $result->get_error_message() );
            return $this->redirect_to_settings( 'token_error' );
        }

        // Store tokens
        $this->store_tokens( $result );

        // Store workspace info from initial OAuth connection
        $this->options->set( 'workspace_id', $result['workspace_id'] ?? '' );
        $this->options->set( 'workspace_name', $result['workspace_name'] ?? '' );

        $this->log( 'Successfully connected to Hou.la workspace: ' . ( $result['workspace_name'] ?? 'unknown' ) );

        // Generate a persistent API key for ongoing requests
        $this->provision_api_key();

        // Register webhook URL on Hou.la
        $this->register_webhook_on_houla();

        return $this->redirect_to_settings( 'connected' );
    }

    // =====================================================================
    // API Key provisioning
    // =====================================================================

    /**
     * Provision a persistent API key after OAuth connection.
     *
     * Calls POST /api/ecommerce/api-key with the fresh JWT to generate
     * a houla_sk_... key. The key is stored encrypted in WP options
     * and used for all subsequent API requests instead of the OAuth token.
     */
    public function provision_api_key() {
        $token = Wp_Houla_Options::decrypt( $this->options->get( 'access_token' ) );

        if ( empty( $token ) ) {
            $this->log( 'Cannot provision API key: no access token available' );
            return;
        }

        $workspace_id = $this->options->get( 'workspace_id' );
        if ( empty( $workspace_id ) ) {
            $this->log( 'Cannot provision API key: no workspace_id' );
            return;
        }

        $api_url = function_exists( 'wphoula_get_api_url' ) ? wphoula_get_api_url() : WPHOULA_API_URL;
        $url     = $api_url . '/api/keys';

        $site_name = wp_parse_url( get_site_url(), PHP_URL_HOST );

        $response = wp_remote_post( $url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'User-Agent'    => 'wp-houla/' . WPHOULA_VERSION,
                'X-Workspace-Id' => $workspace_id,
                'ngrok-skip-browser-warning' => 'true',
            ),
            'body' => wp_json_encode( array(
                'name' => 'WordPress — ' . $site_name,
                'type' => 'internal',
            ) ),
        ) );

        if ( is_wp_error( $response ) ) {
            $this->log( 'API key provisioning failed: ' . $response->get_error_message() );
            return;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( 201 === $status || 200 === $status ) {
            if ( ! empty( $body['key'] ) ) {
                $this->options->set( 'api_key', Wp_Houla_Options::encrypt( $body['key'] ) );
                $this->log( 'API key provisioned successfully: ' . ( $body['prefix'] ?? '?' ) );
            }
        } else {
            $msg = isset( $body['message'] ) ? $body['message'] : 'HTTP ' . $status;
            $this->log( 'API key provisioning error: ' . $msg );
        }
    }

    /**
     * Get the stored API key (decrypted).
     *
     * @return string|false The API key or false if not available.
     */
    public function get_api_key() {
        if ( ! $this->is_connected() ) {
            return false;
        }

        $encrypted = $this->options->get( 'api_key' );

        if ( empty( $encrypted ) ) {
            return false;
        }

        return Wp_Houla_Options::decrypt( $encrypted );
    }

    /**
     * Get the connected workspace id (for X-Workspace-Id on JWT/Bearer calls).
     *
     * @return string The workspace id, or '' if none.
     */
    public function get_workspace_id() {
        return (string) $this->options->get( 'workspace_id' );
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Register the webhook URL on Hou.la after connecting.
     */
    private function register_webhook_on_houla() {
        $api = new Wp_Houla_Api();

        $result = $api->post( '/ecommerce/webhooks/register', array(
            'callbackUrl' => rest_url( 'wp-houla/v1/webhook' ),
            'events'      => array( 'order.paid', 'order.refunded' ),
            'secret'      => $this->options->get( 'webhook_secret' ),
        ) );

        if ( is_wp_error( $result ) ) {
            $this->log( 'Webhook registration failed: ' . $result->get_error_message() );
        } else {
            $this->log( 'Webhook registered successfully' );
        }
    }

    /**
     * Generate a PKCE code_verifier (43-128 URL-safe characters).
     *
     * @return string
     */
    private function generate_code_verifier() {
        $bytes = random_bytes( 64 );
        return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
    }

    /**
     * Generate a PKCE code_challenge from the verifier.
     *
     * @param string $verifier
     * @return string
     */
    private function generate_code_challenge( $verifier ) {
        $hash = hash( 'sha256', $verifier, true );
        return rtrim( strtr( base64_encode( $hash ), '+/', '-_' ), '=' );
    }

    /**
     * Redirect to the plugin settings page with a status message.
     *
     * @param string $status
     * @return void Never returns (wp_redirect + exit).
     */
    private function redirect_to_settings( $status ) {
        $url = admin_url( 'admin.php?page=wp-houla&wphoula_connected=' . ( 'connected' === $status ? '1' : '0' ) . '&wphoula_status=' . $status );
        wp_redirect( $url );
        exit;
    }

    /**
     * Log a message (debug mode only).
     *
     * @param string $message
     */
    private function log( $message ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[WP-Houla Auth] ' . $message );
        }
    }
}
