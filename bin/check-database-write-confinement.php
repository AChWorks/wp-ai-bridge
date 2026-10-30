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
		$name = strtolower( ltrim( $token[1], '\\' ) );
		$next = $tokens[ $index + 1 ] ?? null;
		$prev = $tokens[ $index - 1 ] ?? null;
		if ( in_array( $token[0], array( T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED ), true ) ) {
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

$root   = dirname( __DIR__ );
$native = 'src/Support/class-database-connection.php';
$writer = 'src/Support/class-database-writer.php';
$files  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
$failed = false;
foreach ( $files as $file ) {
	if ( 'php' !== $file->getExtension() ) {
		continue;
	}
	$relative = substr( $file->getPathname(), strlen( $root ) + 1 );
	$metadata = array(
		'src/Abilities/class-native-option-write-abilities.php' => array( 'dbh', 'base_prefix', 'prefix', 'options', 'sitemeta', 'dbname' ),
		'src/Support/class-native-option-operation.php' => array( 'sitemeta' ),
	);
	$typed = in_array( $relative, array( $native, $writer, 'src/Support/class-database-control-storage.php' ), true ) || isset( $metadata[ $relative ] );
	$errors = wpai_database_write_boundary( file_get_contents( $file->getPathname() ), $native === $relative, $typed, $metadata[ $relative ] ?? array() );
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
if ( $failed ) {
	exit( 1 );
}
echo "PASS: typed database native-handle confinement and negative regressions.\n";
