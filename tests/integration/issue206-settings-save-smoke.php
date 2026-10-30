<?php
/**
 * Real WordPress native settings-save pipelines on an isolated installation.
 *
 * Run with wp eval-file after defining WPAI206_DISPOSABLE=true and setting
 * WPAI206_EXPECT_DATABASE to the actual disposable database. This fixture
 * restores the exact owned option rows, filters, actor and request context.
 * It never invokes Gateway, Workspace clear/export or public Abilities.
 *
 * @package WP_AI_Bridge
 */

use WP_AI_Bridge\Admin\Settings_Save;
use WP_AI_Bridge\Admin\Settings_Page;
use WP_AI_Bridge\Support\Environment;
use WP_AI_Bridge\Support\Administration_Configuration;
use WP_AI_Bridge\Support\External_Package_Limits;
use WP_AI_Bridge\Support\Native_Option_Access;
use WP_AI_Bridge\Support\Operation_Content_Limits;
use WP_AI_Bridge\Support\Operation_Receipt_Limits;
use WP_AI_Bridge\Support\Payload_Settings;
use WP_AI_Bridge\Support\REST_Invocation_Input_Limits;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Transfer_Settings;
use WP_AI_Bridge\Support\Workspace_Limits;

global $wpdb, $wp_filter, $wp_current_filter, $wp_registered_settings, $new_allowed_options, $wp_settings_errors;

$expected_database = getenv( 'WPAI206_EXPECT_DATABASE' );
if ( ! defined( 'WPAI206_DISPOSABLE' ) || true !== WPAI206_DISPOSABLE ||
	! is_string( $expected_database ) || '' === $expected_database ||
	$wpdb->dbname !== $expected_database || $wpdb->get_var( 'SELECT DATABASE()' ) !== $expected_database ) {
	throw new RuntimeException( 'The exact coordinator-owned disposable database must be explicitly selected.' );
}
$original_user   = get_current_user_id();
$original_blog   = get_current_blog_id();
$original_options_table = $wpdb->options;
$original_switch_depth = count( $GLOBALS['_wp_switched_stack'] ?? array() );
$action_secondary = 0;
$native_action_cases = 0;
$original_method = $_SERVER['REQUEST_METHOD'] ?? null;
$original_post   = $_POST;
$original_get    = $_GET;
$admin           = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
if ( ! $admin ) {
	throw new RuntimeException( 'The disposable fixture needs an existing administrator.' );
}
wp_set_current_user( (int) $admin[0] );
if ( ! current_user_can( 'manage_options' ) || ( is_multisite() && ( ! is_super_admin() || ! current_user_can( 'manage_network_options' ) ) ) ) {
	wp_set_current_user( $original_user );
	throw new RuntimeException( 'The selected disposable administrator needs actual current network authority.' );
}

$checks = 0;
$assert = static function ( $condition, $message ) use ( &$checks ) {
	++$checks;
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$site_options = array(
	Settings::OPTION_NAME, Settings::DATABASE_OPTION_NAME, Settings::ADMINISTRATION_OPTION_NAME,
	Payload_Settings::OPTION_NAME, Transfer_Settings::OPTION_NAME, Workspace_Limits::OPTION_NAME,
	Operation_Receipt_Limits::OPTION_NAME, Operation_Content_Limits::OPTION_NAME,
	REST_Invocation_Input_Limits::OPTION_NAME, External_Package_Limits::OPTION_NAME,
	Administration_Configuration::OPTION_NAME,
);
$network_options = array( Native_Option_Access::NETWORK_OPTION_NAME, Native_Option_Access::NETWORK_WRITE_OPTION_NAME );
$network = get_current_network_id();
$read_rows = static function ( $table = null ) use ( $wpdb, $site_options ) {
	$table = null === $table ? $wpdb->options : $table;
	$rows = array();
	foreach ( $site_options as $name ) {
		$rows[ $name ] = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE option_name = %s", $name ), ARRAY_A );
	}
	return $rows;
};
$read_network_rows = static function () use ( $wpdb, $network, $network_options ) {
	$rows = array();
	if ( is_multisite() ) {
		foreach ( $network_options as $name ) {
			$rows[ $name ] = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->sitemeta} WHERE site_id = %d AND meta_key = %s ORDER BY meta_id", $network, $name ), ARRAY_A );
		}
	}
	return $rows;
};
$original_rows         = $read_rows();
$original_network_rows = $read_network_rows();
$original_registered   = $wp_registered_settings;
$original_allowed      = $new_allowed_options ?? null;
$original_errors       = $wp_settings_errors ?? null;
$original_stack        = $wp_current_filter;
$hook_names = array( 'wp_die_handler', 'pre_update_option', 'update_option', 'add_option', 'updated_option', 'map_meta_cap' );
foreach ( array_merge( $site_options, $network_options ) as $name ) {
	foreach ( array( 'sanitize_option_', 'default_option_', 'pre_update_option_', 'pre_update_site_option_', 'pre_add_site_option_' ) as $prefix ) {
		$hook_names[] = $prefix . $name;
	}
}
foreach ( array( Settings::DATABASE_OPTION_GROUP, Settings::ADMINISTRATION_OPTION_GROUP, Operation_Receipt_Limits::OPTION_GROUP ) as $group ) {
	$hook_names[] = 'option_page_capability_' . $group;
}
$original_filters = array();
foreach ( $hook_names as $hook ) {
	$original_filters[ $hook ] = isset( $wp_filter[ $hook ] ) ? clone $wp_filter[ $hook ] : null;
}
$hook_callbacks = static function () use ( $hook_names ) {
	global $wp_filter;
	$callbacks = array();
	foreach ( $hook_names as $hook ) {
		$callbacks[ $hook ] = isset( $wp_filter[ $hook ] ) ? $wp_filter[ $hook ]->callbacks : array();
	}
	return $callbacks;
};

