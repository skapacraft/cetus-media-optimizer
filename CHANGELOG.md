# Changelog

All notable changes to **Cetus Image Converter & AI Alt Text** are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-08-13

Three fixes, all of them in the paths that touch files or spend money, plus the
compatibility work for WordPress 7.1.

### Fixed
- "Reset files" deleted WebP and AVIF files WordPress had generated itself. The
  protected list was built from the main file of each attachment only, so a
  `photo.webp` upload survived while `photo-300x200.webp` and the rest of its
  sub-sizes were removed, leaving the media library and every `srcset` pointing
  at files that no longer existed. Any site converting to WebP through
  `image_editor_output_format` lost its sub-sizes the same way. The list is now
  built from everything the database has on record, which is what the function
  had always claimed to do.
- The AI generated, and billed, a second alt text for an image that already had
  one. `wp_generate_attachment_metadata` fires twice for a single upload
  whenever sub-size generation is deferred, and nothing stopped the second call
  from overwriting the first. Big-image uploads could already reach it; from
  WordPress 7.1 it is the norm for every upload made from the editor, where the
  browser generates the sub-sizes and sideloads them one by one. An attachment
  that already has an alt text is now left alone, which also covers the two bulk
  paths that called the generator without checking.
- The orphan scan reported files WordPress owns. It read sub-sizes from the
  attachment metadata but ignored `original_image`, so the unscaled original of
  every big-image upload was listed as an orphan and converted for nothing. The
  companion files WordPress 7.1 writes for browser-processed uploads are
  recognised too: the HEIC an iPhone photo arrived as, the MP4 an animated GIF
  was converted into, and that video's poster frame.

### Changed
- Tested up to WordPress 7.1.

## [1.1.0] - 2026-08-12

### Added
- Detection of C2PA manifests, the data behind Content Credentials, and a guard
  that stops conversion from destroying them. Converting an image re-encodes its
  pixels, and no manifest survives that: it carries a cryptographic hash of the
  asset, so it cannot be copied onto the converted file either without marking
  that file as tampered with, which validates worse than having no provenance at
  all. The new `Cetus_MO_C2PA` class finds a manifest by walking the container
  structure, reading only a few bytes of header: APP11/JUMBF segments labelled
  `c2pa` in JPEG, the `caBX` chunk in PNG, the `C2PA` RIFF chunk in WebP, and the
  ISOBMFF `uuid` box carrying the C2PA UUID in AVIF. No new dependency, no
  external binary, no network call. It reports presence only, and does no
  cryptographic validation.
- A **Content Credentials (C2PA)** setting, on by default, that leaves such files
  unconverted rather than stripping their provenance. Turning it off converts
  them like any other image and records, per attachment and in the conversion
  log, that the credentials were lost. Originals are never deleted either way.
- Warnings before conversion happens: on the attachment screen, where a file
  carrying credentials says so and states what conversion would do to it, and in
  the settings, which count the files preserved and the files whose credentials
  are gone.
- `SECURITY.md` rewritten around private reporting through the Security tab
  rather than email, and around what actually matters here: API key disclosure,
  losing originals, and telemetry leaking more than it says. The old one
  declared support for versions 1.5.x, which never existed.
- `CONTRIBUTING.md` rewritten, `CODE_OF_CONDUCT.md`, and issue and pull request
  templates.
- Dependabot for Composer and the CI actions. Sentry is deliberately excluded
  from the grouped updates: it is the only runtime dependency, and the only one
  that talks to the network.

### Fixed
- `convert_with_gd()` called `imagedestroy()`, which PHP 8.5 deprecates and which
  has done nothing since PHP 8.0, when a GD image became a `GdImage` object freed
  by the garbage collector. On PHP 8.5 every GD conversion emitted a deprecation
  notice into the debug log. The WebP output is byte-for-byte identical without
  it.
- The README claimed telemetry was "opt-in only and currently a stub: no data is
  sent". The plugin requires `sentry/sentry` and ships a working Sentry
  integration, and the release workflow substitutes a real DSN, so the released
  plugin does send crash reports when an administrator opts in. `readme.txt`
  disclosed this correctly under **External Services** all along; it was the
  README, the page most people read before installing, that did not. It now
  says exactly what leaves the site, what is stripped first, and how to verify
  it.
