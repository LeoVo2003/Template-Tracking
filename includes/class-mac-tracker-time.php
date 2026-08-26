<?php

defined( 'ABSPATH' ) || exit;

/**
 * Display / storage helpers for Thailand – Bangkok (GMT+7).
 */
class MAC_Tracker_Time {

	const TZ = 'Asia/Bangkok';

	/**
	 * Current Bangkok wall-clock as MySQL datetime.
	 *
	 * @return string
	 */
	public static function now_mysql() {
		try {
			$dt = new DateTime( 'now', new DateTimeZone( self::TZ ) );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return gmdate( 'Y-m-d H:i:s', time() + ( 7 * HOUR_IN_SECONDS ) );
		}
	}

	/**
	 * Format a stored UTC/GMT MySQL datetime for Bangkok display.
	 *
	 * @param string $mysql UTC/GMT datetime.
	 * @param string $format PHP date format.
	 * @return string
	 */
	public static function format( $mysql, $format = 'Y-m-d H:i:s' ) {
		$mysql = trim( (string) $mysql );
		if ( '' === $mysql || '0000-00-00 00:00:00' === $mysql ) {
			return '';
		}

		try {
			$dt = date_create( $mysql, new DateTimeZone( 'UTC' ) );
			if ( ! $dt ) {
				$dt = date_create( $mysql );
			}
			if ( ! $dt ) {
				return $mysql;
			}
			$dt->setTimezone( new DateTimeZone( self::TZ ) );
			return $dt->format( $format );
		} catch ( Exception $e ) {
			return $mysql;
		}
	}

	/**
	 * @param string $mysql UTC/GMT datetime.
	 * @return string Y-m-d in Bangkok, or empty.
	 */
	public static function date_part( $mysql ) {
		return self::format( $mysql, 'Y-m-d' );
	}

	/**
	 * @param string $mysql UTC/GMT datetime.
	 * @return string H:i:s in Bangkok, or empty.
	 */
	public static function time_part( $mysql ) {
		return self::format( $mysql, 'H:i:s' );
	}

	/**
	 * Combine Bangkok Date + Time CSV cells into UTC MySQL datetime.
	 *
	 * Accepts Date: Y-m-d | M/D/YYYY | M/D (year defaults to current Bangkok year).
	 * Accepts Time: H:i:s | H:i (empty → 00:00:00).
	 *
	 * @param string $date Bangkok date cell.
	 * @param string $time Bangkok time cell.
	 * @return string|null UTC Y-m-d H:i:s, or null if date invalid.
	 */
	public static function bangkok_parts_to_utc( $date, $time = '' ) {
		$date = trim( (string) $date );
		$time = trim( (string) $time );
		if ( '' === $date ) {
			return null;
		}

		$ymd = '';
		if ( preg_match( '/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $date, $m ) ) {
			$ymd = sprintf( '%04d-%02d-%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
		} elseif ( preg_match( '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $date, $m ) ) {
			$ymd = sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[1], (int) $m[2] );
		} elseif ( preg_match( '/^(\d{1,2})\/(\d{1,2})$/', $date, $m ) ) {
			try {
				$year = (int) ( new DateTime( 'now', new DateTimeZone( self::TZ ) ) )->format( 'Y' );
			} catch ( Exception $e ) {
				$year = (int) gmdate( 'Y', time() + ( 7 * HOUR_IN_SECONDS ) );
			}
			$ymd = sprintf( '%04d-%02d-%02d', $year, (int) $m[1], (int) $m[2] );
		} else {
			return null;
		}

		if ( '' === $time ) {
			$time = '00:00:00';
		} elseif ( preg_match( '/^(\d{1,2}):(\d{2})$/', $time, $m ) ) {
			$time = sprintf( '%02d:%02d:00', (int) $m[1], (int) $m[2] );
		} elseif ( preg_match( '/^(\d{1,2}):(\d{2}):(\d{2})$/', $time, $m ) ) {
			$time = sprintf( '%02d:%02d:%02d', (int) $m[1], (int) $m[2], (int) $m[3] );
		} else {
			return null;
		}

		try {
			$dt = date_create( $ymd . ' ' . $time, new DateTimeZone( self::TZ ) );
			if ( ! $dt ) {
				return null;
			}
			$dt->setTimezone( new DateTimeZone( 'UTC' ) );
			return $dt->format( 'Y-m-d H:i:s' );
		} catch ( Exception $e ) {
			return null;
		}
	}

	/**
	 * Auto-sync window: 10:00–17:59 Bangkok; idle overnight until 10:00 next day.
	 *
	 * @return bool
	 */
	public static function is_auto_sync_window() {
		try {
			$dt = new DateTime( 'now', new DateTimeZone( self::TZ ) );
		} catch ( Exception $e ) {
			$hour = (int) gmdate( 'G', time() + ( 7 * HOUR_IN_SECONDS ) );
			return $hour >= 10 && $hour <= 17;
		}

		$hour = (int) $dt->format( 'G' );
		return $hour >= 10 && $hour <= 17;
	}
}
