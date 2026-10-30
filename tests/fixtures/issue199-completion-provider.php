<?php
/**
 * Synthetic public provider for isolated administrator-completion fixtures.
 * Install only with the explicit disposable-test constant in fresh WordPress.
 *
 * @package WP_AI_Bridge
 */

if ( ! defined( 'WPAI_ADMIN13_DISPOSABLE' ) || true !== WPAI_ADMIN13_DISPOSABLE ) {
	return;
}

// Task-only HTTP and CLI use the same finite memory policy.
ini_set( 'memory_limit', '512M' );

/** Durable native effect counter: concurrent dispatches cannot lose increments. */
function wpai199c_provider_effect( $operation_id, $mode = 'normal' ) {
	global $wpdb;
	$table = get_option( 'wpai199c_effect_table', '' );
	if ( ! is_string( $table ) || ! preg_match( '/^provider_admin13_effects_[a-f0-9]{8}$/D', $table ) ) {
		throw new RuntimeException( 'Synthetic effect fixture was not seeded.' );
	}
	$key = hash( 'sha256', (string) $operation_id );
	$result = $wpdb->query( $wpdb->prepare(
		'INSERT INTO %i (effect_key,calls) VALUES(%s,1) ON DUPLICATE KEY UPDATE calls=calls+1',
		$table,
		$key
	) );
	if ( false === $result ) {
		throw new RuntimeException( 'Synthetic native effect counter failed.' );
	}
	if ( 'throw_after_effect' === $mode ) {
		throw new RuntimeException( 'Synthetic provider stopped after its committed effect.' );
	}
	return $key;
}

add_action( 'wpai199c_owned_queue_effect', 'wpai199c_provider_effect', 10, 2 );

// This disposable fixture has no autonomous queue consumer during quiescent capture.
add_filter( 'action_scheduler_allow_async_request_runner', '__return_false', PHP_INT_MAX );

add_action( 'customize_register', static function ( $manager ) {
	$manager->add_setting( 'wpai199c_customizer_text', array(
		'type' => 'theme_mod',
		'default' => 'synthetic default',
		'capability' => 'edit_theme_options',
		'sanitize_callback' => 'sanitize_text_field',
		'validate_callback' => static function ( $validity, $value ) {
			if ( ! is_string( $value ) || strlen( $value ) > 1000 ) {
				$validity->add( 'fixture_invalid', 'Synthetic fixture expects short text.' );
			}
			return $validity;
		},
	) );
} );

add_filter( 'wp_ai_bridge_cron_profiles', static function ( $profiles ) {
	if ( ! interface_exists( '\WP_AI_Bridge\Support\Cron_Targeted_Runner' ) ) {
		return $profiles;
	}
	$profiles['admin13_owned'] = array(
		'hook' => 'wpai199c_owned_queue_effect',
		'args' => array( 'admin13 fixed cron effect', 'normal' ),
		'actions' => array( 'schedule', 'reschedule', 'unschedule', 'run_now' ),
		'min_interval_seconds' => 17,
		'runner_revision' => 'admin13-synthetic-v1',
		'run_budget_seconds' => 3,
		'run_destructive' => false,
		'targeted_runner' => new class implements \WP_AI_Bridge\Support\Cron_Targeted_Runner {
			public function run_once( $context ) {
				$key = wpai199c_provider_effect( $context['operation_id'] );
				return array( 'status' => 'completed', 'effect_ref' => $key );
			}
			public function verify( $context, $effect_ref ) {
				global $wpdb;
				$table = get_option( 'wpai199c_effect_table', '' );
				if ( ! is_string( $table ) || ! preg_match( '/^provider_admin13_effects_[a-f0-9]{8}$/D', $table ) ) {
					return false;
				}
				$count = $wpdb->get_var( $wpdb->prepare( 'SELECT calls FROM %i WHERE effect_key=%s', $table, hash( 'sha256', $context['operation_id'] ) ) );
				return is_string( $effect_ref ) && hash_equals( hash( 'sha256', $context['operation_id'] ), $effect_ref ) && '1' === (string) $count;
			}
		},
	);
	return $profiles;
} );

/**
 * Called explicitly before quiescence. Provider bootstrap itself writes no data.
 * Returns only fixture ownership locators; never credentials or live-site data.
 */
