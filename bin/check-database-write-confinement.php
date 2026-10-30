<?php
/**
 * Inspect the reviewed native database boundary; never execute inspected SQL.
 *
 * @package WP_AI_Bridge
 */

/**
 * Find new connection/reconnect or unconfined native database primitives.
 *
 * This inventories native access, not a SQL sandbox or a substitute for review.
 *
 * @param string $source PHP source.
 * @param bool   $native_owner Whether this is the sole native adapter.
 * @param bool   $typed_owner Whether this is a typed transaction owner.
 * @param array  $metadata_members Explicit read-only context fields for a non-native owner.
 * @return array<string>
 */
function wpai_database_write_boundary( $source, $native_owner, $typed_owner, array $metadata_members = array() ) {
	$allowed = array(
		'mysqli_get_client_info',
		'mysqli_thread_id',
		'mysqli_close',
		'mysqli_prepare',
		'mysqli_stmt_bind_param',
		'mysqli_stmt_send_long_data',
		'mysqli_stmt_execute',
		'mysqli_stmt_affected_rows',
		'mysqli_stmt_insert_id',
		'mysqli_warning_count',
		'mysqli_stmt_get_result',
		'mysqli_stmt_get_warnings',
		'mysqli_query',
		'mysqli_affected_rows',
		'mysqli_insert_id',
		'mysqli_fetch_assoc',
		'mysqli_free_result',
		'mysqli_stmt_close',
		'mysqli_stmt_errno',
		'mysqli_errno',
		'mysqli_stmt_sqlstate',
		'mysqli_sqlstate',
		'mysqli_stmt_param_count',
		'mysqli_stmt_bind_result',
		'mysqli_stmt_fetch',
		'mysqli_stmt_more_results',
		'mysqli_stmt_field_count',
		'mysqli_stmt_result_metadata',
		'mysqli_fetch_fields',
	);
	$tokens  = array_values(
		array_filter(
			token_get_all( $source ),
			static function ( $token ) {
				return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
			}
		)
	);
	$errors  = array();
	foreach ( $tokens as $index => $token ) {
		if ( ! is_array( $token ) ) {
			continue;
		}
		$name = wpai_boundary_basename( $token[1] );
		$next = $tokens[ $index + 1 ] ?? null;
		$prev = $tokens[ $index - 1 ] ?? null;
		if ( in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE ), true ) ) {
			if ( '(' === $next && ( 0 === strpos( $name, 'mysqli_' ) || 0 === strpos( $name, 'mysql_' ) ) &&
				( ! $native_owner || ! in_array( $name, $allowed, true ) ) ) {
				$errors[] = 'Unreviewed native function: ' . $name;
			}
			if ( is_array( $prev ) && T_NEW === $prev[0] && in_array( $name, array( 'mysqli', 'pdo' ), true ) ) {
				$errors[] = 'A second database connection is forbidden.';
			}
			if ( $typed_owner && '(' === $next && is_array( $prev ) && T_OBJECT_OPERATOR === $prev[0] &&
				in_array( $name, array( 'db_connect', 'check_connection', 'ping', 'real_connect', 'multi_query', 'change_user', 'select_db', 'refresh' ), true ) ) {
				$errors[] = 'Connection/session primitive outside the reviewed protocol: ' . $name;
			}
		}
		if ( $typed_owner && T_VARIABLE === $token[0] && '$wpdb' === $token[1] ) {
			$arrow = $tokens[ $index + 1 ] ?? null;
			$field = $tokens[ $index + 2 ] ?? null;
			if ( is_array( $arrow ) && T_OBJECT_OPERATOR === $arrow[0] &&
				is_array( $field ) && ( ! in_array( $field[1], $native_owner ? array( 'dbh', 'base_prefix', 'options', 'dbname', 'ready' ) : $metadata_members, true ) ||
				'(' === ( $tokens[ $index + 3 ] ?? null ) ||
				( ! $native_owner && (
					in_array(
						is_array( $tokens[ $index + 3 ] ?? null ) ? $tokens[ $index + 3 ][0] : ( $tokens[ $index + 3 ] ?? null ),
						array( '=', T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL, T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_COALESCE_EQUAL, T_POW_EQUAL, T_INC, T_DEC ),
						true
					) || ( is_array( $prev ) && in_array( $prev[0], array( T_INC, T_DEC ), true ) )
				) ) ) ) {
				$errors[] = 'Typed execution must not use wpdb SQL/reconnect/credential APIs.';
			}
		}
	}
	return array_values( array_unique( $errors ) );
}


/** @param mixed $token PHP token. @return string */
function wpai_boundary_text( $token ) {
	return is_array( $token ) ? $token[1] : (string) $token;
}

/** @param string $name Qualified identifier. @return string */
function wpai_boundary_basename( $name ) {
	$parts = explode( '\\', strtolower( $name ) );
	return end( $parts );
}

