<?php
/**
 * Dependency-free, bounded WP-Cron inventory and permission regressions.
 *
 * @package WP_AI_Bridge
 */
require __DIR__ . '/bootstrap.php';

use WP_AI_Bridge\Abilities\Cron_Abilities;
use WP_AI_Bridge\Support\Permissions;
use WP_AI_Bridge\Support\Settings;

function is_multisite() {
	return ! empty( $GLOBALS['wpai_test']['multisite'] );
}
function get_current_blog_id() {
	return $GLOBALS['wpai_test']['blog_id'] ?? 1;
}
function wp_salt( $scheme = 'auth' ) {
	return 'isolated-test-salt';
}
function has_action( $hook ) {
	return in_array( $hook, $GLOBALS['wpai_test']['callbacks'] ?? array(), true ) ? 10 : false;
}
function _get_cron_array() {
	++$GLOBALS['wpai_test']['cron_reads'];
	return $GLOBALS['wpai_test']['cron'] ?? array();
}
function wpai138_assert( $condition, $why ) {
	++$GLOBALS['wpai_test']['assertions'];
	if ( ! $condition ) {
		throw new RuntimeException( $why );
	}
}

wpai_test_reset_state();
$GLOBALS['wpai_test']['assertions'] = 0;
$GLOBALS['wpai_test']['cron_reads'] = 0;
$GLOBALS['wpai_test']['blog_id']    = 1;
$GLOBALS['wpai_test']['callbacks']  = array( 'safe_registered_hook' );
$settings                           = new Settings();
$provider                           = new Cron_Abilities( new Permissions( $settings ) );
$registered                         = $provider->register();
wpai138_assert( 1 === count( $registered ), 'Cron discovery registration absent.' );
$catalog = $GLOBALS['wpai_test']['registered_abilities']['wp-ai-bridge/cron-events-read'];
wpai138_assert(
	true === $catalog['meta']['annotations']['readonly'] &&
	false === $catalog['meta']['annotations']['destructive'] &&
	false === $catalog['input_schema']['additionalProperties'],
	'Cron introspection must be bounded and read-only.'
);
wpai138_assert( ! $provider->can_read(), 'Default-off Site Configuration permission bypassed.' );
$denied = $provider->execute();
wpai138_assert( is_wp_error( $denied ) && 'cron_read_denied' === $denied->get_error_code() && 0 === $GLOBALS['wpai_test']['cron_reads'], 'Denied read touched native Cron.' );

$groups                                = $settings->defaults();
$groups[ Settings::GROUP_SITE_CONFIG ] = 1;
update_option( Settings::OPTION_NAME, $groups );
wpai138_assert( $provider->can_read(), 'Authorized WordPress admin cannot inspect Cron.' );

$future                       = time() + 600;
$past                         = time() - 900;
$hash_empty                   = md5( serialize( array() ) );
$hash_args                    = md5( serialize( array( 'confidential' => 'hidden-payload' ) ) );
$GLOBALS['wpai_test']['cron'] = array(
	$past   => array(
		'safe_registered_hook' => array(
			$hash_empty => array(
				'schedule' => false,
				'args'     => array(),
			),
		),
	),
	$future => array(
		'provider_maintenance' => array(
			$hash_args => array(
				'schedule' => 'hourly',
				'interval' => 3600,
				'args'     => array( 'confidential' => 'hidden-payload' ),
			),
		),
	),
);
$list                         = $provider->execute( array( 'limit' => 1 ) );
wpai138_assert(
	! is_wp_error( $list ) && 2 === $list['total'] && $list['has_more'] &&
	1 === $list['next_offset'] && 1 === count( $list['items'] ),
	'Cron page one incorrect.'
);
wpai138_assert( 64 === strlen( $list['snapshot'] ) && 64 === strlen( $list['items'][0]['event_id'] ), 'Opaque event and inventory identities required.' );
wpai138_assert(
	'safe_registered_hook' === $list['items'][0]['hook'] && $list['items'][0]['overdue'] &&
	$list['items'][0]['callback_registered'] && '' === $list['items'][0]['recurrence'],
	'Native single-event semantics lost.'
);

$second = $provider->execute(
	array(
		'offset'            => 1,
		'limit'             => 1,
		'expected_snapshot' => $list['snapshot'],
	)
);
wpai138_assert(
	! is_wp_error( $second ) && 2 === $second['total'] && ! $second['has_more'] &&
	'provider_maintenance' === $second['items'][0]['hook'],
	'Stable Cron second page failed.'
);
wpai138_assert( 3600 === $second['items'][0]['interval_seconds'] && ! $second['items'][0]['callback_registered'], 'Callback and recurrence provenance wrong.' );
wpai138_assert(
	false === strpos( json_encode( $second ), 'hidden-payload' ) &&
	false === strpos( json_encode( $second ), 'confidential' ),
	'Serialized Cron callback arguments leaked.'
);
$exact = $provider->execute(
	array(
		'action'   => 'get',
		'event_id' => $second['items'][0]['event_id'],
	)
);
wpai138_assert( ! is_wp_error( $exact ) && 1 === count( $exact['items'] ) && $exact['items'][0]['event_id'] === $second['items'][0]['event_id'], 'Exact event read not stable.' );
$filtered = $provider->execute( array( 'hook' => 'safe_registered_hook' ) );
wpai138_assert( ! is_wp_error( $filtered ) && 1 === $filtered['total'], 'Exact hook filtering failed.' );
$bad = $provider->execute(
	array(
		'action'   => 'get',
		'event_id' => 'not-a-hash',
	)
);
wpai138_assert( is_wp_error( $bad ) && 'cron_invalid_input' === $bad->get_error_code(), 'Invalid identity not rejected.' );
$bad = $provider->execute( array( 'offset' => '0' ) );
wpai138_assert( is_wp_error( $bad ), 'String offset accepted.' );
$bad = $provider->execute(
	array(
		'action' => 'run',
		'hook'   => 'arbitrary_command',
	)
);
wpai138_assert( is_wp_error( $bad ) && 'cron_invalid_input' === $bad->get_error_code(), 'Cron exposed unexpected PHP runner.' );
$bad = $provider->execute(
	array(
		'action' => 'schedule',
		'hook'   => 'arbitrary_command',
	)
);
wpai138_assert( is_wp_error( $bad ) && 'cron_invalid_input' === $bad->get_error_code(), 'Cron exposed arbitrary scheduling.' );

