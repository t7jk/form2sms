<?php
/**
 * Konfiguracja środowiska testów PHPUnit dla WordPress.
 *
 * Jest to plik "real config" wskazywany przez env var `WP_PHPUNIT__TESTS_CONFIG`.
 * Uzupełnij zmienne środowiskowe (najprościej przez `.env`), żeby wskazać
 * poprawną bazę testową MySQL.
 */

declare(strict_types=1);

$wpunitDir = getenv( 'WP_PHPUNIT__DIR' );
if ( empty( $wpunitDir ) ) {
	$wpunitDir = dirname( __DIR__ ) . '/../vendor/wordpress/phpunit';
}

// WP test bootstrap wykorzystuje ABSPATH do lokalizacji kodu WordPress.
define( 'ABSPATH', rtrim( $wpunitDir, '/\\' ) . '/src/' );

define( 'WP_DEFAULT_THEME', 'default' );
define( 'WP_DEBUG', true );

define( 'DB_NAME', getenv( 'WP_TESTS_DB_NAME' ) ?: 'wordpress_tests' );
define( 'DB_USER', getenv( 'WP_TESTS_DB_USER' ) ?: 'root' );
define( 'DB_PASSWORD', getenv( 'WP_TESTS_DB_PASSWORD' ) ?: '' );
define( 'DB_HOST', getenv( 'WP_TESTS_DB_HOST' ) ?: 'localhost' );
define( 'DB_CHARSET', getenv( 'WP_TESTS_DB_CHARSET' ) ?: 'utf8mb4' );
define( 'DB_COLLATE', getenv( 'WP_TESTS_DB_COLLATE' ) ?: '' );

// Obiekty uwierzytelnienia - nie musi być realne bezpieczeństwo w testach.
define( 'AUTH_KEY', 'test-auth-key' );
define( 'SECURE_AUTH_KEY', 'test-secure-auth-key' );
define( 'LOGGED_IN_KEY', 'test-logged-in-key' );
define( 'NONCE_KEY', 'test-nonce-key' );
define( 'AUTH_SALT', 'test-auth-salt' );
define( 'SECURE_AUTH_SALT', 'test-secure-auth-salt' );
define( 'LOGGED_IN_SALT', 'test-logged-in-salt' );
define( 'NONCE_SALT', 'test-nonce-salt' );

$table_prefix = getenv( 'WP_PHPUNIT__TABLE_PREFIX' ) ?: 'wptests_';

define( 'WP_TESTS_DOMAIN', getenv( 'WP_TESTS_DOMAIN' ) ?: 'example.org' );
define( 'WP_TESTS_EMAIL', getenv( 'WP_TESTS_EMAIL' ) ?: 'admin@example.org' );
define( 'WP_TESTS_TITLE', getenv( 'WP_TESTS_TITLE' ) ?: 'Form2SMS Test Blog' );

define( 'WP_PHP_BINARY', getenv( 'WP_PHP_BINARY' ) ?: 'php' );
define( 'WPLANG', getenv( 'WP_WPLANG' ) ?: '' );