/** @param string $source Source, never executed. @return array */
function wpai_boundary_tokens( $source ) {
	return array_values(
		array_filter(
			token_get_all( $source ),
			static function ( $token ) {
				return ! is_array( $token ) || ! in_array( $token[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true );
			}
		)
	);
}

/** Record executable calls by exact lexical class and enclosing method.
 * @param string $source Source, never executed. @return array
 */
function wpai_boundary_inventory( $source ) {
	$tokens           = wpai_boundary_tokens( $source );
	$class            = '';
	$method           = '';
	$pending_class    = null;
	$pending_function = null;
	$stack            = array();
	$depth            = 0;
	$calls            = array();
	foreach ( $tokens as $index => $token ) {
		$previous = $tokens[ $index - 1 ] ?? null;
		if ( is_array( $token ) && in_array( $token[0], array( T_CLASS, T_INTERFACE, T_TRAIT ), true ) &&
			! ( is_array( $previous ) && T_DOUBLE_COLON === $previous[0] ) ) {
			$name          = $tokens[ $index + 1 ] ?? null;
			$pending_class = is_array( $name ) && T_STRING === $name[0] ? $name[1] : '@anonymous';
		}
		if ( is_array( $token ) && T_FUNCTION === $token[0] ) {
			$name             = $tokens[ $index + 1 ] ?? null;
			$pending_function = is_array( $name ) && T_STRING === $name[0] ? $name[1] : $method;
		}
		if ( is_array( $token ) && in_array( $token[0], array( T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES ), true ) ) {
			++$depth;
		}
		if ( '{' === $token ) {
			++$depth;
			if ( null !== $pending_class ) {
				$stack[]       = array( $class, $method, $depth );
				$class         = $pending_class;
				$method        = '';
				$pending_class = null;
			} elseif ( null !== $pending_function ) {
				$stack[]          = array( $class, $method, $depth );
				$method           = $pending_function;
				$pending_function = null;
			}
		}
		if ( '}' === $token ) {
			if ( $stack && end( $stack )[2] === $depth ) {
				list( $class, $method ) = array_pop( $stack );
			}
			--$depth;
		}
		if ( ! is_array( $token ) || ! in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE ), true ) ) {
			continue;
		}
		$name  = wpai_boundary_basename( $token[1] );
		$scope = $class . '::' . $method;
		if ( is_array( $previous ) && T_NEW === $previous[0] ) {
			$signature = $name;
			$nest      = 0;
			if ( '(' === ( $tokens[ $index + 1 ] ?? null ) ) {
				for ( $cursor = $index + 1; $cursor < count( $tokens ); ++$cursor ) {
					$part       = $tokens[ $cursor ];
					$signature .= wpai_boundary_text( $part );
					if ( '(' === $part ) {
						++$nest; }
					if ( ')' === $part && 0 === --$nest ) {
						break; }
				}
			}
			$calls[] = array(
				'scope'     => $scope,
				'kind'      => 'new',
				'name'      => $name,
				'signature' => $signature,
			);
			continue;
		}
		if ( '(' !== ( $tokens[ $index + 1 ] ?? null ) ||
			( is_array( $previous ) && in_array( $previous[0], array( T_FUNCTION, T_NEW, T_DOUBLE_COLON ), true ) ) ) {
			continue;
		}
		$object    = is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ), true );
		$signature = $name;
		$nest      = 0;
		for ( $cursor = $index + 1; $cursor < count( $tokens ); ++$cursor ) {
			$part       = $tokens[ $cursor ];
			$signature .= wpai_boundary_text( $part );
			if ( '(' === $part ) {
				++$nest; }
			if ( ')' === $part && 0 === --$nest ) {
				break; }
		}
		$calls[] = array(
			'scope'     => $scope,
			'kind'      => $object ? 'method' : 'function',
			'name'      => $name,
			'signature' => $signature,
		);
	}
	return $calls;
}

/** @param array $actual Current calls. @param array $expected Reviewed calls. @param string $label Owner. @return array */
function wpai_boundary_compare( array $actual, array $expected, $label ) {
	ksort( $actual );
	ksort( $expected );
	$errors = array();
	foreach ( array_unique( array_merge( array_keys( $actual ), array_keys( $expected ) ) ) as $key ) {
		if ( ( $actual[ $key ] ?? 0 ) !== ( $expected[ $key ] ?? 0 ) ) {
			$errors[] = $label . ': ' . $key . ' expected=' . ( $expected[ $key ] ?? 0 ) . ' observed=' . ( $actual[ $key ] ?? 0 );
		}
	}
	return $errors;
}

/** Detect obvious protected callable aliases as well as direct syntax.
 * @param string $source Source. @return array
 */
function wpai_boundary_aliases( $source ) {
	$tokens    = wpai_boundary_tokens( $source );
	$protected = array( 'fopen', 'fwrite', 'fputs', 'fputcsv', 'ftruncate', 'file_put_contents', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'link', 'symlink', 'chmod', 'chown', 'chgrp', 'touch', 'stream_copy_to_stream', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'eval' );
	$tainted   = array();
	$errors    = array();
	foreach ( $tokens as $index => $token ) {
		if ( is_array( $token ) && T_VARIABLE === $token[0] && '=' === ( $tokens[ $index + 1 ] ?? null ) ) {
			$value = $tokens[ $index + 2 ] ?? null;
			$name  = is_array( $value ) && T_CONSTANT_ENCAPSED_STRING === $value[0] ? wpai_boundary_basename( substr( $value[1], 1, -1 ) ) : '';
			if ( in_array( $name, $protected, true ) || 0 === strpos( $name, 'mysqli_' ) || 0 === strpos( $name, 'mysql_' ) ||
				( is_array( $value ) && T_VARIABLE === $value[0] && isset( $tainted[ $value[1] ] ) ) ) {
				$tainted[ $token[1] ] = true;
			}
		}
		if ( is_array( $token ) && T_VARIABLE === $token[0] && isset( $tainted[ $token[1] ] ) && '(' === ( $tokens[ $index + 1 ] ?? null ) ) {
			$errors[] = 'Protected primitive invoked through a variable callable.';
		}
		if ( is_array( $token ) && in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED ), true ) &&
			in_array( wpai_boundary_basename( $token[1] ), array( 'call_user_func', 'call_user_func_array', 'forward_static_call', 'forward_static_call_array', 'fromcallable' ), true ) &&
			'(' === ( $tokens[ $index + 1 ] ?? null ) ) {
			$value = $tokens[ $index + 2 ] ?? null;
			$name  = is_array( $value ) && T_CONSTANT_ENCAPSED_STRING === $value[0] ? wpai_boundary_basename( substr( $value[1], 1, -1 ) ) : '';
			if ( in_array( $name, $protected, true ) || 0 === strpos( $name, 'mysqli_' ) || 0 === strpos( $name, 'mysql_' ) ||
				( is_array( $value ) && T_VARIABLE === $value[0] && isset( $tainted[ $value[1] ] ) ) ) {
				$errors[] = 'Protected primitive passed through callable dispatch.';
			}
		}
	}
	return array_values( array_unique( $errors ) );
}

/** No credential, reconnect or new-connection exception follows from ownership.
 * @param string $source Source. @param bool $typed Typed owner. @return array
 */
