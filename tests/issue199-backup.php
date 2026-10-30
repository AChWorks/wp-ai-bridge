<?php
/**
 * Pure backup framing, schema confinement and complete-preflight regressions.
 *
 * @package WP_AI_Bridge
 */
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\Backup_Archive;
use WP_AI_Bridge\Support\Backup_Schema;
use WP_AI_Bridge\Support\Backup_Operation;
use WP_AI_Bridge\Support\Backup_Exporter;
use WP_AI_Bridge\Support\Backup_Restore;

$checks = 0;
$assert = static function ( $value, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $value ) {
		throw new RuntimeException( $message );
	}
};
$throws = static function ( callable $callback, $message ) use ( $assert ) {
	try {
		$callback();
	} catch ( Throwable $error ) {
		$assert( true, $message );
		return;
	}
	$assert( false, $message );
};

$payload = "\0\xff\x80" . str_repeat( 'binary', 1000 );
$frame = Backup_Archive::encode( array( 'seq' => 0, 'type' => 'cell', 'column' => 0 ), $payload, 16384 );
$stream = fopen( 'php://memory', 'w+b' );
fwrite( $stream, $frame );
rewind( $stream );
$decoded = Backup_Archive::read( $stream, 16384 );
$assert( $decoded['payload'] === $payload && $decoded['frame'] === $frame, 'Binary payloads must survive exact frame parsing.' );
$assert( null === Backup_Archive::read( $stream, 16384 ), 'Only clean frame-boundary EOF is accepted.' );
fclose( $stream );
$chain = Backup_Archive::fold( Backup_Archive::initial_chain(), $frame );
$assert( $chain !== hash( 'sha256', $frame ), 'Frame-chain integrity must not masquerade as whole-file SHA256.' );
foreach ( array( substr( $frame, 0, -1 ), substr( $frame, 0, 8 ), substr_replace( $frame, 'x', -1 ), "WPAI1:7fffffff:7fffffff\n" ) as $bad ) {
	$throws( static function () use ( $bad ) {
		$stream = fopen( 'php://memory', 'w+b' );
		fwrite( $stream, $bad );
		rewind( $stream );
		try { Backup_Archive::read( $stream, 16384 ); } finally { fclose( $stream ); }
	}, 'Truncation, changed payload and oversized declared lengths must fail before allocation.' );
}
$throws( static function () { Backup_Archive::encode( array( 'seq' => 0, 'type' => 'file' ), str_repeat( 'x', 20000 ), 16384 ); }, 'Encoding must obey actual frame resources.' );
$assert( Backup_Archive::decode_name( base64_encode( "raw-\xff" ) ) === "raw-\xff", 'Exact nonUTF8 names must remain opaque.' );
$throws( static function () { Backup_Archive::decode_name( "YQ==\n" ); }, 'Noncanonical base64 must fail.' );
$throws( static function () { Backup_Archive::decode_name( base64_encode( "a\0b" ) ); }, 'NUL names must fail.' );
$assert( Backup_Operation::native_int( (string) PHP_INT_MAX ) === PHP_INT_MAX, 'Native integer boundary is supported.' );
$throws( static function () { Backup_Operation::native_int( (string) PHP_INT_MAX . '0' ); }, 'Oversized native offsets must fail explicitly.' );

