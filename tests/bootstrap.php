<?php
/**
 * Bootstrap testów WP PHPUnit.
 *
 * Uwaga: WordPress test bootstrap (z wp-phpunit) musi zostać załadowany
 * zanim wczytamy samą wtyczkę (bo wtyczka zawiera `defined('ABSPATH') || exit;`).
 */

declare(strict_types=1);

// Composer autoload is needed for our test classes (namespaced / classmaps).
$autoload = dirname( __DIR__ ) . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

require_once dirname( __DIR__ ) . '/vendor/wordpress/phpunit/includes/bootstrap.php';

// Wczytaj wtyczkę testowaną.
require_once dirname( __DIR__ ) . '/form2sms.php';

