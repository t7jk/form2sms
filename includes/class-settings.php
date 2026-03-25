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
		add_action( 'admin_notices', [ $this, 'maybe_show_wpforms_notice' ] );
		add_action( 'admin_post_form2sms_send_test', [ $this, 'handle_send_test' ] );
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
		$test_result = isset( $_GET['form2sms_test_result'] )
			? sanitize_text_field( (string) $_GET['form2sms_test_result'] )
			: '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Form2SMS — Ustawienia', 'form2sms' ); ?></h1>

			<?php if ( 'ok' === $test_result ) : ?>
				<div class="notice notice-success">
					<p><?php esc_html_e( 'Wysłano testowy SMS.', 'form2sms' ); ?></p>
				</div>
			<?php elseif ( 'error' === $test_result ) : ?>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'Nie udało się wysłać testowego SMS.', 'form2sms' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ( $settings['form_source'] ?? self::get_defaults()['form_source'] ) === 'cf7' && ! $this->is_cf7_active() ) : ?>
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

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top: 16px;">
				<?php
				wp_nonce_field( 'form2sms_send_test', 'form2sms_send_test_nonce' );
				?>
				<input type="hidden" name="action" value="form2sms_send_test" />
				<?php submit_button( __( 'Test', 'form2sms' ), 'secondary', 'form2sms_send_test_submit', false ); ?>
				<p class="description" style="margin-top: 6px;">
					<?php esc_html_e( 'W trybie testowym zamieniamy tagi z szablonu na same nazwy (np. [email] → email).', 'form2sms' ); ?>
				</p>
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

	/**
	 * Obsługuje wysyłkę testowego SMS z panelu admina.
	 */
	public function handle_send_test(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Brak uprawnień.', 'form2sms' ) );
		}

		check_admin_referer( 'form2sms_send_test', 'form2sms_send_test_nonce' );

		$sender = new Form2SMS_SMS_Sender();
		$ok     = $sender->send_test();

		$redirect = add_query_arg(
			[
				'form2sms_test_result' => $ok ? 'ok' : 'error',
			],
			admin_url( 'tools.php?page=form2sms' )
		);

		wp_safe_redirect( $redirect );
		exit;
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

		// --- Sekcja: Źródło danych z formularza ---
		add_settings_section(
			'form2sms_source',
			__( 'Źródło danych formularza', 'form2sms' ),
			'__return_false',
			'form2sms'
		);

		add_settings_field(
			'form_source',
			__( 'Typ formularza', 'form2sms' ),
			[ $this, 'field_form_source_radio' ],
			'form2sms',
			'form2sms_source',
			[ 'key' => 'form_source' ]
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
			[ $this, 'field_sms_template_cf7' ],
			'form2sms',
			'form2sms_cf7',
			[
				'key'         => 'sms_template_cf7',
				'maxlength'  => 159,
				'description' => __( 'Wstawiaj tagi w nawiasach `[]` (np. [your-name] albo [message]). Maks. 159 znaków. Dla WPForms tagi najlepiej budować z etykiet pól (lowercase + spacje → `-`).', 'form2sms' )
			]
		);

		// --- Sekcja: Formularz WPForms ---
		add_settings_section(
			'form2sms_wpforms',
			__( 'Formularz WPForms', 'form2sms' ),
			'__return_false',
			'form2sms'
		);

		add_settings_field(
			'wpforms_form_id',
			__( 'Aktywny formularz WPForms', 'form2sms' ),
			[ $this, 'field_wpforms_form_select' ],
			'form2sms',
			'form2sms_wpforms',
			[ 'key' => 'wpforms_form_id' ]
		);

		add_settings_field(
			'wpforms_tags_map',
			__( 'Tagi WPForms w wybranym formularzu', 'form2sms' ),
			[ $this, 'field_wpforms_tags_map' ],
			'form2sms',
			'form2sms_wpforms'
		);

		add_settings_field(
			'wpforms_sms_template',
			__( 'Szablon treści SMS (WPForms)', 'form2sms' ),
			[ $this, 'field_sms_template_wpforms' ],
			'form2sms',
			'form2sms_wpforms',
			[
				'key'         => 'sms_template_wpforms',
				'maxlength'  => 159,
				'description' => __( 'Wstawiaj tagi w nawiasach `[]` (np. [imie] albo [wiadomosc]). Maks. 159 znaków. Dla WPForms tagi budujemy z etykiet pól (lowercase + spacje → `-`).', 'form2sms' )
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
			'api_standard_mode',
			__( 'Tryb wysyłki', 'form2sms' ),
			[ $this, 'field_api_standard_mode' ],
			'form2sms',
			'form2sms_api',
			[ 'key' => 'api_standard_mode' ]
		);

		add_settings_field(
			'sender_name',
			__( 'Nazwa nadawcy (pole from)', 'form2sms' ),
			[ $this, 'field_text' ],
			'form2sms',
			'form2sms_api',
			[
				'key'         => 'sender_name',
				'description' => __( 'Opcjonalne. Musi być zweryfikowana w panelu SMSAPI (Pola Nadawcy). W trybie ekonomicznym pole `from` nie jest wysyłane.', 'form2sms' ),
				'maxlength'   => 11,
			]
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

	/**
	 * Radio buttony dla wyboru źródła danych.
	 */
	public function field_form_source_radio( array $args ): void {
		$settings = self::get_settings();
		$key      = $args['key'] ?? 'form_source';
		$source   = (string) ( $settings[ $key ] ?? self::get_defaults()['form_source'] );
		$has_cf7  = $this->is_cf7_active();
		$has_wp   = $this->is_wpforms_active();
		?>
		<fieldset id="form2sms-form-source">
			<label>
				<input type="radio"
					name="form2sms_settings[<?php echo esc_attr( $key ); ?>]"
					value=""
					<?php checked( '' === $source ); ?>>
				<?php esc_html_e( 'Nie wybrano typu', 'form2sms' ); ?>
			</label>
			<br />
			<label>
				<input type="radio"
					name="form2sms_settings[<?php echo esc_attr( $key ); ?>]"
					value="cf7"
					<?php checked( 'cf7' === $source ); ?>
					<?php disabled( ! $has_cf7 ); ?>>
				<?php esc_html_e( 'Contact Form 7', 'form2sms' ); ?>
				<?php if ( ! $has_cf7 ) : ?>
					<span class="description">— <?php esc_html_e( 'nieaktywne', 'form2sms' ); ?></span>
				<?php endif; ?>
			</label>
			<br />
			<label>
				<input type="radio"
					name="form2sms_settings[<?php echo esc_attr( $key ); ?>]"
					value="wpforms"
					<?php checked( 'wpforms' === $source ); ?>
					<?php disabled( ! $has_wp ); ?>>
				<?php esc_html_e( 'WPForms', 'form2sms' ); ?>
				<?php if ( ! $has_wp ) : ?>
					<span class="description">— <?php esc_html_e( 'nieaktywne', 'form2sms' ); ?></span>
				<?php endif; ?>
			</label>
		</fieldset>
		<script>
			( function () {
				const radioSelector = 'input[name="form2sms_settings[form_source]"]';
				const cf7El = document.getElementById( 'form2sms-source-cf7' );
				const wpEl = document.getElementById( 'form2sms-source-wpforms' );
				if ( ! cf7El || ! wpEl ) return;

				function sync() {
					const checked = document.querySelector( radioSelector + ':checked' );
					const val = checked ? checked.value : '';
					cf7El.style.display = ( val === 'cf7' ) ? '' : 'none';
					wpEl.style.display = ( val === 'wpforms' ) ? '' : 'none';
				}

				document.querySelectorAll( radioSelector ).forEach( function ( r ) {
					r.addEventListener( 'change', sync );
				} );

				sync();
			} )();
		</script>
		<?php
	}

	/**
	 * Dropdown z listą aktywnych formularzy WPForms.
	 */
	public function field_wpforms_form_select( array $args ): void {
		$settings = self::get_settings();
		$source   = (string) ( $settings['form_source'] ?? self::get_defaults()['form_source'] );
		$visible  = ( 'wpforms' === $source ) ? '' : 'none';

		$forms = $this->get_wpforms_forms();
		?>
		<div id="form2sms-source-wpforms" style="display:<?php echo esc_attr( $visible ); ?>;">
			<select name="form2sms_settings[wpforms_form_id]">
				<option value="0"><?php esc_html_e( '— wybierz formularz —', 'form2sms' ); ?></option>
				<?php foreach ( $forms as $id => $title ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>"
						<?php selected( (int) $settings['wpforms_form_id'], $id ); ?>>
						<?php echo esc_html( $title ); ?> (ID: <?php echo esc_html( $id ); ?>)
					</option>
				<?php endforeach; ?>
			</select>
			<?php if ( empty( $forms ) ) : ?>
				<p class="description"><?php esc_html_e( 'Brak formularzy WPForms. Utwórz formularz w WPForms.', 'form2sms' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Pole informacyjne: mapa tagów WPForms dla wybranego formularza.
	 */
	public function field_wpforms_tags_map(): void {
		$settings = self::get_settings();
		$source   = (string) ( $settings['form_source'] ?? self::get_defaults()['form_source'] );
		$visible  = ( 'wpforms' === $source ) ? '' : 'none';

		$forms = $this->get_wpforms_forms();
		$tags_by_form_id = [];
		foreach ( $forms as $id => $title ) {
			$tags_by_form_id[ (int) $id ] = $this->get_wpforms_form_tags( (int) $id );
		}
		$tags_by_form_id_json = wp_json_encode( $tags_by_form_id );
		?>
		<div style="display:<?php echo esc_attr( $visible ); ?>;">
			<fieldset id="form2sms-wpforms-tags-fieldset" class="form2sms-wpforms-tags-fieldset" style="display:none; margin-top: 8px;">
				<ul id="form2sms-wpforms-tags-list">
					<li>
						<?php esc_html_e( 'Wybierz formularz powyżej.', 'form2sms' ); ?>
					</li>
				</ul>
			</fieldset>

			<script>
				( function () {
					const select = document.querySelector( 'select[name="form2sms_settings[wpforms_form_id]"]' );
					const list = document.getElementById( 'form2sms-wpforms-tags-list' );
					const fieldset = document.getElementById( 'form2sms-wpforms-tags-fieldset' );
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
		</div>
		<?php
	}

	/**
	 * Pole tekstowe szablonu SMS dla CF7 (widoczne tylko w trybie CF7).
	 */
	public function field_sms_template_cf7( array $args ): void {
		$settings = self::get_settings();
		$source   = (string) ( $settings['form_source'] ?? self::get_defaults()['form_source'] );
		$visible  = ( 'cf7' === $source ) ? '' : 'none';
		?>
		<div style="display:<?php echo esc_attr( $visible ); ?>;">
			<?php $this->field_text( $args ); ?>
		</div>
		<?php
	}

	/**
	 * Pole tekstowe szablonu SMS dla WPForms (widoczne tylko w trybie WPForms).
	 */
	public function field_sms_template_wpforms( array $args ): void {
		$settings = self::get_settings();
		$source   = (string) ( $settings['form_source'] ?? self::get_defaults()['form_source'] );
		$visible  = ( 'wpforms' === $source ) ? '' : 'none';
		?>
		<div style="display:<?php echo esc_attr( $visible ); ?>;">
			<?php $this->field_text( $args ); ?>
		</div>
		<?php
	}

	/** Dropdown z listą aktywnych formularzy CF7. */
	public function field_form_select(): void {
		$settings = self::get_settings();
		$source   = (string) ( $settings['form_source'] ?? self::get_defaults()['form_source'] );
		$visible  = ( 'cf7' === $source ) ? '' : 'none';
		$forms    = $this->get_cf7_forms();
		$tags_by_form_id = [];
		foreach ( $forms as $id => $title ) {
			$tags_by_form_id[ (int) $id ] = $this->get_cf7_form_tags( (int) $id );
		}
		$tags_by_form_id_json = wp_json_encode( $tags_by_form_id );
		?>
		<div id="form2sms-source-cf7" style="display:<?php echo esc_attr( $visible ); ?>;">
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
		</div>
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
		$label    = isset( $args['label'] ) && is_string( $args['label'] ) ? $args['label'] : __( 'Aktywne', 'form2sms' );
		?>
		<label>
			<input type="checkbox"
				name="form2sms_settings[<?php echo esc_attr( $key ); ?>]"
				value="1"
				<?php checked( $checked ); ?>>
			<?php echo esc_html( $label ); ?>
		</label>
		<?php
	}

	/**
	 * Checkbox ON=Standard, OFF=Ekonomiczny dla SMSAPI.
	 */
	public function field_api_standard_mode( array $args ): void {
		$settings = self::get_settings();
		$key      = $args['key'] ?? 'api_standard_mode';
		$checked  = ! empty( $settings[ $key ] );
		?>
		<label>
			<input type="checkbox"
				name="form2sms_settings[<?php echo esc_attr( $key ); ?>]"
				value="1"
				<?php checked( $checked ); ?>>
			<?php esc_html_e( 'Standard (ON) / Ekonomiczny (OFF)', 'form2sms' ); ?>
		</label>
		<p class="description">
			<?php esc_html_e( 'Standard: wysyłamy parametr `from` (wiadomość Pro z polem nadawcy). Ekonomiczny: nie wysyłamy `from` (wiadomość domyślna).', 'form2sms' ); ?>
		</p>
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

		// Źródło danych
		$source = sanitize_text_field( (string) ( $input['form_source'] ?? $defaults['form_source'] ) );
		$clean['form_source'] = in_array( $source, [ '', 'cf7', 'wpforms' ], true )
			? $source
			: (string) $defaults['form_source'];

		// CF7
		$clean['form_id'] = absint( $input['form_id'] ?? $defaults['form_id'] );
		// WPForms
		$clean['wpforms_form_id'] = absint( $input['wpforms_form_id'] ?? $defaults['wpforms_form_id'] );
		$tpl_cf7 = sanitize_text_field( (string) ( $input['sms_template_cf7'] ?? $current['sms_template_cf7'] ?? $defaults['sms_template_cf7'] ) );
		$clean['sms_template_cf7'] = trim( $tpl_cf7 ) !== ''
			? substr( $tpl_cf7, 0, 159 )
			: (string) $defaults['sms_template_cf7'];

		$tpl_wp = sanitize_text_field( (string) ( $input['sms_template_wpforms'] ?? $current['sms_template_wpforms'] ?? $defaults['sms_template_wpforms'] ) );
		$clean['sms_template_wpforms'] = trim( $tpl_wp ) !== ''
			? substr( $tpl_wp, 0, 159 )
			: (string) $defaults['sms_template_wpforms'];

		// SMSAPI
		$clean['api_token']   = sanitize_text_field( $input['api_token'] ?? '' );
		// Numer telefonu: tylko cyfry.
		$clean['admin_phone'] = preg_replace( '/[^0-9]/', '', $input['admin_phone'] ?? '' );
		$clean['api_standard_mode'] = ! empty( $input['api_standard_mode'] );
		$sender_name = sanitize_text_field( (string) ( $input['sender_name'] ?? '' ) );
		$clean['sender_name'] = substr( trim( $sender_name ), 0, 11 );

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
		$settings = self::get_settings();
		if ( ( $settings['form_source'] ?? self::get_defaults()['form_source'] ) !== 'cf7' ) {
			return;
		}
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

	/**
	 * Wyświetla ostrzeżenie w panelu admina, gdy WPForms nie jest aktywny,
	 * a wybrano tryb WPForms.
	 */
	public function maybe_show_wpforms_notice(): void {
		$settings = self::get_settings();
		if ( ( $settings['form_source'] ?? self::get_defaults()['form_source'] ) !== 'wpforms' ) {
			return;
		}
		if ( $this->is_wpforms_active() ) {
			return;
		}

		?>
		<div class="notice notice-error">
			<p>
				<strong><?php esc_html_e( 'Form2SMS:', 'form2sms' ); ?></strong>
				<?php esc_html_e( 'Wtyczka WPForms nie jest zainstalowana lub aktywna. Form2SMS wymaga WPForms do trybu WPForms.', 'form2sms' ); ?>
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
	 * Sprawdza czy WPForms jest aktywny.
	 */
	private function is_wpforms_active(): bool {
		return function_exists( 'wpforms' );
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
	 * Pobiera listę formularzy WPForms: [id => tytuł].
	 */
	private function get_wpforms_forms(): array {
		if ( ! $this->is_wpforms_active() ) {
			return [];
		}

		$posts = get_posts( [
			'post_type'      => 'wpforms',
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
	 * Buduje tagi WPForms na podstawie etykiet pól formularza.
	 *
	 * @param int $form_id ID formularza WPForms.
	 * @return string[] Unikalna, posortowana lista tagów w formacie [tag].
	 */
	private function get_wpforms_form_tags( int $form_id ): array {
		if ( ! $this->is_wpforms_active() || $form_id <= 0 ) {
			return [];
		}

		// WPForms: bez `content_only` metoda get() zwraca WP_Post, nie tablicę z `fields`
		// (por. WPForms_Form_Handler::get_single()).
		$form_data = null;
		if ( function_exists( 'wpforms' ) && is_object( wpforms() ) && isset( wpforms()->form ) && is_object( wpforms()->form ) ) {
			$form_data = wpforms()->form->get( $form_id, [ 'content_only' => true ] );
		}

		if ( ( empty( $form_data ) || ! is_array( $form_data ) ) && current_user_can( 'manage_options' ) ) {
			$post = get_post( $form_id );
			if ( $post instanceof WP_Post && in_array( $post->post_type, [ 'wpforms', 'wpforms-template' ], true ) && function_exists( 'wpforms_decode' ) && is_string( $post->post_content ) && $post->post_content !== '' ) {
				$decoded = wpforms_decode( $post->post_content );
				if ( is_array( $decoded ) ) {
					$form_data = $decoded;
				}
			}
		}

		if ( empty( $form_data ) || ! is_array( $form_data ) ) {
			return [];
		}

		$fields = $form_data['fields'] ?? null;
		if ( empty( $fields ) || ! is_array( $fields ) ) {
			return [];
		}

		$tags = [];
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}

			$raw_label = '';
			if ( ! empty( $field['label'] ) && is_string( $field['label'] ) ) {
				$raw_label = $field['label'];
			} elseif ( ! empty( $field['name'] ) && is_string( $field['name'] ) ) {
				$raw_label = $field['name'];
			}

			$slug = $this->normalize_wpforms_label_for_tag( $raw_label );
			if ( '' === $slug ) {
				continue;
			}

			$tags[] = '[' . $slug . ']';
		}

		$tags = array_values( array_unique( $tags ) );
		sort( $tags, SORT_NATURAL | SORT_FLAG_CASE );
		return $tags;
	}

	/**
	 * Ta sama logika co Form2SMS_WPForms_Handler::normalize_tag_key (spójne tagi w UI i przy wysyłce).
	 *
	 * @param string $raw Etykieta lub nazwa pola z definicji formularza.
	 */
	private function normalize_wpforms_label_for_tag( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}

		$raw = $this->replace_wpforms_label_diacritics( $raw );
		$raw = strtolower( $raw );
		$raw = preg_replace( '/[^a-z0-9_-]+/i', '-', (string) $raw );
		if ( is_string( $raw ) ) {
			$raw = trim( $raw, '-' );
		}

		if ( ! preg_match( '/^[a-z][a-z0-9_-]*$/', (string) $raw ) ) {
			return '';
		}

		return (string) $raw;
	}

	/**
	 * @param string $text
	 */
	private function replace_wpforms_label_diacritics( string $text ): string {
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
		$raw      = (array) get_option( 'form2sms_settings', [] );
		$defaults = self::get_defaults();
		$out      = wp_parse_args( $raw, $defaults );

		// Migracja ze starej opcji `sms_template` (jeden klucz dla obu formularzy w DOM powodował nadpisywanie).
		if ( isset( $raw['sms_template'] ) && is_string( $raw['sms_template'] ) && trim( $raw['sms_template'] ) !== '' ) {
			$legacy = substr( trim( $raw['sms_template'] ), 0, 159 );
			if ( ! array_key_exists( 'sms_template_cf7', $raw ) ) {
				$out['sms_template_cf7'] = $legacy;
			}
			if ( ! array_key_exists( 'sms_template_wpforms', $raw ) ) {
				$out['sms_template_wpforms'] = $legacy;
			}
		}

		return $out;
	}

	/**
	 * Zwraca domyślne ustawienia wtyczki.
	 */
	public static function get_defaults(): array {
		$default_sms = 'Wiadomosc od [your-name] telefon [gsm] email [email] Tresc [message]';

		return [
			'form_source'             => '',
			'form_id'                 => 0,
			'wpforms_form_id'         => 0,
			'sms_template_cf7'        => $default_sms,
			'sms_template_wpforms'    => $default_sms,
			'api_standard_mode'       => false,
			'sender_name'             => '',
			'api_token'               => '',
			'admin_phone'    => '',
			'enabled'        => false,
			'sms_limit'      => 50,
			'sms_count'      => 0,
			'sms_count_date' => '',
		];
	}
}
