<?php
// Quick config check script — run via: docker exec wp-houla-wordpress-1 wp --allow-root eval-file /var/www/html/wp-content/plugins/wp-houla/scripts/check-config.php
$opts = get_option('wphoula-options', array());
echo "api_key: " . (empty($opts['api_key']) ? 'EMPTY' : 'SET (' . strlen($opts['api_key']) . ' chars)') . "\n";
echo "workspace_id: " . (isset($opts['workspace_id']) ? $opts['workspace_id'] : 'EMPTY') . "\n";
echo "api_url: " . (isset($opts['api_url']) ? $opts['api_url'] : 'DEFAULT') . "\n";
echo "debug: " . (isset($opts['debug']) ? ($opts['debug'] ? 'true' : 'false') : 'unset') . "\n";
echo "authorized: " . (get_option('wphoula-authorized', false) ? 'YES' : 'NO') . "\n";

// Decrypt and test the API key
require_once dirname(__DIR__) . '/includes/class-wp-houla-options.php';
$decrypted = Wp_Houla_Options::decrypt($opts['api_key'] ?? '');
if ($decrypted) {
    echo "api_key_prefix: " . substr($decrypted, 0, 15) . "...\n";
    // Test the key against the API
    $url = ($opts['api_url'] ?? 'https://hou.la') . '/api/workspaces';
    $resp = wp_remote_get($url, array(
        'timeout' => 10,
        'headers' => array(
            'X-Api-Key' => $decrypted,
            'Accept' => 'application/json',
            'ngrok-skip-browser-warning' => 'true',
        ),
    ));
    if (is_wp_error($resp)) {
        echo "api_test: ERROR - " . $resp->get_error_message() . "\n";
    } else {
        echo "api_test: HTTP " . wp_remote_retrieve_response_code($resp) . "\n";
        $body = wp_remote_retrieve_body($resp);
        if (strlen($body) > 200) $body = substr($body, 0, 200) . '...';
        echo "api_body: " . $body . "\n";
    }
} else {
    echo "api_key_decrypt: FAILED\n";
}
