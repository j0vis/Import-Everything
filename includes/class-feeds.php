<?php
/**
 * Saved feeds and their scheduled runs.
 *
 * @package ImportEverything
 */

namespace ImportEverything;

defined( 'ABSPATH' ) || exit;

/** Owns saved feeds, their WP-Cron schedules and the runs those schedules start. */
final class Feeds {
	/** The session and batch owner this class runs jobs through. */
	private Admin $admin;

	/**
	 * @param Admin $admin Session and batch owner: Feeds reads sessions and starts batches through it.
	 */
	public function __construct( Admin $admin ) {
		$this->admin = $admin;
	}

	/** Schedules a saved feed can run on: WordPress's own recurrences, so no custom interval is registered. */
	const SCHEDULES = array( 'off', 'hourly', 'twicedaily', 'daily' );

	/** Seconds one scheduled pass may spend before it hands the rest of the feed to a follow-up event. */
	const CRON_SECONDS = 240;

	/**
	 * Most posts one `feed_clear` pass will leave undeleted and exclude through `post__not_in`.
	 *
	 * The exclusion list becomes part of every query in the pass, so it is kept bounded: a feed whose undeletable
	 * posts fill it ends the pass at the cap instead of building an `IN` clause of thousands of IDs, and the
	 * client stops cleanly on the posts it was told were left.
	 */
	const CLEAR_SKIP_MAX = 500;

	/**
	 * Option name holding this user's saved feeds. Per user, like the import history: a saved feed names a URL
	 * and a mapping that belong to one administrator.
	 */
	private static function feeds_key(): string {
		return 'iex_feeds_' . get_current_user_id();
	}

	/**
	 * This user's saved feeds, in the order they were first saved.
	 */
	public static function feeds(): array {
		$stored = get_option( self::feeds_key(), array() );
		$feeds = array();
		if ( is_array( $stored ) && is_array( $stored['feeds'] ?? null ) ) {
			foreach ( $stored['feeds'] as $feed ) {
				if ( is_array( $feed ) ) {
					$feed            += array( 'source' => 'url', 'schedule' => 'off', 'active' => '', 'active_at' => 0, 'last_run' => 0, 'last_result' => null, 'next_run' => 0 );
					// Read from WP-Cron rather than guessed from the schedule: an event WordPress dropped is exactly
					// what a "next run" line has to show.
					$feed['next_run'] = (int) wp_next_scheduled( 'iex_run_feed', array( (string) $feed['id'], get_current_user_id() ) );
					$feeds[]          = $feed;
				}
			}
		}
		return $feeds;
	}

	/**
	 * Write the saved-feed list back as one option that is not autoloaded.
	 *
	 * @param array $feeds Saved feeds.
	 */
	public static function store_feeds( array $feeds ): void {
		update_option( self::feeds_key(), array( 'version' => 1, 'feeds' => array_values( $feeds ) ), false );
	}

	/**
	 * Find one saved feed of this user by its UUID.
	 *
	 * @param string $feed_id Saved feed UUID.
	 * @throws \RuntimeException When the ID is malformed or no saved feed has it.
	 */
	public static function find_feed( string $feed_id ): array {
		if ( ! preg_match( '/^[a-f0-9-]{36}$/', $feed_id ) ) {
			throw new \RuntimeException( esc_html__( 'Invalid saved feed.', 'import-everything' ) );
		}
		foreach ( self::feeds() as $feed ) {
			if ( $feed['id'] === $feed_id ) {
				return $feed;
			}
		}
		throw new \RuntimeException( esc_html__( 'That saved feed no longer exists. Analyze the feed again to save it.', 'import-everything' ) );
	}

	/**
	 * Turn validated settings, as stored in a saved feed, back into the list shape Importer::settings() reads.
	 *
	 * @param array $settings Validated settings.
	 */
	private static function as_input( array $settings ): array {
		$input           = $settings;
		$input['custom'] = array();
		foreach ( $settings['custom'] ?? array() as $name => $row ) {
			$input['custom'][] = array_merge( array( 'name' => $name ), $row );
		}
		return $input;
	}