- The plugin header credited `LivQ` as author while the README, `composer.json`
  and the product itself say SkapaCraft. Two references to a "LivQ server" in
  `readme.txt` went the same way.
- `phpcs.xml.dist` held a raw `&` in its description, which made the ruleset
  invalid XML. PHPCS had been refusing to run from it.
- Two orphaned doc comments in `class-cetus-optimizer.php`: one describing
  `resolve_format()` sat on top of `conversion_disabled()`, and one describing
  `probe_avif_encoding()` sat on top of `source_has_alpha()`.

### Changed
- Comments are in English throughout. They were Italian across every `includes/`
  class, the plugin bootstrap, `uninstall.php` and the deploy workflow, in a
  public repository whose interface, readme and store listing are all English.
- Em dashes are gone from the code, the readme and the configuration files.

### Removed
- The `languages/` folder, along with the POT job in the deploy workflow and the
  `composer pot` script. The plugin never called `load_plugin_textdomain()` or
  `load_textdomain()`, so nothing there was ever loaded: WordPress serves
  translations for a WordPress.org plugin from `wp-content/languages/plugins/`,
  fetched from translate.wordpress.org. The bundled catalogues were dead weight,
  and had drifted badly, still naming a previous author and version 2.2.6.
  `Domain Path` went with them, since the folder it pointed at is gone.

## [1.0.1] - 2026-07-26

### Fixed

- Minor fix.

## [1.0.0] - 2026-07-05

### Added

- **AVIF / WebP conversion**: Creates optimised copies alongside originals using Imagick (with GD fallback). Original files are never deleted.
- **Smart format selection**: *Automatic* mode picks AVIF when the server supports it, WebP otherwise. Force a specific format or disable conversion entirely.
- **Auto-convert on upload**: Optionally convert every new image the moment it lands in the media library.
- **AI-powered Alt Text**: Sends images to Google Gemini or OpenAI (BYOK) and saves the generated description as `_wp_attachment_image_alt`. Automatic provider fallback on HTTP 429.
- **Bulk processor**: Asynchronous AJAX batches (5 images per tick) with real-time progress bar, Pause and Stop controls. Safe for shared hosting.
- **Orphan file scanner**: Finds images on disk not registered in the WordPress database and optimises them via a tick-based AJAX system with progress bar.
- **WP-Cron background processing**: Processes 5 images every 5 minutes without keeping the browser open.
- **Quality sliders**: WebP (default 82) and AVIF (default 75) configurable independently.
- **Conversion log**: Scrollable table of the last 50 conversions (date, filename, format, space saved, status). Clearable via AJAX.
- **Per-image exclusion**: Meta box on the attachment edit screen to skip individual images from conversion and Alt Text generation.
- **Opt-in data cleanup on uninstall**: "Data & Privacy" section in Preferences with a checkbox to permanently remove all plugin settings and attachment metadata from the database upon uninstallation. Original image files are never deleted.
- **Cumulative savings counter**: Total bytes saved across all sessions, persisted in the database.
- **Statistics cards**: Total images, images to convert, disk usage, savings.
- **"Delete all converted files" button**: Removes every WebP/AVIF file generated by the plugin without touching originals.
- **Frontend URL replacement**: `wp_get_attachment_image_src`, `wp_calculate_image_srcset` and `wp_filter_content_tags` filters swap JPG/PNG URLs with WebP/AVIF equivalents. No `.htaccess` rules required.
- **Automatic MIME type registration**: Writes `AddType image/avif .avif` and `AddType image/webp .webp` to `.htaccess` on activation.
- **Server Diagnosis panel**: Green/red indicators for Imagick, GD, WebP and AVIF support with a real encoding probe (1×1 pixel test, cached 24 h).
- **Three-step onboarding wizard**: Diagnosis → Preferences → Library Management.
- **AI Alt Text language selector**: Auto-detected from WordPress locale (22 languages) or manually overridden. Custom prompt textarea.
- **Opt-in anonymous crash reporting**: Via Sentry (disabled by default). Stack traces filtered to plugin files only; no IP, username or personal data transmitted.
- **Internationalisation**: English source strings; `it_IT`, `es_ES`, `fr_FR`, `de_DE` translations included.
