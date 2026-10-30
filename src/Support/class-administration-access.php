<?php
/**
 * Current-principal boundary shared by advanced administration families.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_AI_Bridge\Auth\OAuth_Server;

/** Uses the existing grouped Permissions service, never another ACL. */
final class Administration_Access {
	/** @var Permissions */
	private $permissions;

	/** @param Permissions $permissions Existing delegation service. */
	public function __construct( Permissions $permissions ) {
		$this->permissions = $permissions;
	}

	/**
	 * Recheck one explicit surface grant and all native prerequisites.
	 *
	 * @param string $group Existing Settings group.
	 * @param bool $network Require actual network administration.
	 * @param array $additional_requirements Existing group/capability pairs.
	 * @return bool
	 */
	public function allowed( $group, $network = false, array $additional_requirements = array() ) {
		try {
			return Hook_Dispatch_Recovery::run(
				function () use ( $group, $network, $additional_requirements ) {
					$origin = $this->context();
					if ( $origin['blog'] < 1 || $origin['network'] < 1 || $origin['user'] < 1 ||
						! is_string( $group ) || ! is_bool( $network ) ||
						! $this->permissions->allowed_all( array_merge( array( array( $group, 'manage_options' ) ), $additional_requirements ) ) ) {
						return false;
					}
					if ( $network && function_exists( 'is_multisite' ) && is_multisite() &&
						( ! is_super_admin() || ! current_user_can( 'manage_network_options' ) ) ) {
						return false;
					}
					return $this->same_context( $origin );
				}
			);
		} catch ( \Throwable $error ) {
			return false;
		}
	}

	/** @return array{blog:int,network:int,user:int,client:string} */
	public function context() {
		return array(
			'blog'    => function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0,
			'network' => function_exists( 'get_current_network_id' ) ? (int) get_current_network_id() : 0,
			'user'    => function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0,
			'client'  => OAuth_Server::authenticated_mcp_client_id(),
		);
	}

	/** @param array $origin Original scalar principal context. @return bool */
	public function same_context( array $origin ) {
		return $origin === $this->context();
	}

	/** @return array Protected operator configuration, never caller input. */
	public function configuration() {
		return ( new Administration_Configuration() )->all();
	}
}