	/**
	 * Option name holding one uploaded feed's compressed bytes. Kept out of the feed list, so listing saved feeds
	 * never loads a feed file.
	 *
	 * @param string $feed_id Saved feed UUID.
	 */
	public static function blob_key( string $feed_id ): string {
		return 'iex_feedxml_' . get_current_user_id() . '_' . $feed_id;
	}

	/**
	 * Merge fields into one saved feed. A feed deleted meanwhile is left alone.
	 *
	 * @param string $feed_id Saved feed UUID.
	 * @param array  $changes Fields to merge.
	 */
	public static function update_feed( string $feed_id, array $changes ): void {
		$feeds = self::feeds();
		foreach ( $feeds as $index => $saved ) {
			if ( $saved['id'] === $feed_id ) {
				$feeds[ $index ] = array_merge( $saved, $changes );
			}
		}
		self::store_feeds( $feeds );
	}

	/**
	 * Check that a schedule is one this plugin offers.
	 *
	 * @param string $schedule Requested schedule.
	 * @throws \RuntimeException When the schedule is not offered.
	 */
	public static function schedule_of( string $schedule ): string {
		if ( ! in_array( $schedule, self::SCHEDULES, true ) ) {
			throw new \RuntimeException( esc_html__( 'Choose a schedule: never, hourly, twice daily or daily.', 'import-everything' ) );
		}
		return $schedule;
	}

	/**
	 * Remove a saved feed: its list entry, its stored upload and its scheduled event. The posts it imported stay,
	 * which is what `feed_clear` is for.
	 *
	 * @param string $feed_id Saved feed UUID.
	 * @return array The remaining saved feeds.
	 * @throws \RuntimeException When the feed is unknown.
	 */
	public static function delete_feed( string $feed_id ): array {
		self::find_feed( $feed_id );
		wp_clear_scheduled_hook( 'iex_run_feed', array( $feed_id, get_current_user_id() ) );
		delete_option( self::blob_key( $feed_id ) );
		$feeds = array_values( array_filter( self::feeds(), static fn( $saved ) => $saved['id'] !== $feed_id ) );
		self::store_feeds( $feeds );
		return $feeds;
	}

	/**
	 * Replace a feed's WP-Cron event with one matching its schedule. The event carries the feed and its owner, so
	 * the run can act as that administrator.
	 *
	 * @param string $feed_id  Saved feed UUID.
	 * @param string $schedule One of SCHEDULES.
	 * @throws \RuntimeException When WordPress refuses the event.
	 */
	public static function reschedule( string $feed_id, string $schedule ): void {
		$args = array( $feed_id, get_current_user_id() );
		wp_clear_scheduled_hook( 'iex_run_feed', $args );
		if ( 'off' !== $schedule && false === wp_schedule_event( time() + MINUTE_IN_SECONDS, $schedule, 'iex_run_feed', $args ) ) {
			throw new \RuntimeException( esc_html__( 'WordPress would not schedule this feed. Check that WP-Cron is not disabled.', 'import-everything' ) );
		}
	}

