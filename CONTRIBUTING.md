# Contributing to Cetus Image Converter & AI Alt Text

This is maintained by one person, so the most useful contribution is usually a
precise report rather than a large patch. Everything below exists to make a
change reviewable, not to add ceremony.

## Before anything else

A security problem does not belong in a public issue. Use the **Security** tab,
then **Report a vulnerability**. What counts as one here is in
[SECURITY.md](SECURITY.md).

## Reporting a bug

Open an issue with the bug template. What makes a report actionable:

- the exact steps, from a clean state, and what you expected instead
- the version, and the platform it ran on
- a file or a screenshot when the trigger is one specific input

If the input holds personal data, describe how to build an equivalent one
rather than attaching it.

## Suggesting a feature

Open an issue with the feature template and describe the problem before the
solution. A feature that does not pull its weight does not ship: that is the
project's standard, not a rejection of the idea.

## Pull requests

Open an issue first for anything beyond a typo or a one-line fix, so the design
is agreed before the work happens.

Once that is settled:

1. Branch from `main`.
2. Keep the change to one concern. Two unrelated fixes are two pull requests.
3. Add a `CHANGELOG.md` entry, and bump the version where the project keeps it.
4. Verify it, and say in the pull request how you did.

## Building and checking locally

```bash
composer install
composer phpcs      # WordPress Coding Standards, must come out clean
composer phpcbf     # fixes what it can automatically
```

CI runs the same PHPCS ruleset plus a syntax check on PHP 8.0 through 8.3, on
every push. A pull request that does not pass PHPCS will not be merged.

## Translations

There are no `.po`, `.mo` or `.pot` files in this repository, and none should be
added. The plugin is hosted on WordPress.org, so WordPress fetches translations
from [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/cetus-media-optimizer/)
and loads them from `wp-content/languages/plugins/` on its own. A copy bundled
here would never be read, and would drift from the real one.

To translate the plugin, or to correct a string, go to translate.wordpress.org.
What belongs in a pull request is the `__()` call around a string that is
currently hard-coded, not the translation itself.

To build the distributable zip:

```bash
composer build
composer zip
```

## Two things to be careful with

**The originals.** Conversion adds a companion file and never touches the
source. Any change to `class-cetus-optimizer.php` that could delete, truncate
or overwrite an original will be rejected: for most sites the media library is
the only copy of those images that exists.

**Telemetry.** `includes/class-cetus-telemetry.php` is the only code here that
sends anything the administrator did not start. It is opt-in, it drops events
that do not originate in this plugin, and it strips the user object, the
cookies, the URL and the sensitive headers. A change that widens what it
transmits has to be reflected in three places at once: `readme.txt` under
**External Services**, the README, and the privacy text the plugin registers
under Tools, Privacy. Do not widen it in code alone.

The Sentry DSN is not in this repository. `%%SENTRY_DSN%%` is a placeholder the
release workflow substitutes, so a local build never sends anything. Never
commit a real DSN.

## House rules

- **English only**, in code comments, commit messages and user-facing strings.
- **Commit messages** say what changed and why. The subject line is imperative
  and under 72 characters, the body wraps at 72 and explains the reasoning that
  is not obvious from the diff.
- **No generated or vendored files** in a commit unless the project already
  tracks them.
- **No credentials, keys or personal data**, including in test fixtures.

## Licence

By contributing you agree that your work is distributed under the licence in
[LICENSE](LICENSE).
