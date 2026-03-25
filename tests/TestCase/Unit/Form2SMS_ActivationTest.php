<?php

declare(strict_types=1);

namespace Form2SMS\Test\TestCase\Unit;

use Form2SMS\Test\TestCase\AppTestCase;

class Form2SMS_ActivationTest extends AppTestCase {

	public function testActivationCreatesDefaultSettingsWhenMissing(): void {
		delete_option( 'form2sms_settings' );

		form2sms_activate();

		$option = get_option( 'form2sms_settings' );
		$defaults = \Form2SMS_Settings::get_defaults();

		$this->assertSame( $defaults, $option );
	}

	public function testActivationDoesNotOverrideExistingSettings(): void {
		$custom = \Form2SMS_Settings::get_defaults();
		$custom['enabled'] = true;
		$custom['sms_limit'] = 999;

		update_option( 'form2sms_settings', $custom );

		form2sms_activate();

		$option = get_option( 'form2sms_settings' );
		$this->assertSame( true, (bool) $option['enabled'] );
		$this->assertSame( 999, (int) $option['sms_limit'] );
	}
}

