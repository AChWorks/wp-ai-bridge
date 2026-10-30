<?php
/**
 * Complete imported-cell/representation preflight, including resumable replay.
 *
 * @package WP_AI_Bridge
 */
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/fixtures/issue199-backup-package.php';

use WP_AI_Bridge\Support\Backup_Archive;
use WP_AI_Bridge\Support\Backup_Exporter;
use WP_AI_Bridge\Support\Backup_Restore;
use WP_AI_Bridge\Support\Backup_Schema;

final class WPAI_Backup_Preflight_Target_Spy {
	public $database = array();
	public $files = array();
	public $calls = 0;
	public function __call( $name, $args ) {
		++$this->calls;
		throw new LogicException( 'Preflight attempted a target operation.' );
	}
}

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$validator = new ReflectionMethod( Backup_Restore::class, 'validate' );
$construct = static function ( $seconds, $target ) {
	$reflection = new ReflectionClass( Backup_Restore::class );
	$restore = $reflection->newInstanceWithoutConstructor();
	foreach ( array( 'budget' => 16384, 'policy' => array( 'step_seconds' => $seconds ), 'database' => $target, 'filesystem' => $target ) as $name => $value ) {
		$reflection->getProperty( $name )->setValue( $restore, $value );
	}
	return $restore;
};
$run = static function ( array $package, $expected_error = null, $seconds = 5 ) use ( $assert, $validator, $construct ) {
	$stream = fopen( 'php://memory', 'w+b' );
	foreach ( $package['frames'] as $frame ) {
		fwrite( $stream, $frame );
	}
	rewind( $stream );
	// Independently verify every outer frame and the entire transport chain:
	// malformed fixtures must not merely be broken/truncated outer envelopes.
	$chain = Backup_Archive::initial_chain();
	while ( null !== ( $frame = Backup_Archive::read( $stream, 16384 ) ) ) {
		$chain = Backup_Archive::fold( $chain, $frame['frame'] );
	}
	$assert( $chain === $package['chain'], 'The deliberately malformed package must have a valid complete outer frame chain.' );
	rewind( $stream );
	$target = new WPAI_Backup_Preflight_Target_Spy();
	$before = serialize( array( $target->database, $target->files ) );
	$state = array( 'status' => 'preflighting', 'phase' => 'preflight', 'grammar' => 'header', 'seq' => 0, 'offset' => 0,
		'table_index' => 0, 'root_index' => 0, 'schemas' => array(), 'db_digest' => hash( 'sha256', 'wpai-backup-database-v1' ),
		'files_digest' => hash( 'sha256', 'wpai-backup-files-v1' ), 'chain' => Backup_Archive::initial_chain() );
	$error = null;
	$maximum_checkpoint = 0;
	try {
		while ( null !== ( $frame = Backup_Archive::read( $stream, 16384 ) ) ) {
			$position = ftell( $stream );
			$restore = $construct( $seconds, $target );
			$args = array( &$state, $frame['record'], $frame['payload'], $stream );
			try {
				$validator->invokeArgs( $restore, $args );
			} finally {
				$assert( $position === ftell( $stream ), 'Cell replay must restore the caller archive stream position, including failure.' );
			}
			$state['offset'] += strlen( $frame['frame'] );
			$state['chain'] = Backup_Archive::fold( $state['chain'], $frame['frame'] );
			++$state['seq'];
			$wire = json_encode( $state, JSON_THROW_ON_ERROR );
			$maximum_checkpoint = max( $maximum_checkpoint, strlen( $wire ) );
			// Model a new HTTP request with only JSON-compatible durable state.
			$state = json_decode( $wire, true, 64, JSON_THROW_ON_ERROR );
		}
		if ( isset( $state['footer'] ) && $state['offset'] === $package['bytes'] && $state['chain'] === $package['chain'] ) {
			$state['status'] = 'ready';
		}
	} catch ( RuntimeException $caught ) {
		$error = $caught->getMessage();
	} finally {
		fclose( $stream );
	}
	$assert( $expected_error === $error, 'Complete preflight outcome differs: ' . ( $error ?? 'ready' ) );
	$assert( null === $expected_error ? 'ready' === $state['status'] : 'ready' !== $state['status'], 'Malformed cell/metadata must not reach ready.' );
	$assert( 0 === $target->calls && $before === serialize( array( $target->database, $target->files ) ), 'Preflight changed the target database or filesystem.' );
	$assert( $maximum_checkpoint < 16384, 'A multi-megabyte cell must not be persisted inside its small private checkpoint.' );
};

$run( WPAI_Backup_Package_Fixture::make( '', 4194307 ) );
foreach ( array( 'wrong_cell_sha', 'wrong_payload', 'wrong_empty_sha' ) as $fault ) {
	$run( WPAI_Backup_Package_Fixture::make( $fault ), 'backup_archive_cell_integrity' );
}
foreach ( array( 'wrong_type', 'wrong_binary_charset', 'wrong_text_charset', 'extra_codec' ) as $fault ) {
	$run( WPAI_Backup_Package_Fixture::make( $fault ), 'backup_schema_metadata_mismatch' );
}
$run( WPAI_Backup_Package_Fixture::make(), 'backup_cell_verification_resources', 0.000000001 );

$packed = WPAI_Backup_Package_Fixture::make( '', 1 )['packed'];
$schema = Backup_Exporter::unpack_schema( $packed );
$assert( array( 'name' => 'payload', 'type' => 'longblob', 'charset' => null ) === $schema['columns'][0], 'Binary restore representation must derive from the validated native definition.' );
$assert( array( 'name' => 'note', 'type' => 'longtext', 'charset' => 'utf8mb4' ) === $schema['columns'][1], 'Quoted comment/default words must not replace the real character set.' );
$q = static function ( $name ) { return chr( 96 ) . $name . chr( 96 ); };
$json_ddl = 'CREATE TABLE ' . $q( 'wp_json' ) . ' (' . $q( 'doc' ) . ' json DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
$json_blueprint = Backup_Schema::blueprint( $json_ddl, 'wp_json' );
$assert( array( array( 'name' => 'doc', 'type' => 'json', 'charset' => null ) ) === $json_blueprint['column_metadata'], 'Native MySQL JSON metadata has no character-column charset.' );
$assert( Backup_Schema::reconcile_columns( $json_blueprint, array( array( 'name' => 'doc', 'type' => 'json', 'charset' => null ) ) ) === $json_blueprint['column_metadata'], 'Valid JSON native metadata must remain supported.' );

echo "issue199-backup-preflight-integrity: {$checks} checks passed\n";
