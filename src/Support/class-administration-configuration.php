<?php
/**
 * Protected operator-owned scopes for advanced administration.
 *
 * @package WP_AI_Bridge
 */
namespace WP_AI_Bridge\Support;

/** Configuration is separate from delegated execution and ordinary settings. */
final class Administration_Configuration {
	const OPTION_NAME = 'wp_ai_bridge_administration_configuration';
	const PURPOSES    = array( 'filesystem', 'logs', 'backup_export', 'backup_restore' );

	/** @return void */
	public function boot() {
		add_filter( 'sanitize_option_' . self::OPTION_NAME, array( $this, 'sanitize' ) );
	}

	/** @return bool */
	public function can_configure() {
		return function_exists( 'get_current_blog_id' ) && function_exists( 'get_current_network_id' ) &&
			get_current_blog_id() > 0 && get_current_network_id() > 0 &&
			( new Settings() )->can_configure_group( Settings::GROUP_FILESYSTEM_READ );
	}

	/** @return array Protected native configuration and a content revision. */
	public function all() {
		$value  = $this->defaults();
		$stored = get_option( self::OPTION_NAME, array() );
		if ( is_array( $stored ) && 1 === ( $stored['_format'] ?? null ) &&
			( $stored['_blog_id'] ?? null ) === $value['blog'] &&
			( $stored['_network_id'] ?? null ) === $value['network'] &&
			is_array( $stored['roots'] ?? null ) && is_array( $stored['restore_schemas'] ?? null ) &&
			is_array( $stored['backup_tables'] ?? null ) &&
			is_int( $stored['step_bytes'] ?? null ) && $stored['step_bytes'] > 0 &&
			is_int( $stored['step_seconds'] ?? null ) && $stored['step_seconds'] > 0 ) {
			$valid = true;
			foreach ( $stored['roots'] as $id => $root ) {
				if ( ! $this->stored_root_valid( $id, $root, $value ) ) {
					$valid = false;
					break; }
			}
			foreach ( array( 'restore_schemas', 'backup_tables' ) as $field ) {
				foreach ( $stored[ $field ] as $name ) {
					if ( ! $this->identifier( $name ) ) {
						$valid = false;
						break; }
				}
			}
			if ( $valid ) {
				foreach ( array( 'roots', 'restore_schemas', 'backup_tables', 'step_bytes', 'step_seconds' ) as $field ) {
					$value[ $field ] = $stored[ $field ];
				}
				$value['valid'] = true;
			}
		}
		$value['revision'] = hash( 'sha256', wp_json_encode( $value ) );
		return $value;
	}

	/** @return array Finite work policies; no collection or lifetime count limit. */
	private function defaults() {
		return array(
			'blog'            => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'network'         => function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 0,
			'roots'           => array(),
			'restore_schemas' => array(),
			'backup_tables'   => array(),
			'step_bytes'      => 1024 * 1024,
			'step_seconds'    => 5,
			'valid'           => false,
		);
	}

	/**
	 * Validate the complete candidate before the native option is changed.
	 *
	 * @param mixed $input Operator input; callers cannot grant themselves roots.
	 * @return array Canonical stored value, bound to this site and network.
	 */
	public function sanitize( $input ) {
		$value = $this->validate( $input );
		if ( is_wp_error( $value ) ) {
			return $this->reject( $value->get_error_message(), $value->get_error_data()['status'] );
		}
		return $value;
	}