$root = sys_get_temp_dir() . '/wpai206-native-' . bin2hex( random_bytes( 8 ) );
if ( ! mkdir( $root, 0700 ) ) {
	wp_set_current_user( $original_user );
	throw new RuntimeException( 'Cannot create the exact isolated scope fixture.' );
}
$network_result = is_multisite() ? 'pending' : 'NOT_RUN_single_site';
$controller = new Settings_Save( new Settings() );
$post = static function ( array $fields ) use ( $controller, $assert ) {
	$snapshot = $controller->snapshot();
	$assert( $snapshot['valid'], 'Current native snapshot must be valid.' );
	return array_merge( array(
		'action' => Settings_Save::ACTION,
		'_wpnonce' => wp_create_nonce( $controller->nonce_action() ),
		'expected_revision' => $snapshot['revision'],
		'blog_id' => (string) $snapshot['blog_id'],
		'network_id' => (string) $snapshot['network_id'],
		'user_id' => (string) $snapshot['user_id'],
		'form_complete' => '1',
	), $fields );
};

try {
	// Core runs menu access checks before admin_init. A hidden legacy submenu
	// must redirect during menu construction or an administrator gets HTTP403.
	$legacy_redirect = false;
	foreach ( $wp_filter['admin_menu']->callbacks ?? array() as $priority => $callbacks ) {
		foreach ( $callbacks as $hook ) {
			$callback = $hook['function'];
			if ( is_array( $callback ) && $callback[0] instanceof \WP_AI_Bridge\Admin\Administration_Page && 'redirect_legacy' === $callback[1] ) {
				$legacy_redirect = $priority > 20 && false === has_action( 'admin_init', $callback );
			}
		}
	}
	$assert( $legacy_redirect, 'Legacy bookmarks must redirect after registration and before native Core menu access checks.' );
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$settings = new Settings();
	$settings->register();
	foreach ( array( new Payload_Settings(), new Transfer_Settings(), new Workspace_Limits(), new Operation_Receipt_Limits(), new Operation_Content_Limits(), new REST_Invocation_Input_Limits(), new External_Package_Limits() ) as $service ) {
		$service->register();
	}
	( new Administration_Configuration() )->boot();
	( new Native_Option_Access( new \WP_AI_Bridge\Support\Permissions( $settings ) ) )->register_network_guards();
	foreach ( $site_options as $name ) {
		delete_option( $name );
	}
	if ( is_multisite() ) {
		foreach ( $network_options as $name ) {
			delete_network_option( $network, $name );
		}
	}
	$assert( null === $read_rows()[ Payload_Settings::OPTION_NAME ], 'Payload option must be genuinely absent before the native add path.' );
	$assert( ( new Payload_Settings() )->defaults() === get_option( Payload_Settings::OPTION_NAME ), 'Registered native defaults did not apply to an absent row.' );

	// Reproduce the actual same-screen browser submission: unchanged registered
	// defaults in the first section, with edits only in later hidden panels.
	// Parse successful controls from the real rendered form, retaining hidden0
	// before checkbox1 and omitting unclicked Add scope/Workspace buttons.
	$_SERVER['REQUEST_METHOD'] = 'GET';
	$_GET['page'] = Settings_Page::SETTINGS_SLUG;
	$_POST = array();
	require_once ABSPATH . 'wp-admin/includes/template.php';
	$get_page = new Settings_Page( new Environment(), $settings );
	ob_start();
	try {
		$get_page->render_settings();
		$html = ob_get_contents();
	} finally {
		ob_end_clean();
	}
	$assert( 1 === preg_match( '~<form\\b[^>]*\\bid="wpai-settings-form"[^>]*>(.*?)</form>~s', $html, $form ), 'Cannot find the real unified settings form.' );
	$pairs = array();
	$tags = new WP_HTML_Tag_Processor( $form[1] );
	while ( $tags->next_tag( 'INPUT' ) ) {
		$name = $tags->get_attribute( 'name' );
		$type = strtolower( (string) $tags->get_attribute( 'type' ) );
		if ( ! is_string( $name ) || null !== $tags->get_attribute( 'disabled' ) ||
			in_array( $type, array( 'submit', 'button', 'reset', 'file' ), true ) ||
			( in_array( $type, array( 'checkbox', 'radio' ), true ) && null === $tags->get_attribute( 'checked' ) ) ) {
			continue;
		}
		$value = $tags->get_attribute( 'value' );
		$pairs[] = rawurlencode( $name ) . '=' . rawurlencode( is_string( $value ) ? $value : '' );
	}
	$assert( 'form_complete=1' === end( $pairs ), 'The completeness sentinel must follow every rendered settings input.' );
	foreach ( array( 'textarea', 'select' ) as $tag ) {
		preg_match_all( '~<' . $tag . '\\b([^>]*)>(.*?)</' . $tag . '>~s', $form[1], $controls, PREG_SET_ORDER );
		foreach ( $controls as $control ) {
			$attributes = new WP_HTML_Tag_Processor( '<' . $tag . ' ' . $control[1] . '>' );
			$attributes->next_tag();
			$name = $attributes->get_attribute( 'name' );
			if ( ! is_string( $name ) || null !== $attributes->get_attribute( 'disabled' ) ) {
				continue;
			}
			$value = html_entity_decode( $control[2], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( 'select' === $tag ) {
				$value = '';
				$options = new WP_HTML_Tag_Processor( $control[2] );
				$first = true;
				while ( $options->next_tag( 'OPTION' ) ) {
					if ( $first || null !== $options->get_attribute( 'selected' ) ) {
						$value = (string) $options->get_attribute( 'value' );
					}
					$first = false;
				}
			}
			$pairs[] = rawurlencode( $name ) . '=' . rawurlencode( $value );
		}
	}
	parse_str( implode( '&', $pairs ), $default_post );
	$assert( $settings->sanitize( $default_post[ Settings::OPTION_NAME ] ) === get_option( Settings::OPTION_NAME ) &&
		null === get_option( Settings::OPTION_NAME, null ), 'The full-form prefix must match the native registered default without a stored row.' );
	$default_post[ Settings::DATABASE_OPTION_NAME ][ Settings::GROUP_DATABASE_INSPECTION ] = '1';
	$default_post[ Transfer_Settings::OPTION_NAME ][ Transfer_Settings::REQUEST_KIB ] = '129';
	$_SERVER['REQUEST_METHOD'] = 'POST';
	$_POST = wp_slash( $default_post );
	$post_page = new Settings_Page( new Environment(), $settings );
	$post_page->prepare_settings_screen();
	$feedback = new ReflectionProperty( Settings_Page::class, 'save_result' );
	$feedback->setAccessible( true );
	$result = $feedback->getValue( $post_page );
	$assert( 'saved' === $result['status'] && 'unchanged' === $result['outcomes'][ Settings::OPTION_NAME ], 'Actual full-form same-screen Save rejected its unchanged default prefix: ' . wp_json_encode( $result ) );
	$assert( null === $read_rows()[ Settings::OPTION_NAME ] && 1 === $settings->all()[ Settings::GROUP_DATABASE_INSPECTION ] &&
		129 === ( new Transfer_Settings() )->all()[ Transfer_Settings::REQUEST_KIB ], 'Full-form Save did not retain both later edits while preserving native absent/default semantics.' );
	$_POST = $original_post;
	$_GET = $original_get;
	foreach ( $site_options as $name ) {
		delete_option( $name );
	}
	if ( is_multisite() ) {
		foreach ( $network_options as $name ) {
			delete_network_option( $network, $name );
		}
	}

	$sanitize_calls = 0;
	$count_sanitize = static function ( $value ) use ( &$sanitize_calls ) { ++$sanitize_calls; return $value; };
	add_filter( 'sanitize_option_' . Payload_Settings::OPTION_NAME, $count_sanitize, 5 );
	$fields = array(
		Settings::OPTION_NAME => array( Settings::GROUP_SITE_READ => '0' ),
		Settings::DATABASE_OPTION_NAME => array( Settings::GROUP_DATABASE_READ => '1' ),
		Settings::ADMINISTRATION_OPTION_NAME => array( Settings::GROUP_FILESYSTEM_READ => '1' ),
		Payload_Settings::OPTION_NAME => array( Payload_Settings::RESPONSE_KEY => '96' ),
		Transfer_Settings::OPTION_NAME => array( Transfer_Settings::SESSIONS => '9' ),
		Workspace_Limits::OPTION_NAME => array( Workspace_Limits::DOCUMENTS_KEY => '300' ),
		Operation_Receipt_Limits::OPTION_NAME => '4096',
		Operation_Content_Limits::OPTION_NAME => array( Operation_Content_Limits::SETTING_SCHEMA_KIB => '512' ),
		REST_Invocation_Input_Limits::OPTION_NAME => '24',
		External_Package_Limits::OPTION_NAME => '200',
		Administration_Configuration::OPTION_NAME => array(
			'roots' => array( array( 'id' => 'logs', 'path' => $root, 'purposes' => array( 'logs' ), 'read' => '1' ) ),
			'restore_schemas' => "wpai206_restore\nwpai206_restore_second",
			'backup_tables' => 'wpai206_table',
			'step_seconds' => '7',
		),
	);
	if ( is_multisite() ) {
		$fields[ Native_Option_Access::NETWORK_OPTION_NAME ] = '1';
		$fields[ Native_Option_Access::NETWORK_WRITE_OPTION_NAME ] = '1';
	}
	$result = $controller->save( $post( $fields ) );
	remove_filter( 'sanitize_option_' . Payload_Settings::OPTION_NAME, $count_sanitize, 5 );
	$assert( 'saved' === $result['status'] && ! $result['errors'], 'Native cross-section save failed: ' . wp_json_encode( $result ) );
	$assert( count( $fields ) === count( $result['outcomes'] ), 'A submitted native store was omitted from the verified outcomes.' );
	$assert( 2 === $sanitize_calls, 'The native absent-option update/add double-sanitize path was not exercised.' );
	$assert( 96 === get_option( Payload_Settings::OPTION_NAME )[ Payload_Settings::RESPONSE_KEY ] && 300 === get_option( Workspace_Limits::OPTION_NAME )[ Workspace_Limits::DOCUMENTS_KEY ], 'Native array options did not retain requested integers.' );
	$assert( 4096 === ( new Operation_Receipt_Limits() )->limit() && 1 === $settings->all()[ Settings::GROUP_DATABASE_READ ] && 0 === $settings->all()[ Settings::GROUP_SITE_READ ], 'Native scalars, protected grants or explicit unchecked values were lost.' );
	$assert( $controller->snapshot()['revision'] === $result['revision'], 'Native persisted revision did not match the verified response.' );

	$before = $read_rows();
	$result = $controller->save( $post( array(
		Settings::OPTION_NAME => array( Settings::GROUP_SITE_READ => '1' ),
		Operation_Receipt_Limits::OPTION_NAME => '8192',
		Administration_Configuration::OPTION_NAME => array( 'roots' => array( array( 'id' => 'bad', 'path' => $root . '/missing' ) ) ),
	) ) );
	$assert( 'invalid' === $result['status'] && $before === $read_rows(), 'An invalid later native scope allowed earlier row effects.' );
	$assert( isset( $result['draft'][ Operation_Receipt_Limits::OPTION_NAME ], $result['errors'][ Administration_Configuration::OPTION_NAME ] ), 'Native validation lost draft or targeted feedback.' );

	$result = $controller->save( $post( array( Administration_Configuration::OPTION_NAME => array( 'step_seconds' => '8' ), Workspace_Limits::OPTION_NAME => array( Workspace_Limits::TASKS_KEY => '350' ) ) ) );
	$config = ( new Administration_Configuration() )->all();
	$assert( 'saved' === $result['status'] && $config['roots']['logs']['read'] && array( 'wpai206_table' ) === $config['backup_tables'], 'Omitted native scopes or fields were reset.' );
	$assert( 300 === get_option( Workspace_Limits::OPTION_NAME )[ Workspace_Limits::DOCUMENTS_KEY ], 'Omitted sibling budget was reset.' );

	$reject = static function ( $value, $old ) { return $old; };
	add_filter( 'pre_update_option_' . Operation_Receipt_Limits::OPTION_NAME, $reject, 20, 2 );
	try {
		$result = $controller->save( $post( array( Operation_Receipt_Limits::OPTION_NAME => '8192' ) ) );
		$assert( 'rejected' === $result['status'] && 'rejected' === $result['outcomes'][ Operation_Receipt_Limits::OPTION_NAME ] && 4096 === ( new Operation_Receipt_Limits() )->limit(), 'Native pre-update rejection was reported as saved.' );
		$result = $controller->save( $post( array( Workspace_Limits::OPTION_NAME => array( Workspace_Limits::DOCUMENTS_KEY => '400' ), Operation_Receipt_Limits::OPTION_NAME => '8192', External_Package_Limits::OPTION_NAME => '240' ) ) );
		$assert( 'partial' === $result['status'] && 'saved' === $result['outcomes'][ Workspace_Limits::OPTION_NAME ] && 'not_attempted' === $result['outcomes'][ External_Package_Limits::OPTION_NAME ], 'Native partial persistence hid saved or unattempted stores.' );
		$assert( 400 === get_option( Workspace_Limits::OPTION_NAME )[ Workspace_Limits::DOCUMENTS_KEY ] && 200 === ( new External_Package_Limits() )->requested_mib(), 'Partial result did not reflect real native rows.' );
	} finally {
		remove_filter( 'pre_update_option_' . Operation_Receipt_Limits::OPTION_NAME, $reject, 20 );
	}

	$mutate = static function ( $value ) { return 12288; };
	add_filter( 'pre_update_option_' . Operation_Receipt_Limits::OPTION_NAME, $mutate, 20 );
	try {
		$result = $controller->save( $post( array( Operation_Receipt_Limits::OPTION_NAME => '8192' ) ) );
		$assert( 'partial' === $result['status'] && 'unverified' === $result['outcomes'][ Operation_Receipt_Limits::OPTION_NAME ] && 12288 === ( new Operation_Receipt_Limits() )->limit(), 'A native mutated persisted value was called all-saved.' );
	} finally {
		remove_filter( 'pre_update_option_' . Operation_Receipt_Limits::OPTION_NAME, $mutate, 20 );
	}

	$die = static function ( $name ) {
		if ( Operation_Receipt_Limits::OPTION_NAME === $name ) {
			wp_die( 'synthetic-private-provider-denial', '', array( 'response' => 403 ) );
		}
	};
	add_action( 'updated_option', $die, 20, 1 );
	$stack = $wp_current_filter;
	try {
		$result = $controller->save( $post( array( Operation_Receipt_Limits::OPTION_NAME => '16384', External_Package_Limits::OPTION_NAME => '240' ) ) );
		$assert( 'partial' === $result['status'] && 16384 === ( new Operation_Receipt_Limits() )->limit(), 'Post-effect native wp_die did not retain truthful partial recovery.' );
		$assert( Operation_Receipt_Limits::OPTION_NAME === $result['failure']['option'] && 'write' === $result['failure']['phase'] && isset( $result['errors'][ Operation_Receipt_Limits::OPTION_NAME ] ), 'Interrupted native write lost its exact option/phase.' );
		$assert( false === strpos( wp_json_encode( $result ), 'synthetic-private-provider-denial' ), 'Native provider denial text entered recoverable feedback.' );
		$assert( $stack === $wp_current_filter && ! doing_filter( 'updated_option' ), 'Real WP_Hook iteration/stack was not recovered before continuation.' );
	} finally {
		remove_action( 'updated_option', $die, 20 );
	}
	$result = $controller->save( $post( array( Operation_Receipt_Limits::OPTION_NAME => '20480' ) ) );
	$assert( 'saved' === $result['status'], 'Native hook dispatch did not remain usable after wp_die recovery.' );

	// The last native pre-SQL actions follow every sanitizer/pre-update filter.
	// An existing foreign row makes wrong-table UPDATE detectable; an absent
	// foreign row makes wrong-table INSERT detectable. All rows are read from
	// their explicit original tables even while the callback changed context.
	$drifts = array( 'actor', 'capability' );
	$foreign_table = null;
	if ( is_multisite() ) {
		$created_action_site = wp_insert_site( array(
			'domain' => 'wpai206-actions-' . bin2hex( random_bytes( 6 ) ) . '.invalid',
			'path' => '/',
			'network_id' => $network,
			'title' => 'Unified Save native action fixture',
		) );
		$assert( is_int( $created_action_site ) && $created_action_site > 0, 'Cannot create the precisely owned native-action secondary site.' );
		$action_secondary = $created_action_site;
		$foreign_table = $wpdb->get_blog_prefix( $action_secondary ) . 'options';
		$drifts[] = 'site';
	}
	foreach ( array( 'update_option', 'add_option' ) as $action ) {
		foreach ( $drifts as $drift ) {
			if ( 'update_option' === $action ) {
				// A registered default can short-circuit update_option without
				// creating a row. Use a distinct valid native preimage for UPDATE.
				update_option( Operation_Receipt_Limits::OPTION_NAME, 3072, false );
			} else {
				delete_option( Operation_Receipt_Limits::OPTION_NAME );
			}
			if ( null !== $foreign_table ) {
				switch_to_blog( $action_secondary );
				try {
					if ( 'update_option' === $action ) {
						update_option( Operation_Receipt_Limits::OPTION_NAME, 6144, false );
					} else {
						delete_option( Operation_Receipt_Limits::OPTION_NAME );
					}
				} finally {
					restore_current_blog();
				}
			}
			$before_action = $read_rows( $original_options_table );
			$before_foreign = null === $foreign_table ? null : $read_rows( $foreign_table );
			if ( 'update_option' === $action ) {
				$assert( null !== $before_action[ Operation_Receipt_Limits::OPTION_NAME ] && '3072' === $before_action[ Operation_Receipt_Limits::OPTION_NAME ]['option_value'], 'UPDATE regression requires a physically stored original-site nondefault preimage.' );
				if ( null !== $before_foreign ) {
					$assert( null !== $before_foreign[ Operation_Receipt_Limits::OPTION_NAME ] && '6144' === $before_foreign[ Operation_Receipt_Limits::OPTION_NAME ]['option_value'], 'UPDATE regression requires a distinct physically stored foreign-site preimage.' );
				}
			} else {
				$assert( null === $before_action[ Operation_Receipt_Limits::OPTION_NAME ] && Operation_Receipt_Limits::DEFAULT_RECEIPTS === ( new Operation_Receipt_Limits() )->limit(), 'ADD regression requires a physically absent original-site row with its registered effective default.' );
				if ( null !== $before_foreign ) {
					$assert( null === $before_foreign[ Operation_Receipt_Limits::OPTION_NAME ], 'ADD regression requires a physically absent foreign-site row.' );
				}
			}
			$before_callbacks = $hook_callbacks();
			$before_stack = $wp_current_filter;
			$switch_depth = count( $GLOBALS['_wp_switched_stack'] ?? array() );
			$calls = 0;
			$deny_capability = static function ( $caps, $cap ) {
				return 'manage_options' === $cap ? array( 'do_not_allow' ) : $caps;
			};
			$drift_callback = static function ( $name ) use ( $drift, $action_secondary, $deny_capability, &$calls ) {
				if ( Operation_Receipt_Limits::OPTION_NAME !== $name ) {
					return;
				}
				++$calls;
				if ( 'actor' === $drift ) {
					wp_set_current_user( 0 );
				} elseif ( 'capability' === $drift ) {
					add_filter( 'map_meta_cap', $deny_capability, PHP_INT_MAX, 2 );
				} else {
					switch_to_blog( $action_secondary );
				}
			};
			add_action( $action, $drift_callback, 20, 1 );
			try {
				$result = $controller->save( $post( array(
					Operation_Receipt_Limits::OPTION_NAME => '4096',
					External_Package_Limits::OPTION_NAME => '240',
				) ) );
				$assert( 1 === $calls, 'The intended real native pre-SQL action was not exercised: ' . $action . '/' . $drift );
				$assert( $before_action === $read_rows( $original_options_table ), 'Final native action drift changed an original-site owned row: ' . $action . '/' . $drift );
				if ( null !== $foreign_table ) {
					$assert( $before_foreign === $read_rows( $foreign_table ), 'Final native action drift changed a foreign-site owned row: ' . $action . '/' . $drift );
				}
				$assert( 'partial' === $result['status'] && 'unverified' === $result['outcomes'][ Operation_Receipt_Limits::OPTION_NAME ] && 'not_attempted' === $result['outcomes'][ External_Package_Limits::OPTION_NAME ], 'Native pre-SQL failure fabricated a saved result or attempted later stores.' );
				$assert( 'write' === $result['failure']['phase'] && Operation_Receipt_Limits::OPTION_NAME === $result['failure']['option'] && isset( $result['draft'][ Operation_Receipt_Limits::OPTION_NAME ] ), 'Native pre-SQL failure lost recoverable intent and exact failing option.' );
				$assert( $before_stack === $wp_current_filter && ! doing_action( $action ), 'Native pre-SQL failure left a real WP_Hook action frame active.' );
				++$native_action_cases;
			} finally {
				remove_action( $action, $drift_callback, 20 );
				remove_filter( 'map_meta_cap', $deny_capability, PHP_INT_MAX );
				while ( count( $GLOBALS['_wp_switched_stack'] ?? array() ) > $switch_depth ) {
					restore_current_blog();
				}
				wp_set_current_user( (int) $admin[0] );
			}
			$assert( $before_callbacks === $hook_callbacks(), 'Native pre-SQL failure leaked temporary guards or changed preexisting callbacks.' );
			$assert( $original_blog === get_current_blog_id() && current_user_can( 'manage_options' ), 'Native action fixture did not restore its current actor/site authority.' );
		}
	}

	$stale = $post( array( Operation_Receipt_Limits::OPTION_NAME => '24576' ) );
	update_option( Payload_Settings::OPTION_NAME, array_replace( ( new Payload_Settings() )->all(), array( Payload_Settings::RESPONSE_KEY => 128 ) ), false );
	$before = $read_rows();
	$result = $controller->save( $stale );
	$assert( 'stale' === $result['status'] && $before === $read_rows(), 'Stale native snapshot caused a row write.' );

	$denied = $post( array( Operation_Receipt_Limits::OPTION_NAME => '24576' ) );
	wp_set_current_user( 0 );
	$result = $controller->save( $denied );
	$assert( 'forbidden' === $result['status'] && $before === $read_rows(), 'Anonymous native principal changed a settings row.' );
	wp_set_current_user( (int) $admin[0] );
	$csrf = $post( array( Operation_Receipt_Limits::OPTION_NAME => '24576' ) );
	$csrf['_wpnonce'] = 'invalid-native-nonce';
	$assert( 'forbidden' === $controller->save( $csrf )['status'] && $before === $read_rows(), 'Invalid native nonce changed a settings row.' );
	$incomplete = $post( array( Operation_Receipt_Limits::OPTION_NAME => '24576' ) );
	unset( $incomplete['form_complete'] );
	$assert( 'stale' === $controller->save( $incomplete )['status'] && $before === $read_rows(), 'Missing end-of-form sentinel reached native storage.' );

	$group_cap = static function () { return 'do_not_allow'; };
	add_filter( 'option_page_capability_' . Operation_Receipt_Limits::OPTION_GROUP, $group_cap );
	try {
		$result = $controller->save( $post( array( Operation_Receipt_Limits::OPTION_NAME => '24576', Workspace_Limits::OPTION_NAME => array( Workspace_Limits::DOCUMENTS_KEY => '450' ) ) ) );
		$assert( 'forbidden' === $result['status'] && $before === $read_rows(), 'Native Settings API group capability was bypassed before ordinary writes.' );
	} finally {
		remove_filter( 'option_page_capability_' . Operation_Receipt_Limits::OPTION_GROUP, $group_cap );
	}

	if ( is_multisite() ) {
		$network_before = $read_network_rows();
		$deny_network = static function ( $caps, $cap ) { return 'manage_network_options' === $cap ? array( 'do_not_allow' ) : $caps; };
		add_filter( 'map_meta_cap', $deny_network, 20, 2 );
		try {
			$ordinary = $post( array( Workspace_Limits::OPTION_NAME => array( Workspace_Limits::DOCUMENTS_KEY => '450' ) ) );
			$assert( ! in_array( Native_Option_Access::NETWORK_OPTION_NAME, $controller->snapshot()['editable_options'], true ), 'Denied network authority remained editable.' );
			$result = $controller->save( $ordinary );
			$assert( 'saved' === $result['status'] && $network_before === $read_network_rows() && $before[ Settings::DATABASE_OPTION_NAME ] === $read_rows()[ Settings::DATABASE_OPTION_NAME ], 'Ordinary native save reset unauthorized/omitted stores.' );
			$before_denied = $read_rows();
			$result = $controller->save( $post( array( Native_Option_Access::NETWORK_OPTION_NAME => '0', Operation_Receipt_Limits::OPTION_NAME => '24576' ) ) );
			$assert( 'forbidden' === $result['status'] && $before_denied === $read_rows() && $network_before === $read_network_rows(), 'Unauthorized network field allowed earlier ordinary writes.' );
		} finally {
			remove_filter( 'map_meta_cap', $deny_network, 20 );
		}
		$reject_network = static function ( $value, $old ) { return $old; };
		add_filter( 'pre_update_site_option_' . Native_Option_Access::NETWORK_WRITE_OPTION_NAME, $reject_network, 20, 2 );
		try {
			$result = $controller->save( $post( array( Native_Option_Access::NETWORK_WRITE_OPTION_NAME => '0' ) ) );
			$assert( 'rejected' === $result['status'] && Native_Option_Access::network_write_enabled(), 'Native network pre-update rejection was hidden.' );
		} finally {
			remove_filter( 'pre_update_site_option_' . Native_Option_Access::NETWORK_WRITE_OPTION_NAME, $reject_network, 20 );
		}
		$result = $controller->save( $post( array( Native_Option_Access::NETWORK_WRITE_OPTION_NAME => '0' ) ) );
		$assert( 'saved' === $result['status'] && ! Native_Option_Access::network_write_enabled() && Native_Option_Access::network_enabled(), 'Explicit native network revocation reset the sibling consent.' );
		$network_result = 'PASS';
	}
} finally {
	// Restore only fixture-owned native rows, including exact serialized bytes,
	// option IDs/autoload and original network metadata. No grant normalization
	// can silently alter the preimage during this test-only compensation.
	foreach ( $original_filters as $hook => $original ) {
		if ( null === $original ) {
			unset( $wp_filter[ $hook ] );
		} else {
			$wp_filter[ $hook ] = $original;
		}
	}
	wp_set_current_user( (int) $admin[0] );
	while ( count( $GLOBALS['_wp_switched_stack'] ?? array() ) > $original_switch_depth ) {
		restore_current_blog();
	}
	if ( $action_secondary > 0 ) {
		wp_delete_site( $action_secondary );
	}
	foreach ( $site_options as $name ) {
		$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) );
		if ( null !== $original_rows[ $name ] ) {
			$wpdb->insert( $wpdb->options, $original_rows[ $name ] );
		}
		wp_cache_delete( $name, 'options' );
	}
	wp_cache_delete( 'alloptions', 'options' );
	wp_cache_delete( 'notoptions', 'options' );
	if ( is_multisite() ) {
		foreach ( $network_options as $name ) {
			$wpdb->delete( $wpdb->sitemeta, array( 'site_id' => $network, 'meta_key' => $name ) );
			foreach ( $original_network_rows[ $name ] as $row ) {
				$wpdb->insert( $wpdb->sitemeta, $row );
			}
			wp_cache_delete( $network . ':' . $name, 'site-options' );
		}
		wp_cache_delete( $network . ':notoptions', 'site-options' );
	}
	$wp_registered_settings = $original_registered;
	$new_allowed_options = $original_allowed;
	$wp_settings_errors = $original_errors;
	$assert( $original_rows === $read_rows() && $original_network_rows === $read_network_rows(), 'Fixture did not restore exact native option rows.' );
	$assert( $original_stack === $wp_current_filter, 'Fixture left the WordPress hook stack changed.' );
	if ( null === $original_method ) {
		unset( $_SERVER['REQUEST_METHOD'] );
	} else {
		$_SERVER['REQUEST_METHOD'] = $original_method;
	}
	$_POST = $original_post;
	$_GET = $original_get;
	wp_set_current_user( $original_user );
	rmdir( $root );
}
echo 'PASS: #206 native WordPress save fixture ' . wp_json_encode( array( 'checks' => $checks, 'wordpress' => get_bloginfo( 'version' ), 'multisite' => is_multisite(), 'network' => $network_result, 'native_action_cases' => $native_action_cases, 'exact_rows_restored' => true ) ) . "\n";