function wpai_database_extra_boundary( $source, $typed ) {
	$tokens  = wpai_boundary_tokens( $source );
	$errors  = array();
	$classes = array( 'mysqli', 'pdo', 'sqlite3' );
	foreach ( $tokens as $index => $token ) {
		if ( ! is_array( $token ) || T_USE !== $token[0] ) {
			continue; }
		$name  = $tokens[ $index + 1 ] ?? null;
		$as    = $tokens[ $index + 2 ] ?? null;
		$alias = $tokens[ $index + 3 ] ?? null;
		if ( is_array( $name ) && in_array( strtolower( ltrim( $name[1], '\\' ) ), $classes, true ) &&
			is_array( $as ) && T_AS === $as[0] && is_array( $alias ) && T_STRING === $alias[0] ) {
			$classes[] = strtolower( $alias[1] );
		}
	}
	foreach ( $tokens as $index => $token ) {
		if ( ! is_array( $token ) ) {
			continue; }
		$name     = wpai_boundary_basename( $token[1] );
		$previous = $tokens[ $index - 1 ] ?? null;
		$next     = $tokens[ $index + 1 ] ?? null;
		if ( in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED, T_NAME_RELATIVE ), true ) ) {
			if ( is_array( $previous ) && T_NEW === $previous[0] && in_array( $name, $classes, true ) ) {
				$errors[] = 'A second physical database connection is forbidden.';
			}
			if ( '(' === $next && in_array( $name, array( 'pg_connect', 'pg_pconnect', 'sqlite_open', 'sqlite_popen', 'oci_connect', 'oci_new_connect', 'oci_pconnect', 'odbc_connect', 'odbc_pconnect' ), true ) ) {
				$errors[] = 'A second physical database connection is forbidden.';
			}
			if ( in_array( strtoupper( $token[1] ), array( 'DB_USER', 'DB_PASSWORD', 'DB_HOST' ), true ) ||
				( is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ), true ) && in_array( $name, array( 'dbpassword', 'dbuser', 'dbhost' ), true ) ) ) {
				$errors[] = 'Bridge must not read database credentials.';
			}
			if ( $typed && '(' === $next && is_array( $previous ) && in_array( $previous[0], array( T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR ), true ) &&
				in_array( $name, array( 'query', 'prepare', 'execute', 'real_query', 'execute_query', 'stmt_init', 'bind_param', 'bind_result', 'db_connect', 'check_connection', 'ping', 'real_connect', 'multi_query', 'change_user', 'select_db', 'refresh' ), true ) ) {
				$errors[] = 'Unreviewed native object/session primitive.';
			}
		}
		if ( T_VARIABLE === $token[0] && '$GLOBALS' === $token[1] && '[' === $next && in_array( wpai_boundary_text( $tokens[ $index + 2 ] ?? '' ), array( "'wpdb'", '"wpdb"' ), true ) ) {
			$errors[] = 'Native database aliases must not bypass the explicit wpdb owner.';
		}
		if ( $typed && T_VARIABLE === $token[0] && '$wpdb' === $token[1] &&
			( '&' === wpai_boundary_text( $previous ) || ( '(' === $previous && is_array( $tokens[ $index - 2 ] ?? null ) && T_UNSET === $tokens[ $index - 2 ][0] ) ) ) {
			$errors[] = 'Read-only native context cannot be referenced or unset.';
		}
	}
	return array_merge( $errors, wpai_boundary_aliases( $source ) );
}

/** Exact write modes/destinations and configuration's read-only metadata.
 * @param string $source Source. @param string $relative Exact owner. @param array $pins Reviewed primitive inventory. @return array
 */
