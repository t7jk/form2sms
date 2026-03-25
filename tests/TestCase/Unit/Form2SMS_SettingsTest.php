<?php

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		// Minimalny stub, żeby `Form2SMS_Settings` uznawał CF7 za aktywne.
		class WPCF7_ContactForm {}
	}
}

namespace Form2SMS\Test\TestCase\Unit;

use Form2SMS\Test\TestCase\AppTestCase;

class Form2SMS_SettingsTest extends AppTestCase {

	public function testGetSettingsReturnsDefaultsWhenOptionMissing(): void {
		delete_option( 'form2sms_settings' );

		$settings = \Form2SMS_Settings::get_settings();
		$defaults = \Form2SMS_Settings::get_defaults();

		$this->assertSame( $defaults, $settings );
	}

	public function testSanitizeSettingsSanitizesAndPreservesCounters(): void {
		// Zasymuluj istniejące liczniki (sanitize_settings nie powinno ich nadpisywać).
		update_option( 'form2sms_settings', [
			...$this->getDefaultSettings(),
			'sms_count'      => 7,
			'sms_count_date' => '2020-01-01',
		] );

		$settingsObj = new \Form2SMS_Settings();

		$input = [
			'form_id'      => '-12',
			'sms_template' => '<script>alert(1)</script>Wiadomosc od [your-name]',
			'api_token'    => ' token-123 ',
			'admin_phone'  => '48 500-600-700',
			'enabled'      => '1',
			'sms_limit'    => '0',
			// Próba nadpisania liczników - sanitize_settings i tak ma je zachować z aktualnych ustawień.
			'sms_count'      => 999,
			'sms_count_date' => '2099-12-31',
		];

		$clean = $settingsObj->sanitize_settings( $input );

		$this->assertSame( 12, $clean['form_id'] );
		$this->assertSame( 'alert(1)Wiadomosc od [your-name]', $clean['sms_template'] );

		$this->assertSame( 'token-123', $clean['api_token'] );
		$this->assertSame( '48500600700', $clean['admin_phone'] );

		$this->assertTrue( $clean['enabled'] );
		$this->assertSame( 1, $clean['sms_limit'], 'sms_limit = max(1, absint(...))' );

		$this->assertSame( 7, (int) $clean['sms_count'] );
		$this->assertSame( '2020-01-01', $clean['sms_count_date'] );
	}

	public function testSanitizeSettingsDisabledWhenCheckboxNotProvided(): void {
		delete_option( 'form2sms_settings' );

		$settingsObj = new \Form2SMS_Settings();

		$clean = $settingsObj->sanitize_settings( [
			'form_id'    => 1,
			'enabled'    => 0,
			'sms_limit'  => 10,
			'admin_phone'=> '123',
		] );

		$this->assertFalse( (bool) $clean['enabled'], 'enabled should be false when input enables nothing.' );
		$this->assertSame( 10, (int) $clean['sms_limit'] );
	}

	public function testFieldFormSelectRendersCf7FormsOptions(): void {
		register_post_type( 'wpcf7_contact_form', [
			'public' => false,
			'label'  => 'CF7 contact form',
		] );

		$form1 = self::factory()->post->create( [
			'post_type'   => 'wpcf7_contact_form',
			'post_title'  => 'Form A',
			'post_status' => 'publish',
		] );

		$form2 = self::factory()->post->create( [
			'post_type'   => 'wpcf7_contact_form',
			'post_title'  => 'Form B',
			'post_status' => 'publish',
		] );

		$this->setPluginSettings( [
			'form_id' => $form2,
		] );

		$settingsObj = new \Form2SMS_Settings();

		ob_start();
		$settingsObj->field_form_select();
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'Form A', $out );
		$this->assertStringContainsString( 'Form B', $out );
		$this->assertStringContainsString( 'value="' . (int) $form2 . '"', $out );
		$this->assertStringContainsString( 'selected', $out );
	}

	public function testFieldFormSelectShowsNoFormsMessageWhenEmpty(): void {
		register_post_type( 'wpcf7_contact_form', [
			'public' => false,
			'label'  => 'CF7 contact form',
		] );

		// Upewnij się, że nie ma postów dla tego typu.
		$q = new \WP_Query( [
			'post_type'      => 'wpcf7_contact_form',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
		] );

		foreach ( $q->posts as $p ) {
			wp_delete_post( (int) $p->ID, true );
		}

		$this->setPluginSettings( [
			'form_id' => 0,
		] );

		$settingsObj = new \Form2SMS_Settings();

		ob_start();
		$settingsObj->field_form_select();
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'Brak formularzy CF7', $out );
	}
}

