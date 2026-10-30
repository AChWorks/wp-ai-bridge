<?php
/**
 * Private packet relay that drops exactly one native COMMIT acknowledgement.
 *
 * Test subprocess only. Never logs authentication packets, SQL or row bytes.
 *
 * @package WP_AI_Bridge
 */

$socket = getenv( 'WPAI199W_PROXY_SOCKET' );
$upstream = getenv( 'WPAI199W_PROXY_UPSTREAM' );
$mode = getenv( 'WPAI199W_PROXY_MODE' );
if ( ! is_string( $socket ) || 1 !== preg_match( '/^wpai199w-proxy-[a-f0-9]{12}\.sock$/D', basename( $socket ) ) ||
	! is_string( $upstream ) || ! in_array( $mode, array( 'before', 'after' ), true ) ||
	( ! str_starts_with( $upstream, 'unix://' ) && ! str_starts_with( $upstream, 'tcp://' ) ) ) {
	throw new RuntimeException( 'Invalid private database transport checkpoint.' );
}
function wpai199w_packet( $stream ) {
	$header = '';
	while ( strlen( $header ) < 4 && ! feof( $stream ) ) {
		$part = fread( $stream, 4 - strlen( $header ) );
		if ( false === $part || '' === $part ) {
			return null;
		}
		$header .= $part;
	}
	if ( 4 !== strlen( $header ) ) {
		return null;
	}
	$length = ord( $header[0] ) + ( ord( $header[1] ) << 8 ) + ( ord( $header[2] ) << 16 );
	$payload = '';
	while ( strlen( $payload ) < $length && ! feof( $stream ) ) {
		$part = fread( $stream, min( 65536, $length - strlen( $payload ) ) );
		if ( false === $part || '' === $part ) {
			return null;
		}
		$payload .= $part;
	}
	return strlen( $payload ) === $length ? array( $header . $payload, $payload ) : null;
}
function wpai199w_proxy_write( $stream, $bytes ) {
	$offset = 0;
	while ( $offset < strlen( $bytes ) ) {
		$written = fwrite( $stream, substr( $bytes, $offset ) );
		if ( false === $written || $written < 1 ) {
			throw new RuntimeException( 'Private packet relay write failed.' );
		}
		$offset += $written;
	}
}
$listener = stream_socket_server( 'unix://' . $socket, $errno, $error );
if ( ! is_resource( $listener ) ) {
	throw new RuntimeException( 'Cannot open task-private database relay.' );
}
chmod( $socket, 0600 );
$client = null;
$server = null;
$summary = array( 'commit_seen' => 0, 'commit_forwarded' => 0, 'server_commit_ok' => false, 'reply_dropped' => false );
try {
	$client = stream_socket_accept( $listener, 20 );
	if ( ! is_resource( $client ) ) {
		throw new RuntimeException( 'Database relay client did not arrive.' );
	}
	$server = stream_socket_client( $upstream, $errno, $error, 10 );
	if ( ! is_resource( $server ) ) {
		throw new RuntimeException( 'Database relay upstream unavailable.' );
	}
	stream_set_timeout( $client, 10 );
	stream_set_timeout( $server, 10 );
	$drop_reply = false;
	$deadline = microtime( true ) + 40;
	while ( microtime( true ) < $deadline ) {
		$read = array( $client, $server );
		$write = null;
		$except = null;
		if ( stream_select( $read, $write, $except, 2 ) < 1 ) {
			continue;
		}
		foreach ( $read as $stream ) {
			$packet = wpai199w_packet( $stream );
			if ( null === $packet ) {
				break 2;
			}
			if ( $stream === $client ) {
				$is_commit = strlen( $packet[1] ) > 1 && "\x03" === $packet[1][0] &&
					'COMMIT AND NO CHAIN NO RELEASE' === strtoupper( trim( substr( $packet[1], 1 ) ) );
				if ( $is_commit ) {
					++$summary['commit_seen'];
					if ( 'before' === $mode ) {
						$summary['reply_dropped'] = true;
						break 2;
					}
					++$summary['commit_forwarded'];
					$drop_reply = true;
				}
				wpai199w_proxy_write( $server, $packet[0] );
			} else {
				if ( $drop_reply ) {
					$summary['server_commit_ok'] = isset( $packet[1][0] ) && "\x00" === $packet[1][0];
					$summary['reply_dropped'] = true;
					break 2;
				}
				wpai199w_proxy_write( $client, $packet[0] );
			}
		}
	}
} finally {
	if ( is_resource( $client ) ) {
		fclose( $client );
	}
	if ( is_resource( $server ) ) {
		fclose( $server );
	}
	fclose( $listener );
	if ( file_exists( $socket ) ) {
		unlink( $socket );
	}
}
echo 'WPAI199W_PROXY_RESULT=' . json_encode( $summary ) . "\n";