function wpai_filesystem_boundary( $source, $relative, array $pins ) {
	$writes   = array( 'file_put_contents', 'fopen', 'fwrite', 'fputs', 'fputcsv', 'unlink', 'rename', 'copy', 'mkdir', 'rmdir', 'link', 'symlink', 'chmod', 'chown', 'chgrp', 'touch', 'ftruncate', 'stream_copy_to_stream' );
	$metadata = array( 'lstat', 'stat', 'realpath', 'is_dir', 'is_file', 'file_exists', 'is_link', 'fread', 'file_get_contents', 'readfile', 'opendir', 'scandir', 'file', 'hash_file', 'md5_file', 'sha1_file' );
	$actual   = array();
	$meta     = array();
	$errors   = wpai_boundary_aliases( $source );
	foreach ( wpai_boundary_tokens( $source ) as $token ) {
		if ( chr( 96 ) === $token || ( is_array( $token ) && T_EVAL === $token[0] ) ) {
			$errors[] = 'Direct execution is forbidden.'; }
	}
	foreach ( wpai_boundary_inventory( $source ) as $call ) {
		if ( 'new' === $call['kind'] && in_array( $call['name'], array( 'splfileobject', 'spltempfileobject' ), true ) ) {
			$key            = $call['scope'] . '|new:' . $call['signature'];
			$actual[ $key ] = ( $actual[ $key ] ?? 0 ) + 1;
		}
		if ( 'src/Support/class-administration-configuration.php' === $relative && 'new' === $call['kind'] &&
			in_array( $call['name'], array( 'directoryiterator', 'filesystemiterator', 'recursivedirectoryiterator' ), true ) ) {
			$errors[] = 'Configuration may inspect path metadata only.';
		}
		if ( 'function' !== $call['kind'] ) {
			continue; }
		if ( in_array( $call['name'], array( 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen' ), true ) ) {
			$errors[] = 'Direct execution is forbidden.'; }
		$key = $call['scope'] . '|' . $call['signature'];
		if ( in_array( $call['name'], $writes, true ) ) {
			$actual[ $key ] = ( $actual[ $key ] ?? 0 ) + 1; }
		if ( 'src/Support/class-administration-configuration.php' === $relative && in_array( $call['name'], $metadata, true ) ) {
			$meta[ $key ] = ( $meta[ $key ] ?? 0 ) + 1; }
	}
	$errors = array_merge( $errors, wpai_boundary_compare( $actual, $pins['writes'][ $relative ] ?? array(), $relative . ' filesystem' ) );
	if ( 'src/Support/class-administration-configuration.php' === $relative ) {
		$errors = array_merge( $errors, wpai_boundary_compare( $meta, $pins['metadata'][ $relative ], $relative . ' metadata' ) );
	}
	return $errors;
}

/*
 * Reviewed executable inventories; filesystem arguments pin access modes and
 * destination identities. SQL ownership is class/method-specific. These guards
 * do not claim arbitrary PHP or native SQL is sandboxed.
 */
$boundary_pins = array(
	'writes'       => array(
		'src/Abilities/class-media-abilities.php'          => array(
			'Media_Abilities::upload|file_put_contents($tmp_name,$bytes)' => 1,
		),
		'src/Abilities/class-source-editing-abilities.php' => array(
			'Source_Editing_Abilities::acquire_file_lock|new:splfileobject($target[\'canonical_path\'],\'rb\')' => 1,
			'Source_Editing_Abilities::acquire_coordination_lock|new:splfileobject($this->coordination_lock_path($canonical_path),\'c+b\')' => 1,
			'Source_Editing_Abilities::replace_exact_bytes|link($paths[\'stage\'],$paths[\'probe\'])' => 1,
			'Source_Editing_Abilities::replace_exact_bytes|rename($path,$paths[\'hold\'])' => 1,
			'Source_Editing_Abilities::replace_exact_bytes|link($paths[\'stage\'],$path)' => 1,
			'Source_Editing_Abilities::stage_exact_bytes|fopen($path,\'x+b\')' => 1,
			'Source_Editing_Abilities::stage_exact_bytes|chmod($path,0600)' => 1,
			'Source_Editing_Abilities::stage_exact_bytes|fwrite($handle,substr($bytes,$offset))' => 1,
			'Source_Editing_Abilities::match_stage_metadata|chmod($stage,$mode)' => 1,
			'Source_Editing_Abilities::match_stage_metadata|chown($stage,(int)$source[\'uid\'])' => 1,
			'Source_Editing_Abilities::match_stage_metadata|chgrp($stage,(int)$source[\'gid\'])' => 1,
			'Source_Editing_Abilities::restore_quarantined_path|link($hold,$path)' => 1,
			'Source_Editing_Abilities::remove_replacement_artifact|unlink($path)' => 1,
		),
		'src/Support/class-private-artifact-writer.php'    => array(
			'Private_Artifact_Writer::begin|chmod($real,0600)' => 1,
			'Private_Artifact_Writer::begin|fopen($real,\'wb\')' => 1,
			'Private_Artifact_Writer::append|fwrite($this->handle,substr($bytes,$offset))' => 1,
		),
		'src/Support/class-administration-transfer-writer.php' => array(
			'Administration_Transfer_Writer::append_native|fwrite($this->stream,substr($bytes,$offset))' => 1,
		),
		'src/Support/class-extension-install-lock.php'     => array(
			'Extension_Install_Lock::acquire_filesystem_lock|fopen($path,\'x\')' => 1,
			'Extension_Install_Lock::acquire_filesystem_lock|chmod($path,0644)' => 1,
			'Extension_Install_Lock::acquire_filesystem_lock|fopen($path,\'r\')' => 1,
		),
		'src/Support/class-private-transfer-session.php'   => array(
			'Private_Transfer_Session::begin|fopen($data,\'x+b\')' => 1,
			'Private_Transfer_Session::begin|fopen($manifest,\'x+b\')' => 1,
			'Private_Transfer_Session::append_verified_frame|fopen($path,\'r+b\')' => 1,
			'Private_Transfer_Session::append_verified_frame|fwrite($file,substr($bytes,$written))' => 1,
			'Private_Transfer_Session::with_administration_writer|fopen($path,\'r+b\')' => 1,
			'Private_Transfer_Session::with_administration_source|fopen($path,\'rb\')' => 1,
			'Private_Transfer_Session::stage_administration_export|fopen($path,\'rb\')' => 1,
			'Private_Transfer_Session::append|fopen($path,\'r+b\')' => 1,
			'Private_Transfer_Session::append|fwrite($file,substr($bytes,$cursor))' => 1,
			'Private_Transfer_Session::with_database_sources|fopen($path,\'rb\')' => 1,
			'Private_Transfer_Session::stage_native_export|fopen($path,\'rb\')' => 1,
			'Private_Transfer_Session::read_export_window|fopen($path,\'rb\')' => 1,
			'Private_Transfer_Session::root|mkdir($root,0700)' => 1,
			'Private_Transfer_Session::root|chmod($root,0700)' => 1,
			'Private_Transfer_Session::guard|fopen($path,\'c+b\')' => 1,
			'Private_Transfer_Session::secure_file|chmod($path,0600)' => 1,
			'Private_Transfer_Session::open_meta|fopen($path,\'r+b\')' => 1,
			'Private_Transfer_Session::read_checkpoint_file|fopen($path,\'rb\')' => 1,
			'Private_Transfer_Session::read_claim_fence|fopen($path,\'rb\')' => 1,
			'Private_Transfer_Session::fence_native_effect|fopen($temporary,\'x+b\')' => 1,
			'Private_Transfer_Session::fence_native_effect|fwrite($h,substr($wire,$offset))' => 1,
			'Private_Transfer_Session::fence_native_effect|rename($temporary,$path)' => 1,
			'Private_Transfer_Session::durably_sync_claim_directory|fopen($root,\'rb\')' => 1,
			'Private_Transfer_Session::prepare_media_sideload|fopen($claim[\'path\'],\'rb\')' => 1,
			'Private_Transfer_Session::prepare_media_sideload|fopen($claim[\'temp\'],\'x+b\')' => 1,
			'Private_Transfer_Session::prepare_media_sideload|stream_copy_to_stream($source,$target)' => 1,
			'Private_Transfer_Session::save_meta|fopen($temp,\'x+b\')' => 1,
			'Private_Transfer_Session::save_meta|fwrite($out,substr($wire,$offset))' => 1,
			'Private_Transfer_Session::save_meta|rename($temp,$dest)' => 1,
			'Private_Transfer_Session::check_file|fopen($path,\'r+b\')' => 1,
			'Private_Transfer_Session::check_file|ftruncate($h,$meta[\'received\'])' => 1,
			'Private_Transfer_Session::erase|fopen($marker,\'x+b\')' => 1,
			'Private_Transfer_Session::erase|fwrite($h,\'1\')' => 1,
		),
		'src/Support/class-safe-temporary-staging.php'     => array(
			'Safe_Temporary_Staging::create|chmod($file,0600)' => 1,
			'Safe_Temporary_Staging::create_private_artifact|chmod($file,0600)' => 1,
			'Safe_Temporary_Staging::one_shot_directory|mkdir($path,0700)' => 1,
		),
		'src/Support/class-backup-operation.php'           => array(
			'Backup_Operation::execute|fopen(\'php://memory\',\'w+b\')' => 1,
			'Backup_Operation::execute|fwrite($stream,$raw)' => 1,
		),
		'src/Support/class-scoped-filesystem.php'          => array(
			'Scoped_Filesystem::with_read_stream|fopen($descriptor[\'path\'],\'rb\')' => 1,
			'Scoped_Filesystem::private_directory|mkdir($directory,0700)' => 1,
			'Scoped_Filesystem::lock|fopen($path,\'x+b\')' => 1,
			'Scoped_Filesystem::lock|chmod($path,0600)'    => 1,
			'Scoped_Filesystem::lock|fopen($path,\'r+b\')' => 1,
			'Scoped_Filesystem::mkdir|mkdir($target[\'path\'],$mode)' => 1,
			'Scoped_Filesystem::set_mode|chmod($target[\'path\'],$mode)' => 1,
			'Scoped_Filesystem::apply_verified_source|chmod($stage[\'path\'],$mode)' => 1,
			'Scoped_Filesystem::apply_verified_source|chown($stage[\'path\'],$target[\'stat\'][\'uid\'])' => 1,
			'Scoped_Filesystem::apply_verified_source|chgrp($stage[\'path\'],$target[\'stat\'][\'gid\'])' => 1,
			'Scoped_Filesystem::apply_verified_source|link($stage[\'path\'],$probe)' => 1,
			'Scoped_Filesystem::apply_verified_source|unlink($probe)' => 1,
			'Scoped_Filesystem::apply_verified_source|rename($target[\'path\'],$hold)' => 1,
			'Scoped_Filesystem::apply_verified_source|link($hold,$target[\'path\'])' => 2,
			'Scoped_Filesystem::apply_verified_source|unlink($hold)' => 2,
			'Scoped_Filesystem::apply_verified_source|link($stage[\'path\'],$pin)' => 1,
			'Scoped_Filesystem::apply_verified_source|link($pin,$target[\'path\'])' => 1,
			'Scoped_Filesystem::unlink_candidate_alias|unlink($path)' => 1,
			'Scoped_Filesystem::apply_verified_source|chmod($stage[\'path\'],0600)' => 1,
			'Scoped_Filesystem::prepare_source|fopen($path,\'x+b\')' => 1,
			'Scoped_Filesystem::prepare_source|chmod($path,0600)' => 1,
			'Scoped_Filesystem::prepare_source|fopen($state[\'path\'],\'r+b\')' => 1,
			'Scoped_Filesystem::prepare_source|ftruncate($file,$state[\'offset\'])' => 1,
			'Scoped_Filesystem::prepare_source|fwrite($file,substr($bytes,$done))' => 1,
			'Scoped_Filesystem::prepare_source|unlink($state[\'path\'])' => 1,
			'Scoped_Filesystem::delete|rmdir($target[\'path\'])' => 1,
			'Scoped_Filesystem::delete|rename($target[\'path\'],$hold)' => 1,
			'Scoped_Filesystem::delete|link($hold,$target[\'path\'])' => 1,
			'Scoped_Filesystem::delete|unlink($hold)'      => 2,
			'Scoped_Filesystem::recover|fopen($hold,\'rb\')' => 1,
		),
	),
	'native'       => array(
		'src/Support/class-database-connection.php' => array(
			'Database_Connection::__construct|mysqli_get_client_info' => 1,
			'Database_Connection::__construct|mysqli_thread_id' => 1,
			'Database_Connection::set_backup_sql_mode|mysqli_thread_id' => 1,
			'Database_Connection::set_backup_sql_mode|mysqli_prepare' => 1,
			'Database_Connection::set_backup_sql_mode|mysqli_warning_count' => 2,
			'Database_Connection::set_backup_sql_mode|mysqli_stmt_bind_param' => 1,
			'Database_Connection::set_backup_sql_mode|mysqli_stmt_execute' => 1,
			'Database_Connection::set_backup_sql_mode|mysqli_stmt_get_warnings' => 1,
			'Database_Connection::set_backup_sql_mode|mysqli_stmt_close' => 2,
			'Database_Connection::dispatch_ddl_once|mysqli_thread_id' => 1,
			'Database_Connection::dispatch_ddl_once|mysqli_query' => 1,
			'Database_Connection::dispatch_ddl_once|mysqli_errno' => 1,
			'Database_Connection::dispatch_ddl_once|mysqli_sqlstate' => 1,
			'Database_Connection::dispatch_ddl_once|mysqli_warning_count' => 1,
			'Database_Connection::dispatch_ddl_once|mysqli_affected_rows' => 1,
			'Database_Connection::dispatch_ddl_once|mysqli_free_result' => 1,
			'Database_Connection::prepare_native_sql|mysqli_prepare' => 1,
			'Database_Connection::prepare_native_sql|mysqli_stmt_param_count' => 1,
			'Database_Connection::prepare_native_sql|mysqli_stmt_bind_param' => 1,
			'Database_Connection::prepare_native_sql|mysqli_stmt_close' => 1,
			'Database_Connection::cancel_native_sql|mysqli_stmt_close' => 1,
			'Database_Connection::stream_native_sql_once|mysqli_thread_id' => 1,
			'Database_Connection::stream_native_sql_once|mysqli_stmt_execute' => 1,
			'Database_Connection::stream_native_sql_once|mysqli_stmt_errno' => 2,
			'Database_Connection::stream_native_sql_once|mysqli_stmt_sqlstate' => 2,
			'Database_Connection::stream_native_sql_once|mysqli_warning_count' => 1,
			'Database_Connection::stream_native_sql_once|mysqli_stmt_affected_rows' => 1,
			'Database_Connection::stream_native_sql_once|mysqli_stmt_insert_id' => 1,
			'Database_Connection::stream_native_sql_once|mysqli_stmt_bind_result' => 1,
			'Database_Connection::stream_native_sql_once|mysqli_stmt_fetch' => 1,
			'Database_Connection::stream_native_sql_once|mysqli_stmt_more_results' => 1,
			'Database_Connection::stream_native_sql_once|mysqli_stmt_close' => 2,
			'Database_Connection::native_sql_fields|mysqli_stmt_field_count' => 1,
			'Database_Connection::native_sql_fields|mysqli_stmt_result_metadata' => 1,
			'Database_Connection::native_sql_fields|mysqli_fetch_fields' => 1,
			'Database_Connection::native_sql_fields|mysqli_free_result' => 1,
			'Database_Connection::quarantine|mysqli_close' => 1,
			'Database_Connection::run|mysqli_thread_id'    => 1,
			'Database_Connection::run|mysqli_prepare'      => 1,
			'Database_Connection::run|mysqli_stmt_bind_param' => 1,
			'Database_Connection::run|mysqli_stmt_send_long_data' => 2,
			'Database_Connection::run|mysqli_stmt_execute' => 1,
			'Database_Connection::run|mysqli_stmt_affected_rows' => 1,
			'Database_Connection::run|mysqli_stmt_insert_id' => 1,
			'Database_Connection::run|mysqli_warning_count' => 2,
			'Database_Connection::run|mysqli_stmt_get_result' => 1,
			'Database_Connection::run|mysqli_query'        => 1,
			'Database_Connection::run|mysqli_affected_rows' => 1,
			'Database_Connection::run|mysqli_insert_id'    => 1,
			'Database_Connection::run|mysqli_fetch_assoc'  => 1,
			'Database_Connection::run|mysqli_free_result'  => 1,
			'Database_Connection::run|mysqli_stmt_close'   => 1,
			'Database_Connection::native_failure|mysqli_stmt_errno' => 1,
			'Database_Connection::native_failure|mysqli_errno' => 1,
			'Database_Connection::native_failure|mysqli_stmt_sqlstate' => 1,
			'Database_Connection::native_failure|mysqli_sqlstate' => 1,
		),
	),
	'adapter'      => array(
		'src/Support/class-database-writer.php'           => array(
			'Database_Writer::perform|run'       => 2,
			'Database_Writer::read_receipt|run'  => 1,
			'Database_Writer::row|run'           => 1,
			'Database_Writer::step|run'          => 1,
			'Database_Writer::control_guard|run' => 2,
		),
		'src/Support/class-database-native-sql-operation.php' => array(
			'Database_Native_SQL_Operation::execute|prepare_native_sql' => 1,
			'Database_Native_SQL_Operation::execute|cancel_native_sql' => 2,
			'Database_Native_SQL_Operation::execute|stream_native_sql_once' => 1,
			'Database_Native_SQL_Operation::execute|abandon_native_sql_session' => 1,
			'Database_Native_SQL_Operation::receipt_transaction|begin_callback_journal' => 1,
			'Database_Native_SQL_Operation::receipt_transaction|run' => 2,
			'Database_Native_SQL_Operation::read_locked|run' => 1,
		),
		'src/Support/class-database-schema-operation.php' => array(
			'Database_Schema_Operation::execute|begin_callback_journal' => 2,
			'Database_Schema_Operation::execute|run'       => 1,
			'Database_Schema_Operation::execute|dispatch_ddl_once' => 1,
			'Database_Schema_Operation::rename_locked|acquire_schema_write_lock' => 1,
			'Database_Schema_Operation::rename_locked|run' => 2,
			'Database_Schema_Operation::rename_locked|dispatch_ddl_once' => 1,
			'Database_Schema_Operation::rename_locked|release_schema_write_lock' => 1,
			'Database_Schema_Operation::inert_schema|run'  => 2,
		),
		'src/Support/class-native-option-inputs.php'      => array(
			'Native_Option_Inputs::begin|run'   => 1,
			'Native_Option_Inputs::append|run'  => 1,
			'Native_Option_Inputs::verify|run'  => 1,
			'Native_Option_Inputs::status|run'  => 1,
			'Native_Option_Inputs::release|run' => 1,
			'Native_Option_Inputs::value|run'   => 1,
		),
		'src/Support/class-administration-operation-journal.php' => array(
			'Administration_Operation_Journal::transaction|begin_callback_journal' => 1,
			'Administration_Operation_Journal::transaction|run' => 2,
			'Administration_Operation_Journal::read_locked|run' => 1,
		),
		'src/Support/class-native-option-snapshots.php'   => array(
			'Native_Option_Snapshots::create|run'  => 1,
			'Native_Option_Snapshots::read|run'    => 1,
			'Native_Option_Snapshots::status|run'  => 1,
			'Native_Option_Snapshots::release|run' => 1,
		),
		'src/Support/class-database-control-storage.php'  => array(
			'Database_Control_Storage::observe_table|run' => 1,
			'Database_Control_Storage::schema|run'        => 4,
			'Database_Control_Storage::pin_table|run'     => 6,
			'Database_Control_Storage::foreign_keys|run'  => 1,
			'Database_Control_Storage::stable_native_definition|run' => 1,
		),
		'src/Support/class-native-option-operation.php'   => array(
			'Native_Option_Operation::target|begin_callback_journal' => 1,
			'Native_Option_Operation::target|run' => 3,
		),
		'src/Support/class-create-claim-store.php'        => array(
			'Create_Claim_Store::reserve_native_option_operation|begin_callback_journal' => 1,
			'Create_Claim_Store::reserve_native_option_operation|run' => 1,
			'Create_Claim_Store::read_native_option_operation|begin_callback_journal' => 1,
			'Create_Claim_Store::ready_native_option_operation|begin_callback_journal' => 1,
			'Create_Claim_Store::complete_native_option_operation|begin_callback_journal' => 1,
			'Create_Claim_Store::complete_native_option_operation|run' => 1,
			'Create_Claim_Store::native_option_read_locked|run' => 1,
		),
		'src/Support/class-backup-database.php'           => array(
			'Backup_Database::identity|run'           => 1,
			'Backup_Database::assert_session|run'     => 1,
			'Backup_Database::assert_session|quarantine_backup_session' => 1,
			'Backup_Database::activity_digest|run'    => 1,
			'Backup_Database::inventory|run'          => 2,
			'Backup_Database::schema|run'             => 8,
			'Backup_Database::next_group|run'         => 1,
			'Backup_Database::cell|run'               => 1,
			'Backup_Database::empty_target|run'       => 2,
			'Backup_Database::database_privilege|run' => 1,
			'Backup_Database::assert_inventory|run'   => 2,
			'Backup_Database::ddl|dispatch_ddl_once'  => 1,
			'Backup_Database::insert|begin_callback_journal' => 1,
			'Backup_Database::insert|run'             => 5,
			'Backup_Database::with_session|run'       => 2,
			'Backup_Database::with_session|set_backup_sql_mode' => 2,
			'Backup_Database::with_session|quarantine_backup_session' => 1,
		),
		'src/Support/class-database-connection.php'       => array(
			'Database_Connection::__construct|run'         => 2,
			'Database_Connection::set_backup_sql_mode|run' => 1,
			'Database_Connection::quarantine_backup_session|abandon_native_sql_session' => 1,
			'Database_Connection::assert_clean_session|begin_callback_journal' => 1,
			'Database_Connection::acquire_schema_write_lock|dispatch_ddl_once' => 1,
			'Database_Connection::release_schema_write_lock|dispatch_ddl_once' => 1,
			'Database_Connection::foreign_reference|run'   => 2,
			'Database_Connection::assert_callback_context|run' => 1,
			'Database_Connection::begin_owned|run'         => 5,
			'Database_Connection::commit|run'              => 2,
			'Database_Connection::rollback|run'            => 2,
		),
	),
	'constructors' => array(
		'src/Support/class-database-writer.php'           => array(
			'Database_Writer::execute|database_connection' => 1,
		),
		'src/Support/class-database-native-sql-operation.php' => array(
			'Database_Native_SQL_Operation::execute|database_connection' => 1,
		),
		'src/Support/class-database-schema-operation.php' => array(
			'Database_Schema_Operation::execute|database_connection' => 1,
		),
		'src/Support/class-administration-operation-journal.php' => array(
			'Administration_Operation_Journal::db|database_connection' => 1,
		),
		'src/Support/class-native-option-operation.php'   => array(
			'Native_Option_Operation::db|database_connection' => 1,
		),
		'src/Support/class-backup-database.php'           => array(
			'Backup_Database::__construct|database_connection' => 1,
		),
	),
	'metadata'     => array(
		'src/Support/class-administration-configuration.php' => array(
			'Administration_Configuration::validate|realpath($path)' => 1,
			'Administration_Configuration::validate|lstat($canonical)' => 1,
			'Administration_Configuration::validate|is_link($path)' => 1,
			'Administration_Configuration::validate|is_dir($canonical)' => 1,
		),
	),
);

$root            = dirname( __DIR__ );
$native          = 'src/Support/class-database-connection.php';
$writer          = 'src/Support/class-database-writer.php';
$files           = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
$failed          = false;
$boundary_actual = array(
	'native'       => array(),
	'adapter'      => array(),
	'constructors' => array(),
);
$adapter_methods = array( 'run', 'dispatch_ddl_once', 'prepare_native_sql', 'stream_native_sql_once', 'cancel_native_sql', 'abandon_native_sql_session', 'set_backup_sql_mode', 'quarantine_backup_session', 'begin_callback_journal', 'acquire_schema_write_lock', 'release_schema_write_lock' );
foreach ( $files as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}
	$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
	$metadata = array(
		'src/Abilities/class-native-option-write-abilities.php' => array( 'dbh', 'base_prefix', 'prefix', 'options', 'sitemeta', 'dbname' ),
		'src/Support/class-native-option-operation.php' => array( 'sitemeta' ),
		'src/Support/class-native-scheduler.php'        => array( 'actionscheduler_actions', 'prefix' ),
	);
	$typed    = in_array( $relative, array( $native, $writer, 'src/Support/class-database-control-storage.php', 'src/Support/class-administration-operation-journal.php', 'src/Support/class-database-schema-operation.php', 'src/Support/class-database-native-sql-operation.php', 'src/Support/class-backup-database.php' ), true ) || isset( $metadata[ $relative ] );
	$source   = file_get_contents( $file->getPathname() );
	$errors   = array_merge(
		wpai_database_write_boundary( $source, $native === $relative, $typed, $metadata[ $relative ] ?? array() ),
		wpai_database_extra_boundary( $source, $typed ),
		wpai_filesystem_boundary( $source, $relative, $boundary_pins )
	);
	foreach ( wpai_boundary_inventory( $source ) as $call ) {
		$kind = 'function' === $call['kind'] && 0 === strpos( $call['name'], 'mysqli_' ) ? 'native' :
			( 'method' === $call['kind'] && in_array( $call['name'], $adapter_methods, true ) ? 'adapter' :
				( 'new' === $call['kind'] && 'database_connection' === $call['name'] ? 'constructors' : '' ) );
		if ( '' !== $kind ) {
			$key = $call['scope'] . '|' . $call['name'];
			$boundary_actual[ $kind ][ $relative ][ $key ] = ( $boundary_actual[ $kind ][ $relative ][ $key ] ?? 0 ) + 1;
		}
	}
	foreach ( $errors as $error ) {
		fwrite( STDERR, $relative . ': ' . $error . "\n" );
		$failed = true;
	}
}
foreach ( array(
	array( '<?php mysqli_connect("host");', true, true ),
	array( '<?php new \mysqli();', true, true ),
	array( '<?php new PDO("dsn");', false, false ),
	array( '<?php mysqli_stmt_execute($statement);', false, false ),
	array( '<?php $wpdb->query("COMMIT");', true, true ),
	array( '<?php $db->ping();', true, true ),
	array( '<?php $wpdb->dbpassword;', true, true ),
) as $regression ) {
	if ( ! wpai_database_write_boundary( $regression[0], $regression[1], $regression[2] ) ) {
		fwrite( STDERR, "Native confinement negative regression escaped.\n" );
		$failed = true;
	}
}
if ( wpai_database_write_boundary( '<?php $wpdb->dbh; mysqli_stmt_execute($statement);', true, true ) ) {
	fwrite( STDERR, "Reviewed native adapter primitives were incorrectly rejected.\n" );
	$failed = true;
}
foreach ( array(
	'<?php $wpdb->query("UPDATE controls");',
	'<?php $wpdb->sitemeta();',
	'<?php $wpdb->sitemeta = "another_table";',
	'<?php $wpdb->sitemeta .= "suffix";',
	'<?php ++$wpdb->sitemeta;',
	'<?php $wpdb->sitemeta++;',
	'<?php $wpdb->dbpassword;',
	'<?php mysqli_query($handle, "UPDATE controls");',
	'<?php $db->select_db("other");',
) as $regression ) {
	if ( ! wpai_database_write_boundary( $regression, false, true, array( 'sitemeta' ) ) ) {
		fwrite( STDERR, "Read-only native context confinement regression escaped.\n" );
		$failed = true;
	}
}
if ( wpai_database_write_boundary( '<?php $table = $wpdb->sitemeta;', false, true, array( 'sitemeta' ) ) ) {
	fwrite( STDERR, "Reviewed read-only table context was incorrectly rejected.\n" );
	$failed = true;
}

foreach ( $boundary_actual as $kind => $owners ) {
	foreach ( array_unique( array_merge( array_keys( $owners ), array_keys( $boundary_pins[ $kind ] ) ) ) as $owner ) {
		foreach ( wpai_boundary_compare( $owners[ $owner ] ?? array(), $boundary_pins[ $kind ][ $owner ] ?? array(), $owner . ' ' . $kind ) as $error ) {
			fwrite( STDERR, $error . "\n" );
			$failed = true;
		}
	}
}
// Negative fixtures mutate complete valid owners, so mere missing-call counts
// cannot make an ineffective ownership/mode assertion appear discriminating.
$fs_fixtures = array(
	array( 'src/Support/class-unowned.php', '<?php ftruncate($stream,0);' ),
	array( 'src/Support/class-unowned.php', '<?php $fn="fopen"; $fn($path,"wb");' ),
	array( 'src/Support/class-unowned.php', '<?php call_user_func("unlink",$path);' ),
	array( 'src/Support/class-unowned.php', '<?php new SplFileObject($path,"wb");' ),
	array( 'src/Support/class-unowned.php', '<?php eval($input);' ),
);
foreach ( array(
	'src/Support/class-scoped-filesystem.php'        => array( "'rb'", "'wb'" ),
	'src/Support/class-private-transfer-session.php' => array( "fopen( \$path, 'rb' )", "fopen( \$caller_path, 'rb' )" ),
	'src/Support/class-backup-operation.php'         => array( "'php://memory'", '$caller_path' ),
) as $owner => $mutation ) {
	$original = file_get_contents( $root . '/' . $owner );
	$mutated  = str_replace( $mutation[0], $mutation[1], $original, $replacements );
	if ( $replacements < 1 || wpai_filesystem_boundary( $original, $owner, $boundary_pins ) ) {
		fwrite( STDERR, 'Invalid filesystem negative fixture baseline: ' . $owner . "\n" );
		$failed = true;
	}
	$fs_fixtures[] = array( $owner, $mutated );
}
$config_owner  = 'src/Support/class-administration-configuration.php';
$fs_fixtures[] = array( $config_owner, file_get_contents( $root . '/' . $config_owner ) . "\nfunction wpai_probe(){file_get_contents(\$path);}\n" );
$fs_owner      = 'src/Support/class-scoped-filesystem.php';
$baseline_fs   = file_get_contents( $root . '/' . $fs_owner );
$moved_call    = str_replace( 'fwrite( $file, substr( $bytes, $done ) )', 'strlen( $bytes )', $baseline_fs, $removed );
$moved_call    = str_replace( 'final class Scoped_Filesystem {', 'final class Scoped_Filesystem { public function wpai_probe($file,$bytes,$done){fwrite($file,substr($bytes,$done));}', $moved_call, $inserted );
if ( 1 !== $removed || 1 !== $inserted ) {
	fwrite( STDERR, "Invalid method-ownership negative fixture baseline.\n" );
	$failed = true;
}
$fs_fixtures[] = array( $fs_owner, $moved_call );
foreach ( $fs_fixtures as $fixture ) {
	if ( ! wpai_filesystem_boundary( $fixture[1], $fixture[0], $boundary_pins ) ) {
		fwrite( STDERR, "Filesystem owner/mode negative regression escaped.\n" );
		$failed = true;
	}
}
foreach ( array(
	'<?php use PDO as OtherConnection; new OtherConnection("dsn");',
	'<?php new SQLite3("database");',
	'<?php pg_connect("dsn");',
	'<?php $this->wpdb->dbpassword;',
	'<?php $password=DB_PASSWORD;',
	'<?php $GLOBALS["wpdb"]->query("COMMIT");',
	'<?php $handle->query("COMMIT");',
	'<?php $reference =& $wpdb->sitemeta;',
	'<?php unset($wpdb->sitemeta);',
	'<?php $fn="mysqli_query";$fn($handle,$sql);',
	'<?php call_user_func("mysqli_query",$handle,$sql);',
) as $fixture ) {
	if ( ! wpai_database_extra_boundary( $fixture, true ) ) {
		fwrite( STDERR, "Connection/credential/native alias negative regression escaped.\n" );
		$failed = true;
	}
}
if ( ! wpai_database_write_boundary( '<?php namespace\mysqli_stmt_execute($statement);', false, false ) ) {
	fwrite( STDERR, "Qualified native function negative regression escaped.\n" );
	$failed = true;
}
$native_probe = wpai_boundary_inventory( file_get_contents( $root . '/' . $native ) . "\nfunction wpai_probe(){mysqli_query(\$handle,\$sql);}\n" );
$native_calls = array();
foreach ( $native_probe as $call ) {
	if ( 'function' === $call['kind'] && 0 === strpos( $call['name'], 'mysqli_' ) ) {
		$key                  = $call['scope'] . '|' . $call['name'];
		$native_calls[ $key ] = ( $native_calls[ $key ] ?? 0 ) + 1;
	}
}
if ( ! wpai_boundary_compare( $native_calls, $boundary_pins['native'][ $native ], 'native method probe' ) ) {
	fwrite( STDERR, "Native function ownership regression escaped.\n" );
	$failed = true;
}
$unowned_adapter = wpai_boundary_inventory( '<?php class Unowned { function effect(){ $db->dispatch_ddl_once($sql); } }' );
$key             = $unowned_adapter[0]['scope'] . '|' . $unowned_adapter[0]['name'];
if ( ! wpai_boundary_compare( array( $key => 1 ), array(), 'unowned native adapter' ) ) {
	fwrite( STDERR, "Unowned adapter negative regression escaped.\n" );
	$failed = true;
}

if ( $failed ) {
	exit( 1 );
}
echo "PASS: exact native SQL/filesystem owners, typed context and discriminating negative regressions.\n";
