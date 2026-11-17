<?php
/**
 * Private record spool shared by the XML, JSON and CSV readers.
 *
 * @package ImportEverything
 */

namespace ImportEverything;

defined( 'ABSPATH' ) || exit;

/**
 * The container a job's records are copied into, so a later request reads record k directly.
 *
 * Layout: a 12-byte header (magic, record count, index offset), then one frame per record (a 4-byte big-endian
 * length followed by the record's raw bytes), then one 4-byte little-endian offset per record. The record bytes
 * are opaque here: the XML reader stores outer XML, the JSON reader stores one element's JSON text, the CSV
 * reader stores one row as JSON, and only the reader that wrote the spool knows how to flatten them.
 */
final class Spool {
	/** Magic marker at the head of a record spool. */
	const MAGIC = 'UXSL';

	/** Size of the spool header: magic, record count, index offset. */
	const HEADER = 12;

	/**
	 * Write raw record bodies to a spool.
	 *
	 * @param string           $out     Destination filename.
	 * @param iterable<string> $records Raw record bodies.
	 * @return int Records written.
	 * @throws \RuntimeException When a record is oversized or the file cannot be written.
	 */
	public static function write( string $out, iterable $records ): int {
		$handle = fopen( $out, 'wb' );
		if ( ! $handle ) {
			throw new \RuntimeException( 'Unable to create the private import file.' );
		}
		// Spools hold a private copy of the feed, so keep them out of other users' reach on shared hosts.
		@chmod( $out, 0600 );
		$offsets = array();
		$at      = self::HEADER;
		try {
			if ( self::HEADER !== fwrite( $handle, pack( 'A4VV', self::MAGIC, 0, 0 ) ) ) {
				throw new \RuntimeException( 'Unable to write the private import file.' );
			}
			foreach ( $records as $body ) {
				$body = (string) $body;
				if ( strlen( $body ) > XML::MAX_RECORD_BYTES ) {
					throw new \RuntimeException( 'A record in this feed is larger than the supported record-size limit.' );
				}
				$frame = pack( 'N', strlen( $body ) ) . $body;
				if ( strlen( $frame ) !== fwrite( $handle, $frame ) ) {
					throw new \RuntimeException( 'Unable to write the private import file.' );
				}
				$offsets[] = $at;
				$at       += strlen( $frame );
			}
			$index = $at;
			foreach ( $offsets as $offset ) {
				if ( 4 !== fwrite( $handle, pack( 'V', $offset ) ) ) {
					throw new \RuntimeException( 'Unable to write the private import file.' );
				}
			}
			if ( 0 !== fseek( $handle, 0 ) || self::HEADER !== fwrite( $handle, pack( 'A4VV', self::MAGIC, count( $offsets ), $index ) ) ) {
				throw new \RuntimeException( 'Unable to write the private import file.' );
			}
		} finally {
			fclose( $handle );
		}
		return count( $offsets );
	}

	/**
	 * Validate a spool header without reading any record.
	 *
	 * @param string $spool Spool filename.
	 * @return array|null [records, index offset, file size], or null when the file is not a usable spool.
	 */
	private static function head( string $spool ): ?array {
		if ( ! is_file( $spool ) ) {
			return null;
		}
		$size = filesize( $spool );
		if ( false === $size || $size < self::HEADER ) {
			return null;
		}
		$handle = fopen( $spool, 'rb' );
		if ( ! $handle ) {
			return null;
		}
		$head = fread( $handle, self::HEADER );
		fclose( $handle );
		if ( self::HEADER !== strlen( $head ) ) {
			return null;
		}
		$parsed = unpack( 'A4magic/Vrecords/Vindex', $head );
		if ( self::MAGIC !== $parsed['magic'] || $parsed['records'] > XML::MAX_RECORDS ) {
			return null;
		}
		// The index holds one 4-byte slot per record and must sit inside the file.
		if ( $parsed['index'] < self::HEADER || $parsed['index'] + 4 * $parsed['records'] > $size ) {
			return null;
		}
		return array( (int) $parsed['records'], (int) $parsed['index'], (int) $size );
	}

	/**
	 * How many records a spool holds, or -1 when it is missing, foreign, or truncated.
	 *
	 * @param string $spool Spool filename.
	 */
	public static function count( string $spool ): int {
		$head = self::head( $spool );
		return null === $head ? -1 : $head[0];
	}

	/**
	 * Yield raw record bodies straight out of a spool, starting at record $offset.
	 *
	 * @param string $spool  Spool filename.
	 * @param int    $offset First record to yield.
	 * @param int    $limit  Maximum records to yield.
	 * @throws \RuntimeException When the spool is unreadable or corrupt.
	 */
	public static function read( string $spool, int $offset, int $limit = PHP_INT_MAX ): \Generator {
		$head = self::head( $spool );
		if ( null === $head ) {
			throw new \RuntimeException( 'The private import file for this session is unavailable.' );
		}
		$handle = fopen( $spool, 'rb' );
		if ( ! $handle ) {
			throw new \RuntimeException( 'The private import file for this session is unavailable.' );
		}
		$sent = 0;
		try {
			for ( $index = max( 0, $offset ); $index < $head[0] && $sent < $limit; ++$index ) {
				if ( 0 !== fseek( $handle, $head[1] + 4 * $index ) ) {
					throw new \RuntimeException( 'The private import file for this session is unreadable.' );
				}
				$slot = fread( $handle, 4 );
				if ( 4 !== strlen( $slot ) ) {
					throw new \RuntimeException( 'The private import file for this session is unreadable.' );
				}
				$frame = unpack( 'V', $slot )[1];
				if ( $frame < self::HEADER || $frame + 4 > $head[2] ) {
					throw new \RuntimeException( 'The private import file for this session is unreadable.' );
				}
				if ( 0 !== fseek( $handle, $frame ) ) {
					throw new \RuntimeException( 'The private import file for this session is unreadable.' );
				}
				$header = fread( $handle, 4 );
				if ( 4 !== strlen( $header ) ) {
					throw new \RuntimeException( 'The private import file for this session is unreadable.' );
				}
				$length = (int) unpack( 'N', $header )[1];
				// The cap is rechecked here so a spool edited outside the plugin cannot bypass it.
				if ( $length > XML::MAX_RECORD_BYTES ) {
					throw new \RuntimeException( 'A record in this feed is larger than the supported record-size limit.' );
				}
				$body = fread( $handle, $length );
				if ( strlen( $body ) !== $length ) {
					throw new \RuntimeException( 'The private import file for this session is unreadable.' );
				}
				yield $body;
				++$sent;
			}
		} finally {
			fclose( $handle );
		}
	}
}
