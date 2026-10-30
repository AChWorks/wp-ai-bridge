<?php
/**
 * Real scoped iterator acknowledgements and bounded export cursor progression.
 *
 * @package WP_AI_Bridge
 */
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Support\Backup_Archive;
use WP_AI_Bridge\Support\Backup_Exporter;
use WP_AI_Bridge\Support\Backup_Restore;
use WP_AI_Bridge\Support\Filesystem_Roots;
use WP_AI_Bridge\Support\Scoped_Filesystem;

function wp_salt( $purpose ) { return 'backup-entry-regression-salt'; }
function is_multisite() { return false; }

final class WPAI_Backup_Entries_Access {
	public $config;
	public function configuration() { return $this->config; }
	public function context() { return array( 'blog' => 1, 'network' => 1, 'user' => 1, 'client' => 'entry-test' ); }
	public function allowed( $group, $network = false, $requirements = array() ) { return true; }
}

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
};
$checked = static function ( $result ) {
	if ( is_wp_error( $result ) ) { throw new RuntimeException( $result->get_error_code() ); }
	return $result;
};
$fixture = sys_get_temp_dir() . '/wpai-backup-entries-' . bin2hex( random_bytes( 8 ) );
mkdir( $fixture, 0700 );
$names = array( 'z-last', 'a-first', 'm-middle' );
try {
	foreach ( $names as $name ) { file_put_contents( $fixture . '/' . $name, '' ); }
	$native = lstat( $fixture );
	$access = new WPAI_Backup_Entries_Access();
	$access->config = array( 'revision' => str_repeat( 'a', 64 ), 'step_bytes' => 512, 'step_seconds' => 5,
		'roots' => array( 'installation' => array( 'id' => 'installation', 'label' => 'Entry test', 'path' => $fixture,
			'purposes' => array( 'backup_export', 'backup_restore' ), 'nonserved' => true, 'read' => true, 'scope' => 'site', 'blog' => 1, 'network' => 1,
			'device' => $native['dev'], 'inode' => $native['ino'], 'executable' => false ) ) );
	$root_reflection = new ReflectionClass( Filesystem_Roots::class );
	$roots = $root_reflection->newInstanceWithoutConstructor();
	$root_reflection->getProperty( 'access' )->setValue( $roots, $access );
	$fs_reflection = new ReflectionClass( Scoped_Filesystem::class );
	$filesystem = $fs_reflection->newInstanceWithoutConstructor();
	$fs_reflection->getProperty( 'roots' )->setValue( $filesystem, $roots );
	$fs_reflection->getProperty( 'access' )->setValue( $filesystem, $access );
	$directory = $checked( $filesystem->stat( 'installation', array(), 'backup_export' ) );

	// Prove the real callback contract, including retry and consumed-stop.
	$retry = $checked( $filesystem->iterate_entries( 'installation', array(), 'backup_export', $directory['identity'],
		static function () { return null; }, array( 'entries' => 1 ) ) );
	$assert( 0 === $retry['cursor'] && 0 === $retry['scanned'] && ! $retry['complete'], 'Null must retry the same unconsumed entry.' );
	$stop = $checked( $filesystem->iterate_entries( 'installation', array(), 'backup_export', $directory['identity'],
		static function () { return false; }, array( 'entries' => 1 ) ) );
	$assert( 1 === $stop['cursor'] && 1 === $stop['scanned'] && ! $stop['complete'], 'False must acknowledge the entry but stop before another one.' );
	$seen = array();
	$cursor = 0;
	do {
		$page = $checked( $filesystem->iterate_entries( 'installation', array(), 'backup_export', $directory['identity'],
			static function ( $entry ) use ( &$seen ) { $seen[] = base64_decode( end( $entry['components'] ), true ); return true; },
			array( 'entries' => 1, 'cursor' => $cursor ) ) );
		$assert( $page['complete'] || $page['cursor'] > $cursor, 'An exhausted finite entry page must advance its consumed cursor.' );
		$cursor = $page['cursor'];
	} while ( ! $page['complete'] );
	$assert( count( $names ) === $cursor && count( $names ) === count( $seen ) && count( array_unique( $seen ) ) === count( $names ),
		'Actual bounded iterator pages must visit every native entry exactly once.' );

	$export_reflection = new ReflectionClass( Backup_Exporter::class );
	$exporter = $export_reflection->newInstanceWithoutConstructor();
	$export_reflection->getProperty( 'filesystem' )->setValue( $exporter, $filesystem );
	$export_reflection->getProperty( 'policy' )->setValue( $exporter, array( 'step_bytes' => 512, 'step_seconds' => 5 ) );
	$next = $export_reflection->getMethod( 'next' );
	$state = array( 'phase' => 'entries', 'mode' => 'capture', 'root_index' => 0,
		'plan' => array( 'roots' => array( array( 'id' => 'installation' ) ) ), 'chunk_bytes' => 1,
		'files_digest' => hash( 'sha256', 'wpai-backup-files-v1' ),
		'stack' => array( array( 'components' => array(), 'identity' => $directory['identity'], 'after' => null,
			'scan_cursor' => 0, 'candidate' => null ) ) );
	$emitted = array();
	$pauses = 0;
	$root_ended = false;
	for ( $step = 0; $step < 64 && ! $root_ended; ++$step ) {
		$before = $state;
		$args = array( &$state );
		$record = $next->invokeArgs( $exporter, $args );
		if ( null === $record ) {
			++$pauses;
			$assert( 'entries' === $before['phase'] && 'entries' === $state['phase'] &&
				$state['stack'][0]['scan_cursor'] > $before['stack'][0]['scan_cursor'],
				'Exporter metadata consumption must acknowledge progress instead of retrying one entry forever.' );
		} elseif ( 'file' === $record['record']['type'] ) {
			$emitted[] = base64_decode( end( $record['record']['components'] ), true );
		} elseif ( 'root_end' === $record['record']['type'] ) {
			$root_ended = true;
		}
		// Every continuation recovers only JSON-persistable cursor state.
		$state = json_decode( json_encode( $state ), true, 512, JSON_THROW_ON_ERROR );
	}
	sort( $names, SORT_STRING );
	$assert( $root_ended && $pauses > 1 && $emitted === $names,
		'Multi-step export must eventually emit all entries once in canonical order and close the root.' );
	$assert( 1 === $state['root_index'] && array() === $state['stack'] && 'root' === $state['phase'],
		'Finished directory/root cursors must not replay or silently skip entries.' );
	$restore_reflection = new ReflectionClass( Backup_Restore::class );
	$restore = $restore_reflection->newInstanceWithoutConstructor();
	$restore_reflection->getProperty( 'filesystem' )->setValue( $restore, $filesystem );
	$restore_reflection->getProperty( 'policy' )->setValue( $restore, array( 'step_bytes' => 512, 'step_seconds' => 5 ) );
	$verify = $restore_reflection->getMethod( 'verify_file_record' );
	$restoring = array( 'target_root' => 'installation', 'verify_stack' => array( array( 'parts' => array(), 'expected' => 3 ) ) );
	$finished = false;
	for ( $step = 0; $step < 8 && ! $finished; ++$step ) {
		$before_cursor = $restoring['directory_count']['cursor'] ?? 0;
		$args = array( &$restoring, array( 'type' => 'directory_end' ), '' );
		$finished = $verify->invokeArgs( $restore, $args );
		if ( ! $finished ) {
			$assert( $restoring['directory_count']['cursor'] > $before_cursor &&
				$restoring['directory_count']['count'] === $restoring['directory_count']['cursor'],
				'Restore directory verification must acknowledge and count every consumed entry once across steps.' );
		}
		$restoring = json_decode( json_encode( $restoring ), true, 512, JSON_THROW_ON_ERROR );
	}
	$assert( $finished && array() === $restoring['verify_stack'] && ! isset( $restoring['directory_count'] ),
		'Bounded restore verification must finish after all actual entries and discard its completed counter.' );
	$access->config['step_bytes'] = 4096;
	$restore_reflection->getProperty( 'policy' )->setValue( $restore, array( 'step_bytes' => 4096, 'step_seconds' => 5 ) );
	$count = $restore_reflection->getMethod( 'directory_count' );
	$count->invoke( $restore, 'installation', array(), 3 );
	$assert( true, 'Wrapper directory counts must acknowledge real entries and complete.' );
	$rejected_extra = false;
	try { $count->invoke( $restore, 'installation', array(), 2 ); }
	catch ( RuntimeException $error ) { $rejected_extra = 'backup_restore_extra_files' === $error->getMessage(); }
	$assert( $rejected_extra, 'Acknowledging entries must retain unexpected-extra detection.' );

} finally {
	foreach ( $names as $name ) { if ( is_file( $fixture . '/' . $name ) ) { unlink( $fixture . '/' . $name ); } }
	rmdir( $fixture );
}
echo "issue199-backup-entries: {$checks} checks passed\n";
