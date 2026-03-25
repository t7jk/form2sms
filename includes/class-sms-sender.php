<?php
/**
 * Klasa odpowiedzialna za wysyłanie SMS przez API SMSAPI.pl.
 * Używa wp_remote_post() — bez zewnętrznych bibliotek.
 */

defined( 'ABSPATH' ) || exit;

class Form2SMS_SMS_Sender {

	/** Endpoint REST API SMSAPI.pl. */
	private const API_URL = 'https://api.smsapi.pl/sms.do';

	/** Maksymalna długość SMS w znakach. */
	private const MAX_LENGTH = 159;

	// -------------------------------------------------------------------------
	// Główna metoda wysyłki
	// -------------------------------------------------------------------------

	/**
	 * Wysyła SMS z danymi zgłoszenia.
	 *
	 * @param array $data Tablica z kluczami: name, phone, course.
	 * @return bool True jeśli SMS wysłany poprawnie, false w przeciwnym razie.
	 */
	public function send( array $data ): bool {
		$settings = Form2SMS_Settings::get_settings();

		// Sprawdź czy wysyłanie jest włączone.
		if ( empty( $settings['enabled'] ) ) {
			return false;
		}

		// Sprawdź czy token i numer docelowy są ustawione.
		if ( empty( $settings['api_token'] ) || empty( $settings['admin_phone'] ) ) {
			error_log( '[Form2SMS] Brak tokena API lub numeru telefonu admina w ustawieniach.' );
			return false;
		}

		// Sprawdź dzienny limit SMS.
		if ( ! $this->check_daily_limit( $settings ) ) {
			error_log( sprintf(
				'[Form2SMS] Dzienny limit osiągnięty: %d / %d',
				(int) $settings['sms_count'],
				(int) $settings['sms_limit']
			) );
			return false;
		}

		// Zbuduj treść SMS.
		$message = $this->build_message( $data );

		// Wyślij przez API.
		$success = $this->call_api( $settings['api_token'], $settings['admin_phone'], $message );

		// Zwiększ licznik jeśli wysyłka się powiodła.
		if ( $success ) {
			$this->increment_counter();
		}

		return $success;
	}

	// -------------------------------------------------------------------------
	// Budowanie treści SMS
	// -------------------------------------------------------------------------

	/**
	 * Buduje treść SMS z danych formularza.
	 * Zastępuje polskie znaki, skraca do 159 znaków.
	 *
	 * @param array $data Tablica z kluczami: name, phone, course.
	 * @return string Gotowa treść SMS.
	 */
	private function build_message( array $data ): string {
		$name   = sanitize_text_field( $data['name']   ?? '' );
		$phone  = sanitize_text_field( $data['phone']  ?? '' );
		$course = sanitize_text_field( $data['course'] ?? '' );

		// Szablon SMS: "Jan Kowalski, numer 500600700, zapis na: Wstep do programowania"
		$message = sprintf( '%s, numer %s, zapis na: %s', $name, $phone, $course );

		// Usuń polskie znaki diakrytyczne (SMS GSM7 nie obsługuje UTF-8 wydajnie).
		$message = $this->replace_diacritics( $message );

		// Skróć do maksymalnej długości SMS.
		return substr( $message, 0, self::MAX_LENGTH );
	}

	/**
	 * Zastępuje polskie znaki diakrytyczne odpowiednikami ASCII.
	 * Zapobiega naliczaniu SMS jako Unicode (= droższe, krótsze).
	 *
	 * @param string $text Tekst wejściowy.
	 * @return string Tekst bez polskich znaków.
	 */
	private function replace_diacritics( string $text ): string {
		$search = [
			// Małe litery
			'ą', 'ć', 'ę', 'ł', 'ń', 'ó', 'ś', 'ź', 'ż',
			// Wielkie litery
			'Ą', 'Ć', 'Ę', 'Ł', 'Ń', 'Ó', 'Ś', 'Ź', 'Ż',
		];

		$replace = [
			// Małe litery
			'a', 'c', 'e', 'l', 'n', 'o', 's', 'z', 'z',
			// Wielkie litery
			'A', 'C', 'E', 'L', 'N', 'O', 'S', 'Z', 'Z',
		];

		return str_replace( $search, $replace, $text );
	}

	// -------------------------------------------------------------------------
	// Wywołanie API SMSAPI.pl
	// -------------------------------------------------------------------------

	/**
	 * Wysyła żądanie HTTP do API SMSAPI.pl.
	 *
	 * @param string $token   Bearer token OAuth.
	 * @param string $to      Numer docelowy (np. 48500600700).
	 * @param string $message Treść SMS (max 159 znaków, bez polskich liter).
	 * @return bool True jeśli API zwróciło sukces.
	 */
	private function call_api( string $token, string $to, string $message ): bool {
		$response = wp_remote_post(
			self::API_URL,
			[
				'headers' => [
					'Authorization' => 'Bearer ' . $token,
				],
				'body'    => [
					'to'      => $to,
					'message' => $message,
					'format'  => 'json',
				],
				'timeout' => 15,
			]
		);

		// Błąd sieciowy (np. brak połączenia, timeout).
		if ( is_wp_error( $response ) ) {
			error_log( '[Form2SMS] Błąd WP_Error: ' . $response->get_error_message() );
			return false;
		}

		$http_code = wp_remote_retrieve_response_code( $response );
		$body      = json_decode( wp_remote_retrieve_body( $response ), true );

		// Sprawdź kod HTTP.
		if ( $http_code !== 200 ) {
			error_log( sprintf( '[Form2SMS] Błąd HTTP %d: %s', $http_code, wp_remote_retrieve_body( $response ) ) );
			return false;
		}

		// Sprawdź czy API zwróciło błąd w treści odpowiedzi.
		if ( ! empty( $body['error'] ) ) {
			error_log( sprintf( '[Form2SMS] Błąd API %s: %s', $body['error'], $body['message'] ?? '' ) );
			return false;
		}

		// Sprawdź czy nie ma nieprawidłowych numerów.
		if ( ! empty( $body['invalid_numbers'] ) ) {
			error_log( '[Form2SMS] Nieprawidłowy numer telefonu: ' . $to );
			return false;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Dzienny licznik SMS
	// -------------------------------------------------------------------------

	/**
	 * Sprawdza czy dzienny limit SMS nie został przekroczony.
	 * Resetuje licznik jeśli minął dzień.
	 *
	 * @param array $settings Aktualne ustawienia wtyczki.
	 * @return bool True jeśli można wysłać SMS.
	 */
	private function check_daily_limit( array $settings ): bool {
		$today = gmdate( 'Y-m-d' );

		// Jeśli dzień się zmienił — zresetuj licznik.
		if ( $settings['sms_count_date'] !== $today ) {
			$settings['sms_count']      = 0;
			$settings['sms_count_date'] = $today;
			update_option( 'form2sms_settings', $settings );
		}

		return (int) $settings['sms_count'] < (int) $settings['sms_limit'];
	}

	/**
	 * Zwiększa dzienny licznik SMS o 1.
	 */
	private function increment_counter(): void {
		$settings = Form2SMS_Settings::get_settings();
		$today    = gmdate( 'Y-m-d' );

		// Upewnij się że licznik dotyczy dzisiejszego dnia.
		if ( $settings['sms_count_date'] !== $today ) {
			$settings['sms_count']      = 0;
			$settings['sms_count_date'] = $today;
		}

		$settings['sms_count'] = (int) $settings['sms_count'] + 1;
		update_option( 'form2sms_settings', $settings );
	}
}
