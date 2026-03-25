<?php
/**
 * Klasa obsługująca integrację z WPForms.
 *
 * Pobiera pola zgłoszenia z wejścia WPForms i mapuje je na tagi używane w szablonie SMS.
 */

defined( 'ABSPATH' ) || exit;

class Form2SMS_WPForms_Handler {

	/** @var Form2SMS_SMS_Sender */
	private Form2SMS_SMS_Sender $sender;

	/**
	 * @param Form2SMS_SMS_Sender $sender
	 */
	public function __construct( Form2SMS_SMS_Sender $sender ) {
		$this->sender = $sender;

		// Dopnij się dopiero gdy WPForms jest aktywny.
		if ( function_exists( 'wpforms' ) ) {
			// wpforms_process_complete(array $fields, array $entry, array $form_data, int $entry_id)
			add_action( 'wpforms_process_complete', [ $this, 'handle_submission' ], 10, 4 );
		}
	}

	/**
	 * Obsługuje zgłoszenie formularza WPForms.
	 *
	 * @param array $fields
	 * @param array $entry
	 * @param array $form_data
	 * @param int   $entry_id
	 */
	public function handle_submission( $fields, $entry, $form_data, $entry_id ): void {
		$settings = Form2SMS_Settings::get_settings();

		if ( ( $settings['form_source'] ?? 'cf7' ) !== 'wpforms' ) {
			return;
		}

		$config_form_id = absint( $settings['wpforms_form_id'] ?? 0 );
		$form_id         = absint( $form_data['id'] ?? 0 );

		if ( 0 === $config_form_id ) {
			return;
		}

		if ( 0 === $form_id || $form_id !== $config_form_id ) {
			return;
		}

		$entry_id = absint( (int) $entry_id );
		if ( 0 === $entry_id ) {
			return;
		}

		if ( ! function_exists( 'wpforms' ) ) {
			return;
		}

		$entry_obj = wpforms()->entry->get( $entry_id );
		if ( empty( $entry_obj ) || empty( $entry_obj->fields ) ) {
			return;
		}

		$entry_fields = json_decode( (string) $entry_obj->fields, true );
		if ( ! is_array( $entry_fields ) ) {
			return;
		}

		$posted = $this->map_entry_fields_to_template_tags( $entry_fields );
		if ( empty( $posted ) ) {
			return;
		}

		$this->sender->send( $posted );
	}

	/**
	 * Mapuje zdekodowane pola wejścia WPForms na tag => value podmiany w szablonie SMS.
	 *
	 * @param array $entry_fields
	 * @return array<string,string>
	 */
	private function map_entry_fields_to_template_tags( array $entry_fields ): array {
		$values = [];

		foreach ( $entry_fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$raw_key = '';
			if ( ! empty( $field['label'] ) ) {
				$raw_key = (string) $field['label'];
			} elseif ( ! empty( $field['name'] ) ) {
				$raw_key = (string) $field['name'];
			}

			$key = $this->normalize_tag_key( $raw_key );
			if ( '' === $key ) {
				continue;
			}

			$value = '';

			// Najpierw bierzemy "value" (większość typów pól).
			if ( array_key_exists( 'value', $field ) ) {
				$value = $field['value'];
			} elseif ( ! empty( $field['value_choice'] ) ) {
				// Zdarza się w niektórych konfiguracjach.
				$value = $field['value_choice'];
			} elseif ( ! empty( $field['first'] ) || ! empty( $field['last'] ) ) {
				// Pole "name" (first/middle/last).
				$parts = [];
				if ( ! empty( $field['first'] ) ) {
					$parts[] = (string) $field['first'];
				}
				if ( ! empty( $field['middle'] ) ) {
					$parts[] = (string) $field['middle'];
				}
				if ( ! empty( $field['last'] ) ) {
					$parts[] = (string) $field['last'];
				}
				$value = implode( ' ', $parts );
			}

			if ( is_array( $value ) ) {
				$value = implode( ', ', array_map( 'strval', $value ) );
			}

			$value = sanitize_text_field( (string) $value );
			$values[ $key ] = $value;
		}

		return $values;
	}

	/**
	 * Normalizuje klucz (etykietę pola) do formatu zgodnego z regexem tagów w senderze.
	 *
	 * @param string $raw
	 * @return string
	 */
	private function normalize_tag_key( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		// Ujednolicamy do ASCII (żeby regex tagów działał również dla polskich liter).
		$raw = $this->replace_diacritics( $raw );
		$raw = strtolower( $raw );

		// Zamień spacje/ciągi znaków na '-'.
		$raw = preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $raw );
		if ( is_string( $raw ) ) {
			$raw = trim( $raw, '-' );
		}

		// Tagi muszą zaczynać się literą (patrząc na regex w senderze).
		if ( ! preg_match( '/^[a-z][a-z0-9_-]*$/', (string) $raw ) ) {
			return '';
		}

		return (string) $raw;
	}

	/**
	 * @param string $text
	 * @return string
	 */
	private function replace_diacritics( string $text ): string {
		$search = [
			'ą', 'ć', 'ę', 'ł', 'ń', 'ó', 'ś', 'ź', 'ż',
			'Ą', 'Ć', 'Ę', 'Ł', 'Ń', 'Ó', 'Ś', 'Ź', 'Ż',
		];

		$replace = [
			'a', 'c', 'e', 'l', 'n', 'o', 's', 'z', 'z',
			'A', 'C', 'E', 'L', 'N', 'O', 'S', 'Z', 'Z',
		];

		return str_replace( $search, $replace, $text );
	}
}

