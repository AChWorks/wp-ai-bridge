<?php
/**
 * Explicit, local-only activation of the native-account SQL server policy.
 *
 * This is not an Ability, a settings option, or a general PHP/file editor.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Admin;

/** Handles one fixed, administrator-requested wp-config.php change. */
final class Native_SQL_Config_Setup {
	const ACTION     = 'wpai_native_sql_config_setup';
	const CONSTANT   = 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL';
	const DEFINITION = "define( 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_SQL', true );";
	const MAX_BYTES  = 4194304;

	/** @return string Principal/site/network-bound nonce action. */
	public function nonce_action() {
		return self::ACTION . '-' . get_current_user_id() . '-' . get_current_blog_id() . '-' . get_current_network_id();
	}

	/**
	 * Core loads ABSPATH/wp-config.php first, or its parent when the parent
	 * does not contain wp-settings.php. An included file proves actual use;
	 * a plausible path alone cannot prove which configuration was executed.
	 *
	 * @return array{path:string,supported:bool,reason:string}
	 */
	private function locate() {
		$root   = rtrim( ABSPATH, '/\\' );
		$local  = $root . '/wp-config.php';
		$parent = dirname( $root ) . '/wp-config.php';
		$paths  = array();
		foreach ( get_included_files() as $included ) {
			$real = realpath( $included );
			if ( $real && 'wp-config.php' === basename( $real ) ) {
				$paths[ $real ] = true;
			}
		}
		if ( 1 !== count( $paths ) ) {
			return array(
				'path'      => '',
				'supported' => false,
				'reason'    => __( 'The active wp-config.php could not be identified uniquely from the current PHP bootstrap. No guessed path will be edited.', 'wp-ai-bridge' ),
			);
		}
		$path    = array_key_first( $paths );
		$in_root = realpath( $local );
		$in_up   = realpath( $parent );
		$normal  = $in_root && $path === $in_root && ! is_link( $local );
		$above   = ! $in_root && $in_up && $path === $in_up && ! is_link( $parent ) && ! file_exists( dirname( $root ) . '/wp-settings.php' );
		if ( ! $normal && ! $above ) {
			return array(
				'path'      => $path,
				'supported' => false,
				'reason'    => __( 'The loaded configuration uses a custom, linked or ambiguous bootstrap layout. Automatic editing is not supported for this layout.', 'wp-ai-bridge' ),
			);
		}
		return array(
			'path'      => $path,
			'supported' => true,
			'reason'    => '',
		);
	}

