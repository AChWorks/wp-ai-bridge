<?php
/**
 * One prevalidated, verified save across the existing native settings stores.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Admin;

use WP_AI_Bridge\Auth\OAuth_Server;
use WP_AI_Bridge\Support\Administration_Configuration;
use WP_AI_Bridge\Support\External_Package_Limits;
use WP_AI_Bridge\Support\Hook_Dispatch_Recovery;
use WP_AI_Bridge\Support\Native_Option_Access;
use WP_AI_Bridge\Support\Operation_Content_Limits;
use WP_AI_Bridge\Support\Operation_Receipt_Limits;
use WP_AI_Bridge\Support\Payload_Settings;
use WP_AI_Bridge\Support\REST_Invocation_Input_Limits;
use WP_AI_Bridge\Support\Settings;
use WP_AI_Bridge\Support\Transfer_Settings;
use WP_AI_Bridge\Support\Workspace_Limits;

/**
 * Same-screen POST controller. It never redirects or stores submitted drafts.
 *
 * The page calls save( wp_unslash( $_POST ) ) before rendering and escapes every
 * draft/error it displays. Native setters/guards remain in charge of writes;
 * this controller deliberately makes no cross-option transaction promise.
 */
final class Settings_Save {
	const ACTION = 'wpai_settings_save';

	/** @var Settings */
	private $settings;

	/** @var string Current bounded save phase for recoverable failure feedback. */
	private $phase = 'authorization';

	/** @var string|null Native option currently being validated or written. */
	private $active_option;

	/** @param Settings $settings Existing access settings service. */
	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Hidden fields: expected_revision, blog_id, network_id, user_id,
	 * form_complete=1, action=ACTION and _wpnonce from nonce_action().
	 *
	 * editable_options lists native option names the current actor may submit.
	 * Each grant checkbox needs an explicit hidden 0 before its checked 1.
	 * Omitted options and omitted array fields keep their current values.
	 *
	 * @return array{revision:string,blog_id:int,network_id:int,user_id:int,editable_options:array,valid:bool}
	 */
	public function snapshot() {
		try {
			$state = Hook_Dispatch_Recovery::run( array( $this, 'read_state' ) );
			return array(
				'revision'         => $this->revision( $state ),
				'blog_id'          => $state['context']['blog'],
				'network_id'       => $state['context']['network'],
				'user_id'          => $state['context']['user'],
				'editable_options' => array_keys( array_filter( $state['allowed'] ) ),
				'valid'            => $state['valid'],
			);
		} catch ( \Throwable $error ) {
			return array(
				'revision'         => '',
				'blog_id'          => 0,
				'network_id'       => 0,
				'user_id'          => 0,
				'editable_options' => array(),
				'valid'            => false,
			);
		}
	}

	/** @return string Current principal/site/network-bound WordPress nonce action. */
	public function nonce_action() {
		return self::ACTION . '-' . hash( 'sha256', wp_json_encode( $this->context() ) );
	}

