<?php
/**
 * Klasa zarządzająca stroną ustawień wtyczki Form2SMS.
 * Dodaje pozycję "Form2SMS" w menu Narzędzia (Tools).
 */

defined( 'ABSPATH' ) || exit;

class Form2SMS_Settings {

	public function __construct() {
		add_action( 'admin_menu',    [ $this, 'register_menu' ] );
		add_action( 'admin_init',    [ $this, 'register_settings' ] );
		add_action( 'admin_notices', [ $this, 'maybe_show_cf7_notice' ] );
	}

	// -------------------------------------------------------------------------
	// Menu i strona ustawień
	// -------------------------------------------------------------------------

	/**
	 * Rejestruje podmenu "Form2SMS" pod "Narzędzia".
	 */
	public function register_menu(): void {
		add_management_page(
			__( 'Form2SMS — Ustawienia', 'form2sms' ),
			__( 'Form2SMS', 'form2sms' ),
			'manage_options',
			'form2sms',
			[ $this, 'render_page' ]
		);
	}

	/**
	 * Renderuje stronę ustawień.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'form2sms' ) );
		}

		$settings = self::get_settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Form2SMS — Ustawienia', 'form2sms' ); ?></h1>

			<?php if ( ! $this->is_cf7_active() ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Wtyczka Contact Form 7 nie jest aktywna. Form2SMS wymaga CF7 do działania.', 'form2sms' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'form2sms_group' );
				do_settings_sections( 'form2sms' );
				submit_button( __( 'Zapisz ustawienia', 'form2sms' ) );
				?>
			</form>

			<?php
			// Informacja o dziennym liczniku SMS (tylko do odczytu).
			$today = gmdate( 'Y-m-d' );
			$count = ( $settings['sms_count_date'] === $today ) ? (int) $settings['sms_count'] : 0;
			$limit = (int) $settings['sms_limit'];
			?>
			<p>
				<strong><?php esc_html_e( 'Wysłano dziś:', 'form2sms' ); ?></strong>
				<?php
				// translators: %1$d = liczba wysłanych SMS, %2$d = dzienny limit.
				echo esc_html( sprintf( __( '%1$d / %2$d', 'form2sms' ), $count, $limit ) );
				?>
			</p>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Rejestracja pól Settings API
	// -------------------------------------------------------------------------

	/**
	 * Rejestruje ustawienia, sekcje i pola przez WordPress Settings API.
	 */
	public function register_settings(): void {
		register_setting(
			'form2sms_group',
			'form2sms_settings',
			[
				'sanitize_callback' => [ $this, 'sanitize_settings' ],
				'default'           => self::get_defaults(),
			]
		);

		// --- Sekcja: Formularz CF7 ---
		add_settings_section(
			'form2sms_cf7',
			__( 'Formularz Contact Form 7', 'form2sms' ),
			'__return_false',
			'form2sms'
		);

		add_settings_field(
			'form_id',
			__( 'Aktywny formularz', 'form2sms' ),
			[ $this, 'field_form_select' ],
			'form2sms',
			'form2sms_cf7'
		);

		add_settings_field(
			'field_name',
			__( 'Tag pola: Imię i Nazwisko', 'form2sms' ),
			[ $this, 'field_text' ],
			'form2sms',
			'form2sms_cf7',
			[ 'key' => 'field_name', 'description' => __( 'Nazwa tagu CF7, np. your-name', 'form2sms' ) ]
		);

		add_settings_field(
			'field_phone',
			__( 'Tag pola: Telefon', 'form2sms' ),
			[ $this, 'field_text' ],
			'form2sms',
			'form2sms_cf7',
			[ 'key' => 'field_phone', 'description' => __( 'Nazwa tagu CF7, np. your-phone', 'form2sms' ) ]
		);

		add_settings_field(
			'field_course',
			__( 'Tag pola: Kurs', 'form2sms' ),
			[ $this, 'field_text' ],
			'form2sms',
			'form2sms_cf7',
			[ 'key' => 'field_course', 'description' => __( 'Nazwa tagu CF7, np. your-subject', 'form2sms' ) ]
		);

		// --- Sekcja: SMSAPI ---
		add_settings_section(
			'form2sms_api',
			__( 'Połączenie z SMSAPI.pl', 'form2sms' ),
			'__return_false',
			'form2sms'
		);

		add_settings_field(
			'api_token',
			__( 'Token API (Bearer)', 'form2sms' ),
			[ $this, 'field_password' ],
			'form2sms',
			'form2sms_api',
			[ 'key' => 'api_token', 'description' => __( 'Token OAuth z panelu SMSAPI.pl → API → Tokeny', 'form2sms' ) ]
		);

		add_settings_field(
			'admin_phone',
			__( 'Numer telefonu admina', 'form2sms' ),
			[ $this, 'field_text' ],
			'form2sms',
			'form2sms_api',
			[ 'key' => 'admin_phone', 'description' => __( 'Format: 48500600700 (bez + i spacji)', 'form2sms' ) ]
		);

		// --- Sekcja: Zachowanie ---
		add_settings_section(
			'form2sms_behaviour',
			__( 'Zachowanie', 'form2sms' ),
			'__return_false',
			'form2sms'
		);

		add_settings_field(
			'enabled',
			__( 'Włącz wysyłanie SMS', 'form2sms' ),
			[ $this, 'field_checkbox' ],
			'form2sms',
			'form2sms_behaviour',
			[ 'key' => 'enabled' ]
		);

		add_settings_field(
			'sms_limit',
			__( 'Maksymalna liczba SMS na dobę', 'form2sms' ),
			[ $this, 'field_number' ],
			'form2sms',
			'form2sms_behaviour',
			[ 'key' => 'sms_limit', 'min' => 1, 'max' => 9999 ]
		);
	}

