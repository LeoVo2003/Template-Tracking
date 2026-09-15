<?php

defined( 'ABSPATH' ) || exit;

/**
 * Converts WPM payload variants into one predictable internal contract.
 */
class MAC_Tracker_Normalizer {

	const ACTION_TASK_NAME = '[Website] Action Design';

	/**
	 * Extract project items from supported WPM response shapes.
	 *
	 * @param mixed $response Decoded JSON response.
	 * @return array
	 */
	public static function response_items( $response ) {
		if ( ! is_array( $response ) ) {
			return array();
		}

		if ( self::is_list( $response ) ) {
			return $response;
		}

		foreach ( array( 'data', 'projects', 'items' ) as $key ) {
			if ( isset( $response[ $key ] ) && is_array( $response[ $key ] ) ) {
				return self::is_list( $response[ $key ] )
					? $response[ $key ]
					: ( isset( $response[ $key ]['id'] ) ? array( $response[ $key ] ) : array() );
			}
		}
		if ( isset( $response['id'] ) ) {
			return array( $response );
		}

		return array();
	}

	/**
	 * Read pagination metadata without assuming one WPM spelling.
	 *
	 * @param mixed $response Decoded JSON response.
	 * @return array{page:int,per_page:int,total_pages:?int,total:?int}
	 */
	public static function pagination( $response ) {
		$pagination = is_array( $response ) && isset( $response['pagination'] ) && is_array( $response['pagination'] )
			? $response['pagination']
			: ( is_array( $response ) ? $response : array() );
		$page       = self::first_int( $pagination, array( 'page', 'page_index', 'current_page' ), 1 );
		$per_page   = self::first_int( $pagination, array( 'per_page', 'page_size', 'limit' ), 100 );
		$total_page = self::first_nullable_int( $pagination, array( 'total_pages', 'total_page', 'last_page' ) );
		$total      = self::first_nullable_int( $pagination, array( 'total', 'total_count', 'count' ) );

		return array(
			'page'        => max( 1, $page ),
			'per_page'    => max( 1, $per_page ),
			'total_pages' => $total_page,
			'total'       => $total,
		);
	}

	/**
	 * Normalize one project and preserve raw data for later phases.
	 *
	 * @param mixed $project Raw project.
	 * @return array|null
	 */
	public static function project( $project ) {
		if ( ! is_array( $project ) ) {
			return null;
		}

		$id = self::int_value( $project['id'] ?? $project['project_id'] ?? 0 );
		if ( $id <= 0 ) {
			return null;
		}

		$tasks = array();
		if ( isset( $project['tasks'] ) && is_array( $project['tasks'] ) ) {
			foreach ( $project['tasks'] as $task ) {
				$normalized_task = self::task( $task );
				if ( null !== $normalized_task ) {
					$tasks[] = $normalized_task;
				}
			}
		}

		$domain = self::url( $project['domain_url'] ?? $project['domain'] ?? '' );

		return array(
			'id'              => $id,
			'name'            => self::text( $project['name'] ?? $project['full_name'] ?? '' ),
			'zipcode'         => self::text( $project['zipcode'] ?? $project['zip_code'] ?? '' ),
			'package'         => self::text( $project['package'] ?? '' ),
			'account_manager' => self::person( $project['account_manager'] ?? $project['am'] ?? null ),
			'assignee'        => self::person( $project['assignee'] ?? null ),
			'domain'          => $domain,
			'domain_host'     => self::host( $domain ),
			'layout'          => self::url( $project['web_layout'] ?? $project['layout_url'] ?? '' ),
			'status'          => self::text( $project['status'] ?? '' ),
			'updated_at'      => self::text( $project['updated_at'] ?? '' ),
			'created_at'      => self::text( $project['created_at'] ?? '' ),
			'is_archived'     => ! empty( $project['is_archived'] ),
			'tasks'           => $tasks,
			'raw'             => $project,
		);
	}

