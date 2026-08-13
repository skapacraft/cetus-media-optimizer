<?php
/**
 * Multi-provider AI integration, for generating alt text automatically.
 *
 * Supports Google Gemini (v1beta) and OpenAI (gpt-4o-mini), with automatic
 * fallback between them on a 429 (quota exceeded). The generated text is saved
 * natively as '_wp_attachment_image_alt', so it stays usable if this plugin is
 * ever removed.
 *
 * The image is sent straight from the site to the provider whose key the
 * administrator entered. Nothing passes through a server of ours, and nothing
 * is sent until somebody starts a conversion.
 *
 * @package CetusMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Cetus_MO_AI' ) ) {
	return;
}

/**
 * Class Cetus_MO_AI
 */
class Cetus_MO_AI {

	/**
	 * The Gemini endpoint (v1beta).
	 *
	 * @var string
	 */
	private const GEMINI_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent';

	/**
	 * The OpenAI endpoint.
	 *
	 * @var string
	 */
	private const OPENAI_ENDPOINT = 'https://api.openai.com/v1/chat/completions';

	/**
	 * Which OpenAI model to use.
	 *
	 * @var string
	 */
	private const OPENAI_MODEL = 'gpt-4o-mini';

	/**
	 * Locale prefix to English language name, for the AI prompt.
	 * The model is always told the language in English, because that is what
	 * these models handle most reliably, whatever language the output is in.
	 *
	 * @var array<string, string>
	 */
	private const LANGUAGE_MAP = [
		'it' => 'Italian',
		'en' => 'English',
		'es' => 'Spanish',
		'fr' => 'French',
		'de' => 'German',
		'pt' => 'Portuguese',
		'nl' => 'Dutch',
		'ru' => 'Russian',
		'ja' => 'Japanese',
		'zh' => 'Chinese',
		'ar' => 'Arabic',
		'pl' => 'Polish',
		'sv' => 'Swedish',
		'da' => 'Danish',
		'fi' => 'Finnish',
		'nb' => 'Norwegian',
		'tr' => 'Turkish',
		'ko' => 'Korean',
		'cs' => 'Czech',
		'ro' => 'Romanian',
		'hu' => 'Hungarian',
		'el' => 'Greek',
	];

	// -------------------------------------------------------------------------
	// The dynamic, international prompt
	// -------------------------------------------------------------------------

	/**
	 * Builds the prompt sent to the model to generate the alt text.
	 *
	 * In order of precedence:
	 *   1. The custom prompt, when the user set one in Step 2.
	 *   2. The language the user picked in the Step 2 selector.
	 *   3. The current WordPress locale.
	 *   4. English, as the last resort.
	 *
	 * @return string
	 */
	private function get_prompt(): string {
		$custom = trim( (string) get_option( 'cetus_media_alt_text_prompt', '' ) );
		if ( '' !== $custom ) {
			return $custom;
		}

		$lang_code = trim( (string) get_option( 'cetus_media_alt_text_language', '' ) );
		if ( '' === $lang_code ) {
			$lang_code = $this->locale_to_lang_code( get_locale() );
		}

		$lang_name = self::LANGUAGE_MAP[ $lang_code ] ?? 'English';

		return sprintf(
			'Describe this image in %s with a single concise sentence suitable as an alt text for web accessibility. Return only the description, without any prefix or label.',
			$lang_name
		);
	}

	/**
	 * Turns a WordPress locale ('it_IT', 'fr_FR', 'zh_CN') into the two-letter
	 * language code LANGUAGE_MAP is keyed by.
	 *
	 * @param string $locale WordPress locale, 'it_IT' for example.
	 * @return string ISO 639-1 code such as 'it', or 'en' when unrecognised.
	 */
	private function locale_to_lang_code( string $locale ): string {
		$prefix = strtolower( substr( $locale, 0, 2 ) );
		return array_key_exists( $prefix, self::LANGUAGE_MAP ) ? $prefix : 'en';
	}

	// -------------------------------------------------------------------------
	// Alt text generation, the public entry point
	// -------------------------------------------------------------------------

