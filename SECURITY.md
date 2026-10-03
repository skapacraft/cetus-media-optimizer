# Security policy

> **The project is no longer maintained.** Reports are still welcome through
> the **Security** tab, but there will be no fix and no new release. A
> confirmed vulnerability will be published as an advisory, so that sites still
> running the plugin know to remove it.

## What this plugin is, and why that shapes the threat model

Cetus holds two things that make it worth attacking: **API keys** for Google
Gemini and OpenAI, which cost the site owner real money if they leak, and
**write access to the media library**, which on most WordPress sites is the one
folder full of files nobody has a second copy of.

Three promises shape the design:

> Original images are never deleted. API keys never reach the browser. Nothing
> is sent anywhere the administrator did not switch on.

The third one has a caveat that is stated rather than hidden: the plugin can be
configured to call Google, OpenAI and Sentry. Which, when, and what leaves the
site is documented under **External Services** in `readme.txt` and in the
README, and the Sentry path is off until an administrator turns it on.

## What counts as a vulnerability here

- **API key disclosure.** The keys live in the options table and must never
  appear in front-end HTML, in JavaScript, in an AJAX response, in an error
  message, or in a Sentry event. Anything that gets one out belongs here, and
  it is the highest-severity class in this plugin.
- **Losing images.** Conversion only ever adds a companion file. A path that
  deletes, truncates or overwrites an original is a vulnerability, not a bug:
  for a lot of sites the media library is the only copy that exists.
- **Writing outside the uploads directory.** The plugin writes next to the file
  it converted. Anything that escapes that, through a crafted filename, a
  traversal in an attachment path, or the orphan scanner, belongs here.
- **Privilege escalation.** Every AJAX handler requires a valid nonce and
  `manage_options`. A handler that omits either, or a capability check that can
  be bypassed, belongs here.
- **Telemetry leaking more than it says.** `before_send` in
  `includes/class-cetus-telemetry.php` drops events that do not come from this
  plugin and strips the user object, the cookies, the URL, the environment and
  the sensitive headers. Anything that gets personal data or a key past it is a
  vulnerability even though the feature is opt-in.
- **Server-side request forgery through the AI providers.** The image payload
  goes to a fixed endpoint. Anything that redirects it elsewhere, or that makes
  the plugin fetch a URL an attacker chose, belongs here.
- **Memory or CPU exhaustion from a crafted image.** The converters run over
  files the site owner did not write. A file that makes Imagick or GD consume
  the whole process belongs here, since it takes the site down.

Failing to convert a malformed image is a bug, and worth an issue, but it is
not a vulnerability on its own: bad input should produce a logged failure and a
skipped file rather than a fatal error.

## Out of scope

- WordPress core, Imagick, GD, libavif and libheif themselves. What is in scope
  is how this plugin calls them.
- Google, OpenAI and Sentry as services. Report those to their vendors. What is
  in scope is what this plugin sends them.
- Charges on your own API account from conversions you started. The plugin uses
  the key you entered against the provider you chose, and the README says so.
- Anything requiring an attacker who already has `manage_options`. At that point
  the keys are readable without going through this plugin.

## How to report

**Please do not open a public issue for a security problem.** A public report
tells everyone how to exploit it before there is a fix, on sites the reporter
does not own.

Use GitHub's private reporting: the **Security** tab of this repository, then
**Report a vulnerability**. It opens a channel visible only to the maintainer.

Useful in a report, roughly in order of usefulness:

- what an attacker gains, in one sentence
- the steps to reproduce, from a clean install
- the plugin version, the WordPress version and the PHP version
- whether the server uses Imagick or GD, since the two conversion paths differ
- a file that triggers it, if the trigger is one specific image

Never include a real API key in a report, even your own. Say which provider it
was for and where it surfaced.

## What happens then

This is maintained by one person, so no response time is promised that could
not be kept. What is promised instead:

- a report is acknowledged when it is read, even if the answer is that it needs
  time
- a confirmed finding is fixed before anything else, and released to
  WordPress.org as a patch version
- the fix says what was wrong and since which version, in the changelog
- credit goes to whoever reported it, unless they prefer otherwise

## Supported versions

Only the latest version published on
[WordPress.org](https://wordpress.org/plugins/cetus-media-optimizer/) is
supported. There is no back-porting to older ones: update before reporting.
