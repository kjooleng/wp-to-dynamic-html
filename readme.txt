=== WP To Dynamic HTML (Portable Export) ===
Contributors: Kang JL
Tags: static, export, html, backup, migration
Requires at least: 6.0
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Export WordPress pages and posts to portable static HTML files with optional asset copying and split ZIP archives.

== Description ==

WP To Dynamic HTML (Portable Export) creates static HTML copies of your WordPress
site that can be viewed offline or deployed to any static hosting provider.

The plugin:

* Exports the configured home page as `index.html` (either a static front page or posts page, depending on your Reading settings) [file:59].
* Exports selected pages and, optionally, all published posts as individual `.html` files based on their slugs.
* Renders content using your active theme templates internally (no external HTTP loopback), so it works even when the server blocks self‑requests [file:59].
* Rewrites internal links to point at the corresponding `.html` files.
* Copies referenced CSS, JavaScript, images, and fonts into an `assets/` folder with relative paths.
* Creates one or more ZIP archives of the exported HTML and assets, splitting into parts kept safely below ~9 MB each to respect strict hosting limits.

This is useful for:

* Moving a small content site to static hosting.
* Creating a downloadable offline copy of a site.
* Archiving a point‑in‑time snapshot of your content.

Note: Forms, search, comments, and other dynamic PHP features will not work in the exported static version; only the rendered HTML, CSS, JS, and media are preserved.

== Installation ==

1. Upload the plugin folder `wp-to-dynamic-html` to the `/wp-content/plugins/` directory, or install it as a ZIP via the WordPress Plugins screen.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Go to **Tools → Export Portable HTML** in the WordPress admin area.

== Usage ==

1. Open **Tools → Export Portable HTML**.
2. Review the detected home page type (static page or latest posts).
3. Select which pages to export. The home page is always included as `index.html`.
4. Optionally enable "Include posts" to export all published blog posts.
5. Choose whether to:
   * Copy assets (CSS/JS/images/fonts) into an `assets/` directory.
   * Split ZIP archives so each part remains under your hosting size limit.
6. Click **Start Export**.
7. Watch the progress log as:
   * The home page is exported first.
   * Selected pages and posts are exported one per request.
   * ZIP archives are created at the end.
8. When finished, download the contents of:
   `wp-content/uploads/wp-dynamic-html-export/`

Inside that folder you will find:

* `index.html` – exported home page.
* `*.html` – one file per selected page/post.
* `assets/` – copied theme/plugin assets (if enabled).
* `wp-export-YYYY-MM-DD-HHMMSS-partN.zip` – HTML ZIP parts.
* `wp-export-YYYY-MM-DD-HHMMSS-assets-partN.zip` – assets ZIP parts (when splitting is enabled).
* `README.txt` – instructions for using the exported files.

== Frequently Asked Questions ==

= Will contact forms or comments work in the exported HTML? =

No. The export contains static HTML, CSS, JS, and media. PHP‑based features
such as forms, comments, and search require a dynamic WordPress backend and
will not function in the static copy.

= Why are there multiple ZIP files? =

On hosts with strict per‑file limits, large ZIPs can be rejected or time out.
The plugin therefore splits HTML and assets into multiple ZIP parts, each kept
under roughly 8 MB raw size so that the final `.zip` files remain safely below
a 9 MB limit.

= Why do some large media files not appear in assets? =

To keep exports fast and within execution limits, files larger than a
configured threshold (e.g. 5 MB) are skipped when copying assets. Very large
media should be hosted separately or optimized before export.

= Does the plugin modify my live site? =

No. It only reads your content and templates to generate static files into the
uploads directory; it does not alter posts, pages, or theme files.

== Screenshots ==

1. Export settings screen showing page selection and options.
2. Progress view with per‑page log and progress bar.
3. Example exported folder structure with index.html and assets/.

== Changelog ==

= 1.0.0 =
* Initial stable release.
* Internal rendering of pages/posts using theme templates (no external HTTP).
* Per‑item export via AJAX to respect PHP max execution time.
* Asset copying into `assets/` subfolders with relative URLs.
* Internal link rewriting to `.html` files.
* ZIP creation with optional splitting into parts under ~9 MB.
* Added README generation describing exported folder contents.

== Upgrade Notice ==

= 1.0.0 =
This is the first stable version. If you previously used experimental builds,
remove old versions and replace them with this release to ensure correct
per‑page exports and ZIP splitting behaviour.
