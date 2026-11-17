<?php
/**
 * Delete this plugin's temporary sessions, locks, record spools and import history — never imported content or
 * matching identities.
 *
 * @package ImportEverything
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
foreach ( array( '_transient_iex_', '_transient_timeout_iex_', 'iex_lock_', 'iex_jobs_', 'iex_feeds_', 'iex_feedxml_', 'iex_batch', 'iex_version', 'iex_upgrade' ) as $prefix ) {
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$wpdb->esc_like( $prefix ) . '%'
		)
	);
}

// Scheduled saved-feed runs are WP-Cron events, not options.
wp_unschedule_hook( 'iex_run_feed' );
wp_unschedule_hook( 'iex_continue_feed' );

// Record spools are files in the temp directory, so they are not covered by the option prefixes above.
// Windows' temp path uses backslashes, which glob() treats as escapes; normalize it before matching.
foreach ( (array) glob( str_replace( '\\', '/', get_temp_dir() ) . 'import-everything-spool*' ) as $spool ) {
	if ( is_file( $spool ) ) {
		wp_delete_file( $spool );
	}
}
