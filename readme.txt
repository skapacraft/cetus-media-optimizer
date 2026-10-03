=== Cetus Image Converter & AI Alt Text ===
Contributors:      danielegagliardi
Tags:              webp, avif, image optimization, alt text, ai
Requires at least: 6.2
Tested up to:      7.1
Stable tag:        1.1.2
Requires PHP:      8.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Advanced image optimizer: convert to AVIF/WebP, auto-generate Alt Text via AI, detect orphan files, and manage your entire media library.

== Description ==

**No longer maintained.** 1.1.2 is the last release: the plugin keeps working as it does today, but it will receive no further updates, compatibility fixes or security fixes. Plan a replacement.

**Cetus Image Converter & AI Alt Text** turns your WordPress media library into a lean, modern, and accessible asset store.

= What it does =

* **AVIF / WebP conversion**: Creates optimised copies alongside your originals (JPG/PNG/GIF are never deleted). Uses Imagick when available, falls back to GD.
* **Smart format selection**: *Automatic* mode picks AVIF when the server supports it, WebP otherwise. You can also force one format.
* **Auto-convert on upload**: Optionally convert every new image the moment it lands in your library.
* **AI-powered Alt Text**: Send images to Google Gemini or OpenAI (your own API key, BYOK) and save the generated description natively as `_wp_attachment_image_alt`. Automatic fallback between providers on quota errors (429).
* **Content Credentials aware**: Detects images carrying C2PA Content Credentials, the signed record of where an image came from and whether AI made it, and leaves them unconverted by default so that record survives. Converting re-encodes the pixels and destroys it permanently.
* **Orphan file scanner**: Finds images on disk that are not registered in the WordPress database and lets you optimise or ignore them.
* **Bulk processor**: Asynchronous AJAX batches with real-time progress bar, Pause and Stop controls. Safe for shared hosting.

= Privacy & Security =

* All AJAX requests are protected by WordPress nonces and `current_user_can()` checks.
* Original images are **never deleted**: conversion only adds new files.
* Telemetry is **opt-in only** and disabled by default. No data is ever sent unless the administrator explicitly enables it under Preferences → Telemetry.
* API keys are stored in the WordPress options table and are never exposed in the front-end source.

= Server requirements =

| Feature        | Requirement                             |
| -------------- | --------------------------------------- |
| WebP output    | Imagick with WebP **or** GD with WebP   |
| AVIF output    | Imagick with libavif/libheif            |
| AI Alt Text    | A valid Google Gemini or OpenAI API key |

The built-in **Step 1 – Server Diagnosis** panel shows green/red indicators for every capability on your specific server.

= BYOK, Bring Your Own Key =

Cetus Image Converter & AI Alt Text does **not** proxy AI requests through any third-party server. Calls go directly from your WordPress installation to the Google Generative Language API or the OpenAI API using your own credentials. You are responsible for monitoring your quota usage.

== External Services ==

This plugin connects to the following third-party services:

**Google Gemini (Google Generative Language API)**
Used to automatically generate Alt Text for images. Only called when the administrator has entered a valid Google Gemini API key and triggers a conversion (manual bulk process or automatic on upload, if enabled). The image is sent as a Base64-encoded inline payload. No image data is stored on any SkapaCraft server.

* What is sent: image data (Base64), custom prompt (if set), language preference
* When: only on explicit user action (bulk process or single upload with auto-convert enabled)
* Google Terms of Service: https://policies.google.com/terms
* Google Privacy Policy: https://policies.google.com/privacy
* Google Generative AI Terms: https://ai.google.dev/gemini-api/terms

**OpenAI API**
Used to automatically generate Alt Text for images. Only called when the administrator has entered a valid OpenAI API key and triggers a conversion. The image is sent as a Base64-encoded inline payload. No image data is stored on any SkapaCraft server.

* What is sent: image data (Base64), custom prompt (if set), language preference
* When: only on explicit user action (bulk process or single upload with auto-convert enabled)
* OpenAI Terms of Service: https://openai.com/policies/terms-of-use/
* OpenAI Privacy Policy: https://openai.com/policies/privacy-policy/

**Sentry**
Used to collect anonymous crash reports (PHP fatal errors and exceptions). This feature is **disabled by default** and requires explicit opt-in by the site administrator under Cetus Media → Preferences → Telemetry.

* What is sent: PHP error type and stack trace (limited to plugin files only), WordPress version, PHP version, plugin version. No IP addresses, usernames, email addresses or any personal data are ever transmitted.
* When: only when the administrator explicitly enables telemetry opt-in
* Sentry Terms of Service: https://sentry.io/terms/
* Sentry Privacy Policy: https://sentry.io/privacy/

== Installation ==

1. Upload the `cetus-media-optimizer` folder to `/wp-content/plugins/`.
2. Activate the plugin through the **Plugins** screen in WordPress.
3. Go to **Cetus Media** in the WordPress admin menu.
4. Follow the three-step wizard:
   - **Step 1**: Review the server diagnostics (green = supported, red = unavailable).
   - **Step 2**: Choose your output format and optionally add AI API keys.
   - **Step 3**: Click *Start Optimisation* to process your library.