$ddl = "CREATE TABLE `wp_fixture` (\n  `id` bigint unsigned NOT NULL AUTO_INCREMENT,\n  `payload` longblob,\n  `label` varchar(255) NOT NULL DEFAULT '',\n  `choice` enum('a,b','x) y','it\\'s') DEFAULT NULL,\n  PRIMARY KEY (`id`),\n  KEY `label` (`label`(191))\n) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";
$blueprint = Backup_Schema::blueprint( $ddl, 'wp_fixture' );
$assert( 4 === count( $blueprint['columns'] ), 'Quoted commas, closing parentheses and escaped literals must not split definitions.' );
$qualified = Backup_Schema::create( $blueprint, 'restore_only', 'wp_fixture' );
$assert( 0 === strpos( $qualified, 'CREATE TABLE `restore_only`.`wp_fixture` (' ), 'Restore CREATE must identify the approved schema.' );
$assert( false !== strpos( $qualified, "AUTO_INCREMENT=19" ), 'Native next identity state must survive.' );
$keyless = "CREATE TABLE `wp_duplicate` (\n `0` longblob,\n `01` decimal(30,10) DEFAULT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$assert( array( '0', '01' ) === Backup_Schema::blueprint( $keyless, 'wp_duplicate' )['columns'], 'Keyless and numeric column identifiers must survive independently.' );
$foreign_ddl = "CREATE TABLE `wp_child` (\n `id` bigint NOT NULL,\n `parent` bigint DEFAULT NULL,\n PRIMARY KEY (`id`),\n KEY `parent` (`parent`),\n CONSTRAINT `fk_parent` FOREIGN KEY (`parent`) REFERENCES `wp_parent` (`id`) ON DELETE CASCADE\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$foreign = Backup_Schema::blueprint( $foreign_ddl, 'wp_child' );
$assert( 1 === count( $foreign['foreign'] ) && false === strpos( Backup_Schema::create( $foreign, 'restore_only', 'wp_child' ), 'FOREIGN KEY' ), 'FK constraints must be deferred until data is restored.' );
$assert( false !== strpos( Backup_Schema::add_foreign( $foreign['foreign'][0], 'restore_only', 'wp_child' ), 'REFERENCES `restore_only`.`wp_parent`' ), 'FK restoration must qualify the parent inside the same approved destination.' );
$escaped = "CREATE TABLE `wp_a``b` (\n `a``b` int DEFAULT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$assert( array( 'a`b' ) === Backup_Schema::blueprint( $escaped, 'wp_a`b' )['columns'], 'Escaped native identifiers must be decoded exactly.' );

foreach ( array(
	str_replace( 'ENGINE=InnoDB', 'ENGINE=FEDERATED CONNECTION=\'mysql://secret\'', $keyless ),
	$keyless . '; DROP TABLE wp_options',
	str_replace( 'decimal(30,10) DEFAULT NULL', 'int DEFAULT (evil())', $keyless ),
	str_replace( 'longblob', 'longblob CHECK (evil())', $keyless ),
	str_replace( 'longblob', 'longblob GENERATED ALWAYS AS (evil()) STORED', $keyless ),
	str_replace( 'REFERENCES `wp_parent`', 'REFERENCES `outside`.`wp_parent`', $foreign_ddl ),
	str_replace( 'KEY `parent` (`parent`)', 'KEY `parent` ((evil()))', $foreign_ddl ),
	str_replace( 'DEFAULT CHARSET=utf8mb4', 'DEFAULT CHARSET=utf8mb4 /*!50100 TABLESPACE outside */', $keyless ),
	str_replace( 'ENGINE=InnoDB', 'ENGINE=MyISAM', $keyless ),
) as $unsafe ) {
	$name = false !== strpos( $unsafe, 'wp_child' ) ? 'wp_child' : 'wp_duplicate';
	$throws( static function () use ( $unsafe, $name ) { Backup_Schema::blueprint( $unsafe, $name ); }, 'Unproven executable, external or nontransactional schema must not enter a full restore.' );
}

// Exercise the actual structural preflight without constructing a native DB.
$reflection = new ReflectionClass( Backup_Restore::class );
$restore = $reflection->newInstanceWithoutConstructor();
$property = $reflection->getProperty( 'budget' );
$property->setValue( $restore, 16384 );
$method = $reflection->getMethod( 'validate' );
$packed = array( 'table' => base64_encode( 'wp_duplicate' ), 'ddl' => base64_encode( $keyless ), 'hash' => hash( 'sha256', $keyless ),
	'columns' => array( array( 'name' => base64_encode( '0' ), 'type' => 'longblob', 'charset' => null ), array( 'name' => base64_encode( '01' ), 'type' => 'decimal', 'charset' => null ) ) );
$plan = array( 'tables' => array( $packed['table'] ), 'schemas' => array( $packed['table'] => $packed['hash'] ),
	'roots' => array( array( 'id' => 'installation', 'mode' => 0755 ) ), 'database' => array( 'session' => array( 'sql_mode' => '', 'time_zone' => '+00:00' ) ) );
$state = array( 'grammar' => 'header', 'seq' => 0, 'table_index' => 0, 'root_index' => 0, 'schemas' => array(),
	'db_digest' => hash( 'sha256', 'wpai-backup-database-v1' ), 'files_digest' => hash( 'sha256', 'wpai-backup-files-v1' ),
	'chain' => Backup_Archive::initial_chain() );
