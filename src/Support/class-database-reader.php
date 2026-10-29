<?php
/**
 * Confined, read-only installation database administration.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * Uses only the existing WordPress database connection and prepared SELECTs.
 * Permission checks belong to the Ability wrapper; this store enforces scope.
 */
final class Database_Reader {
	/** @var int Raw byte allowance before MCP/base64 encoding. */
	private $budget;

	/** @param int $budget Per-request raw byte allowance. */
	public function __construct( $budget ) {
		$this->budget = max( 1, (int) $budget );
	}

	/**
	 * Execute an action-specific, validated read request.
	 *
	 * @param array $input Validated request.
	 * @return array|WP_Error
	 */
	public function execute( array $input ) {
		global $wpdb;
		if ( ! is_object( $wpdb ) || ! is_string( $wpdb->base_prefix ) || '' === $wpdb->base_prefix ||
			! $wpdb->has_cap( 'identifier_placeholders' ) ) {
			return new WP_Error( 'database_unavailable', __( 'The WordPress connection needs a nonempty installation prefix and identifier-placeholder support.', 'wp-ai-bridge' ) );
		}
		if ( ! isset( $input['action'] ) || ! in_array( $input['action'], array( 'tables', 'schema', 'rows', 'cell' ), true ) ) {
			return new WP_Error( 'database_input_invalid', __( 'Select a supported database read action.', 'wp-ai-bridge' ) );
		}
		if ( 'tables' === $input['action'] ) {
			return $this->tables( $input );
		}
		if ( ! isset( $input['table'] ) || ! is_string( $input['table'] ) ||
			0 !== strncmp( $input['table'], $wpdb->base_prefix, strlen( $wpdb->base_prefix ) ) ) {
			return new WP_Error( 'database_scope_denied', __( 'Select a table belonging to this WordPress installation prefix.', 'wp-ai-bridge' ) );
		}
		$found = $this->read(
			$wpdb->prepare(
				"SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME = BINARY %s AND TABLE_TYPE = 'BASE TABLE' LIMIT 1",
				$input['table']
			)
		);
		if ( is_wp_error( $found ) ) {
			return $found;
		}
		if ( ! $found ) {
			return new WP_Error( 'database_table_unavailable', __( 'The exact base table is unavailable to the WordPress database account. Views and other databases are not supported.', 'wp-ai-bridge' ) );
		}
		return 'schema' === $input['action'] ? $this->schema( $input ) : $this->values( $input );
	}

	/** @param array $input Validated request. @return array|WP_Error */
	private function tables( array $input ) {
		global $wpdb;
		$items = $this->read(
			$wpdb->prepare(
				"SELECT TABLE_NAME AS name, ENGINE AS engine, TABLE_ROWS AS estimated_rows, DATA_LENGTH AS data_bytes, INDEX_LENGTH AS index_bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND LEFT(BINARY TABLE_NAME, %d) = BINARY %s AND BINARY TABLE_NAME > BINARY %s ORDER BY BINARY TABLE_NAME LIMIT %d",
				strlen( $wpdb->base_prefix ),
				$wpdb->base_prefix,
				$input['after_table'] ?? '',
				$input['limit'] + 1
			)
		);
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$more  = count( $items ) > $input['limit'];
		$items = array_slice( $items, 0, $input['limit'] );
		return array(
			'items'       => $items,
			'has_more'    => $more,
			'after_table' => $more && $items ? end( $items )['name'] : null,
			'scope'       => 'current_database_installation_prefix',
			'consistency' => 'live_not_snapshot',
		);
	}

