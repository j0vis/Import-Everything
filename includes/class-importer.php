<?php
/**
 * Validated WordPress import operations.
 *
 * @package ImportEverything
 */

namespace ImportEverything;

defined( 'ABSPATH' ) || exit;

/** Imports one record at a time through WordPress APIs. */
final class Importer {
	/**
	 * Non-public IPv4 ranges that PHP's FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE still allow:
	 * carrier-grade NAT (RFC 6598), IETF protocol assignments, TEST-NET-1/2/3 (RFC 5737), and
	 * benchmarking space (RFC 2544). None of them are publicly routable.
	 */
	const BLOCKED_RANGES = array(
		array( '100.64.0.0', 10 ),
		array( '192.0.0.0', 24 ),
		array( '192.0.2.0', 24 ),
		array( '198.18.0.0', 15 ),
		array( '198.51.100.0', 24 ),
		array( '203.0.113.0', 24 ),
	);

	/**
	 * Non-public IPv6 ranges PHP's FILTER_FLAG_NO_PRIV_RANGE / NO_RES_RANGE still allow: the documentation prefix
	 * (`2001:db8::/32`, RFC 3849), benchmarking space (`2001:2::/48`, RFC 5180), the ORCHID assignment
	 * (`2001:10::/28`, RFC 4843), multicast (`ff00::/8`, RFC 4291) and the discard-only prefix (`100::/64`, RFC
	 * 6666). None of them accept a public unicast connection.
	 */
	const BLOCKED_V6_RANGES = array(
		array( '2001:db8::', 32 ),
		array( '2001:2::', 48 ),
		array( '2001:10::', 28 ),
		array( 'ff00::', 8 ),
		array( '100::', 64 ),
	);

	/**
	 * Separator a client uses to join two feed lines into one URL, e.g. `screen_url + clips/clip/screens/screen`.
	 *
	 * XML element names cannot contain a space, so `" + "` can never occur inside a field path and every part
	 * is still validated against the schema this server discovered.
	 */
	const JOIN = ' + ';

	/**
	 * The digest scheme every `_iex_identity` is written under, as `sha256:<digest>`.
	 *
	 * The prefix is what tells a later release which algorithm produced a stored identity: a scheme change is then
	 * a migration (recompute it, or look the old tag up) instead of every existing post failing to match and being
	 * imported again as a duplicate. 1.2.11 migrated the untagged digests earlier releases wrote.
	 */
	const IDENTITY_SCHEME = 'sha256';

	/** The post meta key an imported record's identity lives in. Durable: renaming it orphans every import. */
	const IDENTITY_META = '_iex_identity';

	/**
	 * The identity key releases before 1.3.0 wrote. Only a duplicate lookup consults it, and only while the chunked
	 * 1.3.0 rename is still moving rows (Admin::migrating()); once it finished no row carries this key, so the
	 * clause costs nothing but a join in a lookup that runs once per record.
	 */
	const LEGACY_IDENTITY_META = '_uxml_identity';

	/** Largest image this importer will download, in bytes. */
	const MAX_IMAGE_BYTES = 10485760;

	/** Most terms one mapped field may contribute to a single record. */
	const MAX_TERMS = 50;

	/** Longest fixed value or template a mapping value may carry. */
	const MAX_LITERAL = 500;

	/** Most custom fields one import may save, so a crafted settings payload cannot fan out meta writes. */
	const CUSTOM_MAX = 100;

	/**
	 * Most feed fields one gallery row may read. A feed that gives each screenshot its own element (`screen_1`,
	 * `screen_2`, …) needs a list of fields rather than one repeated field; 12 is the number WP-Script's own
	 * importer keeps per gallery, so the ceiling matches what their themes are built to show.
	 */
	const GALLERY_PATHS = 12;

	/**
	 * Prefix marking a mapping value as text written for every record instead of a feed field, e.g.
	 * `const:Uncategorized`. The prefix always wins, so a feed key spelled the same way is not addressed by it.
	 */
	const CONSTANT = 'const:';

	/**
	 * Prefix marking a mapping value built from feed fields, e.g. `tpl:{site_name} - {title}`. Every `{field}`
	 * placeholder is checked against the schema this server discovered, so a template reads no more than a
	 * dropdown can.
	 */
	const TEMPLATE = 'tpl:';

	/**
	 * The taxonomy each mapping target writes to. `actors` is WP-Script's own taxonomy for the video post type,
	 * which is why it is a target of its own: their themes read it beside categories and tags.
	 */
	const TAXONOMIES = array(
		'categories' => 'category',
		'tags'       => 'post_tag',
		'actors'     => 'actors',
	);

	/** Pixel ceiling for a downloaded image (40 megapixels). */
	const MAX_IMAGE_PIXELS = 40000000;

	/** Longest a single image download may take, in seconds, when the request has room for it. */
	const IMAGE_SECONDS = 20;

	/**
	 * The address curl reported it was about to connect to when the check did not approve it, so the failure can
	 * name it instead of reading as a dead network. Cleared as `blocked()` reads it.
	 */
	private static $peer = '';

	/** Whether the `http_api_curl` hook is registered, so it is added once per request rather than once per hop. */
	private static $hooked = false;

	/**
	 * Whether `pin_curl()` held the hop now in flight to the answers it was checked against, reset before each
	 * request.
	 *
	 * The pin only holds the connection on the curl transport: on any other transport the hook never fires and
	 * the pin is inert, so the transport resolves the name a second time unchecked. `pinnable()` refuses a server
	 * that cannot pin before the request; this flag covers the transport actually chosen, and `download()` refuses
	 * the response when it was not set instead of handing an unpinned download to the parser as if it were checked.
	 */
	private static $pin_applied = false;

	/** Shortest single image download worth starting, so a nearly spent request still tries. */
	const MIN_IMAGE_SECONDS = 3;

	/** Redirect hops a download may follow, each one revalidated before any request is made. */
	const MAX_REDIRECTS = 5;

