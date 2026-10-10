<?php
/**
 * Private, non-web-accessible ZIP staging and explicit WordPress Core installation.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_AI_Bridge\Auth\Approved_OAuth_Clients;
use WP_Error;

/**
 * Keeps untrusted executable archives inert until an exact, separately authorized install.
 */
final class Private_Package_Store {
	const OPTION_PREFIX   = 'wpai_private_zip_v1_';
	const CLAIM_PREFIX    = 'wpai_private_zip_claim_v1_';
	const MAX_BYTES       = 67108864;
	const MAX_UNPACKED    = 268435456;
	const MAX_ENTRIES     = 4096;
	const MAX_SITE_STAGES = 24;
	const MAX_USER_STAGES = 4;
	const STAGE_TTL       = 3600;
	const RECORD_TTL      = 86400;
	const STAGE_LOCK_WAIT = 5;

	/** @var Permissions */
	private $permissions;

	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/** Check independent, default-off Bridge consents and native installer authority. */
	public function allowed( $kind ) {
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return false;
		}
		$capability = 'plugin' === $kind ? 'install_plugins' : 'install_themes';
		return $this->permissions->allowed_all(
			array(
				array( Settings::GROUP_CODE_EXTENSIONS, $capability ),
				array( Settings::GROUP_EXTERNAL_PACKAGES, $capability ),
			)
		);
	}

	/** One durable storage domain across WordPress web, cron and CLI workers. */
	private function directory() {
		return Private_Package_Storage::stage_directory();
	}

	/** Stable path composed only from a validated server-generated opaque identity. */
	private function archive_path( $directory, $id ) {
		return $directory . '/' . $id . '.zip';
	}

	/** @return array<string,mixed>|WP_Error Bounded, source-independent archive inspection. */
	public function inspect_zip( $path, $kind ) {
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) || ! is_file( $path ) || is_link( $path ) || ! class_exists( '\ZipArchive' ) ) {
			return $this->invalid_archive();
		}
		$size = filesize( $path );
		if ( false === $size || $size < 1 || $size > min( self::MAX_BYTES, max( 0, (int) wp_max_upload_size() ) ) ) {
			return $this->invalid_archive();
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return $this->invalid_archive();
		}
		try {
			$entry_count = $zip->numFiles; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive native property.
			if ( $entry_count < 1 || $entry_count > self::MAX_ENTRIES ) {
				return $this->invalid_archive();
			}
			$root        = '';
			$unpacked    = 0;
			$has_header  = false;
			$has_content = false;
			$seen_paths  = array();
			$file_paths  = array();
			$dir_paths   = array();
			foreach ( range( 0, $entry_count - 1 ) as $index ) {
				$stats = $zip->statIndex( $index );
				if ( ! is_array( $stats ) || ! isset( $stats['name'], $stats['size'], $stats['comp_size'] ) ) {
					return $this->invalid_archive();
				}
				$name = $stats['name'];
				if ( ! is_string( $name ) || '' === $name || strlen( $name ) > 512 || str_contains( $name, '\\' ) || preg_match( '/[\x00-\x1f\x7f]/', $name ) || str_starts_with( $name, '/' ) || str_contains( $name, '//' ) ) {
					return $this->invalid_archive();
				}
				$canonical = strtolower( rtrim( $name, '/' ) );
				if ( isset( $seen_paths[ $canonical ] ) ) {
					return $this->invalid_archive();
				}
				$seen_paths[ $canonical ] = true;
				$segments                 = explode( '/', trim( $name, '/' ) );
				if ( ( count( $segments ) < 2 && ! ( 1 === count( $segments ) && str_ends_with( $name, '/' ) ) ) || count( $segments ) > 20 ) {
					return $this->invalid_archive();
				}
				foreach ( $segments as $part ) {
					if ( '' === $part || '.' === $part || '..' === $part || str_contains( $part, ':' ) ) {
						return $this->invalid_archive();
					}
				}
				if ( '' === $root ) {
					$root = $segments[0];
				}
				if ( $root !== $segments[0] ) {
					return $this->invalid_archive();
				}
				$directory = str_ends_with( $name, '/' );
				// WordPress may extract ZIPs on case-insensitive filesystems. Reject
				// file/directory and implicit-parent collisions before Core sees them.
				$parent = '';
				foreach ( array_slice( $segments, 0, -1 ) as $part ) {
					$parent = '' === $parent ? strtolower( $part ) : $parent . '/' . strtolower( $part );
					if ( isset( $file_paths[ $parent ] ) ) {
						return $this->invalid_archive();
					}
					$dir_paths[ $parent ] = true;
				}
				if ( $directory ) {
					if ( isset( $file_paths[ $canonical ] ) ) {
						return $this->invalid_archive();
					}
					$dir_paths[ $canonical ] = true;
				} else {
					if ( isset( $dir_paths[ $canonical ] ) ) {
						return $this->invalid_archive();
					}
					$file_paths[ $canonical ] = true;
				}
				$original   = (int) $stats['size'];
				$compressed = (int) $stats['comp_size'];
				if ( $original < 0 || $compressed < 0 || $original > self::MAX_BYTES || ( $original > 0 && ( 0 === $compressed || $original > $compressed * 200 ) ) ) {
					return $this->invalid_archive();
				}
				$unpacked += $original;
				if ( $unpacked > self::MAX_UNPACKED ) {
					return $this->invalid_archive();
				}
				$opsys = 0;
				$attr  = 0;
				if ( ! $zip->getExternalAttributesIndex( $index, $opsys, $attr ) ) {
					return $this->invalid_archive();
				}
				if ( \ZipArchive::OPSYS_UNIX === $opsys ) {
					$type = ( (int) $attr >> 16 ) & 0170000;
					if ( 0 !== $type && 0100000 !== $type && 0040000 !== $type ) {
						return $this->invalid_archive();
					}
					if ( 0040000 === $type && ! $directory ) {
						return $this->invalid_archive();
					}
				}
				if ( $directory ) {
					continue;
				}
				if ( $original > 0 && false === $zip->getFromIndex( $index, 1 ) ) {
					return $this->invalid_archive();
				}
				if ( count( $segments ) === 2 && 'plugin' === $kind && str_ends_with( strtolower( $name ), '.php' ) ) {
					$header = $zip->getFromIndex( $index, 8192 );
					if ( is_string( $header ) && preg_match( '/^[ \t\/\*#]*Plugin Name:[ \t]*[^\s\r\n]/mi', $header ) ) {
						$has_header = true;
					}
				}
				if ( count( $segments ) === 2 && 'theme' === $kind && 'style.css' === strtolower( $segments[1] ) ) {
					$header = $zip->getFromIndex( $index, 8192 );
					if ( is_string( $header ) && preg_match( '/^[ \t\/\*#]*Theme Name:[ \t]*[^\s\r\n]/mi', $header ) ) {
						$has_header = true;
					}
				}
				if ( 'theme' === $kind && ( $root . '/index.php' === $name || $root . '/templates/index.html' === $name || $root . '/block-templates/index.html' === $name ) ) {
					$has_content = true;
				}
			}
			if ( ! $has_header || ( 'theme' === $kind && ! $has_content ) ) {
				return $this->invalid_archive();
			}
			return array(
				'root'           => $root,
				'unpacked_bytes' => $unpacked,
				'entries'        => $entry_count,
			);
		} finally {
			$zip->close();
		}
	}

	private function invalid_archive() {
		return new WP_Error( 'private_package_invalid_archive', __( 'The ZIP package failed bounded structural review.', 'wp-ai-bridge' ) );
	}

	/** Serialize staging/quota reservation across web workers and application servers. */
	private function stage_lock_name() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! isset( $wpdb->prefix ) ) {
			return '';
		}
		return 'wpai_zip_' . substr( hash( 'sha256', (string) $wpdb->prefix . '|' . (string) get_current_blog_id() ), 0, 40 );
	}

	/** @return bool Only a database-owned advisory lock is acceptable; never assume a process-local lock is global. */
	private function acquire_stage_lock( $name ) {
		global $wpdb;
		if ( '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		$result = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed MySQL/MariaDB advisory lock serializes staging quota across concurrent workers.
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::STAGE_LOCK_WAIT ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fully prepared fixed SQL with no dynamic identifiers.
		);
		return 1 === (int) $result;
	}

	/** Fail closed if the WordPress DB connection changed after acquiring the quota lock. */
	private function stage_lock_is_owned( $name ) {
		global $wpdb;
		if ( '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		$owner   = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read fixed MySQL lock ownership only.
			$wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $name ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Prepared named lock lookup.
		);
		$current = $wpdb->get_var( 'SELECT CONNECTION_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fixed connection identity query.
		return (int) $owner > 0 && (int) $owner === (int) $current;
	}

	/** Release the exact request-owned lock, even after staging validation failures. */
	private function release_stage_lock( $name ) {
		global $wpdb;
		if ( '' === $name || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed advisory lock release in finally.
			$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Fully prepared fixed SQL with no dynamic identifiers.
		);
	}

	/** @return array<string,mixed>|WP_Error A real PHP multipart upload, never a caller-supplied local path. */
	public function stage_browser_upload( $upload, $kind, $client_id ) {
		if ( ! $this->allowed( $kind ) || ! ( new Approved_OAuth_Clients() )->is_approved( $client_id ) ) {
			return new WP_Error( 'private_package_permission_denied', __( 'Private package authorization is not available.', 'wp-ai-bridge' ) );
		}
		if ( ! is_array( $upload ) || ! isset( $upload['error'], $upload['tmp_name'], $upload['size'], $upload['name'] ) ||
			UPLOAD_ERR_OK !== (int) $upload['error'] || ! is_string( $upload['tmp_name'] ) ||
			! is_string( $upload['name'] ) || ! is_numeric( $upload['size'] ) ||
			(int) $upload['size'] < 1 || (int) $upload['size'] > min( self::MAX_BYTES, max( 0, (int) wp_max_upload_size() ) ) ||
			! is_uploaded_file( $upload['tmp_name'] ) ) {
			return new WP_Error( 'private_package_invalid_upload', __( 'The private ZIP upload was incomplete or exceeded the size limit.', 'wp-ai-bridge' ) );
		}
		// Deactivation/uninstall retires the same private bytes. Stage under the
		// native mutation guard as well, so a concurrent deactivation cannot
		// remove an in-flight ZIP and leave a post-deactivation orphan.
		$install_guard = new Extension_Install_Lock();
		if ( ! $install_guard->acquire() ) {
			return new WP_Error( 'private_package_busy', __( 'Private package staging is temporarily unavailable; retry after the current upload finishes.', 'wp-ai-bridge' ) );
		}
		try {
			$lock_name = $this->stage_lock_name();
			if ( ! $this->acquire_stage_lock( $lock_name ) ) {
				return new WP_Error( 'private_package_busy', __( 'Private package staging is temporarily unavailable; retry after the current upload finishes.', 'wp-ai-bridge' ) );
			}
			try {
				$count = $this->stage_counts();
				if ( $count['site'] >= self::MAX_SITE_STAGES || $count['user'] >= self::MAX_USER_STAGES ) {
					return new WP_Error( 'private_package_quota', __( 'The private package staging quota has been reached.', 'wp-ai-bridge' ) );
				}
				$directory = $this->directory();
				if ( is_wp_error( $directory ) ) {
					return $directory;
				}
				$id   = bin2hex( random_bytes( 24 ) );
				$path = $this->archive_path( $directory, $id );
				if ( ! move_uploaded_file( $upload['tmp_name'], $path ) ) {
					return new WP_Error( 'private_package_invalid_upload', __( 'The private ZIP upload was incomplete or exceeded the size limit.', 'wp-ai-bridge' ) );
				}
				// A successful move is not enough: the filesystem must enforce private
				// mode, even when the PHP upload's original mode was more permissive.
				if ( ! chmod( $path, 0600 ) ) {
					wp_delete_file( $path );
					return new WP_Error( 'private_package_storage_unavailable', __( 'Private package storage is unavailable.', 'wp-ai-bridge' ) );
				}
				clearstatcache( true, $path );
				if ( is_link( $path ) || 0 !== ( (int) fileperms( $path ) & 0077 ) ) {
					wp_delete_file( $path );
					return new WP_Error( 'private_package_storage_unavailable', __( 'Private package storage is unavailable.', 'wp-ai-bridge' ) );
				}
				$inspection = $this->inspect_zip( $path, $kind );
				if ( is_wp_error( $inspection ) || ! $this->allowed( $kind ) || ! ( new Approved_OAuth_Clients() )->is_approved( $client_id ) ) {
					wp_delete_file( $path );
					return is_wp_error( $inspection ) ? $inspection : new WP_Error( 'private_package_permission_denied', __( 'Private package authorization is not available.', 'wp-ai-bridge' ) );
				}
				$sha = hash_file( 'sha256', $path );
				if ( ! is_string( $sha ) || (int) filesize( $path ) !== (int) $upload['size'] ) {
					wp_delete_file( $path );
					return $this->invalid_archive();
				}
				$meta = array(
					'id'              => $id,
					'kind'            => $kind,
					'user_id'         => get_current_user_id(),
					'blog_id'         => get_current_blog_id(),
					'client_id'       => $client_id,
					'client_revision' => ( new Approved_OAuth_Clients() )->artifact_revision( $client_id ),
					'filename'        => substr( sanitize_file_name( $upload['name'] ), 0, 120 ),
					'sha256'          => $sha,
					'bytes'           => (int) $upload['size'],
					'root'            => $inspection['root'],
					'created'         => time(),
					'expires'         => time() + self::STAGE_TTL,
					'status'          => 'staged',
				);
				if ( ! $this->stage_lock_is_owned( $lock_name ) || ! $install_guard->is_owned() ) {
					wp_delete_file( $path );
					return new WP_Error( 'private_package_busy', __( 'Private package staging is temporarily unavailable; retry after the current upload finishes.', 'wp-ai-bridge' ) );
				}
				if ( ! add_option( self::OPTION_PREFIX . $id, $meta, '', false ) ) {
					wp_delete_file( $path );
					return new WP_Error( 'private_package_storage_unavailable', __( 'Private package storage is unavailable.', 'wp-ai-bridge' ) );
				}
				return $this->public_meta( $meta );
			} finally {
				$this->release_stage_lock( $lock_name );
			}
		} finally {
			$install_guard->release();
		}
	}

	/** @return array<string,mixed>|WP_Error Exact identity and approved-client binding. */
	private function load( $id, $client_id ) {
		if ( ! is_string( $id ) || ! preg_match( '/^[0-9a-f]{48}$/D', $id ) ) {
			return new WP_Error( 'private_package_unavailable', __( 'The requested private package is unavailable.', 'wp-ai-bridge' ) );
		}
		$meta = get_option( self::OPTION_PREFIX . $id, false );
		if ( ! is_array( $meta ) || ( $meta['id'] ?? null ) !== $id ||
			(int) ( $meta['user_id'] ?? 0 ) !== get_current_user_id() ||
			(int) ( $meta['blog_id'] ?? 0 ) !== get_current_blog_id() ||
			( $meta['client_id'] ?? null ) !== $client_id ||
			! ( new Approved_OAuth_Clients() )->artifact_is_current( $client_id, $meta['client_revision'] ?? -1 ) ) {
			return new WP_Error( 'private_package_unavailable', __( 'The requested private package is unavailable.', 'wp-ai-bridge' ) );
		}
		return $meta;
	}

	/** Metadata only; no local paths, uploaded bytes or provider secrets. */
	private function public_meta( $meta ) {
		$status = $meta['status'];
		if ( 'staged' === $status && get_option( self::CLAIM_PREFIX . $meta['id'], false ) ) {
			$status = 'outcome_unknown';
		}
		return array(
			'artifact_id'      => $meta['id'],
			'kind'             => $meta['kind'],
			'filename'         => $meta['filename'],
			'sha256'           => $meta['sha256'],
			'bytes'            => $meta['bytes'],
			'package_root'     => $meta['root'],
			'expires'          => gmdate( 'c', (int) $meta['expires'] ),
			'status'           => time() >= (int) $meta['expires'] && 'staged' === $status ? 'expired' : $status,
			'cleanup_required' => ! empty( $meta['cleanup_required'] ),
		);
	}

	/** @return array<string,mixed>|WP_Error */
	public function inspect( $id, $client_id ) {
		$meta = $this->load( $id, $client_id );
		if ( is_wp_error( $meta ) || ! $this->allowed( is_array( $meta ) ? $meta['kind'] : '' ) ) {
			return new WP_Error( 'private_package_unavailable', __( 'The requested private package is unavailable.', 'wp-ai-bridge' ) );
		}
		return $this->public_meta( $meta );
	}

	/** Admin-session inspection: user/site only, never returns another user's staged artifact. */
	public function inspect_for_browser( $id ) {
		if ( ! is_string( $id ) || ! preg_match( '/^[0-9a-f]{48}$/D', $id ) ) {
			return new WP_Error( 'private_package_unavailable', __( 'The requested private package is unavailable.', 'wp-ai-bridge' ) );
		}
		$meta = get_option( self::OPTION_PREFIX . $id, false );
		if ( ! is_array( $meta ) || ( $meta['id'] ?? null ) !== $id ||
			(int) ( $meta['user_id'] ?? 0 ) !== get_current_user_id() ||
			(int) ( $meta['blog_id'] ?? 0 ) !== get_current_blog_id() ||
			! $this->allowed( $meta['kind'] ?? '' ) ) {
			return new WP_Error( 'private_package_unavailable', __( 'The requested private package is unavailable.', 'wp-ai-bridge' ) );
		}
		return $this->public_meta( $meta );
	}

	/** @return array<int,array<string,mixed>> */
	public function list_for_client( $client_id ) {
		$result = array();
		foreach ( $this->option_names( 64 ) as $option_name ) {
			$meta = get_option( $option_name, false );
			if ( ! is_array( $meta ) || ( $meta['client_id'] ?? null ) !== $client_id || ! isset( $meta['id'] ) ) {
				continue;
			}
			$entry = $this->load( $meta['id'], $client_id );
			if ( is_wp_error( $entry ) || ! $this->allowed( $entry['kind'] ) || 'staged' !== $entry['status'] || time() >= (int) $entry['expires'] ) {
				continue;
			}
			$result[] = $this->public_meta( $entry );
			if ( count( $result ) >= 20 ) {
				break;
			}
		}
		return $result;
	}

	/** @return array<string,int> */
	private function stage_counts() {
		$site  = 0;
		$user  = 0;
		$names = $this->option_names( 101 );
		if ( ! is_array( $names ) || count( $names ) > 100 ) {
			// Never grant extra quota because an old page of metadata hid
			// still-live entries; scheduled cleanup must first reduce backlog.
			return array(
				'site' => self::MAX_SITE_STAGES,
				'user' => self::MAX_USER_STAGES,
			);
		}
		foreach ( $names as $name ) {
			$meta = get_option( $name, false );
			if ( ! is_array( $meta ) || 'staged' !== ( $meta['status'] ?? '' ) || (int) ( $meta['expires'] ?? 0 ) <= time() ) {
				continue;
			}
			++$site;
			if ( (int) ( $meta['user_id'] ?? 0 ) === get_current_user_id() ) {
				++$user;
			}
		}
		return array(
			'site' => $site,
			'user' => $user,
		);
	}

	/** @return array<int,string> Bounded indexed option-name retrieval for the current blog. */
	private function option_names( $limit ) {
		global $wpdb;
		$pattern = $wpdb->esc_like( self::OPTION_PREFIX ) . '%';
		return $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded private lifecycle index only; no user-supplied SQL.
			$wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT %d", $pattern, (int) $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted WordPress options table identifier; values prepared.
		);
	}

	/**
	 * Persist a material transition and verify the exact bounded option state.
	 * A false WordPress update_option() result is not proof of durability.
	 *
	 * @param string $id Artifact ID.
	 * @param array  $meta Fixed Bridge-owned metadata only.
	 * @return bool
	 */
	private function persist_install_state( $id, $meta ) {
		$option = self::OPTION_PREFIX . $id;
		return true === update_option( $option, $meta, false ) && get_option( $option, false ) === $meta;
	}

	/** @return array<string,mixed>|WP_Error Claim once atomically; never guess after native Core effects. */
	public function install( $id, $expected_sha, $kind, $client_id ) {
		$meta = $this->load( $id, $client_id );
		if ( is_wp_error( $meta ) || ( $meta['kind'] ?? null ) !== $kind || ! $this->allowed( $kind ) ) {
			return new WP_Error( 'private_package_permission_denied', __( 'Private package authorization is not available.', 'wp-ai-bridge' ) );
		}
		if ( ! is_string( $expected_sha ) || ! hash_equals( (string) $meta['sha256'], $expected_sha ) ) {
			return new WP_Error( 'private_package_hash_mismatch', __( 'Private package integrity confirmation did not match.', 'wp-ai-bridge' ) );
		}
		$lock = new Extension_Install_Lock();
		if ( ! $lock->acquire() ) {
			return new WP_Error( 'private_package_install_busy', __( 'Another extension installation is in progress. Retry this request after it finishes.', 'wp-ai-bridge' ) );
		}
		try {
			// Values and grants may change while another worker owns the installation lock.
			$meta = $this->load( $id, $client_id );
			if ( is_wp_error( $meta ) || ( $meta['kind'] ?? null ) !== $kind || ! $this->allowed( $kind ) ||
				! hash_equals( (string) ( $meta['sha256'] ?? '' ), $expected_sha ) ) {
				return new WP_Error( 'private_package_permission_denied', __( 'Private package authorization is not available.', 'wp-ai-bridge' ) );
			}
			if ( 'staged' !== $meta['status'] || time() >= (int) $meta['expires'] ) {
				return new WP_Error( 'private_package_not_staged', __( 'This private package is expired or has already been submitted for installation.', 'wp-ai-bridge' ) );
			}
			$directory = $this->directory();
			if ( is_wp_error( $directory ) ) {
				return $directory;
			}
			$path = $this->archive_path( $directory, $id );
			$hash = is_file( $path ) && ! is_link( $path ) ? hash_file( 'sha256', $path ) : false;
			if ( ! is_string( $hash ) || ! hash_equals( $meta['sha256'], $hash ) || (int) filesize( $path ) !== (int) $meta['bytes'] ) {
				return new WP_Error( 'private_package_hash_mismatch', __( 'Private package integrity confirmation did not match.', 'wp-ai-bridge' ) );
			}
			$checked = $this->inspect_zip( $path, $kind );
			if ( is_wp_error( $checked ) || ( $checked['root'] ?? null ) !== $meta['root'] ) {
				return $this->invalid_archive();
			}
			// A unique option INSERT is the one-way, per-blog compare-and-claim gate.
			// Claims survive process failure; never blindly retry a possible Core install.
			if ( ! $lock->is_owned() || ! $this->allowed( $kind ) || ! ( new Approved_OAuth_Clients() )->artifact_is_current( $client_id, $meta['client_revision'] ) ||
				! add_option( self::CLAIM_PREFIX . $id, time(), '', false ) ) {
				return new WP_Error( 'private_package_not_staged', __( 'This private package is expired or has already been submitted for installation.', 'wp-ai-bridge' ) );
			}
			$meta['status'] = 'installing';
			if ( ! $this->persist_install_state( $id, $meta ) ) {
				// No Core execution after an uncommitted installing transition.
				// Verify byte retirement, then persist an explicit recovery
				// indicator if the filesystem refuses deletion.
				if ( is_file( $path ) && ! is_link( $path ) ) {
					wp_delete_file( $path );
				}
				clearstatcache( true, $path );
				$meta['status']           = 'outcome_unknown';
				$meta['cleanup_required'] = is_file( $path ) || is_link( $path );
				$this->persist_install_state( $id, $meta );
				try {
					( new Mutation_Log() )->record( 'wp-ai-bridge/private-package-install', $kind, 0, false, 'private_package_recovery_required' );
				} catch ( \Throwable $error ) {
					// No untrusted paths or Core internals escape this boundary.
				}
				return new WP_Error( 'private_package_recovery_required', __( 'Private package installation needs administrator recovery before any retry.', 'wp-ai-bridge' ) );
			}
			$target  = '';
			$success = false;
			try {
				if ( ! $this->allowed( $kind ) || ! ( new Approved_OAuth_Clients() )->artifact_is_current( $client_id, $meta['client_revision'] ) ) {
					throw new \RuntimeException( 'Authorization changed after claim.' );
				}
				require_once ABSPATH . 'wp-admin/includes/file.php';
				require_once ABSPATH . 'wp-admin/includes/plugin.php';
				require_once ABSPATH . 'wp-admin/includes/theme.php';
				require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
				$skin = new \Automatic_Upgrader_Skin();
				if ( 'plugin' === $kind ) {
					$upgrader = new \Plugin_Upgrader( $skin );
					$ok       = $upgrader->install( $path );
					$target   = (string) $upgrader->plugin_info();
					$success  = true === $ok && '' !== $target && isset( get_plugins()[ $target ] );
				} else {
					$upgrader = new \Theme_Upgrader( $skin );
					$ok       = $upgrader->install( $path );
					$target   = $meta['root'];
					$success  = true === $ok && wp_get_theme( $target )->exists();
				}
			} catch ( \Throwable $error ) {
				$success = false;
			}
			// After handing bytes to Core, ambiguous failure is a recovery state.
			if ( is_file( $path ) ) {
				wp_delete_file( $path );
			}
			$deleted        = ! is_file( $path );
			$success        = $success && $lock->is_owned();
			$meta['status'] = $success && $deleted ? 'installed' : 'outcome_unknown';
			$meta['target'] = $success && $deleted ? $target : '';
			$persisted      = $this->persist_install_state( $id, $meta );
			if ( ! $persisted ) {
				// Even if Core completed, uncommitted outcome state must not be
				// represented as a successful completed installation.
				$meta['status'] = 'outcome_unknown';
				$meta['target'] = '';
				$this->persist_install_state( $id, $meta );
			}
			if ( ! $success || ! $deleted || ! $persisted ) {
				try {
					( new Mutation_Log() )->record( 'wp-ai-bridge/private-package-install', $kind, 0, false, 'private_package_recovery_required' );
				} catch ( \Throwable $error ) {
					// Never disclose a private archive or Core diagnostics while reporting recovery.
				}
				return new WP_Error( 'private_package_recovery_required', __( 'Private package installation needs administrator recovery before any retry.', 'wp-ai-bridge' ) );
			}
			try {
				if ( ! ( new Mutation_Log() )->record( 'wp-ai-bridge/private-package-install', $kind, 0, true, '' ) ) {
					// Native Core already committed. Preserve one-way claim and a
					// recovery-visible outcome when the audit did not persist.
					$meta['status'] = 'outcome_unknown';
					$this->persist_install_state( $id, $meta );
					return new WP_Error( 'private_package_recovery_required', __( 'Private package installation needs administrator recovery before any retry.', 'wp-ai-bridge' ) );
				}
			} catch ( \Throwable $error ) {
				// Once Core has committed, audit failure cannot safely be retried.
				return new WP_Error( 'private_package_recovery_required', __( 'Private package installation needs administrator recovery before any retry.', 'wp-ai-bridge' ) );
			}
			return array(
				'artifact_id' => $id,
				'kind'        => $kind,
				'target'      => $target,
				'installed'   => true,
				'activated'   => false,
			);
		} finally {
			$lock->release();
		}
	}

	/** Dispose only expired, Bridge-owned metadata and private files. */
	public function cleanup_expired() {
		// Cron retention must not delete bytes or metadata while Core, staging,
		// or deactivation holds the PHP-lifetime filesystem coordinator.
		$lock = new Extension_Install_Lock();
		if ( ! $lock->acquire() ) {
			return;
		}
		try {
			if ( ! $lock->is_owned() ) {
				return;
			}
			$directory = $this->directory();
			if ( is_wp_error( $directory ) ) {
				return;
			}
			foreach ( $this->option_names( 64 ) as $name ) {
				$meta = get_option( $name, false );
				if ( ! is_array( $meta ) || ! isset( $meta['id'], $meta['expires'] ) || ! preg_match( '/^[0-9a-f]{48}$/D', $meta['id'] ) ) {
					continue;
				}
				$claimed = false !== get_option( self::CLAIM_PREFIX . $meta['id'], false );
				$expired = ! $claimed && 'staged' === ( $meta['status'] ?? null ) && time() > (int) $meta['expires'];
				$old     = time() > (int) $meta['expires'] + self::RECORD_TTL;
				$path    = $this->archive_path( $directory, $meta['id'] );
				// An explicitly flagged unretired ZIP must not wait for staged
				// expiry: it is executable byte state whose claim is already final.
				if ( $claimed && ! empty( $meta['cleanup_required'] ) ) {
					if ( is_file( $path ) && ! is_link( $path ) ) {
						wp_delete_file( $path );
					}
					clearstatcache( true, $path );
					if ( ! is_file( $path ) && ! is_link( $path ) ) {
						$meta['cleanup_required'] = false;
						$this->persist_install_state( $meta['id'], $meta );
					}
					continue;
				}
				if ( ! $expired && ! $old ) {
					continue;
				}
				if ( is_file( $path ) && ! is_link( $path ) ) {
					wp_delete_file( $path );
				}
				if ( is_file( $path ) ) {
					continue;
				}
				if ( $old ) {
					delete_option( $name );
					delete_option( self::CLAIM_PREFIX . $meta['id'] );
				}
			}
			// Aborted PHP requests before DB registration can leave an inert ZIP.
			$files = glob( $directory . '/*.zip' );
			foreach ( array_slice( is_array( $files ) ? $files : array(), 0, 64 ) as $path ) {
				$basename = basename( $path, '.zip' );
				if ( preg_match( '/^[0-9a-f]{48}$/D', $basename ) && ! is_link( $path ) &&
					! get_option( self::OPTION_PREFIX . $basename, false ) &&
					time() - (int) filemtime( $path ) > self::STAGE_TTL ) {
					wp_delete_file( $path );
				}
			}
		} finally {
			$lock->release();
		}
	}
}
