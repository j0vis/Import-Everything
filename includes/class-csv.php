<?php
/**
 * Streaming CSV reader: header-row discovery, one record at a time, and the shared record spool.
 *
 * @package ImportEverything
 */

namespace ImportEverything;

defined( 'ABSPATH' ) || exit;

/**
 * Read comma, semicolon, tab or pipe separated files as records.
 *
 * A CSV has no nesting and no record element: the header row names the fields, one line is one record, and every
 * value is a single string. It is the format a Google Sheet exports to, which is why a sheet link is rewritten to
 * its CSV export before it is fetched. The file is read line by line, never whole, and the spool stores each
 * record as JSON so a later batch reads record k directly instead of re-scanning the file.
 */
final class CSV {
	/** The one record path a table has, so the client has something to show and re-select. */
	const PATH = '/rows';

	/**
	 * Longest raw line one read may return, in bytes.
	 *
	 * A record's decoded values sum to at most `XML::MAX_RECORD_BYTES`, but its raw line is longer: quoting
	 * doubles every quote it holds, and delimiters and quote pairs add up to three bytes per column (at most
	 * 1,500 columns). Twice the record cap plus slack covers the worst honest line while keeping one read from
	 * loading a whole multi-megabyte line before `record()` can measure it. A longer line is refused at the read.
	 */
	const LINE_MAX = 2 * XML::MAX_RECORD_BYTES + 8192;

	/**
	 * Analyze a file: the header row's columns become the field paths.
	 *
	 * @param string $file Local feed file.
	 * @param string $path Unused; a CSV has a single fixed record path.
	 * @return array Schema in the shape the XML and JSON readers return.
	 * @throws \RuntimeException When the file cannot be read, has no records, or exceeds a shared limit.
	 */
	public static function analyze( string $file, string $path = '' ): array {
		$total   = 0;
		$fields  = array();
		$samples = array();
		foreach ( self::records( $file ) as $record ) {
			++$total;
			foreach ( $record as $field => $values ) {
				if ( ! isset( $fields[ $field ] ) ) {
					$fields[ $field ] = array(
						'path'     => $field,
						'sample'   => '',
						'present'  => 0,
						'repeated' => false,
						'values'   => 1,
					);
				}
				++$fields[ $field ]['present'];
				if ( '' === $fields[ $field ]['sample'] && '' !== (string) $values[0] ) {
					$fields[ $field ]['sample'] = mb_substr( (string) $values[0], 0, 240 );
				}
			}
			if ( count( $samples ) < 5 ) {
				$samples[] = $record;
			}
		}
		if ( 0 === $total ) {
			throw new \RuntimeException( 'This file has a header row but no records.' );
		}
		return array(
			'path'       => self::PATH,
			'total'      => $total,
			'fields'     => array_values( $fields ),
			'samples'    => $samples,
			'candidates' => array( array( 'path' => self::PATH, 'count' => $total ) ),
		);
	}

	/**
	 * Read records from a CSV file, one line at a time.
	 *
	 * Cells past the header row's last column are ignored, and a short row fills the rest with empty values, so
	 * every record carries the same fields whatever the file does.
	 *
	 * @param string $file   Local feed file.
	 * @param int    $offset First record to yield.
	 * @param int    $limit  Most records to yield.
	 * @return \Generator<int, array<string, string[]>>
	 * @throws \RuntimeException When the file cannot be read or exceeds a shared limit.
	 */
	public static function records( string $file, int $offset = 0, int $limit = PHP_INT_MAX ): \Generator {
		$handle = fopen( $file, 'rb' );
		if ( ! $handle ) {
			throw new \RuntimeException( 'Cannot read the uploaded feed.' );
		}
		try {
			$head  = self::header( $handle );
			$index = 0;
			$sent  = 0;
			while ( false !== ( $row = self::line( $handle, $head['delimiter'] ) ) ) {
				if ( self::blank( $row ) ) {
					continue;
				}
				++$index;
				if ( $index > XML::MAX_RECORDS ) {
					throw new \RuntimeException( 'The feed exceeds the limit of 20,000 selected records.' );
				}
				if ( $index <= $offset ) {
					continue;
				}
				if ( $sent >= $limit ) {
					break;
				}
				++$sent;
				yield self::record( $row, $head['names'] );
			}
		} finally {
			fclose( $handle );
		}
	}

