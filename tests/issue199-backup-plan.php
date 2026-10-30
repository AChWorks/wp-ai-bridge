<?php
/**
 * Actual exporter plan/footer stability across native SQL-mode ordering.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Support {
	class Permissions {}
	class Administration_Access {
		public function __construct( $permissions ) {}
		public function configuration() { return array( 'revision' => str_repeat( 'a', 64 ), 'backup_tables' => array() ); }
		public function context() { return array( 'user' => 1, 'blog' => 1, 'network' => 1, 'client' => 'plan-test' ); }
	}
	class Filesystem_Roots {
		public function __construct( $permissions ) {}
		public function resolve( $id, $purpose, $mode ) { return array( 'path' => rtrim( ABSPATH, '/' ) ); }
		public static function within( $path, $root ) { return $path === $root || 0 === strpos( $path, $root . DIRECTORY_SEPARATOR ); }
	}
}
namespace {
	function is_wp_error( $value ) { return false; }
	function wp_upload_dir( $time, $create, $refresh ) { return array( 'basedir' => ABSPATH ); }

	require __DIR__ . '/../src/Support/class-backup-archive.php';
	require __DIR__ . '/../src/Support/class-backup-operation.php';
	require __DIR__ . '/../src/Support/class-backup-exporter.php';

	final class WPAI_Backup_Plan_Native {
		public $mode = 'ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION';
		public $version = '11.8.6-native-order-fixture';
		public function identity() {
			return array( 'database' => 'wp_source', 'prefix' => 'wp_',
				'session' => array( 'sql_mode' => $this->mode, 'time_zone' => 'SYSTEM', 'version' => $this->version ) );
		}
		public function inventory( $extras ) { return array( 'wp_options' ); }
		public function schema( $table ) { return array( 'hash' => str_repeat( 'b', 64 ), 'blueprint' => array( 'foreign' => array() ) ); }
		public function activity_digest() { return str_repeat( 'c', 64 ); }
	}
	final class WPAI_Backup_Plan_Filesystem {
		public $mode = 0755;
		public function stat( $id, $parts, $purpose ) {
			return array( 'type' => 'directory', 'identity' => str_repeat( 'd', 64 ), 'mode' => $this->mode & 0777, 'stat' => array( 'mode' => 0040000 | $this->mode ) );
		}
	}

	$checks = 0;
	$assert = static function ( $condition, $message ) use ( &$checks ) {
		++$checks;
		if ( ! $condition ) { throw new \RuntimeException( $message ); }
	};
	$fixture = sys_get_temp_dir() . '/wpai-backup-mode-plan-' . bin2hex( random_bytes( 8 ) );
	mkdir( $fixture, 0700 );
	define( 'ABSPATH', $fixture . '/' );
	try {
		$reflection = new \ReflectionClass( \WP_AI_Bridge\Support\Backup_Exporter::class );
		$exporter = $reflection->newInstanceWithoutConstructor();
		$permissions = new \WP_AI_Bridge\Support\Permissions();
		$native = new WPAI_Backup_Plan_Native();
		$filesystem = new WPAI_Backup_Plan_Filesystem();
		foreach ( array( 'permissions' => $permissions, 'access' => new \WP_AI_Bridge\Support\Administration_Access( $permissions ),
			'database' => $native, 'filesystem' => $filesystem, 'policy' => array( 'request' => 131072 ) ) as $name => $value ) {
			$reflection->getProperty( $name )->setValue( $exporter, $value );
		}
		$initial = $exporter->plan( array( 'installation' ) );
		$assert( 0755 === $initial['roots'][0]['mode'] && \WP_AI_Bridge\Support\Backup_Archive::FORMAT === $initial['format'],
			'Ordinary root permissions and the existing archive version must remain unchanged.' );
		foreach ( array( 01777, 02755, 04755 ) as $unsupported_mode ) {
			$filesystem->mode = $unsupported_mode;
			foreach ( array( 'plan', 'begin' ) as $action ) {
				$rejected = false;
				try {
					if ( 'plan' === $action ) { $exporter->plan( array( 'installation' ) ); }
					else { $exporter->begin( array( 'source_roots' => array( 'installation' ), 'expected_plan_hash' => $initial['plan_hash'],
						'quiescence_confirmed' => true, 'bootstrap_writers_confirmed' => true ) ); }
				} catch ( \RuntimeException $error ) { $rejected = 'backup_permission_metadata_unsupported' === $error->getMessage(); }
				$assert( $rejected, 'Unsupported raw root permissions must reject inspect and begin before any archive lease.' );
			}
		}
		$filesystem->mode = 0755;
		$expected_modes = array( 'ERROR_FOR_DIVISION_BY_ZERO', 'NO_AUTO_CREATE_USER', 'NO_AUTO_VALUE_ON_ZERO', 'NO_ENGINE_SUBSTITUTION' );
		$assert( implode( ',', $expected_modes ) === $initial['database']['session']['sql_mode'],
			'The augmented plan must retain every native mode name in deterministic string order.' );
		$assert( '+00:00' === $initial['database']['session']['time_zone'] &&
			$native->version === $initial['database']['session']['version'] &&
			'wp_source' === $initial['database']['database'] && 'wp_' === $initial['database']['prefix'],
			'Canonical ordering must preserve the established UTC capture setting and all other native identity fields.' );
		$state = array( 'mode' => 'verify', 'phase' => 'root', 'root_index' => 1, 'plan' => $initial,
			'expected_db' => str_repeat( 'e', 64 ), 'db_digest' => str_repeat( 'e', 64 ),
			'expected_files' => str_repeat( 'f', 64 ), 'files_digest' => str_repeat( 'f', 64 ),
			'chain' => \WP_AI_Bridge\Support\Backup_Archive::initial_chain() );
		$next = $reflection->getMethod( 'next' );
		foreach ( array(
			'NO_AUTO_VALUE_ON_ZERO,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION',
			'NO_ENGINE_SUBSTITUTION,NO_AUTO_CREATE_USER,ERROR_FOR_DIVISION_BY_ZERO,NO_AUTO_VALUE_ON_ZERO',
			implode( ',', $expected_modes ),
		) as $native_order ) {
			$native->mode = $native_order;
			$current = $exporter->plan( array( 'installation' ) );
			$assert( $initial === $current, 'Equivalent native mode readback order must not invalidate the exact complete source plan.' );
			$continuation = json_decode( json_encode( $state ), true, 512, JSON_THROW_ON_ERROR );
			$args = array( &$continuation );
			$record = $next->invokeArgs( $exporter, $args );
			$assert( 'footer' === $record['record']['type'] && true === $record['record']['source_verified'],
				'Final source verification must reach its footer when only native mode ordering differs.' );
		}
		foreach ( array(
			implode( ',', $expected_modes ) . ',STRICT_TRANS_TABLES',
			'NO_AUTO_VALUE_ON_ZERO,NO_AUTO_CREATE_USER,NO_ENGINE_SUBSTITUTION',
		) as $changed_set ) {
			$native->mode = $changed_set;
			$changed = $exporter->plan( array( 'installation' ) );
			$assert( $initial['plan_hash'] !== $changed['plan_hash'], 'A material native mode-name addition/removal must change the plan identity.' );
			$continuation = $state;
			$args = array( &$continuation );
			$stale = false;
			try { $next->invokeArgs( $exporter, $args ); }
			catch ( \RuntimeException $error ) { $stale = 'backup_source_stale' === $error->getMessage(); }
			$assert( $stale, 'Canonical ordering must not remove final stale rejection for a changed mode set.' );
		}
		$native->mode = implode( ',', $expected_modes );
		foreach ( array( 'db_digest', 'files_digest' ) as $domain ) {
			$continuation = $state;
			$continuation[ $domain ] = str_repeat( '0', 64 );
			$args = array( &$continuation );
			$stale = false;
			try { $next->invokeArgs( $exporter, $args ); }
			catch ( \RuntimeException $error ) { $stale = 'backup_source_stale' === $error->getMessage(); }
			$assert( $stale, 'The complete native database/file digest fences must remain strict.' );
		}
	} finally {
		rmdir( $fixture );
	}
	echo "issue199-backup-plan: {$checks} checks passed\n";
}
