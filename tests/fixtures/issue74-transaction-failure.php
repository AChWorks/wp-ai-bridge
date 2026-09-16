<?php
/**
 * Test-only wpdb fault injector for Issue #74 transaction failure coverage.
 *
 * @package WP_AI_Bridge
 */

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

$failure_spec = getenv( 'WPAI_ISSUE74_TX_FAIL' );
if ( false === $failure_spec || '' === trim( $failure_spec ) ) {
	return;
}

final class WPAI_Issue74_Transaction_Failure_WPDB extends wpdb {
	/** @var array<string,bool> */
	private $failures = array();

	/**
	 * @param string $spec Comma-separated transaction statements to fail.
	 */
	public function __construct( $spec ) {
		parent::__construct( DB_USER, DB_PASSWORD, DB_NAME, DB_HOST );
		foreach ( explode( ',', strtoupper( (string) $spec ) ) as $statement ) {
			$statement = trim( $statement );
			if ( '' !== $statement ) {
				$this->failures[ $statement ] = true;
			}
		}

		global $table_prefix;
		$this->set_prefix( $table_prefix );
	}

	/**
	 * @param string $query SQL query.
	 * @return int|bool
	 */
	public function query( $query ) {
		$statement = strtoupper( trim( (string) $query ) );
		if ( isset( $this->failures[ $statement ] ) ) {
			$this->last_error = 'Issue #74 injected transaction failure: ' . $statement;
			return false;
		}

		return parent::query( $query );
	}
}

$GLOBALS['wpdb'] = new WPAI_Issue74_Transaction_Failure_WPDB( $failure_spec );