	/**
	 * Confirm that a scheduled event's owner may still import, and act as them for the rest of the request.
	 *
	 * @param int $user_id Owner of the scheduled event.
	 */
	private static function as_owner( int $user_id ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user || ! user_can( $user, 'manage_options' ) || ! user_can( $user, 'import' ) ) {
			return false;
		}
		wp_set_current_user( $user_id );
		return true;
	}

	/**
	 * WP-Cron requests do not load the admin file helpers, which is where wp_tempnam() lives (admin-ajax loads them).
	 * Load them before a scheduled run touches a temporary file. Called only after the owner check, so a refused run
	 * never loads them.
	 */
	private static function admin_includes(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}

	/**
	 * Whether a feed's active run still has records left. A paused or unfinished run holds the schedule until it
	 * finishes or its session expires, so two passes never write the same feed at once.
	 *
	 * @param array $feed Saved feed.
	 */
	private function running( array $feed ): bool {
		$active = (string) ( $feed['active'] ?? '' );
		if ( '' === $active ) {
			return false;
		}
		$session = get_transient( $this->admin->key( $active ) );
		return is_array( $session ) && is_array( $session['job'] ?? null ) && (int) $session['job']['offset'] < (int) $session['job']['total'];
	}

	/**
	 * Whether the feed's active run has gone quiet long enough to be a pass PHP killed rather than one still
	 * working. `drain()` marks the feed active after every batch, so a live pass stays inside `LOCK_TTL`.
	 *
	 * @param array $feed Saved feed.
	 */
	private function abandoned( array $feed ): bool {
		return time() - (int) ( $feed['active_at'] ?? 0 ) > Admin::LOCK_TTL;
	}

	/**
	 * Validated settings for a saved feed's run, tagged with the feed so every record it writes can be traced back.
	 *
	 * @param array $feed   Saved feed.
	 * @param array $schema Schema the feed was analyzed into.
	 */
	public static function feed_settings( array $feed, array $schema ): array {
		$settings         = Importer::settings( self::as_input( $feed['settings'] ), $schema );
		$settings['feed'] = (string) $feed['id'];
		return $settings;
	}

	/**
	 * Validate a saved feed's mapping against the document it just fetched, and name both sides when it no longer
	 * fits.
	 *
	 * `feed_update` runs this same check on a new address before storing it, because someone is watching; a
	 * scheduled run has nobody watching, so the check has to leave a result the Saved feeds list can show instead of
	 * starting a job whose every record fails on a field the document no longer has.
	 *
	 * @param array $feed   Saved feed.
	 * @param array $schema Schema the feed's current document analyzes into.
	 * @return array Settings to run.
	 * @throws \RuntimeException When the saved mapping reads a field the document no longer has.
	 */
	private static function verify_feed( array $feed, array $schema ): array {
		try {
			return self::feed_settings( $feed, $schema );
		} catch ( \RuntimeException $error ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: 1: saved feed name, 2: the mapping problem the importer names. */
					esc_html__( 'Saved feed “%1$s” no longer fits the document at its address, so the run was not started: %2$s Nothing was imported. Open the feed and fix its mapping, then run it again.', 'import-everything' ),
					esc_html( (string) ( $feed['name'] ?? '' ) ),
					$error->getMessage()
				)
			);
		}
	}

	/**
	 * Fetch or unpack a saved feed and analyze it at its saved record path, as a private session the batch path
	 * can read. A URL feed is fetched again on every run; an uploaded feed uses the copy stored when it was saved.
	 *
	 * @param array $feed Saved feed.
	 * @throws \RuntimeException When the feed cannot be fetched, its upload is missing, or it no longer analyzes.
	 */
	public function open_feed( array $feed ): array {
		$url  = 'upload' === $feed['source'] ? '' : (string) $feed['url'];
		$id   = wp_generate_uuid4();
		$blob = '';
		$file = '';
		try {
			if ( '' === $url ) {
				$blob = (string) get_option( self::blob_key( (string) $feed['id'] ), '' );
				if ( '' === $blob ) {
					throw new \RuntimeException( esc_html__( 'The uploaded file for this saved feed is missing. Analyze the file again and save the feed.', 'import-everything' ) );
				}
				// Reading the stored copy and decoding it both hold the whole copy in memory. The URL branch below
				// charges its download; an uploaded feed has to charge the copy it just read before decoding it.
				Admin::memory_for_feed( strlen( $blob ), Admin::STORE_FACTOR );
				$file = $this->admin->file( array( 'xml' => $blob ), '' );
			} else {
				$fetch = self::feed_url( $url );
				if ( ! Importer::public_url( $fetch ) ) {
					throw new \RuntimeException( esc_html__( 'This saved feed now points to a private address, so it cannot be fetched.', 'import-everything' ) );
				}
				$file = wp_tempnam( 'import-everything' );
				Importer::download( $fetch, $file, XML::MAX_BYTES, 30 );
			}
			$bytes  = (int) filesize( $file );
			if ( '' !== $blob ) {
				// The decoded upload is held whole by the parser as well; the URL branch charges this before pack().
				Admin::memory_for_feed( $bytes );
			}
			$format = Admin::format( $file );
			$schema = Admin::discover( $format, $file, (string) $feed['path'] );
			if ( '' === $blob ) {
				Admin::memory_for_feed( $bytes );
				$blob = Admin::pack( $file );
				// As in `source`: this session holds the copy whole until a batch moves it out.
				Admin::memory_for_feed( strlen( $blob ), Admin::STORE_FACTOR );
			}
		} finally {
			if ( '' !== $file && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
		$session = array(
			'format' => $format,
			'xml'    => $blob,
			'name'   => (string) $feed['name'],
			'schema' => $schema,
			'url'    => $url,
			'feed'   => (string) $feed['id'],
			'bytes'  => $bytes,
		);
		if ( ! set_transient( $this->admin->key( $id ), $session, DAY_IN_SECONDS ) ) {
			throw new \RuntimeException( esc_html__( 'Unable to store the private feed session.', 'import-everything' ) );
		}
		return array(
			'id'      => $id,
			'session' => $session,
		);
	}

	/**
	 * The address a feed URL really downloads from.
	 *
	 * A Google Sheets link is rewritten to the sheet's CSV export, which is the one public endpoint a sheet offers
	 * without credentials: `/spreadsheets/d/<id>/export?format=csv` for a shared sheet (keeping its `gid` tab) and
	 * `/spreadsheets/d/e/<id>/pub?output=csv` for one published to the web. A link that already asks for CSV or TSV
	 * is left as it is, and every other address is returned unchanged.
	 *
	 * @param string $url Feed address as entered or saved.
	 */
	public static function feed_url( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) ) {
			return $url;
		}
		if ( ! in_array( strtolower( (string) ( $parts['host'] ?? '' ) ), array( 'docs.google.com', 'drive.google.com' ), true ) ) {
			return $url;
		}
		// `u/<account>/` is the address a signed-in account copies from its own sheet list.
		$path = (string) ( $parts['path'] ?? '' );
		if ( ! preg_match( '#/spreadsheets/(?:u/[0-9]+/)?(?:d/e/([A-Za-z0-9_-]+)|d/([A-Za-z0-9_-]+))#', $path, $match ) ) {
			return $url;
		}
		$sheet_id = '' !== ( $match[1] ?? '' ) ? $match[1] : ( $match[2] ?? '' );
		$query = (string) ( $parts['query'] ?? '' );
		if ( preg_match( '/(?:^|&)(?:output|format)=(?:csv|tsv)(?:&|$)/i', $query ) ) {
			return $url;
		}
		// The tab id sits in the query or after the fragment, and is only ever digits. Reading it with one
		// pattern avoids parse_str() building an array out of whatever a pasted address carries.
		$gid = '';
		if ( preg_match( '/(?:^|[?&#])gid=([0-9]+)/', $query . '#' . (string) ( $parts['fragment'] ?? '' ), $match ) ) {
			$gid = $match[1];
		}
		$export = str_contains( $path, '/d/e/' )
			? 'https://docs.google.com/spreadsheets/d/e/' . $sheet_id . '/pub?output=csv'
			: 'https://docs.google.com/spreadsheets/d/' . $sheet_id . '/export?format=csv';
		return '' !== $gid ? $export . '&gid=' . rawurlencode( $gid ) : $export;
	}

	/**
	 * Fetch a candidate address for a saved feed and analyze it at that feed's own record path, so a new URL can be
	 * checked against the saved mapping before it is stored. No session is kept: the file is deleted here.
	 *
	 * @param string $url  Candidate feed address.
	 * @param array  $feed Saved feed.
	 * @return array Schema the address analyzes into.
	 * @throws \RuntimeException When the address is private, unreachable, or no longer holds this feed's records.
	 */
	public static function inspect( string $url, array $feed ): array {
		$fetch = self::feed_url( $url );
		if ( ! Importer::public_url( $fetch ) ) {
			throw new \RuntimeException( esc_html__( 'Enter a public HTTP(S) XML, JSON, CSV or Google Sheets URL. Private addresses are blocked.', 'import-everything' ) );
		}
		$file = wp_tempnam( 'import-everything' );
		try {
			Importer::download( $fetch, $file, XML::MAX_BYTES, 30 );
			return Admin::discover( Admin::format( $file ), $file, (string) $feed['path'] );
		} finally {
			if ( is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}

	/**
	 * Open a saved feed and start its job. This is the one place a saved-feed run begins, shared by Run import
	 * and the schedule, so both behave identically.
	 *
	 * @param array $feed Saved feed.
	 * @throws \RuntimeException When the feed cannot be opened or its mapping no longer applies.
	 */
	public function launch( array $feed ): array {
		$opened = $this->open_feed( $feed );
		$schema = $opened['session']['schema'];
		// Checked before begin() so a mapping the document no longer supports cannot spool records or open a job,
		// and the session open_feed() just stored is dropped with it: a run that never starts leaves nothing behind.
		try {
			$settings = self::verify_feed( $feed, $schema );
		} catch ( \RuntimeException $error ) {
			delete_transient( $this->admin->key( $opened['id'] ) );
			throw $error;
		}
		$job = $this->admin->begin( $opened['session'], $opened['id'], $settings );
		self::update_feed( (string) $feed['id'], array( 'last_run' => time(), 'active' => $opened['id'], 'active_at' => time() ) );
		return array(
			'id'    => $opened['id'],
			'name'  => (string) $feed['name'],
			'path'  => (string) $schema['path'],
			'total' => (int) $schema['total'],
			'job'   => $job,
		);
	}

	/**
	 * Record how a saved-feed run ended and release the feed's active run.
	 *
	 * @param string $feed_id Saved feed UUID; nothing is recorded when empty.
	 * @param string $status  completed or failed.
	 * @param array  $job     Job counters, or empty when the run never started.
	 * @param string $message Failure text, or empty.
	 * @param string $session The run that is finishing; when given, a feed another run has taken over is left alone.
	 */
	public static function finish( string $feed_id, string $status, array $job, string $message, string $session = '' ): void {
		if ( '' === $feed_id ) {
			return;
		}
		// A continue event can arrive after the next run has already started, and its result must not undo that
		// run's counters or a failure it recorded.
		if ( '' !== $session ) {
			foreach ( self::feeds() as $saved ) {
				if ( (string) $saved['id'] === $feed_id ) {
					$current  = (string) $saved['active'];
					$previous = (string) ( $saved['last_result']['status'] ?? '' );
					// A completed run has no active session, so a late continue event must not replace its result. A
					// run whose session expired while still running may still record that failure.
					if ( $current !== $session && ( '' !== $current || 'completed' === $previous ) ) {
						return;
					}
					break;
				}
			}
		}
		$result = array( 'status' => $status, 'message' => $message, 'at' => time() );
		foreach ( array( 'total', 'created', 'updated', 'skipped', 'failed', 'warnings' ) as $key ) {
			$result[ $key ] = (int) ( $job[ $key ] ?? 0 );
		}
		self::update_feed( $feed_id, array( 'active' => '', 'last_result' => $result ) );
	}

	/**
	 * Run batches of a job until it finishes or this pass has used its time. Then the rest is handed to a
	 * follow-up event. A batch lock held by another run is waited out the same way, instead of failing the feed.
	 *
	 * @param string $id      Private session UUID of the job.
	 * @param int    $user_id Administrator who owns the job.
	 * @param string $feed_id Saved feed UUID.
	 */
	public function drain( string $id, int $user_id, string $feed_id ): void {
		$job  = $this->admin->session( $id )['job'];
		$stop = microtime( true ) + self::pass_seconds();
		while ( (int) $job['offset'] < (int) $job['total'] ) {
			if ( microtime( true ) >= $stop ) {
				wp_schedule_single_event( time() + MINUTE_IN_SECONDS, 'iex_continue_feed', array( $id, $user_id, $feed_id ) );
				return;
			}
			$before = (int) $job['offset'];
			try {
				$job = $this->admin->batch( $id, array( 'offset' => $before ) );
			} catch ( \RuntimeException $error ) {
				if ( $error->getCode() > 0 ) {
					wp_schedule_single_event( time() + $error->getCode(), 'iex_continue_feed', array( $id, $user_id, $feed_id ) );
					return;
				}
				throw $error;
			}
			// A pass that is still working has to look alive to the next scheduled event, or it would be taken for one
			// PHP killed and resumed beside itself.
			if ( '' !== $feed_id ) {
				self::update_feed( $feed_id, array( 'active_at' => time() ) );
			}
			if ( (int) $job['offset'] === $before ) {
				throw new \RuntimeException( esc_html__( 'The scheduled run stopped making progress.', 'import-everything' ) );
			}
		}
		self::finish( $feed_id, 'completed', $job, '', $id );
	}

	/**
	 * Pick up a pass PHP killed, instead of skipping the feed until that pass's session expires.
	 *
	 * The spool and the journal are intact, so draining the session continues where the killed request stopped. A
	 * session that cannot be read any more is a clear failed result rather than a retry loop, and `finish()` releases
	 * the feed so the next scheduled run starts fresh.
	 *
	 * @param array $feed    Saved feed whose run still holds records.
	 * @param int   $user_id Administrator who owns the feed.
	 */
	private function resume( array $feed, int $user_id ): void {
		$feed_id = (string) $feed['id'];
		$id      = (string) $feed['active'];
		try {
			self::update_feed( $feed_id, array( 'active_at' => time() ) );
			$this->drain( $id, $user_id, $feed_id );
		} catch ( \Throwable $error ) {
			Admin::log( 'resumed scheduled run failed: ' . $error->getMessage() );
			self::finish(
				$feed_id,
				'failed',
				array(),
				$error instanceof \RuntimeException
					? sprintf(
						/* translators: %s: why the run could not carry on. */
						esc_html__( 'The previous scheduled run was stopped before it finished and could not be resumed: %s The records it imported are kept; the next run imports the rest.', 'import-everything' ),
						$error->getMessage()
					)
					: esc_html__( 'The interrupted scheduled run could not be resumed. Check the site debug log.', 'import-everything' ),
				$id
			);
		}
	}

	/**
	 * WP-Cron entry point: run a saved feed as the administrator who saved it, through the same start and batch
	 * path the Run button uses.
	 *
	 * @param string $feed_id Saved feed UUID.
	 * @param int    $user_id Administrator who owns the feed.
	 */
	public function scheduled( string $feed_id, int $user_id ): void {
		if ( ! self::as_owner( $user_id ) ) {
			wp_clear_scheduled_hook( 'iex_run_feed', array( $feed_id, $user_id ) );
			Admin::log( 'scheduled feed ' . $feed_id . ' unscheduled: its owner may no longer import' );
			return;
		}
		try {
			self::admin_includes();
			$feed = self::find_feed( $feed_id );
			if ( 'off' === $feed['schedule'] ) {
				return;
			}
			if ( $this->running( $feed ) ) {
				// A live pass keeps `active_at` young and is left alone. A pass PHP killed leaves it behind, and waiting
				// for that session to expire is what stalled the feed for a day.
				if ( $this->abandoned( $feed ) ) {
					$this->resume( $feed, $user_id );
				}
				return;
			}
			$started = $this->launch( $feed );
			self::update_feed( $feed_id, array( 'last_result' => array( 'status' => 'running', 'at' => time() ) ) );
			$this->drain( $started['id'], $user_id, $feed_id );
		} catch ( \Throwable $error ) {
			Admin::log( 'scheduled feed failed: ' . $error->getMessage() );
			self::finish( $feed_id, 'failed', array(), $error instanceof \RuntimeException ? $error->getMessage() : __( 'The scheduled run failed unexpectedly. Check the site debug log.', 'import-everything' ) );
		}
	}

	/**
	 * WP-Cron entry point: carry on a scheduled run that used up its pass, as the administrator who owns it.
	 *
	 * @param string $session_id Private session UUID of the run.
	 * @param int    $user_id    Administrator who owns the run.
	 * @param string $feed_id    Saved feed UUID.
	 */
	public function continued( string $session_id, int $user_id, string $feed_id = '' ): void {
		if ( ! self::as_owner( $user_id ) ) {
			Admin::log( 'a scheduled run was not continued: its owner may no longer import' );
			return;
		}
		try {
			self::admin_includes();
			$session = get_transient( $this->admin->key( $session_id ) );
			if ( ! is_array( $session ) ) {
				throw new \RuntimeException( esc_html__( 'The scheduled run expired before it finished. The records it imported are kept; the next run imports the rest.', 'import-everything' ) );
			}
			$this->drain( $session_id, $user_id, $feed_id );
		} catch ( \Throwable $error ) {
			Admin::log( 'scheduled run failed: ' . $error->getMessage() );
							self::finish( $feed_id, 'failed', array(), $error instanceof \RuntimeException ? $error->getMessage() : __( 'The scheduled run failed unexpectedly. Check the site debug log.', 'import-everything' ), $session_id );
			}
		}

	/**
	 * Query for the posts a saved feed imported, in every status, found through the _iex_feed meta each import
	 * writes.
	 *
	 * @param string $feed_id Saved feed UUID.
	 * @param int    $limit   Posts per page.
	 * @param array  $skip    Post IDs to leave out, such as posts this pass may not delete.
	 */
	public static function feed_query( string $feed_id, int $limit, array $skip = array() ): \WP_Query {
		return new \WP_Query(
			array(
				'post_type'        => get_post_types(),
				'post_status'      => array_values( array_unique( array_merge( get_post_stati(), array( 'trash', 'inherit' ) ) ) ),
				'meta_key'         => '_iex_feed',
				'meta_value'       => $feed_id,
				'post__not_in'     => $skip,
				'fields'           => 'ids',
				'posts_per_page'   => $limit,
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
			)
		);
	}

	/**
	 * Delete the posts a saved feed imported and keep the feed. Works in passes of at most BATCH_SECONDS, so the
	 * client calls again until nothing is left or a pass deletes nothing.
	 *
	 * @param string $feed_id Saved feed UUID.
	 */
	public static function clear_posts( string $feed_id ): array {
		$stop    = microtime( true ) + Admin::BATCH_SECONDS;
		$skip    = array();
		$deleted = 0;
		while ( microtime( true ) < $stop ) {
			$ids = self::feed_query( $feed_id, Admin::batch_records(), $skip )->posts;
			if ( ! $ids ) {
				break;
			}
			foreach ( $ids as $post_id ) {
				if ( microtime( true ) >= $stop ) {
					break 2;
				}
				// Read before the delete: the post's meta and thumbnail are gone once it is deleted.
				$image    = (int) get_post_thumbnail_id( $post_id );
				$imported = '' !== (string) get_post_meta( $post_id, '_iex_image_url', true );
				if ( current_user_can( 'delete_post', $post_id ) && wp_delete_post( $post_id, true ) ) {
					++$deleted;
					// The featured image was downloaded for this one record, so it goes with the post.
					if ( $imported && $image && current_user_can( 'delete_post', $image ) ) {
						wp_delete_attachment( $image, true );
					}
					continue;
				}
				// The post stays: exclude it from the next query, and end the pass once the list is full so
				// `post__not_in` stays bounded.
				$skip[] = $post_id;
				if ( count( $skip ) >= self::CLEAR_SKIP_MAX ) {
					break 2;
				}
			}
		}
		return array(
			'deleted'   => $deleted,
			'blocked'   => count( $skip ),
			'remaining' => (int) self::feed_query( $feed_id, 1 )->found_posts,
		);
	}

	/**
	 * How long one scheduled pass may run: `CRON_SECONDS`, or less when this request's own limit is shorter, so a
	 * pass on a host with a 30 s limit hands the rest over instead of being killed mid-batch with the feed active.
	 */
	private static function pass_seconds(): float {
		$limit = (int) ini_get( 'max_execution_time' );
		if ( $limit < 1 ) {
			return (float) self::CRON_SECONDS;
		}
		$spent = isset( $_SERVER['REQUEST_TIME_FLOAT'] ) ? max( 0.0, microtime( true ) - (float) $_SERVER['REQUEST_TIME_FLOAT'] ) : 0.0;
		return max( 0.0, min( (float) self::CRON_SECONDS, (float) $limit - $spent - Admin::REQUEST_RESERVE ) );
	}

}