	/** @return string Empty when currently authorized, otherwise the precise denial. */
	public function authority_issue() {
		$actor   = get_current_user_id();
		$site    = get_current_blog_id();
		$network = get_current_network_id();
		if ( ! current_user_can( 'manage_options' ) ) {
			return __( 'Current WordPress user lacks manage_options; only an authorized administrator may request this setup.', 'wp-ai-bridge' );
		}
		if ( is_multisite() && ( ! is_super_admin() || ! current_user_can( 'manage_network_options' ) ) ) {
			return __( 'This file is shared by the network. Actual Super Admin and manage_network_options authority are required.', 'wp-ai-bridge' );
		}
		if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
			return __( 'DISALLOW_FILE_EDIT is enabled by hosting policy. Edit the file through an authorized hosting channel instead.', 'wp-ai-bridge' );
		}
		if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
			return __( 'DISALLOW_FILE_MODS is enabled; WordPress forbids programmatic file changes on this host.', 'wp-ai-bridge' );
		}
		if ( defined( 'FS_METHOD' ) && 'direct' !== FS_METHOD ) {
			return __( 'FS_METHOD explicitly selects a non-direct WordPress filesystem backend. This setup does not bypass that hosting choice or request filesystem credentials.', 'wp-ai-bridge' );
		}
		if ( ! current_user_can( 'edit_files' ) ) {
			return __( 'WordPress denied the native edit_files capability for the current administrator.', 'wp-ai-bridge' );
		}
		if ( ! function_exists( 'wp_is_file_mod_allowed' ) || ! wp_is_file_mod_allowed( 'wpai_native_sql_config_setup' ) ) {
			return __( 'WordPress file-modification policy denied this operation (DISALLOW_FILE_MODS or a policy filter).', 'wp-ai-bridge' );
		}
		if ( get_current_user_id() !== $actor || get_current_blog_id() !== $site || get_current_network_id() !== $network ) {
			return __( 'The active WordPress user, site or network changed during native permission evaluation. No configuration edit is allowed.', 'wp-ai-bridge' );
		}
		return '';
	}

	/** @return bool Whether the current WordPress user may modify executable site configuration. */
	public function can_modify() {
		return '' === $this->authority_issue();
	}

	/** @param string $path Active known path. @return string|false */
	private function read( $path ) {
		clearstatcache( true, $path );
		if ( ! is_file( $path ) || ! is_readable( $path ) || is_link( $path ) ) {
			return false;
		}
		$size = filesize( $path );
		if ( false === $size || $size > self::MAX_BYTES ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- This is a bounded local config read, never an HTTP fetch.
		return @file_get_contents( $path );
	}

	/**
	 * An intentionally narrow, token-checked edit. Never execute source for
	 * parsing, replace an explicit false, or guess at dynamic/external policy.
	 *
	 * @param string $source Current complete bytes.
	 * @return array{state:string,reason:string,line:int,candidate?:string}
	 */
	public function analyze( $source ) {
		$no = static function ( $state, $reason, $line = 0 ) {
			return array(
				'state'  => $state,
				'reason' => $reason,
				'line'   => $line,
			);
		};
		if ( '' === $source || strlen( $source ) > self::MAX_BYTES ) {
			return $no( 'unsupported', __( 'The configuration file is empty or exceeds the bounded safe-edit size.', 'wp-ai-bridge' ) );
		}
		try {
			$tokens = token_get_all( $source, TOKEN_PARSE );
		} catch ( \ParseError $error ) {
			return $no( 'unsupported', __( 'The configuration cannot be parsed safely as PHP. No file was changed.', 'wp-ai-bridge' ) );
		}
		if ( ! isset( $tokens[0] ) || ! is_array( $tokens[0] ) || T_OPEN_TAG !== $tokens[0][0] ) {
			return $no( 'unsupported', __( 'The configuration is not a single ordinary PHP block; manual inspection is required.', 'wp-ai-bridge' ) );
		}
		$occurrences = array();
		$require_at  = array();
		$previous    = null;
		$offset      = 0;
		$depth       = 0;
		foreach ( $tokens as $index => $token ) {
			$value = is_array( $token ) ? $token[1] : $token;
			$id    = is_array( $token ) ? $token[0] : 0;
			if ( T_CLOSE_TAG === $id || T_INLINE_HTML === $id || ( T_OPEN_TAG === $id && 0 !== $offset ) ) {
				return $no( 'unsupported', __( 'Multiple PHP/HTML blocks or a PHP closing tag prevent a safe automatic insertion.', 'wp-ai-bridge' ) );
			}
			if ( T_CONSTANT_ENCAPSED_STRING === $id && false !== strpos( $value, self::CONSTANT ) ) {
				$occurrences[] = array(
					'offset' => $offset,
					'line'   => $token[2],
				);
			} elseif ( T_STRING === $id && self::CONSTANT === $value ) {
				$occurrences[] = array(
					'offset' => $offset,
					'line'   => $token[2],
				);
			}
			if ( T_CONSTANT_ENCAPSED_STRING === $id ) {
				// Callback-based APIs such as array_map() can invoke define()
				// without an ordinary function-call token. Rather than maintain
				// an incomplete list of callback consumers, reject a literal
				// naming any qualified policy-definition callable anywhere in
				// the executable PHP source, including dormant code branches.
				$literal_callable = substr( $value, 1, -1 );
				$namespace_at     = strrpos( $literal_callable, '\\' );
				if ( false !== $namespace_at ) {
					$literal_callable = substr( $literal_callable, $namespace_at + 1 );
				}
				if ( in_array( strtolower( $literal_callable ), array( 'define', 'defined' ), true ) ) {
					return $no( 'complex', __( 'An indirect function invocation prevents proving that this SQL constant is not set elsewhere.', 'wp-ai-bridge' ) );
				}
				// A computed constant name may be split across string tokens.
				// Recognize its distinctive policy prefix even without the
				// complete final symbol. This is deliberately fail-closed.
				if ( false !== strpos( $value, 'WP_AI_BRIDGE_ALLOW_NATIVE_ACCOUNT_' ) &&
					false === strpos( $value, self::CONSTANT ) ) {
					return $no( 'complex', __( 'The constant is defined or referenced dynamically. Locate its real definition manually; no duplicate was added.', 'wp-ai-bridge' ) );
				}
			}
			if ( T_EVAL === $id ) {
				return $no( 'complex', __( 'The configuration evaluates dynamically generated PHP. This server-policy source cannot be proven safe for automatic insertion.', 'wp-ai-bridge' ) );
			}
			if ( in_array( $id, array( T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE ), true ) ) {
				if ( 0 !== $depth ) {
					return $no( 'unsupported', __( 'The configuration contains conditional or nested includes. Automatic insertion cannot prove the policy source.', 'wp-ai-bridge' ) );
				}
				$require_at[] = array(
					'offset'   => $offset,
					'previous' => $previous,
				);
			}
			// PHP 8 tokenizes \define() and namespaced calls differently
			// from bare T_STRING. A qualified definition must not escape the
			// policy-source check solely because of its token representation.
			$function_name = '';
			if ( in_array( $id, array( T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE ), true ) ) {
				$parts         = explode( '\\', strtolower( $value ) );
				$function_name = end( $parts );
			}
			$definition_call = in_array( $function_name, array( 'define', 'defined' ), true );
			// Indirect calls can reach define() without any literal policy-name
			// token. Decline rather than interpreting callable values or running
			// arbitrary PHP. Skip the lookahead for comments and ordinary tokens
			// to keep parsing bounded even for long runs of PHP comments.
			$indirect_call = in_array( $function_name, array( 'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array' ), true ) ||
				T_VARIABLE === $id || T_CONSTANT_ENCAPSED_STRING === $id || ')' === $value || ']' === $value;
			if ( $definition_call || $indirect_call ) {
				$next = $this->next_significant( $tokens, $index + 1 );
				if ( '(' === $next['value'] && $definition_call ) {
					$argument = $this->next_significant( $tokens, $next['index'] + 1 );
					$after    = $this->next_significant( $tokens, $argument['index'] + 1 );
					// A computed name may resolve to SQL policy only in another
					// request. Never insert beside an unknown define/defined.
					if ( T_CONSTANT_ENCAPSED_STRING !== $argument['id'] ||
						! in_array( $after['value'], array( ',', ')' ), true ) ) {
						return $no( 'complex', __( 'A dynamic define/defined expression prevents proving that this SQL constant is not set elsewhere.', 'wp-ai-bridge' ) );
					}
				}
				if ( '(' === $next['value'] && $indirect_call ) {
					return $no( 'complex', __( 'An indirect function invocation prevents proving that this SQL constant is not set elsewhere.', 'wp-ai-bridge' ) );
				}
			}
			if ( ! in_array( $id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$previous = $value;
			}
			if ( '{' === $value ) {
				++$depth;
			} elseif ( '}' === $value ) {
				--$depth;
			}
			$offset += strlen( $value );
		}
		if ( count( $occurrences ) > 1 ) {
			return $no( 'complex', __( 'Multiple references to the native SQL constant exist. Do not append or automatically override any definition.', 'wp-ai-bridge' ), $occurrences[0]['line'] );
		}
		if ( 1 === count( $occurrences ) ) {
			$found = array();
			preg_match_all(
				'/\bdefine\s*\(\s*([\'"])' . preg_quote( self::CONSTANT, '/' ) . '\1\s*,\s*(true|false)\s*\)\s*;/i',
				$source,
				$found,
				PREG_OFFSET_CAPTURE
			);
			$matched = 1 === count( $found[0] ) && $found[0][0][1] <= $occurrences[0]['offset'] &&
				$occurrences[0]['offset'] < $found[0][0][1] + strlen( $found[0][0][0] );
			if ( ! $matched ) {
				return $no( 'complex', __( 'The constant is defined or referenced dynamically. Locate its real definition manually; no duplicate was added.', 'wp-ai-bridge' ), $occurrences[0]['line'] );
			}
			if ( 'false' === strtolower( $found[2][0][0] ) ) {
				return $no( 'false', __( 'An explicit false definition exists. Change this intentional server policy manually rather than overriding it automatically.', 'wp-ai-bridge' ), $occurrences[0]['line'] );
			}
			return $no( 'true', __( 'The configuration already contains an explicit true definition.', 'wp-ai-bridge' ), $occurrences[0]['line'] );
		}
		// Require a unique, standalone, top-level Core bootstrap statement.
		$matches = array();
		preg_match_all(
			'/^[ \t]*(?:require|require_once)\s*\(?\s*ABSPATH\s*\.\s*([\'"])wp-settings\.php\1\s*\)?\s*;/m',
			$source,
			$matches,
			PREG_OFFSET_CAPTURE
		);
		if ( 1 !== count( $matches[0] ) ) {
			return $no( 'unsupported', __( 'A unique standard WordPress bootstrap line could not be identified. Do not insert PHP at an unverified location.', 'wp-ai-bridge' ) );
		}
		$at             = $matches[0][0][1];
		$statement      = $matches[0][0][0];
		$keyword_at     = $at + strspn( $statement, " \t" );
		$prior          = $require_at[0]['previous'] ?? '';
		$after_open_tag = is_string( $prior ) && 1 === preg_match( '/^<\?php\s*$/i', $prior );
		if ( 1 !== count( $require_at ) || $keyword_at !== $require_at[0]['offset'] ||
			! in_array( $prior, array( ';', '}' ), true ) && ! $after_open_tag ) {
			return $no( 'unsupported', __( 'The bootstrap statement is conditional or nonstandard. Manual review is required before changing PHP.', 'wp-ai-bridge' ) );
		}
		$line      = substr_count( substr( $source, 0, $at ), "\n" ) + 1;
		$newline   = false !== strpos( $source, "\r\n" ) ? "\r\n" : "\n";
		$candidate = substr_replace( $source, self::DEFINITION . $newline . $newline, $at, 0 );
		try {
			token_get_all( $candidate, TOKEN_PARSE );
		} catch ( \ParseError $error ) {
			return $no( 'unsupported', __( 'The proposed PHP change could not be parsed. No file was changed.', 'wp-ai-bridge' ) );
		}
		return array(
			'state'     => 'ready',
			'reason'    => __( 'The standard configuration layout supports one minimal insertion before WordPress starts.', 'wp-ai-bridge' ),
			'line'      => $line,
			'candidate' => $candidate,
		);
	}

	/**
	 * @param array $tokens Tokenized PHP.
	 * @param int   $from   First index.
	 * @return array{id:int,index:int,value:string}
	 */
	private function next_significant( array $tokens, $from ) {
		for ( $i = $from; $i < count( $tokens ); $i++ ) {
			$item = $tokens[ $i ];
			$id   = is_array( $item ) ? $item[0] : 0;
			if ( ! in_array( $id, array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
				return array(
					'id'    => $id,
					'index' => $i,
					'value' => is_array( $item ) ? $item[1] : $item,
				);
			}
		}
		return array(
			'id'    => 0,
			'index' => count( $tokens ),
			'value' => '',
		);
	}

	/** @return array Current policy, exact file when proven, and safe manual guidance. */
	public function inspect() {
		$located = $this->locate();
		$path    = $located['path'];
		$result  = array(
			'path'      => $path,
			'supported' => $located['supported'],
			'state'     => 'unknown',
			'reason'    => $located['reason'],
			'line'      => 0,
			'digest'    => '',
			'path_hash' => hash( 'sha256', $path ),
			'can_auto'  => false,
			'enabled'   => defined( self::CONSTANT ) && true === constant( self::CONSTANT ),
		);
		if ( '' === $path ) {
			if ( $result['enabled'] ) {
				$result['state'] = 'enabled';
			}
			return $result;
		}
		$source = $this->read( $path );
		if ( false === $source ) {
			$result['reason'] = __( 'The loaded wp-config.php is missing, unreadable, not a regular file or too large for safe inspection.', 'wp-ai-bridge' );
			return $result;
		}
		$scan             = $this->analyze( $source );
		$result['state']  = $scan['state'];
		$result['reason'] = $scan['reason'];
		$result['line']   = $scan['line'];
		$result['digest'] = hash( 'sha256', $source );
		if ( ! $located['supported'] ) {
			$result['state']  = 'unsupported';
			$result['reason'] = $located['reason'];
		} elseif ( $result['enabled'] ) {
			// A true runtime value does not turn a conflicting or dynamically
			// configured file into an uncomplicated, automatically editable file.
			if ( 'true' === $result['state'] ) {
				$result['state']  = 'enabled';
				$result['reason'] = __( 'The SQL constant is exactly boolean true in this WordPress request. Database grants and native privileges remain separate.', 'wp-ai-bridge' );
			} elseif ( 'ready' === $result['state'] ) {
				$result['state']  = 'external';
				$result['reason'] = __( 'Runtime SQL policy is enabled, but the loaded wp-config.php has no explicit definition. An external source may own the policy; do not append a shadow definition.', 'wp-ai-bridge' );
			}
		} elseif ( defined( self::CONSTANT ) && 'ready' === $result['state'] ) {
			$result['state']  = 'external';
			$result['reason'] = __( 'The constant has another runtime value, apparently from an external or dynamic source. Find that definition; do not add a shadow definition.', 'wp-ai-bridge' );
		} elseif ( 'true' === $result['state'] ) {
			$result['state']  = 'pending';
			$result['reason'] = __( 'The file contains true but the current request did not load boolean true. Check the actual bootstrap and reload on a fresh request.', 'wp-ai-bridge' );
		}
		// Resolve mutable WordPress capability/hosting filters once for this
		// observation, and reject if any filter changes the principal/context.
		$permission_issue = 'ready' === $result['state'] ? $this->authority_issue() : '';
		if ( 'ready' === $result['state'] && '' !== $permission_issue ) {
			$result['reason'] = $permission_issue;
		}
		$result['can_auto'] = 'ready' === $result['state'] && '' === $permission_issue && is_writable( $path ) && is_writable( dirname( $path ) );
		if ( 'ready' === $result['state'] && '' === $permission_issue && ! $result['can_auto'] ) {
			$result['reason'] = __( 'PHP cannot write the existing configuration and its directory. File ownership or filesystem permissions prevent automatic editing.', 'wp-ai-bridge' );
		}
		return $result;
	}

	/**
	 * Same-screen, separate-form action. No SQL, grant or settings writes.
	 * The current request can never activate a newly written PHP constant.
	 *
	 * @param array $posted Unslashed POST values.
	 * @return array Result for a new-tab Settings response.
	 */
	public function activate( array $posted ) {
		nocache_headers();
		$state = $this->inspect();
		$fail  = static function ( $state, $reason ) {
			$state['result'] = 'denied';
			$state['reason'] = $reason;
			return $state;
		};
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || self::ACTION !== ( $posted['action'] ?? null ) ) {
			return $fail( $state, __( 'A separate configuration setup POST is required.', 'wp-ai-bridge' ) );
		}
		if ( ! current_user_can( 'manage_options' ) || ! $this->can_modify() ) {
			return $fail( $state, $this->authority_issue() );
		}
		if ( ! is_string( $posted['_wpnonce'] ?? null ) || ! wp_verify_nonce( $posted['_wpnonce'], $this->nonce_action() ) ) {
			return $fail( $state, __( 'Configuration setup security check failed. Reopen Settings before trying again.', 'wp-ai-bridge' ) );
		}
		foreach ( array(
			'user'    => get_current_user_id(),
			'blog'    => get_current_blog_id(),
			'network' => get_current_network_id(),
		) as $key => $value ) {
			if ( ( $posted[ $key ] ?? null ) !== (string) $value ) {
				return $fail( $state, __( 'The active WordPress user, site or network changed. Reload Settings and try again.', 'wp-ai-bridge' ) );
			}
		}
		if ( 'ready' !== $state['state'] ) {
			return $fail( $state, __( 'The current configuration is already enabled, unsupported or requires manual review. No automatic edit was attempted.', 'wp-ai-bridge' ) );
		}
		if ( ! is_string( $posted['expected_digest'] ?? null ) || ! hash_equals( $state['digest'], $posted['expected_digest'] ) ||
			! is_string( $posted['expected_path_hash'] ?? null ) || ! hash_equals( $state['path_hash'], $posted['expected_path_hash'] ) ) {
			return $fail( $state, __( 'The wp-config.php file or its active path changed since this form was opened. Reload before trying again.', 'wp-ai-bridge' ) );
		}
		if ( ! $state['can_auto'] ) {
			return $fail( $state, $state['reason'] );
		}
		$write = $this->publish( $state['path'], $state['digest'] );
		if ( true !== $write ) {
			return $fail( $this->inspect(), $write );
		}
		$after           = $this->inspect();
		$after['result'] = 'written';
		$after['reason'] = __( 'The configuration change was written and verified on disk. Open a fresh Settings request to confirm runtime activation; this request still has its original constant value.', 'wp-ai-bridge' );
		return $after;
	}

	/**
	 * Stage a complete replacement in the same filesystem and atomically
	 * publish it. The original inode is never chmod/chown'ed. Refuse layouts
	 * where staging would silently change ownership or file modes.
	 *
	 * @param string $path Expected canonical active config path.
	 * @param string $expected_hash Exact on-screen source hash.
	 * @return true|string Localized outcome.
	 */
	private function publish( $path, $expected_hash ) {
		$locked = false;
		$stage  = '';
		$lock   = null;
		$source = null;
		try {
			$lock_path = rtrim( get_temp_dir(), '/\\' ) . '/wpai-config-' . hash( 'sha256', $path ) . '.lock';
			$lock      = @fopen( $lock_path, 'c+b' );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Lock failure is checked before changing the file.
			if ( ! $lock || ! @flock( $lock, LOCK_EX | LOCK_NB ) ) {
				return __( 'Another configuration setup is running or its coordination lock is unavailable.', 'wp-ai-bridge' );
			}
			$locked = true;
			$source = @fopen( $path, 'rb' );
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- File lock failure returns a precise denial.
			if ( ! $source || ! @flock( $source, LOCK_EX | LOCK_NB ) ) {
				return __( 'The configuration file could not be locked without blocking other edits.', 'wp-ai-bridge' );
			}
			$before = fstat( $source );
			if ( ! $before || ( $before['mode'] & 0170000 ) !== 0100000 || 1 !== $before['nlink'] || ! is_writable( $path ) ) {
				return __( 'The configuration file is not a writable, single-link regular file. Automatic replacement would be unsafe.', 'wp-ai-bridge' );
			}
			$content = $this->read( $path );
			if ( false === $content || ! hash_equals( $expected_hash, hash( 'sha256', $content ) ) || ! $this->same_inode( $path, $before ) ) {
				return __( 'The configuration changed during setup. Nothing was overwritten; reload and inspect the current file.', 'wp-ai-bridge' );
			}
			$scan = $this->analyze( $content );
			if ( 'ready' !== $scan['state'] || ! $this->can_modify() ) {
				return __( 'The configuration layout or WordPress file-modification authority changed before writing. No file was replaced.', 'wp-ai-bridge' );
			}
			$replacement = $scan['candidate'];
			$stage       = dirname( $path ) . '/.wpai-config-' . bin2hex( random_bytes( 16 ) ) . '.php';
			$old_umask   = umask( 0077 );
			try {
				$staging = @fopen( $stage, 'x+b' );
			} finally {
				umask( $old_umask );
			}
			if ( ! $staging ) {
				return __( 'The server could not create a private, same-directory staging file. Use the manual instructions.', 'wp-ai-bridge' );
			}
			try {
				$length = strlen( $replacement );
				$offset = 0;
				while ( $offset < $length ) {
					// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Checked bounded writes to a private disposable stage.
					$written = @fwrite( $staging, substr( $replacement, $offset, 65536 ) );
					if ( ! is_int( $written ) || 0 === $written ) {
						return __( 'The complete staged configuration could not be written. The live configuration was left untouched.', 'wp-ai-bridge' );
					}
					$offset += $written;
				}
				if ( ! fflush( $staging ) || ( function_exists( 'fsync' ) && ! fsync( $staging ) ) ) {
					return __( 'The staged file could not be synchronized. The live configuration was left untouched.', 'wp-ai-bridge' );
				}
			} finally {
				fclose( $staging );
			}
			// Only change the disposable staging file's mode to preserve the
			// original; never elevate access to the original wp-config.php.
			$mode = $before['mode'] & 07777;
			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Only own stage is altered; errors are fatal to publication.
			if ( ! @chmod( $stage, $mode ) ) {
				return __( 'The staged file could not preserve the original file mode; no configuration was replaced.', 'wp-ai-bridge' );
			}
			clearstatcache( true, $stage );
			$staged_stat = @stat( $stage );
			if ( ! $staged_stat || $staged_stat['uid'] !== $before['uid'] || $staged_stat['gid'] !== $before['gid'] ||
				( $staged_stat['mode'] & 07777 ) !== $mode || ( $staged_stat['mode'] & 0170000 ) !== 0100000 ||
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Hash failure prevents publication.
				! hash_equals( hash( 'sha256', $replacement ), (string) @hash_file( 'sha256', $stage ) ) ) {
				return __( 'The server cannot stage a byte-exact replacement with unchanged ownership and permissions. Use manual editing.', 'wp-ai-bridge' );
			}
			// WordPress capability/file-modification filters are effectful
			// callbacks. Run them BEFORE the last source-integrity check: no
			// extension point is permitted between that check and rename().
			if ( ! $this->can_modify() ) {
				return __( 'The original configuration or authority changed before publication. The existing file was preserved.', 'wp-ai-bridge' );
			}
			clearstatcache( true, $path );
			$fresh = $this->read( $path );
			if ( false === $fresh || ! hash_equals( $expected_hash, hash( 'sha256', $fresh ) ) || ! $this->same_inode( $path, $before ) ) {
				return __( 'The original configuration or authority changed before publication. The existing file was preserved.', 'wp-ai-bridge' );
			}
			// WordPress/PHP offers no atomic conditional-rename API for
			// uncooperative external writers. The path- and inode-bound
			// preflight + advisory locks minimize that unavoidable gap.
			if ( ! @rename( $stage, $path ) ) {
				return __( 'The filesystem rejected atomic replacement. The original file was not intentionally changed.', 'wp-ai-bridge' );
			}
			$stage = '';
			if ( function_exists( 'opcache_invalidate' ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- New-request activation is verified separately.
				@opcache_invalidate( $path, true );
			}
			clearstatcache( true, $path );
			$persisted = $this->read( $path );
			$after     = @stat( $path );
			if ( false === $persisted || ! hash_equals( hash( 'sha256', $replacement ), hash( 'sha256', $persisted ) ) ||
				! $after || $after['uid'] !== $before['uid'] || $after['gid'] !== $before['gid'] || ( $after['mode'] & 07777 ) !== $mode ) {
				return __( 'The replacement result is uncertain. Inspect wp-config.php manually before retrying; do not add a second definition.', 'wp-ai-bridge' );
			}
			return true;
		} catch ( \Throwable $error ) {
			return __( 'Configuration setup was interrupted. Inspect the actual file before any retry; no success is assumed.', 'wp-ai-bridge' );
		} finally {
			if ( '' !== $stage && is_file( $stage ) ) {
				@unlink( $stage );
			}
			if ( is_resource( $source ) ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort unlock in finally.
				@flock( $source, LOCK_UN );
				fclose( $source );
			}
			if ( $locked ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Best effort unlock in finally.
				@flock( $lock, LOCK_UN );
			}
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
		}
	}

	/** @param string $path Canonical pathname. @param array $original Stat snapshot. @return bool */
	private function same_inode( $path, $original ) {
		clearstatcache( true, $path );
		$now = @stat( $path );
		return $now && $original['dev'] === $now['dev'] && $original['ino'] === $now['ino'] &&
			$original['uid'] === $now['uid'] && $original['gid'] === $now['gid'] &&
			( $original['mode'] & 07777 ) === ( $now['mode'] & 07777 );
	}
}
