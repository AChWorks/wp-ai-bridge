<?php
/**
 * Real temporary-filesystem regressions with isolated authority/journal fixtures.
 */
namespace WP_AI_Bridge\Auth {
	class OAuth_Server {
		public static $client = 'filesystem-test-client';
		public static function authenticated_mcp_client_id() { return self::$client; }
	}
}
namespace WP_AI_Bridge\Support {
	class Permissions {}
	class Administration_Access {
		public function __construct( $permissions ) {}
		public function context() { return $GLOBALS['fs_origin']; }
		public function same_context( $origin ) { return $origin === $GLOBALS['fs_origin']; }
		public function configuration() { return $GLOBALS['fs_config']; }
		public function allowed( $group, $network = false, $requirements = array() ) {
			if ( empty( $GLOBALS['fs_grants'][ $group ] ) ) { return false; }
			foreach ( $requirements as $requirement ) {
				if ( empty( $GLOBALS['fs_grants'][ $requirement[0] ] ) ) { return false; }
			}
			return true;
		}
	}
	class Administration_Operation_Journal {
		public static $records = array();
		private $key;
		public function __construct( $purpose, $identity, $budget ) {
			$this->key = hash( 'sha256', serialize( array( $purpose, $identity ) ) );
		}
		public function status( $id, $fingerprint = null ) {
			$record = self::$records[ $this->key . $id ] ?? null;
			if ( null !== $record && null !== $fingerprint && $fingerprint !== $record['receipt']['fingerprint'] ) {
				return new \WP_Error( 'administration_operation_conflict', 'request changed' );
			}
			if ( null !== $record ) { $record['historical'] = true; }
			return $record;
		}
		public function reserve( $id, $fingerprint, $descriptor ) {
			$existing = $this->status( $id, $fingerprint );
			if ( null !== $existing ) { return $existing; }
			$record = array( 'state' => 'prepared', 'historical' => false,
				'receipt' => array( 'fingerprint' => $fingerprint, 'descriptor' => $descriptor, 'result' => null ),
				'dispatch_allowed' => true, 'claim' => array( 'key' => $this->key . $id ) );
			self::$records[ $this->key . $id ] = $record;
			return $record;
		}
		public function arm( $claim ) {
			if ( isset( $GLOBALS['fs_arm_hook'] ) ) {
				$hook = $GLOBALS['fs_arm_hook'];
				unset( $GLOBALS['fs_arm_hook'] );
				$hook( self::$records[ $claim['key'] ]['receipt']['descriptor'] );
			}
			self::$records[ $claim['key'] ]['state'] = 'dispatch_armed';
			self::$records[ $claim['key'] ]['dispatch_allowed'] = false;
			return true;
		}
		public function complete( $claim, $result ) {
			self::$records[ $claim['key'] ]['state'] = 'completed';
			self::$records[ $claim['key'] ]['receipt']['result'] = $result;
			self::$records[ $claim['key'] ]['dispatch_allowed'] = false;
			return self::$records[ $claim['key'] ];
		}
	}
}
namespace {
	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code, $message = '', $data = null ) {
			$this->code = $code; $this->message = $message; $this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_message() { return $this->message; }
		public function get_error_data() { return $this->data; }
	}
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
	function wp_json_encode( $value ) {
		if ( ! empty( $GLOBALS['fs_fail_terminal_checkpoint'] ) && is_array( $value ) &&
			'wpai-filesystem-copy-v1' === ( $value['checkpoint']['format'] ?? '' ) &&
			'committed' === ( $value['checkpoint']['outcome'] ?? '' ) ) {
			unset( $GLOBALS['fs_fail_terminal_checkpoint'] );
			return false;
		}
		return json_encode( $value );
	}
	function wp_salt( $purpose ) { return 'synthetic-filesystem-fixture-salt'; }
	function get_current_blog_id() { return $GLOBALS['fs_origin']['blog']; }
	function get_current_network_id() { return $GLOBALS['fs_origin']['network']; }
	function get_current_user_id() { return $GLOBALS['fs_origin']['user']; }
	function is_multisite() { return false; }
	function is_super_admin() { return true; }
	function current_user_can( $capability ) { return $GLOBALS['fs_capabilities'][ $capability ] ?? true; }
	function wp_is_file_mod_allowed( $context ) { return true; }
	function get_option( $key, $fallback = null ) { return $GLOBALS['fs_options'][ $key ] ?? $fallback; }
	function get_temp_dir() { return $GLOBALS['fs_temp']; }
	function wp_get_upload_dir() { return array( 'basedir' => ABSPATH . 'uploads' ); }
	function wp_normalize_path( $path ) { return str_replace( '\\', '/', $path ); }
	function trailingslashit( $path ) { return rtrim( $path, '/\\' ) . '/'; }
	function wp_convert_hr_to_bytes( $value ) { return 128 * 1048576; }
	function wp_delete_file( $path ) { return unlink( $path ); }
	function __( $value, $domain = '' ) { return $value; }
	function wp_tempnam( $prefix, $directory ) { return tempnam( $directory, $prefix ); }

	$fixture = sys_get_temp_dir() . '/wpai-fs-test-' . bin2hex( random_bytes( 8 ) );
	mkdir( $fixture, 0700 );
	mkdir( $fixture . '/site', 0755 );
	mkdir( $fixture . '/private', 0700 );
	mkdir( $fixture . '/site/uploads', 0755 );
	define( 'ABSPATH', $fixture . '/site/' );
	define( 'WP_CONTENT_DIR', $fixture . '/site/content' );
	define( 'DB_NAME', 'synthetic_filesystem' );
	$GLOBALS['fs_temp'] = $fixture . '/private';
	$GLOBALS['fs_origin'] = array( 'blog' => 1, 'network' => 1, 'user' => 1, 'client' => 'filesystem-test-client' );
	$GLOBALS['fs_options'] = array();
	$GLOBALS['fs_grants'] = array_fill_keys( array( 'filesystem_read', 'filesystem_write', 'filesystem_delete',
		'filesystem_executable', 'full_backup', 'full_restore', 'sensitive_logs_read' ), true );
	$root_stat = lstat( $fixture . '/site/uploads' );
	$GLOBALS['fs_config'] = array( 'revision' => str_repeat( 'a', 64 ), 'step_bytes' => 7, 'step_seconds' => 1,
		'roots' => array( 'data' => array( 'id' => 'data', 'label' => 'Test files', 'path' => $fixture . '/site/uploads',
			'purposes' => array( 'filesystem', 'logs', 'backup_export' ), 'read' => true, 'write' => true,
			'destructive' => true, 'executable' => false, 'scope' => 'site', 'blog' => 1, 'network' => 1,
			'device' => $root_stat['dev'], 'inode' => $root_stat['ino'], 'nonserved' => false ) ) );
	require __DIR__ . '/../src/Support/class-transfer-settings.php';
	require __DIR__ . '/../src/Support/class-payload-settings.php';
	require __DIR__ . '/../src/Support/class-bounded-payload.php';
	require __DIR__ . '/../src/Support/class-safe-temporary-staging.php';
	require __DIR__ . '/../src/Support/class-administration-transfer-writer.php';
	require __DIR__ . '/../src/Support/class-private-transfer-session.php';
	require __DIR__ . '/../src/Support/class-filesystem-roots.php';
	require __DIR__ . '/../src/Support/class-scoped-filesystem.php';
	require __DIR__ . '/../src/Support/class-filesystem-operation.php';
	require __DIR__ . '/../src/Support/class-filesystem-transfer.php';

	$assertions = 0;
	function check( $condition, $label ) {
		++$GLOBALS['assertions'];
		if ( ! $condition ) { throw new \RuntimeException( 'FAIL: ' . $label ); }
	}
	function ok( $result, $label ) {
		check( ! is_wp_error( $result ), $label . ( is_wp_error( $result ) ? ': ' . $result->get_error_code() : '' ) );
		return $result;
	}
	function remove_tree( $path ) {
		if ( is_link( $path ) || ! is_dir( $path ) ) { @unlink( $path ); return; }
		foreach ( new \FilesystemIterator( $path ) as $entry ) { remove_tree( $entry->getPathname() ); }
		rmdir( $path );
	}
	try {
		$permissions = new \WP_AI_Bridge\Support\Permissions();
		$fs = new \WP_AI_Bridge\Support\Scoped_Filesystem( $permissions );
		$roots = new \WP_AI_Bridge\Support\Filesystem_Roots( $permissions );
		foreach ( array( '../outside', '/etc/passwd', 'file:///etc/passwd', 'a//b', 'a/./b', "bad\0name" ) as $path ) {
			check( is_wp_error( \WP_AI_Bridge\Support\Scoped_Filesystem::components( $path ) ), 'invalid path rejected' );
		}
		$binary_name = "name-\xff\\raw";
		file_put_contents( $fixture . '/site/uploads/' . $binary_name, "binary\0\xff" );
		$binary = ok( $fs->stat( 'data', array( base64_encode( $binary_name ) ) ), 'binary filename' );
		$window = ok( $fs->read_window( 'data', $binary['components'], 'filesystem', $binary['identity'], 1, 4 ), 'binary window' );
		check( "inar" === $window['bytes'], 'byte offsets are raw bytes' );
		symlink( $fixture . '/private', $fixture . '/site/uploads/escape' );
		check( is_wp_error( $fs->stat( 'data', 'escape/unknown' ) ), 'symlink ancestor rejected' );
		file_put_contents( $fixture . '/private/secret', 'secret' );
		link( $fixture . '/private/secret', $fixture . '/site/uploads/alias' );
		check( is_wp_error( $fs->stat( 'data', 'alias' ) ), 'hardlink alias rejected' );
		unlink( $fixture . '/site/uploads/alias' );
		mkdir( $fixture . '/site/uploads/wpai-transfer-private', 0700 );
		check( is_wp_error( $fs->stat( 'data', 'wpai-transfer-private' ) ), 'private state rejected' );
		$gap = $fs->iterate_entries( 'data', array(), 'backup_export', null, function () { return true; }, array() );
		check( is_wp_error( $gap ), 'backup never silently omits protected entries' );
		rmdir( $fixture . '/site/uploads/wpai-transfer-private' );
		unlink( $fixture . '/site/uploads/escape' );

		// VCS deployment metadata is readable/backup eligible, with separate code trust for changes.
		mkdir( $fixture . '/site/uploads/.git', 0755 );
		mkdir( $fixture . '/site/uploads/.svn', 0755 );
		file_put_contents( $fixture . '/site/uploads/.git/config', 'fixture-vcs-config' );
		file_put_contents( $fixture . '/site/uploads/.svn/wc.db', "fixture-svn-database" );
		$git = ok( $fs->stat( 'data', '.git/config' ), 'configured Git metadata read' );
		$svn = ok( $fs->stat( 'data', '.svn/wc.db' ), 'configured SVN metadata read' );
		$git_read = ok( $fs->read_window( 'data', '.git/config', 'filesystem', $git['identity'], 0, 7 ), 'Git bytes under sensitive read' );
		check( 'fixture' === $git_read['bytes'], 'Git read is exact' );
		$inventory = array();
		$cursor = 0;
		do {
			$page = ok( $fs->iterate_entries( 'data', array(), 'backup_export', null,
				function ( $entry ) use ( &$inventory ) { $inventory[] = $entry['components']; return true; },
				array( 'cursor' => $cursor ) ), 'full root inventory includes VCS entries' );
			$cursor = $page['cursor'];
		} while ( ! $page['complete'] );
		check( in_array( array( base64_encode( '.git' ) ), $inventory, true ) &&
			in_array( array( base64_encode( '.svn' ) ), $inventory, true ), 'backup does not omit Git or SVN directories' );
		foreach ( array( '.git', '.svn' ) as $vcs ) {
			$entries = array();
			$page = ok( $fs->iterate_entries( 'data', $vcs, 'backup_export', null,
				function ( $entry ) use ( &$entries ) { $entries[] = $entry['components']; return true; }, array() ), 'full VCS child inventory' );
			check( count( $entries ) === 1, 'VCS metadata file included without filtering' );
		}
		check( \WP_AI_Bridge\Support\Filesystem_Roots::protected_path( $fixture . '/site/uploads/.git/wpai-transfer-fixture' ), 'actual Bridge artifacts remain protected inside VCS trees' );
		$git_directory = ok( $fs->stat( 'data', '.git' ), 'Git directory identity' );
		$denied = $fs->mkdir( 'data', '.git/ordinary', 'filesystem', $git_directory['identity'], 'vcs-mkdir-data-0001' );
		check( is_wp_error( $denied ) && ! is_dir( $fixture . '/site/uploads/.git/ordinary' ), 'ordinary data write cannot change Git metadata' );
		$denied = $fs->delete( 'data', '.git/config', 'filesystem',
			array( 'identity' => $git['identity'], 'sha256' => hash( 'sha256', 'fixture-vcs-config' ) ), 'vcs-delete-data-0001' );
		check( is_wp_error( $denied ) && is_file( $fixture . '/site/uploads/.git/config' ), 'ordinary destructive grant cannot delete Git configuration' );
		$vcs_operations = new \WP_AI_Bridge\Support\Filesystem_Operation( $permissions );
		$svn_directory = ok( $fs->stat( 'data', '.svn' ), 'SVN directory identity' );
		$denied = $vcs_operations->begin( 'data', '.svn', 'vcs-moved', true, $svn_directory['identity'], 'vcs-move-data-0001' );
		check( is_wp_error( $denied ) && ! file_exists( $fixture . '/site/uploads/vcs-moved' ), 'moving VCS metadata checks source code trust before creating a destination' );
		$GLOBALS['fs_config']['roots']['data']['executable'] = true;
		$GLOBALS['fs_grants']['filesystem_executable'] = false;
		check( is_wp_error( $fs->mkdir( 'data', '.git/no-code-consent', 'filesystem', $git_directory['identity'], 'vcs-mkdir-grant-0001' ) ), 'root executable flag does not replace the current code grant' );
		$GLOBALS['fs_grants']['filesystem_executable'] = true;
		$GLOBALS['fs_capabilities']['edit_plugins'] = false;
		check( is_wp_error( $fs->mkdir( 'data', '.git/no-native-authority', 'filesystem', $git_directory['identity'], 'vcs-mkdir-native-0001' ) ), 'VCS changes retain current native code capability checks' );
		unset( $GLOBALS['fs_capabilities']['edit_plugins'] );
		$created = ok( $fs->mkdir( 'data', '.git/approved', 'filesystem', $git_directory['identity'], 'vcs-mkdir-code-0001' ), 'explicit code-trusted VCS directory creation' );
		check( 'committed' === $created['outcome'], 'approved VCS creation commits' );
		$approved = ok( $fs->stat( 'data', '.git/approved' ), 'approved VCS child identity' );
		$removed = ok( $fs->delete( 'data', '.git/approved', 'filesystem', $approved['identity'], 'vcs-delete-code-0001' ), 'explicit code-trusted VCS deletion' );
		check( 'committed' === $removed['outcome'], 'approved VCS deletion commits' );
		$vcs_job = ok( $vcs_operations->begin( 'data', '.svn', 'vcs-moved', true, $svn_directory['identity'], 'vcs-move-code-0001' ), 'explicit code-trusted VCS move begins' );
		$vcs_steps = 0;
		do {
			$vcs_progress = ok( $vcs_operations->step( 'data', $vcs_job['transfer_id'], true ), 'approved VCS move progression' );
			check( ++$vcs_steps < 30, 'approved VCS move is bounded and progresses' );
		} while ( 'committed' !== $vcs_progress['outcome'] );
		check( ! file_exists( $fixture . '/site/uploads/.svn' ) &&
			'fixture-svn-database' === file_get_contents( $fixture . '/site/uploads/vcs-moved/wc.db' ), 'authorized VCS move preserves exact metadata' );
		$GLOBALS['fs_config']['roots']['data']['executable'] = false;

		$factory = function ( $purpose = 'filesystem_input' ) use ( $permissions ) {
			return \WP_AI_Bridge\Support\Private_Transfer_Session::for_administration( $permissions, $purpose,
				array( 'root_id' => 'data', 'revision' => $GLOBALS['fs_config']['revision'], 'require_network' => false ) );
		};
		$policy = ( new \WP_AI_Bridge\Support\Transfer_Settings() )->bytes();
		$store = $factory();
		$content = "opaque\0file\xff\nwith several chunks";
		$lease = ok( $store->begin( 'filesystem_input', strlen( $content ), hash( 'sha256', $content ),
			$policy['file'], $policy['chunk'], $policy['sessions'], $policy['ttl'], $policy['active'] ), 'begin inert source' );
		ok( $store->append( $lease['id'], 0, substr( $content, 0, 5 ) ), 'first append' );
		ok( $store->append( $lease['id'], 0, substr( $content, 0, 5 ) ), 'same replay' );
		check( is_wp_error( $store->append( $lease['id'], 0, 'other' ) ), 'different replay denied' );
		ok( $store->append( $lease['id'], 5, substr( $content, 5 ) ), 'remaining append' );
		ok( $store->verify( $lease['id'] ), 'source verification' );
		$attempts = 0;
		do {
			$result = ok( $store->with_administration_source( $lease['id'], function ( $source ) use ( $fs ) {
				return $fs->apply_verified_source( 'data', 'written.bin', 'filesystem', null, $source, 'write-fixture-0001' );
			} ), 'bounded verified publication' );
			check( ++$attempts < 30, 'preparation makes progress' );
		} while ( ! empty( $result['preparing'] ) );
		check( $attempts === 1 && 'committed' === $result['outcome'], 'verified resumable ingress publishes without a second full copy' );
		check( file_get_contents( $fixture . '/site/uploads/written.bin' ) === $content, 'published exact bytes' );
		$replay = ok( $fs->status( 'data', 'filesystem', 'write-fixture-0001' ), 'historical write status' );
		check( ! empty( $replay['historical'] ), 'no second publication' );
		$released = ok( $store->status( $lease['id'] ), 'released source receipt remains' );
		check( $released['payload_released'], 'completed publication releases input reservation' );
		$stat = ok( $fs->stat( 'data', 'written.bin' ), 'current source' );
		$deleted = ok( $fs->delete( 'data', 'written.bin', 'filesystem',
			array( 'identity' => $stat['identity'], 'sha256' => hash( 'sha256', $content ) ), 'delete-fixture-0001' ), 'delete exact source' );
		check( 'committed' === $deleted['outcome'] && ! file_exists( $fixture . '/site/uploads/written.bin' ), 'delete committed' );
		$deleted_again = ok( $fs->delete( 'data', 'written.bin', 'filesystem',
			array( 'identity' => $stat['identity'], 'sha256' => hash( 'sha256', $content ) ), 'delete-fixture-0001' ), 'delete replay' );
		check( ! empty( $deleted_again['historical'] ), 'delete replay historical' );

		$make_input = function ( $bytes ) use ( $factory, $policy ) {
			$input = $factory();
			$lease = ok( $input->begin( 'filesystem_input', strlen( $bytes ), hash( 'sha256', $bytes ),
				$policy['file'], $policy['chunk'], $policy['sessions'], $policy['ttl'], $policy['active'] ), 'replacement input' );
			ok( $input->append( $lease['id'], 0, $bytes ), 'replacement append' );
			ok( $input->verify( $lease['id'] ), 'replacement verify' );
			return array( $input, $lease );
		};
		file_put_contents( $fixture . '/site/uploads/replace.bin', 'old' );
		chmod( $fixture . '/site/uploads/replace.bin', 0600 );
		$old = ok( $fs->hash( 'data', 'replace.bin' ), 'replacement preimage' );
		list( $input, $replacement ) = $make_input( 'new' );
		$changed = ok( $input->with_administration_source( $replacement['id'], function ( $source ) use ( $fs, $old ) {
			return $fs->apply_verified_source( 'data', 'replace.bin', 'filesystem',
				array( 'identity' => $old['identity'], 'sha256' => $old['sha256'] ), $source, 'replace-file-0001' );
		} ), 'guarded replacement' );
		check( 'committed' === $changed['outcome'] && file_get_contents( $fixture . '/site/uploads/replace.bin' ) === 'new', 'exact replacement bytes' );
		check( 0600 === ( fileperms( $fixture . '/site/uploads/replace.bin' ) & 0777 ), 'ordinary mode preserved' );
		$old = ok( $fs->hash( 'data', 'replace.bin' ), 'race preimage' );
		list( $input, $replacement ) = $make_input( 'candidate' );
		$GLOBALS['fs_arm_hook'] = function () use ( $fixture ) {
			file_put_contents( $fixture . '/site/uploads/replace.bin', 'external' );
		};
		$raced = ok( $input->with_administration_source( $replacement['id'], function ( $source ) use ( $fs, $old ) {
			return $fs->apply_verified_source( 'data', 'replace.bin', 'filesystem',
				array( 'identity' => $old['identity'], 'sha256' => $old['sha256'] ), $source, 'replace-race-0001' );
		} ), 'competing replacement fenced' );
		check( 'rejected_no_effect' === $raced['outcome'] && file_get_contents( $fixture . '/site/uploads/replace.bin' ) === 'external', 'competing generation preserved' );
		list( $input, $replacement ) = $make_input( 'candidate' );
		$GLOBALS['fs_arm_hook'] = function () use ( $fixture ) {
			file_put_contents( $fixture . '/site/uploads/competitor.bin', 'winner' );
		};
		$raced = ok( $input->with_administration_source( $replacement['id'], function ( $source ) use ( $fs ) {
			return $fs->apply_verified_source( 'data', 'competitor.bin', 'filesystem', null, $source, 'create-race-0001' );
		} ), 'competing create fenced' );
		check( 'rejected_no_effect' === $raced['outcome'] && file_get_contents( $fixture . '/site/uploads/competitor.bin' ) === 'winner', 'exclusive publication preserves competitor' );

		$stream = $factory( 'filesystem_export' );
		$dynamic = ok( $stream->begin_administration_stream( 100 ), 'dynamic admission' );
		$first = ok( $stream->append_verified_frame( $dynamic['id'], 0, 'first', hash( 'sha256', 'first' ) ), 'dynamic first frame' );
		$again = ok( $stream->append_verified_frame( $dynamic['id'], 0, 'first', hash( 'sha256', 'first' ) ), 'dynamic exact replay' );
		check( $first['stream_chain'] === $again['stream_chain'], 'frame replay does not duplicate chain' );
		check( is_wp_error( $stream->append_verified_frame( $dynamic['id'], 1, 'bad', hash( 'sha256', 'bad' ) ) ), 'dynamic gap denied' );
		$second = ok( $stream->append_verified_frame( $dynamic['id'], 5, 'second', hash( 'sha256', 'second' ) ), 'dynamic second frame' );
		$sealed = ok( $stream->seal_administration_stream( $dynamic['id'], 11, $second['stream_chain'] ), 'dynamic seal' );
		check( 11 === $sealed['total'] && 'sha256-frame-chain-v1' === $sealed['integrity'], 'explicit chain digest algorithm' );
		$read = ok( $stream->read_administration( $dynamic['id'], 0, 11 ), 'sealed export read' );
		check( 'firstsecond' === base64_decode( $read['base64'], true ), 'dynamic bytes lossless' );
		ok( $stream->checkpoint( $dynamic['id'], array( 'cursor' => 11, 'large' => str_repeat( 'x', 90000 ) ), 0 ), 'checkpoint CAS above legacy4096 metadata' );
		$GLOBALS['fs_options'][ \WP_AI_Bridge\Support\Transfer_Settings::OPTION_NAME ] = array( 'request_kib' => 16 );
		$checkpoint = ok( $stream->read_checkpoint( $dynamic['id'] ), 'accepted checkpoint remains readable after policy reduction' );
		check( strlen( $checkpoint['state']['large'] ) === 90000, 'private checkpoint roundtrip remains exact' );
		unset( $GLOBALS['fs_options'][ \WP_AI_Bridge\Support\Transfer_Settings::OPTION_NAME ] );
		check( is_wp_error( $stream->checkpoint( $dynamic['id'], array(), 0 ) ), 'stale checkpoint denied' );

		$revoked = $factory();
		$own = ok( $revoked->begin( 'filesystem_input', 0, hash( 'sha256', '' ), $policy['file'], $policy['chunk'],
			$policy['sessions'], $policy['ttl'], $policy['active'] ), 'own cleanup allocation' );
		$GLOBALS['fs_grants']['filesystem_write'] = false;
		check( is_wp_error( $revoked->status( $own['id'] ) ), 'revocation denies data plane' );
		ok( $revoked->discard_administration_creation( $own['id'] ), 'own creation cleanup survives revocation' );
		$GLOBALS['fs_grants']['filesystem_write'] = true;
		$origin = $GLOBALS['fs_origin'];
		$GLOBALS['fs_origin']['network'] = 2;
		check( is_wp_error( $stream->status( $dynamic['id'] ) ), 'original network retained' );
		$GLOBALS['fs_origin'] = $origin;

		mkdir( $fixture . '/site/uploads/tree', 0755 );
		mkdir( $fixture . '/site/uploads/tree/empty', 0755 );
		file_put_contents( $fixture . '/site/uploads/tree/file.bin', $content );
		$tree = ok( $fs->stat( 'data', 'tree' ), 'tree source' );
		$operations = new \WP_AI_Bridge\Support\Filesystem_Operation( $permissions );
		$job = ok( $operations->begin( 'data', 'tree', 'copied', false, $tree['identity'], 'copy-directory-0001' ), 'copy begins' );
		$recovered_begin = ok( $operations->begin( 'data', 'tree', 'copied', false, $tree['identity'], 'copy-directory-0001' ), 'lost begin response recovers stable continuation' );
		check( $recovered_begin['transfer_id'] === $job['transfer_id'], 'begin does not allocate a second job' );
		$steps = 0;
		do {
			$progress = ok( $operations->step( 'data', $job['transfer_id'], false ), 'bounded directory copy step' );
			check( ++$steps < 60, 'directory copy makes progress' );
		} while ( 'committed' !== $progress['outcome'] );
		check( file_get_contents( $fixture . '/site/uploads/copied/file.bin' ) === $content &&
			is_dir( $fixture . '/site/uploads/copied/empty' ), 'directory copy preserves files and empty directories' );
		$terminal = ok( $fs->status( 'data', 'filesystem', 'copy-directory-0001' ), 'durable directory completion receipt' );
		check( 'committed' === $terminal['outcome'], 'completion survives disposable payload release' );
		$tree = ok( $fs->stat( 'data', 'tree' ), 'move source' );
		$job = ok( $operations->begin( 'data', 'tree', 'moved', true, $tree['identity'], 'move-directory-0001' ), 'move begins' );
		$steps = 0;
		$GLOBALS['fs_fail_terminal_checkpoint'] = true;
		$lost_response = false;
		do {
			$progress = $operations->step( 'data', $job['transfer_id'], true );
			if ( is_wp_error( $progress ) && 'transfer_outcome_unknown' === $progress->get_error_code() ) {
				$lost_response = true;
				$progress = $operations->step( 'data', $job['transfer_id'], true );
			}
			$progress = ok( $progress, 'bounded directory move step' );
			check( ++$steps < 60, 'directory move makes progress' );
		} while ( 'committed' !== $progress['outcome'] );
		check( $lost_response, 'termination after source deletion recovered from same immutable receipt' );
		check( ! file_exists( $fixture . '/site/uploads/tree' ) &&
			file_get_contents( $fixture . '/site/uploads/moved/file.bin' ) === $content, 'move conditionally deletes verified source tree' );

		$root_config = $GLOBALS['fs_config']['roots']['data'];
		$GLOBALS['fs_config']['roots']['data']['inode'] = -1;
		check( is_wp_error( $roots->resolve( 'data' ) ), 'saved root inode replacement denied' );
		$GLOBALS['fs_config']['roots']['data'] = $root_config;
		echo 'PASS: scoped filesystem ' . $GLOBALS['assertions'] . " assertions\n";
	} finally {
		remove_tree( $fixture );
	}
}