	/**
	 * Generates and saves the alt text for a WordPress attachment.
	 *
	 * Uses the provider chosen in the settings, falling back to the other one
	 * automatically on a 429.
	 *
	 * An attachment that already has an alt text is left alone. Every provider
	 * call is billed, and this runs on hooks that fire more than once for the
	 * same image: wp_generate_attachment_metadata fires twice for a single
	 * upload, once with context 'create' and again with 'update' once the
	 * sub-sizes exist. That was already the case for big-image uploads, and from
	 * WordPress 7.1 it is the norm for every upload made from the editor, where
	 * the browser generates the sub-sizes and sideloads them.
	 *
	 * @param int $attachment_id The WordPress attachment id.
	 * @return array{success: bool, alt_text: string, provider_used: string, message: string}
	 */
	public function generate_and_save_alt_text( int $attachment_id ): array {
		$result = [
			'success'       => false,
			'alt_text'      => '',
			'provider_used' => '',
			'message'       => '',
		];

		$existing_alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		if ( '' !== trim( (string) $existing_alt ) ) {
			$result['alt_text'] = (string) $existing_alt;
			$result['message']  = 'Alt text già presente: generazione saltata.';
			return $result;
		}

		$image_path = get_attached_file( $attachment_id );
		if ( ! $image_path || ! file_exists( $image_path ) ) {
			$result['message'] = 'File immagine non trovato.';
			return $result;
		}

		// Read the image and encode it as Base64.
		$image_data = $this->encode_image( $image_path );
		if ( null === $image_data ) {
			$result['message'] = 'Impossibile leggere il file immagine.';
			return $result;
		}

		$mime_type        = $this->get_image_mime( $image_path );
		$primary_provider = get_option( 'cetus_media_ai_provider', 'gemini' );
		$fallback_enabled = '1' === get_option( 'cetus_media_ai_fallback', '1' );

		// First attempt, with the primary provider.
		[ $alt_text, $error_code, $error_msg ] = $this->call_provider(
			$primary_provider,
			$image_data,
			$mime_type
		);

		$provider_used = $primary_provider;

		// Automatic fallback on a 429.
		if ( null === $alt_text && 429 === $error_code && $fallback_enabled ) {
			$fallback_provider = ( 'gemini' === $primary_provider ) ? 'openai' : 'gemini';

			[ $alt_text, , $error_msg ] = $this->call_provider(
				$fallback_provider,
				$image_data,
				$mime_type
			);

			if ( null !== $alt_text ) {
				$provider_used = $fallback_provider;
			}
		}

		if ( null === $alt_text ) {
			$result['message'] = $error_msg ?: 'Nessun provider disponibile o errore sconosciuto.';
			return $result;
		}

		// Sanifica e salva l'alt text.
		$alt_text = sanitize_text_field( $alt_text );
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );

		$result['success']       = true;
		$result['alt_text']      = $alt_text;
		$result['provider_used'] = $provider_used;
		$result['message']       = sprintf(
			'Alt text generato tramite %s e salvato.',
			ucfirst( $provider_used )
		);