	/**
	 * Preflight scopes without terminating the request or changing any option.
	 *
	 * The native sanitizer retains the same validation and authorization.
	 *
	 * @param mixed $input Complete operator scope candidate.
	 * @return array|\WP_Error Canonical value or recoverable validation error.
	 */
	public function validate( $input ) {
		if ( ! $this->can_configure() ) {
			return $this->invalid( __( 'Changing administration scopes requires current administrator and network authority.', 'wp-ai-bridge' ), 403 );
		}
		$origin = $this->defaults();
		if ( ! is_array( $input ) || ! is_array( $input['roots'] ?? null ) ) {
			return $this->invalid( __( 'Administration roots must be a complete array.', 'wp-ai-bridge' ) );
		}
		$value = array(
			'_format'         => 1,
			'_blog_id'        => $origin['blog'],
			'_network_id'     => $origin['network'],
			'roots'           => array(),
			'restore_schemas' => array(),
			'backup_tables'   => array(),
			'step_bytes'      => 1024 * 1024,
			'step_seconds'    => 5,
		);
		foreach ( $input['roots'] as $entry ) {
			if ( ! is_array( $entry ) ) {
				return $this->invalid( __( 'Each configured root must be a complete record.', 'wp-ai-bridge' ) ); }
			if ( $this->flag( $entry['remove'] ?? false ) ) {
				continue; }
			$id   = $entry['id'] ?? '';
			$path = $entry['path'] ?? '';
			if ( '' === $id && '' === $path ) {
				continue; }
			if ( ! $this->root_id( $id ) || isset( $value['roots'][ $id ] ) || ! is_string( $path ) ||
				'' === $path || false !== strpos( $path, "\0" ) || strpos( $path, '://' ) !== false ) {
				return $this->invalid( __( 'Root IDs must be unique ASCII identifiers and paths must be absolute native directories.', 'wp-ai-bridge' ) );
			}
			$absolute  = '/' === $path[0] || ( strlen( $path ) > 2 && ctype_alpha( $path[0] ) && ':' === $path[1] && in_array( $path[2], array( '/', '\\' ), true ) );
			$canonical = $absolute ? realpath( $path ) : false;
			$stat      = is_string( $canonical ) ? @lstat( $canonical ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Operator-only validation returns a targeted scope error.
			if ( ! is_string( $canonical ) || 1 !== preg_match( '//u', $canonical ) || ! is_array( $stat ) || is_link( $path ) || ! is_dir( $canonical ) ||
				0040000 !== ( $stat['mode'] & 0170000 ) || Filesystem_Roots::protected_path( $canonical ) ) {
				return $this->invalid( __( 'A configured root is missing, linked, private or not a directory. Review its path.', 'wp-ai-bridge' ) );
			}
			$purposes = $entry['purposes'] ?? array();
			if ( ! is_array( $purposes ) || count( array_filter( $purposes, 'is_string' ) ) !== count( $purposes ) || array_diff( $purposes, self::PURPOSES ) ) {
				return $this->invalid( __( 'A root contains an unknown administration purpose.', 'wp-ai-bridge' ) );
			}
			$scope = $entry['scope'] ?? 'site';
			if ( ! in_array( $scope, array( 'site', 'network' ), true ) ) {
				return $this->invalid( __( 'Root scope must be this site or the current network.', 'wp-ai-bridge' ) );
			}
			$label = $entry['label'] ?? $id;
			if ( ! is_string( $label ) || 1 !== preg_match( '//u', $label ) ) {
				return $this->invalid( __( 'Root labels must contain valid text.', 'wp-ai-bridge' ) );
			}
			$root = array(
				'id'       => $id,
				'label'    => sanitize_text_field( $label ),
				'path'     => $canonical,
				'purposes' => array_values( array_unique( $purposes ) ),
				'scope'    => $scope,
				'blog'     => 'site' === $scope ? $origin['blog'] : 0,
				'network'  => $origin['network'],
				'device'   => $stat['dev'],
				'inode'    => $stat['ino'],
			);
			foreach ( array( 'read', 'write', 'destructive', 'executable', 'nonserved' ) as $flag ) {
				$root[ $flag ] = $this->flag( $entry[ $flag ] ?? false );
			}
			if ( in_array( 'logs', $root['purposes'], true ) && ! $root['read'] ) {
				return $this->invalid( __( 'Log roots require read access.', 'wp-ai-bridge' ) );
			}
			if ( in_array( 'backup_restore', $root['purposes'], true ) &&
				( ! $root['write'] || ! $root['nonserved'] || Filesystem_Roots::served( $canonical ) ) ) {
				return $this->invalid( __( 'Restore roots must be writable and explicitly nonserved, outside the active installation.', 'wp-ai-bridge' ) );
			}
			$value['roots'][ $id ] = $root;
		}
		foreach ( array( 'restore_schemas', 'backup_tables' ) as $field ) {
			$names = $input[ $field ] ?? array();
			if ( is_string( $names ) ) {
				$names = preg_split( '/\r\n|\r|\n/', $names ); }
			if ( ! is_array( $names ) ) {
				return $this->invalid( __( 'Database scopes must be exact identifiers, one per line.', 'wp-ai-bridge' ) ); }
			foreach ( $names as $name ) {
				if ( ! is_string( $name ) ) {
					return $this->invalid( __( 'Database scope identifiers must be text.', 'wp-ai-bridge' ) ); }
				if ( '' === $name ) {
					continue; }
				if ( ! $this->identifier( $name ) ) {
					return $this->invalid( __( 'A database scope identifier is invalid or exceeds the native identifier length.', 'wp-ai-bridge' ) ); }
				$value[ $field ][] = $name;
			}
			$value[ $field ] = array_values( array_unique( $value[ $field ] ) );
		}
		foreach ( array( 'step_bytes', 'step_seconds' ) as $field ) {
			if ( ! isset( $input[ $field ] ) ) {
				continue; }
			$n = $input[ $field ];
			if ( ( ! is_int( $n ) && ! is_string( $n ) ) || ! ctype_digit( (string) $n ) ||
				false === filter_var(
					$n,
					FILTER_VALIDATE_INT,
					array(
						'options' => array(
							'min_range' => 1,
							'max_range' => PHP_INT_MAX,
						),
					)
				) ) {
				return $this->invalid( __( 'Work budgets must be positive native integers.', 'wp-ai-bridge' ) );
			}
			$value[ $field ] = (int) $n;
		}
		$current = $this->defaults();
		if ( $origin['blog'] !== $current['blog'] || $origin['network'] !== $current['network'] || ! $this->can_configure() ) {
			return $this->invalid( __( 'Administration context changed while validating scopes.', 'wp-ai-bridge' ), 409 );
		}
		return $value;
	}

	/** @param mixed $value Canonical flag input. @return bool */
	private function flag( $value ) {
		return in_array( $value, array( true, 1, '1' ), true );
	}

	/** @param mixed $id Exact configuration key. @return bool */
	private function root_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,63}$/D', $id );
	}

	/** @param mixed $value Native identifier, not a SQL fragment. @return bool */
	private function identifier( $value ) {
		return is_string( $value ) && false === strpos( $value, "\0" ) && trim( $value ) === $value &&
			1 === preg_match( '/^.{1,64}$/usD', $value );
	}

	/** @param mixed $id Map key. @param mixed $root Stored root. @param array $origin Current site/network. @return bool */
	private function stored_root_valid( $id, $root, array $origin ) {
		if ( ! $this->root_id( $id ) || ! is_array( $root ) || ( $root['id'] ?? null ) !== $id ||
			! is_string( $root['path'] ?? null ) || '' === $root['path'] || 1 !== preg_match( '//u', $root['path'] ) || false !== strpos( $root['path'], "\0" ) ||
			! is_string( $root['label'] ?? null ) || 1 !== preg_match( '//u', $root['label'] ) || ! is_array( $root['purposes'] ?? null ) ||
			count( array_filter( $root['purposes'], 'is_string' ) ) !== count( $root['purposes'] ) ||
			array_diff( $root['purposes'], self::PURPOSES ) || ! in_array( $root['scope'] ?? '', array( 'site', 'network' ), true ) ||
			( $root['network'] ?? null ) !== $origin['network'] ||
			( 'site' === $root['scope'] ? $origin['blog'] : 0 ) !== ( $root['blog'] ?? null ) ||
			! is_int( $root['device'] ?? null ) || ! is_int( $root['inode'] ?? null ) || $root['inode'] < 1 ) {
			return false;
		}
		foreach ( array( 'read', 'write', 'destructive', 'executable', 'nonserved' ) as $flag ) {
			if ( ! is_bool( $root[ $flag ] ?? null ) ) {
				return false; }
		}
		return true;
	}

	/** @param string $message Scope validation problem. @param int $status HTTP status. @return \WP_Error */
	private function invalid( $message, $status = 400 ) {
		return new \WP_Error( 'wpai_administration_configuration_invalid', $message, array( 'status' => $status ) );
	}

	/** @param string $message Exact operator validation problem. @param int $status HTTP status. @return array */
	private function reject( $message, $status = 400 ) {
		wp_die( esc_html( $message ), esc_html__( 'Administration scopes', 'wp-ai-bridge' ), array( 'response' => $status ) );
		$stored = get_option( self::OPTION_NAME, array() );
		return is_array( $stored ) ? $stored : array();
	}
}
