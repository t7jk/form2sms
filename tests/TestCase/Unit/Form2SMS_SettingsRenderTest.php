<?php

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		// Minimalny stub do gałęzi "CF7 aktywne".
		class WPCF7_ContactForm {}
	}
}

namespace Form2SMS\Test\TestCase\Unit;

use Form2SMS\Test\TestCase\AppTestCase;

class Form2SMS_SettingsRenderTest extends AppTestCase {

	public function testMaybeShowCf7NoticeProducesNoOutputWhenCf7Active(): void {
		$settingsObj = new \Form2SMS_Settings();

		ob_start();
		$settingsObj->maybe_show_cf7_notice();
		$out = (string) ob_get_clean();

		$this->assertSame( '', $out );
	}

	public function testFieldTextRendersInputWithValue(): void {
		$this->setPluginSettings( [
			'field_name' => 'my-custom-name',
		] );

		$settingsObj = new \Form2SMS_Settings();

		ob_start();
		$settingsObj->field_text( [
			'key'         => 'field_name',
			'description' => 'desc',
		] );
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="form2sms_settings[field_name]"', $out );
		$this->assertStringContainsString( 'value="my-custom-name"', $out );
	}

	public function testFieldCheckboxRendersCheckedWhenEnabled(): void {
		$this->setPluginSettings( [
			'enabled' => true,
		] );

		$settingsObj = new \Form2SMS_Settings();

		ob_start();
		$settingsObj->field_checkbox( [
			'key' => 'enabled',
		] );
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'type="checkbox"', $out );
		$this->assertStringContainsString( 'checked', $out );
	}

	public function testFieldNumberRendersValueAndMinMax(): void {
		$this->setPluginSettings( [
			'sms_limit' => 77,
		] );

		$settingsObj = new \Form2SMS_Settings();

		ob_start();
		$settingsObj->field_number( [
			'key' => 'sms_limit',
			'min' => 1,
			'max' => 9999,
		] );
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'name="form2sms_settings[sms_limit]"', $out );
		$this->assertStringContainsString( 'value="77"', $out );
		$this->assertStringContainsString( 'min="1"', $out );
		$this->assertStringContainsString( 'max="9999"', $out );
	}

	public function testRenderPageOutputsSettingsForm(): void {
		$user_id = self::factory()->user->create( [
			'role' => 'administrator',
		] );
		wp_set_current_user( (int) $user_id );

		$this->setPluginSettings( [
			'sms_count_date' => gmdate( 'Y-m-d' ),
			'sms_count'      => 3,
			'sms_limit'      => 50,
		] );

		$settingsObj = new \Form2SMS_Settings();
		$settingsObj->register_settings();

		ob_start();
		$settingsObj->render_page();
		$out = (string) ob_get_clean();

		$this->assertStringContainsString( 'Form2SMS', $out );
		$this->assertStringContainsString( 'Ustawienia', $out );
		$this->assertStringContainsString( 'action="options.php"', $out );
		$this->assertStringContainsString( 'Wysłano dziś:', $out );
		$this->assertStringContainsString( '3 / 50', $out );
	}
}