$initial_state = $state;
foreach ( array( array( 'time_zone' => 'SYSTEM' ), array( 'mode' => '0777' ) ) as $malformed ) {
	$bad_state = $initial_state;
	$bad_plan = $plan;
	if ( isset( $malformed['time_zone'] ) ) {
		$bad_plan['database']['session']['time_zone'] = $malformed['time_zone'];
	} else {
		$bad_plan['roots'][0]['mode'] = $malformed['mode'];
	}
	$throws( static function () use ( $method, $restore, &$bad_state, $bad_plan ) {
		$args = array( &$bad_state, array( 'type' => 'header' ), json_encode( array( 'format' => Backup_Archive::FORMAT, 'plan' => $bad_plan, 'chunk_bytes' => 4096, 'consistency' => 'declared_quiescent_verified_capture' ) ) );
		$method->invokeArgs( $restore, $args );
	}, 'Malformed imported root mode/timezone must fail before target effects.' );
}
$feed = static function ( array $record, $bytes = '' ) use ( $method, $restore, &$state ) {
	$args = array( &$state, $record, $bytes );
	$method->invokeArgs( $restore, $args );
};
$feed( array( 'type' => 'header' ), json_encode( array( 'format' => Backup_Archive::FORMAT, 'plan' => $plan, 'chunk_bytes' => 4096, 'consistency' => 'declared_quiescent_verified_capture' ) ) );
$feed( array( 'type' => 'table', 'table' => $packed['table'], 'schema_hash' => $packed['hash'] ), json_encode( $packed ) );
$group = hash( 'sha256', 'wpai-row-v1;V0:' . hash( 'sha256', '' ) . ';N;' );
$feed( array( 'type' => 'row', 'digest' => $group, 'multiplicity' => '2' ) );
$feed( array( 'type' => 'cell', 'column' => 0, 'offset' => 0, 'total' => 0, 'null' => false, 'sha256' => hash( 'sha256', '' ) ) );
$feed( array( 'type' => 'cell', 'column' => 1, 'offset' => 0, 'total' => 0, 'null' => true, 'sha256' => null ) );
$feed( array( 'type' => 'row_end', 'digest' => $group ) );
$feed( array( 'type' => 'table_end', 'table' => $packed['table'] ) );
$feed( array( 'type' => 'root', 'root' => 'installation', 'mode' => 0755 ) );
$feed( array( 'type' => 'directory', 'components' => array( base64_encode( 'empty' ) ), 'mode' => 0555, 'source_identity' => str_repeat( 'a', 64 ) ) );
$feed( array( 'type' => 'directory_end', 'components' => array( base64_encode( 'empty' ) ) ) );
$throws( static function () use ( $feed ) { $feed( array( 'type' => 'directory', 'components' => array( base64_encode( 'empty' ) ), 'mode' => 0755, 'source_identity' => str_repeat( 'a', 64 ) ) ); }, 'Duplicate paths must fail preflight before restore effects.' );
$feed( array( 'type' => 'file', 'components' => array( base64_encode( 'z-empty.bin' ) ), 'mode' => 0640, 'bytes' => 0, 'chunk_bytes' => 4096, 'source_identity' => str_repeat( 'b', 64 ) ) );
$feed( array( 'type' => 'file_end', 'bytes' => 0, 'chain' => Backup_Archive::initial_chain() ) );
$feed( array( 'type' => 'directory_end', 'components' => array() ) );
$feed( array( 'type' => 'root_end' ) );
$feed( array( 'type' => 'footer', 'source_verified' => true, 'previous_chain' => $state['chain'], 'database_digest' => $state['db_digest'], 'files_digest' => $state['files_digest'] ) );
$assert( isset( $state['footer'] ), 'A structurally complete keyless-duplicate/empty-directory package should pass preflight.' );
$throws( static function () use ( $feed ) { $feed( array( 'type' => 'footer', 'source_verified' => true, 'previous_chain' => str_repeat( '0', 64 ), 'database_digest' => str_repeat( '0', 64 ), 'files_digest' => str_repeat( '0', 64 ) ) ); }, 'An incomplete/wrong footer must not grant complete status.' );

echo "issue199-backup: {$checks} checks passed\n";
