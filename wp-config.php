<?php
/**
 * WordPress configuration file
 */

define( 'WC_GZD_ENCRYPTION_KEY', '0f7ae85baabfc1c87716706d47b97c3f7a6f8d9c7e107a5b6f1e7a0aaec90f40' );

// ** Database settings ** //
define('DB_NAME', 'wordpress');
define('DB_USER', 'wordpress');
define('DB_PASSWORD', 'db-hoshaR-4wuvve-xisbeb');
define('DB_HOST', 'db:3306');
define('DB_CHARSET', 'utf8');
define('DB_COLLATE', '');

// ** Domain settings ** //
define('WP_HOME', 'https://shop.bigfranky.de');
define('WP_SITEURL', 'https://shop.bigfranky.de');

// ** SSL settings ** //
define('FORCE_SSL_ADMIN', true);
if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https') {
    $_SERVER['HTTPS'] = 'on';
}

// ** Security keys ** //
define('AUTH_KEY',         'put your unique phrase here');
define('SECURE_AUTH_KEY',  'put your unique phrase here');
define('LOGGED_IN_KEY',    'put your unique phrase here');
define('NONCE_KEY',        'put your unique phrase here');
define('AUTH_SALT',        'put your unique phrase here');
define('SECURE_AUTH_SALT', 'put your unique phrase here');
define('LOGGED_IN_SALT',   'put your unique phrase here');
define('NONCE_SALT',       'put your unique phrase here');

// ** Database table prefix ** //
$table_prefix = 'wp_';

// ** Debug settings ** //
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);

// ** WordPress paths ** //
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/');
}

require_once ABSPATH . 'wp-settings.php'; 
