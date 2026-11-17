<?php
/**
 * Streaming JSON discovery. No JSONPath or executable expressions are accepted.
 *
 * @package ImportEverything
 */

namespace ImportEverything;

defined( 'ABSPATH' ) || exit;

/**
 * JSON parser independent of WordPress for fixture testing.
 *
 * A JSON feed cannot be decoded whole: a 20 MiB document needs several times that in memory, and this plugin's
 * documented floor is a 64M limit. Records are therefore located and copied with a small tokenizer that reads
 * the file in chunks, and only one record at a time is decoded. The tokenizer never evaluates anything: keys
 * become field paths, and every path a client can map is re-checked against the schema this server discovered.
 */
final class JSON {
	/** Bytes read per refill. */
	const CHUNK = 65536;

	/** JSON nesting this parser accepts, mirroring the XML depth guard. */
	const MAX_DEPTH = 48;

	/** Open file handle. */
	private $handle;

	/** Bytes read but not yet consumed, and the index of the next unconsumed byte in them. */
	private string $buffer = '';
	private int $at = 0;

	/** Absolute byte offset of the next unconsumed byte, used in parse errors. */
	private int $pos = 0;

	/** Captured bytes while one record is being copied, or null while only scanning. */
	private ?string $capture = null;

	/** Candidate record arrays found so far, keyed by path. */
	private array $found = array();

	/** Record path a copy pass reads, or an empty string for a discovery pass. */
	private string $target = '';

	/** Records to skip before the first one is yielded, and how many may be yielded. */
	private int $skip = 0;
	private int $limit = PHP_INT_MAX;

	/** Records yielded so far, and whether a limit ended the walk early. */
	private int $sent = 0;
	private bool $done = false;

	/**
	 * Open and validate a local JSON file.
	 *
	 * @param string $file Local JSON filename.
	 * @throws \RuntimeException When the file cannot be read safely.
	 */
	private function __construct( string $file ) {
		if ( ! is_file( $file ) || 0 === filesize( $file ) || filesize( $file ) > XML::MAX_BYTES ) {
			throw new \RuntimeException( 'JSON must be a non-empty file of at most 20 MiB.' );
		}
		$handle = fopen( $file, 'rb' );
		if ( ! $handle ) {
			throw new \RuntimeException( 'Cannot open the JSON file.' );
		}
		$this->handle = $handle;
		$this->ensure( 3 );
		// A UTF-8 BOM is not part of the document, and some editors write one.
		if ( "\xEF\xBB\xBF" === substr( $this->buffer, 0, 3 ) ) {
			$this->at  = 3;
			$this->pos = 3;
		}
	}

