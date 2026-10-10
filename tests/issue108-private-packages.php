<?php
/**
 * Issue #108: private ZIP authorization, client isolation, and inert archive review.
 *
 * @package WP_AI_Bridge
 */
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Private_Package_Abilities;
use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Private_Package_Store;
use WP_AI_Bridge\Support\Settings;

$GLOBALS['wpai108_blog'] = 1;
function get_current_blog_id() {
	return $GLOBALS['wpai108_blog']; }
function wp_max_upload_size() {
	return 67108864; }

$count = 0;
function wpai108_assert( $condition, $message ) {
	global $count;
	++$count;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

wpai_test_reset_state();
$settings = new Settings();
$provider = new Private_Package_Abilities( new Permissions( $settings ) );
$store    = new Private_Package_Store( new Permissions( $settings ) );
$client   = OAuth_Server::CHATGPT_CLIENT_ID;
$id       = str_repeat( 'a', 48 );
$sha      = str_repeat( 'b', 64 );
$ref      = new ReflectionProperty( OAuth_Server::class, 'authenticated_mcp_client_id' );

$registered = $provider->register();
wpai108_assert( 2 === count( $registered ), 'Two focused package Abilities must be registered.' );
$read_schema    = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/private-packages-read'];
$install_schema = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/private-package-install'];
wpai108_assert( true === $read_schema['meta']['annotations']['readonly'], 'Inspector must be read-only.' );
wpai108_assert( true === $install_schema['meta']['annotations']['destructive'] && false === $install_schema['meta']['annotations']['idempotent'], 'Install must be destructive and non-idempotent.' );
wpai108_assert( array( 'artifact_id', 'sha256', 'kind' ) === $install_schema['input_schema']['required'], 'An exact hash-bound install request is mandatory.' );
wpai108_assert( false === $install_schema['input_schema']['additionalProperties'], 'Unexpected install parameters are forbidden.' );
wpai108_assert( ! $provider->can_read(), 'Missing MCP context cannot enumerate staged packages.' );
wpai108_assert( ! $provider->can_install( array( 'kind' => 'plugin' ) ), 'Missing MCP context cannot install packages.' );

$ref->setValue( null, $client );
wpai108_assert( ! $provider->can_read(), 'Client identity alone must never enable packages on existing sites.' );
$grants                                    = $settings->defaults();
$grants[ Settings::GROUP_CODE_EXTENSIONS ] = 1;
update_option( Settings::OPTION_NAME, $grants, false );
$GLOBALS['wpai_test']['capabilities']['install_plugins'] = true;
wpai108_assert( ! $provider->can_read(), 'Code & Extensions alone must not allow private packages.' );
$grants[ Settings::GROUP_EXTERNAL_PACKAGES ] = 1;
update_option( Settings::OPTION_NAME, $grants, false );
wpai108_assert( $provider->can_read(), 'Both grants and native authority must enable read.' );
wpai108_assert( $provider->can_install( array( 'kind' => 'plugin' ) ), 'Explicit plugin install authority should be available.' );
wpai108_assert( ! $provider->can_install( array( 'kind' => 'theme' ) ), 'Plugin install must not imply theme authority.' );

$metadata = array(
	'id'              => $id,
	'kind'            => 'plugin',
	'user_id'         => 1,
	'blog_id'         => 1,
	'client_id'       => $client,
	'client_revision' => 0,
	'sha256'          => $sha,
	'filename'        => 'hello.zip',
	'bytes'           => 800,
	'root'            => 'hello',
	'created'         => time(),
	'expires'         => time() + 3600,
	'status'          => 'staged',
);
update_option( Private_Package_Store::OPTION_PREFIX . $id, $metadata, false );
$result = $provider->read(
	array(
		'action'      => 'get',
		'artifact_id' => $id,
	)
);
wpai108_assert( ! is_wp_error( $result ) && $result['item']['sha256'] === $sha, 'Same principal/client/site must inspect only bounded metadata.' );
wpai108_assert( ! str_contains( json_encode( $result ), sys_get_temp_dir() ), 'Private filesystem paths must not be returned.' );
wpai108_assert(
	is_wp_error(
		$provider->install(
			array(
				'artifact_id' => $id,
				'sha256'      => str_repeat( 'c', 64 ),
				'kind'        => 'plugin',
			)
		)
	),
	'Wrong hash must deny before Core execution.'
);
wpai108_assert(
	is_wp_error(
		$provider->read(
			array(
				'action'      => 'get',
				'artifact_id' => '../' . $id,
			)
		)
	),
	'Traversal input must not reach package lookup.'
);
wpai108_assert(
	is_wp_error(
		$provider->read(
			array(
				'action'       => 'get',
				'artifact_id'  => $id,
				'action_extra' => 'x',
			)
		)
	),
	'Unknown read fields must fail closed.'
);

$GLOBALS['wpai_test']['user_id'] = 2;
wpai108_assert(
	is_wp_error(
		$provider->read(
			array(
				'action'      => 'get',
				'artifact_id' => $id,
			)
		)
	),
	'Another WordPress user must not inspect staged packages.'
);
$GLOBALS['wpai_test']['user_id'] = 1;
$GLOBALS['wpai108_blog']         = 2;
wpai108_assert(
	is_wp_error(
		$provider->read(
			array(
				'action'      => 'get',
				'artifact_id' => $id,
			)
		)
	),
	'Multisite blog boundary must be enforced.'
);
$GLOBALS['wpai108_blog'] = 1;
$ref->setValue( null, 'https://another.example.test/client.json' );
wpai108_assert(
	is_wp_error(
		$provider->read(
			array(
				'action'      => 'get',
				'artifact_id' => $id,
			)
		)
	),
	'Unapproved OAuth client must be denied.'
);
$ref->setValue( null, '' );

$grants[ Settings::GROUP_EXTERNAL_PACKAGES ] = 0;
update_option( Settings::OPTION_NAME, $grants, false );
wpai108_assert( ! $provider->can_read(), 'Revoked package grant must take effect immediately.' );

wpai108_assert( is_wp_error( $store->inspect_zip( '/missing/private.zip', 'plugin' ) ), 'Missing archive must not be inspected or executed.' );
if ( class_exists( 'ZipArchive' ) ) {
	$path = tempnam( sys_get_temp_dir(), 'wpai108-test-' );
	$zip  = new ZipArchive();
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addEmptyDir( 'safe-plugin' );
	$zip->addFromString( 'safe-plugin/main.php', "<?php\n/*\nPlugin Name: Safe Test Plugin\n*/\n" );
	$zip->close();
	$review = $store->inspect_zip( $path, 'plugin' );
	wpai108_assert( ! is_wp_error( $review ) && 'safe-plugin' === $review['root'], 'Ordinary ZIP including its root directory must pass safe review.' );
	wpai108_assert( is_wp_error( $store->inspect_zip( $path, 'theme' ) ), 'A plugin archive must not masquerade as a theme.' );
	unlink( $path );
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'evil-plugin/../escape.php', "<?php\n/* Plugin Name: Unsafe */" );
	$zip->addFromString( 'evil-plugin/main.php', "<?php\n/*\nPlugin Name: Unsafe\n*/" );
	$zip->close();
	wpai108_assert( is_wp_error( $store->inspect_zip( $path, 'plugin' ) ), 'ZIP traversal must be denied.' );
	unlink( $path );
	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addEmptyDir( 'safe-theme' );
	$zip->addFromString( 'safe-theme/style.css', "/*\nTheme Name: Safe Test Theme\n*/" );
	$zip->addFromString( 'safe-theme/templates/index.html', '<!-- wp:paragraph -->Hello<!-- /wp:paragraph -->' );
	$zip->close();
	wpai108_assert( ! is_wp_error( $store->inspect_zip( $path, 'theme' ) ), 'A bounded block-theme ZIP must pass structural inspection.' );
	unlink( $path );

	// Keep adversarial ZIP tests in the reproducible repository suite rather than
	// relying on an ephemeral one-off local probe.
	$plugin_header = "<?php\n/*\nPlugin Name: Test ZIP\n*/\n";
	$bad_archives  = array(
		'case-folded sibling' => array(
			'safe-plugin/main.php'   => $plugin_header,
			'safe-plugin/readme.txt' => 'first',
			'safe-plugin/README.txt' => 'second',
		),
		'file before child'   => array(
			'safe-plugin/main.php'       => $plugin_header,
			'safe-plugin/part'           => 'file',
			'safe-plugin/part/child.txt' => 'child',
		),
		'child before file'   => array(
			'safe-plugin/main.php'       => $plugin_header,
			'safe-plugin/part/child.txt' => 'child',
			'safe-plugin/part'           => 'file',
		),
		'multiple roots'      => array(
			'safe-plugin/main.php' => $plugin_header,
			'other/file.txt'       => 'unexpected',
		),
		'compression bomb'    => array(
			'safe-plugin/main.php' => $plugin_header,
			'safe-plugin/blob.txt' => str_repeat( 'A', 524288 ),
		),
	);
	foreach ( $bad_archives as $label => $entries ) {
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		foreach ( $entries as $entry_name => $contents ) {
			$zip->addFromString( $entry_name, $contents );
		}
		$zip->close();
		wpai108_assert( is_wp_error( $store->inspect_zip( $path, 'plugin' ) ), 'Untrusted ZIP must be denied: ' . $label );
		unlink( $path );
	}

	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'safe-plugin/main.php', $plugin_header );
	$zip->addFromString( 'safe-plugin/link', 'unsafe' );
	wpai108_assert( $zip->setExternalAttributesName( 'safe-plugin/link', ZipArchive::OPSYS_UNIX, 0120777 << 16 ), 'ZIP fixture could not encode a symbolic link.' );
	$zip->close();
	wpai108_assert( is_wp_error( $store->inspect_zip( $path, 'plugin' ) ), 'UNIX symbolic link ZIP entries must be denied.' );
	unlink( $path );

	$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
	$zip->addFromString( 'safe-plugin/main.php', $plugin_header );
	$zip->close();
	$bytes = file_get_contents( $path );
	file_put_contents( $path, substr( $bytes, 0, intdiv( strlen( $bytes ), 2 ) ) );
	wpai108_assert( is_wp_error( $store->inspect_zip( $path, 'plugin' ) ), 'Incomplete/truncated ZIPs must be rejected.' );
	unlink( $path );

	if ( method_exists( ZipArchive::class, 'setEncryptionName' ) ) {
		$zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$zip->addFromString( 'safe-plugin/main.php', $plugin_header );
		wpai108_assert( $zip->setEncryptionName( 'safe-plugin/main.php', ZipArchive::EM_AES_256, 'fixture-only-password' ), 'ZIP fixture could not encode an encrypted entry.' );
		$zip->close();
		wpai108_assert( is_wp_error( $store->inspect_zip( $path, 'plugin' ) ), 'Encrypted/unreadable plugin ZIP entries must be denied.' );
		unlink( $path );
	}
}
echo 'PASS: Issue #108 private package contract (' . $count . " assertions).\n";
