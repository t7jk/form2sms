<?php

declare(strict_types=1);

namespace Form2SMS\Test\TestCase\Unit;

use Form2SMS\Test\TestCase\AppTestCase;

class Form2SMS_SMS_SenderTest extends AppTestCase {

	/** @var null|callable */
	private $preHttpFilter = null;

	/** @var array<string,mixed> */
	private array $capturedRequest = [];

	protected function tearDown(): void {
		if ( null !== $this->preHttpFilter ) {
			remove_filter( 'pre_http_request', $this->preHttpFilter, 10 );
		}
		$this->preHttpFilter = null;
		$this->capturedRequest = [];

		parent::tearDown();
	}

	private function setPreHttpResponse( int $httpCode, array $body ): void {
		$this->capturedRequest = [];

		$this->preHttpFilter = function( $preempt, $r, $url ) use ( $httpCode, $body ) {
			$this->capturedRequest = [
				'url'  => $url,
				'args' => $r,
			];

			return [
				'response' => [
					'code' => $httpCode,
				],
				'body' => json_encode( $body, JSON_UNESCAPED_UNICODE ),
			];
		};

		// Priority 10, 3 args.
		add_filter( 'pre_http_request', $this->preHttpFilter, 10, 3 );
	}

	private function setPreHttpWPError( string $code, string $message ): void {
		$this->capturedRequest = [];

		$this->preHttpFilter = function( $preempt, $r, $url ) use ( $code, $message ) {
			$this->capturedRequest = [
				'url'  => $url,
				'args' => $r,
			];

			return new \WP_Error( $code, $message );
		};

		add_filter( 'pre_http_request', $this->preHttpFilter, 10, 3 );
	}

	public function testSendReturnsFalseWhenDisabled(): void {
		$this->setPluginSettings( [
			'enabled' => false,
		] );

		$httpCalled = false;

		$this->preHttpFilter = function( $preempt, $r, $url ) use ( &$httpCalled ) {
			$httpCalled = true;
			return [
				'response' => [ 'code' => 200 ],
				'body'     => '{}',
			];
		};

		add_filter( 'pre_http_request', $this->preHttpFilter, 10, 3 );

		$sender = new \Form2SMS_SMS_Sender();
		$ok     = $sender->send( [
			'your-name' => 'Jan Kowalski',
			'gsm'       => '500600700',
			'email'     => 'test@example.com',
			'message'   => 'Test kurs',
		] );

		$this->assertFalse( $ok );
		$this->assertFalse( $httpCalled, 'send() must not call HTTP when disabled.' );
	}

	public function testSendReturnsFalseWhenMissingTokenOrAdminPhone(): void {
		$sender = new \Form2SMS_SMS_Sender();

		// Brak tokena.
		$this->setPluginSettings( [
			'enabled'     => true,
			'api_token'   => '',
			'admin_phone' => '48500600700',
		] );

		$this->preHttpFilter = function() {
			throw new \RuntimeException( 'HTTP should not be called without token.' );
		};
		add_filter( 'pre_http_request', $this->preHttpFilter, 10, 3 );

		$ok = $sender->send( [
			'your-name' => 'Jan Kowalski',
			'gsm'       => '500600700',
			'email'     => 'test@example.com',
			'message'   => 'Test kurs',
		] );
		$this->assertFalse( $ok );

		// Brak numeru admina.
		remove_filter( 'pre_http_request', $this->preHttpFilter, 10 );
		$this->preHttpFilter = null;

		$this->setPluginSettings( [
			'enabled'     => true,
			'api_token'   => 'token-123',
			'admin_phone' => '',
		] );

		$this->preHttpFilter = function() {
			throw new \RuntimeException( 'HTTP should not be called without admin_phone.' );
		};
		add_filter( 'pre_http_request', $this->preHttpFilter, 10, 3 );

		$ok2 = $sender->send( [
			'your-name' => 'Jan Kowalski',
			'gsm'       => '500600700',
			'email'     => 'test@example.com',
			'message'   => 'Test kurs',
		] );
		$this->assertFalse( $ok2 );
	}

	public function testSendRespectsDailyLimit(): void {
		$sender = new \Form2SMS_SMS_Sender();

		$today = gmdate( 'Y-m-d' );
		$this->setPluginSettings( [
			'enabled'        => true,
			'api_token'      => 'token-123',
			'admin_phone'    => '48500600700',
			'sms_limit'      => 2,
			'sms_count'      => 2,
			'sms_count_date' => $today,
		] );

		$httpCalled = false;
		$this->preHttpFilter = function( $preempt, $r, $url ) use ( &$httpCalled ) {
			$httpCalled = true;
			return [
				'response' => [ 'code' => 200 ],
				'body'     => '{}',
			];
		};
		add_filter( 'pre_http_request', $this->preHttpFilter, 10, 3 );

		$ok = $sender->send( [
			'your-name' => 'Jan Kowalski',
			'gsm'       => '500600700',
			'email'     => 'test@example.com',
			'message'   => 'Test kurs',
		] );

		$this->assertFalse( $ok );
		$this->assertFalse( $httpCalled, 'No HTTP call expected when daily limit is reached.' );

		$settings = \Form2SMS_Settings::get_settings();
		$this->assertSame( 2, (int) $settings['sms_count'] );
	}

