<?php
/**
 * One-time upgrade steps and the 1.3.0 stored-key rename.
 *
 * @package ImportEverything
 */

namespace ImportEverything;

defined( 'ABSPATH' ) || exit;

/** Runs stored-data migrations once per version change, in resumable chunks. */
final class Upgrade {
	/**
	 * Option holding the plugin version whose upgrade steps have already run here. Autoloaded because the requests
	 * that check it — admin, AJAX and cron — read it once, and one row in alloptions is cheaper than a query. A
	 * front-end page never reads it: `maybe_upgrade()` returns before the check there. While the identity migration
	 * is unfinished the value carries the point it reached (`TAG_MARKER`), not a bare version; only a value without
	 * a marker says the steps for that version are done.
	 */
	const VERSION_OPTION = 'iex_version';

	/**
	 * Option holding the progress of the 1.3.0 key rename while it is unfinished. Not autoloaded: only the requests
	 * that can run the upgrade read it, and it is deleted when the last chunk lands. Its presence also tells a
	 * duplicate lookup that posts still carry the old identity meta key.
	 */
	const MIGRATE_OPTION = 'iex_upgrade';

	/**
	 * The version option releases before 1.3.0 wrote, where everything was named `uxml_`. Read once per request that
	 * can upgrade — the value is autoloaded on an old install — to find a site whose stored keys still need the
	 * rename. The literal is deliberate: it names the old build, not this one.
	 */
	const LEGACY_VERSION_OPTION = 'uxml_version';

	/**
	 * Identity rows the upgrade migration rewrites per statement. Earlier releases did this in one UPDATE over every
	 * row, which is a table scan and a long lock on a site with tens of thousands of imported posts; a chunk is a
	 * bounded statement that ends well inside a request.
	 */
	const TAG_CHUNK = 500;

	/** Seconds the identity migration may spend before it stores its cursor and lets the next request continue. */
	const TAG_SECONDS = 5;

	/**
	 * What the version option carries while the identity migration is unfinished: `<version>:sha256@<meta_id>`. The
	 * meta_id is the point reached, so an interrupted migration resumes after it instead of scanning the table from
	 * the start. A plain version never contains a colon, so the two shapes cannot be mistaken for each other.
	 */
	const TAG_MARKER = ':sha256@';

	/**
	 * Run the pending upgrade on the request kinds that can act on it, and nowhere else.
	 *
	 * `register()` runs on `plugins_loaded` for every request, and `upgrade()` has to read the plugin header from disk
	 * to learn the installed version, so checking on a public page view would cost a file read on traffic that can
	 * never use the answer. The check is therefore confined to the requests that do use it: a wp-admin page, the
	 * plugin's own AJAX endpoint (which is not `is_admin()`, so it is named separately) and WP-Cron. An update is
	 * applied by the first of those after it, which is also the first moment the plugin does anything with stored
	 * data; front-end traffic reads neither the header nor the version option.
	 */
	public static function maybe_upgrade(): void {
		if ( ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() ) {
			return;
		}
		self::upgrade();
	}

	/**
	 * Run this release's one-time upgrade steps when the stored version is older than the installed one.
	 *
	 * One stored option decides, so a request that has nothing to do pays a single autoloaded read. The version is
	 * written only after every step finished, so a step that dies half-way runs again next request instead of being
	 * skipped for good. The identity migration is the one step that reads and rewrites stored rows, so it works in
	 * bounded chunks: it stores how far it got in the option and returns early while rows remain, and the next admin,
	 * AJAX or cron request continues from there. Nothing here touches transient sessions, whose TTL expires them
	 * anyway. Every step must be idempotent: a marker re-runs the whole list, and only the chunked step carries a
	 * cursor to resume from.
	 */
	public static function upgrade(): void {
		$current = self::version();
		if ( '' === $current ) {
			return;
		}
		// 1.3.0 renamed every stored key, so the rename runs before anything reads the new ones. It returns early on
		// a site that never carried the old names, and while a site still has rows left it returns false, so the
		// steps below wait for the next request instead of running against two naming schemes.
		if ( ! self::migrate_legacy() ) {
			return;
		}
		$installed = (string) get_option( self::VERSION_OPTION, '' );
		$cursor    = self::cursor( $installed );
		// A marker is not a version: it says an earlier request stopped before the steps finished, so the stored
		// value names no finished release and is read as a site that was never upgraded. Every step is idempotent
		// and the chunked one resumes from the cursor, so an interrupted upgrade may repeat a step, and can never
		// skip one. Reading the marker as a version would do both: it compares as neither the old nor the new release.
		$from = null === $cursor ? $installed : '';
		if ( version_compare( $from, $current, '>=' ) ) {
			return;
		}
		if ( version_compare( $from, '1.2.11', '<' ) ) {
			if ( ! self::tag_identities( (int) $cursor, $current ) ) {
				return;
			}
		}
		update_option( self::VERSION_OPTION, $current, true );
	}

