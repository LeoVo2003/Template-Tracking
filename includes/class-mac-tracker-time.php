<?php
defined( 'ABSPATH' ) || exit;

class MAC_Tracker_Time {
	public static function now_utc() { return gmdate( 'Y-m-d H:i:s' ); }

	public static function bangkok_label( $utc_value ) {
		$parts = self::bangkok_parts( $utc_value );
		return $parts ? $parts['date'] . ' ' . $parts['time'] . ' ICT' : '—';
	}

	public static function bangkok_date( $utc_value ) {
		$parts = self::bangkok_parts( $utc_value );
		return $parts ? $parts['date'] : '—';
	}

	public static function bangkok_time( $utc_value ) {
		$parts = self::bangkok_parts( $utc_value );
		return $parts ? $parts['time'] : '—';
	}

	public static function bangkok_input( $utc_value ) {
		$utc_value = trim( (string) $utc_value );
		if ( '' === $utc_value ) { return ''; }
		try { $date = new DateTime( $utc_value, new DateTimeZone( 'UTC' ) ); $date->setTimezone( new DateTimeZone( 'Asia/Bangkok' ) ); return $date->format( 'Y-m-d\\TH:i' ); } catch ( Exception $exception ) { return ''; }
	}

	private static function bangkok_parts( $utc_value ) {
		$utc_value = trim( (string) $utc_value );
		if ( '' === $utc_value ) { return null; }
		try {
			$date = new DateTime( $utc_value, new DateTimeZone( 'UTC' ) );
			$date->setTimezone( new DateTimeZone( 'Asia/Bangkok' ) );
			return array( 'date' => $date->format( 'd/m/Y' ), 'time' => $date->format( 'H:i' ) );
		} catch ( Exception $exception ) { return null; }
	}

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
		$time = trim( (string) $time );
		if ( '' === $date ) { return null; }
		// The cleaned master roster uses dd/mm/yyyy while the original legacy
		// rows use m/d without a year. Parse each shape explicitly; DateTime's
		// locale guessing was turning many valid July/August dates into blanks.
		if ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date ) ) {
			$local = DateTime::createFromFormat( '!d/m/Y H:i', $date . ' ' . ( '' === $time ? '00:00' : $time ), new DateTimeZone( 'Asia/Bangkok' ) );
			if ( $local instanceof DateTime ) { $local->setTimezone( new DateTimeZone( 'UTC' ) ); return $local->format( 'Y-m-d H:i:s' ); }
		}
		// The legacy pin CSV stores dates like 4/1. Resolve those against the
		// current Bangkok year instead of allowing PHP to guess a timezone/year.
		if ( preg_match( '/^\d{1,2}[\/-]\d{1,2}$/', $date ) ) {
			$date .= '/' . gmdate( 'Y' );
			$local = DateTime::createFromFormat( '!m/d/Y H:i', $date . ' ' . ( '' === $time ? '00:00' : $time ), new DateTimeZone( 'Asia/Bangkok' ) );
			if ( $local instanceof DateTime ) { $local->setTimezone( new DateTimeZone( 'UTC' ) ); return $local->format( 'Y-m-d H:i:s' ); }
		}
		$value = trim( $date . ' ' . $time );
		if ( '' === trim( $value ) ) { return null; }
		try {
			$local = new DateTime( $value, new DateTimeZone( 'Asia/Bangkok' ) );
			$local->setTimezone( new DateTimeZone( 'UTC' ) );
			return $local->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) { return self::normalize_utc( $value ); }
	}
}