	public function testSendResetsCounterOnNewDayAndIncrementsOnSuccess(): void {
		$sender = new \Form2SMS_SMS_Sender();

		$today     = gmdate( 'Y-m-d' );
		$yesterday = gmdate( 'Y-m-d', time() - 86400 );

		$this->setPluginSettings( [
			'enabled'         => true,
			'api_token'       => 'token-123',
			'admin_phone'     => '48500600700',
			'sms_limit'       => 10,
			'sms_count'       => 999,
			'sms_count_date'  => $yesterday,
		] );

		$this->setPreHttpResponse( 200, [
			// Brak kluczy error/invalid_numbers oznacza sukces.
		] );

		$ok = $sender->send( [
			'your-name' => 'Jan Kowalski',
			'gsm'       => '500600700',
			'email'     => 'test@example.com',
			'message'   => 'Kurs PHP',
		] );

		$this->assertTrue( $ok );

		$settings = \Form2SMS_Settings::get_settings();
		$this->assertSame( $today, (string) $settings['sms_count_date'] );
		$this->assertSame( 1, (int) $settings['sms_count'], 'After reset, successful send increments by 1.' );
	}

	public function testSendPassesExpectedHeadersAndBodyToApi(): void {
		$sender = new \Form2SMS_SMS_Sender();

		$this->setPluginSettings( [
			'enabled'     => true,
			'api_token'   => 'token-123',
			'admin_phone' => '48500600700',
			'api_standard_mode' => false,
			'sender_name'       => 'MyBrand',
			'sms_limit'   => 100,
			'sms_count'   => 0,
			'sms_count_date' => gmdate( 'Y-m-d' ),
		] );

		$this->setPreHttpResponse( 200, [] );

		$posted = [
			'your-name' => 'Jan Żółć',
			'gsm'       => '500600700',
			'email'     => 'test@example.com',
			'message'   => str_repeat( 'A', 300 ),
		];

		$reflection = new \ReflectionClass( \Form2SMS_SMS_Sender::class );
		$build      = $reflection->getMethod( 'build_message' );
		$build->setAccessible( true );
		$expectedMessage = $build->invoke( $sender, $posted );

		$ok = $sender->send( $posted );
		$this->assertTrue( $ok );

		$this->assertArrayHasKey( 'args', $this->capturedRequest );
		$headers = $this->capturedRequest['args']['headers'] ?? [];
		$auth    = $headers['Authorization'] ?? $headers['authorization'] ?? null;
		$this->assertSame( 'Bearer token-123', $auth );

		$this->assertArrayHasKey( 'body', $this->capturedRequest['args'] );
		$body = $this->capturedRequest['args']['body'];
		$this->assertSame( '48500600700', (string) $body['to'] );
		$this->assertSame( 'json', (string) $body['format'] );
		$this->assertSame( $expectedMessage, (string) $body['message'] );
		$this->assertLessThanOrEqual( 159, strlen( (string) $body['message'] ) );
		$this->assertArrayNotHasKey( 'from', $body, 'Economic mode omits `from` so SMSAPI uses account default sender (set ECO as default in panel).' );
	}

	public function testSendInStandardModeIncludesFromWhenSenderNameProvided(): void {
		$sender = new \Form2SMS_SMS_Sender();

		$this->setPluginSettings( [
			'enabled'           => true,
			'api_token'         => 'token-123',
			'admin_phone'       => '48500600700',
			'api_standard_mode' => true,
			'sender_name'       => 'MyBrand',
			'sms_limit'         => 100,
			'sms_count'         => 0,
			'sms_count_date'    => gmdate( 'Y-m-d' ),
		] );

		$this->setPreHttpResponse( 200, [] );

		$ok = $sender->send( [
			'your-name' => 'Jan Kowalski',
			'gsm'       => '500600700',
			'email'     => 'test@example.com',
			'message'   => 'Kurs PHP',
		] );
		$this->assertTrue( $ok );

		$body = $this->capturedRequest['args']['body'];
		$this->assertSame( 'MyBrand', (string) $body['from'] );
	}