	/**
	 * Whether the 1.3.0 key rename is still unfinished, so a duplicate lookup must also match the old meta key.
	 *
	 * The read is uncached on purpose: WordPress caches a missing option in its `notoptions` row, so a settled site
	 * answers from the object cache, and no static cache can go stale when a request finishes the migration and then
	 * keeps importing in the same request. A completed migration also sets the request flag below, so the deleted
	 * option is not read again.
	 */
	public static function migrating(): bool {
		return ! self::$renamed && null !== get_option( self::MIGRATE_OPTION, null );
	}

	/** Set once the rename finished in this request, so the option that was just deleted is not read again. */
	private static $renamed = false;

	/**
	 * Move the keys releases before 1.3.0 stored under `uxml_` to `iex_`, in bounded slices.
	 *
	 * Everything the plugin persists is named after it: the version option, saved feeds, histories, the batch size,
	 * the compressed copy of an uploaded feed, import sessions, per-record locks and the identity, image and feed
	 * meta of every imported post. Renaming the plugin without moving those would orphan all of it — saved feeds
	 * would vanish, schedules would stop firing, and every imported record would no longer match its post and be
	 * imported again as a new one. So the old namespace is read before the plugin's own steps run, copied to the
	 * new names, and the old names deleted.
	 *
	 * Options and sessions cost one statement each, but the post meta of a site with tens of thousands of imported
	 * posts cannot be rewritten in one go, so those rows are taken TAG_CHUNK at a time and the cursor is stored in
	 * MIGRATE_OPTION. A request that runs out of time returns false and the next admin, AJAX or cron request carries
	 * on after that meta_id. Re-running a slice is harmless: it only matches rows that still carry the old key.
	 *
	 * @return bool Whether nothing is left to rename.
	 */
	private static function migrate_legacy(): bool {
		global $wpdb;
		$installed = (string) get_option( self::VERSION_OPTION, '' );
		$state     = get_option( self::MIGRATE_OPTION, null );
		if ( null === $state ) {
			$from = preg_match( '/^[0-9]/', $installed ) ? preg_replace( '/:.*$/', '', $installed ) : '0';
			if ( version_compare( (string) $from, '1.3.0', '>=' ) ) {
				return true;
			}
			// A fresh install never carried the old names: one autoloaded read settles it, and nothing is rewritten.
			if ( '' === (string) get_option( self::LEGACY_VERSION_OPTION, '' ) ) {
				return true;
			}
			$state = array( 'phase' => 'options' );
			update_option( self::MIGRATE_OPTION, $state, false );
		}
		if ( ! is_array( $state ) || ! isset( $state['phase'] ) ) {
			$state = array( 'phase' => 'options' );
		}
		if ( 'options' === $state['phase'] ) {
			if ( ! self::rename_options() ) {
				return false;
			}
			self::reschedule_feeds();
			$state = array( 'phase' => 'meta', 'key' => 0, 'cursor' => 0 );
			update_option( self::MIGRATE_OPTION, $state, false );
		}
		$keys   = array( '_uxml_identity', '_uxml_image_url', '_uxml_feed' );
		$stop   = microtime( true ) + self::TAG_SECONDS;
		$chunk  = self::TAG_CHUNK;
		$key    = (int) ( $state['key'] ?? 0 );
		$cursor = (int) ( $state['cursor'] ?? 0 );
		while ( $key < count( $keys ) ) {
			// The ids are read first, so the cursor names the last row this slice touched even if the request dies
			// before it writes the marker: re-running the slice then costs one chunk at most.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
					$keys[ $key ],
					$cursor,
					$chunk
				)
			);
			if ( ! $ids ) {
				++$key;
				$cursor = 0;
				update_option( self::MIGRATE_OPTION, array( 'phase' => 'meta', 'key' => $key, 'cursor' => 0 ), false );
				continue;
			}
			$ids = array_map( 'intval', $ids );
			$new = '_iex_' . substr( $keys[ $key ], 6 );
			$done = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_key = %s WHERE meta_id IN (" . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ')',
					...array_merge( array( $new ), $ids )
				)
			);
			if ( false === $done ) {
				Admin::log( 'the 1.3.0 key rename stopped on a database error at ' . $keys[ $key ] . ' meta_id ' . $cursor );
				return false;
			}
			$full   = $chunk === count( $ids );
			$cursor = (int) max( $ids );
			if ( ! $full ) {
				++$key;
				$cursor = 0;
			}
			update_option( self::MIGRATE_OPTION, array( 'phase' => 'meta', 'key' => $key, 'cursor' => $cursor ), false );
			if ( microtime( true ) >= $stop ) {
				return false;
			}
		}
		// The meta was renamed under the object cache, so later reads in this request must not keep the old keys' rows.
		wp_cache_flush();
		delete_option( self::MIGRATE_OPTION );
		self::$renamed = true;
		return true;
	}

	/**
	 * Copy every `uxml_` option to its `iex_` name and delete the old row, then move in-flight sessions and the
	 * scheduled hooks.
	 *
	 * A name the new build already wrote wins: the old row is then only deleted, so running the step twice cannot
	 * overwrite newer data with older. Per-record locks are not copied — a lock held by the previous release names a
	 * run that request ended long ago, and the TTL would only keep it alive — and WordPress's cron table is not an
	 * option, so the old hooks are unscheduled here and the saved feeds are scheduled again by reschedule_feeds().
	 *
	 * @return bool Whether every option was copied and its old row removed.
	 */
	private static function rename_options(): bool {
		global $wpdb;
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'uxml_' ) . '%'
			)
		);
		foreach ( (array) $names as $name ) {
			if ( str_starts_with( (string) $name, 'uxml_lock_' ) ) {
				delete_option( (string) $name );
				continue;
			}
			$new = 'iex_' . substr( (string) $name, 5 );
			if ( null === get_option( $new, null ) ) {
				$copied = add_option( $new, get_option( (string) $name ), '', self::VERSION_OPTION === $new ? 'yes' : 'no' );
				// The old row is deleted only after the copy exists, so a failed write leaves it for the next request.
				if ( ! $copied && null === get_option( $new, null ) ) {
					Admin::log( 'the 1.3.0 option rename could not copy ' . $name );
					return false;
				}
			}
			delete_option( (string) $name );
		}
		// A session is a transient, and a run that is still resumable must stay resumable after the rename.
		foreach ( array( '_transient_', '_transient_timeout_' ) as $prefix ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_name = REPLACE(option_name, %s, %s) WHERE option_name LIKE %s",
					$prefix . 'uxml_',
					$prefix . 'iex_',
					$wpdb->esc_like( $prefix . 'uxml_' ) . '%'
				)
			);
		}
		wp_cache_flush();
		wp_unschedule_hook( 'uxml_run_feed' );
		wp_unschedule_hook( 'uxml_continue_feed' );
		return true;
	}

	/**
	 * Put every saved feed back on the schedule it had, now under the new hook name. A cron event is stored against
	 * the hook it was created with, so unscheduling the old hook leaves every feed without a run until this puts it
	 * back; the event carries the feed's own owner, not the current request's user.
	 */
	private static function reschedule_feeds(): void {
		global $wpdb;
		$names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'iex_feeds_' ) . '%'
			)
		);
		foreach ( (array) $names as $name ) {
			$owner = (int) substr( (string) $name, strlen( 'iex_feeds_' ) );
			$saved = $owner > 0 ? get_option( (string) $name, array() ) : array();
			$feeds = is_array( $saved ) && is_array( $saved['feeds'] ?? null ) ? $saved['feeds'] : array();
			foreach ( $feeds as $feed ) {
				if ( ! is_array( $feed ) || ! isset( $feed['id'], $feed['schedule'] ) || ! in_array( $feed['schedule'], Feeds::SCHEDULES, true ) || 'off' === $feed['schedule'] ) {
					continue;
				}
				$args = array( (string) $feed['id'], $owner );
				wp_clear_scheduled_hook( 'iex_run_feed', $args );
				wp_schedule_event( time() + MINUTE_IN_SECONDS, (string) $feed['schedule'], 'iex_run_feed', $args );
			}
		}
	}

	/**
	 * The meta_id a pending upgrade marker reached, or null when the stored value is a plain version.
	 *
	 * @param string $stored Stored value of the version option.
	 */
	private static function cursor( string $stored ): ?int {
		return preg_match( '/' . preg_quote( self::TAG_MARKER, '/' ) . '([0-9]+)$/', $stored, $match ) ? (int) $match[1] : null;
	}

	/**
	 * Rewrite one time slice of the identities an older release stored as a bare digest, resuming where the last
	 * slice stopped.
	 *
	 * 1.2.11 writes `sha256:<digest>`; everything before it wrote the digest alone, so without this rewrite no
	 * existing post would match its record any more and the next run would import it again as a new post. A site
	 * with tens of thousands of imported posts cannot do that in one statement without risking a timeout, so the
	 * rows are taken TAG_CHUNK at a time in meta_id order and the cursor is stored in the version marker after every
	 * full chunk: a request that runs out of time returns with the marker set, and the next one carries on after that
	 * meta_id instead of scanning and rewriting the table from the start. Re-running a chunk is harmless, because a
	 * value that already carries a scheme is skipped, so a crash between the UPDATE and the marker write costs one
	 * chunk at most.
	 *
	 * @param int    $cursor  meta_id the previous slice reached; 0 to begin at the first identity.
	 * @param string $current Installed version, carried by the marker while work remains.
	 * @return bool Whether every identity is tagged now.
	 */
	private static function tag_identities( int $cursor, string $current ): bool {
		global $wpdb;
		$stop  = microtime( true ) + self::TAG_SECONDS;
		$chunk = self::TAG_CHUNK;
		do {
			// The ids are read first so the cursor is exactly the last row this slice touched. A LIMIT on the UPDATE
			// alone would leave a request that died before writing its marker with no record of where it stopped.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT meta_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value NOT LIKE %s AND meta_id > %d ORDER BY meta_id ASC LIMIT %d",
					Importer::IDENTITY_META,
					'%:%',
					$cursor,
					$chunk
				)
			);
			if ( ! $ids ) {
				return true;
			}
			$ids = array_map( 'intval', $ids );
			// Only a full chunk can have rows behind it: a short one reached the end of the table. One test, used for
			// the marker, the loop and the answer, so the three cannot disagree.
			$full   = $chunk === count( $ids );
			$tagged = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->postmeta} SET meta_value = CONCAT(%s, meta_value) WHERE meta_id IN (" . implode( ', ', array_fill( 0, count( $ids ), '%d' ) ) . ') AND meta_key = %s AND meta_value NOT LIKE %s',
					...array_merge( array( Importer::IDENTITY_SCHEME . ':' ), $ids, array( Importer::IDENTITY_META, '%:%' ) )
				)
			);
			if ( false === $tagged ) {
				// The statement failed, so the cursor stays where it was and the next request retries this slice.
				Admin::log( 'the identity upgrade stopped on a database error after meta_id ' . $cursor );
				return false;
			}
			$cursor = (int) max( $ids );
			if ( $full ) {
				update_option( self::VERSION_OPTION, $current . self::TAG_MARKER . $cursor, true );
			}
		} while ( $full && microtime( true ) < $stop );
		return ! $full;
	}

	/**
	 * Installed plugin version, read from the plugin header rather than duplicated in code.
	 *
	 * The read is cached for the request: the header decides the upgrade once, and the support report asks for it
	 * again on the same request, so one file read is enough — and a header that changed under a running request
	 * cannot make the upgrade run against two different versions.
	 */
	public static function version(): string {
		static $version = null;
		if ( null === $version ) {
			$header  = get_file_data( IMPORT_EVERYTHING_FILE, array( 'Version' => 'Version' ) );
			$version = (string) ( $header['Version'] ?? '' );
		}
		return $version;
	}

}
