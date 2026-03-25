<?php
/**
 * Klasa obsługująca integrację z Contact Form 7.
 * Przechwytuje zgłoszenie formularza i przekazuje dane do wysyłki SMS.
 */

defined( 'ABSPATH' ) || exit;

class Form2SMS_CF7_Handler {

	/** @var Form2SMS_SMS_Sender Instancja klasy wysyłającej SMS. */
	private Form2SMS_SMS_Sender $sender;

	/**
	 * @param Form2SMS_SMS_Sender $sender Wstrzyknięta instancja sendera.
	 */
	public function __construct( Form2SMS_SMS_Sender $sender ) {
		$this->sender = $sender;

		// Podepnij się pod CF7 dopiero gdy klasy CF7 są dostępne.
		if ( class_exists( 'WPCF7_ContactForm' ) ) {
			// wpcf7_mail_sent odpala się PO pomyślnym wysłaniu maila przez CF7.
			add_action( 'wpcf7_mail_sent', [ $this, 'handle_submission' ] );
		}
	}

	/**
	 * Obsługuje zgłoszenie formularza CF7.
	 * Wywoływany przez hook wpcf7_mail_sent po wysłaniu maila.
	 *
	 * @param WPCF7_ContactForm $contact_form Obiekt formularza CF7.
	 */
	public function handle_submission( $contact_form ): void {
		// Upewnij się że klasa WPCF7_Submission jest dostępna.
		if ( ! class_exists( 'WPCF7_Submission' ) ) {
			return;
		}

		$settings = Form2SMS_Settings::get_settings();
		if ( ( $settings['form_source'] ?? 'cf7' ) !== 'cf7' ) {
			return;
		}

		// Pobierz ID formularza — obsługa CF7 5.x i starszych wersji.
		$form_id = method_exists( $contact_form, 'id' )
			? (int) $contact_form->id()
			: (int) ( $_POST['_wpcf7'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification

		// Sprawdź czy to skonfigurowany formularz — ignoruj pozostałe.
		if ( 0 === (int) $settings['form_id'] || $form_id !== (int) $settings['form_id'] ) {
			return;
		}

		// Pobierz dane przesłane przez użytkownika.
		$submission = WPCF7_Submission::get_instance();

		if ( null === $submission ) {
			error_log( '[Form2SMS] WPCF7_Submission::get_instance() zwróciło null.' );
			return;
		}

		$posted = $submission->get_posted_data();

		// Przekaż wszystkie tagi CF7 do sendera — to sender wykona podmianę [tag] w szablonie.
		$this->sender->send( (array) $posted );
	}
}
