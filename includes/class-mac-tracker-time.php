<?php
defined( 'ABSPATH' ) || exit;

class MAC_Tracker_Time {
	public static function now_utc() { return gmdate( 'Y-m-d H:i:s' ); }

	public static function normalize_utc( $value ) {
		$value = trim( (string) $value );
		if ( '' === $value ) { return null; }
		$stamp = strtotime( $value );
		return false === $stamp ? null : gmdate( 'Y-m-d H:i:s', $stamp );
	}

	public static function on_or_after_bangkok_date( $value, $cutoff ) {
		$value = trim( (string) $value );
		if ( '' === $value ) { return false; }
		try {
			$dt = new DateTime( $value );
			$dt->setTimezone( new DateTimeZone( 'Asia/Bangkok' ) );
			return $dt->format( 'Y-m-d' ) >= (string) $cutoff;
		} catch ( Exception $e ) { return false; }
	}

	public static function csv_bangkok_to_utc( $date, $time ) {
		$date = trim( (string) $date );
		// The legacy pin CSV stores dates like 4/1. Resolve those against the
		// current Bangkok year instead of allowing PHP to guess a timezone/year.
		if ( preg_match( '/^\d{1,2}[\/-]\d{1,2}$/', $date ) ) {
			$date .= '/' . gmdate( 'Y' );
		}
		$value = trim( $date . ' ' . (string) $time );
		if ( '' === trim( $value ) ) { return null; }
		try {
			$local = new DateTime( $value, new DateTimeZone( 'Asia/Bangkok' ) );
			$local->setTimezone( new DateTimeZone( 'UTC' ) );
			return $local->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) { return self::normalize_utc( $value ); }
	}
}
