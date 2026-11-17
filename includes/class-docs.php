<?php
/**
 * The plugin's own documentation page.
 *
 * @package ImportEverything
 */

namespace ImportEverything;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the reference page linked from this plugin's entry in the admin sidebar.
 *
 * Every word on it is authored here: no feed value, session detail or stored setting is printed, so explaining the
 * plugin cannot become another path untrusted text takes to the browser. The limits it states are read from the
 * constants the classes enforce — the same ones the support report publishes — so a changed cap cannot leave this
 * page describing the old one.
 */
final class Docs {

	/** Page slug, shared by the menu registration and the asset loader so the screen is named once. */
	const SLUG = 'import-everything-docs';

	/** Print the page. Registered as the submenu callback, so it is reached only by a request for this screen. */
	public static function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$sections = self::sections();
		echo '<div class="wrap" id="iex-app"><div class="iex-docs"><h1>' . esc_html__( 'Documentation', 'import-everything' ) . '</h1>';
		echo self::contents( $sections );
		foreach ( $sections as $id => $section ) {
			echo self::section( $id, $section[0], $section[1]() );
		}
		echo '</div></div>';
	}

	/**
	 * The whole page in one place: the anchor, the label the contents list and the heading share, and the body.
	 *
	 * One table means an anchor, a title or a body cannot be added, renamed or reordered in three separate spots.
	 *
	 * @return array<string, array{0: string, 1: callable(): string}>
	 */
	private static function sections(): array {
		return array(
			'quick-start'     => array( __( 'Quick start', 'import-everything' ), static fn(): string => self::quick_start() ),
			'sources'         => array( __( 'Sources', 'import-everything' ), static fn(): string => self::sources() ),
			'mapping'         => array( __( 'Mapping fields', 'import-everything' ), static fn(): string => self::mapping() ),
			'preview'         => array( __( 'Preview and import', 'import-everything' ), static fn(): string => self::preview() ),
			'feeds'           => array( __( 'Saved feeds and schedules', 'import-everything' ), static fn(): string => self::feeds() ),
			'history'         => array( __( 'Recent imports', 'import-everything' ), static fn(): string => self::history() ),
			'support'         => array( __( 'Support report', 'import-everything' ), static fn(): string => self::support() ),
			'limits'          => array( __( 'Limits and formats', 'import-everything' ), static fn(): string => self::limits() ),
			'troubleshooting' => array( __( 'Troubleshooting', 'import-everything' ), static fn(): string => self::troubleshooting() ),
			'security'        => array( __( 'Security and privacy', 'import-everything' ), static fn(): string => self::security() ),
			'uninstall'       => array( __( 'Uninstall', 'import-everything' ), static fn(): string => self::uninstall() ),
		);
	}

	/**
	 * The table of contents, built from the same table the page renders from.
	 *
	 * @param array<string, array{0: string, 1: callable(): string}> $sections Page sections.
	 */
	private static function contents( array $sections ): string {
		$out = '<nav class="iex-doc-toc" aria-label="' . esc_attr( __( 'Documentation sections', 'import-everything' ) ) . '">';
		foreach ( $sections as $id => $section ) {
			$out .= '<a href="#iex-doc-' . esc_attr( $id ) . '">' . esc_html( $section[0] ) . '</a>';
		}
		return $out . '</nav>';
	}

	/** Quick start section body. */
	private static function quick_start(): string {
		$requirements = array(
			__( 'WordPress 6.4 or newer, on PHP 8.1 or newer.', 'import-everything' ),
			__( 'The PHP XMLReader, DOM, mbstring and zlib extensions. Activation stops with a message naming the extensions it needs.', 'import-everything' ),
			__( 'The manage_options and import capabilities, which an administrator has. Every request re-checks them.', 'import-everything' ),
			__( 'Normal WordPress HTTP and media support, so a URL feed can be downloaded and an image can be resized.', 'import-everything' ),
		);
		$steps        = array(
			__( 'Open Import Everything! in the admin sidebar. The page has three tabs: Import, Saved feeds and Recent imports.', 'import-everything' ),
			__( 'On Import, add a source: choose an XML, JSON, CSV or TSV file, or paste a public feed or Google Sheets URL.', 'import-everything' ),
			__( 'Confirm the record element the plugin guessed, and review the field mapping it suggested.', 'import-everything' ),
			__( 'Choose the post type, the post status, the import identity and what to do with a record that already exists.', 'import-everything' ),
			__( 'Generate the preview, read it, tick the confirmation box, and import.', 'import-everything' ),
		);
		return self::h( __( 'Requirements', 'import-everything' ) ) . self::list_of( $requirements )
			. self::h( __( 'Your first import', 'import-everything' ) ) . self::list_of( $steps, 'ol' );
	}

	/** Sources section body. */
	private static function sources(): string {
		$formats = array(
			__( 'XML, RSS and Atom: records are the elements named item, entry, record, photo, video, gallery, media, post, article, url or page, and channel and feed are read as containers rather than records.', 'import-everything' ),
			__( 'JSON: a record list is any array whose elements are objects. Nested keys become slash-separated paths such as meta/note, / inside a key is escaped as ~1 and ~ as ~0, an array of values becomes a repeated field, and a top-level array is read as the record list.', 'import-everything' ),
			__( 'CSV and TSV: the header row becomes the field paths, so one line is one record. Comma, semicolon, tab and pipe are detected from the header row, a UTF-8 BOM is stripped, a blank header cell is named column_N, a repeated header name gets a numeric suffix, a short row fills its missing cells with empty values and extra cells are ignored.', 'import-everything' ),
			__( 'Google Sheets: paste the sheet link as your browser shows it, or a published-to-web link. The sheet is read through its own CSV export, keeping the tab named by gid whether it sits in the query or after the #. No API key, OAuth or service account is involved, so the sheet must be shared or published to the web.', 'import-everything' ),
		);
		return self::p( __( 'A source is an uploaded file or a public http(s) address. The format is decided from the content — the first non-whitespace byte, with a UTF-8 BOM ignored — never from the extension or the URL, so a .json address that serves XML is read as XML.', 'import-everything' ) )
			. self::h( __( 'What is read', 'import-everything' ) ) . self::list_of( $formats )
			. self::h( __( 'Record element and fields', 'import-everything' ) )
			. self::p( __( 'After discovery the mapping step names the record path it chose and lists every candidate it found with a record count. Picking another re-analyzes the feed, which resets the field mapping, because the fields of one record path do not describe another.', 'import-everything' ) )
			. self::p( __( 'Field discovery scans every selected record, not a sample, and unions what it finds: attributes, nested elements, CDATA, namespace prefixes, empty fields and repeated values. A repeated field records how many values it held, and the mapping dropdowns offer those positions.', 'import-everything' ) )
			. self::h( __( 'What is downloaded, and what can go wrong', 'import-everything' ) )
			. self::list_of(
				array(
					sprintf( __( 'A URL feed is downloaded through the same public-address checks on every redirect it follows, at most %1$d redirects, with a %2$s cap.', 'import-everything' ), Importer::MAX_REDIRECTS, self::mib( XML::MAX_BYTES ) ),
					__( 'An HTTP 200 with an empty body is refused and names the host: a dump whose filters match no records, or whose key is not allowed for this server, often answers that way.', 'import-everything' ),
					__( 'A sign-in page, a challenge or a private sheet arrives as HTML and is named as a web page instead of being reported as invalid XML.', 'import-everything' ),
					__( 'An Excel workbook (.xlsx) is not read: export the sheet as CSV first, which every spreadsheet application and Google Sheets do.', 'import-everything' ),
					__( 'Push sources are not readers. A webhook or a streaming subject is imported through the URL its server publishes, because an import pulls.', 'import-everything' ),
				)
			)
			. self::p( __( 'Before a session is created the plugin checks the feed, and the compressed copy it will store, against the memory actually left under memory_limit. A feed that cannot fit is refused with the amount it needs and three ways forward: import from a URL, split the file, or raise the limit.', 'import-everything' ), 'iex-doc-note' );
	}

	/** Mapping section body. */
	private static function mapping(): string {
		$term_fields = array(
			__( 'Categories, Tags and Actors are mapping targets of their own. Actors is WP-Script’s taxonomy on the Videos type.', 'import-everything' ),
			__( 'Every value of the mapped field becomes a term, and a value holding commas lists several (News, Sport), so a repeated RSS category, a JSON array and a spreadsheet cell all work the same way.', 'import-everything' ),
			sprintf( __( 'Names are trimmed, de-duplicated and capped at %d per record. A term that does not exist yet is created, which is normal WordPress behaviour for an importer.', 'import-everything' ), Importer::MAX_TERMS ),
			__( 'A mapped taxonomy replaces the post’s terms rather than appending to them, so a re-run with Update existing records reflects the feed’s own list.', 'import-everything' ),
			__( 'The chosen post type must register the taxonomy. Pages register neither categories nor tags by default, and the server refuses such a mapping with a message naming both the post type and the taxonomy.', 'import-everything' ),
		);
		$modes       = array(
			array( __( 'First value', 'import-everything' ), __( 'Writes the first value of the field.', 'import-everything' ) ),
			array( __( 'Join with newlines', 'import-everything' ), __( 'Writes every value of the field as one string, separated by line breaks.', 'import-everything' ) ),
			array( __( 'Store as array', 'import-everything' ), __( 'Writes one serialized array of strings. A theme reading it must unserialize it; this is not a set of media IDs.', 'import-everything' ) ),
			array( __( 'One meta row per value', 'import-everything' ), __( 'Writes one meta row per value, which is how WP-Script’s cards read their thumbs slideshow. Blank cells are dropped and each kept URL is trimmed.', 'import-everything' ) ),
		);
		$gallery     = array(
			__( 'A custom field that keeps every value — one meta row per value, or stored as array — can name a list of fields instead of one, for a feed that gives each screenshot its own numbered element (screen_1, screen_2, screen_3).', 'import-everything' ),
			__( 'The values are gathered in the order the list names them into one meta key — one meta row per image in the row-per-value mode — and the mapping step shows the extra fields under the row.', 'import-everything' ),
			sprintf( __( 'The row’s own field must be the first of the list. At least two distinct fields are required, at most %d are accepted, and each name is checked against the schema this server discovered. A single field stays an ordinary row.', 'import-everything' ), Importer::GALLERY_PATHS ),
		);
		return self::h( __( 'Where a record goes', 'import-everything' ) )
			. self::p( __( 'The target post type can be Posts, Pages, an image Attachment, or any registered custom post type whose create capability you hold. The post status is draft, pending or publish; an attachment cannot be drafted, so those choices are stored as private and publish becomes inherit.', 'import-everything' ) )
			. self::p( __( 'Title, content, excerpt and unique identifier are single feed paths, so none of them takes a joined value or typed text; a record’s title is always its own. The featured image may join two feed lines or pick one value position, but it too cannot carry typed text. Content, excerpts and metadata keep safe HTML only, and the preview shows them as text — frontend rendering is your theme’s business.', 'import-everything' ) )
			. self::h( __( 'Categories, tags and Actors', 'import-everything' ) ) . self::list_of( $term_fields )
			. self::h( __( 'Custom fields', 'import-everything' ) )
			. self::p( sprintf( __( 'A custom row stores one meta key of your choosing. Type the exact key your theme reads: letters and numbers, dots, dashes and underscores, up to 191 characters, and never a protected key starting with an underscore. Each feed field may be mapped more than once, but a meta key may be used only once. Up to %d custom rows are accepted.', 'import-everything' ), Importer::CUSTOM_MAX ) )
			. self::table( array( __( 'Repeated values', 'import-everything' ), __( 'What it stores', 'import-everything' ) ), $modes )
			. self::p( __( 'A row also has a value format. As in the feed stores the text unchanged; Duration → seconds converts a count, a clock such as 05:00 or an ISO 8601 value such as PT5M to whole seconds, because WP-Script stores duration that way. An unreadable duration writes nothing instead of zero. The conversion is refused for a repeating mode or a joined path, because it reads exactly one value.', 'import-everything' ) )
			. self::p( __( 'One meta row per value also splits a single cell that lists its images with commas, semicolons, pipes, line breaks or spaces — but only when every part is an absolute URL, so an image whose query string contains a comma stays one row. The split happens before HTML escaping, so the semicolon of an escaped &amp; is never mistaken for a separator.', 'import-everything' ) )
			. self::h( __( 'A gallery split across fields', 'import-everything' ) ) . self::list_of( $gallery )
			. self::h( __( 'Typed values: const: and tpl:', 'import-everything' ) )
			. self::p( __( 'A term target or a custom row can carry text of its own instead of a feed field. Type Uncategorized to file every record under that category, or on for WP-Script’s hd_video, and that text is written for every record; type {site_name} - {title} and the value is built per record. The stored mapping tags the two shapes as const: and tpl: so saved feeds and mapping templates keep them.', 'import-everything' ) )
			. self::list_of(
				array(
					__( 'Every {field} placeholder in a template is checked against the fields this server discovered, so a template can read no more than a dropdown can.', 'import-everything' ),
					__( 'A template that fills none of its placeholders writes nothing, rather than the punctuation around them.', 'import-everything' ),
					__( 'A fixed term value is split on commas like a field’s own values and must leave at least one name.', 'import-everything' ),
					__( 'Text is one value, so a custom row carrying it must use First value, and a template may itself contain the two-line separator.', 'import-everything' ),
					sprintf( __( 'A fixed value or template is at most %d characters.', 'import-everything' ), Importer::MAX_LITERAL ),
				)
			)
			. self::h( __( 'Two feed lines for one URL', 'import-everything' ) )
			. '<p>' . esc_html__( 'NATS feeds keep a base directory in one element and a relative file name in another, and mapping either line alone gives a 404. Put the base in the field picker and the file name under “Then append” in the same row, or write it yourself: ', 'import-everything' ) . self::code( 'screen_url + clips/clip/screens/screen' ) . esc_html__( ' — the two values are then stored as one URL.', 'import-everything' ) . '</p>'
			. self::list_of(
				array(
					__( 'Only the featured image and a custom field may hold a join, because only those targets store a URL.', 'import-everything' ),
					__( 'Both parts must exist in the discovered schema, and at most two parts are accepted.', 'import-everything' ),
					__( 'The two values are concatenated verbatim — no separator is inserted — and a part missing from a record contributes nothing instead of failing the record.', 'import-everything' ),
					__( 'A joined value is one string, so its repeated-values mode must stay First value.', 'import-everything' ),
				)
			)
			. self::h( __( 'Reading a repeated line at a position', 'import-everything' ) )
			. '<p>' . esc_html__( 'Append #2 — or #3, up to the number of values the feed held — to a part to read that value instead of the first. The dropdowns offer the same thing as “value 2”, “value 3”: ', 'import-everything' ) . self::code( 'screen_url + clips/clip/screens/screen#2' ) . esc_html__( ' takes GetScoreCash’s second screenshot instead of the first.', 'import-everything' ) . '</p>'
			. self::list_of(
				array(
					__( 'Only the featured image and a First-value custom row may use a position, because those read one value.', 'import-everything' ),
					__( 'An exact field path always wins: a JSON key that itself ends in #2 is read as that key, not as a position, even when the feed also has a repeated field the position could mean.', 'import-everything' ),
					__( 'The position is checked against the value count the schema recorded, and a record carrying fewer values leaves that field empty for that record.', 'import-everything' ),
				)
			)
			. self::h( __( 'Presets', 'import-everything' ) )
			. self::p( __( 'A preset fills a whole mapping in one click and replaces whatever was mapped before, so apply it before you fine-tune rows. Each one only uses fields your feed really has and reports the recipe entries it could not find instead of inventing a meta key; the WP-Script convention keys it fills with fixed text are the exception, and it says how many of those it wrote.', 'import-everything' ) )
			. self::list_of(
				array(
					__( 'NATS: the featured image, the two-line video URL and rows named duration, model_name, site_id and site_name.', 'import-everything' ),
					__( 'WP-Script: video_url and its per-quality files (240p to 4K), the thumb poster, duration in seconds, the hover preview under both trailer_url and preview_url, embed, shortcode, tracking_url, the thumbs gallery, and Categories, Tags and Actors — whichever of those the target post type registers. It switches the target to the Videos type when that type exists, writes hd_video as on and the view and vote counters as 0 when the feed ships none, and names anything else it left out.', 'import-everything' ),
					__( 'Tube Corporate: the same video fields from that family’s partner feed columns — id as identity, title and description, main_screenshot as the featured image and thumb, duration, video_url joined to dir when the file is relative, preview_url under both preview keys, embed, video_viewed as the view count, screenshots as the thumbs gallery, and categories, tags and models as Actors. It switches the target to the Videos type the same way.', 'import-everything' ),
				)
			)
			. self::h( __( 'Saving a mapping', 'import-everything' ) )
			. self::p( __( 'Save mapping downloads a JSON template of this mapping — the post type, status, duplicate behaviour, term targets and custom rows, including any fixed value or template. It contains no feed URL, no credentials and no session data, so it can be moved to another site. Load mapping reads one back, keeps only the fields that feed actually has, and says how many mappings it omitted; the file must be smaller than 256 KiB.', 'import-everything' ) )
			. self::p( __( 'The mapping step adds a hint at the end of the mapping card when a field is empty in every record of the feed, naming the mapping targets that read it (custom rows are not scanned), and the preview’s term rows read — empty in every record or — none in this record instead of a bare dash that looks like a broken mapping. A fixed value is never called empty.', 'import-everything' ), 'iex-doc-note' );
	}

	/** Preview and import section body. */
	private static function preview(): string {
		return self::p( __( 'The preview renders up to five records exactly as an import would write them, with the categories, tags and Actors resolved to term names, and with custom rows already converted, joined and split. It creates no posts and fetches no media.', 'import-everything' ) )
			. self::h( __( 'Duplicates: identity and unique field', 'import-everything' ) )
			. self::p( __( 'Every imported post stores an identity hashed from the import identity text, the post type and the unique field, and that identity is how a later run recognises the same record. Leave the unique field empty and a fingerprint of the whole record is used instead — then changed feed data becomes a new record.', 'import-everything' ) )
			. self::list_of(
				array(
					__( 'Keep the same import identity and unique field on every re-run, and use a different identity for unrelated feeds, even when they share a domain.', 'import-everything' ),
					__( 'Skip existing records imports only what is new and never downloads an image for a skipped record.', 'import-everything' ),
					__( 'Update mapped fields replaces title, content, excerpt, status, categories, tags and mapped custom keys, and retries image warnings. A blank content or excerpt mapping clears that field; unmapped custom metadata is preserved.', 'import-everything' ),
					__( 'The stored identity carries the algorithm that produced it (sha256:), so a future change of scheme is a migration instead of every existing post failing to match and being imported twice.', 'import-everything' ),
				)
			)
			. self::h( __( 'Batches, pause and resume', 'import-everything' ) )
			. self::p( sprintf( __( 'Import copies the feed’s records into a private spool on the server, then imports them in batches. Records per batch is any whole number from 1 to %1$d — %2$d unless you change it — and is saved for this site when you leave the field or press Enter. Fewer records per request means less memory used at once, which is what a small shared host runs out of; more turns a %3$d-record feed into %4$d requests instead of %5$d.', 'import-everything' ), Admin::BATCH_MAX, Admin::BATCH_RECORDS, 1000, (int) ( 1000 / Admin::BATCH_MAX ), (int) ( 1000 / Admin::BATCH_RECORDS ) ) )
			. self::list_of(
				array(
					sprintf( __( 'A batch also stops on its own deadline: %1$d seconds of record work when the server sets no limit, otherwise the request’s limit minus what it has already spent and %2$d seconds held back so the record in flight can finish. It never starts a record with less than %3$d seconds left.', 'import-everything' ), Admin::BATCH_SECONDS, Admin::REQUEST_RESERVE, Admin::RECORD_FLOOR_SECONDS ),
					sprintf( __( 'Each image download is capped by what is left of that deadline, between %1$d and %2$d seconds, so a stalled image fails on its own instead of the request being killed mid-write.', 'import-everything' ), Importer::MIN_IMAGE_SECONDS, Importer::IMAGE_SECONDS ),
					sprintf( __( 'Pausing waits for the batch in flight and then stops. Closing the tab does not end an import: the run is kept under Recent imports for %d hours and Resume continues from the next record.', 'import-everything' ), (int) ( DAY_IN_SECONDS / HOUR_IN_SECONDS ) ),
					sprintf( __( 'If another request holds this identity’s batch lock, the browser waits the number of seconds the server reports and retries by itself, for up to five minutes. The lock is refreshed after every record and is treated as abandoned only after %d seconds of silence.', 'import-everything' ), Admin::LOCK_TTL ),
					__( 'The journal records after every record, and a request killed in the middle is resolved by the next batch: the record is matched by identity first, then skipped or completed, so an interrupted import reconciles instead of duplicating.', 'import-everything' ),
				)
			)
			. self::h( __( 'Images and attachments', 'import-everything' ) )
			. self::list_of(
				array(
					sprintf( __( 'A featured image is downloaded and attached only for JPEG, PNG, GIF and WebP, at most %1$s and %2$s. SVG, AVIF and video files are not fetched.', 'import-everything' ), self::mib( Importer::MAX_IMAGE_BYTES ), self::megapixels( Importer::MAX_IMAGE_PIXELS ) ),
					__( 'A failed image does not discard the post: the record imports with a warning, and the latest 100 issues are kept in the report. In attachment mode the image is the record, so a failed image fails that record.', 'import-everything' ),
					__( 'Updating reuses an unchanged image that was already imported on the same post. Changing an image URL leaves the earlier file in the media library.', 'import-everything' ),
					__( 'An attachment record stored as private or pending is not file-level access control: media URLs stay public under normal WordPress uploads.', 'import-everything' ),
				)
			)
			. self::p( __( 'Imports are not transactional and have no rollback. Run one against a backup, and prefer Skip for a first pass over content you did not create.', 'import-everything' ), 'iex-doc-note' );
	}

	/** Saved feeds and schedules section body. */
	private static function feeds(): string {
		return self::p( __( 'A saved feed keeps a feed and its mapping, so the same import can be run again or on a schedule without repeating the wizard. Save one from the mapping step: a URL feed keeps its address and is read again on every run, while an uploaded file is stored as a private copy on this site and runs without the original file.', 'import-everything' ) )
			. self::p( __( 'Saved feeds has one row per feed: its name, whether it imports as drafts, pending review or published, where its last run got to, when it runs next, and its address behind Show URL.', 'import-everything' ) )
			. self::h( __( 'What the buttons do', 'import-everything' ) )
			. self::table(
				array( __( 'Control', 'import-everything' ), __( 'Effect', 'import-everything' ) ),
				array(
					array( __( 'Edit', 'import-everything' ), __( 'Renames the feed, and for a URL feed also changes its address. The new address is read once and the saved mapping is checked against it, so a feed that moved cannot be saved pointing at a document that lost its fields.', 'import-everything' ) ),
					array( __( 'Run import', 'import-everything' ), __( 'Starts a run now, after re-reading a URL feed and revalidating the mapping. A feed that dropped a mapped field records one clear failure naming the feed and what no longer fits, and spools nothing.', 'import-everything' ) ),
					array( __( 'Edit mapping', 'import-everything' ), __( 'Opens the saved feed in the mapping step. Saving it again updates the feed instead of creating a second one, and keeps its name.', 'import-everything' ) ),
					array( __( 'Delete posts', 'import-everything' ), __( 'Permanently deletes the posts this feed imported, in passes, and removes a featured image the importer downloaded. The feed, its upload, its mapping and its schedule are kept. A post you may not delete is left in place and counted as blocked.', 'import-everything' ) ),
					array( __( 'Delete', 'import-everything' ), __( 'Removes the feed, its stored upload and its schedule. Imported posts stay.', 'import-everything' ) ),
				)
			)
			. self::h( __( 'Schedules', 'import-everything' ) )
			. self::p( __( 'The schedule picker offers Never (manual), Hourly, Twice daily and Daily — WordPress’s own recurrences, so no custom interval is registered. Runs use WP-Cron, which means they happen on page loads or when a system cron calls wp-cron.php; a scheduled run is not a live stream.', 'import-everything' ) )
			. self::list_of(
				array(
					sprintf( __( 'A pass runs for up to %d minutes, or for less when the server’s own max_execution_time is shorter, and hands the remaining records to a follow-up event one minute later instead of being killed part-way.', 'import-everything' ), (int) round( Feeds::CRON_SECONDS / 60 ) ),
					__( 'A feed whose run is still in flight is skipped until it finishes or its private session expires.', 'import-everything' ),
					__( 'If a pass is killed anyway, the next scheduled run resumes the session from the record it reached. Only when that session can no longer be read does the feed record a failed result explaining it and start fresh next time.', 'import-everything' ),
					__( 'The owner’s manage_options and import capabilities are checked again when a scheduled run starts, under that owner’s own account.', 'import-everything' ),
				)
			)
			. self::p( __( 'When the latest run of a saved feed failed, the plugin screen says so at the top: the feed, when it failed, what the server said, and a button that opens Saved feeds. Returning to the browser tab re-reads the list, so a failure recorded while the page sat open appears without a reload.', 'import-everything' ) );
	}

	/** Recent imports section body. */
	private static function history(): string {
		return self::p( sprintf( __( 'Recent imports remembers the last %1$d runs on this site: the feed name, the post type and status, the record counts, the result, and the last %2$d journal lines. It holds no feed contents, no sample values and no server paths, and only the administrator who ran an import can read it.', 'import-everything' ), Admin::JOBS_MAX, Admin::JOB_JOURNAL ) )
			. self::list_of(
				array(
					__( 'Resume puts the wizard back on the progress step and continues the run from the next record — including a record a killed request left half-written, which the journal names.', 'import-everything' ),
					__( 'A run whose session has expired reads as expired instead of offering a Resume that cannot work. Reload the source with the same identity and unique field to reconcile what was already imported.', 'import-everything' ),
					sprintf( __( 'Each entry is pruned %d days after it was last touched, and starting another import clears the previous completed session while its history entry stays.', 'import-everything' ), (int) ( Admin::JOB_TTL / DAY_IN_SECONDS ) ),
				)
			)
			. self::p( __( 'An interactive import is not a background job: resuming one needs this page open in a browser. A saved feed with a schedule is the background path.', 'import-everything' ) );
	}

	/** Support report section body. */
	private static function support(): string {
		return self::p( __( 'Download support report appears once a feed is analyzed. It bundles this plugin’s version and limits, your WordPress and PHP environment, the record path and counts, the mapped settings, the job counters and journal, and this identity’s batch-lock state into one JSON file you can attach to a support request.', 'import-everything' ) )
			. self::list_of(
				array(
					__( 'It reads an existing session: no writes and no downloads. It describes an unexpired session, so download it while the import is open.', 'import-everything' ),
					__( 'The feed file and the schema’s sample values are never included.', 'import-everything' ),
					__( 'Tick “Include field names” and it also lists the field paths it discovered — names only, never a value — which is often what a support request needs in order to name the field that is missing.', 'import-everything' ),
					__( 'The list is reported as field_names, because source.fields beside it is a count.', 'import-everything' ),
					__( 'Review the file before sharing it: journal entries can quote feed values.', 'import-everything' ),
				)
			);
	}

	/**
	 * Limits section body.
	 *
	 * Every value here is read from the constant the class enforces — the same constants the support report
	 * publishes — so this table cannot drift from the build that ships it.
	 */
	private static function limits(): string {
		return self::p( __( 'Every value below is read from the constant that enforces it, the same ones the support report publishes, so this table cannot describe an older build.', 'import-everything' ) )
			. self::table(
				array( __( 'Limit', 'import-everything' ), __( 'Value', 'import-everything' ) ),
				array(
					array(
						__( 'Feed size', 'import-everything' ),
						self::mib( XML::MAX_BYTES ) . ' — ' . __( 'each upload is also bounded by your host’s upload limits, which the UI shows.', 'import-everything' ),
					),
					array( __( 'Records per feed', 'import-everything' ), self::counted( XML::MAX_RECORDS ) ),
					array( __( 'Field paths per feed', 'import-everything' ), self::counted( XML::MAX_FIELDS ) ),
					array( __( 'Nesting depth', 'import-everything' ), sprintf( __( '%d levels', 'import-everything' ), JSON::MAX_DEPTH ) ),
					array( __( 'One record', 'import-everything' ), self::mib( XML::MAX_RECORD_BYTES ) ),
					array(
						__( 'Images', 'import-everything' ),
						sprintf( __( 'JPEG, PNG, GIF or WebP; at most %1$s and %2$s.', 'import-everything' ), self::mib( Importer::MAX_IMAGE_BYTES ), self::megapixels( Importer::MAX_IMAGE_PIXELS ) ),
					),
					array(
						__( 'Terms per record', 'import-everything' ),
						sprintf( __( 'At most %d names from one mapped taxonomy field.', 'import-everything' ), Importer::MAX_TERMS ),
					),
					array( __( 'Custom rows per mapping', 'import-everything' ), self::counted( Importer::CUSTOM_MAX ) ),
					array( __( 'Gallery fields per row', 'import-everything' ), self::counted( Importer::GALLERY_PATHS ) ),
					array(
						__( 'Typed value or template', 'import-everything' ),
						sprintf( __( 'At most %s characters.', 'import-everything' ), self::counted( Importer::MAX_LITERAL ) ),
					),
					array(
						__( 'Records per batch', 'import-everything' ),
						sprintf( __( '%1$d to %2$d, %3$d unless you change it; %4$d seconds of work per batch when no PHP time limit is set.', 'import-everything' ), 1, Admin::BATCH_MAX, Admin::BATCH_RECORDS, Admin::BATCH_SECONDS ),
					),
					array(
						__( 'Remembered runs', 'import-everything' ),
						sprintf( __( '%1$s, pruned %2$d days after their last update, %3$d journal lines each.', 'import-everything' ), self::counted( Admin::JOBS_MAX ), (int) ( Admin::JOB_TTL / DAY_IN_SECONDS ), Admin::JOB_JOURNAL ),
					),
					array(
						__( 'Import session', 'import-everything' ),
						sprintf( __( '%d hours, stored as a private per-user transient.', 'import-everything' ), (int) ( DAY_IN_SECONDS / HOUR_IN_SECONDS ) ),
					),
				)
			)
			. self::p( __( 'A record spool costs one file of about the feed’s size in the server’s temporary directory while its import is resumable; it is not web-reachable, its path never reaches the browser, and it is deleted as soon as the import finishes or is cleared.', 'import-everything' ) );
	}

	/** Troubleshooting section body. */
	private static function troubleshooting(): string {
		return self::table(
			array( __( 'What you see', 'import-everything' ), __( 'What to do', 'import-everything' ) ),
			array(
				array(
					__( '“Session expired. Reload your feed.”', 'import-everything' ),
					sprintf( __( 'The private copy of the feed lived %d hours. Reload the source with the same import identity and unique field; records already imported then match instead of being created again.', 'import-everything' ), (int) ( DAY_IN_SECONDS / HOUR_IN_SECONDS ) ),
				),
				array(
					__( 'The feed is refused as a web page', 'import-everything' ),
					__( 'The address answered with HTML: a sign-in wall, a challenge, or a sheet that is not shared. Use the export link itself, or publish the sheet to the web.', 'import-everything' ),
				),
				array(
					__( 'An empty 200 response from a feed URL', 'import-everything' ),
					__( 'The server returned no bytes. Open the same URL in a browser: a dump whose filters match no records, or whose key is not allowed for this server, answers that way. Pull the link from the source page so its key and filters are current.', 'import-everything' ),
				),
				array(
					__( '“too large for this server’s memory limit”', 'import-everything' ),
					__( 'The message says how many MB the private copy needs and how much is left, and offers three ways forward: import from a URL, split the file, or raise memory_limit. A feed that barely compresses needs several times its own size to be held and written.', 'import-everything' ),
				),
				array(
					__( 'A mapping is refused for the post type', 'import-everything' ),
					__( 'The chosen type does not register that taxonomy. Pick a type that does on the next step, or leave the field unmapped.', 'import-everything' ),
				),
				array(
					__( 'Re-running created duplicates', 'import-everything' ),
					__( 'The identity or the unique field changed between runs. Use the same import identity text and the same unique field; a feed whose data changed under a fingerprint identity is a new record by design.', 'import-everything' ),
				),
				array(
					__( 'A featured image is missing', 'import-everything' ),
					sprintf( __( 'Read the image warning in the report. Only JPEG, PNG, GIF and WebP up to %1$s and %2$s are fetched, and the URL must be absolute and public.', 'import-everything' ), self::mib( Importer::MAX_IMAGE_BYTES ), self::megapixels( Importer::MAX_IMAGE_PIXELS ) ),
				),
				array(
					__( 'A scheduled feed did not run', 'import-everything' ),
					__( 'WP-Cron only fires on page loads or from a system cron calling wp-cron.php. Check that the schedule is not Never (manual), that the feed is not reporting a failure, and that another run is not still holding it.', 'import-everything' ),
				),
				array(
					__( '“Waiting N seconds for the previous batch”', 'import-everything' ),
					sprintf( __( 'Another request holds this identity’s batch lock. The page waits and retries by itself; the lock is reclaimed after %d seconds without a heartbeat.', 'import-everything' ), Admin::LOCK_TTL ),
				),
				array(
					__( 'A request failed before the plugin answered', 'import-everything' ),
					__( 'The message names the operation, the admin-ajax.php URL, any HTTP status, and whether the page and that address agree on https. A mixed-content block, a site that stopped, or a security plugin filtering admin-ajax.php all read differently from a feed failure.', 'import-everything' ),
				),
				array(
					__( 'A CSV or sheet imports as one column', 'import-everything' ),
					__( 'The header row did not show a separator. Check that the export really is separated values, that the first line is the header, and that a quoted value is fully quoted.', 'import-everything' ),
				),
				array(
					__( 'An .xlsx file is refused', 'import-everything' ),
					__( 'Workbooks are not read. Export the sheet as CSV, or paste the Google Sheets link and let the plugin read its CSV export.', 'import-everything' ),
				),
			)
		);
	}

	/** Security section body. */
	private static function security(): string {
		return self::list_of(
			array(
				__( 'Every request requires manage_options and import, plus a nonce. Post type and publish capabilities are re-checked on every batch, not once at the start.', 'import-everything' ),
				__( 'Downloaded addresses must be public: private, reserved, carrier-grade NAT, documentation, benchmarking and multicast ranges are refused, on every redirect hop.', 'import-everything' ),
				__( 'Each hop’s name is resolved once and the connection is held to those addresses, so a name that answers publicly to the check and privately to the transport is stopped before anything is read.', 'import-everything' ),
				__( 'XML is parsed without network access, without DTDs and without entity substitution; a document type or entity declaration is rejected before it can be expanded.', 'import-everything' ),
				__( 'Field paths come from the client as strings and are checked against the schema this server discovered. There is no XPath, no PHP evaluation and no arbitrary selector.', 'import-everything' ),
				__( 'Content and metadata keep safe HTML only, titles are sanitized as text, and the interface renders every value as text.', 'import-everything' ),
				sprintf( __( 'Feed data lives in private per-user transients for up to %d hours and in a spool file in the server’s temporary directory, which is not web-reachable, while a run is resumable. Transients are not encrypted and an object cache may evict them early.', 'import-everything' ), (int) ( DAY_IN_SECONDS / HOUR_IN_SECONDS ) ),
				__( 'No third-party importer code, branding, libraries or add-ons are included, and no data leaves your site except a request to a feed or image address you supplied.', 'import-everything' ),
			)
		);
	}

	/** Uninstall section body. */
	private static function uninstall(): string {
		return self::p( __( 'Deleting the plugin removes what the plugin stored about itself: its transients and locks, the import history, saved feeds and their schedules, stored uploads, and record spool files. It never deletes imported content — posts, pages, attachments, media files and the identity and image metadata the importer wrote stay in your site, like content you created by hand.', 'import-everything' ) );
	}

	/**
	 * One documentation section: an anchor, its heading, and the body.
	 *
	 * @param string $id    Anchor suffix.
	 * @param string $title Section heading, shared with the contents list.
	 * @param string $body  Authored, already-escaped markup.
	 */
	private static function section( string $id, string $title, string $body ): string {
		return '<section class="iex-doc-section" id="iex-doc-' . esc_attr( $id ) . '"><h2>' . esc_html( $title ) . '</h2>' . $body . '</section>';
	}

	/**
	 * A subheading inside a section.
	 *
	 * @param string $text Translated heading.
	 */
	private static function h( string $text ): string {
		return '<h3>' . esc_html( $text ) . '</h3>';
	}

	/**
	 * A paragraph, or a callout when a class is given.
	 *
	 * @param string $text  Translated text.
	 * @param string $class Optional class, used for the note style.
	 */
	private static function p( string $text, string $class = '' ): string {
		return '<p' . ( '' === $class ? '' : ' class="' . esc_attr( $class ) . '"' ) . '>' . esc_html( $text ) . '</p>';
	}

	/**
	 * A bullet or numbered list of translated items.
	 *
	 * @param array  $items Texts to print, one per item.
	 * @param string $tag   ol for a numbered list, ul otherwise.
	 */
	private static function list_of( array $items, string $tag = 'ul' ): string {
		$tag = 'ol' === $tag ? 'ol' : 'ul';
		$out = '<' . $tag . '>';
		foreach ( $items as $item ) {
			$out .= '<li>' . esc_html( (string) $item ) . '</li>';
		}
		return $out . '</' . $tag . '>';
	}

	/**
	 * A table of translated cells, the first row being its header.
	 *
	 * Cells are text: every one is escaped here, so markup passed in would be printed as markup. A value that needs
	 * styling belongs in the prose around the table, or in a cell printed as plain text.
	 *
	 * @param array $head Header cells.
	 * @param array $rows Rows of cells.
	 */
	private static function table( array $head, array $rows ): string {
		$out = '<table><thead><tr>';
		foreach ( $head as $cell ) {
			$out .= '<th scope="col">' . esc_html( (string) $cell ) . '</th>';
		}
		$out .= '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$out .= '<tr>';
			foreach ( $row as $cell ) {
				$out .= '<td>' . esc_html( (string) $cell ) . '</td>';
			}
			$out .= '</tr>';
		}
		return $out . '</tbody></table>';
	}

	/**
	 * An inline code fragment.
	 *
	 * @param string $text Literal text, escaped for display.
	 */
	private static function code( string $text ): string {
		return '<code>' . esc_html( $text ) . '</code>';
	}

	/**
	 * A byte cap as the whole mebibytes this page states.
	 *
	 * @param int $bytes Byte count.
	 */
	private static function mib( int $bytes ): string {
		return (int) round( $bytes / 1048576 ) . ' MiB';
	}

	/**
	 * A pixel cap as the whole megapixels this page states.
	 *
	 * @param int $pixels Pixel count.
	 */
	private static function megapixels( int $pixels ): string {
		return (int) round( $pixels / 1000000 ) . ' megapixels';
	}

	/**
	 * A whole count with thousands separators, so a four- or five-digit cap reads like the one next to it.
	 *
	 * @param int $value Number to print.
	 */
	private static function counted( int $value ): string {
		return number_format( $value );
	}
}