	/** Close the file handle. */
	private function close(): void {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
		}
	}

	/**
	 * Keep at least $bytes readable in the buffer, compacting what was consumed.
	 *
	 * @param int $bytes Bytes required at the current offset.
	 */
	private function ensure( int $bytes ): void {
		while ( strlen( $this->buffer ) - $this->at < $bytes ) {
			if ( feof( $this->handle ) ) {
				return;
			}
			$chunk = fread( $this->handle, max( self::CHUNK, $bytes ) );
			if ( false === $chunk || '' === $chunk ) {
				return;
			}
			if ( $this->at > 0 ) {
				$this->buffer = substr( $this->buffer, $this->at );
				$this->at     = 0;
			}
			$this->buffer .= $chunk;
		}
	}

	/** The next unconsumed byte, or null at the end of the file. */
	private function peek(): ?string {
		$this->ensure( 1 );
		return $this->at < strlen( $this->buffer ) ? $this->buffer[ $this->at ] : null;
	}

	/** Consume and return the next byte, appending it to the capture when one is active. */
	private function take(): ?string {
		$char = $this->peek();
		if ( null === $char ) {
			return null;
		}
		++$this->at;
		++$this->pos;
		if ( null !== $this->capture ) {
			$this->capture .= $char;
		}
		return $char;
	}

	/**
	 * Consume exactly $bytes bytes.
	 *
	 * @param int $bytes Bytes to consume.
	 */
	private function advance( int $bytes ): void {
		for ( $i = 0; $i < $bytes; ++$i ) {
			$this->take();
		}
	}

	/** Skip JSON whitespace. */
	private function skip_space(): void {
		while ( null !== ( $char = $this->peek() ) && false !== strpos( " \t\r\n", $char ) ) {
			$this->take();
		}
	}

	/**
	 * Whether the next bytes spell one of the JSON literals.
	 *
	 * @param string $word Literal to match.
	 */
	private function match_word( string $word ): bool {
		$this->ensure( strlen( $word ) );
		return substr( $this->buffer, $this->at, strlen( $word ) ) === $word;
	}

	/**
	 * A parse error that names where it happened, without disclosing the feed's contents.
	 *
	 * @param string $detail What was expected.
	 */
	private function fail( string $detail ): \RuntimeException {
		return new \RuntimeException( 'Invalid JSON near byte ' . $this->pos . ': ' . $detail );
	}

	/**
	 * Parse one quoted string and return its decoded value.
	 *
	 * @throws \RuntimeException When the token is not a valid JSON string.
	 */
	private function string_token(): string {
		$this->take();
		$raw = '"';
		while ( true ) {
			$char = $this->take();
			if ( null === $char ) {
				throw $this->fail( 'a string is not terminated.' );
			}
			$raw .= $char;
			if ( '\\' === $char ) {
				$next = $this->take();
				if ( null === $next ) {
					throw $this->fail( 'a string is not terminated.' );
				}
				$raw .= $next;
				continue;
			}
			if ( '"' === $char ) {
				break;
			}
			if ( ord( $char ) < 0x20 ) {
				throw $this->fail( 'a string contains a control character.' );
			}
		}
		$value = json_decode( $raw, true );
		if ( ! is_string( $value ) ) {
			throw $this->fail( 'a string could not be decoded.' );
		}
		return $value;
	}

	/**
	 * Consume one JSON number without evaluating it.
	 *
	 * @throws \RuntimeException When the token is not a valid JSON number.
	 */
	private function number_token(): void {
		if ( '-' === $this->peek() ) {
			$this->take();
		}
		$first = $this->peek();
		if ( null === $first || ! ctype_digit( $first ) ) {
			throw $this->fail( 'expected a number.' );
		}
		if ( '0' === $first ) {
			$this->take();
		} else {
			while ( null !== ( $char = $this->peek() ) && ctype_digit( $char ) ) {
				$this->take();
			}
		}
		if ( '.' === $this->peek() ) {
			$this->take();
			$digit = $this->peek();
			if ( null === $digit || ! ctype_digit( $digit ) ) {
				throw $this->fail( 'expected a digit after a decimal point.' );
			}
			while ( null !== ( $char = $this->peek() ) && ctype_digit( $char ) ) {
				$this->take();
			}
		}
		if ( 'e' === $this->peek() || 'E' === $this->peek() ) {
			$this->take();
			if ( '+' === $this->peek() || '-' === $this->peek() ) {
				$this->take();
			}
			$digit = $this->peek();
			if ( null === $digit || ! ctype_digit( $digit ) ) {
				throw $this->fail( 'expected a digit in an exponent.' );
			}
			while ( null !== ( $char = $this->peek() ) && ctype_digit( $char ) ) {
				$this->take();
			}
		}
	}

	/**
	 * Parse one JSON value, yielding the raw text of each record the copy pass selects.
	 *
	 * @param string $path  Absolute path of the value, using `/` segments.
	 * @param int    $depth Nesting depth, bounded by MAX_DEPTH.
	 * @throws \RuntimeException When the value is malformed or too deeply nested.
	 */
	private function value( string $path, int $depth ): \Generator {
		if ( $this->done ) {
			return;
		}
		if ( $depth > self::MAX_DEPTH ) {
			throw new \RuntimeException( 'JSON nesting exceeds the supported depth of 48.' );
		}
		$this->skip_space();
		$char = $this->peek();
		if ( null === $char ) {
			throw $this->fail( 'unexpected end of file.' );
		}
		if ( '{' === $char ) {
			yield from $this->object( $path, $depth );
			return;
		}
		if ( '[' === $char ) {
			yield from $this->array_value( $path, $depth );
			return;
		}
		if ( '"' === $char ) {
			$this->string_token();
			return;
		}
		if ( '-' === $char || ctype_digit( $char ) ) {
			$this->number_token();
			return;
		}
		foreach ( array( 'true', 'false', 'null' ) as $word ) {
			if ( $this->match_word( $word ) ) {
				$this->advance( strlen( $word ) );
				return;
			}
		}
		throw $this->fail( 'unexpected character.' );
	}

	/**
	 * Parse an object, naming each child by its key.
	 *
	 * @param string $path  Absolute path of the object.
	 * @param int    $depth Nesting depth.
	 */
	private function object( string $path, int $depth ): \Generator {
		$this->take();
		$this->skip_space();
		if ( '}' === $this->peek() ) {
			$this->take();
			return;
		}
		while ( ! $this->done ) {
			$this->skip_space();
			if ( '"' !== $this->peek() ) {
				throw $this->fail( 'expected an object key.' );
			}
			$key = $this->string_token();
			$this->skip_space();
			if ( ':' !== $this->peek() ) {
				throw $this->fail( 'expected a colon after an object key.' );
			}
			$this->take();
			yield from $this->value( self::child( $path, $key ), $depth + 1 );
			if ( $this->done ) {
				return;
			}
			$this->skip_space();
			$char = $this->take();
			if ( ',' === $char ) {
				continue;
			}
			if ( '}' === $char ) {
				return;
			}
			throw $this->fail( 'expected a comma or the end of an object.' );
		}
	}

	/**
	 * Parse an array, recording it as a candidate when its elements are objects, and copying its elements
	 * when it is the record path this pass reads.
	 *
	 * @param string $path  Absolute path of the array.
	 * @param int    $depth Nesting depth.
	 */
	private function array_value( string $path, int $depth ): \Generator {
		$this->take();
		$path = '' === $path ? '/' : $path;
		$this->skip_space();
		if ( ']' === $this->peek() ) {
			$this->take();
			return;
		}
		$count   = 0;
		$objects = false;
		while ( ! $this->done ) {
			$this->skip_space();
			if ( 0 === $count ) {
				$objects = '{' === $this->peek();
			}
			$number = $count;
			++$count;
			if ( $this->target === $path && $number >= $this->skip && null === $this->capture ) {
				if ( $this->sent >= $this->limit ) {
					$this->done = true;
					return;
				}
				$this->capture = '';
				yield from $this->value( $path, $depth + 1 );
				$raw           = (string) $this->capture;
				$this->capture = null;
				if ( strlen( $raw ) > XML::MAX_RECORD_BYTES ) {
					throw new \RuntimeException( 'A record in this feed is larger than the supported record-size limit.' );
				}
				++$this->sent;
				yield $raw;
			} else {
				yield from $this->value( $path, $depth + 1 );
			}
			if ( $this->done ) {
				return;
			}
			$this->skip_space();
			$char = $this->take();
			if ( ']' === $char ) {
				break;
			}
			if ( ',' !== $char ) {
				throw $this->fail( 'expected a comma or the end of an array.' );
			}
			$this->skip_space();
			if ( ']' === $this->peek() ) {
				throw $this->fail( 'a trailing comma is not valid JSON.' );
			}
		}
		if ( $objects ) {
			if ( ! isset( $this->found[ $path ] ) ) {
				if ( count( $this->found ) >= XML::MAX_CANDIDATES ) {
					throw new \RuntimeException( 'This JSON feed has more than ' . XML::MAX_CANDIDATES . ' distinct record paths. Choose a smaller feed.' );
				}
				$this->found[ $path ] = array(
					'path'  => $path,
					'tag'   => self::tag( $path ),
					'count' => 0,
					'depth' => substr_count( $path, '/' ),
				);
			}
			$this->found[ $path ]['count'] += $count;
		}
	}

	/**
	 * Parse the document, yielding the raw text of each selected record.
	 *
	 * @throws \RuntimeException When the document is malformed or has content after its root value.
	 */
	private function document(): \Generator {
		$this->skip_space();
		$char = $this->peek();
		if ( null === $char ) {
			throw new \RuntimeException( 'This JSON feed is empty.' );
		}
		if ( '{' !== $char && '[' !== $char ) {
			throw new \RuntimeException( 'JSON must start with an object or an array.' );
		}
		yield from $this->value( '', 0 );
		if ( $this->done ) {
			return;
		}
		$this->skip_space();
		if ( null !== $this->peek() ) {
			throw $this->fail( 'unexpected content after the document.' );
		}
	}

	/** Last path segment of a record path, for the record-element dropdown. */
	private static function tag( string $path ): string {
		$parts = explode( '/', trim( $path, '/' ) );
		$last  = (string) end( $parts );
		return '' === $last ? 'item' : str_replace( array( '~1', '~0' ), array( '/', '~' ), $last );
	}

	/**
	 * One escaped key path segment. JSON Pointer escaping keeps `a/b` and `a~1b` distinct; the two-line join
	 * separator cannot appear in a key, because it would make a field path ambiguous.
	 *
	 * @param string $key Object key.
	 * @throws \RuntimeException When the key contains the join separator.
	 */
	private static function segment( string $key ): string {
		if ( false !== strpos( $key, Importer::JOIN ) ) {
			throw new \RuntimeException( 'A JSON field name contains " + ", which this importer reserves for joining two fields into one value.' );
		}
		return str_replace( array( '~', '/' ), array( '~0', '~1' ), $key );
	}

	/**
	 * Absolute child path, used while scanning for record arrays.
	 *
	 * @param string $path Parent path.
	 * @param string $key  Child key.
	 */
	private static function child( string $path, string $key ): string {
		$segment = self::segment( $key );
		return '' === $path || '/' === $path ? '/' . $segment : $path . '/' . $segment;
	}

	/**
	 * Relative child path, used for a record's field names.
	 *
	 * @param string $path Parent field path.
	 * @param string $key  Child key.
	 */
	private static function field_child( string $path, string $key ): string {
		$segment = self::segment( $key );
		return '' === $path ? $segment : $path . '/' . $segment;
	}

	/**
	 * Flatten one JSON record into the same shape the XML parser produces: field path to values.
	 *
	 * Repeated arrays accumulate values under the same path, so an array of thumbnails behaves like a
	 * repeated XML element. `null` becomes an empty value, booleans become `true`/`false`, and numbers keep
	 * their JSON spelling because WordPress stores strings here.
	 *
	 * @param string $raw One JSON record.
	 * @throws \RuntimeException When the record cannot be decoded safely.
	 */
	public static function flatten( string $raw ): array {
		$data = json_decode( $raw, true, 64, JSON_BIGINT_AS_STRING );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			throw new \RuntimeException( 'Unable to parse this JSON record safely.' );
		}
		$fields = array();
		self::walk( $data, '', $fields, 0 );
		return $fields;
	}

	/**
	 * Walk one decoded record, keeping repeated values and bounding depth and field count.
	 *
	 * @param mixed  $value  Decoded value.
	 * @param string $path   Current relative field path.
	 * @param array  $fields Collected values by path.
	 * @param int    $depth  Current nesting depth.
	 * @throws \RuntimeException When the record nests too deeply or has too many fields.
	 */
	private static function walk( $value, string $path, array &$fields, int $depth ): void {
		if ( $depth > self::MAX_DEPTH ) {
			throw new \RuntimeException( 'JSON nesting exceeds the supported depth of 48.' );
		}
		if ( is_array( $value ) ) {
			if ( self::is_list( $value ) ) {
				foreach ( $value as $item ) {
					self::walk( $item, $path, $fields, $depth + 1 );
				}
			} else {
				foreach ( $value as $key => $item ) {
					self::walk( $item, self::field_child( $path, (string) $key ), $fields, $depth + 1 );
				}
			}
			if ( count( $fields ) > XML::MAX_FIELDS ) {
				throw new \RuntimeException( 'The feed exceeds the limit of 1,500 distinct field paths.' );
			}
			return;
		}
		$fields[ '' === $path ? '#text' : $path ][] = self::scalar( $value );
	}

	/**
	 * Whether a decoded array is a JSON list rather than an object.
	 *
	 * @param array $value Decoded array.
	 */
	private static function is_list( array $value ): bool {
		if ( array() === $value ) {
			return true;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * The text form of one JSON scalar.
	 *
	 * @param mixed $value Decoded scalar.
	 */
	private static function scalar( $value ): string {
		if ( null === $value ) {
			return '';
		}
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( is_string( $value ) ) {
			return $value;
		}
		return (string) json_encode( $value, JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}

	/**
	 * Enumerate record arrays: arrays whose elements are objects.
	 *
	 * @param string $file Local JSON filename.
	 * @return array [paths, feed_path, feed_name]
	 * @throws \RuntimeException When the document is malformed.
	 */
	public static function candidates( string $file ): array {
		$parser = new self( $file );
		try {
			foreach ( $parser->document() as $ignored ) {
				continue;
			}
		} finally {
			$parser->close();
		}
		$paths = array_values( $parser->found );
		usort(
			$paths,
			static function ( $a, $b ) {
				$depth = $a['depth'] <=> $b['depth'];
				if ( 0 !== $depth ) {
					return $depth;
				}
				$count = $b['count'] <=> $a['count'];
				return 0 !== $count ? $count : strcmp( $a['path'], $b['path'] );
			}
		);
		return array(
			'paths'     => $paths,
			'feed_path' => '',
			'feed_name' => '',
		);
	}

	/**
	 * Yield selected records, decoding one element at a time.
	 *
	 * @param string $file   Local JSON filename.
	 * @param string $path   Selected record array path, `/` for the document root.
	 * @param int    $offset Records to skip.
	 * @param int    $limit  Maximum records to yield.
	 * @throws \RuntimeException When the document is malformed.
	 */
	public static function records( string $file, string $path, int $offset = 0, int $limit = PHP_INT_MAX ): \Generator {
		$parser         = new self( $file );
		$parser->target = '' === $path ? '/' : $path;
		$parser->skip   = max( 0, $offset );
		$parser->limit  = max( 0, $limit );
		try {
			foreach ( $parser->document() as $raw ) {
				yield self::flatten( $raw );
			}
		} finally {
			$parser->close();
		}
	}

	/**
	 * Copy every selected record into the shared spool.
	 *
	 * @param string $file  Local JSON filename.
	 * @param string $path  Selected record array path.
	 * @param string $spool Destination filename.
	 * @return int Records written.
	 * @throws \RuntimeException When the spool cannot be written.
	 */
	public static function spool( string $file, string $path, string $spool ): int {
		$parser         = new self( $file );
		$parser->target = '' === $path ? '/' : $path;
		try {
			return Spool::write( $spool, $parser->document() );
		} finally {
			$parser->close();
		}
	}

	/**
	 * How many records a spool holds, or -1 when it is missing, foreign, or truncated.
	 *
	 * @param string $spool Spool filename.
	 */
	public static function spool_count( string $spool ): int {
		return Spool::count( $spool );
	}

	/**
	 * Yield flattened records straight out of a spool, starting at record $offset.
	 *
	 * @param string $spool  Spool filename.
	 * @param int    $offset First record to yield.
	 * @param int    $limit  Maximum records to yield.
	 * @throws \RuntimeException When the spool is unreadable or corrupt.
	 */
	public static function spooled( string $spool, int $offset, int $limit = PHP_INT_MAX ): \Generator {
		foreach ( Spool::read( $spool, $offset, $limit ) as $raw ) {
			yield self::flatten( $raw );
		}
	}

	/**
	 * Discover the union of fields across every selected record; samples are text only.
	 *
	 * @param string   $file        Local JSON filename.
	 * @param string   $selected    Optional explicit record path.
	 * @param int|null $max_records Optional record cap, defaulting to XML::MAX_RECORDS.
	 * @throws \RuntimeException When the schema is invalid or exceeds limits.
	 */
	public static function analyze( string $file, string $selected = '', ?int $max_records = null ): array {
		$candidates = self::candidates( $file );
		$paths      = $candidates['paths'];
		if ( ! $paths ) {
			throw new \RuntimeException( 'No supported records found. Expected a JSON array of objects, such as a list of items.' );
		}
		$path   = $selected ? $selected : $paths[0]['path'];
		$choice = array_values( array_filter( $paths, static fn( $item ) => $item['path'] === $path ) );
		if ( ! $choice ) {
			throw new \RuntimeException( 'The selected record path does not exist in this feed.' );
		}
		$max_records = $max_records ?? XML::MAX_RECORDS;
		if ( $choice[0]['count'] > $max_records ) {
			throw new \RuntimeException( 'Please split this feed into files with no more than ' . $max_records . ' records.' );
		}
		$fields  = array();
		$samples = array();
		$total   = 0;
		foreach ( self::records( $file, $path, 0, $max_records + 1 ) as $record ) {
			++$total;
			if ( count( $samples ) < 5 ) {
				$samples[] = $record;
			}
			foreach ( $record as $key => $values ) {
				if ( ! isset( $fields[ $key ] ) ) {
					$fields[ $key ] = array(
						'path'     => $key,
						'sample'   => '',
						'present'  => 0,
						'repeated' => false,
						'values'   => 0,
					);
				}
				++$fields[ $key ]['present'];
				$fields[ $key ]['repeated'] = $fields[ $key ]['repeated'] || count( $values ) > 1;
				$fields[ $key ]['values']   = max( $fields[ $key ]['values'], count( $values ) );
				if ( '' === $fields[ $key ]['sample'] ) {
					$fields[ $key ]['sample'] = mb_substr( implode( "\n", $values ), 0, 240 );
				}
			}
			if ( count( $fields ) > XML::MAX_FIELDS ) {
				throw new \RuntimeException( 'The feed exceeds the limit of 1,500 distinct field paths.' );
			}
		}
		ksort( $fields );
		return array(
			'path'       => $path,
			'total'      => $total,
			'fields'     => array_values( $fields ),
			'samples'    => $samples,
			'candidates' => $paths,
			'feed_path'  => $candidates['feed_path'],
			'feed_name'  => $candidates['feed_name'],
		);
	}
}
