<?php
/**
 * Plugin Name: Import Everything!
 * Description: Import anything into WordPress: XML, RSS, Atom, JSON, CSV and Google Sheets feeds, NATS datatools, posts and custom fields, through one visual mapper and resumable imports.
 * Version: 1.3.2
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Import Everything!
 * License: GPL-2.0-or-later
 * Text Domain: import-everything
 *
 * @package ImportEverything
 */

namespace ImportEverything;

defined( 'ABSPATH' ) || exit;

define( 'IMPORT_EVERYTHING_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-xml.php';
require_once __DIR__ . '/includes/class-spool.php';
require_once __DIR__ . '/includes/class-json.php';
require_once __DIR__ . '/includes/class-csv.php';
require_once __DIR__ . '/includes/class-importer.php';
require_once __DIR__ . '/includes/class-upgrade.php';
require_once __DIR__ . '/includes/class-feeds.php';
require_once __DIR__ . '/includes/class-docs.php';
require_once __DIR__ . '/includes/class-admin.php';

/** Verify the host can securely parse XML. */
function activate(): void {
	if ( ! class_exists( '\XMLReader' ) || ! class_exists( '\DOMDocument' ) || ! function_exists( 'mb_substr' ) || ! function_exists( 'gzencode' ) ) {
		wp_die( esc_html__( 'Import Everything! requires the PHP XMLReader, DOM, mbstring, and zlib extensions.', 'import-everything' ) );
	}
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\\activate' );

add_action(
	'plugins_loaded',
	static function () {
		( new Admin() )->register();
	}
);