	/**
	 * Copy every record into a spool as one JSON body per record.
	 *
	 * @param string $file  Local feed file.
	 * @param string $spool Destination filename.
	 * @return int Records written.
	 * @throws \RuntimeException When the file cannot be read or a record cannot be encoded.
	 */
	public static function spool( string $file, string $spool ): int {
		return Spool::write( $spool, self::bodies( $file ) );
	}

	/**
	 * Read spooled records back, decoding the JSON body each frame holds.
	 *
	 * @param string $spool  Spool filename.
	 * @param int    $offset First record to yield.
	 * @param int    $limit  Most records to yield.
	 * @return \Generator<int, array<string, string[]>>
	 * @throws \RuntimeException When a spooled body is not a record, so a damaged record cannot vanish silently
	 *                           and leave the job's offset one row short of the file.
	 */
	public static function spooled( string $spool, int $offset = 0, int $limit = PHP_INT_MAX ): \Generator {
		foreach ( Spool::read( $spool, $offset, $limit ) as $body ) {
			$record = json_decode( (string) $body, true );
			if ( ! is_array( $record ) ) {
				throw new \RuntimeException( 'A record could not be read back from its private import copy.' );
			}
			yield $record;
		}
	}

	/**
	 * The JSON bodies a spool stores, one per record.
	 *
	 * @param string $file Local feed file.
	 * @return \Generator<int, string>
	 * @throws \RuntimeException When a record cannot be encoded.
	 */
	private static function bodies( string $file ): \Generator {
		foreach ( self::records( $file ) as $record ) {
			$body = json_encode( $record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
			if ( false === $body ) {
				throw new \RuntimeException( 'A record could not be encoded for the import.' );
			}
			yield $body;
		}
	}

	/**
	 * Read the header row and the delimiter it is split by, leaving the handle on the first data row.
	 *
	 * @param resource $handle Open file handle.
	 * @return array{delimiter: string, names: string[]}
	 * @throws \RuntimeException When the file has no header row.
	 */
	private static function header( $handle ): array {
		// The delimiter is read from the header line, so blank lines above it must not be what gets probed.
		$probe = false;
		while ( false !== ( $line = fgets( $handle, self::LINE_MAX + 2 ) ) ) {
			// Read past the ceiling, so a line that just fits still reads whole and a longer one comes back over the
			// ceiling: a fragment is refused here instead of being probed for a delimiter.
			if ( strlen( $line ) > self::LINE_MAX ) {
				throw new \RuntimeException( 'A line in this feed is larger than the supported record-size limit.' );
			}
			$first = str_starts_with( $line, "\xEF\xBB\xBF" ) ? substr( $line, 3 ) : $line;
			if ( '' !== trim( $first ) ) {
				$probe = $first;
				break;
			}
		}
		if ( false === $probe ) {
			throw new \RuntimeException( 'This file is empty.' );
		}
		$delimiter = self::delimiter( $probe );
		rewind( $handle );
		$row = self::line( $handle, $delimiter );
		while ( false !== $row && self::blank( $row ) ) {
			$row = self::line( $handle, $delimiter );
		}
		if ( false === $row ) {
			throw new \RuntimeException( 'This file has a header row but no records.' );
		}
		return array(
			'delimiter' => $delimiter,
			'names'     => self::names( $row ),
		);
	}

	/**
	 * The delimiter a line is separated by: the one that appears most often outside quotes, comma on a tie.
	 *
	 * @param string $line One CSV line.
	 */
	private static function delimiter( string $line ): string {
		// Quoted runs are removed first, so a separator inside a value is not counted as one between values.
		// A doubled quote needs no special case: the second quote of the pair simply opens the next run, and the
		// characters of both runs are removed either way.
		$plain = (string) preg_replace( '/"[^"]*"/', '', $line );
		$best  = ',';
		$score = 0;
		foreach ( array( ',', ';', "\t", '|' ) as $candidate ) {
			$count = substr_count( $plain, $candidate );
			if ( $count > $score ) {
				$score = $count;
				$best  = $candidate;
			}
		}
		return $best;
	}

	/**
	 * One parsed line, with a UTF-8 BOM stripped from its first cell.
	 *
	 * @param resource $handle    Open file handle.
	 * @param string   $delimiter Field delimiter.
	 * @return string[]|false The cells, or false at the end of the file.
	 */
	private static function line( $handle, string $delimiter ) {
		$start = (int) ftell( $handle );
		$row   = fgetcsv( $handle, self::LINE_MAX + 2, $delimiter, '"', '' );
		if ( ! is_array( $row ) ) {
			return false;
		}
		// The read is capped, so the bytes it consumed are how a longer line shows itself: one that took more than
		// the ceiling was cut at it, and this fragment is refused instead of imported as a row.
		if ( (int) ftell( $handle ) - $start > self::LINE_MAX ) {
			// Its own message, not `record()`'s: this refuses a line the record-size check would only catch after
			// holding it whole, which is the read the ceiling exists to prevent.
			throw new \RuntimeException( 'A line in this feed is larger than the supported record-size limit.' );
		}
		if ( isset( $row[0] ) && is_string( $row[0] ) && str_starts_with( $row[0], "\xEF\xBB\xBF" ) ) {
			$row[0] = substr( $row[0], 3 );
		}
		return $row;
	}

	/**
	 * Whether a row holds nothing but empty cells, so blank lines between records are skipped.
	 *
	 * @param string[] $row Parsed cells.
	 */
	private static function blank( array $row ): bool {
		foreach ( $row as $cell ) {
			if ( '' !== trim( (string) $cell ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Field names for a header row: an empty cell becomes `column_N`, and a repeated name gets a numeric suffix
	 * so two columns never share one path.
	 *
	 * @param string[] $row Header cells.
	 * @return string[]
	 * @throws \RuntimeException When the header has more columns than the shared field limit.
	 */
	private static function names( array $row ): array {
		if ( count( $row ) > XML::MAX_FIELDS ) {
			throw new \RuntimeException( 'The feed exceeds the limit of 1,500 distinct field paths.' );
		}
		$names = array();
		$seen  = array();
		foreach ( array_values( $row ) as $index => $cell ) {
			$base = trim( (string) $cell );
			if ( '' === $base ) {
				$base = 'column_' . ( $index + 1 );
			}
			$name = $base;
			for ( $n = 2; isset( $seen[ $name ] ); ++$n ) {
				$name = $base . '_' . $n;
			}
			$seen[ $name ] = true;
			$names[]       = $name;
		}
		return $names;
	}

	/**
	 * One record, as the flat path-to-values map the import path reads.
	 *
	 * @param string[] $row   Parsed cells beyond the header row.
	 * @param string[] $names Header field names.
	 * @throws \RuntimeException When the record is larger than the shared record-size limit.
	 */
	private static function record( array $row, array $names ): array {
		$record = array();
		$size   = 0;
		foreach ( $names as $index => $name ) {
			$value            = isset( $row[ $index ] ) ? (string) $row[ $index ] : '';
			$size            += strlen( $value );
			$record[ $name ]  = array( $value );
		}
		if ( $size > XML::MAX_RECORD_BYTES ) {
			throw new \RuntimeException( 'A record in this feed is larger than the supported record-size limit.' );
		}
		return $record;
	}
}
