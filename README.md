# Cetus Image Converter & AI Alt Text

**Next-Gen WebP & AVIF Conversion with AI-Powered Alt Text**

> **No longer maintained.** 1.1.2 is the last release, and the plugin has been
> closed on WordPress.org. Sites that have it installed keep working as they do
> today, but there will be no further updates, compatibility fixes or security
> fixes. The source stays here, under the same licence, for anyone who wants to
> carry it on.

Automated image optimization and smart SEO accessibility for WordPress. Convert your entire media library to AVIF or WebP, auto-generate Alt Text via Google Gemini or OpenAI, detect orphan files, and track savings: all from a single native admin panel.

## Features

- **AVIF / WebP conversion**: Creates optimized copies alongside originals (JPG/PNG/GIF are never deleted). Uses Imagick when available, falls back to GD.
- **Smart format selection**: Automatic mode picks AVIF when the server supports it, WebP otherwise. Force a specific format if needed.
- **WebP → AVIF re-conversion**: Already have WebP images? Convert them to AVIF without re-uploading.
- **Auto-convert on upload**: Optionally convert every new image the moment it lands in the library.
- **AI-powered Alt Text**: Send images to Google Gemini or OpenAI (BYOK) and save descriptions natively as `_wp_attachment_image_alt`. Automatic fallback between providers on quota errors.
- **Bulk processor**: Asynchronous AJAX batches with real-time progress bar, speed (img/s), ETA, Pause and Stop controls. Safe for shared hosting.
- **WP-Cron background processing**: Process the library unattended without keeping the browser open.
- **Orphan file scanner**: Finds images on disk not registered in the WordPress database.
- **Conversion log**: Scrollable table of the last conversions with format, space saved and status.
- **Per-image exclusion**: Skip individual images from both auto-convert and bulk batch via the attachment edit screen.
- **Library statistics**: Total images, unconverted count, disk usage by format, cumulative savings.

## Requirements

| Feature | Requirement |
|---|---|
| WebP output | Imagick with WebP **or** GD with WebP |
| AVIF output | Imagick with libavif / libheif |
| AI Alt Text | Google Gemini or OpenAI API key (BYOK) |
| WordPress | 6.2 or later |
| PHP | 8.0 or later |

The built-in **Step 1, Server Diagnosis** panel shows green/red indicators for every capability on your specific server.

## Installation

1. Upload the `cetus-media-optimizer` folder to `/wp-content/plugins/`.
2. Activate through **Plugins** in the WordPress admin.
3. Go to **Cetus Media** in the admin menu.
4. Follow the three-step panel:
   - **Step 1**: Review server diagnostics.
   - **Step 2**: Choose output format and optionally add AI API keys.
   - **Step 3**: Click *Start Optimisation* to process the library.

## Privacy & Security

- All AJAX handlers require a valid WordPress nonce and `current_user_can('manage_options')`.
- Original images are **never deleted**: conversion only adds companion files.
- API keys are stored in the WordPress options table and are never exposed in front-end HTML or JavaScript.
- AI requests go directly from your WordPress installation to Google or OpenAI: no data passes through a server of ours.

### The three services this plugin can talk to

Nothing here happens unless you turn it on. The full disclosure, in the form
WordPress.org requires, is under **External Services** in
[readme.txt](readme.txt).

**Google Gemini** and **OpenAI**, for alt text. Called only when you have
entered an API key and started a conversion. The image goes as a Base64 payload
directly from your site to the provider you chose.

**Sentry**, for crash reports. This one is worth reading carefully, because it
is the only feature that sends anything without you starting it:

- It is **off by default** and needs an explicit opt-in under
  *Cetus Media → Preferences → Telemetry*.
- With it on, a PHP error inside the plugin is reported. `before_send` drops
  any event whose stack trace does not pass through this plugin's own files, so
  a fault in your theme or in another plugin is discarded rather than sent.
- What leaves the site: error type, the stack trace, WordPress version, PHP
  version, plugin version. The user object is emptied, `send_default_pii` is
  off, and the `Cookie`, `Authorization`, `X-Forwarded-For`, `X-Real-IP` and
  `HTTP_CLIENT_IP` headers, the cookies, the environment and the URL are all
  stripped before the event is transmitted. Tracing and profiling are at 0.
- The plugin registers its own section in **Tools → Privacy**, so what it does
  is stated where WordPress expects your visitors to be told about it.
- The DSN is not in this repository. `%%SENTRY_DSN%%` is a placeholder that the
  release workflow substitutes; without it, `init()` returns before Sentry is
  ever started. Building this repository yourself therefore produces a plugin
  with no telemetry at all, even if the switch is on.

The implementation is in
[`includes/class-cetus-telemetry.php`](includes/class-cetus-telemetry.php),
and it is short enough to read in full before deciding whether you believe it.

## Development

```bash
# Install dev dependencies
composer install

# Run PHP_CodeSniffer
composer phpcs

# Fix what it can fix automatically
composer phpcbf
```

CI runs PHPCS against the WordPress Coding Standards and a syntax check on PHP
8.0 through 8.3, on every push. See `.github/workflows/`.

How to report, propose and build is in [CONTRIBUTING.md](CONTRIBUTING.md).

## Reporting a security problem

Not in a public issue: use the **Security** tab, then **Report a vulnerability**.
The project is no longer maintained, so there will be no fix: a confirmed
vulnerability is published as an advisory, so that sites still running the
plugin know to remove it. Details in [SECURITY.md](SECURITY.md).

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for the full history.

## Support

None: the project is no longer maintained, and issues and the support forum are
no longer answered.

## Author

Developed by SkapaCraft, now retired.

## Trademarks

Not affiliated with, endorsed by or sponsored by Google LLC, OpenAI or
Functional Software, Inc. Google, Gemini, OpenAI and Sentry are trademarks of
their respective owners, named here only to identify the services this plugin
can be configured to call.

## Licence

Copyright (C) 2026 SkapaCraft. GPL-2.0-or-later, see [LICENSE](LICENSE).

WordPress requires the GPL, and it suits a plugin that can be configured to
send crash reports off the site: whatever it does with that data, you can read
the code that does it and change it.

## Naming

Three names refer to the same thing, and all three are correct in their own
place. **Cetus Image Converter & AI Alt Text** is the published product name,
on WordPress.org and in the admin. **cetus-media-optimizer** is the
WordPress.org slug, which is fixed once a plugin is published, and therefore
also the name of this repository and of the plugin directory.
