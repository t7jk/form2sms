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
	 * @param array<string,string> $postedValues Dane CF7 po stronie servera (mapa: tag => wartość).
	 * @return bool True jeśli SMS wysłany poprawnie, false w przeciwnym razie.
	 */
	public function send( array $postedValues ): bool {
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

		// Zbuduj treść SMS na podstawie szablonu z ustawień.
		$message = $this->build_message( $postedValues );

		// Wyślij przez API.
		$success = $this->call_api( $settings['api_token'], $settings['admin_phone'], $message );

		// Zwiększ licznik jeśli wysyłka się powiodła.
		if ( $success ) {
			$this->increment_counter();
		}

		return $success;
	}

	/**
	 * Wysyła testowy SMS na bazie szablonu z ustawień.
	 * W treści zamienia tagi CF7 `[tag]` na same nazwy tagów (bez danych).
	 *
	 * Nie podbija dziennego licznika SMS (to tylko test).
	 *
	 * @return bool True jeśli API zwróciło sukces, false w przeciwnym razie.
	 */
	public function send_test(): bool {
		$settings = Form2SMS_Settings::get_settings();

		// Token i numer docelowy muszą być ustawione.
		if ( empty( $settings['api_token'] ) || empty( $settings['admin_phone'] ) ) {
			error_log( '[Form2SMS] Brak tokena API lub numeru telefonu admina w ustawieniach (test).' );
			return false;
		}

		// Respektuj dzienny limit, ale nie zwiększaj licznika przy teście.
		if ( ! $this->check_daily_limit( $settings ) ) {
			error_log( '[Form2SMS] Dzienny limit osiągnięty (test).' );
			return false;
		}

		$message = $this->build_test_message();

		return $this->call_api( (string) $settings['api_token'], (string) $settings['admin_phone'], $message );
	}

	// -------------------------------------------------------------------------
	// Budowanie treści SMS
	// -------------------------------------------------------------------------

	/**
	 * Buduje treść SMS z danych formularza.
	 * Zastępuje polskie znaki, skraca do 159 znaków.
	 *
	 * @param array<string,string> $postedValues Dane CF7 (tag => value).
	 * @return string Gotowa treść SMS.
	 */
	private function build_message( array $postedValues ): string {
		$settings = Form2SMS_Settings::get_settings();
		$template = (string) ( $settings['sms_template'] ?? 'Wiadomosc od [your-name] telefon [gsm] email [email] Tresc [message]' );

		$message = $this->replace_template_tags( $template, $postedValues );

		// Usuń polskie znaki diakrytyczne (SMS GSM7 nie obsługuje UTF-8 wydajnie).
		$message = $this->replace_diacritics( $message );

		// Skróć do maksymalnej długości SMS.
		return substr( $message, 0, self::MAX_LENGTH );
	}

	/**
	 * Buduje treść SMS w trybie testowym.
	 * Tagi CF7 `[tag]` zamienia na same nazwy tagów `tag` (bez nawiasów).
	 *
	 * @return string Gotowa treść testowego SMS.
	 */
	private function build_test_message(): string {
		$settings = Form2SMS_Settings::get_settings();
		$template = (string) ( $settings['sms_template'] ?? 'Wiadomosc od [your-name] telefon [gsm] email [email] Tresc [message]' );

		$message = $this->replace_template_tags_with_tag_names( $template );
		$message = $this->replace_diacritics( $message );

		return substr( $message, 0, self::MAX_LENGTH );
	}

	/**
	 * Zamienia tagi w formie `[tag]` na wartości z danych CF7.
	 *
	 * @param string              $template Szablon SMS.
	 * @param array<string,string> $postedValues Dane CF7 (tag => value).
	 * @return string Gotowy SMS.
	 */
	private function replace_template_tags( string $template, array $postedValues ): string {
		// Ujednolicamy klucze do lower-case dla odporności na casing.
		$values = [];
		foreach ( $postedValues as $k => $v ) {
			$key = strtolower( (string) $k );
			if ( is_array( $v ) ) {
				$parts = [];
				foreach ( $v as $vv ) {
					$parts[] = sanitize_text_field( (string) $vv );
				}
				$values[ $key ] = implode( ', ', $parts );
			} else {
				$values[ $key ] = sanitize_text_field( (string) $v );
			}
		}

		return (string) preg_replace_callback(
			'/\[([a-zA-Z][a-zA-Z0-9_-]*)\]/',
			function ( array $m ) use ( $values ) : string {
				$tag = strtolower( (string) $m[1] );
				return $values[ $tag ] ?? '';
			},
			$template
		);
	}

	/**
	 * Zamienia tagi w formie `[tag]` na same nazwy tagów (bez nawiasów).
	 *
	 * @param string $template Szablon SMS.
	 * @return string Gotowy tekst testowy.
	 */
	private function replace_template_tags_with_tag_names( string $template ): string {
		return (string) preg_replace_callback(
			'/\[([a-zA-Z][a-zA-Z0-9_-]*)\]/',
			function ( array $m ) : string {
				// Utrzymujemy casing z szablonu, żeby było czytelnie dla użytkownika.
				return (string) $m[1];
			},
			$template
		);
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
