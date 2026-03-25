<?php

declare(strict_types=1);

namespace Form2SMS\Test\TestCase\Regression;

use Form2SMS\Test\TestCase\AppTestCase;

class KnownBugsTest extends AppTestCase {

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
				'response' => [ 'code' => $httpCode ],
				'body'     => json_encode( $body, JSON_UNESCAPED_UNICODE ),
			];
		};

		add_filter( 'pre_http_request', $this->preHttpFilter, 10, 3 );
	}

	public function testRegressionDailyLimitResetsWhenNewDay(): void {
		$this->regression(
			'BUG-002',
			'Nie resetowano licznikow SMS po zmianie dnia'
		);

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

		$this->setPreHttpResponse( 200, [] );

		$ok = $sender->send( [
			'your-name' => 'Jan Kowalski',
			'gsm'       => '500600700',
			'email'     => 'test@example.com',
			'message'   => 'Kurs PHP',
		] );

		$this->assertTrue( $ok );

		$settings = \Form2SMS_Settings::get_settings();
		$this->assertSame( $today, (string) $settings['sms_count_date'] );
		$this->assertSame( 1, (int) $settings['sms_count'] );
	}

	public function testRegressionDiacriticsRemovedFromMessage(): void {
		$this->regression(
			'BUG-001',
			'Wiadomosc SMS mogla zawierac polskie znaki diakrytyczne'
		);

		$sender = new \Form2SMS_SMS_Sender();

		$this->setPluginSettings( [
			'enabled'     => true,
			'api_token'   => 'token-123',
			'admin_phone' => '48500600700',
			'sms_limit'   => 10,
			'sms_count'   => 0,
			'sms_count_date' => gmdate( 'Y-m-d' ),
		] );

		$this->setPreHttpResponse( 200, [] );

		$ok = $sender->send( [
			'your-name' => 'Jan Żółć',
			'gsm'       => '500600700',
			'email'     => 'test@example.com',
			'message'   => 'Wstęp do Żarć',
		] );

		$this->assertTrue( $ok );

		$body = $this->capturedRequest['args']['body'];
		$message = (string) $body['message'];

		$dangerChars = [ 'ą', 'ć', 'ę', 'ł', 'ń', 'ó', 'ś', 'ź', 'ż', 'Ą', 'Ć', 'Ę', 'Ł', 'Ń', 'Ó', 'Ś', 'Ź', 'Ż' ];
		foreach ( $dangerChars as $ch ) {
			$this->assertFalse( strpos( $message, $ch ) !== false, "Message must not contain diacritic: {$ch}" );
		}
	}

	public function testRegressionInvalidNumbersCausesSendFailure(): void {
		$this->regression(
			'BUG-003',
			'Gdy API zwracalo invalid_numbers, send nadal zwracal success'
		);

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
}