		return $result;
	}

	// -------------------------------------------------------------------------
	// Provider dispatch
	// -------------------------------------------------------------------------

	/**
	 * Calls the given provider and returns its result.
	 *
	 * @param string $provider   'gemini' | 'openai'.
	 * @param string $image_data The image, Base64 encoded.
	 * @param string $mime_type  The image MIME type, 'image/jpeg' for example.
	 * @return array{0: string|null, 1: int|null, 2: string} [alt_text, http_code, error_message]
	 */
	private function call_provider( string $provider, string $image_data, string $mime_type ): array {
		return match ( $provider ) {
			'gemini' => $this->call_gemini( $image_data, $mime_type ),
			'openai' => $this->call_openai( $image_data, $mime_type ),
			default  => [ null, null, sprintf( 'Provider "%s" not recognised.', esc_html( $provider ) ) ],
		};
	}

	// -------------------------------------------------------------------------
	// Google Gemini
	// -------------------------------------------------------------------------

	/**
	 * Calls the Google Gemini API to generate the alt text.
	 *
	 * @param string $image_data The image, Base64 encoded.
	 * @param string $mime_type  The image MIME type.
	 * @return array{0: string|null, 1: int|null, 2: string}
	 */
	private function call_gemini( string $image_data, string $mime_type ): array {
		$api_key = get_option( 'cetus_media_gemini_key', '' );

		if ( empty( $api_key ) ) {
			return [ null, null, 'Chiave API Gemini non configurata.' ];
		}

		$url  = add_query_arg( 'key', rawurlencode( $api_key ), self::GEMINI_ENDPOINT );
		$body = [
			'contents' => [
				[
					'parts' => [
						[
							'inlineData' => [
								'mimeType' => $mime_type,
								'data'     => $image_data,
							],
						],
						[
							'text' => $this->get_prompt(),
						],
					],
				],
			],
		];

		$response = wp_remote_post(
			$url,
			[
				'headers' => [ 'Content-Type' => 'application/json' ],
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ null, null, 'Errore HTTP Gemini: ' . $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $code ) {
			return [ null, 429, 'Quota Gemini esaurita (429). Verifica i limiti del tuo account.' ];
		}

		if ( 200 !== $code ) {
			return [ null, $code, sprintf( 'Gemini ha risposto con codice %d.', $code ) ];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return [ null, $code, 'Risposta Gemini vuota o in formato inatteso.' ];
		}

		return [ trim( $text ), $code, '' ];
	}

	// -------------------------------------------------------------------------
	// OpenAI
	// -------------------------------------------------------------------------

	/**
	 * Calls the OpenAI API (gpt-4o-mini) to generate the alt text.
	 *
	 * @param string $image_data The image, Base64 encoded.
	 * @param string $mime_type  The image MIME type.
	 * @return array{0: string|null, 1: int|null, 2: string}
	 */
	private function call_openai( string $image_data, string $mime_type ): array {
		$api_key = get_option( 'cetus_media_openai_key', '' );

		if ( empty( $api_key ) ) {
			return [ null, null, 'Chiave API OpenAI non configurata.' ];
		}

		$data_url = sprintf( 'data:%s;base64,%s', $mime_type, $image_data );

		$body = [
			'model'      => self::OPENAI_MODEL,
			'max_tokens' => 150,
			'messages'   => [
				[
					'role'    => 'user',
					'content' => [
						[
							'type'      => 'image_url',
							'image_url' => [ 'url' => $data_url ],
						],
						[
							'type' => 'text',
							'text' => $this->get_prompt(),
						],
					],
				],
			],
		];

		$response = wp_remote_post(
			self::OPENAI_ENDPOINT,
			[
				'headers' => [
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $api_key,
				],
				'body'    => wp_json_encode( $body ),
				'timeout' => 30,
			]
		);

		if ( is_wp_error( $response ) ) {
			return [ null, null, 'Errore HTTP OpenAI: ' . $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $code ) {
			return [ null, 429, 'Quota OpenAI esaurita (429). Verifica i limiti del tuo account.' ];
		}

		if ( 200 !== $code ) {
			return [ null, $code, sprintf( 'OpenAI ha risposto con codice %d.', $code ) ];
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = $data['choices'][0]['message']['content'] ?? null;

		if ( ! is_string( $text ) || '' === trim( $text ) ) {
			return [ null, $code, 'Risposta OpenAI vuota o in formato inatteso.' ];
		}

		return [ trim( $text ), $code, '' ];
	}

	// -------------------------------------------------------------------------
	// API key validation
	// -------------------------------------------------------------------------

	/**
	 * Checks an API key by making one test call.
	 *
	 * @param string $provider 'gemini' | 'openai'.
	 * @param string $api_key  The key to test.
	 * @return array{valid: bool, message: string}
	 */
	public function validate_api_key( string $provider, string $api_key ): array {
		if ( empty( $api_key ) ) {
			return [
				'valid'   => false,
				'message' => 'Chiave API vuota.',
			];
		}

		if ( 'gemini' === $provider ) {
			// Expected shape: AIzaSy... , 39 characters.
			if ( ! preg_match( '/^AIzaSy[A-Za-z0-9_-]{33}$/', $api_key ) ) {
				return [
					'valid'   => false,
					'message' => 'Formato chiave Gemini non valido (atteso: AIzaSy..., 39 caratteri).',
				];
			}
		}

		if ( 'openai' === $provider ) {
			// Expected shape: sk-... or sk-proj-...
			if ( ! preg_match( '/^sk-/', $api_key ) ) {
				return [
					'valid'   => false,
					'message' => 'Formato chiave OpenAI non valido (atteso: sk-...).',
				];
			}
		}

		return [
			'valid'   => true,
			'message' => 'Formato chiave valido.',
		];
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Reads the image file and returns it Base64 encoded.
	 *
	 * @param string $path Absolute path to the file.
	 * @return string|null Null when the file cannot be read, or exceeds 20 MB.
	 */
	private function encode_image( string $path ): ?string {
		// The vision APIs cap the payload at 20 MB.
		if ( filesize( $path ) > 20 * 1024 * 1024 ) {
			return null;
		}

		$content = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $content ) {
			return null;
		}

		return base64_encode( $content ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Returns an image file's MIME type, safely.
	 *
	 * @param string $path Absolute path to the file.
	 * @return string The MIME type, defaulting to 'image/jpeg'.
	 */
	private function get_image_mime( string $path ): string {
		$info = wp_check_filetype( $path );
		return is_string( $info['type'] ) && '' !== $info['type'] ? $info['type'] : 'image/jpeg';
	}
}
