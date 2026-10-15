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
 * Only the fixed Bridge claim, Workspace capacities and native Cron mutations can acquire a lock.
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
	 * Namespace MySQL's SERVER-WIDE advisory locks by schema as well as
	 * WordPress's exact site-local table. Independent databases on the same
	 * MySQL host commonly have identical wp_ table names.
	 *
	 * @param string $scope One of the four fixed Bridge admission scopes.
	 * @param string $table The actual current blog's native table name.
	 * @param string $database Stable shared WordPress database/schema identity.
	 * @return string Empty for an unsupported/ambiguous identity.
	 */
	private static function lock_name( $scope, $table, $database ) {
		if ( ! in_array( $scope, array( 'claims', 'documents', 'tasks', 'cron' ), true ) ||
			! is_string( $table ) || '' === $table ||
			! is_string( $database ) || '' === trim( $database ) ) {
			return '';
		}
		// Length-delimited fields prevent ambiguous concatenation. Do not use
		// ABSPATH, host aliases or a process-specific value: separate WP nodes
		// accessing the SAME database/site must derive exactly one lock.
		$identity = strlen( $database ) . ':' . $database . strlen( $table ) . ':' . $table . $scope;
		return 'wpai_admit_' . substr( hash( 'sha256', $identity ), 0, 40 );
	}

	/**
	 * Return the current WordPress posts table binding without exposing a
	 * second direct database surface in the Workspace application store.
	 * A deletion may continue only while this matches its admitted table.
	 *
	 * @return string Empty when WordPress DB context is unavailable.
	 */
	public static function current_posts_table() {
		global $wpdb;
		return isset( $wpdb ) && is_object( $wpdb ) &&
			isset( $wpdb->posts ) && is_string( $wpdb->posts ) ?
			$wpdb->posts : '';
	}

	/**
	 * @param string $scope One fixed Bridge-owned admission surface.
	 * @return self|false
	 */
	public static function acquire( $scope ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return false;
		}
		// Named locks are server-wide, NOT scoped by the active database.
		// DB_NAME is shared across horizontally scaled nodes, while the
		// current blog's table differentiates multisite tenants.
		$database = defined( 'DB_NAME' ) && is_string( DB_NAME ) ? DB_NAME : '';
		$table    = in_array( $scope, array( 'claims', 'cron' ), true ) ? ( $wpdb->options ?? '' ) : ( $wpdb->posts ?? '' );
		// Standard wpdb exposes the connection's configured schema. If a DB
		// router reports a different one, do not create an ambiguous lock.
		if ( isset( $wpdb->dbname ) && $wpdb->dbname !== $database ) {
			return false;
		}
		$name = self::lock_name( $scope, $table, $database );
		if ( '' === $name ) {
			return false;
		}
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
