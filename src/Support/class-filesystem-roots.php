<?php
/**
 * Administrator-configured, purpose-bound filesystem roots.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * Canonical paths remain internal. Portable PHP requires trusted ancestor
 * writers; these checks are not openat/O_NOFOLLOW isolation from hostile UIDs.
 */
final class Filesystem_Roots {
	private $access;

	public function __construct( Permissions $permissions ) {
		$this->access = new Administration_Access( $permissions );
	}

	public function available( $purpose = 'filesystem', $mode = 'read' ) {
		$config = $this->access->configuration();
		$result = array();
		foreach ( $config['roots'] as $id => $unused ) {
			$root = $this->resolve( $id, $purpose, $mode );
			if ( ! is_wp_error( $root ) ) {
				$result[] = array(
					'id'            => $id,
					'label'         => $root['label'],
					'scope'         => $root['scope'],
					'root_revision' => $root['revision'],
				);
			}
		}
		return $result;
	}

	public function resolve( $root_id, $purpose = 'filesystem', $mode = 'read' ) {
		$config = $this->access->configuration();
		$root   = is_string( $root_id ) ? ( $config['roots'][ $root_id ] ?? null ) : null;
		$groups = array(
			'filesystem'     => 'filesystem_read',
			'logs'           => 'sensitive_logs_read',
			'backup_export'  => 'full_backup',
			'backup_restore' => 'full_restore',
		);
		if ( ! is_array( $root ) || ! isset( $groups[ $purpose ] ) ||
			! in_array( $mode, array( 'read', 'write', 'destructive', 'executable' ), true ) ||
			! in_array( $purpose, $root['purposes'], true ) || empty( $root[ $mode ] ) ) {
			return $this->denied();
		}
		$network = 'network' === $root['scope'];
		$origin  = $this->access->context();
		if ( (int) $root['network'] !== $origin['network'] ||
			( ! $network && (int) $root['blog'] !== $origin['blog'] ) ||
			! $this->access->allowed( $groups[ $purpose ], $network ) ) {
			return $this->denied();
		}
		if ( 'filesystem' === $purpose && 'read' !== $mode ) {
			$extra = array(
				'write'       => 'filesystem_write',
				'destructive' => 'filesystem_delete',
				'executable'  => 'filesystem_executable',
			);
			if ( ! $this->access->allowed( $extra[ $mode ], $network ) ) {
				return $this->denied();
			}
		}
		if ( 'read' !== $mode && ( ! wp_is_file_mod_allowed( 'wp_ai_bridge_filesystem' ) ||
			( 'logs' === $purpose ) ) ) {
			return $this->denied();
		}
		$path = $root['path'];
		if ( ! is_string( $path ) || '' === $path || is_link( $path ) || realpath( $path ) !== $path ||
			! is_dir( $path ) || ! $this->safe_ancestors( $path ) || self::protected_path( $path ) ) {
			return new WP_Error( 'filesystem_root_unavailable', 'The configured root is unavailable or unsafe.' );
		}
		$shared       = self::within( $path, ABSPATH ) || self::within( ABSPATH, $path ) ||
			( defined( 'WP_CONTENT_DIR' ) && ( self::within( $path, WP_CONTENT_DIR ) || self::within( WP_CONTENT_DIR, $path ) ) );
		$uploads      = function_exists( 'wp_get_upload_dir' ) ? wp_get_upload_dir() : array();
		$uploads_root = isset( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		$site_data    = ! $network && empty( $root['executable'] ) && is_string( $uploads_root ) && self::within( $path, $uploads_root );
		if ( is_multisite() && ( ( $shared && ! $site_data ) || ! empty( $root['executable'] ) ) &&
			( ! is_super_admin() || ! current_user_can( 'manage_network_options' ) ) ) {
			return $this->denied();
		}
		if ( 'backup_restore' === $purpose && ( empty( $root['nonserved'] ) || self::served( $path ) ) ) {
			return new WP_Error( 'filesystem_restore_served', 'Restore requires an approved separate nonserved destination.' );
		}
		$stat = @lstat( $path );
		if ( ! is_array( $stat ) || ! isset( $root['device'], $root['inode'] ) ||
			$stat['dev'] !== $root['device'] || $stat['ino'] !== $root['inode'] ) {
			return new WP_Error( 'filesystem_root_unavailable', 'The configured root cannot be inspected.' );
		}
		$root['revision'] = $config['revision'];
		$root['origin']   = $origin;
		$root['stat']     = $stat;
		$root['id']       = $root_id;
		$root['purpose']  = $purpose;
		return $root;
	}

	/** Inspect canonical ancestry; trust is an explicit host/operator prerequisite, not inferred from mode bits. */
	private function safe_ancestors( $path ) {
		$current = $path;
		while ( true ) {
			$stat = @lstat( $current );
			if ( ! is_array( $stat ) || 0040000 !== ( $stat['mode'] & 0170000 ) || is_link( $current ) ) {
				return false;
			}
			$parent = dirname( $current );
			if ( $parent === $current ) {
				return true;
			}
			$current = $parent;
		}
	}

	public static function within( $path, $root ) {
		$root = rtrim( $root, DIRECTORY_SEPARATOR );
		return $path === $root || 0 === strpos( $path, $root . DIRECTORY_SEPARATOR );
	}

	/** Reserved private/control artifacts never become ordinary FS/log sources. */
	public static function protected_path( $path ) {
		foreach ( explode( DIRECTORY_SEPARATOR, $path ) as $part ) {
			if ( preg_match( '/^(?:wpai-transfer-|wpai-native-input-|wpai-private-artifact|wpai-source-lock-|\.ht-wpai-source-cas-|\.wpai-fs-)/D', $part ) ||
				in_array( $part, array( '.git', '.svn' ), true ) ) {
				return true;
			}
		}
		return false;
	}

	public static function served( $path ) {
		$roots = array(
			ABSPATH,
			defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR : ABSPATH,
			$_SERVER['DOCUMENT_ROOT'] ?? '',
		);
		if ( function_exists( 'wp_get_upload_dir' ) ) {
			$uploads = wp_get_upload_dir();
			$roots[] = $uploads['basedir'] ?? '';
		}
		foreach ( $roots as $root ) {
			$canonical = is_string( $root ) && '' !== $root ? realpath( $root ) : false;
			if ( is_string( $canonical ) && ( self::within( $path, $canonical ) || self::within( $canonical, $path ) ) ) {
				return true;
			}
		}
		return false;
	}

	private function denied() {
		return new WP_Error( 'filesystem_denied', 'Current administrator delegation and configured root authority are required.' );
	}
}
