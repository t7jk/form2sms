<?php
/**
 * Plugin Name:       Form2SMS
 * Plugin URI:        https://github.com/form2sms
 * Description:       Wysyła powiadomienie SMS przez SMSAPI.pl po każdym zgłoszeniu formularza Contact Form 7.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Form2SMS
 * License:           GPL-2.0-or-later
 * Text Domain:       form2sms
 * Domain Path:       /languages
 */

// Blokada bezpośredniego dostępu do pliku.
defined( 'ABSPATH' ) || exit;

// Stałe wtyczki.
define( 'FORM2SMS_VERSION', '1.0.0' );
define( 'FORM2SMS_PATH', plugin_dir_path( __FILE__ ) );
define( 'FORM2SMS_URL', plugin_dir_url( __FILE__ ) );

// Dołącz klasy.
require_once FORM2SMS_PATH . 'includes/class-settings.php';
require_once FORM2SMS_PATH . 'includes/class-sms-sender.php';
require_once FORM2SMS_PATH . 'includes/class-cf7-handler.php';
require_once FORM2SMS_PATH . 'includes/class-wpforms-handler.php';

/**
 * Uruchamia wtyczkę po załadowaniu wszystkich pluginów.
 * Zapewnia, że CF7 i tłumaczenia są już dostępne.
 */
function form2sms_bootstrap(): void {
	// Załaduj tłumaczenia wtyczki.
	load_plugin_textdomain(
		'form2sms',
		false,
		dirname( plugin_basename( __FILE__ ) ) . '/languages/'
	);

	// Utwórz instancje klas i połącz je ze sobą.
	$settings = new Form2SMS_Settings();
	$sender   = new Form2SMS_SMS_Sender();
	new Form2SMS_CF7_Handler( $sender );
	new Form2SMS_WPForms_Handler( $sender );
}
add_action( 'plugins_loaded', 'form2sms_bootstrap' );

/**
 * Hak aktywacji wtyczki — zapisuje domyślne ustawienia, jeśli nie istnieją.
 */
function form2sms_activate(): void {
	if ( false === get_option( 'form2sms_settings' ) ) {
		// autoload = 'no': opcja ładowana tylko gdy potrzebna, nie przy każdym żądaniu.
		add_option( 'form2sms_settings', Form2SMS_Settings::get_defaults(), '', 'no' );
	}
}
register_activation_hook( __FILE__, 'form2sms_activate' );
