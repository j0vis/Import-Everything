<?php
/**
 * Admin UI and authenticated import sessions.
 *
 * @package ImportEverything
 */

namespace ImportEverything;

defined( 'ABSPATH' ) || exit;

/** Owns short-lived private feed sessions and import batches. */
final class Admin {
	/**
	 * Seconds without a heartbeat before a batch lock is treated as abandoned and reclaimed.
	 *
	 * The holder refreshes the lock after every record, so the only silence a live batch can produce is one
	 * record long: its network share of the batch budget plus local image processing. This window covers that
	 * with room to spare, and it is what bounds the wait after a request killed by a signal (which cannot run
	 * the shutdown handler that releases the lock). Keep it comfortably above BATCH_SECONDS.
	 */
	const LOCK_TTL = 120;

	/** Records imported per batch unless the site saves another size. */
	const BATCH_RECORDS = 5;

	/**
	 * Ceiling for the saved batch size. Memory is what this bounds: one record's post, meta, terms and downloaded
	 * image all live in the same request, so a shared host can ask for fewer records per request instead. The large
	 * end is for a long feed: a thousand records at 100 per batch is ten requests instead of two hundred, and
	 * BATCH_SECONDS still ends a batch that would run long.
	 */
	const BATCH_MAX = 100;

	/** Option holding this site's batch size. Not autoloaded: only an import request reads it. */
	const BATCH_OPTION = 'iex_batch_records';

	/** Wall-clock seconds of record work that ends a batch early. */
	const BATCH_SECONDS = 8;

	/** Seconds held back from the batch budget so the record in flight can finish and the response can go out. */
	const REQUEST_RESERVE = 5;

	/** The loop will not start a record with less than this much budget left, so no record starts pre-doomed. */
	const RECORD_FLOOR_SECONDS = 2;

	/** Import jobs remembered per user, newest first. The oldest are dropped past this many. */
	const JOBS_MAX = 20;

	/** How long a remembered job stays in the history, in seconds (seven days). */
	const JOB_TTL = 604800;

	/** Journal rows stored per remembered job, so the history cannot grow without bound. */
	const JOB_JOURNAL = 10;

	/**
	 * Memory a feed's private copy needs, as a multiple of the feed's own size.
	 *
	 * `pack()` streams both halves, so this is not the 2.4x the pre-streaming form needed; the PACK_MODE arm now
	 * measures a 21.5 MB incompressible feed peaking at 24 MiB (1.1x). The rest is headroom for the steps that hold
	 * the feed whole again — discovery parses it, and a batch walks it — which is why 1.7x stays the bar rather than
	 * the measurement. The stored copy's own writes are charged separately, at `STORE_FACTOR`.
	 */
	const MEMORY_FACTOR = 1.7;

	/**
	 * Copies of the stored private feed a write to an option needs, as a multiple of its own length.
	 *
	 * A write is not one copy: WordPress serializes the session array, escapes that string for SQL and builds the
	 * statement around it, and `prepare()` grows its buffers while doing so — four copies under the one already
	 * held, which is the headroom that keeps the last one from being the allocation that fataled. `MEMORY_FACTOR`
	 * cannot see any of this: it charges the feed, not the copy, so a 20 MiB feed that gzipped to nothing passed it
	 * and then exhausted a 64 MB limit inside `set_transient()`. The same bar is applied before the base64 copy is
	 * built, so a copy the session could never be written is never built either.
	 */
	const STORE_FACTOR = 4.0;

