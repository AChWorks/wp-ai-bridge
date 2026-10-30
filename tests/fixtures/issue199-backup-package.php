<?php
/**
 * Deliberately self-consistent portable packages for integrity acceptance.
 * Fixtures only: no WordPress bootstrap, database or filesystem effects.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Support\Backup_Archive;

final class WPAI_Backup_Package_Fixture {
	public static function make( $fault = '', $large_bytes = 1048579 ) {
		$q = static function ( $name ) { return chr( 96 ) . $name . chr( 96 ); };
		$table = 'wp_integrity_fixture';
		$ddl = 'CREATE TABLE ' . $q( $table ) . " (\n " .
			$q( 'payload' ) . " longblob DEFAULT NULL,\n " .
			$q( 'note' ) . " longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL COMMENT 'CHARACTER SET latin1 AUTO_INCREMENT=9',\n " .
			$q( 'empty' ) . " varbinary(32) DEFAULT NULL,\n " .
			$q( 'nullable' ) . " longblob DEFAULT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
		$columns = array(
			array( 'name' => base64_encode( 'payload' ), 'type' => 'longblob', 'charset' => null ),
			array( 'name' => base64_encode( 'note' ), 'type' => 'longtext', 'charset' => 'utf8mb4' ),
			array( 'name' => base64_encode( 'empty' ), 'type' => 'varbinary', 'charset' => null ),
			array( 'name' => base64_encode( 'nullable' ), 'type' => 'longblob', 'charset' => null ),
		);
		if ( 'wrong_type' === $fault ) {
			$columns[0]['type'] = 'bit';
		} elseif ( 'wrong_binary_charset' === $fault ) {
			$columns[0]['charset'] = 'latin1';
		} elseif ( 'wrong_text_charset' === $fault ) {
			$columns[1]['charset'] = 'latin1';
		} elseif ( 'extra_codec' === $fault ) {
			$columns[0]['parameter_charset'] = 'latin1';
		}
		$schema_hash = hash( 'sha256', $ddl );
		$packed = array( 'table' => base64_encode( $table ), 'ddl' => base64_encode( $ddl ), 'hash' => $schema_hash, 'columns' => $columns );
		$plan = array(
			'tables' => array( base64_encode( $table ) ),
			'schemas' => array( base64_encode( $table ) => $schema_hash ),
			'roots' => array( array( 'id' => 'installation', 'mode' => 0755 ) ),
			'database' => array( 'session' => array( 'sql_mode' => '', 'time_zone' => '+00:00' ) ),
		);
		$unit = "\0\xffLargeسلام";
		$large = substr( str_repeat( $unit, intdiv( $large_bytes, strlen( $unit ) ) + 1 ), 0, $large_bytes );
		$values = array( $large, "سلام\0text", '', null );
		$digests = array();
		$row_identity = 'wpai-row-v1;';
		foreach ( $values as $index => $value ) {
			$digest = null === $value ? null : hash( 'sha256', $value );
			if ( 'wrong_cell_sha' === $fault && 0 === $index || 'wrong_empty_sha' === $fault && 2 === $index ) {
				$digest = hash( 'sha256', 'declared bytes differ from the actual cell payload' );
			}
			$digests[] = $digest;
			$row_identity .= null === $value ? 'N;' : 'V' . strlen( $value ) . ':' . $digest . ';';
		}
		$group = hash( 'sha256', $row_identity );
		$frames = array();
		$chain = Backup_Archive::initial_chain();
		$seq = 0;
		$append = static function ( array $record, $payload = '' ) use ( &$frames, &$chain, &$seq ) {
			$record['seq'] = $seq++;
			$frame = Backup_Archive::encode( $record, $payload, 16384 );
			$frames[] = $frame;
			$chain = Backup_Archive::fold( $chain, $frame );
		};
		$append( array( 'type' => 'header' ), json_encode( array( 'format' => Backup_Archive::FORMAT, 'plan' => $plan, 'chunk_bytes' => 4096,
			'consistency' => 'declared_quiescent_verified_capture' ) ) );
		$append( array( 'type' => 'table', 'table' => base64_encode( $table ), 'schema_hash' => $schema_hash ), json_encode( $packed ) );
		$append( array( 'type' => 'row', 'digest' => $group, 'multiplicity' => '2' ) );
		foreach ( $values as $index => $value ) {
			$total = null === $value ? 0 : strlen( $value );
			$offset = 0;
			do {
				$payload = null === $value ? '' : substr( $value, $offset, 4096 );
				if ( 'wrong_payload' === $fault && 0 === $index && 0 === $offset ) {
					$payload[0] = 'x';
				}
				$append( array( 'type' => 'cell', 'column' => $index, 'offset' => $offset, 'total' => $total, 'null' => null === $value, 'sha256' => $digests[ $index ] ), $payload );
				$offset += strlen( $payload );
			} while ( $offset < $total );
		}
		$append( array( 'type' => 'row_end', 'digest' => $group ) );
		$append( array( 'type' => 'table_end', 'table' => base64_encode( $table ) ) );
		$append( array( 'type' => 'root', 'root' => 'installation', 'mode' => 0755 ) );
		$append( array( 'type' => 'directory_end', 'components' => array() ) );
		$append( array( 'type' => 'root_end' ) );
		$db_digest = hash( 'sha256', hash( 'sha256', 'wpai-backup-database-v1' ) . ':table:' . base64_encode( $table ) . ':' . $schema_hash );
		$db_digest = hash( 'sha256', $db_digest . ':row:' . $group . ':2' );
		$files_digest = hash( 'sha256', hash( 'sha256', 'wpai-backup-files-v1' ) . ':root:installation:493' );
		$files_digest = hash( 'sha256', $files_digest . ':directory_end:[]' );
		$append( array( 'type' => 'footer', 'previous_chain' => $chain, 'database_digest' => $db_digest,
			'files_digest' => $files_digest, 'source_verified' => true, 'activation_pending' => true ) );
		return array( 'frames' => $frames, 'bytes' => array_sum( array_map( 'strlen', $frames ) ), 'chain' => $chain,
			'packed' => $packed, 'large_bytes' => $large_bytes );
	}
}
