<?php

declare(strict_types=1);

namespace Form2SMS\Test\TestCase;

use WP_UnitTestCase;

/**
 * Wspolna baza dla testow wtyczki.
 *
 * W pluginie nie ma wlasnych tabel w bazie, ale opcje w WP ida do DB,
 * wiec mozemy wykrywac nieoczekiwane zapytania (N+1) przez $wpdb->num_queries.
 */
abstract class AppTestCase extends WP_UnitTestCase {

	/**
	 * Ustawienia domyslne pluginu.
	 *
	 * @return array<string,mixed>
	 */
	protected function getDefaultSettings(): array {
		return \Form2SMS_Settings::get_defaults();
	}

	/**
	 * Nadpisz `form2sms_settings` w bazie.
	 *
	 * @param array<string,mixed> $overrides
	 */
	protected function setPluginSettings( array $overrides = [] ): void {
		$defaults = $this->getDefaultSettings();
		$next     = array_merge( $defaults, $overrides );
		update_option( 'form2sms_settings', $next );
	}

	/**
	 * Prosty helper do wykrywania N+1 (liczymy liczbę query w $wpdb->num_queries).
	 *
	 * @param int $maxQueries
	 * @param callable():void $callback
	 */
	protected function assertMaxQueries( int $maxQueries, callable $callback, string $message = '' ): void {
		global $wpdb;
		// W testach WP $wpdb powinien zawsze być dostępny.

		$before = (int) $wpdb->num_queries;
		$callback();
		$after  = (int) $wpdb->num_queries;
		$actual = $after - $before;

		$this->assertLessThanOrEqual( $maxQueries, $actual, $message ?: "Max queries exceeded: {$actual} > {$maxQueries}" );
	}

	/**
	 * Wspolny setter dla opcji licznika SMS (dzieki temu testy sa izolowane).
	 */
	protected function resetCountersToToday( int $smsCount = 0, ?string $date = null ): void {
		$date = $date ?: gmdate( 'Y-m-d' );
		$this->setPluginSettings( [
			'sms_count'      => $smsCount,
			'sms_count_date' => $date,
		] );
	}

	/**
	 * Oznacza test jako zabezpieczenie konkretnego buga.
	 * Dla czytelności raportu zapisujemy mapę w pliku.
	 */
	protected function regression( string $bugId, string $description ): void {
		$logFile = 'tests/reports/regression_map.json';
		$map     = file_exists( $logFile )
			? json_decode( (string) file_get_contents( $logFile ), true )
			: [];

		if ( ! is_array( $map ) ) {
			$map = [];
		}

		$map[ $bugId ] = [
			'description' => $description,
			'test'        => get_class( $this ) . '::' . $this->getName(),
			'date'        => gmdate( 'Y-m-d' ),
		];

		file_put_contents( $logFile, json_encode( $map, JSON_PRETTY_PRINT ) );
	}
}