function wpai199c_seed_provider( $suffix ) {
	global $wpdb;
	if ( ! current_user_can( 'manage_options' ) || ! is_string( $suffix ) || ! preg_match( '/^[a-f0-9]{8}$/D', $suffix ) ) {
		throw new RuntimeException( 'Disposable provider seed requires its administrator and exact run identity.' );
	}
	$tables = array(
		'provider_admin13_private_' . $suffix,
		'provider_admin13_keyless_' . $suffix,
		'provider_admin13_effects_' . $suffix,
	);
	$definitions = array(
		'(id BIGINT UNSIGNED NOT NULL PRIMARY KEY,payload LONGBLOB NULL,note LONGTEXT NULL,amount DECIMAL(30,12) NOT NULL,`0` LONGBLOB NULL) ENGINE=InnoDB',
		'(payload LONGBLOB NULL,note LONGTEXT NULL) ENGINE=InnoDB',
		'(effect_key VARBINARY(64) NOT NULL PRIMARY KEY,calls BIGINT UNSIGNED NOT NULL) ENGINE=InnoDB',
	);
	foreach ( $tables as $index => $table ) {
		if ( false === $wpdb->query( $wpdb->prepare( 'CREATE TABLE %i ' . $definitions[ $index ], $table ) ) ) {
			throw new RuntimeException( 'Synthetic provider table creation failed; no existing table was replaced.' );
		}
	}
	$large = str_repeat( "\0\xffسلام", 131073 );
	$rows = array(
		array( 'id' => '18446744073709551614', 'payload' => $large, 'note' => serialize( array( 'exact' => "سلام\0bytes", 'bigint' => '18446744073709551614' ) ), 'amount' => '123456789012345678.123456789012', '0' => '' ),
		array( 'id' => '2', 'payload' => null, 'note' => '', 'amount' => '-0.000000000001', '0' => "\xff\0" ),
	);
	foreach ( $rows as $row ) {
		if ( false === $wpdb->insert( $tables[0], $row, array_fill( 0, count( $row ), '%s' ) ) ) {
			throw new RuntimeException( 'Synthetic exact provider row insert failed.' );
		}
	}
	foreach ( array(
		array( 'payload' => "\0\xffduplicate", 'note' => 'same' ),
		array( 'payload' => "\0\xffduplicate", 'note' => 'same' ),
		array( 'payload' => "\0\xffduplicate", 'note' => 'same' ),
		array( 'payload' => null, 'note' => '' ),
		array( 'payload' => '', 'note' => null ),
	) as $row ) {
		if ( false === $wpdb->insert( $tables[1], $row ) ) {
			throw new RuntimeException( 'Synthetic keyless duplicate insert failed.' );
		}
	}
	update_option( 'wpai199c_effect_table', $tables[2], false );
	update_option( 'wpai199c_serialized_fixture', array( 'binary' => "\0\xff", 'decimal' => '123456789012345678.123456789012', 'unicode' => 'داده' ), false );
	$directory = ABSPATH . 'wp-content/uploads/wpai199c-' . $suffix;
	if ( file_exists( $directory ) || ! wp_mkdir_p( $directory . '/empty/nested' ) ) {
		throw new RuntimeException( 'Synthetic filesystem fixture ownership unavailable.' );
	}
	$files = array(
		'binary.bin' => $large,
		'داده.txt' => str_repeat( "سلام جهان\n", 100 ),
		'executable-mode.txt' => "Synthetic mode-preservation fixture; never executed.\n",
		'fixture.log' => "synthetic first\n" . str_repeat( 'x', 8193 ) . "\nsynthetic last\n",
	);
	foreach ( $files as $relative => $bytes ) {
		if ( strlen( $bytes ) !== file_put_contents( $directory . '/' . $relative, $bytes ) ) {
			throw new RuntimeException( 'Synthetic file seed failed.' );
		}
		chmod( $directory . '/' . $relative, 'executable-mode.txt' === $relative ? 0750 : 0640 );
	}
	chmod( $directory . '/empty', 0750 );
	chmod( $directory . '/empty/nested', 0710 );
	return array( 'tables' => $tables, 'directory' => $directory, 'large_bytes' => strlen( $large ), 'large_sha256' => hash( 'sha256', $large ) );
}