	/** @param array $input Validated request. @return array|WP_Error */
	private function schema( array $input ) {
		global $wpdb;
		$section = $input['section'] ?? 'columns';
		$offset  = $input['offset'] ?? 0;
		if ( 'indexes' === $section ) {
			$sql = $wpdb->prepare(
				'SELECT INDEX_NAME AS name, NON_UNIQUE AS non_unique, SEQ_IN_INDEX AS position, COLUMN_NAME AS column_name, SUB_PART AS prefix_length, INDEX_TYPE AS index_type FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME = BINARY %s ORDER BY INDEX_NAME, SEQ_IN_INDEX LIMIT %d OFFSET %d',
				$input['table'],
				$input['limit'] + 1,
				$offset
			);
		} else {
			// Defaults/comments can contain private values; do not list them.
			// ENUM/SET definitions can be large, so bound their metadata too.
			$sql = $wpdb->prepare(
				'SELECT COLUMN_NAME AS name, SUBSTRING(COLUMN_TYPE, 1, %d) AS sql_type, OCTET_LENGTH(COLUMN_TYPE) AS sql_type_bytes, DATA_TYPE AS data_type, IS_NULLABLE AS nullable, CHARACTER_SET_NAME AS character_set, COLLATION_NAME AS collation_name, ORDINAL_POSITION AS position, (COLUMN_DEFAULT IS NOT NULL) AS has_default FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME = BINARY %s ORDER BY ORDINAL_POSITION LIMIT %d OFFSET %d',
				max( 1, intdiv( $this->budget, $input['limit'] + 1 ) ),
				$input['table'],
				$input['limit'] + 1,
				$offset
			);
		}
		$items = $this->read( $sql );
		if ( is_wp_error( $items ) ) {
			return $items;
		}
		$more  = count( $items ) > $input['limit'];
		$items = array_slice( $items, 0, $input['limit'] );
		if ( 'columns' === $section ) {
			foreach ( $items as &$item ) {
				$item['sql_type_truncated'] = strlen( $item['sql_type'] ) < (int) $item['sql_type_bytes'];
			}
			unset( $item );
		}
		return array(
			'table'       => $input['table'],
			'section'     => $section,
			'items'       => $items,
			'has_more'    => $more,
			'next_offset' => $more ? $offset + $input['limit'] : null,
			'consistency' => 'live_not_snapshot',
		);
	}

