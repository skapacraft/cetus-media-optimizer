<?php
/**
 * Sentry integration, for anonymous reporting of PHP errors.
 *
 * It runs ONLY when the administrator has explicitly opted in from the plugin
 * settings, and it is off by default. No personal data and no IP address is
 * ever transmitted: see sanitize_event() below for what is stripped, and
 * SECURITY.md for what counts as a failure of that promise.
 *
 * @package CetusMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles starting Sentry, and the WordPress privacy policy entry.
 */
class Cetus_MO_Telemetry {

	/**
	 * The Sentry project DSN.
	 *
	 * A placeholder that the release workflow substitutes at deploy time. NEVER
	 * commit a real DSN to a public repository. A site can also define
	 * CETUS_MO_SENTRY_DSN in wp-config.php to use its own project instead.
	 *
	 * A build made straight from this repository keeps the placeholder, so
	 * init() returns before Sentry starts and nothing is ever sent.
	 */
	private const DSN = '%%SENTRY_DSN%%';

	/**
	 * Starts Sentry, but only with explicit consent and only when the SDK is
	 * present through Composer.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( ! self::is_opted_in() ) {
			return;
		}

		$dsn = defined( 'CETUS_MO_SENTRY_DSN' ) ? CETUS_MO_SENTRY_DSN : self::DSN;

		if ( '' === $dsn || 'YOUR_SENTRY_DSN_HERE' === $dsn || str_contains( $dsn, '%%' ) ) {
			return;
		}

		if ( ! class_exists( '\Sentry\SentrySdk' ) ) {
			return;
		}

		\Sentry\init(
			[
				'dsn'                  => $dsn,
				'send_default_pii'     => false,
				'environment'          => ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? 'development' : 'production',
				'release'              => 'cetus-media-optimizer@' . CETUS_MO_VERSION,
				'traces_sample_rate'   => 0.0,
				'profiles_sample_rate' => 0.0,
				'max_breadcrumbs'      => 10,
				'attach_stacktrace'    => true,
				'before_send'          => [ self::class, 'sanitize_event' ],
			]
		);

		\Sentry\configureScope(
			function ( \Sentry\State\Scope $scope ): void {
				$scope->setTag( 'wp_version', get_bloginfo( 'version' ) );
				$scope->setTag( 'php_version', PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION );
				$scope->setTag( 'plugin_version', CETUS_MO_VERSION );
				$scope->setUser( [] );
			}
		);
	}

	/**
	 * The before_send callback: strips anything personal or sensitive before an
	 * event leaves the site.
	 *
	 * It also discards events entirely when the stack trace does not pass
	 * through this plugin's own files, so a fault in the theme or in another
	 * plugin is never reported from here.
	 *
	 * @param \Sentry\Event     $event The original event.
	 * @param \Sentry\EventHint $hint  Extra context, such as the original exception.
	 * @return \Sentry\Event|null
	 */
	public static function sanitize_event( \Sentry\Event $event, \Sentry\EventHint $hint ): ?\Sentry\Event {
		// Drop events that did not originate in this plugin's own files.
		$plugin_dir = defined( 'CETUS_MO_DIR' ) ? CETUS_MO_DIR : '';
		if ( $plugin_dir ) {
			$from_plugin = false;
			foreach ( $event->getStacktrace()?->getFrames() ?? [] as $frame ) {
				if ( str_starts_with( (string) $frame->getAbsoluteFilename(), $plugin_dir ) ) {
					$from_plugin = true;
					break;
				}
			}
			// Check the original exception in the hint as well.
			if ( ! $from_plugin && isset( $hint->exception ) ) {
				foreach ( $hint->exception->getTrace() as $trace_frame ) {
					if ( isset( $trace_frame['file'] ) && str_starts_with( $trace_frame['file'], $plugin_dir ) ) {
						$from_plugin = true;
						break;
					}
				}
			}
			if ( ! $from_plugin ) {
				return null;
			}
		}

		$event->setUser( null );

		$request = $event->getRequest();
		if ( ! empty( $request ) ) {
			$sensitive_headers = [
				'Cookie',
				'Authorization',
				'X-Forwarded-For',
				'X-Real-IP',
				'HTTP_CLIENT_IP',
			];
			$headers           = $request['headers'] ?? [];
			foreach ( $sensitive_headers as $header ) {
				unset( $headers[ $header ], $headers[ strtolower( $header ) ] );
			}
			unset( $request['cookies'], $request['env'], $request['url'] );
			$request['headers'] = $headers;
			$event->setRequest( $request );
		}

		$extra = $event->getExtra();
		if ( ! empty( $extra ) ) {
			unset( $extra['server'] );
			$event->setExtra( $extra );
		}

		return $event;
	}

	/**
	 * Whether the administrator has given consent.
	 *
	 * @return bool
	 */
	public static function is_opted_in(): bool {
		return '1' === get_option( 'cetus_media_telemetry_opt_in', '0' );
	}

	/**
	 * Registers the plugin's privacy policy text on the WordPress privacy page,
	 * under Tools, Privacy. Whatever this plugin can send has to be stated where
	 * a site's visitors are told about it, not only in the repository.
	 *
	 * @return void
	 */
	public static function register_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$allowed_tags = [
			'strong' => [],
			'a'      => [
				'href'   => [],
				'rel'    => [],
				'target' => [],
			],
			'p'      => [],
		];

		$content = '<h2>' . esc_html__( 'Cetus Image Converter & AI Alt Text: Error Telemetry', 'cetus-media-optimizer' ) . '</h2>';

		$content .= '<p>' . wp_kses(
			__(
				'If the site administrator has enabled telemetry in the settings of <strong>Cetus Image Converter & AI Alt Text</strong>, the plugin sends anonymous PHP error reports to the <a href="https://sentry.io" rel="noopener noreferrer" target="_blank">Sentry</a> service (sentry.io).',
				'cetus-media-optimizer'
			),
			$allowed_tags
		) . '</p>';

		$content .= '<p>' . esc_html__(
			'The transmitted data includes exclusively: PHP error type, stack trace limited to plugin files, WordPress version, PHP version and plugin version. No IP address, username, site content or personal data is ever transmitted.',
			'cetus-media-optimizer'
		) . '</p>';

		$content .= '<p>' . esc_html__(
			'This feature is disabled by default and requires explicit administrator consent. It can be disabled at any time from Cetus Media → Preferences → Telemetry.',
			'cetus-media-optimizer'
		) . '</p>';

		$content .= '<p>' . wp_kses(
			sprintf(
				/* translators: %s: URL of Sentry privacy policy */
				__( 'For more information see the <a href="%s" rel="noopener noreferrer" target="_blank">Sentry Privacy Policy</a>.', 'cetus-media-optimizer' ),
				'https://sentry.io/privacy/'
			),
			$allowed_tags
		) . '</p>';

		wp_add_privacy_policy_content(
			__( 'Cetus Image Converter & AI Alt Text', 'cetus-media-optimizer' ),
			$content
		);
	}
}