	/**
	 * Write a diagnostic line when WP_DEBUG is on; control characters are stripped so untrusted feed text
	 * cannot forge extra log entries.
	 *
	 * @param string $message Diagnostic text.
	 */
	public static function log( string $message ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}
		error_log( 'Import Everything!: ' . preg_replace( '/[\x00-\x1f\x7f]+/', ' ', $message ) );
	}

	/**
	 * Read a request field that must be a string, refusing a wrong type before it reaches a typed
	 * parameter or a string function.
	 *
	 * @param array  $input    Request payload.
	 * @param string $key      Field name.
	 * @param string $fallback Value used when the field is absent.
	 * @throws \RuntimeException When the field is present but not a string.
	 */
	private static function text( array $input, string $key, string $fallback = '' ): string {
		if ( ! isset( $input[ $key ] ) ) {
			return $fallback;
		}
		if ( ! is_string( $input[ $key ] ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid request payload.', 'import-everything' ) );
		}
		return $input[ $key ];
	}

	/**
	 * Read a request field that must be an array.
	 *
	 * @param array  $input    Request payload.
	 * @param string $key      Field name.
	 * @param array  $fallback Value used when the field is absent.
	 * @throws \RuntimeException When the field is present but not an array.
	 */
	private static function array_field( array $input, string $key, array $fallback = array() ): array {
		if ( ! isset( $input[ $key ] ) ) {
			return $fallback;
		}
		if ( ! is_array( $input[ $key ] ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid request payload.', 'import-everything' ) );
		}
		return $input[ $key ];
	}

	/** Register hooks. */
	public function register(): void {
		// A release that changes stored data upgrades it here, before any hook can read a session or saved feed.
		Upgrade::maybe_upgrade();
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'redirect_legacy' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'wp_ajax_iex_request', array( $this, 'request' ) );
		add_action( 'iex_run_feed', array( $this, 'scheduled' ), 10, 2 );
		add_action( 'iex_continue_feed', array( $this, 'continued' ), 10, 3 );
	}

	/**
	 * Top-level admin menu entry: importing a feed is a job of its own, so it gets its own menu rather than a
	 * page buried under Tools.
	 */
	public function menu(): void {
		add_menu_page(
			__( 'Import Everything!', 'import-everything' ),
			__( 'Import Everything!', 'import-everything' ),
			'manage_options',
			'import-everything',
			array( $this, 'page' ),
			'dashicons-rss',
			58
		);
		// The reference page sits beside the importer in the sidebar, so "what does this field do?" is one click
		// away instead of a support request. It is server-rendered markup and reads nothing from a session.
		add_submenu_page(
			'import-everything',
			__( 'Import Everything! documentation', 'import-everything' ),
			__( 'Documentation', 'import-everything' ),
			'manage_options',
			Docs::SLUG,
			array( Docs::class, 'page' )
		);
	}

	/**
	 * The importer used to live under Tools. A bookmark or an old link to that page follows the menu instead of
	 * dead-ending on WordPress's "not allowed" screen; the new page keeps the same slug and query string.
	 */
	public function redirect_legacy(): void {
		// 1.3.0 renamed the page slug, so a bookmark to the old one follows the rename instead of dead-ending.
		if ( 'admin.php' === ( $GLOBALS['pagenow'] ?? '' ) && 'ultimate-xml' === ( $_GET['page'] ?? '' ) ) {
			if ( current_user_can( 'manage_options' ) ) {
				wp_safe_redirect( admin_url( 'admin.php?page=import-everything' ) );
				exit;
			}
			return;
		}
		if ( 'tools.php' !== ( $GLOBALS['pagenow'] ?? '' ) || 'import-everything' !== ( $_GET['page'] ?? '' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_safe_redirect( admin_url( 'admin.php?page=import-everything' ) );
		exit;
	}

	/**
	 * Enqueue on this plugin's two screens: the importer itself and its documentation page.
	 *
	 * The documentation page is static markup in the same workspace shell, so it takes the stylesheet and stops
	 * there — there is no AJAX client on a page that never imports anything.
	 *
	 * @param string $hook Current admin screen hook.
	 */
	public function assets( string $hook ): void {
		$editor = 'toplevel_page_import-everything' === $hook;
		if ( ! $editor && 'import-everything_page_' . Docs::SLUG !== $hook ) {
			return;
		}
		$url = plugin_dir_url( IMPORT_EVERYTHING_FILE );
		$dir = plugin_dir_path( IMPORT_EVERYTHING_FILE );
		// The asset version is the file's own mtime, so a shipped CSS or JS change never hides behind a cached URL.
		wp_enqueue_style( 'iex-admin', $url . 'assets/admin.css', array(), (string) max( 1, (int) @filemtime( $dir . 'assets/admin.css' ) ) );
		if ( ! $editor ) {
			return;
		}
		wp_enqueue_script( 'iex-admin', $url . 'assets/admin.js', array( 'wp-i18n' ), (string) max( 1, (int) @filemtime( $dir . 'assets/admin.js' ) ), true );
		$types = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $type ) {
			if ( current_user_can( $type->cap->create_posts ) ) {
				$types[] = array(
					'value' => $type->name,
					'label' => $type->labels->singular_name,
				);
			}
		}
		if ( ! in_array( 'attachment', array_column( $types, 'value' ), true ) && current_user_can( 'upload_files' ) ) {
			$types[] = array(
				'value' => 'attachment',
				'label' => __( 'Attachment (image)', 'import-everything' ),
			);
		}
		// Which term mappings each post type can store. The mapping step runs before the post type is chosen, so
		// the client needs this to warn about a pair the server would refuse at preview.
		$taxonomies = array();
		foreach ( $types as $entry ) {
			$taxonomies[ $entry['value'] ] = array_keys(
				array_filter(
					Importer::TAXONOMIES,
					static fn( $taxonomy ) => is_object_in_taxonomy( $entry['value'], $taxonomy )
				)
			);
		}
		wp_localize_script(
			'iex-admin',
			'ImportEverything',
			array(
				'ajax'        => admin_url( 'admin-ajax.php' ),
				'nonce'       => wp_create_nonce( 'iex_request' ),
				'types'       => $types,
				'taxonomies'  => $taxonomies,
				'batch'       => self::batch_size_state(),
				'uploadLimit' => min( XML::MAX_BYTES, wp_max_upload_size() ),
				'version'     => Upgrade::version(),
			)
		);
		wp_set_script_translations( 'iex-admin', 'import-everything', plugin_dir_path( IMPORT_EVERYTHING_FILE ) . 'languages' );
	}

	/** Render accessible mount; JavaScript never injects XML as HTML. */
	public function page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<div class="wrap" id="iex-app"><p>' . esc_html__( 'Loading Import Everything!…', 'import-everything' ) . '</p></div>';
		echo '<noscript><p>' . esc_html__( 'Enable JavaScript to use the visual importer.', 'import-everything' ) . '</p></noscript>';
	}

	/**
	 * Scope opaque session IDs to the signed-in administrator.
	 *
	 * @param string $id     Session UUID.
	 * @param string $prefix Storage-key family prefix.
	 * @throws \RuntimeException When the ID is invalid.
	 */
	public function key( string $id, string $prefix = 'iex_' ): string {
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $id ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid import session.', 'import-everything' ) );
		}
		return $prefix . get_current_user_id() . '_' . $id;
	}

	/**
	 * Read an unexpired private session.
	 *
	 * @param string $id Session UUID.
	 * @throws \RuntimeException When the session has expired.
	 */
	public function session( string $id ): array {
		$session = get_transient( $this->key( $id ) );
		if ( ! is_array( $session ) ) {
			throw new \RuntimeException( esc_html__( 'Session expired. Reload your feed. Imports already created are preserved.', 'import-everything' ) );
		}
		return $session;
	}

	/**
	 * Which parser a feed needs, from its first non-whitespace byte. A UTF-8 BOM is ignored, and the content
	 * decides: a `.json` URL that serves XML is still read as XML, so the extension is only a hint.
	 *
	 * @param string $file Local feed file.
	 * @throws \RuntimeException When the file cannot be read.
	 */
	public static function format( string $file ): string {
		$handle = fopen( $file, 'rb' );
		if ( ! $handle ) {
			throw new \RuntimeException( esc_html__( 'Cannot read the uploaded feed.', 'import-everything' ) );
		}
		$head = (string) fread( $handle, 8192 );
		fclose( $handle );
		if ( str_starts_with( $head, "\xEF\xBB\xBF" ) ) {
			$head = substr( $head, 3 );
		}
		// A leading blank line is not the header, so drop whitespace before deciding anything.
		$head = ltrim( $head, " \t\r\n" );
		if ( str_starts_with( $head, "\xEF\xBB\xBF" ) ) {
			$head = ltrim( substr( $head, 3 ), " \t\r\n" );
		}
		// A sign-in page or a challenge is HTML, and handing it to the XML reader only produces a parser error
		// that blames the feed. Answer with what actually arrived instead.
		$lower = strtolower( substr( $head, 0, 512 ) );
		if ( str_starts_with( $lower, '<!doctype html' ) || str_starts_with( $lower, '<html' ) ) {
			throw new \RuntimeException( esc_html__( 'A web page was returned instead of a feed. Use the feed export link itself — a page that needs a sign-in or is blocked answers as HTML.', 'import-everything' ) );
		}
		if ( '' !== $head && ( '{' === $head[0] || '[' === $head[0] ) ) {
			return 'json';
		}
		if ( '' === $head || '<' === $head[0] ) {
			return 'xml';
		}
		// One separator anywhere in the first line is all a table needs to be recognized as one.
		return preg_match( '/[,;\t|]/', (string) strtok( $head, "\r\n" ) ) ? 'csv' : 'xml';
	}

	/**
	 * Discover a feed's record paths and fields with the parser its format needs.
	 *
	 * @param string $format json, csv or xml.
	 * @param string $file   Local feed file.
	 * @param string $path   Optional explicit record path.
	 * @throws \RuntimeException When the feed has no supported records.
	 */
	public static function discover( string $format, string $file, string $path = '' ): array {
		return match ( $format ) {
			'json'  => JSON::analyze( $file, $path ),
			'csv'   => CSV::analyze( $file ),
			default => XML::analyze( $file, $path ),
		};
	}

	/**
	 * Refuse a feed this server cannot hold, before a session or spool is written.
	 *
	 * Called where the feed is about to sit in memory whole: before `pack()` in `source` and `open_feed()`, and at
	 * `begin()`, where the spool copy is decompressed at once. The size comes from the feed the request just read, so
	 * an admin with a 21 MB file hears what to do instead of watching PHP fatal and finding a half-built session
	 * behind it. It is called a second time for the stored copy, at `STORE_FACTOR`, wherever that copy is about to
	 * be written to an option: the same message covers both, because both are about the private copy.
	 *
	 * A zero size (a session written before `bytes` existed) and a memory_limit of -1 or none are never refused.
	 *
	 * @param int   $bytes  Size to charge for: feed bytes, or the stored copy's length.
	 * @param float $factor Copies of that size the operation needs.
	 * @throws \RuntimeException When the estimated peak exceeds what is left under memory_limit.
	 */
	public static function memory_for_feed( int $bytes, float $factor = self::MEMORY_FACTOR ): void {
		if ( $bytes < 1 ) {
			return;
		}
		$limit = self::bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit < 1 ) {
			return;
		}
		$needed = (int) ceil( $bytes * $factor );
		$used   = memory_get_usage( true );
		$left   = $limit - $used;
		if ( $needed <= $left ) {
			return;
		}
		$mb = static fn( int $value ): int => (int) ceil( $value / 1048576 );
		throw new \RuntimeException(
			sprintf(
				/* translators: 1: memory the private feed copy needs in MB, 2: memory left under memory_limit in MB, 3: memory_limit to set in MB. */
				esc_html__( 'This feed is too large for this server\'s memory limit: its private copy needs about %1$d MB and only %2$d MB is left. Import it from a URL, split it into smaller files, or raise PHP\'s memory_limit to at least %3$d MB.', 'import-everything' ),
				$mb( $needed ),
				max( 0, $mb( $left ) ),
				$mb( $needed + $used )
			)
		);
	}

	/**
	 * Compress a feed into the private session payload in fixed chunks. Holding the raw XML, its
	 * gzip output and the base64 copy at once needs roughly 2.4x the file size, which exceeds a common
	 * 40 MB memory_limit; streaming both halves keeps the peak to about the stored copy itself.
	 *
	 * @param string $file Local XML file.
	 * @throws \RuntimeException When the feed cannot be read or compressed.
	 */
	public static function pack( string $file ): string {
		$temp   = wp_tempnam( 'import-everything-gz' );
		$source = fopen( $file, 'rb' );
		$gzip   = gzopen( $temp, 'wb6' );
		if ( ! $source || ! $gzip ) {
			if ( $source ) {
				fclose( $source );
			}
			if ( $gzip ) {
				gzclose( $gzip );
			}
			wp_delete_file( $temp );
			throw new \RuntimeException( esc_html__( 'Unable to compress the feed for this session.', 'import-everything' ) );
		}
		$failed = false;
		while ( ! feof( $source ) ) {
			$chunk = fread( $source, 1048576 );
			if ( false === $chunk || ( '' !== $chunk && false === gzwrite( $gzip, $chunk ) ) ) {
				$failed = true;
				break;
			}
		}
		fclose( $source );
		gzclose( $gzip );
		if ( ! $failed ) {
			// The stored copy is base64, a third larger than the gzip, and it has to exist whole because it is the
			// value the session carries. Charging it here refuses a feed this server could never hold while the
			// encoding buffers are still unallocated — the allocation that killed the request when the copy was
			// built by growing one string, and the one a whole-file base64_encode() held beside the gzip.
			$size = 4 * (int) ceil( (int) filesize( $temp ) / 3 );
			try {
				self::memory_for_feed( $size, 1.0 );
			} catch ( \RuntimeException $error ) {
				wp_delete_file( $temp );
				throw $error;
			}
		}
		$packed = $failed ? false : fopen( $temp, 'rb' );
		$encoded = $failed ? '' : wp_tempnam( 'import-everything-b64' );
		$base64  = $failed ? false : fopen( $encoded, 'wb' );
		if ( false === $packed || false === $base64 ) {
			if ( $packed ) {
				fclose( $packed );
			}
			if ( $base64 ) {
				fclose( $base64 );
			}
			wp_delete_file( $temp );
			wp_delete_file( $encoded );
			throw new \RuntimeException( esc_html__( 'Unable to compress the feed for this session.', 'import-everything' ) );
		}
		// Chunks are cut on 3-byte boundaries, so the written bytes are the single base64 string the format
		// expects, padding and all. Encoding into a file rather than appending to a string keeps the peak at the
		// copy itself: appending makes PHP allocate the grown string beside the old one, and at the limit that
		// second copy was the allocation that fataled.
		$pending = '';
		while ( ! feof( $packed ) ) {
			$chunk = fread( $packed, 786432 );
			if ( false === $chunk ) {
				$failed = true;
				break;
			}
			$pending .= $chunk;
			$take = strlen( $pending ) - ( strlen( $pending ) % 3 );
			if ( $take > 0 ) {
				if ( false === fwrite( $base64, base64_encode( substr( $pending, 0, $take ) ) ) ) {
					$failed = true;
					break;
				}
				$pending = substr( $pending, $take );
			}
		}
		if ( ! $failed && '' !== $pending && false === fwrite( $base64, base64_encode( $pending ) ) ) {
			$failed = true;
		}
		fclose( $packed );
		fclose( $base64 );
		wp_delete_file( $temp );
		$blob = $failed ? false : file_get_contents( $encoded );
		wp_delete_file( $encoded );
		if ( false === $blob ) {
			throw new \RuntimeException( esc_html__( 'Unable to compress the feed for this session.', 'import-everything' ) );
		}
		return $blob;
	}

	/**
	 * Materialize the compressed feed privately for the duration of one request.
	 *
	 * The compressed feed lives inside the session until a job starts; `start()` then moves it beside the
	 * session so the journal write after every record no longer rewrites several megabytes. Both locations are
	 * accepted, so sessions written by an older release still restore.
	 *
	 * @param array  $session Private compressed session data.
	 * @param string $id      Session UUID.
	 * @throws \RuntimeException When restoring the feed fails.
	 */
	public function file( array $session, string $id ): string {
		$blob = (string) ( $session['xml'] ?? '' );
		if ( '' === $blob ) {
			$blob = (string) get_transient( $this->key( $id, 'iex_feed_' ) );
		}
		if ( '' === $blob ) {
			throw new \RuntimeException( esc_html__( 'The private feed for this session has expired. Load the feed again to continue.', 'import-everything' ) );
		}
		$file = wp_tempnam( 'import-everything' );
		$feed = gzdecode( base64_decode( $blob, true ), XML::MAX_BYTES );
		if ( false === $feed || false === file_put_contents( $file, $feed ) ) {
			throw new \RuntimeException( esc_html__( 'Unable to restore the private feed for this session.', 'import-everything' ) );
		}
		return $file;
	}

	/**
	 * Copy the records of a job that is starting into a spool, so later batches read record k directly instead
	 * of re-parsing the feed from the top.
	 *
	 * This is best effort: a failure is logged and the import continues on the re-parsing path, because losing
	 * the speed-up is better than refusing to start.
	 *
	 * @param array  $session Private session data.
	 * @param string $id      Session UUID.
	 * @return string Spool filename, or an empty string when no spool could be built.
	 */
	private function spool( array $session, string $id ): string {
		$spool = '';
		try {
			$file = $this->file( $session, $id );
			try {
				$spool = wp_tempnam( 'import-everything-spool' );
				match ( (string) ( $session['format'] ?? 'xml' ) ) {
					'json'  => JSON::spool( $file, $session['schema']['path'], $spool ),
					'csv'   => CSV::spool( $file, $spool ),
					default => XML::spool( $file, $session['schema']['path'], $spool ),
				};
			} finally {
				if ( is_file( $file ) ) {
					wp_delete_file( $file );
				}
			}
		} catch ( \Throwable $error ) {
			self::log( 'spool unavailable: ' . $error->getMessage() );
			if ( '' !== $spool && is_file( $spool ) ) {
				wp_delete_file( $spool );
			}
			return '';
		}
		return $spool;
	}

	/**
	 * Delete the private feed copies a session owns: its record spool file and its compressed feed blob.
	 *
	 * @param array  $session Private session data.
	 * @param string $id      Session UUID.
	 */
	private function purge( array $session, string $id ): void {
		if ( ! empty( $session['spool'] ) && is_file( $session['spool'] ) ) {
			wp_delete_file( $session['spool'] );
		}
		delete_transient( $this->key( $id, 'iex_feed_' ) );
	}

	/**
	 * Forget a finished job's feed copies, so they stop occupying disk and database space.
	 *
	 * @param array  $session Private session data.
	 * @param string $id      Session UUID.
	 */
	private function release( array &$session, string $id ): void {
		$this->purge( $session, $id );
		$session['spool'] = '';
		$session['xml']   = '';
		set_transient( $this->key( $id ), $session, DAY_IN_SECONDS );
	}

	/**
	 * Option name holding this user's remembered import jobs.
	 *
	 * Per user, not per site: an entry names a session whose key is scoped to the signed-in administrator, so a
	 * second administrator can neither see the job nor resume it.
	 */
	private static function jobs_key(): string {
		return 'iex_jobs_' . get_current_user_id();
	}

	/**
	 * One durable record of a job: names, a settings summary, the counters and a bounded journal.
	 *
	 * The feed contents, the record spool path and the session key are deliberately absent — the same rule the
	 * support report follows. This option is a small printable index the admin can read, not a copy of the job,
	 * and it never holds a server path or a schema sample value.
	 *
	 * @param array  $session Private session data.
	 * @param string $id      Session UUID.
	 * @param string $state   running, completed, or cleared.
	 */
	private static function entry( array $session, string $id, string $state ): array {
		$job      = is_array( $session['job'] ?? null ) ? $session['job'] : array();
		$settings = is_array( $job['settings'] ?? null ) ? $job['settings'] : array();
		return array(
			'id'         => $id,
			'name'       => (string) ( $session['name'] ?? '' ),
			'path'       => (string) ( $session['schema']['path'] ?? '' ),
			'type'       => (string) ( $settings['type'] ?? '' ),
			'status'     => (string) ( $settings['status'] ?? '' ),
			'identity'   => (string) ( $settings['identity'] ?? '' ),
			'duplicates' => (string) ( $settings['duplicates'] ?? '' ),
			'total'      => (int) ( $job['total'] ?? 0 ),
			'offset'     => (int) ( $job['offset'] ?? 0 ),
			'created'    => (int) ( $job['created'] ?? 0 ),
			'updated'    => (int) ( $job['updated'] ?? 0 ),
			'skipped'    => (int) ( $job['skipped'] ?? 0 ),
			'failed'     => (int) ( $job['failed'] ?? 0 ),
			'warnings'   => (int) ( $job['warnings'] ?? 0 ),
			'state'      => $state,
			'started'    => time(),
			'checked'    => time(),
			'journal'    => array_slice( is_array( $job['log'] ?? null ) ? $job['log'] : array(), -self::JOB_JOURNAL ),
		);
	}

	/**
	 * Drop entries past their retention window, then keep the most recently touched MAX.
	 *
	 * @param array $entries Job entries keyed by session UUID.
	 */
	private static function bounded( array $entries ): array {
		$cutoff = time() - self::JOB_TTL;
		foreach ( $entries as $id => $entry ) {
			if ( (int) ( $entry['checked'] ?? 0 ) < $cutoff ) {
				unset( $entries[ $id ] );
			}
		}
		// Reversed before and after the sort so that, when two entries share a timestamp, the one written later
		// is the one that survives the cap: PHP's sort is stable, so ties would otherwise favour the oldest.
		$entries = array_reverse( $entries, true );
		uasort( $entries, static fn( $a, $b ) => (int) ( $b['checked'] ?? 0 ) <=> (int) ( $a['checked'] ?? 0 ) );
		return array_reverse( array_slice( $entries, 0, self::JOBS_MAX, true ), true );
	}

	/**
	 * Store or update this run in the history, keeping the first-seen start time.
	 *
	 * Called once per batch rather than once per record: the session already checkpoints every record, and a
	 * second option write per record would double the cost of an import for a list nobody reads mid-batch.
	 *
	 * @param array  $session Private session data.
	 * @param string $id      Session UUID.
	 * @param string $state   running, completed, or cleared.
	 */
	private static function remember( array $session, string $id, string $state ): void {
		$key     = self::jobs_key();
		$stored  = get_option( $key, array() );
		$entries = is_array( $stored ) && is_array( $stored['jobs'] ?? null ) ? $stored['jobs'] : array();
		$entry   = self::entry( $session, $id, $state );
		if ( isset( $entries[ $id ]['started'] ) ) {
			$entry['started'] = (int) $entries[ $id ]['started'];
		}
		$entries[ $id ] = $entry;
		update_option( $key, array( 'version' => 1, 'jobs' => self::bounded( $entries ) ), false );
	}

	/**
	 * The remembered jobs of this user, newest first, each annotated with what a resume would need.
	 *
	 * The live session wins over the stored copy, because it is the state the next batch actually reads: a job
	 * whose session is gone is reported as expired instead of offering a resume button that cannot work, and a
	 * record left half-written by a killed request is flagged so the admin knows resuming will reconcile it.
	 */
	private static function history(): array {
		$user   = get_current_user_id();
		$stored = get_option( self::jobs_key(), array() );
		$jobs   = array();
		if ( ! is_array( $stored ) || ! is_array( $stored['jobs'] ?? null ) ) {
			return $jobs;
		}
		foreach ( self::bounded( $stored['jobs'] ) as $id => $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$entry['in_flight'] = 0;
			$entry['resumable'] = false;
			$session           = 'running' === ( $entry['state'] ?? '' ) ? get_transient( 'iex_' . $user . '_' . $id ) : false;
			if ( is_array( $session ) && is_array( $session['job'] ?? null ) ) {
				$live               = self::entry( $session, (string) $id, 'running' );
				$live['journal']    = array_slice( $live['journal'], -self::JOB_JOURNAL );
				$live['started']    = (int) ( $entry['started'] ?? $live['started'] );
				$live['checked']    = (int) ( $entry['checked'] ?? $live['checked'] );
				$live['state']      = $live['offset'] >= $live['total'] ? 'completed' : ( 'cleared' === ( $entry['state'] ?? '' ) ? 'cleared' : 'running' );
				$live['in_flight']  = (int) ( $session['job']['in_flight'] ?? 0 );
				$live['resumable']  = 'running' === $live['state'] && self::readable( $session, (string) $id );
				$entry              = $live;
			} elseif ( 'running' === ( $entry['state'] ?? '' ) ) {
				$entry['state'] = 'expired';
			}
			$entry['left'] = max( 0, (int) $entry['total'] - (int) $entry['offset'] );
			$jobs[]        = $entry;
		}
		return $jobs;
	}

	/**
	 * Whether a session still has something to read records from: its spool, or its compressed feed.
	 *
	 * @param array  $session Private session data.
	 * @param string $id      Session UUID.
	 */
	private static function readable( array $session, string $id ): bool {
		$spool = (string) ( $session['spool'] ?? '' );
		if ( '' !== $spool && is_file( $spool ) ) {
			return true;
		}
		if ( '' !== (string) ( $session['xml'] ?? '' ) ) {
			return true;
		}
		return false !== get_transient( 'iex_feed_' . get_current_user_id() . '_' . $id );
	}

	/**
	 * Best-effort cleanup of spools left behind by abandoned sessions. The session TTL bounds how long a paused
	 * import stays resumable, so a spool older than that plus a margin can never be reached again.
	 */
	private static function sweep(): void {
		$cutoff = time() - DAY_IN_SECONDS - HOUR_IN_SECONDS;
		$temp   = get_temp_dir();
		// A directory scan, not glob(): on Windows a glob pattern built from a backslash path
		// (`C:\Windows\TEMP/...`) matches nothing, which silently left every stale spool behind.
		if ( ! is_readable( $temp ) ) {
			return;
		}
		$files = scandir( $temp );
		if ( false === $files ) {
			return;
		}
		foreach ( $files as $name ) {
			if ( 0 !== strpos( $name, 'import-everything-spool' ) ) {
				continue;
			}
			$stale = $temp . $name;
			if ( is_file( $stale ) && filemtime( $stale ) < $cutoff ) {
				wp_delete_file( $stale );
			}
		}
	}

	/**
	 * Start a job on a session: freeze the settings, copy the records to a spool, and journal the run.
	 *
	 * @param array  $session  Private session data.
	 * @param string $id       Session UUID.
	 * @param array  $settings Validated import settings.
	 * @throws \RuntimeException When the session already has a job.
	 */
	public function begin( array $session, string $id, array $settings ): array {
		if ( isset( $session['job'] ) ) {
			throw new \RuntimeException( esc_html__( 'This session already has an import job.', 'import-everything' ) );
		}
		// The spool copy is decompressed into memory, so a feed that cannot fit memory_limit stops here rather
		// than in the middle of the spool: nothing has been written yet, so the admin can act on the message.
		self::memory_for_feed( (int) ( $session['bytes'] ?? 0 ) );
		// The session is itself an option write and carries the compressed feed until a spool moves it out, so the
		// write's own copies have to fit too — the check that was missing when a 64 MB site died in set_transient().
		self::memory_for_feed( strlen( (string) ( $session['xml'] ?? '' ) ), self::STORE_FACTOR );
		$session['job'] = array(
			'settings'  => $settings,
			'offset'    => 0,
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'warnings'  => 0,
			'log'       => array(),
			'total'     => $session['schema']['total'],
			// Record number currently being written; non-zero only while one is in flight.
			'in_flight' => 0,
		);
		// Copy the records once, then keep the compressed feed beside the session rather than inside it, so the
		// journal write after every record stays small instead of rewriting the feed.
		$session['spool'] = $this->spool( $session, $id );
		if ( '' !== $session['spool'] ) {
			set_transient( $this->key( $id, 'iex_feed_' ), $session['xml'], DAY_IN_SECONDS );
			$session['xml'] = '';
		}
		set_transient( $this->key( $id ), $session, DAY_IN_SECONDS );
		self::remember( $session, $id, 'running' );
		return $session['job'];
	}

	/**
	 * Process only nonce-verified, privileged requests; temporary files always removed.
	 *
	 * @throws \RuntimeException Invalid requests are caught and returned as JSON errors.
	 */
	public function request(): void {
		if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'import' ) ) {
			wp_send_json_error( array( 'message' => __( 'Administrator import permission is required.', 'import-everything' ) ), 403 );
		}
		check_ajax_referer( 'iex_request', 'nonce' );
		$file      = '';
		$operation = '';
		try {
			$raw_operation = $_POST['operation'] ?? '';
			$raw_payload   = $_POST['payload'] ?? '{}';
			if ( ! is_string( $raw_operation ) || ! is_string( $raw_payload ) ) {
				throw new \RuntimeException( esc_html__( 'Invalid request payload.', 'import-everything' ) );
			}
			$operation = sanitize_key( wp_unslash( $raw_operation ) );
			$input     = json_decode( wp_unslash( $raw_payload ), true );
			if ( ! is_array( $input ) ) {
				throw new \RuntimeException( esc_html__( 'Invalid request payload.', 'import-everything' ) );
			}
			if ( 'source' === $operation ) {
				self::sweep();
				$origin = '';
				if ( ! empty( $_FILES['xml'] ) ) {
					$upload = $_FILES['xml'];
					// `xml[]` arrives as parallel arrays. Refuse it before pathinfo() or is_uploaded_file() is handed
					// an array, which would print a warning ahead of the JSON response.
					if (
						! is_array( $upload )
						|| ! isset( $upload['name'], $upload['tmp_name'], $upload['error'], $upload['size'] )
						|| ! is_string( $upload['name'] )
						|| ! is_string( $upload['tmp_name'] )
						|| ! is_int( $upload['error'] )
						|| ! is_int( $upload['size'] )
					) {
						throw new \RuntimeException( esc_html__( 'Upload one file at a time. Use a single .xml, .json, .csv or .tsv file.', 'import-everything' ) );
					}
					$extension = strtolower( pathinfo( (string) $upload['name'], PATHINFO_EXTENSION ) );
					if ( UPLOAD_ERR_OK !== $upload['error'] || ! is_uploaded_file( $upload['tmp_name'] ) || ! in_array( $extension, array( 'xml', 'json', 'csv', 'tsv' ), true ) || $upload['size'] > XML::MAX_BYTES ) {
						throw new \RuntimeException( esc_html__( 'Upload a valid .xml, .json, .csv or .tsv file of at most 20 MiB (also subject to your server upload limit).', 'import-everything' ) );
					}
					$file = $upload['tmp_name'];
					$name = sanitize_file_name( $upload['name'] );
				} elseif ( ! empty( $input['example'] ) ) {
					$examples = array( 'video' => 'video.xml', 'gallery' => 'gallery.xml', 'json' => 'catalog.json', 'positions' => 'positions.json', 'csv' => 'catalog.csv' );
					$choice   = is_string( $input['example'] ) ? $input['example'] : '';
					$example  = $examples[ $choice ] ?? 'gallery.xml';
					$file     = wp_tempnam( 'import-everything' );
					copy( plugin_dir_path( IMPORT_EVERYTHING_FILE ) . 'examples/' . $example, $file );
					$name = $example;
				} else {
					$url   = esc_url_raw( self::text( $input, 'url' ) );
					$fetch = Feeds::feed_url( $url );
					if ( ! Importer::public_url( $fetch ) ) {
						throw new \RuntimeException( esc_html__( 'Enter a public HTTP(S) XML, JSON, CSV or Google Sheets URL. Private addresses are blocked.', 'import-everything' ) );
					}
					$file = wp_tempnam( 'import-everything' );
					Importer::download( $fetch, $file, XML::MAX_BYTES, 30 );
					$origin = $url;
					$name = sanitize_text_field( wp_parse_url( $url, PHP_URL_HOST ) ) . ' feed';
				}
				$bytes = (int) filesize( $file );
				self::memory_for_feed( $bytes );
				$format  = self::format( $file );
				$schema  = self::discover( $format, $file );
				$id      = wp_generate_uuid4();
				$blob    = self::pack( $file );
				// The packed size is what the session carries, and storing it needs copies of itself: an incompressible
				// feed is far larger here than the feed-size estimate above could know it would be.
				self::memory_for_feed( strlen( $blob ), self::STORE_FACTOR );
				$session = array(
					'format' => $format,
					'url'    => $origin,
					'xml'    => $blob,
					'name'   => $name,
					'schema' => $schema,
					'bytes'  => $bytes,
				);
				if ( ! set_transient( $this->key( $id ), $session, DAY_IN_SECONDS ) ) {
					throw new \RuntimeException( esc_html__( 'Unable to store the private feed session. The compressed feed is probably larger than the database allows, so split it into smaller files.', 'import-everything' ) );
				}
				$result = array(
					'id'     => $id,
					'format' => $format,
					'url'    => $origin,
					'name'   => $name,
					'schema' => $schema,
				);
			} elseif ( 'batch_size' === $operation ) {
				if ( isset( $input['size'] ) ) {
					$size = $input['size'];
					if ( ! is_int( $size ) || $size < 1 || $size > self::BATCH_MAX ) {
						throw new \RuntimeException( sprintf( esc_html__( 'Choose between 1 and %d records per batch.', 'import-everything' ), self::BATCH_MAX ) );
					}
					update_option( self::BATCH_OPTION, $size, false );
				}
				$result = self::batch_size_state();
			} elseif ( 'feeds' === $operation ) {
				$result = array( 'feeds' => Feeds::feeds() );
			} elseif ( 'feed_delete' === $operation ) {
				$result = array( 'feeds' => Feeds::delete_feed( self::text( $input, 'feed' ) ) );
			} elseif ( 'feed_run' === $operation ) {
				$feed = Feeds::find_feed( self::text( $input, 'feed' ) );
				try {
					$result = ( new Feeds( $this ) )->launch( $feed );
				} catch ( \RuntimeException $error ) {
					// A run that never started still leaves a result the Saved feeds list can show; the scheduled
					// path records its own failure the same way.
					Feeds::finish( (string) $feed['id'], 'failed', array(), $error->getMessage() );
					throw $error;
				}
			} elseif ( 'feed_open' === $operation ) {
				$feed   = Feeds::find_feed( self::text( $input, 'feed' ) );
				$opened = ( new Feeds( $this ) )->open_feed( $feed );
				$result = array(
					'id'     => $opened['id'],
					'url'    => (string) $feed['url'],
					'name'   => (string) $feed['name'],
					'schema' => $opened['session']['schema'],
				);
			} elseif ( 'feed_update' === $operation ) {
				$feed    = Feeds::find_feed( self::text( $input, 'feed' ) );
				$name    = sanitize_text_field( self::text( $input, 'name', (string) $feed['name'] ) );
				if ( '' === $name || strlen( $name ) > 200 ) {
					throw new \RuntimeException( esc_html__( 'Enter a feed name of 1 to 200 characters.', 'import-everything' ) );
				}
				$changes = array( 'name' => $name, 'updated' => time() );
				$url     = 'upload' === $feed['source'] ? '' : esc_url_raw( self::text( $input, 'url', (string) $feed['url'] ) );
				if ( '' !== $url && $url !== (string) $feed['url'] ) {
					// A new address is a new schema, so the saved mapping is checked against it before the feed points
					// there: a scheduled run must not start failing on a field the new feed does not have.
					Feeds::feed_settings( array_merge( $feed, array( 'url' => $url ) ), Feeds::inspect( $url, $feed ) );
					$changes['url'] = $url;
				}
				Feeds::update_feed( (string) $feed['id'], $changes );
				$feeds  = Feeds::feeds();
				$result = array( 'feeds' => $feeds, 'feed' => array_column( $feeds, null, 'id' )[ (string) $feed['id'] ] ?? array() );
			} elseif ( 'feed_schedule' === $operation ) {
				$feed_id  = self::text( $input, 'feed' );
				Feeds::find_feed( $feed_id );
				$schedule = Feeds::schedule_of( self::text( $input, 'schedule' ) );
				Feeds::reschedule( $feed_id, $schedule );
				Feeds::update_feed( $feed_id, array( 'schedule' => $schedule ) );
				$result = array( 'feeds' => Feeds::feeds() );
			} elseif ( 'feed_posts' === $operation ) {
				$feed   = Feeds::find_feed( self::text( $input, 'feed' ) );
				$result = array( 'posts' => (int) Feeds::feed_query( $feed['id'], 1 )->found_posts );
			} elseif ( 'feed_clear' === $operation ) {
				$feed   = Feeds::find_feed( self::text( $input, 'feed' ) );
				$result = Feeds::clear_posts( $feed['id'] );
			} elseif ( 'jobs' === $operation ) {
				// The admin's own import history: no session id needed, and nothing here leaves this user's scope.
				$result = array(
					'jobs'        => self::history(),
					'max'         => self::JOBS_MAX,
					'kept_days'   => (int) ( self::JOB_TTL / DAY_IN_SECONDS ),
					'resume_hours' => 24,
				);
			} else {
				$id      = self::text( $input, 'id' );
				$session = $this->session( $id );
				if ( 'clear' === $operation ) {
					$this->purge( $session, $id );
					// The run stays in the history as cleared: it is a record of what happened, not a resume path.
					self::remember( $session, $id, 'cleared' );
					delete_transient( $this->key( $id ) );
					$result = array( 'cleared' => true );
				} elseif ( 'analyze' === $operation ) {
					if ( isset( $session['job'] ) ) {
						throw new \RuntimeException( esc_html__( 'Load a new feed to change a started import.', 'import-everything' ) );
					}
					$file              = $this->file( $session, $id );
					$session['schema'] = self::discover( (string) ( $session['format'] ?? 'xml' ), $file, self::text( $input, 'path' ) );
					set_transient( $this->key( $id ), $session, DAY_IN_SECONDS );
					$result = $session['schema'];
				} elseif ( 'preview' === $operation || 'start' === $operation ) {
					$settings = Importer::settings( self::array_field( $input, 'settings' ), $session['schema'] );
					if ( 'preview' === $operation ) {
						$result = array_map( static fn( $record ) => Importer::preview( $record, $settings ), $session['schema']['samples'] );
					} else {
						$result = $this->begin( $session, $id, $settings );
					}
				} elseif ( 'feed_save' === $operation ) {
					$url      = (string) ( $session['url'] ?? '' );
					$feeds    = Feeds::feeds();
					$now      = time();
					$feed_id  = self::text( $input, 'feed' );
					$position = null;
					if ( '' !== $feed_id ) {
						Feeds::find_feed( $feed_id );
						foreach ( $feeds as $index => $saved ) {
							if ( $saved['id'] === $feed_id ) {
								$position = $index;
							}
						}
					}
					$settings = Importer::settings( self::array_field( $input, 'settings' ), $session['schema'] );
					// Updating keeps the name the feed already has unless one is sent, so re-saving a mapping can never
					// silently rename a feed back to its host.
					$fallback = null === $position ? (string) ( $session['name'] ?? '' ) : (string) $feeds[ $position ]['name'];
					$name     = sanitize_text_field( self::text( $input, 'name', $fallback ) );
					if ( '' === $name || strlen( $name ) > 200 ) {
						throw new \RuntimeException( esc_html__( 'Enter a feed name of 1 to 200 characters.', 'import-everything' ) );
					}
					$saved_id = null === $position ? wp_generate_uuid4() : $feeds[ $position ]['id'];
					if ( '' === $url ) {
						// An upload has no address to fetch, so its compressed copy is kept under its own option.
						$blob = (string) ( $session['xml'] ?? '' );
						if ( '' === $blob ) {
							$blob = (string) get_transient( $this->key( $id, 'iex_feed_' ) );
						}
						if ( '' === $blob ) {
							throw new \RuntimeException( esc_html__( 'The private feed for this session has expired. Load the feed again to save it.', 'import-everything' ) );
						}
						// The saved copy is an option of its own, written the same way: refuse before the write, not inside it.
						self::memory_for_feed( strlen( $blob ), self::STORE_FACTOR );
						update_option( Feeds::blob_key( $saved_id ), $blob, false );
					} else {
						delete_option( Feeds::blob_key( $saved_id ) );
					}
					$entry = array(
						'name'     => $name,
						'url'      => $url,
						'source'   => '' === $url ? 'upload' : 'url',
						'path'     => (string) $session['schema']['path'],
						'settings' => $settings,
						'updated'  => $now,
					);
					if ( null === $position ) {
						$feeds[]  = $entry + array( 'id' => $saved_id, 'created' => $now, 'last_run' => 0, 'schedule' => 'off', 'active' => '', 'last_result' => null );
						$position = array_key_last( $feeds );
					} else {
						$feeds[ $position ] = array_merge( $feeds[ $position ], $entry );
					}
					Feeds::store_feeds( $feeds );
					$result = array( 'feeds' => $feeds, 'feed' => $feeds[ $position ] );
				} elseif ( 'support' === $operation ) {
					// Opt-in, and only the literal boolean: a string a client might send for "true" has to fail closed,
					// because the flag adds data to a bundle that leaves the site.
					$result = self::report( $session, true === ( $input['fields'] ?? false ) );
				} elseif ( 'batch' === $operation ) {
					$result = $this->batch( $id, $input );
				} else {
					throw new \RuntimeException( esc_html__( 'Unknown importer operation.', 'import-everything' ) );
				}
			}
		} catch ( \RuntimeException $error ) {
			// Expected failures already carry an authored, user-facing message. A positive code is the busy
			// path's "try again in this many seconds", which lets the client wait instead of giving up.
			if ( $file && is_file( $file ) ) {
				wp_delete_file( $file );
			}
			$failure = array( 'message' => $error->getMessage() );
			if ( $error->getCode() > 0 ) {
				$failure['retry_after'] = $error->getCode();
			}
			wp_send_json_error( $failure, 400 );
		} catch ( \Throwable $error ) {
			// Unexpected: keep the detail in the debug log rather than disclosing internals to the client.
			self::log( 'unexpected ' . get_class( $error ) . ' during "' . $operation . '": ' . $error->getMessage() );
			if ( $file && is_file( $file ) ) {
				wp_delete_file( $file );
			}
			wp_send_json_error( array( 'message' => __( 'The import request failed unexpectedly. Check the site debug log for details.', 'import-everything' ) ), 500 );
		}
		if ( $file && is_file( $file ) ) {
			wp_delete_file( $file );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Build a printable support bundle: plugin and environment limits, the mapped settings, the current job
	 * counters and journal, and this identity's batch-lock state. The feed file and the schema's sample
	 * values are deliberately excluded, so the report is safe to attach to a support request. The admin may
	 * opt in to the feed's field names — the paths alone, which are element and key names, never a value.
	 * The list is reported as `field_names`, not `fields`: the document already has `source.fields`, which is a
	 * count, and one word cannot mean two things in one file an admin hands to support.
	 *
	 * @param array $session Private session data.
	 * @param bool  $fields  Whether to add the feed's field paths (names only).
	 */
	private static function report( array $session, bool $fields = false ): array {
		$schema = is_array( $session['schema'] ?? null ) ? $session['schema'] : array();
		$job    = is_array( $session['job'] ?? null ) ? $session['job'] : null;
		$report = array(
			'format'      => 'import-everything-support',
			'version'     => 1,
			'generated'   => gmdate( 'c' ),
			'source'      => array(
				'format'      => (string) ( $session['format'] ?? 'xml' ),
				'name'        => (string) ( $session['name'] ?? '' ),
				'record_path' => (string) ( $schema['path'] ?? '' ),
				'records'     => (int) ( $schema['total'] ?? 0 ),
				'fields'      => count( $schema['fields'] ?? array() ),
				'candidates'  => count( $schema['candidates'] ?? array() ),
			),
			'job'         => null,
			'limits'      => array(
				'xml_max_bytes'        => XML::MAX_BYTES,
				'xml_max_records'      => XML::MAX_RECORDS,
				'xml_max_fields'       => XML::MAX_FIELDS,
				'xml_max_record_bytes' => XML::MAX_RECORD_BYTES,
				'batch_records'        => self::batch_records(),
				'batch_records_default' => self::BATCH_RECORDS,
				'batch_records_max'    => self::BATCH_MAX,
				'batch_seconds'        => self::BATCH_SECONDS,
				'lock_ttl_seconds'     => self::LOCK_TTL,
				'journal_entries'      => 100,
				'image_max_bytes'      => Importer::MAX_IMAGE_BYTES,
				'image_max_pixels'     => Importer::MAX_IMAGE_PIXELS,
			),
			'environment' => array(
				'plugin'              => Upgrade::version(),
				'wordpress'           => (string) get_bloginfo( 'version' ),
				'php'                 => PHP_VERSION,
				'os'                  => PHP_OS_FAMILY,
				'database'            => self::database(),
				'environment_type'    => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : '',
				'multisite'           => is_multisite(),
				'extensions'          => array(
					'XMLReader' => class_exists( '\XMLReader' ),
					'DOM'       => class_exists( '\DOMDocument' ),
					'mbstring'  => function_exists( 'mb_substr' ),
					'zlib'      => function_exists( 'gzencode' ),
				),
				'memory_limit'        => self::size( 'memory_limit' ),
				'upload_max_filesize' => self::size( 'upload_max_filesize' ),
				'post_max_size'       => self::size( 'post_max_size' ),
				'max_execution_time'  => (int) ini_get( 'max_execution_time' ),
				'wp_debug'            => defined( 'WP_DEBUG' ) && WP_DEBUG,
				'display_errors'      => (bool) filter_var( ini_get( 'display_errors' ), FILTER_VALIDATE_BOOLEAN ),
			),
			'checks'      => array(
				'memory_limit_covers_largest_feed' => self::covers_memory(),
				'upload_limit_covers_largest_feed' => wp_max_upload_size() >= XML::MAX_BYTES,
				'xml_extensions_loaded'            => class_exists( '\XMLReader' ) && class_exists( '\DOMDocument' ) && function_exists( 'mb_substr' ) && function_exists( 'gzencode' ),
				'image_editor_supports_jpeg'       => wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) ),
			),
			'locks'       => array(),
		);
		if ( $fields ) {
			// Built from `path` alone and never from the schema entry, so a sample value has no way in: a name is
			// what a support request needs to say "this feed has no <tags> field", and a value is what must stay.
			$report['field_names'] = array();
			foreach ( is_array( $schema['fields'] ?? null ) ? $schema['fields'] : array() as $field ) {
				if ( is_array( $field ) && isset( $field['path'] ) && is_string( $field['path'] ) ) {
					$report['field_names'][] = $field['path'];
				}
			}
		}
		if ( $job ) {
			$settings          = is_array( $job['settings'] ?? null ) ? $job['settings'] : array();
			$report['job']     = array(
				'mapping'  => $settings['mapping'] ?? array(),
				'custom'   => array_keys( $settings['custom'] ?? array() ),
				'settings' => array(
					'type'       => (string) ( $settings['type'] ?? '' ),
					'status'     => (string) ( $settings['status'] ?? '' ),
					'identity'   => (string) ( $settings['identity'] ?? '' ),
					'duplicates' => (string) ( $settings['duplicates'] ?? '' ),
				),
				'counters' => array(
					'offset'   => (int) ( $job['offset'] ?? 0 ),
					'total'    => (int) ( $job['total'] ?? 0 ),
					'created'  => (int) ( $job['created'] ?? 0 ),
					'updated'  => (int) ( $job['updated'] ?? 0 ),
					'skipped'  => (int) ( $job['skipped'] ?? 0 ),
					'failed'   => (int) ( $job['failed'] ?? 0 ),
					'warnings' => (int) ( $job['warnings'] ?? 0 ),
				),
				'journal'  => is_array( $job['log'] ?? null ) ? $job['log'] : array(),
			);
			$report['locks'][] = self::lock_state( $settings );
		}
		return $report;
	}

	/**
	 * Option name of the per-identity batch lock. Shared by the batch and support-report paths so the lock the
	 * report describes is always the lock that is actually taken, not a second spelling of it.
	 *
	 * @param array $settings Validated import settings.
	 */
	private static function lock_name( array $settings ): string {
		return 'iex_lock_' . hash( 'sha256', ( $settings['identity'] ?? '' ) . '|' . ( $settings['type'] ?? '' ) );
	}

	/**
	 * Report this identity's batch lock, so a stalled or abandoned import is visible without database access.
	 *
	 * @param array $settings Validated import settings.
	 */
	private static function lock_state( array $settings ): array {
		$name = self::lock_name( $settings );
		$held = (string) get_option( $name );
		if ( '' === $held ) {
			return array(
				'name'  => $name,
				'held'  => false,
				'age'   => null,
				'stale' => false,
			);
		}
		$age = time() - (int) ( explode( '|', $held )[1] ?? 0 );
		return array(
			'name'  => $name,
			'held'  => true,
			'age'   => $age,
			'stale' => $age >= self::LOCK_TTL,
		);
	}

	/**
	 * Whether PHP's memory limit can hold the largest feed this plugin accepts (about 24 MiB to build its private
	 * copy, so 64 MiB is the documented floor). An unlimited (-1) limit passes.
	 */
	private static function covers_memory(): bool {
		$bytes = self::bytes( (string) ini_get( 'memory_limit' ) );
		return $bytes < 0 || $bytes >= 67108864;
	}

	/**
	 * Report a PHP size setting as both the raw ini shorthand and a byte count, because shorthand cannot be
	 * compared with this plugin's own byte limits.
	 *
	 * @param string $option ini option name.
	 */
	private static function size( string $option ): array {
		$value = (string) ini_get( $option );
		return array(
			'value' => $value,
			'bytes' => self::bytes( $value ),
		);
	}

	/**
	 * Convert a PHP shorthand byte value such as 256M to bytes. A negative value stays negative, which is how
	 * PHP reports "unlimited".
	 *
	 * @param string $value Raw ini value.
	 */
	private static function bytes( string $value ): int {
		$value = trim( $value );
		if ( '' === $value ) {
			return 0;
		}
		$units = array(
			'k' => 1024,
			'm' => 1048576,
			'g' => 1073741824,
			't' => 1099511627776,
		);
		$unit  = strtolower( substr( $value, -1 ) );
		$size  = (int) $value;
		return isset( $units[ $unit ] ) ? $size * $units[ $unit ] : $size;
	}

	/**
	 * Database server version, when WordPress exposes its handle.
	 */
	private static function database(): string {
		global $wpdb;
		return is_object( $wpdb ) && method_exists( $wpdb, 'db_version' ) ? (string) $wpdb->db_version() : '';
	}

	/**
	 * How many records one batch may import: this site's saved size, clamped to what the loop can honour.
	 *
	 * The option is read per batch rather than frozen in a constant, because the admin can lower it on a host that
	 * runs out of memory. It is clamped here so a value written straight into the options table (0, or 500) cannot
	 * make one request unbounded; anything unusable falls back to BATCH_RECORDS.
	 */
	public static function batch_records(): int {
		$saved = (int) get_option( self::BATCH_OPTION, 0 );
		if ( $saved < 1 ) {
			return self::BATCH_RECORDS;
		}
		return min( self::BATCH_MAX, $saved );
	}

	/**
	 * The batch-size control's own state: what is saved, what the shipped default is, and the ceiling.
	 *
	 * @return array{size: int, default: int, max: int}
	 */
	private static function batch_size_state(): array {
		return array(
			'size'    => self::batch_records(),
			'default' => self::BATCH_RECORDS,
			'max'     => self::BATCH_MAX,
		);
	}

	/**
	 * Seconds of record work this request may still use, from the batch budget and the PHP time limit.
	 *
	 * A batch must finish inside the request it runs in: the loop stops by this deadline, and PHP's own limit
	 * is raised only as far as the deadline plus the reserve, so a slow record can still complete and answer
	 * instead of the request being killed in the middle of a write.
	 */
	private static function budget(): float {
		$spent  = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? max( 0.0, microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) : 0.0;
		$limit  = (int) ini_get( 'max_execution_time' );
		$budget = (float) self::BATCH_SECONDS;
		if ( $limit > 0 ) {
			$budget = min( $budget, max( 0.0, $limit - $spent - self::REQUEST_RESERVE ) );
			$budget = max( (float) self::RECORD_FLOOR_SECONDS + 1.0, $budget );
			// set_time_limit() restarts PHP's counter from now, which is the window this batch intends to use.
			// It cannot extend past the original allowance unless the request started late, in which case it is
			// what stops a record that is already being written from being killed halfway through.
			if ( function_exists( 'set_time_limit' ) && ! set_time_limit( (int) ceil( $budget + self::REQUEST_RESERVE ) ) ) {
				self::log( 'the server would not set the time limit; the batch stays inside its own budget' );
			}
		}
		return max( (float) self::RECORD_FLOOR_SECONDS + 1.0, $budget );
	}

	/**
	 * Take this identity's batch lock, reclaiming one whose holder stopped refreshing it.
	 *
	 * @param string $lock Lock option name.
	 * @param string $id   Session UUID.
	 * @throws \RuntimeException When a live batch holds the lock; the code carries the seconds to wait.
	 */
	private static function lock( string $lock, string $id ): void {
		if ( add_option( $lock, $id . '|' . time(), '', false ) ) {
			return;
		}
		// A process killed by a signal cannot run its shutdown handler, so a lock that stopped being refreshed
		// must not block this identity. A live holder refreshes it after every record; the wait is bounded by
		// how long one record can take, not by a fixed penalty the admin has to sit through.
		$held = explode( '|', (string) get_option( $lock ) );
		$age  = time() - (int) ( $held[1] ?? 0 );
		if ( $age < self::LOCK_TTL ) {
			// The client waits this out and retries, so an interrupted import finishes without the admin
			// having to notice the error and press Resume again.
			throw new \RuntimeException( esc_html__( 'Another batch with this identity is running. Wait and resume.', 'import-everything' ), self::LOCK_TTL - $age + 2 );
		}
		delete_option( $lock );
		if ( ! add_option( $lock, $id . '|' . time(), '', false ) ) {
			throw new \RuntimeException( esc_html__( 'Another batch with this identity is running. Wait and resume.', 'import-everything' ), 5 );
		}
		self::log( 'reclaimed a batch lock that had not been refreshed for ' . $age . ' seconds' );
	}

	/**
	 * Keep this identity's lock young while the batch runs, so a live batch never looks abandoned.
	 *
	 * @param string $lock Lock option name.
	 * @param string $id   Session UUID.
	 */
	private static function heartbeat( string $lock, string $id ): void {
		// Only the owner may refresh the lock: a reclaimed lock belongs to another run, and a released one must
		// stay released instead of being recreated by a late heartbeat.
		$held = explode( '|', (string) get_option( $lock ) );
		if ( ( $held[0] ?? '' ) !== $id ) {
			return;
		}
		update_option( $lock, $id . '|' . time(), false );
	}

	/**
	 * Release a lock only while this session still owns it, so a reclaimed lock is never deleted by its
	 * previous holder.
	 *
	 * @param string $lock Lock option name.
	 * @param string $id   Session UUID.
	 */
	private static function unlock( string $lock, string $id ): void {
		$held = explode( '|', (string) get_option( $lock ) );
		if ( ( $held[0] ?? '' ) === $id ) {
			delete_option( $lock );
		}
	}

	/**
	 * Read the next records of a job from its spool, or by re-parsing the feed when no usable spool remains.
	 *
	 * @param array  $session Private session data.
	 * @param string $id      Session UUID.
	 * @param int    $offset  First record to read.
	 * @param string $file    Set to the materialized feed file when the fallback path is used.
	 */
	private function reader( array $session, string $id, int $offset, string &$file ): \Generator {
		$spool  = (string) ( $session['spool'] ?? '' );
		$format = (string) ( $session['format'] ?? 'xml' );
		$usable = '' !== $spool && Spool::count( $spool ) === (int) $session['job']['total'];
		$limit = self::batch_records();
		if ( $usable ) {
			return match ( $format ) {
				'json'  => JSON::spooled( $spool, $offset, $limit ),
				'csv'   => CSV::spooled( $spool, $offset, $limit ),
				default => XML::spooled( $spool, $offset, $limit ),
			};
		}
		$file = $this->file( $session, $id );
		$path = (string) $session['schema']['path'];
		if ( 'csv' === $format ) {
			return CSV::records( $file, $offset, $limit );
		}
		return 'json' === $format ? JSON::records( $file, $path, $offset, $limit ) : XML::records( $file, $path, $offset, $limit );
	}

	/**
	 * Account for a record whose request was killed mid-write before the job continues past it.
	 *
	 * The record is left where it is: the loop imports it again, and because the identity lookup runs before
	 * any write, re-importing converges — skip skips it, update completes it. This note is what tells the
	 * admin that the previous request died, which the counters alone cannot show.
	 *
	 * @param array  $session Private session data.
	 * @param string $id      Session UUID.
	 * @param string $file    Set to the materialized feed file when the fallback path is used.
	 * @param int    $number  The record the killed request had marked in flight, read before it is cleared.
	 */
	private function interrupted( array &$session, string $id, string &$file, int $number ): void {
		$job   = &$session['job'];
		$found = 0;
		try {
			foreach ( $this->reader( $session, $id, $number - 1, $file ) as $record ) {
				$found = Importer::find( $record, $job['settings'] );
				break;
			}
		} catch ( \Throwable $error ) {
			self::log( 'could not inspect interrupted record ' . $number . ': ' . $error->getMessage() );
		}
		$job['log'][] = array(
			'record'  => $number,
			'message' => $found
				? esc_html__( 'The previous request stopped while this record was being written. The post it started was found and will be completed, not duplicated.', 'import-everything' )
				: esc_html__( 'The previous request stopped before this record was written. It will be imported again.', 'import-everything' ),
		);
		$job['log'] = array_slice( $job['log'], -100 );
		set_transient( $this->key( $id ), $session, DAY_IN_SECONDS );
	}

	/**
	 * Serialize import writes and journal each completed record for safe HTTP retries.
	 *
	 * @param string $id Session UUID.
	 * @param array  $input Validated request payload.
	 * @throws \RuntimeException When the job is unavailable or already running.
	 */
	public function batch( string $id, array $input ): array {
		$session = $this->session( $id );
		if ( ! isset( $session['job'] ) ) {
			throw new \RuntimeException( esc_html__( 'Preview and start an import first.', 'import-everything' ) );
		}
		$settings = $session['job']['settings'];
		// Recheck role and post-type permissions on every batch, not just at job creation.
		$raw           = $settings;
		$raw['custom'] = array();
		foreach ( $settings['custom'] as $name => $row ) {
			$raw['custom'][] = array_merge( array( 'name' => $name ), $row );
		}
		Importer::settings( $raw, $session['schema'] );
		$lock = self::lock_name( $settings );
		self::lock( $lock, $id );
		register_shutdown_function(
			static function () use ( $lock, $id ) {
				self::unlock( $lock, $id );
			}
		);
		$file = '';
		try {
			$session = $this->session( $id );
			$job     = &$session['job'];
			if ( (int) ( $input['offset'] ?? -1 ) !== $job['offset'] || $job['offset'] >= $job['total'] ) {
				return $job;
			}
			// Read the marker before clearing it: the journal note must name the record that was interrupted.
			$stopped = (int) $job['in_flight'];
			if ( $stopped > 0 ) {
				$job['in_flight'] = 0;
				$this->interrupted( $session, $id, $file, $stopped );
			}
			$deadline = microtime( true ) + self::budget();
			foreach ( $this->reader( $session, $id, (int) $job['offset'], $file ) as $record ) {
				$remaining = $deadline - microtime( true );
				if ( $remaining < self::RECORD_FLOOR_SECONDS ) {
					// Out of budget: stop between records rather than run into the request's own time limit.
					break;
				}
				// Mark the record before touching it, so a killed request leaves evidence instead of silence.
				$job['in_flight'] = $job['offset'] + 1;
				set_transient( $this->key( $id ), $session, DAY_IN_SECONDS );
				try {
					$receipt = Importer::record( $record, $settings, $remaining );
					++$job[ $receipt['result'] ];
					if ( '' !== (string) ( $settings['feed'] ?? '' ) && ! empty( $receipt['id'] ) ) {
						update_post_meta( (int) $receipt['id'], '_iex_feed', (string) $settings['feed'] );
					}
					if ( $receipt['warning'] ) {
						++$job['warnings'];
						$job['log'][] = array(
							'record'  => $job['offset'] + 1,
							'message' => $receipt['warning'],
						);
					}
				} catch ( \Throwable $error ) {
					++$job['failed'];
					self::log( 'record ' . ( $job['offset'] + 1 ) . ' failed: ' . $error->getMessage() );
					$job['log'][] = array(
						'record'  => $job['offset'] + 1,
						'message' => $error instanceof \RuntimeException ? $error->getMessage() : __( 'Unexpected error while importing this record. Check the site debug log.', 'import-everything' ),
					);
				}
				++$job['offset'];
				$job['in_flight'] = 0;
				$job['log']       = array_slice( $job['log'], -100 );
				set_transient( $this->key( $id ), $session, DAY_IN_SECONDS );
				// The journal write is the checkpoint; refreshing the lock beside it means a live batch can only
				// ever be silent for one record, which is what LOCK_TTL is sized against.
				self::heartbeat( $lock, $id );
			}
			$finished = (int) $job['offset'] >= (int) $job['total'];
			if ( $finished ) {
				$this->release( $session, $id );
			}
			// One history write per batch: the session is the per-record checkpoint, this is the readable index.
			self::remember( $session, $id, $finished ? 'completed' : 'running' );
			return $job;
		} finally {
			if ( $file && is_file( $file ) ) {
				wp_delete_file( $file );
			}
			self::unlock( $lock, $id );
		}
	}

	/**
	 * Saved-feed and scheduled-run entry points live in Feeds, which runs jobs through this session and batch
	 * owner. These delegates are what the WP-Cron hooks bind to, so the hook wiring stays in one place.
	 */
	public function scheduled( string $feed_id, int $user_id ): void {
		( new Feeds( $this ) )->scheduled( $feed_id, $user_id );
	}

	/** See scheduled(): the follow-up pass runs through Feeds as the owning administrator. */
	public function continued( string $session_id, int $user_id, string $feed_id = '' ): void {
		( new Feeds( $this ) )->continued( $session_id, $user_id, $feed_id );
	}

	/** Whether the key rename is still unfinished; see Upgrade::migrating(). */
	public static function migrating(): bool {
		return Upgrade::migrating();
	}
}
