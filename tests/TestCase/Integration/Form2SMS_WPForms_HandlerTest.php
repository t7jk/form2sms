<?php

declare(strict_types=1);

namespace {
	if ( ! function_exists( 'wpforms' ) ) {
	class WPFormsEntryObjectStub {
		/** @var string */
		public string $fields;

		/**
		 * @param array<int,array<string,mixed>> $fields
		 */
		public function __construct( array $fields ) {
			$this->fields = json_encode( $fields, JSON_UNESCAPED_UNICODE );
		}
	}

	class WPFormsEntryServiceStub {
		/** @var WPFormsEntryObjectStub */
		private WPFormsEntryObjectStub $entry;

		public function __construct( WPFormsEntryObjectStub $entry ) {
			$this->entry = $entry;
		}

		public function get( int $entry_id ) {
			// W testach nie rozróżniamy entry_id.
			return $this->entry;
		}
	}

	class WPFormsStub {
		public WPFormsEntryServiceStub $entry;

		public function __construct( WPFormsEntryServiceStub $entry_service ) {
			$this->entry = $entry_service;
		}
	}

	/**
	 * @return WPFormsStub
	 */
	function wpforms() {
		/** @var WPFormsStub $wpforms_stub */
		global $wpforms_stub;
		return $wpforms_stub;
	}
}

}

namespace Form2SMS\Test\TestCase\Integration {

use Form2SMS\Test\TestCase\AppTestCase;

class Form2SMS_WPForms_HandlerTest extends AppTestCase {
	public function testHandleSubmissionMapsWpformsEntryFieldsAndSendsSmsWhenFormMatches(): void {
		$formId  = 55;
		$entryId = 999;

		$entryFields = [
			0 => [
				'label' => 'Your Name',
				'value' => 'Jan Kowalski',
			],
			1 => [
				'label' => 'GSM',
				'value' => '500600700',
			],
			2 => [
				'label' => 'Email',
				'value' => 'test@example.com',
			],
			3 => [
				'label' => 'Message',
				'value' => 'Kurs PHP',
			],
		];

		// Podstaw WPForms stub na potrzeby testu.
		$entryObj = new \WPFormsEntryObjectStub( $entryFields );
		$service  = new \WPFormsEntryServiceStub( $entryObj );
		$GLOBALS['wpforms_stub'] = new \WPFormsStub( $service );

		$this->setPluginSettings( [
			'form_source'      => 'wpforms',
			'wpforms_form_id'  => $formId,
		] );

		$sender = $this->createMock( \Form2SMS_SMS_Sender::class );
		$sender->expects( $this->once() )
			->method( 'send' )
			->with( [
				'your-name' => 'Jan Kowalski',
				'gsm'       => '500600700',
				'email'     => 'test@example.com',
				'message'   => 'Kurs PHP',
			] )
			->willReturn( true );

		$handler = new \Form2SMS_WPForms_Handler( $sender );

		$handler->handle_submission(
			[],
			[],
			[ 'id' => $formId ],
			$entryId
		);
	}

	public function testHandleSubmissionDoesNothingWhenFormIdDoesNotMatch(): void {
		$entryFields = [
			0 => [
				'label' => 'Your Name',
				'value' => 'Jan Kowalski',
			],
		];

		$entryObj = new \WPFormsEntryObjectStub( $entryFields );
		$service  = new \WPFormsEntryServiceStub( $entryObj );
		$GLOBALS['wpforms_stub'] = new \WPFormsStub( $service );

		$this->setPluginSettings( [
			'form_source'     => 'wpforms',
			'wpforms_form_id' => 123,
		] );

		$sender = $this->createMock( \Form2SMS_SMS_Sender::class );
		$sender->expects( $this->never() )->method( 'send' );

		$handler = new \Form2SMS_WPForms_Handler( $sender );

		$handler->handle_submission(
			[],
			[],
			[ 'id' => 999 ],
			999
		);
	}
}
}

