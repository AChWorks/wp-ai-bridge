<?php
/**
 * Narrow site-local admission coordinator for finite Bridge-owned stores.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

/**
 * MySQL connection-scoped named locks guard check-and-create boundaries.
 *
 * Only the fixed Bridge claim and Workspace capacities can acquire a lock.
 * A failed/unsupported database lock means no new write may begin. A worker
 * death releases the MySQL lock; the underlying durable objects/claims are
 * never erased or inferred rolled back by the coordinator.
 */
final class Admission_Lock {
	const WAIT_SECONDS = 10;

	/** @var array<string,bool> Deny reentrant same-process admissions. */
	private static $held = array();

	/** @var string */
	private $name = '';

	/** @param string $name Fixed derived database lock identity. */
	private function __construct( $name ) {
		$this->name = $name;
	}

	/**
	 * @param string $scope One fixed Bridge-owned admission surface.
	 * @return self|false
	 */
	public static function acquire( $scope ) {
		global $wpdb;
		if ( ! in_array( $scope, array( 'claims', 'documents', 'tasks' ), true ) ||
			! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		// WordPress blog/site table names form the isolation boundary. Hashing
		// keeps names short and avoids caller-selected SQL or lock identifiers.
		$table = 'claims' === $scope ? ( $wpdb->options ?? '' ) : ( $wpdb->posts ?? '' );
		if ( ! is_string( $table ) || '' === $table ) {
			return false;
		}
		$name = 'wpai_admit_' . substr( hash( 'sha256', $scope . '|' . $table ), 0, 40 );
		if ( isset( self::$held[ $name ] ) ) {
			return false;
		}
		$acquired = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixed admission lock.
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $name, self::WAIT_SECONDS ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant prepared lock query.
		);
		if ( '1' !== (string) $acquired ) {
			return false;
		}
		$lock                = new self( $name );
		self::$held[ $name ] = true;
		if ( ! $lock->is_owned() ) {
			$lock->release();
			return false;
		}
		return $lock;
	}

	/** @return bool True only while the original current DB session holds it. */
	public function is_owned() {
		global $wpdb;
		if ( '' === $this->name || ! isset( self::$held[ $this->name ] ) ||
			! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		$owner   = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Verify fixed lock owner.
			$wpdb->prepare( 'SELECT IS_USED_LOCK(%s)', $this->name ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant prepared lock query.
		);
		$session = $wpdb->get_var( 'SELECT CONNECTION_ID()' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Fixed DB identity.
		return is_numeric( $owner ) && is_numeric( $session ) &&
			(int) $owner > 0 && (int) $owner === (int) $session;
	}

	/** @return void */
	public function release() {
		global $wpdb;
		if ( '' === $this->name ) {
			return;
		}
		if ( $this->is_owned() ) {
			$wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Release only this verified lock.
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $this->name ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Constant prepared lock query.
			);
		}
		unset( self::$held[ $this->name ] );
		$this->name = '';
	}

	/** @return void */
	public function __destruct() {
		$this->release();
	}
}