	/**
	 * Preflight the entire submission before effects, then verify native writes.
	 *
	 * status: saved|unchanged|invalid|forbidden|stale|partial|rejected.
	 * errors maps option/field names (or _form) to displayable text.
	 * outcomes maps submitted options to saved|unchanged|rejected|unverified|
	 * not_attempted. draft contains only submitted recognized options and stays
	 * request-local. A partial/rejected/stale result must retain an unsaved UI.
	 *
	 * @param array $posted Unslashed same-screen POST body.
	 * @return array Verified outcome, recoverable errors and request-local draft.
	 */
	public function save( array $posted ) {
		nocache_headers();
		$this->phase         = 'authorization';
		$this->active_option = null;
		$definitions         = $this->definitions();
		$result              = array(
			'status'   => 'invalid',
			'errors'   => array(),
			'outcomes' => array(),
			'draft'    => array_intersect_key( $posted, $definitions ),
			'revision' => '',
		);
		// Native protected sanitizers may terminate on hook-time authority drift.
		// Keep that failure recoverable only inside this same-screen controller.
		$die_handler = static function () {
			return static function () {
				throw new \RuntimeException( 'Native settings save interrupted.' );
			};
		};
		add_filter( 'wp_die_handler', $die_handler, PHP_INT_MAX );
		try {
			return Hook_Dispatch_Recovery::run(
				function () use ( $posted, $definitions, &$result ) {
					return $this->process( $posted, $definitions, $result );
				}
			);
		} catch ( \Throwable $error ) {
			// An interrupted hook may also have changed an earlier result.
			foreach ( $result['outcomes'] as $name => $outcome ) {
				if ( in_array( $outcome, array( 'saved', 'unchanged' ), true ) ) {
					$result['outcomes'][ $name ] = 'unverified';
				}
			}
			// Never expose provider exception text, which can contain secrets.
			$result['status']                                    = $this->changed( $result ) ? 'partial' : 'rejected';
			$result['errors'][ $this->active_option ?? '_form' ] = __( 'The save result could not be verified. Review stored settings and your unsaved changes before trying again.', 'wp-ai-bridge' );
			$result['failure']                                   = array(
				'phase'  => $this->phase,
				'option' => $this->active_option,
				'code'   => 'wpai_settings_save_interrupted',
			);
			return $result;
		} finally {
			remove_filter( 'wp_die_handler', $die_handler, PHP_INT_MAX );
		}
	}

	/** @param array $posted POST body. @param array $definitions Stores. @param array $result Response. @return array */
	private function process( array $posted, array $definitions, array &$result ) {
		$state = $this->read_state();
		if ( ! $state['valid'] || 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) ||
			self::ACTION !== ( $posted['action'] ?? null ) ) {
			return $this->failure( $result, 'forbidden', '_form', __( 'Current administrator authority and a complete settings POST are required.', 'wp-ai-bridge' ) );
		}
		if ( ! is_string( $posted['_wpnonce'] ?? null ) || ! wp_verify_nonce( $posted['_wpnonce'], $this->nonce_action() ) ) {
			return $this->failure( $result, 'forbidden', '_form', __( 'The settings security check failed. Reload the screen before saving.', 'wp-ai-bridge' ) );
		}
		foreach ( array(
			'blog_id'    => 'blog',
			'network_id' => 'network',
			'user_id'    => 'user',
		) as $field => $key ) {
			if ( ( $posted[ $field ] ?? null ) !== (string) $state['context'][ $key ] ) {
				return $this->failure( $result, 'stale', '_form', __( 'The site, network or signed-in user changed. Reload and review before saving.', 'wp-ai-bridge' ) );
			}
		}
		$result['revision'] = $this->revision( $state );
		if ( '1' !== ( $posted['form_complete'] ?? null ) || ! is_string( $posted['expected_revision'] ?? null ) ||
			! hash_equals( $result['revision'], $posted['expected_revision'] ) ) {
			return $this->failure( $result, 'stale', '_form', __( 'Settings changed or the form was incomplete. Reload and review your unsaved changes before saving.', 'wp-ai-bridge' ) );
		}

		$candidates  = array();
		$this->phase = 'validation';
		foreach ( $result['draft'] as $name => $input ) {
			$this->active_option = $name;
			if ( ! $state['allowed'][ $name ] ) {
				$this->failure( $result, 'forbidden', $name, __( 'You no longer have authority to change this settings section.', 'wp-ai-bridge' ) );
				continue;
			}
			$value = $this->validate( $name, $input, $definitions[ $name ] );
			if ( is_wp_error( $value ) ) {
				$this->failure( $result, 'invalid', $name, $value->get_error_message() );
			} else {
				$candidates[ $name ] = $value;
			}
		}
		if ( $result['errors'] ) {
			return $result;
		}
		$this->phase         = 'verification';
		$this->active_option = null;
		if ( $state !== $this->read_state() ) {
			return $this->failure( $result, 'stale', '_form', __( 'Settings or administrator authority changed during validation. Review before saving.', 'wp-ai-bridge' ) );
		}