	public function testSendReturnsFalseOnHttpErrorAndDoesNotIncrementCounter(): void {
		$sender = new \Form2SMS_SMS_Sender();

		$this->setPluginSettings( [
			'enabled'         => true,
			'api_token'       => 'token-123',
			'admin_phone'     => '48500600700',
			'sms_limit'       => 10,
			'sms_count'       => 0,
			'sms_count_date'  => gmdate( 'Y-m-d' ),
		] );

		$this->setPreHttpResponse( 500, [] );

		$ok = $sender->send( [
			'your-name' => 'Jan Kowalski',
			'gsm'       => '500600700',
			'email'     => 'test@example.com',
			'message'   => 'Kurs PHP',
		] );

		$this->assertFalse( $ok );
		$settings = \Form2SMS_Settings::get_settings();
		$this->assertSame( 0, (int) $settings['sms_count'] );
	}

	public function testSendReturnsFalseWhenApiBodyHasError(): void {
		$sender = new \Form2SMS_SMS_Sender();

		$this->setPluginSettings( [
			'enabled'         => true,
			'api_token'       => 'token-123',
			'admin_phone'     => '48500600700',
			'sms_limit'       => 10,
			'sms_count'       => 0,
			'sms_count_date'  => gmdate( 'Y-m-d' ),
		] );

		$this->setPreHttpResponse( 200, [
			'error'   => 'bad_request',
			'message' => 'Invalid token',
		] );

		$ok = $sender->send( [
			'your-name' => 'Jan Kowalski',
			'gsm'       => '500600700',
			'email'     => 'test@example.com',
			'message'   => 'Kurs PHP',
		] );

		$this->assertFalse( $ok );
		$settings = \Form2SMS_Settings::get_settings();
		$this->assertSame( 0, (int) $settings['sms_count'] );
	}

	public function testSendReturnsFalseWhenApiBodyHasInvalidNumbers(): void {
		$sender = new \Form2SMS_SMS_Sender();

		$this->setPluginSettings( [
			'enabled'         => true,
			'api_token'       => 'token-123',
			'admin_phone'     => '48500600700',
			'sms_limit'       => 10,
			'sms_count'       => 0,
			'sms_count_date'  => gmdate( 'Y-m-d' ),
		] );

		$this->setPreHttpResponse( 200, [
			'invalid_numbers' => [ '48500600700' ],
		] );

		$ok = $sender->send( [
			'your-name' => 'Jan Kowalski',
			'gsm'       => '500600700',
			'email'     => 'test@example.com',
			'message'   => 'Kurs PHP',
		] );

		$this->assertFalse( $ok );
		$settings = \Form2SMS_Settings::get_settings();
		$this->assertSame( 0, (int) $settings['sms_count'] );
	}

	public function testSendReturnsFalseOnWpError(): void {
		$sender = new \Form2SMS_SMS_Sender();

		$this->setPluginSettings( [
			'enabled'         => true,
			'api_token'       => 'token-123',
			'admin_phone'     => '48500600700',
			'sms_limit'       => 10,
			'sms_count'       => 0,
			'sms_count_date'  => gmdate( 'Y-m-d' ),
		] );

		$this->setPreHttpWPError( 'timeout', 'timeout' );

		$ok = $sender->send( [
			'your-name' => 'Jan Kowalski',
			'gsm'       => '500600700',
			'email'     => 'test@example.com',
			'message'   => 'Kurs PHP',
		] );

		$this->assertFalse( $ok );
		$settings = \Form2SMS_Settings::get_settings();
		$this->assertSame( 0, (int) $settings['sms_count'] );
	}

	public function testSendTestSendsTemplateWithTagNamesAndDoesNotIncrementCounter(): void {
		$sender = new \Form2SMS_SMS_Sender();

		$today = gmdate( 'Y-m-d' );

		$this->setPluginSettings( [
			'enabled'            => false,
			'api_token'          => 'token-123',
			'admin_phone'        => '48500600700',
			'sms_limit'          => 10,
			'sms_count'          => 7,
			'sms_count_date'     => $today,
			'form_source'        => 'cf7',
			'sms_template_cf7'   => 'Wiadomosc od [your-name] telefon [gsm] email [email]',
		] );

		$this->setPreHttpResponse( 200, [] );

		$ok = $sender->send_test();
		$this->assertTrue( $ok );

		// Walidacja wysłanej treści.
		$reflection = new \ReflectionClass( \Form2SMS_SMS_Sender::class );
		$build      = $reflection->getMethod( 'build_test_message' );
		$build->setAccessible( true );
		$expectedMessage = $build->invoke( $sender );

		$this->assertArrayHasKey( 'args', $this->capturedRequest );
		$body = $this->capturedRequest['args']['body'];
		$this->assertSame( '48500600700', (string) $body['to'] );
		$this->assertSame( $expectedMessage, (string) $body['message'] );

		// Test nie powinien inkrementować licznika.
		$settings = \Form2SMS_Settings::get_settings();
		$this->assertSame( 7, (int) $settings['sms_count'] );
	}
}