	// -------------------------------------------------------------------------
	// Rendery poszczególnych pól
	// -------------------------------------------------------------------------

	/** Dropdown z listą aktywnych formularzy CF7. */
	public function field_form_select(): void {
		$settings = self::get_settings();
		$forms    = $this->get_cf7_forms();
		?>
		<select name="form2sms_settings[form_id]">
			<option value="0"><?php esc_html_e( '— wybierz formularz —', 'form2sms' ); ?></option>
			<?php foreach ( $forms as $id => $title ) : ?>
				<option value="<?php echo esc_attr( $id ); ?>"
					<?php selected( (int) $settings['form_id'], $id ); ?>>
					<?php echo esc_html( $title ); ?> (ID: <?php echo esc_html( $id ); ?>)
				</option>
			<?php endforeach; ?>
		</select>
		<?php if ( empty( $forms ) ) : ?>
			<p class="description"><?php esc_html_e( 'Brak formularzy CF7. Utwórz formularz w Contact Form 7.', 'form2sms' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/** Pole tekstowe. */
	public function field_text( array $args ): void {
		$settings = self::get_settings();
		$key      = $args['key'];
		$value    = $settings[ $key ] ?? '';
		$desc     = $args['description'] ?? '';
		?>
		<input type="text"
			name="form2sms_settings[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text">
		<?php if ( $desc ) : ?>
			<p class="description"><?php echo esc_html( $desc ); ?></p>
		<?php endif; ?>
		<?php
	}

	/** Pole hasła (maskuje token API). */
	public function field_password( array $args ): void {
		$settings = self::get_settings();
		$key      = $args['key'];
		$value    = $settings[ $key ] ?? '';
		$desc     = $args['description'] ?? '';
		?>
		<input type="password"
			name="form2sms_settings[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			autocomplete="new-password">
		<?php if ( $desc ) : ?>
			<p class="description"><?php echo esc_html( $desc ); ?></p>
		<?php endif; ?>
		<?php
	}

	/** Pole checkbox. */
	public function field_checkbox( array $args ): void {
		$settings = self::get_settings();
		$key      = $args['key'];
		$checked  = ! empty( $settings[ $key ] );
		?>
		<label>
			<input type="checkbox"
				name="form2sms_settings[<?php echo esc_attr( $key ); ?>]"
				value="1"
				<?php checked( $checked ); ?>>
			<?php esc_html_e( 'Aktywne', 'form2sms' ); ?>
		</label>
		<?php
	}

	/** Pole liczbowe. */
	public function field_number( array $args ): void {
		$settings = self::get_settings();
		$key      = $args['key'];
		$value    = (int) ( $settings[ $key ] ?? 50 );
		$min      = $args['min'] ?? 1;
		$max      = $args['max'] ?? 9999;
		?>
		<input type="number"
			name="form2sms_settings[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( $value ); ?>"
			min="<?php echo esc_attr( $min ); ?>"
			max="<?php echo esc_attr( $max ); ?>"
			class="small-text">
		<?php
	}

	// -------------------------------------------------------------------------
	// Sanityzacja
	// -------------------------------------------------------------------------

	/**
	 * Sanityzuje dane przed zapisem do bazy.
	 * Zachowuje pola auto-zarządzane: sms_count, sms_count_date.
	 */
	public function sanitize_settings( array $input ): array {
		$current  = self::get_settings();
		$defaults = self::get_defaults();

		$clean = [];

		// CF7
		$clean['form_id']      = absint( $input['form_id'] ?? $defaults['form_id'] );
		$clean['field_name']   = sanitize_text_field( $input['field_name']   ?? $defaults['field_name'] );
		$clean['field_phone']  = sanitize_text_field( $input['field_phone']  ?? $defaults['field_phone'] );
		$clean['field_course'] = sanitize_text_field( $input['field_course'] ?? $defaults['field_course'] );

		// SMSAPI
		$clean['api_token']   = sanitize_text_field( $input['api_token'] ?? '' );
		// Numer telefonu: tylko cyfry.
		$clean['admin_phone'] = preg_replace( '/[^0-9]/', '', $input['admin_phone'] ?? '' );

		// Zachowanie
		$clean['enabled']   = ! empty( $input['enabled'] );
		$clean['sms_limit'] = max( 1, absint( $input['sms_limit'] ?? $defaults['sms_limit'] ) );

		// Pola auto-zarządzane — nie nadpisujemy danymi z formularza.
		$clean['sms_count']      = $current['sms_count'];
		$clean['sms_count_date'] = $current['sms_count_date'];

		return $clean;
	}

	// -------------------------------------------------------------------------
	// Powiadomienie o braku CF7
	// -------------------------------------------------------------------------

	/**
	 * Wyświetla ostrzeżenie w panelu admina, gdy CF7 nie jest aktywny.
	 */
	public function maybe_show_cf7_notice(): void {
		if ( $this->is_cf7_active() ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Form2SMS:', 'form2sms' ); ?></strong>
				<?php esc_html_e( 'Wtyczka Contact Form 7 nie jest zainstalowana lub aktywna.', 'form2sms' ); ?>
			</p>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpery
	// -------------------------------------------------------------------------

	/**
	 * Sprawdza czy Contact Form 7 jest aktywny.
	 */
	private function is_cf7_active(): bool {
		return class_exists( 'WPCF7_ContactForm' );
	}

	/**
	 * Pobiera listę formularzy CF7: [id => tytuł].
	 */
	private function get_cf7_forms(): array {
		if ( ! $this->is_cf7_active() ) {
			return [];
		}

		$posts = get_posts( [
			'post_type'      => 'wpcf7_contact_form',
			'numberposts'    => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );

		$forms = [];
		foreach ( $posts as $post ) {
			$forms[ $post->ID ] = $post->post_title;
		}

		return $forms;
	}

	/**
	 * Zwraca aktualne ustawienia wtyczki (z domyślnymi jako fallback).
	 */
	public static function get_settings(): array {
		return wp_parse_args(
			(array) get_option( 'form2sms_settings', [] ),
			self::get_defaults()
		);
	}

	/**
	 * Zwraca domyślne ustawienia wtyczki.
	 */
	public static function get_defaults(): array {
		return [
			'form_id'        => 0,
			'field_name'     => 'your-name',
			'field_phone'    => 'your-phone',
			'field_course'   => 'your-subject',
			'api_token'      => '',
			'admin_phone'    => '',
			'enabled'        => false,
			'sms_limit'      => 50,
			'sms_count'      => 0,
			'sms_count_date' => '',
		];
	}
}
