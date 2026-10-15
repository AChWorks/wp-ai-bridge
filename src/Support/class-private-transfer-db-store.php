<?php
/**
 * WordPress database fallback for private, resumable transfer staging.
 *
 * No plaintext or metadata is placed beneath a web-served filesystem path.
 * This deliberately implements the staging lifecycle only; native consumers
 * must never act on a database lease until their durable effect path is ported.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_Error;

/**
 * Per-blog InnoDB leases and binary chunks, without autoloaded WP options.
 */
final class Private_Transfer_DB_Store {
	/** @var callable */
	private $allowed;

	/** @param callable $allowed Existing private-transfer purpose policy. */
	public function __construct( $allowed ) {
		$this->allowed = $allowed;
	}

	/** @return array<int,string>|WP_Error */
	private function tables() {
		global $wpdb;
		$prefix = isset( $wpdb->prefix ) ? $wpdb->prefix : '';
		if ( ! is_string( $prefix ) || ! preg_match( '/^[a-zA-Z0-9_]+$/D', $prefix ) ||
			strlen( $prefix . 'wpai_private_lease' ) > 64 || ! defined( 'DB_NAME' ) ||
			! is_string( DB_NAME ) || '' === DB_NAME ||
			( isset( $wpdb->dbname ) && DB_NAME !== $wpdb->dbname ) ) {
			return new WP_Error( 'transfer_db_unavailable', 'WordPress database identity is not safe for private transfer staging.' );
		}
		return array( $prefix . 'wpai_private_lease', $prefix . 'wpai_private_chunk' );
	}

