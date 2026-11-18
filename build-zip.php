<?php
/**
 * Builds dist/import-everything.zip from the explicit runtime file list.
 *
 *   php build-zip.php
 *
 * Entry names are written with forward slashes explicitly, because ZipArchive::addFile() records the
 * platform path (backslashes on Windows), which is not a valid plugin layout. The zip is a release
 * asset, never a committed file.
 */
$root = __DIR__;
$out  = $root . '/dist/import-everything.zip';

$files = array(
	'import-everything.php',
	'uninstall.php',
	'readme.txt',
	'LICENSE.txt',
	'includes/class-admin.php',
	'includes/class-docs.php',
	'includes/class-feeds.php',
	'includes/class-upgrade.php',
	'includes/class-importer.php',
	'includes/class-xml.php',
	'includes/class-spool.php',
	'includes/class-json.php',
	'includes/class-csv.php',
	'assets/admin.js',
	'assets/admin.css',
	'examples/gallery.xml',
	'examples/video.xml',
	'examples/catalog.json',
	'examples/positions.json',
	'examples/catalog.csv',
);

if ( ! is_dir( $root . '/dist' ) && ! mkdir( $root . '/dist', 0777, true ) ) {
	fwrite( STDERR, "cannot create {$root}/dist\n" );
	exit( 1 );
}

/*
 * A file the main plugin file requires but this list omits ships as a fatal error on activation,
 * so every require_once in import-everything.php is checked against the list before the zip is built.
 */
$main = (string) file_get_contents( $root . '/import-everything.php' );
if ( ! preg_match_all( '/require_once __DIR__ \. \'([^\']+)\'/', $main, $requires ) ) {
	fwrite( STDERR, "cannot read the require_once list from import-everything.php\n" );
	exit( 1 );
}
foreach ( array_unique( $requires[1] ) as $required ) {
	$required = ltrim( $required, '/' );
	if ( ! in_array( $required, $files, true ) ) {
		fwrite( STDERR, "required but not shipped: {$required}\n" );
		exit( 1 );
	}
}

if ( is_file( $out ) ) {
	unlink( $out );
}

$zip = new ZipArchive();
if ( true !== $zip->open( $out, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
	fwrite( STDERR, "cannot create {$out}\n" );
	exit( 1 );
}
foreach ( $files as $relative ) {
	$path = $root . '/' . $relative;
	if ( ! is_file( $path ) ) {
		fwrite( STDERR, "missing: {$relative}\n" );
		exit( 1 );
	}
	$zip->addFile( $path, 'import-everything/' . $relative );
}
$zip->close();

printf( "%s: %d bytes\n", $out, filesize( $out ) );
$check = new ZipArchive();
$check->open( $out );
for ( $i = 0; $i < $check->numFiles; ++$i ) {
	$stat = $check->statIndex( $i );
	printf( "  %8d  %s\n", $stat['size'], $stat['name'] );
	if ( false !== strpos( $stat['name'], '\\' ) ) {
		fwrite( STDERR, "backslash in entry name\n" );
		exit( 1 );
	}
}
$check->close();
