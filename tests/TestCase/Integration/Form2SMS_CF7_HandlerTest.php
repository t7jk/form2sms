<?php

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WPCF7_ContactForm' ) ) {
		class WPCF7_ContactForm {
			private int $id;

			public function __construct( int $id ) {
				$this->id = $id;
			}

			public function id(): int {
				return $this->id;
			}
		}
	}

	if ( ! class_exists( 'WPCF7_Submission' ) ) {
		class WPCF7_Submission {
			/** @var null|self */
			private static $instance = null;

			/** @var array<string,string> */
			private array $postedData = [];

			/**
			 * @param array<string,string> $postedData
			 */
			public function __construct( array $postedData ) {
				$this->postedData = $postedData;
			}

			public static function setInstance( ?self $instance ): void {
				self::$instance = $instance;
			}

			public static function get_instance() {
				return self::$instance;
			}

			/**
			 * @return array<string,string>
			 */
			public function get_posted_data(): array {
				return $this->postedData;
			}
		}
	}
}

namespace Form2SMS\Test\TestCase\Integration;

use Form2SMS\Test\TestCase\AppTestCase;

class Form2SMS_CF7_HandlerTest extends AppTestCase {

	public function testHandleSubmissionMapsPostedDataAndSendsSmsWhenFormMatches(): void {
		$formId = 123;

		$this->setPluginSettings( [
			'form_id'        => $formId,
			'field_name'     => 'your-name',
			'field_phone'    => 'your-phone',
			'field_course'   => 'your-subject',
		] );

		$posted = [
			'your-name'    => 'Jan Kowalski',
			'your-phone'   => '500600700',
			'your-subject' => 'Kurs PHP',
		];

		\WPCF7_Submission::setInstance( new \WPCF7_Submission( $posted ) );

		$sender = $this->createMock( \Form2SMS_SMS_Sender::class );
		$sender->expects( $this->once() )
			->method( 'send' )
			->with( [
				'name'   => 'Jan Kowalski',
				'phone'  => '500600700',
				'course' => 'Kurs PHP',
			] )
			->willReturn( true );

		$handler = new \Form2SMS_CF7_Handler( $sender );

		$handler->handle_submission( new \WPCF7_ContactForm( $formId ) );
	}

	public function testHandleSubmissionDoesNothingWhenFormIdDoesNotMatch(): void {
		$this->setPluginSettings( [
			'form_id' => 1,
		] );

		\WPCF7_Submission::setInstance( new \WPCF7_Submission( [
			'your-name'    => 'Jan',
			'your-phone'   => '500600700',
			'your-subject' => 'Kurs',
		] ) );

		$sender = $this->createMock( \Form2SMS_SMS_Sender::class );
		$sender->expects( $this->never() )->method( 'send' );

		$handler = new \Form2SMS_CF7_Handler( $sender );

		$handler->handle_submission( new \WPCF7_ContactForm( 999 ) );
	}

	public function testHandleSubmissionDoesNothingWhenSubmissionIsNull(): void {
		$formId = 10;
		$this->setPluginSettings( [
			'form_id'      => $formId,
			'field_name'    => 'your-name',
			'field_phone'   => 'your-phone',
			'field_course'  => 'your-subject',
		] );

		\WPCF7_Submission::setInstance( null );

		$sender = $this->createMock( \Form2SMS_SMS_Sender::class );
		$sender->expects( $this->never() )->method( 'send' );

		$handler = new \Form2SMS_CF7_Handler( $sender );

		$handler->handle_submission( new \WPCF7_ContactForm( $formId ) );
	}
}

