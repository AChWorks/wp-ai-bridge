<?php
/**
 * Native schema identity must preserve literals resembling table counters.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support {
	final class Database_Write_Failure extends \RuntimeException {
		public $reason;
		public $details;
		public function __construct( $reason, array $details = array() ) { parent::__construct( $reason ); $this->reason = $reason; $this->details = $details; }
	}
	final class Database_Connection {
		public $database = 'fixture';
		public $prefix = 'wp_';
		public $options = 'wp_options';
		public $mariadb = false;
		public $ddl;
		public $sql_mode = '';
		public function table( $table ) { return $table; }
		public function has_privilege( $privilege, $table ) { return true; }
		public function run( $sql, $parameters = array(), $sources = array(), $max_rows = 4096 ) {
			if ( 0 === strpos( $sql, 'SHOW CREATE TABLE ' ) ) { return array( 'rows' => array( array( 'definition' => $this->ddl ) ) ); }
			if ( 'SELECT @@SESSION.sql_mode AS mode' === $sql ) { return array( 'rows' => array( array( 'mode' => $this->sql_mode ) ) ); }
			if ( 0 === strpos( $sql, 'SELECT TABLE_TYPE AS kind,' ) ) { return array( 'rows' => array( array( 'kind' => 'BASE TABLE', 'engine' => 'InnoDB', 'options' => '' ) ) ); }
			if ( 0 === strpos( $sql, 'SELECT TABLE_TYPE AS kind FROM ' ) ) { return array( 'rows' => array( array( 'kind' => 'BASE TABLE' ) ) ); }
			if ( false !== strpos( $sql, 'SUM(OCTET_LENGTH(COLUMN_TYPE)' ) ) { return array( 'rows' => array( array( 'bytes' => '1280' ) ) ); }
			if ( false !== strpos( $sql, 'SUM(OCTET_LENGTH(INDEX_COMMENT)' ) ) { return array( 'rows' => array( array( 'bytes' => '256' ) ) ); }
			if ( false !== strpos( $sql, 'OCTET_LENGTH(TABLE_COMMENT)' ) ) { return array( 'rows' => array( array( 'bytes' => '64' ) ) ); }
			if ( 0 === strpos( $sql, 'SELECT COLUMN_NAME AS name, ORDINAL_POSITION' ) ) {
				return array( 'rows' => array(
					array( 'name' => 'id', 'ordinal' => '1', 'type' => 'bigint', 'sql_type' => 'bigint', 'nullable' => 'NO', 'charset' => null, 'collation' => null, 'default_value' => null, 'extra' => 'auto_increment', 'expression' => '' ),
					array( 'name' => 'v', 'ordinal' => '2', 'type' => 'varchar', 'sql_type' => 'varchar(255)', 'nullable' => 'YES', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'default_value' => '', 'extra' => '', 'expression' => '' ),
				) );
			}
			if ( 0 === strpos( $sql, 'SELECT INDEX_NAME AS name,' ) ) {
				return array( 'rows' => array( array( 'name' => 'PRIMARY', 'non_unique' => '0', 'position' => '1', 'column_name' => 'id', 'prefix_bytes' => null, 'type' => 'BTREE', 'collation' => 'A' ) ) );
			}
			if ( 0 === strpos( $sql, 'SELECT 1 FROM ' ) ) { return array( 'rows' => array() ); }
			throw new \RuntimeException( 'Unexpected schema metadata request.' );
		}
	}
}

namespace {
	require __DIR__ . '/bootstrap.php';

	use WP_AI_Bridge\Support\Database_Control_Storage as Storage;
	use WP_AI_Bridge\Support\Database_Connection as Native;

	$checks = 0;
	$assert = static function ( $condition, $message ) use ( &$checks ) { ++$checks; if ( ! $condition ) { throw new RuntimeException( $message ); } };
	$tick = chr( 96 );
	$definition = static function ( $default, $column_comment, $table_comment, $counter ) use ( $tick ) {
		return 'CREATE TABLE ' . $tick . 'wp_fixture' . $tick . " (\n  " . $tick . 'id' . $tick . " bigint NOT NULL AUTO_INCREMENT,\n  " . $tick . 'v' . $tick .
			" varchar(255) DEFAULT '" . $default . "' COMMENT '" . $column_comment . "',\n  PRIMARY KEY (" . $tick . 'id' . $tick . ")\n) ENGINE=InnoDB AUTO_INCREMENT=" . $counter . " DEFAULT CHARSET=utf8mb4 COMMENT='" . $table_comment . "'";
	};
	$hashes = static function ( $ddl, $mode = '' ) {
		$native = new Native();
		$native->ddl = $ddl;
		$native->sql_mode = $mode;
		$storage = new Storage( $native, 65536 );
		return array( $storage->observe_table( 'wp_fixture' )['schema_hash'], $storage->schema( 'wp_fixture' )['hash'] );
	};
	$original = $definition( ' AUTO_INCREMENT=1', 'column AUTO_INCREMENT=3', 'table AUTO_INCREMENT=5', 19 );
	$base_hashes = $hashes( $original );
	foreach ( array(
		$definition( ' AUTO_INCREMENT=2', 'column AUTO_INCREMENT=3', 'table AUTO_INCREMENT=5', 19 ),
		$definition( ' AUTO_INCREMENT=1', 'column AUTO_INCREMENT=4', 'table AUTO_INCREMENT=5', 19 ),
		$definition( ' AUTO_INCREMENT=1', 'column AUTO_INCREMENT=3', 'table AUTO_INCREMENT=6', 19 ),
	) as $changed ) {
		$current = $hashes( $changed );
		foreach ( array( 0 => 'observed schema', 1 => 'typed write schema' ) as $index => $label ) {
			$assert( $base_hashes[ $index ] !== $current[ $index ], $label . ' hash aliased a changed native literal/default/comment.' );
		}
	}
	$counter_only = $definition( ' AUTO_INCREMENT=1', 'column AUTO_INCREMENT=3', 'table AUTO_INCREMENT=5', 97 );
	$assert( $base_hashes === $hashes( $counter_only ), 'Counter-only advancement changed stable schema identity.' );

	$expected = str_replace( ' ENGINE=InnoDB AUTO_INCREMENT=19 ', ' ENGINE=InnoDB ', $original );
	$assert( $expected === Storage::stable_definition( $original, '' ), 'Normalizer changed bytes beyond the actual table counter.' );
	$without_counter = str_replace( ' ENGINE=InnoDB AUTO_INCREMENT=19 ', ' ENGINE=InnoDB ', $original );
	$assert( $without_counter === Storage::stable_definition( $without_counter, '' ), 'Literal counter text was removed from a definition without a native counter.' );
	$literal_cases = array(
		array( "it\\'s 雪 AUTO_INCREMENT=71", '' ),
		array( "it''s 雪 AUTO_INCREMENT=72", '' ),
		array( "tail\\", 'NO_BACKSLASH_ESCAPES' ),
		array( "nul\0binary\xff AUTO_INCREMENT=73", 'NO_BACKSLASH_ESCAPES' ),
	);
	foreach ( $literal_cases as $case ) {
		$ddl = $definition( $case[0], 'quoted AUTO_INCREMENT=74', 'trick ) ENGINE=InnoDB AUTO_INCREMENT=75', 19 );
		$expected = str_replace( "\n) ENGINE=InnoDB AUTO_INCREMENT=19 ", "\n) ENGINE=InnoDB ", $ddl );
		$assert( $expected === Storage::stable_definition( $ddl, $case[1] ), 'Quoted/multibyte/escaped native literal bytes were not retained.' );
		$assert( $hashes( $ddl, $case[1] ) === $hashes( str_replace( "\n) ENGINE=InnoDB AUTO_INCREMENT=19 ", "\n) ENGINE=InnoDB AUTO_INCREMENT=20 ", $ddl ), $case[1] ), 'Quoted literal prevented correct table-counter normalization.' );
	}
	$quoted_name = 'CREATE TABLE ' . $tick . 'wp_ ) AUTO_INCREMENT=81' . $tick . ' (' . $tick . 'col' . $tick . $tick . ' AUTO_INCREMENT=82' . $tick . " int) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4";
	$assert( str_replace( ' ENGINE=InnoDB AUTO_INCREMENT=19 ', ' ENGINE=InnoDB ', $quoted_name ) === Storage::stable_definition( $quoted_name, '' ), 'Backtick/doubled identifier bytes were treated as options.' );
	$ansi = 'CREATE TABLE "wp_backslash\\" ("col"" AUTO_INCREMENT=83" int) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4';
	$assert( str_replace( ' ENGINE=InnoDB AUTO_INCREMENT=19 ', ' ENGINE=InnoDB ', $ansi ) === Storage::stable_definition( $ansi, 'ANSI_QUOTES' ), 'ANSI identifier quoting treated backslash as a quote escape.' );
	$commented = str_replace( ' ENGINE=InnoDB AUTO_INCREMENT=19 ', ' ENGINE=InnoDB /* AUTO_INCREMENT=84 */ AUTO_INCREMENT=19 ', $original );
	$assert( str_replace( '*/ AUTO_INCREMENT=19 ', '*/ ', $commented ) === Storage::stable_definition( $commented, '' ), 'Native comment text was normalized as a table option.' );
	echo "PASS: native schema hashes preserve literal/quoted bytes and normalize only table counters ({$checks} checks).\n";
}
