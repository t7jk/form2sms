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
			'sms_template',
			__( 'Szablon treści SMS', 'form2sms' ),
			[ $this, 'field_text' ],
			'form2sms',
			'form2sms_cf7',
			[
				'key'         => 'sms_template',
				'maxlength'  => 159,
				'description' => __( 'Wstawiaj tagi CF7 w nawiasach `[]`, np. [your-name] albo [message]. Maks. 159 znaków.', 'form2sms' )
			]
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
		$tags_by_form_id = [];
		foreach ( $forms as $id => $title ) {
			$tags_by_form_id[ (int) $id ] = $this->get_cf7_form_tags( (int) $id );
		}
		$tags_by_form_id_json = wp_json_encode( $tags_by_form_id );
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

		<fieldset id="form2sms-cf7-tags-fieldset" class="form2sms-cf7-tags-fieldset" style="display:none;">
			<legend><?php esc_html_e( 'Tagi CF7 w wybranym formularzu', 'form2sms' ); ?></legend>
			<ul id="form2sms-cf7-tags-list">
				<li>
					<?php esc_html_e( 'Wybierz formularz powyżej.', 'form2sms' ); ?>
				</li>
			</ul>
		</fieldset>

		<script>
			( function () {
				const select = document.querySelector( 'select[name="form2sms_settings[form_id]"]' );
				const list = document.getElementById( 'form2sms-cf7-tags-list' );
				const fieldset = document.getElementById( 'form2sms-cf7-tags-fieldset' );
				const tagsById = <?php echo ( $tags_by_form_id_json ? $tags_by_form_id_json : '{}' ); ?>;

				if ( ! select || ! list || ! fieldset ) return;

				function setList( items ) {
					list.innerHTML = '';
					if ( ! items || ! items.length ) {
						const li = document.createElement( 'li' );
						li.textContent = '<?php echo esc_js( __( 'Brak tagów do wyświetlenia dla tego formularza.', 'form2sms' ) ); ?>';
						list.appendChild( li );
						return;
					}

					items.forEach( function ( tag ) {
						const li = document.createElement( 'li' );
						const code = document.createElement( 'code' );
						code.textContent = tag;
						li.appendChild( code );
						list.appendChild( li );
					} );
				}

				function renderFromSelection() {
					const formId = parseInt( select.value, 10 );
					if ( ! formId ) {
						fieldset.style.display = 'none';
						return;
					}
					fieldset.style.display = '';

					if ( ! tagsById[ formId ] ) {
						setList( [] );
						return;
					}
					setList( tagsById[ formId ] );
				}

				select.addEventListener( 'change', renderFromSelection );
				renderFromSelection();
			} )();
		</script>
		<?php
	}

	/** Pole tekstowe. */
	public function field_text( array $args ): void {
		$settings = self::get_settings();
		$key      = $args['key'];
		$value    = $settings[ $key ] ?? '';
		$desc     = $args['description'] ?? '';
		$maxlength = isset( $args['maxlength'] ) ? (int) $args['maxlength'] : null;
		?>
		<input type="text"
			name="form2sms_settings[<?php echo esc_attr( $key ); ?>]"
			value="<?php echo esc_attr( $value ); ?>"
			<?php echo ( $maxlength ? 'maxlength="' . esc_attr( (string) $maxlength ) . '"' : '' ); ?>
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
		$clean['form_id'] = absint( $input['form_id'] ?? $defaults['form_id'] );
		$template = sanitize_text_field( $input['sms_template'] ?? $defaults['sms_template'] );
		$clean['sms_template'] = trim( (string) $template ) !== ''
			? substr( (string) $template, 0, 159 )
			: (string) $defaults['sms_template'];

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
	 * Wyciąga nazwy tagów CF7, które odpowiadają polom formularza.
	 *
	 * @param int $form_id ID formularza CF7.
	 * @return string[] Unikalna, posortowana lista nazw tagów.
	 */
	private function get_cf7_form_tags( int $form_id ): array {
		$source = get_post_meta( $form_id, '_form', true );
		if ( ! is_string( $source ) || '' === trim( $source ) ) {
			$source = (string) get_post_field( 'post_content', $form_id );
		}
		if ( ! is_string( $source ) || '' === trim( $source ) ) {
			return [];
		}

		// Whitelist typów pól, które CF7 wysyła w submitted data.
		// Dzięki temu unikamy tagów strukturalnych typu [span], [div] itd.
		$field_types = [
			'text',
			'email',
			'tel',
			'url',
			'number',
			'date',
			'password',
			'textarea',
			'select',
			'radio',
			'checkbox',
			'acceptance',
			'file',
			'quiz',
			'range',
			'hidden',
		];

		$ignored_simple_tags = [
			'span',
			'div',
			'p',
			'label',
			'fieldset',
			'legend',
			'br',
			'hr',
			'table',
			'thead',
			'tbody',
			'tr',
			'td',
			'th',
			'ul',
			'ol',
			'li',
			'h1',
			'h2',
			'h3',
			'h4',
			'h5',
			'h6',
			'strong',
			'em',
			'small',
			'button',
			'submit',
			'captcha',
			'recaptcha',
		];

		$tags = [];

		// Typowane tagi: [type field-name ...] (np. [text your-name]).
		if ( preg_match_all( '/\[(\/?)([a-zA-Z][a-zA-Z0-9_-]*\*?)\s+([a-zA-Z0-9_-]+)/', $source, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $m ) {
				$type = strtolower( rtrim( (string) $m[2], '*' ) );
				if ( ! in_array( $type, $field_types, true ) ) {
					continue;
				}
				$tags[] = (string) $m[3];
			}
		}

		// Proste tagi: [field-name] (CF7 wspiera skróty).
		if ( preg_match_all( '/\[(\/?)([a-zA-Z0-9_-]+)\]/', $source, $matches2, PREG_SET_ORDER ) ) {
			foreach ( $matches2 as $m ) {
				$name = strtolower( (string) $m[2] );
				if ( in_array( $name, $ignored_simple_tags, true ) ) {
					continue;
				}
				$tags[] = (string) $m[2];
			}
		}

		// Unikalność bez zmiany oryginalnego casingu dla pierwszego wystąpienia.
		$unique = [];
		foreach ( $tags as $tag ) {
			$key = strtolower( (string) $tag );
			if ( isset( $unique[ $key ] ) ) {
				continue;
			}
			$unique[ $key ] = (string) $tag;
		}

		$final = array_values( $unique );
		sort( $final, SORT_NATURAL | SORT_FLAG_CASE );

		return $final;
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
			'sms_template'   => 'Wiadomosc od [your-name] telefon [gsm] email [email] Tresc [message]',
			'api_token'      => '',
			'admin_phone'    => '',
			'enabled'        => false,
			'sms_limit'      => 50,
			'sms_count'      => 0,
			'sms_count_date' => '',
		];
	}
}
