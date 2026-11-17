<?php
/**
 * Streaming XML discovery. No XPath or executable expressions are accepted.
 *
 * @package ImportEverything
 */

namespace ImportEverything;

/** XML parser independent of WordPress for fixture testing. */
final class XML {
	const MAX_BYTES        = 20971520;
	const MAX_RECORDS      = 20000;
	const MAX_FIELDS       = 1500;
	/** Most distinct record paths one document may offer, shared with the JSON parser. */
	const MAX_CANDIDATES   = 1500;
	const MAX_RECORD_BYTES = 1048576;
	const TAGS        = array( 'item', 'entry', 'record', 'photo', 'video', 'gallery', 'media', 'post', 'article', 'url', 'page' );
	const FEED_TAGS  = array( 'channel', 'feed' );

	/**
	 * Open a local file with entity expansion and network access disabled.
	 *
	 * @param string $file Local XML filename.
	 * @throws \RuntimeException When the file cannot be read safely.
	 */
	private static function reader( string $file ): \XMLReader {
		if ( ! is_file( $file ) || filesize( $file ) > self::MAX_BYTES || 0 === filesize( $file ) ) {
			throw new \RuntimeException( 'XML must be a non-empty file of at most 20 MiB.' );
		}
		$reader = new \XMLReader();
		if ( ! $reader->open( $file, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
			throw new \RuntimeException( 'Cannot open the XML file.' );
		}
		$reader->setParserProperty( \XMLReader::LOADDTD, false );
		$reader->setParserProperty( \XMLReader::SUBST_ENTITIES, false );
		return $reader;
	}

	/**
	 * Enumerate potential record paths without mistaking nested NATS items for entries.
	 *
	 * @param string $file Local XML filename.
	 * @throws \RuntimeException When XML is malformed or unsafe.
	 */
	public static function candidates( string $file ): array {
		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();
		$reader = self::reader( $file );
		$paths     = array();
		$stack     = array();
		$feed_path = '';
		$feed_name = '';
		$root      = '';
		$children  = array();
		try {
			while ( $reader->read() ) {
				if ( \XMLReader::DOC_TYPE === $reader->nodeType || \XMLReader::ENTITY_REF === $reader->nodeType ) {
					throw new \RuntimeException( 'DTD declarations and entities are not allowed. Export plain XML instead.' );
				}
				if ( \XMLReader::ELEMENT !== $reader->nodeType ) {
					continue;
				}
				if ( $reader->depth > 48 ) {
					throw new \RuntimeException( 'XML nesting exceeds the supported depth of 48.' );
				}
				$stack                   = array_slice( $stack, 0, $reader->depth );
				$stack[ $reader->depth ] = $reader->name;
				$local                   = strtolower( $reader->localName );
				// Remember the document's shape, so "no records" can say what the document actually holds.
				if ( 0 === $reader->depth && '' === $root ) {
					$root = $reader->localName;
				} elseif ( 1 === $reader->depth && count( $children ) < 12 ) {
					$children[ $reader->localName ] = true;
				}
				if ( in_array( $local, self::TAGS, true ) ) {
					$path = '/' . implode( '/', $stack );
					if ( ! isset( $paths[ $path ] ) ) {
						if ( count( $paths ) >= self::MAX_CANDIDATES ) {
							throw new \RuntimeException( 'This XML has more than ' . self::MAX_CANDIDATES . ' distinct record paths. Choose a smaller feed.' );
						}
						$paths[ $path ] = array(
							'path'  => $path,
							'tag'   => $reader->localName,
							'count' => 0,
							'depth' => $reader->depth,
						);
					}
					++$paths[ $path ]['count'];
				}
				if ( '' === $feed_path && in_array( $local, self::FEED_TAGS, true ) ) {
					$feed_path = '/' . implode( '/', $stack );
					$feed_name = $reader->localName;
				}
			}
			if ( libxml_get_errors() ) {
				$error = libxml_get_errors()[0];
				throw new \RuntimeException( 'Invalid XML near line ' . $error->line . ': ' . trim( $error->message ) );
			}
		} finally {
			$reader->close();
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		$paths = array_values( $paths );
		usort(
			$paths,
			static function ( $a, $b ) {
				$depth_order = $a['depth'] <=> $b['depth'];
				return 0 !== $depth_order ? $depth_order : ( $b['count'] <=> $a['count'] );
			}
		);
		return array(
			'paths'     => $paths,
			'feed_path' => $feed_path,
			'feed_name' => $feed_name,
			'root'      => $root,
			'children'  => array_keys( $children ),
		);
	}

	/**
	 * Flatten a record into stable paths and arrays of values.
	 *
	 * @param string $xml One serialized XML record.
	 * @throws \RuntimeException When record XML cannot be parsed safely.
	 */
	public static function flatten( string $xml ): array {
		// Reject DTD and entity declarations before libxml sees the record: loadXML() expands internal entities
		// while loading (a nested-entity record grows exponentially in the DOM), so the doctype check after the
		// load only fires after the expansion it exists to prevent. The scan runs over the record's markup only:
		// CDATA sections, comments and processing instructions are skipped, so a literal "<!DOCTYPE" inside one
		// of those stays data instead of reading as a declaration.
		if ( preg_match( '/<\!\s*(?:DOCTYPE|ENTITY)\b/i', self::markup( $xml ) ) ) {
			// Its own message, not the generic one below: this refusal comes from the scan, before libxml has been
			// given the record, and the gate asserts that message so removing the scan cannot pass as the load check.
			throw new \RuntimeException( 'This record declares a document type or entities, which are never read.' );
		}
		$document = new \DOMDocument();
		$loaded   = false;
		$doctype  = false;
		// A malformed record is an authored 400, so loadXML() must not print a libxml warning ahead of the JSON
		// response. errors() in candidates() already works this way; flatten() is the other DOM entry point.
		$previous = libxml_use_internal_errors( true );
		libxml_clear_errors();
		try {
			$loaded  = $document->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT );
			$doctype = $loaded && $document->doctype;
		} finally {
			libxml_clear_errors();
			libxml_use_internal_errors( $previous );
		}
		if ( ! $loaded || $doctype ) {
			throw new \RuntimeException( 'Unable to parse this record safely.' );
		}
		$fields = array();
		self::walk( $document->documentElement, '', $fields );
		return $fields;
	}

	/**
	 * The record with CDATA sections, comments and processing instructions removed.
	 *
	 * Only the markup that remains can carry a declaration: a "<" cannot appear unescaped in text or an
	 * attribute value, so a "<!DOCTYPE" or "<!ENTITY" outside those three constructs is real markup, while
	 * one inside them is data the parser never reads as a declaration. An unterminated construct swallows the
	 * rest of the record, which is also malformed and will fail the load below either way.
	 *
	 * @param string $xml One serialized XML record.
	 */
	private static function markup( string $xml ): string {
		$markup = '';
		$at     = 0;
		$length = strlen( $xml );
		while ( $at < $length ) {
			$lt = strpos( $xml, '<', $at );
			if ( false === $lt ) {
				$markup .= substr( $xml, $at );
				break;
			}
			$markup .= substr( $xml, $at, $lt - $at );
			if ( substr( $xml, $lt, 4 ) === '<!--' ) {
				$end = strpos( $xml, '-->', $lt + 4 );
			} elseif ( substr( $xml, $lt, 9 ) === '<![CDATA[' ) {
				$end = strpos( $xml, ']]>', $lt + 9 );
			} elseif ( substr( $xml, $lt, 2 ) === '<?' ) {
				$end = strpos( $xml, '?>', $lt + 2 );
			} else {
				$markup .= '<';
				$at      = $lt + 1;
				continue;
			}
			if ( false === $end ) {
				break;
			}
			$at = $end + ( substr( $xml, $lt, 2 ) === '<?' ? 2 : 3 );
		}
		return $markup;
	}

	/**
	 * Walk elements, keeping repeated values and distinguishing named NATS item groups.
	 *
	 * @param \DOMElement $node Current XML element.
	 * @param string      $path Current relative field path.
	 * @param array       $fields Collected values by path.
	 * @throws \RuntimeException When the schema has too many fields.
	 */
	private static function walk( \DOMElement $node, string $path, array &$fields ): void {
		foreach ( $node->attributes as $attribute ) {
			if ( 'http://www.w3.org/2000/xmlns/' === $attribute->namespaceURI ) {
				continue;
			}
			$key              = ( $path ? $path . '/' : '' ) . '@' . $attribute->nodeName;
			$fields[ $key ][] = $attribute->nodeValue;
		}
		$children = array();
		$text     = '';
		foreach ( $node->childNodes as $child ) {
			if ( $child instanceof \DOMElement ) {
				$children[] = $child;
			} elseif ( $child instanceof \DOMText || $child instanceof \DOMCdataSection ) {
				$text .= $child->nodeValue;
			}
		}
		if ( ! $children || '' !== trim( $text ) ) {
			$fields[ $path ? $path : '#text' ][] = trim( $text );
		}
		foreach ( $children as $child ) {
			$name = $child->nodeName;
			// NATS reuses <item> for unrelated dictionaries, e.g. ids and ident_details.
			if ( 'item' === strtolower( $child->localName ) && $child->hasAttribute( 'id' ) && $child->childElementCount ) {
				$name .= '[@id=' . json_encode( $child->getAttribute( 'id' ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ']';
			}
			self::walk( $child, $path ? $path . '/' . $name : $name, $fields );
		}
		if ( count( $fields ) > self::MAX_FIELDS ) {
			throw new \RuntimeException( 'The feed exceeds the limit of 1,500 distinct field paths.' );
		}
	}

	/**
	 * Stream one raw record's XML per selected record.
	 *
	 * This is the only parse loop: `records()` flattens what it yields and `spool()` copies it to disk, so
	 * every guard here (DTD/entity rejection, path matching, the record-size cap) protects both paths.
	 *
	 * @param string $file   Local XML filename.
	 * @param string $path   Selected absolute record path.
	 * @param int    $offset Records to skip.
	 * @param int    $limit  Maximum records to yield.
	 * @throws \RuntimeException When unsupported XML entities are encountered.
	 */
	private static function raw( string $file, string $path, int $offset = 0, int $limit = PHP_INT_MAX ): \Generator {
		$reader = self::reader( $file );
		$stack  = array();
		$index  = 0;
		$sent   = 0;
		try {
			while ( $reader->read() ) {
				if ( \XMLReader::DOC_TYPE === $reader->nodeType || \XMLReader::ENTITY_REF === $reader->nodeType ) {
					throw new \RuntimeException( 'DTD declarations and entities are not allowed.' );
				}
				if ( \XMLReader::ELEMENT !== $reader->nodeType ) {
					continue;
				}
				$stack                   = array_slice( $stack, 0, $reader->depth );
				$stack[ $reader->depth ] = $reader->name;
				if ( '/' . implode( '/', $stack ) !== $path ) {
					continue;
				}
				if ( $index++ < $offset ) {
					continue;
				}
				$xml = $reader->readOuterXml();
				if ( strlen( $xml ) > self::MAX_RECORD_BYTES ) {
					throw new \RuntimeException( 'A record in this feed is larger than the supported record-size limit.' );
				}
				yield $xml;
				if ( ++$sent >= $limit ) {
					break;
				}
			}
		} finally {
			$reader->close();
		}
	}

	/**
	 * Yield selected records, streaming the file rather than loading the whole document.
	 *
	 * @param string $file   Local XML filename.
	 * @param string $path   Selected absolute record path.
	 * @param int    $offset Records to skip.
	 * @param int    $limit  Maximum records to yield.
	 * @throws \RuntimeException When unsupported XML entities are encountered.
	 */
	public static function records( string $file, string $path, int $offset = 0, int $limit = PHP_INT_MAX ): \Generator {
		foreach ( self::raw( $file, $path, $offset, $limit ) as $xml ) {
			yield self::flatten( $xml );
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
	 * Copy every selected record into a spool, so a later request can read record k directly instead of
	 * re-parsing the feed from the top.
	 *
	 * A spool is the only place a record's byte position can be known: `XMLReader` reports no byte offsets, so
	 * the frames are written here and their positions are therefore exact by construction rather than guessed.
	 * Layout: a 12-byte header (magic, record count, index offset), then frames of a 4-byte big-endian length
	 * followed by the record's outer XML, then one 4-byte little-endian offset per record.
	 *
	 * @param string $file  Local XML filename.
	 * @param string $path  Selected absolute record path.
	 * @param string $spool Destination filename.
	 * @return int Records written.
	 * @throws \RuntimeException When the spool cannot be written.
	 */
	public static function spool( string $file, string $path, string $spool ): int {
		return Spool::write( $spool, self::raw( $file, $path ) );
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
		foreach ( Spool::read( $spool, $offset, $limit ) as $xml ) {
			yield self::flatten( $xml );
		}
	}

	/**
	 * Why a document that parsed has no records, in terms of what it actually holds.
	 *
	 * A dump can answer 200 with a valid but empty container — NATS does exactly that when the filters in the
	 * address match nothing — which is a different failure from a file whose records are simply not in the tag
	 * list, and the admin can only act on it if the message says which one happened.
	 *
	 * @param array $candidates Result of candidates().
	 */
	private static function empty_message( array $candidates ): string {
		$tag  = static fn( string $name ): string => substr( (string) preg_replace( '/[^A-Za-z0-9._:-]/', '', $name ), 0, 40 );
		$root = $tag( (string) ( $candidates['root'] ?? '' ) );
		if ( '' === $root ) {
			return 'No supported records found. Expected item, entry, record, photo, video, gallery, media, post, article, url, or page.';
		}
		$children = array_values( array_filter( array_map( $tag, array_slice( (array) ( $candidates['children'] ?? array() ), 0, 6 ) ) ) );
		return sprintf(
			'This feed has no records: its <%1$s> element holds %2$s. Check the export settings in the address (which sites, types and days it selects) and whether the feed URL is complete.',
			$root,
			$children ? '<' . implode( '>, <', $children ) . '>' : 'nothing'
		);
	}

	/**
	 * Discover the union of fields across every selected record; samples are text only.
	 *
	 * @param string $file Local XML filename.
	 * @param string $selected Optional explicit record path.
	 * @throws \RuntimeException When the schema is invalid or exceeds limits.
	 */
	public static function analyze( string $file, string $selected = '', ?int $max_records = null ): array {
		$candidates = self::candidates( $file );
		$paths      = $candidates['paths'];
		if ( ! $paths ) {
			throw new \RuntimeException( self::empty_message( $candidates ) );
		}
		$path   = $selected ? $selected : $paths[0]['path'];
		$choice = array_values( array_filter( $paths, static fn( $item ) => $item['path'] === $path ) );
		if ( ! $choice ) {
			throw new \RuntimeException( 'The selected record path does not exist in this feed.' );
		}
		$max_records = $max_records ?? self::MAX_RECORDS;
		if ( $choice[0]['count'] > $max_records ) {
			throw new \RuntimeException( 'Please split this feed into files with no more than ' . $max_records . ' records.' );
		}
		$fields  = array();
		$samples = array();
		$total   = 0;
		$limit   = $max_records;
		$records = self::records( $file, $path, 0, $limit + 1 );
		foreach ( $records as $record ) {
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
					$fields[ $key ]['sample'] = mb_substr( implode( '\n', $values ), 0, 240 );
				}
			}
			if ( count( $fields ) > self::MAX_FIELDS ) {
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