$GLOBALS['wpai_test']['cron'][ $future + 1 ] = array(
	'new_event' => array(
		$hash_empty => array(
			'schedule' => false,
			'args'     => array(),
		),
	),
);
$stale                                       = $provider->execute(
	array(
		'offset'            => 1,
		'expected_snapshot' => $list['snapshot'],
	)
);
wpai138_assert( is_wp_error( $stale ) && 'cron_stale_snapshot' === $stale->get_error_code(), 'Changed index reused stale pagination.' );

$GLOBALS['wpai_test']['blog_id'] = 2;
$other_blog                      = $provider->execute();
wpai138_assert( $other_blog['items'][0]['event_id'] !== $list['items'][0]['event_id'], 'Cross-blog event identity reused.' );
$GLOBALS['wpai_test']['blog_id'] = 1;

$GLOBALS['wpai_test']['capabilities']['manage_options'] = false;
wpai138_assert( is_wp_error( $provider->execute() ), 'Revoked native capability allowed Cron.' );
$GLOBALS['wpai_test']['capabilities']['manage_options'] = true;
$GLOBALS['wpai_test']['multisite']                      = true;
wpai138_assert( is_wp_error( $provider->execute() ), 'Multisite site admin saw network-sensitive Cron jobs.' );
$GLOBALS['wpai_test']['capabilities']['manage_network_options'] = true;
wpai138_assert( ! is_wp_error( $provider->execute() ), 'Multisite network admin denied Cron inventory.' );
$GLOBALS['wpai_test']['multisite'] = false;

$GLOBALS['wpai_test']['cron'] = array();
$empty                        = $provider->execute();
wpai138_assert(
	0 === $empty['total'] && ! $empty['has_more'] &&
	! $empty['external_trigger_verified'],
	'Empty Cron or external-trigger claim incorrect.'
);

$GLOBALS['wpai_test']['cron'] = array();
$large_total                 = 4103;
for ( $i = 0; $i < $large_total; ++$i ) {
	$GLOBALS['wpai_test']['cron'][ $future + $i ] = array(
		'hook' => array(
			$hash_empty => array(
				'schedule' => false,
				'args'     => array(),
			),
		),
	);
}
$large_first = $provider->execute( array( 'limit' => 25 ) );
wpai138_assert(
	! is_wp_error( $large_first ) && $large_total === $large_first['total'] &&
	25 === count( $large_first['items'] ) && $large_first['has_more'] &&
	25 === $large_first['next_offset'],
	'Legitimate Cron inventories over 4,096 events must support bounded pages.'
);
$large_last = $provider->execute(
	array(
		'offset'            => $large_total - 7,
		'limit'             => 25,
		'expected_snapshot' => $large_first['snapshot'],
	)
);
wpai138_assert(
	! is_wp_error( $large_last ) && $large_total === $large_last['total'] &&
	7 === count( $large_last['items'] ) && ! $large_last['has_more'] &&
	$large_total === $large_last['next_offset'] &&
	$large_first['snapshot'] === $large_last['snapshot'],
	'Large Cron inventory last-page continuation failed.'
);
$large_exact = $provider->execute(
	array(
		'action'   => 'get',
		'event_id' => $large_last['items'][6]['event_id'],
	)
);
wpai138_assert(
	! is_wp_error( $large_exact ) &&
	$large_last['items'][6]['event_id'] === $large_exact['items'][0]['event_id'],
	'Large inventory exact event lookup failed.'
);
$GLOBALS['wpai_test']['cron'][ $future + $large_total ] = array(
	'hook' => array(
		$hash_empty => array(
			'schedule' => false,
			'args'     => array(),
		),
	),
);
$large_stale = $provider->execute(
	array(
		'offset'            => 25,
		'expected_snapshot' => $large_first['snapshot'],
	)
);
wpai138_assert(
	is_wp_error( $large_stale ) &&
	'cron_stale_snapshot' === $large_stale->get_error_code(),
	'Large inventory replayed a stale page after event changes.'
);

echo 'PASS: Issue #138 bounded native Cron inventory (' . $GLOBALS['wpai_test']['assertions'] . " assertions).\n";