== Frequently Asked Questions ==

= Will Cetus Image Converter & AI Alt Text delete my original images? =

No. Originals are never touched. The plugin creates a second file with the new extension (e.g. `photo.jpg` → `photo.webp`) alongside the original.

= My server does not support AVIF. What happens? =

If you choose *Automatic* format, the plugin detects server capabilities at runtime and falls back to WebP. If WebP is also unavailable, the image is skipped and a notice is shown in the diagnostics panel.

= What happens to images with Content Credentials (C2PA)? =

By default they are left unconverted, so they keep their credentials and yield no saving. A C2PA manifest contains a cryptographic hash of the image, so re-encoding the pixels invalidates it, and it cannot simply be copied onto the converted file either: doing that would mark the converted file as tampered with, which validates worse than having no provenance at all. You can turn this protection off under Preferences, in which case those images are converted like any other and the plugin tells you, per image and in the conversion log, that the credentials were lost. Your original file is never deleted in either case, so the credentials always remain in the original on disk.

= Is there a limit to the number of images I can process? =

No hard limit. The bulk processor works in small batches (5 images per AJAX tick) to respect server timeouts and can be paused and resumed at any time.

= How does the AI Alt Text feature work? =

After conversion, the plugin sends the image (as a Base64-encoded inline payload) to the selected AI provider. The response is sanitised and saved as the standard WordPress `_wp_attachment_image_alt` post meta. No image data is stored on any server.

= What happens if my AI quota is exceeded? =

If the primary provider returns HTTP 429, the plugin automatically retries with the other provider (if both keys are configured and fallback is enabled). A warning is displayed in the admin panel reminding you to monitor your API quota.

= Is the telemetry feature active? =

No, by default. The opt-in checkbox is disabled by default. If you enable it, anonymous PHP crash reports (error type, stack trace limited to plugin files, WordPress/PHP/plugin version) are sent to Sentry. No IP address, user data, API keys or personal information is ever transmitted. You can disable it at any time from Preferences → Telemetry.

== Screenshots ==

1. **Bulk optimisation in progress**: Real-time progress bar with speed (img/s), ETA, converted/skipped/errors counter and Pause/Stop controls.
2. **Server Diagnosis**: Green indicators confirm Imagick, GD, WebP and AVIF are all available. Runs a real encoding probe, not just a format list check.
3. **Preferences**: Output format selector (Automatic / AVIF / WebP / No conversion), auto-convert on upload toggle, and independent quality sliders for WebP and AVIF.
4. **AI Alt Text**: Configure Google Gemini or OpenAI (BYOK), automatic fallback on quota errors, language selector and custom prompt override.
5. **Library Management**: Statistics cards (total images, to convert, disk usage by format, cumulative savings), orphan file scanner and conversion log.

== Changelog ==

= 1.1.2 =
* This is the last release. Cetus Image Converter & AI Alt Text is no longer maintained.
* Added: an admin notice saying so, shown to users who can manage plugins on the Dashboard, the Plugins screen and the plugin's own page. On the first two it can be dismissed for good, per user.
* Removed: the review request box and the support email link from the plugin's page.

= 1.1.1 =
* Fixed: "Reset files" deleted the WebP and AVIF sub-sizes WordPress had generated itself. Only the main file of each attachment was protected, so a WebP upload kept its original while its sub-sizes were removed, leaving the media library and every srcset pointing at files that no longer existed.
* Fixed: the AI generated, and billed, a second alt text for images that already had one. `wp_generate_attachment_metadata` fires twice for a single upload whenever sub-size generation is deferred, and nothing stopped the second call from overwriting the first.
* Fixed: the orphan scan reported files WordPress owns, namely the unscaled original of every big-image upload and the companion files WordPress 7.1 writes when the browser processes an upload.
* Compatibility: tested up to WordPress 7.1, including client-side media processing.

= 1.1.0 =
* Added: detection of C2PA Content Credentials in JPEG, PNG, WebP and AVIF, reading only the file header. No new dependency and no external service.
* Added: images carrying Content Credentials are no longer converted by default, so their provenance survives. The behaviour is a setting you can turn off.
* Added: warnings before conversion, on the attachment screen and in the settings, with counts of files preserved and files whose credentials were lost.
* Fixed: `imagedestroy()` emitted a deprecation notice on PHP 8.5. It has done nothing since PHP 8.0 and has been removed; WebP output is unchanged.
* Docs: telemetry is now described accurately in the README, author credits corrected, and the code comments are in English throughout.

= 1.0.1 =
* Minor fix.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.1 =
Fixes a bug where "Reset files" deleted WebP and AVIF sub-sizes generated by WordPress itself, not by this plugin. If you use "Reset files" at all, upgrade before running it again. Also stops the AI from paying for a second alt text on images that already have one.

= 1.1.0 =
Images carrying C2PA Content Credentials are now skipped by default instead of being converted, because conversion destroys that provenance record permanently. If you would rather convert them and accept the loss, turn the Content Credentials setting off under Preferences. No existing file is modified or deleted by this upgrade.

= 1.0.0 =
Initial release.