	/** @return bool */
	private function table_exists( $table ) {
		global $wpdb;
		return $table === $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) )
		);
	}

	/** @return bool */
	private function durable( $tables ) {
		global $wpdb;
		$flush = $wpdb->get_var( 'SELECT @@innodb_flush_log_at_trx_commit' );
		if ( '1' !== (string) $flush ) {
			return false;
		}
		foreach ( $tables as $table ) {
			$engine = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
					$table
				)
			);
			if ( 'InnoDB' !== $engine ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Create private tables using WordPress's own schema API, under a named
	 * database lock to keep concurrent first-use requests from racing DDL.
	 *
	 * @return array<int,string>|WP_Error
	 */
	private function ensure() {
		global $wpdb;
		$tables = $this->tables();
		if ( is_wp_error( $tables ) ) {
			return $tables;
		}
		if ( $this->table_exists( $tables[0] ) && $this->table_exists( $tables[1] ) ) {
			return $this->durable( $tables ) ? $tables :
				new WP_Error( 'transfer_db_unsafe', 'Private transfer requires durable InnoDB tables and commit flushing.' );
		}
		$name = 'wpai_db_schema_' . substr( hash( 'sha256', DB_NAME . ':' . $tables[0] ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 10 ) ) ) {
			return new WP_Error( 'transfer_db_unavailable', 'Could not lock the private staging schema installation.' );
		}
		try {
			if ( ! $this->table_exists( $tables[0] ) || ! $this->table_exists( $tables[1] ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
				$collate = $wpdb->get_charset_collate();
				dbDelta(
					"CREATE TABLE {$tables[0]} (
id char(64) NOT NULL,
blog_id bigint(20) unsigned NOT NULL,
user_id bigint(20) unsigned NOT NULL,
client_id char(64) NOT NULL,
total_bytes bigint(20) unsigned NOT NULL,
received_bytes bigint(20) unsigned NOT NULL,
expires_at bigint(20) unsigned NOT NULL,
state varchar(20) NOT NULL,
payload_released tinyint(1) NOT NULL DEFAULT 0,
meta longtext NOT NULL,
PRIMARY KEY  (id),
KEY expiry (expires_at),
KEY state (state)
) ENGINE=InnoDB {$collate};"
				);
				dbDelta(
					"CREATE TABLE {$tables[1]} (
id char(64) NOT NULL,
byte_offset bigint(20) unsigned NOT NULL,
data longblob NOT NULL,
PRIMARY KEY  (id,byte_offset)
) ENGINE=InnoDB {$collate};"
				);
			}
			if ( ! $this->table_exists( $tables[0] ) || ! $this->table_exists( $tables[1] ) || ! $this->durable( $tables ) ) {
				return new WP_Error( 'transfer_db_unsafe', 'WordPress could not create durable private transfer tables.' );
			}
			return $tables;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/** @return bool Whether this opaque ID exists in the site-local database. */
	public function has_id( $id ) {
		global $wpdb;
		if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $id ) ) {
			return false;
		}
		$tables = $this->tables();
		if ( is_wp_error( $tables ) || ! $this->table_exists( $tables[0] ) ) {
			return false;
		}
		return null !== $wpdb->get_var(
			$wpdb->prepare( 'SELECT id FROM %i WHERE id = %s LIMIT 1', $tables[0], $id )
		);
	}

	/** @return bool */
	private function permitted( $purpose ) {
		return is_callable( $this->allowed ) && (bool) call_user_func( $this->allowed, $purpose );
	}

	/** @return bool */
	private function same_actor( $row ) {
		return (int) get_current_blog_id() === (int) $row->blog_id &&
			(int) get_current_user_id() === (int) $row->user_id &&
			hash( 'sha256', OAuth_Server::authenticated_mcp_client_id() ) === (string) $row->client_id &&
			$this->permitted( (string) $row->state_purpose );
	}

	/** @return array<string,mixed>|WP_Error */
	private function decode_row( $row ) {
		if ( ! is_object( $row ) ) {
			return new WP_Error( 'transfer_storage_uncertain', 'Private database lease not found.' );
		}
		$meta = json_decode( (string) $row->meta, true );
		if ( ! is_array( $meta ) || 1 !== ( $meta['version'] ?? null ) ||
			! hash_equals( (string) $row->id, (string) ( $meta['id'] ?? '' ) ) ||
			! hash_equals( (string) $row->client_id, hash( 'sha256', (string) ( $meta['client'] ?? '' ) ) ) ||
			(int) ( $meta['blog'] ?? -1 ) !== (int) $row->blog_id ||
			(int) ( $meta['user'] ?? -1 ) !== (int) $row->user_id ||
			! is_int( $meta['received'] ?? null ) || (int) $row->received_bytes !== $meta['received'] ||
			! is_int( $meta['total'] ?? null ) || (int) $row->total_bytes !== $meta['total'] ||
			! hash_equals( (string) $row->state, (string) ( $meta['state'] ?? '' ) ) ) {
			return new WP_Error( 'transfer_storage_uncertain', 'Private database lease checkpoint is inconsistent.' );
		}
		$row->state_purpose = (string) ( $meta['purpose'] ?? '' );
		return $this->same_actor( $row ) ? $meta :
			new WP_Error( 'transfer_denied', 'Current site, principal, client and WordPress permission must match the private lease.' );
	}

	/** @return array<string,mixed>|WP_Error */
	private function get( $tables, $id, $lock = false ) {
		global $wpdb;
		if ( ! is_string( $id ) || 1 !== preg_match( '/^[a-f0-9]{64}$/D', $id ) ) {
			return new WP_Error( 'transfer_invalid', 'The private transfer ID is invalid.' );
		}
		$query = $lock ?
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %s FOR UPDATE', $tables[0], $id ) :
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %s', $tables[0], $id );
		$row   = $wpdb->get_row( $query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Query constructed exclusively from the two immediately preceding wpdb::prepare calls with a safe %i table placeholder.
		if ( '' !== $wpdb->last_error ) {
			return new WP_Error( 'transfer_storage_uncertain', 'Private database lease could not be read.' );
		}
		$meta = $this->decode_row( $row );
		if ( is_wp_error( $meta ) ) {
			return $meta;
		}
		if ( 'cancelled' === $meta['state'] ) {
			return new WP_Error( 'transfer_cancelled', 'This private lease has been cancelled.' );
		}
		if ( $meta['expires'] <= time() && ! in_array( $meta['state'], array( 'committing', 'observed' ), true ) ) {
			return new WP_Error( 'transfer_expired', 'The private transfer has expired.' );
		}
		return $meta;
	}

	/** @return array<string,mixed> */
	private function receipt( $meta ) {
		return array(
			'id'                    => $meta['id'],
			'purpose'               => $meta['purpose'],
			'total'                 => $meta['total'],
			'received'              => $meta['received'],
			'chunk'                 => $meta['chunk'],
			'complete'              => $meta['total'] === $meta['received'],
			'state'                 => $meta['state'],
			'sha256'                => $meta['sha256'],
			'expires'               => $meta['expires'],
			'destination_id'        => 0,
			'source_id'             => 0,
			'outcome'               => 'none',
			'intent'                => '',
			'target_id'             => 0,
			'source_representation' => '',
			'payload_released'      => ! empty( $meta['payload_released'] ),
			'plan_hash'             => '',
			'import_items'          => 0,
			'import_processed'      => 0,
		);
	}

	/** @return bool */
	private function start() {
		global $wpdb;
		return '0' === (string) $wpdb->get_var( 'SELECT @@in_transaction' ) &&
			false !== $wpdb->query( 'START TRANSACTION' );
	}

	/** @return bool */
	private function commit() {
		global $wpdb;
		return false !== $wpdb->query( 'COMMIT' );
	}

	/** Persist state and receipt together. Caller owns the InnoDB row lock. */
	private function save( $tables, $meta ) {
		global $wpdb;
		$json = wp_json_encode( $meta );
		if ( ! is_string( $json ) || strlen( $json ) > 4096 ) {
			return false;
		}
		return 1 === $wpdb->update(
			$tables[0],
			array(
				'received_bytes' => $meta['received'],
				'state'          => $meta['state'],
				'meta'           => $json,
			),
			array( 'id' => $meta['id'] ),
			array( '%d', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Reap a bounded number of expired, never-claimed leases while admission is
	 * serialized. A one-way native claim must never be deleted by TTL alone.
	 *
	 * @param array<int,string> $tables Validated per-blog database identifiers.
	 * @return bool
	 */
	private function prune_expired( $tables ) {
		global $wpdb;
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM %i WHERE expires_at <= %d AND state IN ('open','verified','cancelled') ORDER BY expires_at ASC LIMIT 32",
				$tables[0],
				time()
			)
		);
		if ( ! is_array( $ids ) || '' !== $wpdb->last_error ) {
			return false;
		}
		foreach ( $ids as $id ) {
			if ( ! $this->start() ) {
				return false;
			}
			$committed = false;
			try {
				$row = $wpdb->get_row(
					$wpdb->prepare( 'SELECT state,expires_at FROM %i WHERE id = %s FOR UPDATE', $tables[0], $id )
				);
				if ( '' !== $wpdb->last_error ) {
					return false;
				}
				if ( is_object( $row ) && (int) $row->expires_at <= time() &&
					in_array( (string) $row->state, array( 'open', 'verified', 'cancelled' ), true ) ) {
					$deleted_bytes = $wpdb->query(
						$wpdb->prepare( 'DELETE FROM %i WHERE id = %s', $tables[1], $id )
					);
					$deleted_lease = false !== $deleted_bytes ? $wpdb->query(
						$wpdb->prepare( 'DELETE FROM %i WHERE id = %s', $tables[0], $id )
					) : false;
					if ( 1 !== $deleted_lease ) {
						return false;
					}
				}
				$committed = $this->commit();
			} finally {
				if ( ! $committed ) {
					$wpdb->query( 'ROLLBACK' );
				}
			}
			if ( ! $committed ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Begin only after explicit bounds were checked by Private_Transfer_Session.
	 * All site-local reservations are counted under one DB advisory lock.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function begin( $purpose, $total, $sha256, $max_total, $max_chunk, $active_limit, $ttl_seconds, $max_active ) {
		global $wpdb;
		$tables = $this->ensure();
		if ( is_wp_error( $tables ) ) {
			return $tables;
		}
		$name = 'wpai_db_admit_' . substr( hash( 'sha256', DB_NAME . ':' . $tables[0] ), 0, 40 );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, 10 ) ) ) {
			return new WP_Error( 'transfer_capacity', 'Could not reserve private database transfer capacity.' );
		}
		try {
			// Refuse new admissions until bounded expired payload cleanup succeeds.
			if ( ! $this->prune_expired( $tables ) ) {
				return new WP_Error( 'transfer_cleanup_uncertain', 'Expired private database staging could not be reconciled safely.' );
			}
			$stats = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT(*) AS n, COALESCE(SUM(total_bytes), 0) AS reserved
					FROM %i WHERE payload_released = 0 AND expires_at > %d AND state IN ('open','verified')",
					$tables[0],
					time()
				)
			);
			if ( ! $stats || '' !== $wpdb->last_error ||
				(int) $stats->n >= $active_limit ||
				(int) $stats->reserved > $max_active ||
				$total > $max_active - (int) $stats->reserved ) {
				return new WP_Error( 'transfer_capacity', 'Configured private database reservations have been reached or cannot be established.' );
			}
			$id   = bin2hex( random_bytes( 32 ) );
			$meta = array(
				'version'  => 1,
				'id'       => $id,
				'purpose'  => $purpose,
				'blog'     => (int) get_current_blog_id(),
				'user'     => (int) get_current_user_id(),
				'client'   => OAuth_Server::authenticated_mcp_client_id(),
				'total'    => $total,
				'chunk'    => $max_chunk,
				'received' => 0,
				'sha256'   => $sha256,
				'expires'  => time() + $ttl_seconds,
				'state'    => 'open',
			);
			$json = wp_json_encode( $meta );
			if ( ! is_string( $json ) || strlen( $json ) > 4096 ) {
				return new WP_Error( 'transfer_db_unavailable', 'Private database metadata could not be encoded.' );
			}
			$inserted = $wpdb->insert(
				$tables[0],
				array(
					'id'             => $id,
					'blog_id'        => $meta['blog'],
					'user_id'        => $meta['user'],
					'client_id'      => hash( 'sha256', $meta['client'] ),
					'total_bytes'    => $total,
					'received_bytes' => 0,
					'expires_at'     => $meta['expires'],
					'state'          => 'open',
					'meta'           => $json,
				),
				array( '%s', '%d', '%d', '%s', '%d', '%d', '%d', '%s', '%s' )
			);
			return 1 === $inserted ? $this->receipt( $meta ) :
				new WP_Error( 'transfer_db_unavailable', 'Private database lease reservation could not be committed.' );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) );
		}
	}

	/** @return array<string,mixed>|WP_Error */
	public function status( $id ) {
		$tables = $this->tables();
		if ( is_wp_error( $tables ) ) {
			return $tables;
		}
		$meta = $this->get( $tables, $id );
		return is_wp_error( $meta ) ? $meta : $this->receipt( $meta );
	}

	/** @return array<string,mixed>|WP_Error */
	public function append( $id, $offset, $bytes ) {
		global $wpdb;
		$tables = $this->tables();
		if ( is_wp_error( $tables ) || ! $this->start() ) {
			return new WP_Error( 'transfer_db_unavailable', 'Private database append transaction is unavailable.' );
		}
		$commit = false;
		try {
			$meta = $this->get( $tables, $id, true );
			if ( is_wp_error( $meta ) ) {
				return $meta;
			}
			if ( 'open' !== $meta['state'] || ! is_int( $offset ) || $offset < 0 ||
				! is_string( $bytes ) || '' === $bytes || strlen( $bytes ) > $meta['chunk'] ||
				$offset > $meta['received'] || strlen( $bytes ) > $meta['total'] - $offset ) {
				return new WP_Error( 'transfer_invalid', 'Private database append offset, size or state is invalid.' );
			}
			if ( $offset < $meta['received'] ) {
				if ( strlen( $bytes ) > $meta['received'] - $offset ) {
					return new WP_Error( 'transfer_conflict', 'A replay cannot extend past committed bytes.' );
				}
				$cursor = $offset;
				$end    = $offset + strlen( $bytes );
				while ( $cursor < $end ) {
					$row = $wpdb->get_row(
						$wpdb->prepare(
							'SELECT byte_offset,data FROM %i WHERE id = %s AND byte_offset <= %d ORDER BY byte_offset DESC LIMIT 1',
							$tables[1],
							$id,
							$cursor
						)
					);
					if ( ! $row || '' !== $wpdb->last_error ) {
						return new WP_Error( 'transfer_storage_uncertain', 'Committed database transfer chunk is missing.' );
					}
					$start = (int) $row->byte_offset;
					$slice = substr( $row->data, $cursor - $start, $end - $cursor );
					if ( '' === $slice || ! hash_equals( $slice, substr( $bytes, $cursor - $offset, strlen( $slice ) ) ) ) {
						return new WP_Error( 'transfer_conflict', 'Replayed private transfer bytes differ from committed data.' );
					}
					$cursor += strlen( $slice );
				}
				$commit = $this->commit();
				return $commit ? $this->receipt( $meta ) :
					new WP_Error( 'transfer_outcome_unknown', 'Private database replay transaction outcome is unknown.' );
			}
			$written = $wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (id,byte_offset,data) VALUES (%s,%d,UNHEX(%s))',
					$tables[1],
					$id,
					$offset,
					bin2hex( $bytes )
				)
			);
			if ( 1 !== $written ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Private database chunk insertion could not be confirmed.' );
			}
			$meta['received'] += strlen( $bytes );
			if ( ! $this->save( $tables, $meta ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Private database receipt was not saved.' );
			}
			$commit = $this->commit();
			return $commit ? $this->receipt( $meta ) :
				new WP_Error( 'transfer_outcome_unknown', 'Private database append commit outcome is unknown.' );
		} finally {
			if ( ! $commit ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
	}

	/** @return array<string,mixed>|WP_Error */
	public function verify( $id ) {
		global $wpdb;
		$tables = $this->tables();
		if ( is_wp_error( $tables ) || ! $this->start() ) {
			return new WP_Error( 'transfer_db_unavailable', 'Private database verification transaction is unavailable.' );
		}
		$commit = false;
		try {
			$meta = $this->get( $tables, $id, true );
			if ( is_wp_error( $meta ) ) {
				return $meta;
			}
			if ( ! in_array( $meta['state'], array( 'open', 'verified' ), true ) ) {
				return new WP_Error( 'transfer_consumed', 'This private database lease cannot be reopened.' );
			}
			if ( $meta['received'] !== $meta['total'] ) {
				return new WP_Error( 'transfer_incomplete', 'Private database transfer is incomplete.' );
			}
			$hash   = hash_init( 'sha256' );
			$cursor = 0;
			while ( $cursor < $meta['total'] ) {
				$row = $wpdb->get_row(
					$wpdb->prepare(
						'SELECT byte_offset,data FROM %i WHERE id = %s AND byte_offset >= %d ORDER BY byte_offset ASC LIMIT 1',
						$tables[1],
						$id,
						$cursor
					)
				);
				if ( ! $row || '' !== $wpdb->last_error || (int) $row->byte_offset !== $cursor || '' === $row->data ) {
					return new WP_Error( 'transfer_storage_uncertain', 'Private database payload has missing or conflicting chunks.' );
				}
				hash_update( $hash, $row->data );
				$cursor += strlen( $row->data );
				if ( $cursor > $meta['total'] ) {
					return new WP_Error( 'transfer_storage_uncertain', 'Private database payload exceeds its reservation.' );
				}
			}
			if ( ! hash_equals( $meta['sha256'], hash_final( $hash ) ) ) {
				return new WP_Error( 'transfer_checksum_mismatch', 'Private database transfer checksum differs from the declared digest.' );
			}
			if ( 'open' === $meta['state'] ) {
				$meta['state'] = 'verified';
				if ( ! $this->save( $tables, $meta ) ) {
					return new WP_Error( 'transfer_outcome_unknown', 'Private database verification receipt could not be stored.' );
				}
			}
			$commit = $this->commit();
			return $commit ? $this->receipt( $meta ) :
				new WP_Error( 'transfer_outcome_unknown', 'Private database verification commit outcome is unknown.' );
		} finally {
			if ( ! $commit ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
	}

	/** @return true|WP_Error */
	public function cancel( $id ) {
		global $wpdb;
		$tables = $this->tables();
		if ( is_wp_error( $tables ) || ! $this->start() ) {
			return new WP_Error( 'transfer_db_unavailable', 'Private database cancel transaction is unavailable.' );
		}
		$commit = false;
		try {
			$meta = $this->get( $tables, $id, true );
			if ( is_wp_error( $meta ) ) {
				return $meta;
			}
			if ( ! in_array( $meta['state'], array( 'open', 'verified' ), true ) ) {
				return new WP_Error( 'transfer_outcome_unknown', 'Claimed private lease cannot be cancelled.' );
			}
			$meta['state']            = 'cancelled';
			$meta['payload_released'] = true;
			if ( ! $this->save( $tables, $meta ) || false === $wpdb->delete( $tables[1], array( 'id' => $id ), array( '%s' ) ) ) {
				return new WP_Error( 'transfer_cleanup_uncertain', 'Private database cancellation is not confirmed.' );
			}
			$commit = $this->commit();
			return $commit ? true : new WP_Error( 'transfer_cleanup_uncertain', 'Private database cancellation commit outcome is unknown.' );
		} finally {
			if ( ! $commit ) {
				$wpdb->query( 'ROLLBACK' );
			}
		}
	}

	/** Fail closed until consumers can hold durable one-way database claims. */
	public function consumer_unavailable() {
		return new WP_Error(
			'transfer_db_consumer_unavailable',
			'Verified private database staging exists, but the native-effect consumer is not yet supported by this backend. No WordPress change was attempted.'
		);
	}
}
