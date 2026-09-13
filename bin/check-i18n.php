<?php
/**
 * Validates the bundled Persian localization catalog and source coverage.
 *
 * @package WP_Native_Builder_Bridge
 */

$root    = dirname( __DIR__ );
$catalog = $root . '/languages/wp-native-builder-bridge-fa_IR.l10n.php';

if ( ! is_file( $catalog ) ) {
	fwrite( STDERR, "ERROR: Persian localization catalog is missing.\n" );
	exit( 1 );
}

$data = require $catalog;
if ( ! is_array( $data ) || 'fa_IR' !== ( $data['language'] ?? '' ) || ! is_array( $data['messages'] ?? null ) ) {
	fwrite( STDERR, "ERROR: Persian localization catalog metadata is invalid.\n" );
	exit( 1 );
}

$messages = $data['messages'];
foreach ( $messages as $source => $translation ) {
	if ( '' === trim( (string) $source ) || '' === trim( (string) $translation ) ) {
		fwrite( STDERR, "ERROR: Persian catalog contains an empty source or translation.\n" );
		exit( 1 );
	}
}

$required = array(
	'Direct ChatGPT App' => 'اتصال مستقیم به ChatGPT App',
	'Authorize ChatGPT'  => 'تأیید دسترسی ChatGPT',
	'Access groups'      => 'گروه‌های دسترسی',
	'Live Content'       => 'محتوای زنده',
	'Source Editing'      => 'ویرایش کد منبع',
	'Read Content'       => 'خواندن محتوا',
);
foreach ( $required as $source => $translation ) {
	if ( $translation !== ( $messages[ $source ] ?? null ) ) {
		fwrite( STDERR, 'ERROR: required Persian translation mismatch: ' . $source . "\n" );
		exit( 1 );
	}
}

/**
 * Decodes a PHP string-literal token without evaluating repository source.
 *
 * @param string $literal T_CONSTANT_ENCAPSED_STRING token text.
 * @return string
 */
function wpnb_i18n_decode_literal( $literal ) {
	$quote = substr( $literal, 0, 1 );
	$body  = substr( $literal, 1, -1 );
	if ( "'" === $quote ) {
		return str_replace( array( "\\'", '\\\\' ), array( "'", '\\' ), $body );
	}

	return stripcslashes( $body );
}

/**
 * Returns literal top-level arguments from one function call.
 *
 * @param array<int,mixed> $tokens     PHP tokens.
 * @param int              $open_index Index of opening parenthesis.
 * @return array<int,string>
 */
function wpnb_i18n_literal_arguments( $tokens, $open_index ) {
	$arguments = array();
	$argument  = 0;
	$depth     = 1;
	$count     = count( $tokens );

	for ( $index = $open_index + 1; $index < $count; $index++ ) {
		$token = $tokens[ $index ];
		if ( '(' === $token || '[' === $token || '{' === $token ) {
			$depth++;
			continue;
		}
		if ( ')' === $token || ']' === $token || '}' === $token ) {
			$depth--;
			if ( 0 === $depth ) {
				break;
			}
			continue;
		}
		if ( 1 === $depth && ',' === $token ) {
			$argument++;
			continue;
		}
		if ( 1 === $depth && is_array( $token ) && T_CONSTANT_ENCAPSED_STRING === $token[0] && ! isset( $arguments[ $argument ] ) ) {
			$arguments[ $argument ] = wpnb_i18n_decode_literal( $token[1] );
		}
	}

	return $arguments;
}

$translation_functions = array(
	'__'           => 1,
	'_e'           => 1,
	'_x'           => 1,
	'_ex'          => 1,
	'_n'           => 2,
	'_nx'          => 2,
	'esc_html__'   => 1,
	'esc_html_e'   => 1,
	'esc_attr__'   => 1,
	'esc_attr_e'   => 1,
);
$source_files          = array( $root . '/wp-native-builder-bridge.php' );
$iterator              = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
foreach ( $iterator as $file ) {
	if ( $file->isFile() && 'php' === strtolower( $file->getExtension() ) ) {
		$source_files[] = $file->getPathname();
	}
}

$source_messages = array();
foreach ( $source_files as $file ) {
	$tokens = token_get_all( file_get_contents( $file ) );
	$count  = count( $tokens );
	for ( $index = 0; $index < $count; $index++ ) {
		$token = $tokens[ $index ];
		if ( ! is_array( $token ) || T_STRING !== $token[0] || ! isset( $translation_functions[ $token[1] ] ) ) {
			continue;
		}

		$open_index = $index + 1;
		while ( $open_index < $count && is_array( $tokens[ $open_index ] ) && in_array( $tokens[ $open_index ][0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true ) ) {
			$open_index++;
		}
		if ( $open_index >= $count || '(' !== $tokens[ $open_index ] ) {
			continue;
		}

		$arguments = wpnb_i18n_literal_arguments( $tokens, $open_index );
		$required_arguments = $translation_functions[ $token[1] ];
		for ( $argument = 0; $argument < $required_arguments; $argument++ ) {
			if ( isset( $arguments[ $argument ] ) && '' !== trim( $arguments[ $argument ] ) ) {
				$source_messages[ $arguments[ $argument ] ] = true;
			}
		}
	}
}

$missing = array_values( array_diff( array_keys( $source_messages ), array_keys( $messages ) ) );
if ( $missing ) {
	fwrite( STDERR, "ERROR: Persian catalog is missing source strings:\n - " . implode( "\n - ", $missing ) . "\n" );
	exit( 1 );
}

echo sprintf(
	"PASS: bundled Persian catalog contains %d non-empty translations and covers %d literal production gettext strings.\n",
	count( $messages ),
	count( $source_messages )
);