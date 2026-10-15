<?php
/**
 * Bounded identity verification of an administrator-selected replacement ZIP.
 *
 * @package WP_AI_Bridge
 */

namespace WP_AI_Bridge\Support;

use WP_Error;

/**
 * Inspect only the package's native archive directory and canonical headers.
 * WordPress Core still owns actual extraction, installation and validation.
 */
final class Extension_Package_Inspector {

	/**
	 * @param string $file             Downloaded private temporary ZIP.
	 * @param string $kind             Plugin or theme.
	 * @param string $target           Installed WordPress plugin basename or theme stylesheet.
	 * @param string $expected_version Explicitly selected incoming version.
	 * @param int    $max_bytes        Effective bounded download allowance.
	 * @return true|WP_Error
	 */
	public function inspect( $file, $kind, $target, $expected_version, $max_bytes ) {
		if ( ! class_exists( '\\ZipArchive' ) ) {
			return $this->failure( 'external_package_inspection_unavailable' );
		}
		$zip = new \ZipArchive();
		if ( true !== $zip->open( $file, \ZipArchive::RDONLY ) ) {
			return $this->failure( 'external_package_archive_invalid' );
		}
		try {
			$root   = 'plugin' === $kind ? dirname( $target ) : $target;
			$header = 'plugin' === $kind ? $target : $target . '/style.css';
			$prefix = $root . '/';
			$seen   = array();
			$total  = 0;
			$budget = min( 1073741824, max( 1048576, $max_bytes * 12 ) );
			// Vendor ZipArchive exposes the camel-cased numFiles property.
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
			$file_count = (int) $zip->numFiles;
			if ( $file_count < 1 || $file_count > 4096 ) {
				return $this->failure( 'external_package_archive_invalid' );
			}
			for ( $index = 0; $index < $file_count; $index++ ) {
				$stat = $zip->statIndex( $index );
				if ( ! is_array( $stat ) || ! isset( $stat['name'], $stat['size'] ) ) {
					return $this->failure( 'external_package_archive_invalid' );
				}
				$name = (string) $stat['name'];
				$size = $stat['size'];
				if ( '' === $name || strlen( $name ) > 1024 || ! str_starts_with( $name, $prefix )
					|| preg_match( '/[\\x00-\\x1f\\x7f\\\\:]/', $name ) || str_contains( $name, '//' )
					|| isset( $seen[ strtolower( $name ) ] ) || ! is_int( $size ) || $size < 0 || $size > $budget - $total ) {
					return $this->failure( 'external_package_archive_invalid' );
				}
				foreach ( explode( '/', trim( $name, '/' ) ) as $part ) {
					if ( '' === $part || '.' === $part || '..' === $part ) {
						return $this->failure( 'external_package_archive_invalid' );
					}
				}
				$seen[ strtolower( $name ) ] = true;
				$total                      += $size;
				$opsys                       = 0;
				$attrs                       = 0;
				if ( ! $zip->getExternalAttributesIndex( $index, $opsys, $attrs ) ) {
					return $this->failure( 'external_package_archive_invalid' );
				}
				// Never pass UNIX filesystem links/devices to the native installer.
				if ( \ZipArchive::OPSYS_UNIX === $opsys ) {
					$mode = ( $attrs >> 16 ) & 0170000;
					if ( 0 !== $mode && 0100000 !== $mode && 0040000 !== $mode ) {
						return $this->failure( 'external_package_archive_invalid' );
					}
				}
			}
			if ( ! isset( $seen[ strtolower( $header ) ] ) ) {
				return $this->failure( 'external_package_identity_mismatch' );
			}
			$prefix_data = $zip->getFromName( $header, 8192 );
			if ( ! is_string( $prefix_data ) || '' === $prefix_data ) {
				return $this->failure( 'external_package_identity_mismatch' );
			}
			$name_header = 'plugin' === $kind ? 'Plugin Name' : 'Theme Name';
			if ( ! preg_match( '/^[ \\t\\/\\*#@]*' . preg_quote( $name_header, '/' ) . ':[ \\t]*(.+)$/mi', $prefix_data, $title )
				|| '' === trim( $title[1] )
				|| ! preg_match( '/^[ \\t\\/\\*#@]*Version:[ \\t]*(.+)$/mi', $prefix_data, $version )
				|| ! hash_equals( $expected_version, trim( $version[1] ) ) ) {
				return $this->failure( 'external_package_identity_mismatch' );
			}
			return true;
		} finally {
			$zip->close();
		}
	}

	/** @param string $code Allowlisted public error code. @return WP_Error */
	private function failure( $code ) {
		return new WP_Error( $code, __( 'The replacement ZIP did not pass the required package identity and safety checks.', 'wp-ai-bridge' ) );
	}
}