	/**
	 * Validate mapping against the discovered schema, never client-supplied XPath.
	 *
	 * @param array $input Incoming settings to validate and sanitize.
	 * @param array $schema Server-discovered XML schema.
	 * @throws \RuntimeException When permissions or mappings are invalid.
	 */
	public static function settings( array $input, array $schema ): array {
		// Reject wrong types before they reach string functions. PHP raises a warning when these are handed
		// an array, and a warning printed ahead of the JSON response breaks the client's response parsing.
		foreach ( array( 'type', 'status', 'identity', 'duplicates' ) as $key ) {
			if ( isset( $input[ $key ] ) && ! is_string( $input[ $key ] ) ) {
				throw new \RuntimeException( esc_html__( 'Invalid import settings.', 'import-everything' ) );
			}
		}
		foreach ( array( 'mapping', 'custom' ) as $key ) {
			if ( isset( $input[ $key ] ) && ! is_array( $input[ $key ] ) ) {
				throw new \RuntimeException( esc_html__( 'Invalid import settings.', 'import-everything' ) );
			}
		}
		$type   = sanitize_key( $input['type'] ?? 'post' );
		$status = sanitize_key( $input['status'] ?? 'draft' );
		$object = get_post_type_object( $type );
		if ( ! $object || ( ! $object->show_ui && 'attachment' !== $type ) || ! current_user_can( $object->cap->create_posts ) ) {
			throw new \RuntimeException( esc_html__( 'You cannot import into this post type.', 'import-everything' ) );
		}
		if ( ! in_array( $status, array( 'publish', 'draft', 'pending' ), true ) ) {
			throw new \RuntimeException( esc_html__( 'Choose publish, draft, or pending.', 'import-everything' ) );
		}
		if ( 'publish' === $status && ! current_user_can( $object->cap->publish_posts ) ) {
			throw new \RuntimeException( esc_html__( 'You do not have permission to publish this post type.', 'import-everything' ) );
		}
		$known    = array_column( $schema['fields'], 'path' );
		$counts   = array();
		foreach ( $schema['fields'] as $field ) {
			$counts[ $field['path'] ] = (int) ( $field['values'] ?? 0 );
		}
		$custom_input = $input['custom'] ?? array();
		if ( count( $custom_input ) > self::CUSTOM_MAX ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: maximum number of custom fields. */
					esc_html__( 'An import can save at most %d custom fields.', 'import-everything' ),
					self::CUSTOM_MAX
				)
			);
		}
		$mapping  = array();
		$supplied = $input['mapping'] ?? array();
		foreach ( array( 'title', 'content', 'excerpt', 'image', 'unique', 'categories', 'tags', 'actors' ) as $target ) {
			// Only the featured image may be assembled from two feed lines, and only it may pick one value out of
			// a repeated line: a NATS feed keeps a base directory and the file name apart, and the screens line
			// carries several pictures, so the two must be joined or the media 404s.
			// A term target may also be filed under text the admin typed (`const:`/`tpl:`) when the feed's own
			// field is empty; the post fields stay feed-only, so a record's title is always its own.
			list( $parts )      = self::paths( $supplied[ $target ] ?? '', $known, 'image' === $target, $counts, 'image' === $target, isset( self::TAXONOMIES[ $target ] ) );
			$mapping[ $target ] = implode( self::JOIN, $parts );
		}
		if ( ! $mapping['title'] || ( 'attachment' === $type && ! $mapping['image'] ) ) {
			throw new \RuntimeException( esc_html__( 'Map a title. Attachments also require an image URL mapping.', 'import-everything' ) );
		}
		if ( $mapping['image'] && ! current_user_can( 'upload_files' ) ) {
			throw new \RuntimeException( esc_html__( 'You do not have permission to upload images.', 'import-everything' ) );
		}
		// Terms only exist on post types that register the taxonomy, so a mapping the post type cannot store is
		// refused up front instead of silently importing nothing.
		foreach ( self::TAXONOMIES as $target => $taxonomy ) {
			if ( '' === $mapping[ $target ] || is_object_in_taxonomy( $type, $taxonomy ) ) {
				continue;
			}
			// Name both sides: the mapping step comes before the post type is chosen, so this refusal is the
			// first place a mismatched pair becomes visible and it has to say what to change.
			$object = get_post_type_object( $type );
			$label  = $object && isset( $object->labels->singular_name ) ? (string) $object->labels->singular_name : $type;
			$terms  = array(
				'categories' => __( 'categories', 'import-everything' ),
				'tags'       => __( 'tags', 'import-everything' ),
				'actors'     => __( 'actors', 'import-everything' ),
			);
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: post type name, 2: taxonomy terms, e.g. actors. */
					esc_html__( '%1$s cannot store %2$s. Choose a post type that registers that taxonomy on the next step, or leave this field unmapped.', 'import-everything' ),
					$label,
					$terms[ $target ]
				)
			);
		}
		// A fixed value that holds no name would file every record under nothing, so refuse it here instead of
		// writing an empty term list for every record. A template is judged per record, when it is read.
		foreach ( array_keys( self::TAXONOMIES ) as $target ) {
			if ( 0 === strpos( $mapping[ $target ], self::CONSTANT ) && ! self::terms( array(), $mapping[ $target ] ) ) {
				throw new \RuntimeException( esc_html__( 'A fixed value for categories, tags, or actors must contain at least one name.', 'import-everything' ) );
			}
		}
		$custom = array();
		foreach ( $custom_input as $row ) {
			if ( ! is_array( $row ) ) {
				throw new \RuntimeException( esc_html__( 'Invalid custom field list.', 'import-everything' ) );
			}
			$name = $row['name'] ?? '';
			if ( ! is_string( $name ) || ! preg_match( '/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,190}$/', $name ) || isset( $custom[ $name ] ) ) {
				throw new \RuntimeException( esc_html__( 'Custom field names must be unique, start with a letter or number, and contain only letters, numbers, dots, dashes, or underscores.', 'import-everything' ) );
			}
			list( $parts, $picked ) = self::paths( $row['path'] ?? '', $known, true, $counts, true, true );
			$mode                   = $row['mode'] ?? '';
			$format                 = $row['format'] ?? 'raw';
			if ( ! $parts || ! is_string( $mode ) || ! in_array( $mode, array( 'first', 'join', 'array', 'rows' ), true ) ) {
				throw new \RuntimeException( esc_html__( 'Choose a valid field and repeated-value mode for each custom field.', 'import-everything' ) );
			}
			if ( ! is_string( $format ) || ! in_array( $format, array( 'raw', 'seconds' ), true ) ) {
				throw new \RuntimeException( esc_html__( 'Choose the raw value or seconds for each custom field format.', 'import-everything' ) );
			}
			if ( 'seconds' === $format && ( 'first' !== $mode || count( $parts ) > 1 || $picked ) ) {
				throw new \RuntimeException( esc_html__( 'A converted duration reads one value, so this custom field must use First value with a single field.', 'import-everything' ) );
			}
			if ( count( $parts ) > 1 && 'first' !== $mode ) {
				throw new \RuntimeException( esc_html__( 'A joined value is a single URL, so this custom field must use First value.', 'import-everything' ) );
			}
			if ( $picked && 'first' !== $mode ) {
				throw new \RuntimeException( esc_html__( 'A chosen value position is one value, so this custom field must use First value.', 'import-everything' ) );
			}
			// Text the admin typed is one value, so the modes that repeat a feed's values have nothing to repeat.
			if ( 1 === count( $parts ) && self::is_literal( $parts[0] ) && 'first' !== $mode ) {
				throw new \RuntimeException( esc_html__( 'A fixed value or template is one value, so this custom field must use First value.', 'import-everything' ) );
			}
			// A gallery may also be split across separate feed fields (`screen_1`, `screen_2`, `screen_3`), which no
			// single path can describe. Each named field contributes its values in order, so one meta key still ends up
			// with one row per screenshot; only the modes that keep every value can carry such a list.
			$gallery = array();
			if ( isset( $row['paths'] ) ) {
				if ( ! is_array( $row['paths'] ) || count( $row['paths'] ) < 2 || count( $row['paths'] ) > self::GALLERY_PATHS ) {
					throw new \RuntimeException( esc_html__( 'A gallery reads between two and twelve feed fields.', 'import-everything' ) );
				}
				if ( ! in_array( $mode, array( 'array', 'rows' ), true ) ) {
					throw new \RuntimeException( esc_html__( 'A gallery reads a list of fields, so this custom field must keep every value.', 'import-everything' ) );
				}
				foreach ( $row['paths'] as $one ) {
					list( $only ) = self::paths( $one, $known, false, $counts, false, false );
					if ( 1 !== count( $only ) ) {
						throw new \RuntimeException( esc_html__( 'A gallery reads one feed field per entry.', 'import-everything' ) );
					}
					$gallery[] = $only[0];
				}
				$gallery = array_values( array_unique( $gallery ) );
				if ( count( $gallery ) < 2 ) {
					throw new \RuntimeException( esc_html__( 'A gallery reads two different feed fields.', 'import-everything' ) );
				}
				// The row's own field is the first of the list, so the stored shape says the same thing twice: the field
				// the mapping step shows, and the fields the gallery actually reads.
				if ( $parts[0] !== $gallery[0] ) {
					throw new \RuntimeException( esc_html__( 'A gallery starts at this custom field’s own field.', 'import-everything' ) );
				}
			}
			$custom[ $name ] = array(
				'path'   => implode( self::JOIN, $parts ),
				'mode'   => $mode,
				'format' => $format,
			);
			if ( $gallery ) {
				$custom[ $name ]['paths'] = $gallery;
			}
		}
		$identity = sanitize_text_field( $input['identity'] ?? '' );
		if ( '' === $identity || strlen( $identity ) > 200 ) {
			throw new \RuntimeException( esc_html__( 'Enter an import identity of 1–200 characters.', 'import-everything' ) );
		}
		return array(
			'type'       => $type,
			'status'     => $status,
			'identity'   => $identity,
			'duplicates' => 'update' === ( $input['duplicates'] ?? '' ) ? 'update' : 'skip',
			'mapping'    => $mapping,
			'custom'     => $custom,
		);
	}

	/**
	 * The term names a mapped field contributes to one record.
	 *
	 * Every value of the field is read, because RSS repeats `<category>` and JSON repeats an array; each value is
	 * also split on commas, which is how a CSV cell (and a Google Sheets column) lists several terms. Names are
	 * de-duplicated, trimmed, and capped so one record cannot flood a site with terms.
	 *
	 * @param array  $record Flat record values.
	 * @param string $source Validated mapping value: a feed field, a `const:` value, or a `tpl:` template, or an
	 *                       empty string when the target is not mapped.
	 * @return string[]
	 */
	private static function terms( array $record, string $source ): array {
		if ( '' === $source ) {
			return array();
		}
		// Typed text contributes one value, so a fixed comma list files a record under several terms; a template
		// resolves per record and can be empty for one that fills none of its placeholders.
		$values = self::is_literal( $source ) ? array( self::expand( $record, $source ) ) : ( $record[ $source ] ?? array() );
		$names  = array();
		foreach ( $values as $value ) {
			foreach ( explode( ',', (string) $value ) as $name ) {
				$name = trim( sanitize_text_field( $name ) );
				if ( '' !== $name && ! in_array( $name, $names, true ) ) {
					$names[] = $name;
				}
				if ( count( $names ) >= self::MAX_TERMS ) {
					break 2;
				}
			}
		}
		return $names;
	}

	/**
	 * Convert a duration to whole seconds, the unit WP-Script stores in its `duration` meta.
	 *
	 * Accepts a plain count (`300`), a clock (`05:00`, `1:02:03`) and ISO 8601 (`PT5M`, `P0DT0H5M0S`, the form
	 * YouTube's data API uses). Anything else becomes an empty value rather than a wrong number, so a feed with
	 * an unexpected spelling writes no duration instead of a duration of zero.
	 *
	 * @param string $value Raw duration text.
	 */
	private static function seconds( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( ctype_digit( $value ) ) {
			return (string) (int) $value;
		}
		if ( preg_match( '/^(\d+):([0-5]?\d):([0-5]?\d)$/', $value, $match ) ) {
			return (string) ( (int) $match[1] * 3600 + (int) $match[2] * 60 + (int) $match[3] );
		}
		if ( preg_match( '/^(\d+):([0-5]?\d)$/', $value, $match ) ) {
			return (string) ( (int) $match[1] * 60 + (int) $match[2] );
		}
		if ( preg_match( '/^P(?:(\d+)D)?T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?$/i', $value, $match ) ) {
			return (string) ( (int) ( $match[1] ?? 0 ) * 86400 + (int) ( $match[2] ?? 0 ) * 3600 + (int) ( $match[3] ?? 0 ) * 60 + (int) ( $match[4] ?? 0 ) );
		}
		return '';
	}

	/**
	 * Normalize a mapping value into the one or two field paths it reads, all of them from this feed.
	 *
	 * A client sends a path string; two paths joined with `JOIN` ask for a URL assembled from both lines. Every
	 * part is checked against the schema the server discovered itself, so this stays a lookup and never a
	 * client-supplied selector.
	 *
	 * A part that spells out an exact field wins over the `#n` syntax, so a feed's own key ending in `#2` (JSON
	 * allows one) stays that key instead of becoming a position in a different field.
	 *
	 * @param mixed    $value      Client-supplied mapping value.
	 * @param string[] $known      Field paths found in this feed.
	 * @param bool     $combine    Whether two fields may be joined into one value.
	 * @param int[]    $counts     How many values each field held, from the schema (0 when it did not say).
	 * @param bool     $positional Whether this target may pick one value out of a repeated field.
	 * @param bool     $literal    Whether this target may also carry text the admin typed instead of a field.
	 * @return array{0: string[], 1: bool} The paths in order, and whether any of them uses a position.
	 * @throws \RuntimeException When a value is not a path in this feed, joins too many, or asks for a position
	 *                           a target may not use or the feed does not hold.
	 */
	private static function paths( $value, array $known, bool $combine = false, array $counts = array(), bool $positional = false, bool $literal = false ): array {
		if ( ! is_string( $value ) ) {
			throw new \RuntimeException( esc_html__( 'A mapped field is not present in this feed.', 'import-everything' ) );
		}
		if ( '' === $value ) {
			return array( array(), false );
		}
		if ( $literal && self::literal( $value, $known ) ) {
			// Text stays one part: it is not split on JOIN, so a template may itself contain " + ".
			return array( array( $value ), false );
		}
		$parts = array_map( 'trim', explode( self::JOIN, $value ) );
		if ( count( $parts ) > 1 && ! $combine ) {
			throw new \RuntimeException( esc_html__( 'Only the featured image and custom fields can join two feed fields into one value.', 'import-everything' ) );
		}
		if ( count( $parts ) > 2 || in_array( '', $parts, true ) ) {
			throw new \RuntimeException( esc_html__( 'A joined value combines exactly two feed fields.', 'import-everything' ) );
		}
		$positioned = false;
		foreach ( $parts as $part ) {
			if ( in_array( $part, $known, true ) ) {
				continue;
			}
			list( $base, $at ) = self::position( $part );
			if ( $at > 0 ) {
				if ( ! $positional ) {
					throw new \RuntimeException( esc_html__( 'Only the featured image and a First value custom field can pick one value out of a repeated field.', 'import-everything' ) );
				}
				// The bound only applies when the schema says how many values the line held: an older schema cannot
				// answer, and a position past the end would leave the media URL empty for every record.
				$available = (int) ( $counts[ $base ] ?? 0 );
				if ( $available > 0 && $at > $available ) {
					throw new \RuntimeException(
						sprintf(
							/* translators: 1: feed field path, 2: number of values the field holds. */
							esc_html__( 'This feed holds only %2$d values in %1$s, so it cannot be read at that position.', 'import-everything' ),
							esc_html( $base ),
							$available
						)
					);
				}
				$positioned = true;
			}
			if ( ! in_array( $base, $known, true ) ) {
				throw new \RuntimeException( esc_html__( 'A mapped field is not present in this feed.', 'import-everything' ) );
			}
		}
		return array( $parts, $positioned );
	}

	/**
	 * Split a `field#n` path into its field and the 1-based position it asks for.
	 *
	 * Parsing only: whether a target may use a position is the caller's rule, and `#0` is not a position, so it
	 * stays part of the path and is refused as an unknown field unless the feed really has that key.
	 *
	 * @param string $part One trimmed part of a mapping value.
	 * @return array{0: string, 1: int} The field path and its position, or 0 when the part carries no position.
	 */
	private static function position( string $part ): array {
		if ( preg_match( '/^(.*)#(\d+)$/', $part, $match ) && '' !== $match[1] && (int) $match[2] > 0 ) {
			return array( $match[1], (int) $match[2] );
		}
		return array( $part, 0 );
	}

	/**
	 * Whether a validated mapping value is text the admin typed rather than a feed field.
	 *
	 * @param string $value One validated mapping value.
	 */
	private static function is_literal( string $value ): bool {
		return 0 === strpos( $value, self::CONSTANT ) || 0 === strpos( $value, self::TEMPLATE );
	}

	/**
	 * Validate a mapping value that is text, telling it apart from a feed field by its prefix.
	 *
	 * `const:` is text written for every record; `tpl:` is text with `{field}` placeholders, and every
	 * placeholder has to name a field of this feed, so a template reads exactly what a dropdown could and no
	 * more. A value carrying neither prefix is a field path, which the caller looks up itself.
	 *
	 * @param string   $value Client-supplied mapping value.
	 * @param string[] $known Field paths found in this feed.
	 * @return bool Whether this value is text (and has been validated).
	 * @throws \RuntimeException When the text is empty, too long, or reads a field this feed does not have.
	 */
	private static function literal( string $value, array $known ): bool {
		if ( 0 === strpos( $value, self::CONSTANT ) ) {
			$text = trim( substr( $value, strlen( self::CONSTANT ) ) );
			if ( '' === $text ) {
				throw new \RuntimeException( esc_html__( 'A fixed value needs text, for example Uncategorized.', 'import-everything' ) );
			}
			if ( strlen( $text ) > self::MAX_LITERAL ) {
				throw new \RuntimeException( esc_html__( 'A fixed value is too long. Keep it under 500 characters.', 'import-everything' ) );
			}
			return true;
		}
		if ( 0 !== strpos( $value, self::TEMPLATE ) ) {
			return false;
		}
		$text = trim( substr( $value, strlen( self::TEMPLATE ) ) );
		if ( '' === $text || ! preg_match_all( '/\{([^{}]+)\}/', $text, $matches ) ) {
			throw new \RuntimeException( esc_html__( 'A template needs at least one {field} placeholder. To write the same text for every record, use a fixed value.', 'import-everything' ) );
		}
		if ( strlen( $text ) > self::MAX_LITERAL ) {
			throw new \RuntimeException( esc_html__( 'A template is too long. Keep it under 500 characters.', 'import-everything' ) );
		}
		$rest = (string) preg_replace( '/\{[^{}]+\}/', '', $text );
		if ( false !== strpos( $rest, '{' ) || false !== strpos( $rest, '}' ) ) {
			throw new \RuntimeException( esc_html__( 'A template placeholder is a {field} name, with no other braces around it.', 'import-everything' ) );
		}
		foreach ( $matches[1] as $name ) {
			if ( ! in_array( $name, $known, true ) ) {
				throw new \RuntimeException(
					sprintf(
						/* translators: %s: field name the template asks for. */
						esc_html__( 'This feed has no field %s, so the template cannot read it.', 'import-everything' ),
						esc_html( $name )
					)
				);
			}
		}
		return true;
	}

	/**
	 * Read one text mapping value for this record: the fixed text as typed, or the template with its placeholders
	 * filled from this record's own fields.
	 *
	 * @param array  $record Flat record values.
	 * @param string $value  Validated text value (`const:`/`tpl:`).
	 */
	private static function expand( array $record, string $value ): string {
		if ( 0 === strpos( $value, self::CONSTANT ) ) {
			return trim( substr( $value, strlen( self::CONSTANT ) ) );
		}
		$text     = trim( substr( $value, strlen( self::TEMPLATE ) ) );
		$filled   = false;
		$expanded = (string) preg_replace_callback(
			'/\{([^{}]+)\}/',
			static function ( array $match ) use ( $record, &$filled ): string {
				$part = (string) ( $record[ $match[1] ][0] ?? '' );
				if ( '' !== $part ) {
					$filled = true;
				}
				return $part;
			},
			$text
		);
		// A record that fills none of the placeholders writes nothing, rather than the punctuation left around
		// them, which is what a theme would otherwise read as a value.
		return $filled ? $expanded : '';
	}

	/**
	 * Read one mapped value out of a flat record, concatenating the halves of a joined URL.
	 *
	 * @param array  $record Flat XML record values.
	 * @param string $path   Validated mapping: one field path, or two joined with `JOIN`.
	 */
	private static function joined( array $record, string $path ): string {
		$value = '';
		foreach ( array_map( 'trim', explode( self::JOIN, $path ) ) as $part ) {
			if ( array_key_exists( $part, $record ) ) {
				$value .= $record[ $part ][0] ?? '';
				continue;
			}
			list( $base, $at ) = self::position( $part );
			$value .= $record[ $base ][ $at > 0 ? $at - 1 : 0 ] ?? '';
		}
		return $value;
	}

	/**
	 * Split an image-list cell that lists its images in one value.
	 *
	 * A feed, or a spreadsheet cell, can put the whole gallery in one field value with the addresses separated
	 * by commas, semicolons, pipes, line breaks or spaces. `rows` is the row-per-value mode, so those separate images
	 * there — but only when every part is an absolute URL, because a single address that carries one in its query
	 * (`?crop=10,20`, `?a=1;b=2`, `?q=a|b`) is one image and must stay one meta row. The values arrive unescaped,
	 * so the `;` of an escaped `&amp;` entity cannot be read as a separator, and the parts are already trimmed
	 * with empty ones gone.
	 *
	 * @param string[] $values Trimmed, non-empty values of an image row.
	 * @return string[]
	 */
	private static function gallery( array $values ): array {
		$out = array();
		foreach ( $values as $value ) {
			$parts = array_values( array_filter( array_map( 'trim', preg_split( '/[,;|\s]+/', $value ) ), static fn( $one ) => '' !== $one ) );
			$split = count( $parts ) > 1;
			foreach ( $split ? $parts : array() as $part ) {
				if ( ! preg_match( '#^(?:[a-z][a-z0-9+.-]*:)?//#i', $part ) ) {
					$split = false;
					break;
				}
			}
			if ( $split ) {
				array_push( $out, ...$parts );
				continue;
			}
			$out[] = $value;
		}
		return $out;
	}

	/**
	 * Resolve mappings, sanitizing values before WordPress storage.
	 *
	 * @param array $record Flat record values.
	 * @param array $settings Validated import settings.
	 */
	public static function preview( array $record, array $settings ): array {
		$map    = $settings['mapping'];
		$custom = array();
		foreach ( $settings['custom'] as $name => $row ) {
			if ( 'first' === $row['mode'] ) {
				// The one mode a joined URL or typed text can use, because both are a single value.
				$value           = self::is_literal( $row['path'] ) ? self::expand( $record, $row['path'] ) : self::joined( $record, $row['path'] );
				$value           = wp_kses_post( $value );
				$custom[ $name ] = 'seconds' === ( $row['format'] ?? 'raw' ) ? self::seconds( $value ) : $value;
				continue;
			}
			// A gallery row may name several fields, each holding one screenshot; every value of every field is kept
			// in the order the row lists them, so the gallery follows the feed's own numbering. The raw values are
			// collected first: `gallery()` splits a cell into images before the escaping, because `wp_kses_post()`
			// turns an address's `&` into `&amp;` and that entity's semicolon would read as a gallery separator.
			$values = array();
			foreach ( $row['paths'] ?? array( $row['path'] ) as $one ) {
				foreach ( (array) ( $record[ $one ] ?? array( '' ) ) as $value ) {
					$values[] = (string) $value;
				}
			}
			// `array` is one serialized value; `rows` is one meta row per value, which is what WP-Script's own
			// `thumbs` hover slideshow expects (their cards read it with get_post_meta( ..., 'thumbs', false ) and
			// implode, playing it only when the video has no `trailer_url`). An image list is still a list of URLs: a
			// blank cell becomes an empty meta row their cards render as a broken frame, and surrounding whitespace
			// stops a kept URL from resolving, so drop the one and trim the other. One cell that lists several
			// addresses — with commas, semicolons, pipes, line breaks or spaces — is a list too, and `gallery()`
			// gives each its row.
			if ( 'rows' === $row['mode'] ) {
				$values = array_values( array_filter( array_map( 'trim', $values ), static fn( $one ) => '' !== $one ) );
				$values = self::gallery( $values );
			}
			$values          = array_map( 'wp_kses_post', $values );
			$custom[ $name ] = in_array( $row['mode'], array( 'array', 'rows' ), true ) ? $values : implode( "\n", $values );
		}
		return array(
			'title'      => sanitize_text_field( $record[ $map['title'] ][0] ?? '' ),
			'content'    => wp_kses_post( implode( "\n", $record[ $map['content'] ] ?? array() ) ),
			'excerpt'    => wp_kses_post( implode( "\n", $record[ $map['excerpt'] ] ?? array() ) ),
			'image'      => self::joined( $record, $map['image'] ),
			'categories' => self::terms( $record, $map['categories'] ?? '' ),
			'tags'       => self::terms( $record, $map['tags'] ?? '' ),
			'actors'     => self::terms( $record, $map['actors'] ?? '' ),
			'custom'     => $custom,
		);
	}

	/**
	 * Resolve a host once, keeping the answers so the connection itself can be held to them.
	 *
	 * WordPress' HTTP API resolves the name again when it connects, so a name that answers public to this check
	 * and private to the transport passed the check and then reached the network it had just refused. `download()`
	 * keeps these answers and `pin_curl()` makes curl use them, which is also the one place the address actually
	 * connected to is knowable and can be checked.
	 *
	 * @param string $url URL whose host is resolved.
	 * @return array{host:string,port:int,addresses:string[]}|null Host, port and every public answer, or null.
	 */
	public static function pin( string $url ): ?array {
		if ( ! wp_http_validate_url( $url ) ) {
			return null;
		}
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		if ( '' === $host ) {
			return null;
		}
		// parse_url() keeps the brackets of an IPv6 literal, and every check below works on the bare address.
		$name      = trim( $host, '[]' );
		$scheme    = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$port      = (int) ( wp_parse_url( $url, PHP_URL_PORT ) ?: ( 'https' === $scheme ? 443 : 80 ) );
		$addresses = filter_var( $name, FILTER_VALIDATE_IP ) ? array( $name ) : gethostbynamel( $name );
		$addresses = array_values( array_unique( array_filter( (array) $addresses, 'is_string' ) ) );
		if ( ! $addresses ) {
			return null;
		}
		foreach ( $addresses as $address ) {
			if ( ! filter_var( $address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return null;
			}
			if ( ! self::routable( $address ) ) {
				return null;
			}
		}
		return array(
			'host'      => $name,
			'port'      => $port,
			'addresses' => $addresses,
		);
	}

	/**
	 * Require public DNS/IPs, including when WordPress treats the local site host as safe.
	 *
	 * @param string $url URL to check.
	 */
	public static function public_url( string $url ): bool {
		return null !== self::pin( $url );
	}

	/**
	 * Whether the address curl is about to connect to is one this request resolved and checked.
	 *
	 * @param string $address Peer address as curl reports it (an IPv6 address without brackets).
	 * @param array  $pin     Answers `pin()` returned for the request.
	 */
	public static function approved( string $address, array $pin ): bool {
		$peer = strtolower( trim( $address, '[]' ) );
		foreach ( (array) ( $pin['addresses'] ?? array() ) as $candidate ) {
			if ( $peer === strtolower( trim( (string) $candidate, '[]' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Make curl connect to the addresses the check approved, and stop it if it reaches another.
	 *
	 * `CURLOPT_RESOLVE` removes the second lookup the transport would make; the pre-request callback then sees
	 * the peer address libcurl actually connected to — the only place that address is knowable — and aborts
	 * before the request is sent when it is not one of the checked answers. Both options need the curl transport,
	 * which is why `download()` passes the answers along instead of assuming it is in use.
	 *
	 * @param resource|\CurlHandle $handle Curl handle WordPress is about to send.
	 * @param array                $args   Request arguments, carrying the pin `download()` took.
	 * @param string               $url    Requested URL, unused here.
	 */
	public static function pin_curl( $handle, array $args = array(), string $url = '' ): void {
		$pin = $args['iex_pin'] ?? null;
		if ( ! is_array( $pin ) || empty( $pin['host'] ) || empty( $pin['addresses'] ) ) {
			return;
		}
		$name = (string) $pin['host'];
		$port = (int) ( $pin['port'] ?? 0 );
		// An address literal is connected to as written, so there is no lookup to hold; a name is only held once
		// the resolve is in place, which is why the flag follows the option rather than the hook firing.
		$held = true;
		if ( ! filter_var( $name, FILTER_VALIDATE_IP ) && defined( 'CURLOPT_RESOLVE' ) ) {
			$entries = array();
			foreach ( (array) $pin['addresses'] as $address ) {
				$entries[] = $name . ':' . $port . ':' . ( false === strpos( (string) $address, ':' ) ? $address : '[' . $address . ']' );
			}
			$held = (bool) @curl_setopt( $handle, CURLOPT_RESOLVE, $entries );
		}
		self::$pin_applied = $held;
		if ( ! defined( 'CURLOPT_PREREQFUNCTION' ) || ! defined( 'CURL_PREREQFUNC_ABORT' ) ) {
			return;
		}
		// The pin is captured rather than read from the request: this runs inside curl, after the request that
		// carried it has gone out of scope.
		@curl_setopt(
			$handle,
			CURLOPT_PREREQFUNCTION,
			static function ( $handle, string $primary, string $local, int $primary_port, int $local_port ) use ( $pin ): int {
				if ( '' !== $primary && ! self::approved( $primary, $pin ) ) {
					self::$peer = $primary;
					return \CURL_PREREQFUNC_ABORT;
				}
				return \CURL_PREREQFUNC_OK;
			}
		);
	}

	/**
	 * The address a refused connection asked for, cleared as it is read.
	 */
	private static function blocked(): string {
		$peer       = self::$peer;
		self::$peer = '';
		return $peer;
	}

	/**
	 * Whether this server can hold a checked address to its HTTP connection at all: the pin needs curl and
	 * `CURLOPT_RESOLVE`, the option that removes the transport's own lookup.
	 */
	private static function pinnable(): bool {
		return function_exists( 'curl_init' ) && defined( 'CURLOPT_RESOLVE' );
	}

	/**
	 * Register the curl pin once per request; the callback is inert unless the arguments carry a pin.
	 */
	private static function hook(): void {
		if ( self::$hooked ) {
			return;
		}
		self::$hooked = true;
		add_action( 'http_api_curl', array( __CLASS__, 'pin_curl' ), 10, 3 );
	}

	/**
	 * Reject IPv4 addresses outside the public internet that the range flags above let through.
	 *
	 * @param string $address One resolved address.
	 */
	private static function routable( string $address ): bool {
		$packed = @inet_pton( $address );
		if ( false === $packed ) {
			// Not an address at all: `pin()` only passes values filter_var() accepted, so this is fail-closed.
			return false;
		}
		if ( 4 === strlen( $packed ) ) {
			$ip = ip2long( $address );
			if ( false === $ip ) {
				return false;
			}
			foreach ( self::BLOCKED_RANGES as $range ) {
				$mask = -1 << ( 32 - $range[1] );
				if ( ( $ip & $mask ) === ( ip2long( $range[0] ) & $mask ) ) {
					return false;
				}
			}
			return true;
		}
		foreach ( self::BLOCKED_V6_RANGES as $range ) {
			if ( self::within( $packed, $range[0], $range[1] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether a packed address lies inside a network: the leading `$bits` bits have to match.
	 *
	 * @param string $packed  Address as inet_pton() returned it.
	 * @param string $network Network address, e.g. `2001:db8::`.
	 * @param int    $bits    Prefix length.
	 */
	private static function within( string $packed, string $network, int $bits ): bool {
		$base = @inet_pton( $network );
		if ( false === $base || strlen( $base ) !== strlen( $packed ) ) {
			return false;
		}
		$whole = intdiv( $bits, 8 );
		if ( $whole > 0 && substr( $packed, 0, $whole ) !== substr( $base, 0, $whole ) ) {
			return false;
		}
		$rest = $bits % 8;
		if ( 0 === $rest ) {
			return true;
		}
		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;
		return ( ord( $packed[ $whole ] ) & $mask ) === ( ord( $base[ $whole ] ) & $mask );
	}

	/**
	 * Follow redirects manually, rechecking every target before any HTTP request.
	 *
	 * `$seconds` is the budget for the whole download, not for each hop: every hop gets whatever is left of
	 * it, so a chain of slow redirects cannot multiply one record's network time by the redirect limit.
	 *
	 * @param string $url     Public download URL.
	 * @param string $file    Private temporary destination.
	 * @param int    $bytes   Maximum download size.
	 * @param float $seconds Seconds the whole download may take.
	 * @throws \RuntimeException When a target is unsafe or download fails.
	 */
	public static function download( string $url, string $file, int $bytes, float $seconds ): void {
		$deadline = microtime( true ) + $seconds;
		for ( $redirect = 0; $redirect <= self::MAX_REDIRECTS; ++$redirect ) {
			$timeout = (int) ceil( $deadline - microtime( true ) );
			if ( $timeout < 1 ) {
				throw new \RuntimeException( esc_html__( 'The download did not finish inside this request. Resume the import to try again.', 'import-everything' ) );
			}
			$pin = self::pin( $url );
			if ( ! $pin ) {
				throw new \RuntimeException( esc_html__( 'URL must be a public HTTP(S) address. Private addresses are blocked.', 'import-everything' ) );
			}
			if ( ! self::pinnable() ) {
				// Refuse before the request rather than after it: a hop this server cannot pin must not reach the
				// address at all, or the request itself is the SSRF and only its answer is discarded.
				throw new \RuntimeException( esc_html__( 'This server cannot hold a download to the address it checked: the PHP curl extension is missing or too old. Nothing was requested.', 'import-everything' ) );
			}
			self::hook();
			self::blocked();
			self::$pin_applied = false;
			$response = wp_safe_remote_get(
				$url,
				array(
					'timeout'             => $timeout,
					'redirection'         => 0,
					'stream'              => true,
					'filename'            => $file,
					'limit_response_size' => $bytes + 1,
					// The answers this hop was checked against: `pin_curl()` holds the transport to them.
					'iex_pin'             => $pin,
				)
			);
			if ( is_wp_error( $response ) ) {
				$peer = self::blocked();
				if ( '' !== $peer ) {
					// The connection reached an address this request never checked, which is what the pin exists to
					// stop: a name that answers public to the check and private to the transport.
					throw new \RuntimeException(
						sprintf(
							/* translators: 1: host name of the requested URL, 2: IP address the connection used. */
							esc_html__( 'The download was stopped because %1$s connected to %2$s, an address this request never checked. Nothing was read from it.', 'import-everything' ),
							esc_html( (string) wp_parse_url( $url, PHP_URL_HOST ) ),
							esc_html( $peer )
						)
					);
				}
				throw new \RuntimeException( esc_html__( 'Download failed. Check that the public URL is reachable.', 'import-everything' ) );
			}
			if ( ! self::$pin_applied ) {
				// The hop went out through a transport that did not apply the pin, so the answers could not be held to
				// the connection. The body may have been written, but it is never read: refuse it outright rather
				// than import bytes a check never held. A WP_Error above is answered first, because a request that
				// never left read nothing and its own message is the accurate one.
				throw new \RuntimeException( esc_html__( 'The download was stopped because this server\'s HTTP transport did not use curl, so the checked address could not be held to the connection. Nothing was read from it.', 'import-everything' ) );
			}
			$status = wp_remote_retrieve_response_code( $response );
			if ( 200 === $status ) {
				clearstatcache( true, $file );
				$size = (int) filesize( $file );
				if ( 0 === $size ) {
					// NATS adtools answer a valid request with an empty 200 when the dump matches nothing or the
					// requesting server is not allowed for the key. Say that, instead of failing later as "not XML".
					throw new \RuntimeException(
						sprintf(
							/* translators: %s: host name of the requested URL. */
							esc_html__( 'The server at %s answered HTTP 200 with an empty response, so there is no feed to read. NATS adtools do this when the dump matches no records, or when this server is not allowed to use that adtool key; open the same URL in a browser to confirm it returns XML.', 'import-everything' ),
							esc_html( (string) wp_parse_url( $url, PHP_URL_HOST ) )
						)
					);
				}
				if ( $size > $bytes ) {
					throw new \RuntimeException( esc_html__( 'Download exceeds the allowed file size.', 'import-everything' ) );
				}
				return;
			}
			$location = wp_remote_retrieve_header( $response, 'location' );
			if ( ! in_array( $status, array( 301, 302, 303, 307, 308 ), true ) || ! $location ) {
				throw new \RuntimeException( esc_html__( 'The download URL did not return HTTP 200.', 'import-everything' ) );
			}
			$url = \WP_Http::make_absolute_url( $location, $url );
		}
		throw new \RuntimeException( esc_html__( 'Too many download redirects.', 'import-everything' ) );
	}

	/**
	 * Seconds one image fetch may take: the configured ceiling, never more than the request has left.
	 *
	 * A record whose image is slow must not be able to run the request past its own time limit, so the
	 * caller passes the batch's remaining budget down to the download.
	 *
	 * @param float $seconds Seconds left in this request, or zero when there is no deadline.
	 */
	public static function image_seconds( float $seconds = 0.0 ): float {
		if ( $seconds <= 0.0 ) {
			return (float) self::IMAGE_SECONDS;
		}
		return max( (float) self::MIN_IMAGE_SECONDS, min( (float) self::IMAGE_SECONDS, $seconds ) );
	}

	/**
	 * Download images via SSRF-safe WordPress HTTP APIs with size and pixel bounds.
	 *
	 * @param string $url       Public image URL.
	 * @param int    $parent_id Parent post ID, or zero.
	 * @param array  $post      Attachment data.
	 * @param float  $seconds   Seconds this record may still spend on the network, or zero for no deadline.
	 * @throws \RuntimeException When image validation or sideload fails.
	 */
	private static function image( string $url, int $parent_id, array $post = array(), float $seconds = 0.0 ): int {
		if ( ! wp_http_validate_url( $url ) ) {
			throw new \RuntimeException( esc_html__( 'Image URL must be a public HTTP(S) address.', 'import-everything' ) );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$temp = wp_tempnam( 'import-everything' );
		try {
			self::download( $url, $temp, self::MAX_IMAGE_BYTES, self::image_seconds( $seconds ) );
			if ( filesize( $temp ) > self::MAX_IMAGE_BYTES ) {
				throw new \RuntimeException( esc_html__( 'Image exceeds 10 MiB.', 'import-everything' ) );
			}
			$info       = wp_getimagesize( $temp );
			$extensions = array(
				'image/jpeg' => 'jpg',
				'image/png'  => 'png',
				'image/gif'  => 'gif',
				'image/webp' => 'webp',
			);
			if ( ! $info || ! isset( $extensions[ $info['mime'] ] ) || $info[0] * $info[1] > self::MAX_IMAGE_PIXELS ) {
				throw new \RuntimeException( esc_html__( 'Use a JPEG, PNG, GIF, or WebP image of at most 40 megapixels.', 'import-everything' ) );
			}
			$name = 'import-everything-' . substr( hash( 'sha256', $url ), 0, 16 ) . '.' . $extensions[ $info['mime'] ];
			$id   = media_handle_sideload(
				array(
					'name'     => $name,
					'tmp_name' => $temp,
				),
				$parent_id,
				null,
				$post
			);
			if ( is_wp_error( $id ) ) {
				throw new \RuntimeException( esc_html( $id->get_error_message() ) );
			}
			return $id;
		} finally {
			if ( is_file( $temp ) ) {
				wp_delete_file( $temp );
			}
		}
	}

	/**
	 * The durable key a record matches on across runs, or an empty string when it has no usable identifier.
	 *
	 * @param array $record   Flat XML record values.
	 * @param array $settings Validated import settings.
	 */
	public static function identity( array $record, array $settings ): string {
		$unique = $settings['mapping']['unique'];
		$value  = $unique ? ( $record[ $unique ][0] ?? '' ) : wp_json_encode( $record );
		if ( ! is_string( $value ) || '' === $value ) {
			return '';
		}
		return self::IDENTITY_SCHEME . ':' . hash( 'sha256', $settings['identity'] . '|' . $settings['type'] . '|' . $value );
	}

	/**
	 * Every stored value one import key can match. Releases before 1.2.11 wrote the digest alone, and the chunked
	 * migration that tags those rows can take several requests — so an import that runs between two chunks would
	 * miss its older post and create a duplicate if the lookup only asked for the tagged form. Accepting both is a
	 * read-only widening: the bare value is the same hash under the same scheme, and a row matched that way is
	 * re-tagged by the update the import writes anyway.
	 *
	 * @param string $key Import key from identity().
	 * @return string[] The tagged key, plus the untagged digest beside it.
	 */
	private static function aliases( string $key ): array {
		$prefix = self::IDENTITY_SCHEME . ':';
		return str_starts_with( $key, $prefix ) ? array( $key, substr( $key, strlen( $prefix ) ) ) : array( $key );
	}

	/**
	 * Find the post an import key already belongs to, including trashed and private ones. The lookup accepts the
	 * untagged digest beside the key (see aliases()), so it still finds a post whose identity the migration has not
	 * rewritten yet.
	 *
	 * @param string $key  Import key from identity().
	 * @param string $type Post type being imported.
	 */
	private static function locate( string $key, string $type ): int {
		if ( '' === $key ) {
			return 0;
		}
		$match = array(
			'key'     => self::IDENTITY_META,
			'value'   => self::aliases( $key ),
			'compare' => 'IN',
		);
		// While the 1.3.0 key rename is unfinished, a post imported by an earlier release still carries the old meta
		// key; without the second clause the import would not find it and would create the record again.
		$meta = Admin::migrating()
			? array( 'relation' => 'OR', $match, array( 'key' => self::LEGACY_IDENTITY_META, 'value' => self::aliases( $key ), 'compare' => 'IN' ) )
			: array( $match );
		$ids = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future', 'inherit', 'trash' ),
				'meta_query'       => $meta,
				'fields'           => 'ids',
				'numberposts'      => 1,
				'suppress_filters' => true,
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}

	/**
	 * Whether a record already exists, without writing anything: a resumed batch uses this to tell an
	 * interrupted record that landed from one that did not.
	 *
	 * @param array $record   Flat XML record values.
	 * @param array $settings Validated import settings.
	 */
	public static function find( array $record, array $settings ): int {
		return self::locate( self::identity( $record, $settings ), $settings['type'] );
	}

	/**
	 * Import a record, returning a durable result for the batch journal.
	 *
	 * @param array $record   Flat XML record values.
	 * @param array $settings Validated import settings.
	 * @param float $seconds  Seconds this record may still spend on the network, or zero for no deadline.
	 * @throws \RuntimeException When a record cannot be safely written.
	 */
	public static function record( array $record, array $settings, float $seconds = 0.0 ): array {
		$data = self::preview( $record, $settings );
		if ( '' === $data['title'] ) {
			throw new \RuntimeException( esc_html__( 'Record has an empty mapped title.', 'import-everything' ) );
		}
		$key = self::identity( $record, $settings );
		if ( '' === $key ) {
			throw new \RuntimeException( esc_html__( 'Record has an empty unique identifier.', 'import-everything' ) );
		}
		$id = self::locate( $key, $settings['type'] );
		if ( $id && 'skip' === $settings['duplicates'] ) {
			return array(
				'result'  => 'skipped',
				'id'      => $id,
				'warning' => '',
			);
		}
		if ( $id && ( 'trash' === get_post_status( $id ) || ! current_user_can( 'edit_post', $id ) ) ) {
			throw new \RuntimeException( esc_html__( 'Existing record is trashed or cannot be edited.', 'import-everything' ) );
		}
		// Core attachments cannot be draft/pending: keep non-published imports private.
		$status = 'attachment' === $settings['type'] ? ( 'publish' === $settings['status'] ? 'inherit' : 'private' ) : $settings['status'];
		$post   = array(
			'post_title'   => $data['title'],
			'post_content' => $data['content'],
			'post_excerpt' => $data['excerpt'],
			'post_type'    => $settings['type'],
			'post_status'  => $status,
			// Write the identity with the insert itself, so a request killed between creating the post and
			// storing its identity cannot leave an unmatchable post that a retry would duplicate.
			'meta_input'   => array( self::IDENTITY_META => $key ),
		);
		$old    = $id;
		if ( ! $id ) {
			$post['post_author'] = get_current_user_id();
		}
		if ( 'attachment' === $settings['type'] && ! $id ) {
			$id = self::image( $data['image'], 0, wp_slash( $post ), $seconds );
		} else {
			$post['ID'] = $id;
			$id         = wp_insert_post( wp_slash( $post ), true );
			if ( is_wp_error( $id ) ) {
				throw new \RuntimeException( esc_html( $id->get_error_message() ) );
			}
		}
		update_post_meta( $id, self::IDENTITY_META, $key );
		foreach ( $data['custom'] as $name => $value ) {
			if ( is_array( $value ) && 'rows' === ( $settings['custom'][ $name ]['mode'] ?? '' ) ) {
				delete_post_meta( $id, $name );
				foreach ( $value as $one ) {
					add_post_meta( $id, $name, wp_slash( $one ), false );
				}
				continue;
			}
			update_post_meta( $id, $name, wp_slash( $value ) );
		}
		// Terms are replaced, not appended, so an update reflects the feed's own list for this record.
		foreach ( self::TAXONOMIES as $target => $taxonomy ) {
			if ( ! $data[ $target ] || ! is_object_in_taxonomy( $settings['type'], $taxonomy ) ) {
				continue;
			}
			$terms = wp_set_object_terms( $id, $data[ $target ], $taxonomy, false );
			if ( is_wp_error( $terms ) ) {
				throw new \RuntimeException( esc_html( $terms->get_error_message() ) );
			}
		}
		$warning = '';
		if ( 'attachment' !== $settings['type'] && $data['image'] ) {
			try {
				if ( get_post_meta( $id, '_iex_image_url', true ) !== $data['image'] || ! get_post_thumbnail_id( $id ) ) {
					$image = self::image( $data['image'], $id, array(), $seconds );
					if ( ! set_post_thumbnail( $id, $image ) ) {
						wp_delete_attachment( $image, true );
						throw new \RuntimeException( esc_html__( 'Could not set featured image.', 'import-everything' ) );
					}
					update_post_meta( $id, '_iex_image_url', $data['image'] );
				}
			} catch ( \RuntimeException $error ) {
				$warning = $error->getMessage();
			}
		}
		return array(
			'result'  => $old ? 'updated' : 'created',
			'id'      => $id,
			'warning' => $warning,
		);
	}
}
