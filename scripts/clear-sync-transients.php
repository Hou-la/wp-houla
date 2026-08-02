<?php
// Hardening: never allow this diagnostic/dev script to run via a web request.
if ( php_sapi_name() !== 'cli' && ! defined( 'WP_CLI' ) ) { exit; }
require_once "/var/www/vhosts/lucky-geek.com/httpdocs/wp-load.php";
delete_transient("wphouladev_bg_sync_status");
delete_transient("wphouladev_bg_sync_nonce");
echo "Transients cleared\n";
