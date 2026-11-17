# Import Everything!

An independent, original importer for everything a source can hand you — XML, RSS, Atom, JSON, CSV/TSV and
Google Sheets — into WordPress posts, pages, image attachments and custom post types, in one three-step visual
workflow.

![The Saved feeds tab: each saved feed with its last run, its next scheduled run, and the post status its runs import with](saved-feeds-check.png)

## What it does

- **One wizard, three steps.** Add a source (an uploaded file or a public URL), map the fields it discovered,
  then preview and import. Nothing is written and no media is fetched until you confirm the preview.
- **Every format, detected from the content.** XML, RSS and Atom feeds, JSON documents, comma/semicolon/tab/pipe
  tables and Google Sheets links all share the same mapping, preview, history and batch pipeline. The format is
  read from the file's first byte — never from the extension or the URL — so an address that serves XML is read
  as XML whatever it is called.
- **Fields, not guesses.** Discovery scans every selected record, including attributes, nested elements, CDATA,
  namespaces, empty fields and repeated values. The server re-checks the mapping against that schema, so a client
  cannot name a field the feed does not have: no XPath, no PHP evaluation, no arbitrary selectors.
- **Taxonomies and custom meta.** Categories, tags and WP-Script's Actors are mapping targets of their own; a
  custom row stores one value, a joined string, a serialized array, or one meta row per value — which is what a
  theme's hover gallery reads.
- **One-click presets** for WP-Script video themes, Tube Corporate partner feeds and NATS dumps. Each fills only
  the fields the feed really has and names the ones it could not find instead of inventing a meta key.
- **Imports that survive a long feed.** Each batch resumes from a private on-server record spool instead of
  re-reading the feed from the top, stops inside your server's own time limit, and waits out a request that holds
  the import lock rather than failing on it.
- **Saved feeds and schedules.** Save an analyzed feed, come back to edit its mapping or address, and run it by
  hand or through WP-Cron. A moved feed is checked against the saved mapping before it is stored or run, and
  Delete posts removes exactly what that feed imported.
- **History and a support report.** The last 20 runs are kept per administrator — counts, results and journal
  lines, with no feed contents, sample values or server paths — and one click bundles the environment, mapping
  and journal into a JSON file you can attach to a support request.
- **Documentation inside the plugin**, linked from its own sidebar entry and readable on a site with no internet
  access.

![The plugin's own Documentation page, linked under Import Everything! in the admin menu](docs-page-top.png)

## Requirements

- WordPress 6.4 or newer, on PHP 8.1 or newer.
- The PHP extensions `XMLReader`, `DOM`, `mbstring` and `zlib` — activation stops with a message naming any that
  is missing.
- The `manage_options` and `import` capabilities, which an administrator has. Every request re-checks them.
- Normal WordPress HTTP and media support, so a URL feed can be downloaded and an image resized.

## Installation

1. Copy the plugin into `wp-content/plugins/import-everything/` — WordPress identifies a plugin by its folder, so
   keep that name — or zip that folder and upload the zip under **Plugins → Add Plugin → Upload Plugin**.
2. Activate **Import Everything!** on the Plugins screen.
3. Open **Import Everything!** in the admin menu.

To build the installable zip yourself, put the plugin files in a single top-level `import-everything/` folder and
archive it as `import-everything.zip`; nothing needs to be compiled or downloaded first, and no Node.js or
Composer dependencies are involved.

## Using it

1. **Add source.** Upload an `.xml`, `.json`, `.csv` or `.tsv` file, or paste a public feed URL or a Google
   Sheets link. The record element is guessed and the fields it found are listed in the field explorer.
2. **Map fields.** Pick the post title, content, excerpt, featured image, unique identifier, categories, tags and
   any custom meta keys your theme reads. A URL split across two feed lines can be mapped to both, a repeated
   line can be read at a chosen position, and a term or meta row can carry a fixed value (`const:`) or a
   `{field}` template (`tpl:`). Choose the post type, the status, the import identity and what to do with a
   record that already exists.
3. **Preview & import.** The preview shows up to five records exactly as an import would write them — terms
   resolved, joins concatenated, durations converted — without creating anything. Tick the confirmation box and
   import; the run can be paused, and it can be resumed from **Recent imports** after you close the tab.

**Save feed** on the mapping step stores the mapping with the feed, so it can be run again by hand or on a
schedule (`off`, hourly, twice daily or daily), and its status is stored with it. **Download support report**
appears once a feed is analyzed.

## Documentation and limits

**`readme.txt` in this repository is the authoritative statement of behaviour and limits** — the hard limits for
each format, batch and memory arithmetic, taxonomy and image rules, duplicate handling, what uninstall removes,
and the full changelog. The plugin's own **Documentation** page covers the same ground inside WordPress. This
README describes what the plugin is for and how to start; where it and `readme.txt` disagree, `readme.txt` and the
code are right.

## Security

- Requests are administrator-only: `manage_options` and `import` on every request, a nonce check, and per-user
  sessions.
- Downloads are public-only and revalidated on every redirect hop, including the address ranges PHP's own range
  flags miss. Each hop's name is resolved once and the connection is held to the addresses that check approved,
  so a name that answers publicly to the check and privately to the connection is stopped before anything is
  read.
- XML is parsed without network access, document types and entities are rejected, and content and meta values are
  written through WordPress's own safe-HTML filtering. The admin UI renders everything as text.

## License

[GPL-2.0-or-later](LICENSE.txt).

This is independent, original work: no third-party importer code, branding, libraries or add-ons are included or
required.