	/** @param array $input Validated request. @return array|WP_Error */
	private function values( array $input ) {
		global $wpdb;
		$is_cell = 'cell' === $input['action'];
		$columns = $is_cell ? array( $input['column'] ) : $input['columns'];
		$filters = $input['key'] ?? $input['filters'] ?? array();
		$hashes  = $input['key_hashes'] ?? array();
		$named   = array_values( array_unique( array_merge( $columns, array_keys( $filters ), array_keys( $hashes ) ) ) );
		// Inspect only named columns, never values or all provider tables.
		$column_sql = $wpdb->prepare(
			'SELECT COLUMN_NAME AS name, DATA_TYPE AS data_type FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME = BINARY %s AND BINARY COLUMN_NAME IN (' . implode( ', ', array_fill( 0, count( $named ), '%s' ) ) . ')',
			array_merge( array( $input['table'] ), $named )
		);
		$known      = $this->read( $column_sql );
		if ( is_wp_error( $known ) ) {
			return $known;
		}
		if ( count( $known ) !== count( $named ) || array_diff( $named, array_column( $known, 'name' ) ) ) {
			return new WP_Error( 'database_column_unavailable', __( 'Select exact columns from the current table schema.', 'wp-ai-bridge' ) );
		}
		$types = array_column( $known, 'data_type', 'name' );
		$keys  = $this->read(
			$wpdb->prepare(
				"SELECT s.COLUMN_NAME AS name, c.DATA_TYPE AS data_type FROM information_schema.STATISTICS s JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = s.TABLE_SCHEMA AND BINARY c.TABLE_NAME = BINARY s.TABLE_NAME AND BINARY c.COLUMN_NAME = BINARY s.COLUMN_NAME WHERE s.TABLE_SCHEMA = DATABASE() AND BINARY s.TABLE_NAME = BINARY %s AND s.INDEX_NAME = 'PRIMARY' ORDER BY s.SEQ_IN_INDEX",
				$input['table']
			)
		);
		if ( is_wp_error( $keys ) ) {
			return $keys;
		}
		$numeric = 1 === count( $keys ) && in_array( $keys[0]['data_type'], array( 'tinyint', 'smallint', 'mediumint', 'int', 'bigint' ), true );
		if ( isset( $input['after_id'] ) && ( ! $numeric || ! empty( $input['offset'] ) ) ) {
			return new WP_Error( 'database_cursor_invalid', __( 'after_id requires one integer primary key and cannot be combined with an offset.', 'wp-ai-bridge' ) );
		}
		$limit      = $is_cell ? 2 : $input['limit'] + 1;
		$key_window = $numeric ? 21 : max( 1, intdiv( $this->budget, $limit * ( count( $columns ) + count( $keys ) ) ) );
		$key_bytes  = $is_cell ? 0 : $limit * count( $keys ) * $key_window;
		$available  = $this->budget - $key_bytes;
		if ( ! $is_cell && $available < $limit * count( $columns ) ) {
			return new WP_Error( 'database_response_budget', __( 'This page exceeds the configured response budget. Request fewer records/columns or a shorter cell window, or adjust the response policy in WP AI Bridge Settings.', 'wp-ai-bridge' ) );
		}
		$window = $is_cell ? ( $input['length'] ?? min( 8192, $this->budget ) ) : intdiv( $available, $limit * count( $columns ) );
		$start  = $is_cell ? ( $input['offset'] ?? 0 ) : 0;
		$select = array();
		foreach ( $columns as $index => $column ) {
			$select[] = $wpdb->prepare( 'SUBSTRING(CAST(%i AS BINARY), %d, %d)', $column, $start + 1, $window ) . ' AS v' . $index;
			$select[] = $wpdb->prepare( 'OCTET_LENGTH(%i)', $column ) . ' AS n' . $index;
			$select[] = $wpdb->prepare( 'SHA2(CAST(%i AS BINARY), 256)', $column ) . ' AS h' . $index;
		}
		if ( ! $is_cell ) {
			foreach ( $keys as $index => $key ) {
				// A prefix-indexed TEXT/BLOB primary key can have an enormous
				// full value. Bound the projection before PHP receives any bytes.
				$select[] = $wpdb->prepare( 'SUBSTRING(CAST(%i AS BINARY), 1, %d)', $key['name'], $key_window ) . ' AS k' . $index;
				$select[] = $wpdb->prepare( 'OCTET_LENGTH(%i)', $key['name'] ) . ' AS kn' . $index;
				$select[] = $wpdb->prepare( 'SHA2(CAST(%i AS BINARY), 256)', $key['name'] ) . ' AS kh' . $index;
			}
		}
		$where = array();
		foreach ( $filters as $column => $value ) {
			// Indexed equality narrows candidates. Byte equality additionally
			// distinguishes case-insensitive/padded collations and raw numerics.
			$where[] = null === $value ?
				$wpdb->prepare( '%i IS NULL', $column ) :
				$wpdb->prepare( '(%i = FROM_BASE64(%s) AND CAST(%i AS BINARY) = FROM_BASE64(%s))', $column, $value, $column, $value );
		}
		foreach ( $hashes as $column => $digest ) {
			// Explicit scan fallback for keys that cannot fit a response window.
			// Hash collisions/duplicate values cannot authorize an ambiguous row.
			$where[] = $wpdb->prepare( 'SHA2(CAST(%i AS BINARY), 256) = %s', $column, $digest );
		}
		if ( isset( $input['after_id'] ) ) {
			// DECIMAL preserves integer keys beyond PHP and JavaScript ranges.
			$where[] = $wpdb->prepare( '%i > CAST(%s AS DECIMAL(65,0))', $keys[0]['name'], $input['after_id'] );
		}
		$order = array();
		foreach ( $keys as $key ) {
			$order[] = $wpdb->prepare( '%i ASC', $key['name'] );
		}
		$sql  = 'SELECT ' . implode( ', ', $select ) . $wpdb->prepare( ' FROM %i', $input['table'] );
		$sql .= $where ? ' WHERE ' . implode( ' AND ', $where ) : '';
		$sql .= $order ? ' ORDER BY ' . implode( ', ', $order ) : '';
		$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $is_cell ? 0 : ( $input['offset'] ?? 0 ) );
		$rows = $this->read( $sql );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		if ( $is_cell ) {
			if ( 1 !== count( $rows ) ) {
				return new WP_Error( 'database_record_not_unique', __( 'The exact key must match one current record. Refresh the key; absent or duplicate records cannot supply a cell window.', 'wp-ai-bridge' ) );
			}
			if ( null !== $rows[0]['n0'] && ! $this->valid_digest( $rows[0]['h0'] ) ) {
				return $this->digest_error();
			}
			if ( isset( $input['expected_sha256'] ) && $input['expected_sha256'] !== $rows[0]['h0'] ) {
				return new WP_Error( 'database_cell_stale', __( 'The database value changed. Restart with its current hash; do not concatenate windows from different values.', 'wp-ai-bridge' ) );
			}
			$cell = $this->cell( $rows[0], 0, $start, $types[ $columns[0] ] );
			if ( is_wp_error( $cell ) ) {
				return $cell;
			}
			return array(
				'table'       => $input['table'],
				'column'      => $columns[0],
				'cell'        => $cell,
				'consistency' => 'single_statement_value',
			);
		}
		$more  = count( $rows ) > $input['limit'];
		$items = array();
		foreach ( array_slice( $rows, 0, $input['limit'] ) as $row ) {
			$item = array(
				'key'          => array(),
				'key_complete' => ! empty( $keys ),
				'key_hashes'   => array(),
				'cells'        => array(),
			);
			foreach ( $keys as $index => $key ) {
				if ( null === $row[ 'kn' . $index ] || ! $this->valid_digest( $row[ 'kh' . $index ] ) ) {
					return $this->digest_error();
				}
				$bytes = (string) $row[ 'k' . $index ];
				if ( strlen( $bytes ) === (int) $row[ 'kn' . $index ] ) {
					$item['key'][ $key['name'] ] = base64_encode( $bytes );
				} else {
					$item['key_complete']               = false;
					$item['key_hashes'][ $key['name'] ] = $row[ 'kh' . $index ];
				}
			}
			foreach ( $columns as $index => $column ) {
				$cell = $this->cell( $row, $index, 0, $types[ $column ] );
				if ( is_wp_error( $cell ) ) {
					return $cell;
				}
				$item['cells'][ $column ] = $cell;
			}
			$items[] = $item;
		}
		return array(
			'table'       => $input['table'],
			'items'       => $items,
			'has_more'    => $more,
			'after_id'    => $more && $numeric && $items ? (string) $rows[ count( $items ) - 1 ]['k0'] : null,
			'next_offset' => $more && ! $numeric ? ( $input['offset'] ?? 0 ) + count( $items ) : null,
			'paging'      => $numeric ? 'integer_primary_keyset' : 'offset_live',
			'consistency' => 'live_not_snapshot',
		);
	}

	/**
	 * Preserve exact SQL bytes and NULL without decoding serialized PHP values.
	 *
	 * @param array  $row SQL projection.
	 * @param int    $index Column index.
	 * @param int    $start Requested byte offset.
	 * @param string $type Native SQL data type.
	 * @return array|WP_Error
	 */
	private function cell( array $row, $index, $start, $type ) {
		$null  = null === $row[ 'n' . $index ];
		$bytes = $null ? '' : (string) $row[ 'v' . $index ];
		$total = $null ? 0 : (int) $row[ 'n' . $index ];
		if ( ! $null && ! $this->valid_digest( $row[ 'h' . $index ] ) ) {
			return $this->digest_error();
		}
		if ( $start > $total ) {
			return new WP_Error( 'database_window_invalid', __( 'The requested byte offset is beyond the current value. Refresh its length and hash.', 'wp-ai-bridge' ) );
		}
		return array(
			'is_null'     => $null,
			'sql_type'    => $type,
			'encoding'    => 'base64',
			'data'        => base64_encode( $bytes ),
			'total_bytes' => $total,
			'offset'      => $start,
			'bytes'       => strlen( $bytes ),
			'sha256'      => $null ? null : (string) $row[ 'h' . $index ],
			'next_offset' => $start + strlen( $bytes ) < $total ? $start + strlen( $bytes ) : null,
		);
	}

	/** @param mixed $digest Server-computed SHA256. @return bool */
	private function valid_digest( $digest ) {
		return is_string( $digest ) && 1 === preg_match( '/^[a-f0-9]{64}$/D', $digest );
	}

	/** @return WP_Error */
	private function digest_error() {
		return new WP_Error( 'database_digest_unavailable', __( 'The database did not provide a valid SHA-256 for this value. Verify database SHA2 support and refresh the read; unverified bytes cannot be returned.', 'wp-ai-bridge' ) );
	}

	/**
	 * Execute only SQL assembled by this store; never expose query text/errors.
	 *
	 * @param string $sql Internally prepared SELECT.
	 * @return array|WP_Error
	 */
	private function read( $sql ) {
		global $wpdb;
		$old = $wpdb->suppress_errors( true );
		try {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Only private prepared SELECT fragments reach this executor.
			$result = $wpdb->get_results( $sql, ARRAY_A );
			if ( '' === $wpdb->last_error && is_array( $result ) ) {
				return $result;
			}
		} catch ( \Throwable $error ) {
			// A database drop-in may throw. Never return its SQL/data-bearing text.
			unset( $error );
		} finally {
			$wpdb->suppress_errors( $old );
		}
		return new WP_Error( 'database_query_failed', __( 'The WordPress database account could not complete this read. Check table/metadata privileges, database availability and server diagnostics. Raw query text and stored values are omitted.', 'wp-ai-bridge' ) );
	}
}
