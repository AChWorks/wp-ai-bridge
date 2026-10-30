<?php
/**
 * Actual multisite database execution and isolated delegation-configuration tests.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Abilities\Database_Abilities;
use WP_AI_Bridge\Admin\Settings_Page;
use WP_AI_Bridge\Admin\Settings_Save;
use WP_AI_Bridge\Support\Environment;
use WP_AI_Bridge\Support\Mutation_Log;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

function wpai199ms_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function wpai199ms_restore_option( $name, $original ) {
	if ( false === $original ) {
		delete_option( $name );
		return;
	}
	$restore = static function () use ( $original ) { return $original; };
	add_filter( 'pre_update_option_' . $name, $restore, PHP_INT_MAX );
	try {
		update_option( $name, $original, false );
	} finally {
		remove_filter( 'pre_update_option_' . $name, $restore, PHP_INT_MAX );
	}
}
function wpai199ms_expect_denied( $callback ) {
	$denied = false;
	$handler = static function () {
		return static function ( $message, $title = '', $args = array() ) {
			throw new RuntimeException( 'wpai199ms-denied-' . ( is_array( $args ) ? ( $args['response'] ?? 500 ) : 500 ) );
		};
	};
	add_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
	try {
		$callback();
	} catch ( RuntimeException $error ) {
		if ( 'wpai199ms-denied-403' !== $error->getMessage() ) {
			throw $error;
		}
		$denied = true;
	} finally {
		remove_filter( 'wp_die_handler', $handler, PHP_INT_MAX );
	}
	wpai199ms_assert( $denied, 'Unauthorized protected-option save did not terminate with HTTP 403 before persistence.' );
}
function wpai199ms_render_controls( Settings $settings ) {
	$page = new Settings_Page( new Environment(), $settings );
	ob_start();
	try {
		$page->render_settings();
		$html = ob_get_contents();
	} finally {
		ob_end_clean();
	}
	wpai199ms_assert( 1 === preg_match( '~<form\\b[^>]*\\bid="wpai-settings-form"[^>]*>(.*?)</form>~s', $html, $form ), 'Ordinary and database grants must share the unified native settings form.' );
	$controls = array();
	$context = array();
	$processor = new WP_HTML_Tag_Processor( $form[1] );
	$values = $settings->all();
	while ( $processor->next_tag( 'INPUT' ) ) {
		$name = $processor->get_attribute( 'name' );
		if ( in_array( $name, array( 'action', '_wpnonce', 'expected_revision', 'blog_id', 'network_id', 'user_id' ), true ) ) {
			$context[ $name ][] = $processor->get_attribute( 'value' );
		}
		if ( 'checkbox' !== $processor->get_attribute( 'type' ) ) {
			continue;
		}
		foreach ( array( Settings::GROUP_DATABASE_INSPECTION, Settings::GROUP_DATABASE_READ, Settings::GROUP_SITE_READ ) as $key ) {
			if ( 'wpai-grant-' . $key !== $processor->get_attribute( 'id' ) ) {
				continue;
			}
			$option = Settings::GROUP_SITE_READ === $key ? Settings::OPTION_NAME : Settings::DATABASE_OPTION_NAME;
			$disabled = null !== $processor->get_attribute( 'disabled' );
			$controls[ $key ] = $disabled;
			wpai199ms_assert( $disabled ? ( null === $name || $option . '[' . $key . ']' === $name ) : $option . '[' . $key . ']' === $name, 'The grant input lost its native option name or became actionable without authority.' );
			wpai199ms_assert( ( ! empty( $values[ $key ] ) ) === ( null !== $processor->get_attribute( 'checked' ) ), 'The visible grant does not reflect its current saved state.' );
		}
	}
	wpai199ms_assert( 3 === count( $controls ), 'Native Settings page omitted database/ordinary grant controls.' );
	$save = new Settings_Save( $settings );
	$snapshot = $save->snapshot();
	foreach ( array( 'action' => Settings_Save::ACTION, 'expected_revision' => $snapshot['revision'], 'blog_id' => (string) get_current_blog_id(), 'network_id' => (string) get_current_network_id(), 'user_id' => (string) get_current_user_id() ) as $field => $expected ) {
		wpai199ms_assert( array( $expected ) === ( $context[ $field ] ?? array() ), 'The unified database settings form lost its unique current-context field: ' . $field );
	}
	wpai199ms_assert( 1 === count( $context['_wpnonce'] ?? array() ) && wp_verify_nonce( $context['_wpnonce'][0], $save->nonce_action() ), 'Unified database settings lost its contextual native nonce.' );
	wpai199ms_assert( Settings::OPTION_NAME !== Settings::DATABASE_OPTION_NAME && Settings::OPTION_GROUP !== Settings::DATABASE_OPTION_GROUP, 'Unified presentation must retain separate native grant stores and registered groups.' );
	return array( $controls, $html );
}

$original_user = get_current_user_id();
$original_blog = get_current_blog_id();
$main_original = get_option( Settings::OPTION_NAME, false );
$main_database = get_option( Settings::DATABASE_OPTION_NAME, false );
$secondary = 0;
$created_secondary = false;
$secondary_original = false;
$secondary_database = false;
$switched = false;
$site_admin = 0;
$deny_network = null;
$interleave = null;

try {
	wpai199ms_assert( is_multisite() && is_super_admin(), 'Database multisite fixture requires its synthetic Super Admin.' );
	$settings = new Settings();
	wpai199ms_assert( false !== has_filter( 'sanitize_option_' . Settings::DATABASE_OPTION_NAME ), 'Protected option write guard was not installed before native admin settings registration.' );
	wpai199ms_assert( false !== has_filter( 'option_page_capability_' . Settings::DATABASE_OPTION_GROUP ), 'Protected form capability was not installed on bootstrap.' );
	$settings->register();
	$provider = new Database_Abilities( new Permissions( $settings ), new Mutation_Log() );
	$inspect = wp_get_ability( 'wp-ai-bridge/database-inspect' );
	wpai199ms_assert( $inspect instanceof WP_Ability, 'Database inspection not registered on multisite.' );
	$grants = array_replace( $settings->database_defaults(), array( Settings::GROUP_DATABASE_INSPECTION => 1, Settings::GROUP_DATABASE_READ => 1 ) );
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	wpai199ms_assert( $provider->can_read(), 'Real Super Admin/network grants did not enable database reads.' );
	wpai199ms_assert( 0 === get_option( Settings::DATABASE_OPTION_NAME )[ Settings::GROUP_DATABASE_WRITE ] && 0 === get_option( Settings::DATABASE_OPTION_NAME )[ Settings::GROUP_DATABASE_DELETE ], 'Read-only dedicated grants unexpectedly enabled typed writes/deletion.' );
	wpai199ms_assert( ! is_wp_error( $inspect->execute( array( 'action' => 'tables', 'limit' => 1 ) ) ), 'Super Admin cannot inspect the installation database.' );
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 20 ) ) as $blog_id ) {
		if ( (int) $blog_id !== (int) $original_blog ) {
			$secondary = (int) $blog_id;
			break;
		}
	}
	if ( ! $secondary ) {
		$network = get_network();
		$secondary = wpmu_create_blog( $network->domain, trailingslashit( $network->path ) . 'wpai199-' . strtolower( wp_generate_password( 8, false, false ) ) . '/', 'Database administration test', $original_user, array(), $network->id );
		wpai199ms_assert( ! is_wp_error( $secondary ) && $secondary > 0, 'Cannot create a second synthetic site.' );
		$created_secondary = true;
	}
	switch_to_blog( $secondary );
	$switched = true;
	$secondary_original = get_option( Settings::OPTION_NAME, false );
	$secondary_database = get_option( Settings::DATABASE_OPTION_NAME, false );
	update_option( Settings::OPTION_NAME, $settings->defaults(), false );
	update_option( Settings::DATABASE_OPTION_NAME, $settings->database_defaults(), false );
	wpai199ms_assert( ! $provider->can_read() && is_wp_error( $inspect->execute( array( 'action' => 'tables' ) ) ), 'Main-blog database grants leaked to another blog.' );
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	wpai199ms_assert( $provider->can_read(), 'Super Admin cannot use an explicitly granted secondary site.' );
	$suffix = wp_generate_password( 10, false, false );
	$site_admin = wp_insert_user( array( 'user_login' => 'wpai199ms-' . $suffix, 'user_pass' => wp_generate_password( 32 ), 'user_email' => 'wpai199ms-' . $suffix . '@example.invalid' ) );
	wpai199ms_assert( ! is_wp_error( $site_admin ), 'Cannot create local administrator fixture.' );
	add_user_to_blog( $secondary, $site_admin, 'administrator' );
	update_option( Settings::DATABASE_OPTION_NAME, $settings->database_defaults(), false );
	wp_set_current_user( $site_admin );
	wpai199ms_assert( current_user_can( 'manage_options' ) && ! is_super_admin(), 'Local administrator fixture has wrong native authority.' );
	wpai199ms_assert( ! $settings->can_configure_group( Settings::GROUP_DATABASE_READ ) && $settings->can_configure_group( Settings::GROUP_SITE_READ ), 'Database and ordinary configuration authority were conflated.' );
	$capability = apply_filters( 'option_page_capability_' . Settings::DATABASE_OPTION_GROUP, 'manage_options' );
	wpai199ms_assert( is_string( $capability ) && ! current_user_can( $capability ), 'Native protected-group capability authorized a local site administrator.' );
	$local_input = array( Settings::GROUP_DATABASE_INSPECTION => 1, Settings::GROUP_DATABASE_READ => 1, Settings::GROUP_SITE_READ => 0, Settings::GROUP_BUILDER_WRITE => 1 );
	$ordinary = sanitize_option( Settings::OPTION_NAME, $local_input );
	wpai199ms_assert( ! isset( $ordinary[ Settings::GROUP_DATABASE_INSPECTION ], $ordinary[ Settings::GROUP_DATABASE_READ ] ), 'Ordinary native sanitizer retained protected database fields.' );
	update_option( Settings::OPTION_NAME, $local_input, false );
	wpai199ms_assert( 0 === $settings->all()[ Settings::GROUP_DATABASE_READ ] && 1 === get_option( Settings::OPTION_NAME )[ Settings::GROUP_BUILDER_WRITE ], 'Ordinary site save expanded database grants or failed to save ordinary fields.' );
	wpai199ms_expect_denied( static function () use ( $grants ) { update_option( Settings::DATABASE_OPTION_NAME, $grants, false ); } );
	wpai199ms_assert( $settings->database_defaults() === get_option( Settings::DATABASE_OPTION_NAME ), 'Unauthorized native database save changed its dedicated row.' );
	list( $local_controls, $local_html ) = wpai199ms_render_controls( $settings );
	$explanation = __( 'Changing this grant requires current administrator authority and, on multisite, actual Super Admin and network authority.', 'wp-ai-bridge' );
	wpai199ms_assert( $local_controls[ Settings::GROUP_DATABASE_INSPECTION ] && $local_controls[ Settings::GROUP_DATABASE_READ ] && ! $local_controls[ Settings::GROUP_SITE_READ ] && str_contains( $local_html, esc_html( $explanation ) ), 'Local Settings UI did not disable/explain database controls while retaining ordinary fields.' );
	wpai199ms_assert( ! $provider->can_read() && is_wp_error( $inspect->execute( array( 'action' => 'schema', 'table' => $GLOBALS['wpdb']->base_prefix . 'users' ) ) ), 'Local site administrator gained network database execution authority.' );

	wp_set_current_user( $original_user );
	update_option( Settings::DATABASE_OPTION_NAME, $grants, false );
	wpai199ms_assert( $provider->can_read() && $grants === get_option( Settings::DATABASE_OPTION_NAME ), 'Authorized Super Admin cannot enable dedicated database grants.' );
	$capability = apply_filters( 'option_page_capability_' . Settings::DATABASE_OPTION_GROUP, 'manage_options' );
	wpai199ms_assert( current_user_can( $capability ), 'Native protected-group capability rejected an authorized Super Admin.' );
	list( $super_controls, $super_html ) = wpai199ms_render_controls( $settings );
	wpai199ms_assert( ! $super_controls[ Settings::GROUP_DATABASE_INSPECTION ] && ! $super_controls[ Settings::GROUP_DATABASE_READ ] && ! str_contains( $super_html, esc_html( $explanation ) ), 'Authorized Super Admin retained disabled database controls.' );
	wp_set_current_user( $site_admin );
	foreach ( array(
		array( Settings::GROUP_DATABASE_INSPECTION => 0, Settings::GROUP_DATABASE_READ => 0, Settings::GROUP_SITE_READ => 1 ),
		array( Settings::GROUP_SITE_READ => 0, Settings::GROUP_BUILDER_WRITE => 1 ),
	) as $ordinary_save ) {
		update_option( Settings::OPTION_NAME, $ordinary_save, false );
		wpai199ms_assert( $grants === get_option( Settings::DATABASE_OPTION_NAME ), 'An ordinary save wrote the independent database grant row.' );
	}

	// Reproduce the review finding at the real native post-sanitization boundary.
	// The privileged update must survive completion of the older local save.
	foreach ( array( $settings->database_defaults(), $grants ) as $fresh_grants ) {
		$calls = 0;
		$interleave = static function ( $value ) use ( $original_user, $site_admin, $fresh_grants, &$calls ) {
			++$calls;
			wpai199ms_assert( ! array_key_exists( Settings::GROUP_DATABASE_INSPECTION, $value ) && ! array_key_exists( Settings::GROUP_DATABASE_READ, $value ), 'Outer ordinary save still carried stale protected grant bits.' );
			wp_set_current_user( $original_user );
			try {
				update_option( Settings::DATABASE_OPTION_NAME, $fresh_grants, false );
			} finally {
				wp_set_current_user( $site_admin );
			}
			return $value;
		};
		add_filter( 'pre_update_option_' . Settings::OPTION_NAME, $interleave );
		update_option( Settings::OPTION_NAME, array( Settings::GROUP_DATABASE_INSPECTION => 1, Settings::GROUP_DATABASE_READ => 1, Settings::GROUP_SITE_READ => 1, Settings::GROUP_BUILDER_WRITE => 0 ), false );
		remove_filter( 'pre_update_option_' . Settings::OPTION_NAME, $interleave );
		$interleave = null;
		wpai199ms_assert( 1 === $calls && $fresh_grants === get_option( Settings::DATABASE_OPTION_NAME ), 'Completion of an older ordinary save resurrected/revoked a concurrently changed database grant.' );
	}
	wp_set_current_user( $original_user );
	$deny_network = static function ( $caps, $cap ) { return 'manage_network_options' === $cap ? array( 'do_not_allow' ) : $caps; };
	add_filter( 'map_meta_cap', $deny_network, 999, 2 );
	wpai199ms_assert( ! $provider->can_read() && ! $settings->can_configure_group( Settings::GROUP_DATABASE_READ ), 'Explicit native network denial was ignored.' );
	wpai199ms_expect_denied( static function () use ( $settings ) { update_option( Settings::DATABASE_OPTION_NAME, $settings->database_defaults(), false ); } );
	wpai199ms_assert( $grants === get_option( Settings::DATABASE_OPTION_NAME ), 'Mapped network denial permitted a protected grant-row write.' );
	remove_filter( 'map_meta_cap', $deny_network, 999 );
	$deny_network = null;
	update_option( Settings::DATABASE_OPTION_NAME, $settings->database_defaults(), false );
	wpai199ms_assert( ! $provider->can_read() && $settings->database_defaults() === get_option( Settings::DATABASE_OPTION_NAME ), 'Authorized Super Admin could not revoke both dedicated database grants.' );
	echo "PASS: Issue #199 multisite isolated delegation, native authority, UI and concurrent-save boundaries.\n";
} finally {
	wp_set_current_user( $original_user );
	if ( null !== $interleave ) {
		remove_filter( 'pre_update_option_' . Settings::OPTION_NAME, $interleave );
	}
	if ( null !== $deny_network ) {
		remove_filter( 'map_meta_cap', $deny_network, 999 );
	}
	if ( $switched ) {
		wpai199ms_restore_option( Settings::OPTION_NAME, $secondary_original );
		wpai199ms_restore_option( Settings::DATABASE_OPTION_NAME, $secondary_database );
		restore_current_blog();
	}
	if ( $created_secondary && is_int( $secondary ) && $secondary > 0 ) {
		wp_delete_site( $secondary );
	}
	if ( is_int( $site_admin ) && $site_admin > 0 ) {
		require_once ABSPATH . 'wp-admin/includes/ms.php';
		wpmu_delete_user( $site_admin );
	}
	wpai199ms_restore_option( Settings::OPTION_NAME, $main_original );
	wpai199ms_restore_option( Settings::DATABASE_OPTION_NAME, $main_database );
}