		foreach ( $candidates as $name => $candidate ) {
			$result['outcomes'][ $name ] = 'not_attempted';
		}
		foreach ( $candidates as $name => $candidate ) {
			$this->active_option = $name;
			$this->phase         = 'verification';
			$fresh               = $this->read_state();
			if ( $state !== $fresh ) {
				$this->recheck_outcomes( $result, $candidates, $fresh, $definitions, $state['context'] );
				return $this->failure( $result, $this->changed( $result ) ? 'partial' : 'stale', '_form', __( 'Settings or administrator authority changed while saving. Remaining sections were not saved.', 'wp-ai-bridge' ) );
			}
			if ( $this->matches( $candidate, $state['effective_values'][ $name ], $definitions[ $name ] ) ) {
				$result['outcomes'][ $name ] = 'unchanged';
				continue;
			}
			$result['outcomes'][ $name ] = 'unverified';
			$this->phase                 = 'write';
			$this->write( $name, $candidate, $definitions[ $name ], $state );
			$this->phase = 'verification';
			$after       = $this->read_state();
			if ( ! $this->matches( $candidate, $after['effective_values'][ $name ], $definitions[ $name ] ) ) {
				$result['outcomes'][ $name ] = $after['valid'] && $state['context'] === $after['context'] && $state['values'][ $name ] === $after['values'][ $name ] ? 'rejected' : 'unverified';
				$this->recheck_outcomes( $result, $candidates, $after, $definitions, $state['context'] );
				return $this->failure( $result, $this->changed( $result ) ? 'partial' : 'rejected', $name, __( 'WordPress did not retain the requested value. Review this section and the remaining unsaved changes.', 'wp-ai-bridge' ) );
			}
			$result['outcomes'][ $name ]           = 'saved';
			$expected                              = $state;
			$expected['values'][ $name ]           = $after['values'][ $name ];
			$expected['effective_values'][ $name ] = $after['effective_values'][ $name ];
			if ( $expected !== $after ) {
				$result['outcomes'][ $name ] = 'unverified';
				$this->recheck_outcomes( $result, $candidates, $after, $definitions, $state['context'] );
				return $this->failure( $result, 'partial', '_form', __( 'Another setting or administrator context changed during the write. Review stored values; remaining sections were not saved.', 'wp-ai-bridge' ) );
			}
			$state = $after;
		}
		// A later native hook can change an earlier result. Recheck the whole set.
		$this->active_option = null;
		$after               = $this->read_state();
		if ( $state !== $after ) {
			$this->recheck_outcomes( $result, $candidates, $after, $definitions, $state['context'] );
			return $this->failure( $result, $this->changed( $result ) ? 'partial' : 'stale', '_form', __( 'The final saved state could not be confirmed. Review stored values and unsaved changes.', 'wp-ai-bridge' ) );
		}
		$result['status']   = $this->changed( $result ) ? 'saved' : 'unchanged';
		$result['revision'] = $this->revision( $after );
		return $result;
	}

	/**
	 * Late native-hook guards prevent a sanitizer/filter from changing the
	 * principal, target or snapshot before the native setter reaches storage.
	 * External hooks remain nontransactional; their effects are never rolled back.
	 *
	 * @param string $name Native option.
	 * @param mixed  $candidate Prevalidated value.
	 * @param array  $definition Store.
	 * @param array  $state Expected context, authority and preimage.
	 * @return void
	 */
	private function write( $name, $candidate, array $definition, array $state ) {
		$guard = function ( $value ) use ( $state ) {
			if ( $state !== $this->read_state() ) {
				throw new \RuntimeException( 'Settings context changed before native storage.' );
			}
			return $value;
		};
		$hooks = array( 'sanitize_option_' . $name );
		if ( 'network' === $definition['kind'] ) {
			$hooks[] = 'pre_update_site_option_' . $name;
			$hooks[] = 'pre_add_site_option_' . $name;
		} else {
			$hooks[] = 'pre_update_option_' . $name;
			$hooks[] = 'pre_add_option_' . $name;
		}
		foreach ( $hooks as $hook ) {
			add_filter( $hook, $guard, PHP_INT_MAX );
		}
		$global_guard = function ( $value, $option ) use ( $name, $guard ) {
			return $name === $option ? $guard( $value ) : $value;
		};
		// Core's generic site-option pre-update filter follows its named one.
		add_filter( 'pre_update_option', $global_guard, PHP_INT_MAX, 2 );
		try {
			Hook_Dispatch_Recovery::run(
				function () use ( $name, $candidate, $definition, $state ) {
					if ( 'network' === $definition['kind'] ) {
						update_network_option( $state['context']['network'], $name, $candidate );
					} else {
						update_option( $name, $candidate, false );
					}
				}
			);
		} finally {
			remove_filter( 'pre_update_option', $global_guard, PHP_INT_MAX );
			foreach ( $hooks as $hook ) {
				remove_filter( $hook, $guard, PHP_INT_MAX );
			}
		}
	}

	/** @return array Native option definitions; no alternate grant store. */
	private function definitions() {
		return array(
			Settings::OPTION_NAME                     => array(
				'kind'  => 'grants',
				'group' => Settings::OPTION_GROUP,
			),
			Settings::DATABASE_OPTION_NAME            => array(
				'kind'  => 'database',
				'group' => Settings::DATABASE_OPTION_GROUP,
			),
			Settings::ADMINISTRATION_OPTION_NAME      => array(
				'kind'  => 'administration',
				'group' => Settings::ADMINISTRATION_OPTION_GROUP,
			),
			Payload_Settings::OPTION_NAME             => array(
				'kind'    => 'budget',
				'service' => new Payload_Settings(),
				'group'   => Payload_Settings::OPTION_GROUP,
			),
			Transfer_Settings::OPTION_NAME            => array(
				'kind'    => 'budget',
				'service' => new Transfer_Settings(),
				'group'   => Transfer_Settings::OPTION_GROUP,
			),
			Workspace_Limits::OPTION_NAME             => array(
				'kind'    => 'budget',
				'service' => new Workspace_Limits(),
				'group'   => Workspace_Limits::OPTION_GROUP,
			),
			Operation_Receipt_Limits::OPTION_NAME     => array(
				'kind'    => 'scalar',
				'service' => new Operation_Receipt_Limits(),
				'group'   => Operation_Receipt_Limits::OPTION_GROUP,
			),
			Operation_Content_Limits::OPTION_NAME     => array(
				'kind'    => 'budget',
				'service' => new Operation_Content_Limits(),
				'group'   => Operation_Content_Limits::OPTION_GROUP,
			),
			REST_Invocation_Input_Limits::OPTION_NAME => array(
				'kind'    => 'scalar',
				'service' => new REST_Invocation_Input_Limits(),
				'group'   => REST_Invocation_Input_Limits::OPTION_GROUP,
			),
			External_Package_Limits::OPTION_NAME      => array(
				'kind'    => 'scalar',
				'service' => new External_Package_Limits(),
				'group'   => External_Package_Limits::OPTION_GROUP,
			),
			Administration_Configuration::OPTION_NAME => array( 'kind' => 'configuration' ),
			Native_Option_Access::NETWORK_OPTION_NAME => array( 'kind' => 'network' ),
			Native_Option_Access::NETWORK_WRITE_OPTION_NAME => array( 'kind' => 'network' ),
		);
	}

	/** @param string $name Option. @param mixed $input Input. @param array $definition Store. @return mixed */
	private function validate( $name, $input, array $definition ) {
		$kind = $definition['kind'];
		if ( 'network' === $kind ) {
			return $this->flag( $input );
		}
		if ( in_array( $kind, array( 'grants', 'database', 'administration' ), true ) ) {
			$current = $this->settings->all();
			$keys    = 'database' === $kind ? Settings::DATABASE_GROUPS : ( 'administration' === $kind ? Settings::ADMINISTRATION_GROUPS : array_keys( $this->settings->sanitize( array() ) ) );
			$value   = array_intersect_key( $current, array_fill_keys( $keys, true ) );
			if ( ! is_array( $input ) || array_diff( array_keys( $input ), $keys ) ) {
				return $this->invalid();
			}
			foreach ( $input as $key => $flag ) {
				$parsed = $this->flag( $flag );
				if ( is_wp_error( $parsed ) ) {
					return $parsed;
				}
				$value[ $key ] = $parsed;
			}
			if ( 'administration' === $kind ) {
				return $this->settings->sanitize_administration_grants( $value );
			}
			return 'database' === $kind ? $this->settings->sanitize_database_grants( $value ) : $this->settings->sanitize( $value );
		}
		if ( 'configuration' === $kind ) {
			if ( ! is_array( $input ) || array_diff( array_keys( $input ), array( 'roots', 'restore_schemas', 'backup_tables', 'step_bytes', 'step_seconds' ) ) ) {
				return $this->invalid();
			}
			$service = new Administration_Configuration();
			$current = $service->all();
			$current = array_intersect_key( $current, array_fill_keys( array( 'roots', 'restore_schemas', 'backup_tables', 'step_bytes', 'step_seconds' ), true ) );
			if ( array_key_exists( 'roots', $input ) ) {
				if ( '' === $input['roots'] ) {
					$input['roots'] = array();
				}
				if ( ! is_array( $input['roots'] ) ) {
					return $this->invalid();
				}
				foreach ( $input['roots'] as &$root ) {
					if ( ! is_array( $root ) || array_diff( array_keys( $root ), array( 'id', 'label', 'path', 'scope', 'purposes', 'read', 'write', 'destructive', 'executable', 'nonserved', 'remove' ) ) ) {
						return $this->invalid();
					}
					foreach ( array( 'read', 'write', 'destructive', 'executable', 'nonserved', 'remove' ) as $flag ) {
						if ( array_key_exists( $flag, $root ) && is_wp_error( $this->flag( $root[ $flag ] ) ) ) {
							return $this->invalid();
						}
					}
					if ( isset( $root['purposes'] ) && '' === $root['purposes'] ) {
						$root['purposes'] = array();
					}
					// A posted collection replaces roots; missing fields within a
					// retained root preserve its previous values. Explicit 0 revokes.
					$id = $root['id'] ?? null;
					if ( is_string( $id ) && isset( $current['roots'][ $id ] ) ) {
						$root = array_replace( $current['roots'][ $id ], $root );
					}
				}
				unset( $root );
			}
			return $service->validate( array_replace( $current, $input ) );
		}
		$service = $definition['service'];
		if ( 'budget' === $kind ) {
			if ( ! is_array( $input ) || array_diff_key( $input, $service->defaults() ) ) {
				return $this->invalid();
			}
			foreach ( $input as $value ) {
				if ( null === $this->integer( $value ) ) {
					return $this->invalid();
				}
			}
			$value = $service->sanitize( $input );
			foreach ( $input as $key => $number ) {
				if ( $this->integer( $number ) !== $value[ $key ] ) {
					return $this->invalid();
				}
			}
			return $value;
		}
		$parsed = $this->integer( $input );
		if ( null === $parsed || $parsed !== $service->sanitize( $input ) ) {
			return $this->invalid();
		}
		return $parsed;
	}

	/** @param mixed $value Boolean control. @return int|\WP_Error */
	private function flag( $value ) {
		return in_array( $value, array( 0, 1, '0', '1', false, true ), true ) ? (int) $value : $this->invalid();
	}

	/** @param mixed $value Strict positive native decimal. @return int|null */
	private function integer( $value ) {
		if ( ( ! is_int( $value ) && ! is_string( $value ) ) || 1 !== preg_match( '/^[1-9][0-9]*$/D', (string) $value ) ) {
			return null;
		}
		$text = (string) $value;
		$max  = (string) PHP_INT_MAX;
		return strlen( $text ) < strlen( $max ) || ( strlen( $text ) === strlen( $max ) && strcmp( $text, $max ) <= 0 ) ? (int) $text : null;
	}

	/** @return \WP_Error */
	private function invalid() {
		return new \WP_Error( 'wpai_settings_invalid', __( 'Review this section: use explicit checkbox values, complete scope records and whole numbers within the shown limits.', 'wp-ai-bridge' ) );
	}

	/** @return array Principal identity, including authenticated MCP context. */
	private function context() {
		return array(
			'blog'      => (int) get_current_blog_id(),
			'network'   => (int) get_current_network_id(),
			'user'      => (int) get_current_user_id(),
			'client'    => OAuth_Server::authenticated_mcp_client_id(),
			'multisite' => is_multisite(),
		);
	}

	/**
	 * Public only for the exception-safe callable boundary; read-only.
	 *
	 * @return array Snapshot of context, authority and current native stores.
	 */
	public function read_state() {
		$origin           = $this->context();
		$valid            = $origin['blog'] > 0 && $origin['network'] > 0 && $origin['user'] > 0 && current_user_can( 'manage_options' );
		$allowed          = array();
		$values           = array();
		$effective_values = array();
		foreach ( $this->definitions() as $name => $definition ) {
			$kind    = $definition['kind'];
			$can_set = $valid;
			if ( isset( $definition['group'] ) ) {
				$cap     = apply_filters( 'option_page_capability_' . $definition['group'], 'manage_options' );
				$can_set = $can_set && is_string( $cap ) && current_user_can( $cap );
			}
			if ( in_array( $kind, array( 'database', 'administration', 'configuration' ), true ) ) {
				$can_set = $can_set && $this->settings->can_configure_group( 'database' === $kind ? Settings::GROUP_DATABASE_INSPECTION : Settings::GROUP_FILESYSTEM_READ );
			}
			if ( 'network' === $kind ) {
				$can_set                   = $can_set && Native_Option_Access::can_configure_network( $origin['network'] );
				$values[ $name ]           = $origin['multisite'] ? get_network_option( $origin['network'], $name, null ) : null;
				$effective_values[ $name ] = $values[ $name ];
			} else {
				// Both are observed Options API views, subject to native filters.
				// Preserve the explicit-default view for freshness. Core's setter
				// compares the native registered-default view and may return false
				// without adding a row when that effective value is unchanged.
				$values[ $name ]           = get_option( $name, null );
				$effective_values[ $name ] = get_option( $name );
			}
			$allowed[ $name ] = $can_set;
		}
		return array(
			'context'          => $origin,
			'allowed'          => $allowed,
			'values'           => $values,
			'effective_values' => $effective_values,
			'valid'            => $valid && $origin === $this->context() && current_user_can( 'manage_options' ),
		);
	}

	/** @param array $state Snapshot. @return string Opaque authenticated revision. */
	private function revision( array $state ) {
		return hash_hmac( 'sha256', serialize( $state ), wp_salt( 'nonce' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Internal scalar/array snapshot hash, never decoded.
	}

	/** @param mixed $expected Candidate. @param mixed $actual Readback. @param array $definition Store. @return bool */
	private function matches( $expected, $actual, array $definition ) {
		if ( in_array( $definition['kind'], array( 'scalar', 'network' ), true ) ) {
			// Core may return stored scalar integers as decimal strings.
			return $expected === $actual || ( is_int( $expected ) && (string) $expected === $actual );
		}
		return $expected === $actual;
	}

	/** @param array $result Response. @param array $candidates Intent. @param array $state Readback. @param array $definitions Stores. @param array $origin Original context. @return void */
	private function recheck_outcomes( array &$result, array $candidates, array $state, array $definitions, array $origin ) {
		foreach ( $result['outcomes'] as $name => $outcome ) {
			if ( in_array( $outcome, array( 'saved', 'unchanged' ), true ) &&
				( ! $state['valid'] || $origin !== $state['context'] || ! $this->matches( $candidates[ $name ], $state['effective_values'][ $name ], $definitions[ $name ] ) ) ) {
				$result['outcomes'][ $name ] = 'unverified';
			}
		}
	}

	/** @param array $result Current result. @return bool Any write was attempted or confirmed. */
	private function changed( array $result ) {
		return (bool) array_intersect( array( 'saved', 'unverified' ), $result['outcomes'] );
	}

	/** @param array $result Response. @param string $status Outcome. @param string $field Field. @param string $message Text. @return array */
	private function failure( array &$result, $status, $field, $message ) {
		$result['status']           = 'forbidden' === $result['status'] ? 'forbidden' : $status;
		$result['errors'][ $field ] = $message;
		return $result;
	}
}