	/**
	 * Normalize a task, including task type variants.
	 *
	 * @param mixed $task Raw task.
	 * @return array|null
	 */
	public static function task( $task ) {
		if ( ! is_array( $task ) ) {
			return null;
		}

		$id = self::int_value( $task['id'] ?? $task['task_id'] ?? 0 );
		if ( $id <= 0 ) {
			return null;
		}

		$type = $task['task_type'] ?? $task['task_type_name'] ?? $task['type'] ?? $task['title'] ?? $task['name'] ?? null;
		if ( is_array( $type ) ) {
			$type = $type['name'] ?? $type['title'] ?? '';
		}

		$extra = $task['extra_data'] ?? $task['extra'] ?? array();
		if ( ! is_array( $extra ) ) {
			$extra = array();
		}

		return array(
			'id'             => $id,
			'type_name'      => self::text( $type ),
			'is_action_design' => self::ACTION_TASK_NAME === self::text( $type ),
			'status'         => self::text( $task['status'] ?? '' ),
			'completed_at'   => self::text( $task['completed_at'] ?? $task['completedAt'] ?? $task['completed_date'] ?? $task['done_at'] ?? $task['done_date'] ?? $task['doneDate'] ?? '' ),
			'due_at'         => self::text( $task['due_date'] ?? $task['due_at'] ?? $task['dueDate'] ?? $task['due_datetime'] ?? $task['due'] ?? $task['end_date'] ?? $task['end_at'] ?? '' ),
			'demo_url'       => self::url( $extra['web_demo_url'] ?? $task['web_demo_url'] ?? '' ),
			'layout_url'     => self::url( $extra['web_layout'] ?? $task['web_layout'] ?? '' ),
			'color_template' => self::text( $extra['color_template'] ?? $task['color_template'] ?? '' ),
			'extra'          => $extra,
			'raw'            => $task,
		);
	}

	/**
	 * Normalize a URL-like value without changing the original raw payload.
	 *
	 * @param mixed $value URL-like value.
	 * @return string
	 */
	public static function url( $value ) {
		if ( is_array( $value ) ) {
			$value = $value['url'] ?? $value['link'] ?? $value['href'] ?? '';
		}

		return trim( self::text( $value ) );
	}

	/**
	 * Normalize host for exact domain matching.
	 *
	 * @param mixed $value URL or host.
	 * @return string
	 */
	public static function host( $value ) {
		$value = trim( self::url( $value ) );
		if ( '' === $value ) {
			return '';
		}

		$parsed = wp_parse_url( 0 === strpos( $value, '//' ) ? 'https:' . $value : $value );
		$host   = is_array( $parsed ) && ! empty( $parsed['host'] ) ? $parsed['host'] : $value;
		$host   = strtolower( trim( $host, " \t\n\r\0\x0B." ) );

		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * @param mixed $value Person value.
	 * @return array{name:string,id:int}|null
	 */
	private static function person( $value ) {
		if ( ! is_array( $value ) ) {
			$value = array( 'name' => $value );
		}

		$name = self::text( $value['name'] ?? $value['full_name'] ?? $value['title'] ?? '' );
		$id   = self::int_value( $value['id'] ?? 0 );
		return '' === $name && $id <= 0 ? null : array( 'name' => $name, 'id' => $id );
	}

	private static function text( $value ) {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private static function int_value( $value ) {
		return is_numeric( $value ) ? (int) $value : 0;
	}

	private static function first_int( array $source, array $keys, $fallback ) {
		foreach ( $keys as $key ) {
			if ( isset( $source[ $key ] ) && is_numeric( $source[ $key ] ) ) {
				return (int) $source[ $key ];
			}
		}
		return (int) $fallback;
	}

	private static function first_nullable_int( array $source, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $source[ $key ] ) && is_numeric( $source[ $key ] ) ) {
				return (int) $source[ $key ];
			}
		}
		return null;
	}

	private static function is_list( array $value ) {
		$expected = 0;
		foreach ( array_keys( $value ) as $key ) {
			if ( $key !== $expected++ ) {
				return false;
			}
		}
		return true;
	}
}
