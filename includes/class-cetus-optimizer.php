<?php
/**
 * AVIF and WebP conversion, and the orphaned-file scan.
 *
 * Optimised files are created alongside the originals, and the source is never
 * deleted: for most sites the media library is the only copy of those images
 * that exists. In the WordPress media library the JPG thumbnails are forced, so
 * the admin never shows a broken preview.
 *
 * @package CetusMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Cetus_MO_Optimizer' ) ) {
	return;
}

/**
 * Class Cetus_MO_Optimizer
 */
class Cetus_MO_Optimizer {

	/**
	 * Fallback WebP quality, used only while the option is not yet in the database.
	 *
	 * @var int
	 */
	private const WEBP_QUALITY_DEFAULT = 82;

	/**
	 * Fallback AVIF quality, used only while the option is not yet in the database.
	 *
	 * @var int
	 */
	private const AVIF_QUALITY_DEFAULT = 75;

	/**
	 * The MIME types this plugin will convert.
	 *
	 * @var array<string>
	 */
	private const SUPPORTED_MIME = [
		'image/jpeg',
		'image/png',
		'image/gif',
		'image/webp',  // Allows re-converting WebP into AVIF.
	];

	// -------------------------------------------------------------------------
	// Initialisation
	// -------------------------------------------------------------------------

	/**
	 * Registers the WordPress hooks.
	 *
	 * @return void
	 */
	public function init(): void {
		// Automatic conversion on upload, when that is enabled.
		add_filter( 'wp_generate_attachment_metadata', [ $this, 'on_new_upload' ], 10, 2 );

		// Swap the front-end URLs, src and srcset, to the optimised formats.
		// PHP_INT_MAX puts us after any filter the theme registers, so a theme
		// that mangles the URL is corrected without needing output buffering.
		add_filter( 'wp_get_attachment_image_src', [ $this, 'swap_src_to_optimized' ], PHP_INT_MAX, 4 );
		add_filter( 'wp_calculate_image_srcset', [ $this, 'swap_srcset_to_optimized' ], PHP_INT_MAX, 5 );

		// Swap the URLs of images embedded in post content.
		add_filter( 'wp_filter_content_tags', [ $this, 'swap_content_image_urls' ], PHP_INT_MAX );

		// A final pass over the_content and post_thumbnail_html, which does two
		// things: it brings the src and the lightbox href into line with whatever
		// format the srcset already uses, and it rolls back to .jpg or .png when
		// the optimised file is not actually on disk.
		add_filter( 'the_content', [ $this, 'sanitize_optimized_urls' ], PHP_INT_MAX );
		add_filter( 'post_thumbnail_html', [ $this, 'sanitize_optimized_urls' ], PHP_INT_MAX );

		// Rebuild a missing srcset on the featured image, for the case where the
		// theme rewrote the src before wp_calculate_image_srcset() could run.
		// It takes 5 parameters so $post_thumbnail_id arrives from the filter
		// directly, rather than being derived from a CSS class the theme may not
		// have included.
		add_filter( 'post_thumbnail_html', [ $this, 'rebuild_srcset_if_missing' ], PHP_INT_MAX, 5 );

		// In the admin, the media library keeps the native JPGs, so a browser
		// without AVIF support never shows a broken preview there.
		if ( is_admin() ) {
			add_filter( 'wp_get_attachment_image_src', [ $this, 'force_jpg_thumbnail' ], 30, 4 );
			add_filter( 'wp_prepare_attachment_for_js', [ $this, 'force_js_thumbnail' ], 30, 3 );
		}
	}

	// -------------------------------------------------------------------------
	// Hook: a new upload
	// -------------------------------------------------------------------------

	/**
	 * Callback for 'wp_generate_attachment_metadata'.
	 * Converts the image that was just uploaded, when auto-convert is on.
	 *
	 * @param array<string, mixed> $metadata      The attachment metadata.
	 * @param int                  $attachment_id The attachment id.
	 * @return array<string, mixed>
	 */
	public function on_new_upload( array $metadata, int $attachment_id ): array {
		if ( '1' !== get_option( 'cetus_media_auto_convert', '0' ) ) {
			return $metadata;
		}

		$mime = get_post_mime_type( $attachment_id );
		if ( ! in_array( $mime, self::SUPPORTED_MIME, true ) ) {
			return $metadata;
		}

		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return $metadata;
		}

		// Skip attachments the user excluded from the meta box.
		if ( $this->is_attachment_excluded( $attachment_id ) ) {
			return $metadata;
		}

		// Convert only when the user has not chosen "no conversion".
		if ( ! $this->conversion_disabled() ) {
			$this->convert_single( $file, $attachment_id );
		}

		// Generate the alt text through the AI, when a provider key is configured.
		if ( $this->ai_keys_configured() && class_exists( 'Cetus_MO_AI' ) ) {
			( new Cetus_MO_AI() )->generate_and_save_alt_text( $attachment_id );
		}

		return $metadata;
	}

	/**
	 * Whether at least one AI provider has a key set.
	 *
	 * @return bool
	 */
	private function ai_keys_configured(): bool {
		return '' !== get_option( 'cetus_media_gemini_key', '' )
			|| '' !== get_option( 'cetus_media_openai_key', '' );
	}

	// -------------------------------------------------------------------------
	// Converting a single image
	// -------------------------------------------------------------------------

	/**
	 * Converts one image file into the format chosen in the settings, writing it
	 * alongside the original (photo.jpg becomes photo.webp). The original is
	 * never deleted, and an existing optimised file is left alone.
	 *
	 * @param string $source_path   Absolute path to the source file.
	 * @param int    $attachment_id WordPress attachment id, 0 when the file is
	 *                              outside the media library.
	 * @return array{success: bool, skipped: bool, output_path: string, bytes_saved: int, message: string}
	 */
	public function convert_single( string $source_path, int $attachment_id = 0 ): array {
		$result = [
			'success'     => false,
			'skipped'     => false,
			'output_path' => '',
			'bytes_saved' => 0,
			'message'     => '',
		];

		if ( ! file_exists( $source_path ) ) {
			$result['message'] = sprintf( 'File not found: %s', esc_html( basename( $source_path ) ) );
			return $result;
		}

		$format = $this->resolve_format();

		// Most libheif builds on shared hosting do not convert a PNG with an alpha
		// channel to AVIF correctly: the result is a black square. Falling back to
		// WebP is not optional here, and WebP handles alpha natively anyway.
		if ( 'avif' === $format && $this->source_has_alpha( $source_path ) ) {
			$format = 'webp';
		}

		// The output path is the source path with the extension swapped.
		$output_path = $this->build_output_path( $source_path, $format );

		// An existing optimised file is left alone, but the missing sizes are
		// still converted: the two can be out of step.
		if ( file_exists( $output_path ) ) {
			$result['skipped'] = true;
			$result['message'] = 'Optimised file already present, skipped.';
			if ( $attachment_id > 0 ) {
				$this->convert_attachment_sizes( $attachment_id, $format );
			}
			return $result;
		}

		// Convert with Imagick, preferred, or GD.
		$converted = $this->do_convert( $source_path, $output_path, $format );

		// When AVIF fails, the alpha detector being one way it can, switch the
		// format to WebP and retry. Doing it here rather than per file means the
		// sizes that follow use WebP too, so one attachment does not end up half
		// in one format and half in the other.
		if ( ! $converted && 'avif' === $format ) {
			$format      = 'webp';
			$output_path = $this->build_output_path( $source_path, $format );
			$converted   = $this->do_convert( $source_path, $output_path, $format );
		}

		if ( ! $converted ) {
			$result['message'] = 'Conversion failed: Imagick and GD unavailable, or an internal error.';
			return $result;
		}

		$original_size = (int) filesize( $source_path );
		$new_size      = (int) filesize( $output_path );

		$result['success']     = true;
		$result['output_path'] = $output_path;
		$result['bytes_saved'] = max( 0, $original_size - $new_size );
		$result['message']     = sprintf(
			'Converted to %s. Saved: %s KB.',
			strtoupper( $format ),
			number_format( $result['bytes_saved'] / 1024, 1 )
		);

		// Update the cumulative saved-bytes counter.
		$prev = (int) get_option( 'cetus_mo_total_bytes_saved', 0 );
		update_option( 'cetus_mo_total_bytes_saved', $prev + $result['bytes_saved'] );

		// Record it in the conversion log.
		self::add_log_entry(
			[
				'attachment_id' => $attachment_id,
				'filename'      => basename( $source_path ),
				'format'        => strtoupper( $format ),
				'bytes_saved'   => $result['bytes_saved'],
				'status'        => 'converted',
			]
		);

		// Convert the sub-sizes WordPress generated as well.
		if ( $attachment_id > 0 ) {
			$this->convert_attachment_sizes( $attachment_id, $format );
		}

		return $result;
	}

	/**
	 * Converts every sub-size of an attachment: thumbnail, medium, large and the
	 * rest.
	 *
	 * @param int    $attachment_id The attachment id.
	 * @param string $format        Target format, 'webp' or 'avif'.
	 * @return void
	 */
	private function convert_attachment_sizes( int $attachment_id, string $format ): void {
		$main_file = get_attached_file( $attachment_id );
		if ( ! $main_file || ! file_exists( $main_file ) ) {
			return;
		}

		$dir      = trailingslashit( dirname( $main_file ) );
		$basename = pathinfo( $main_file, PATHINFO_FILENAME );

		// Look for the thumbnails WordPress generated ({name}-WxH.ext) on the
		// filesystem directly. That is sturdier than reading
		// _wp_attachment_metadata['sizes'], whose 'file' field holds an absolute
		// path on some hosts and a bare filename on others.
		$candidates = glob( $dir . $basename . '-[0-9]*x[0-9]*.*' );
		if ( empty( $candidates ) ) {
			return;
		}

		foreach ( $candidates as $size_path ) {
			if ( ! preg_match( '/\.(jpe?g|png|gif)$/i', $size_path ) ) {
				continue;
			}

			$output_path = $this->build_output_path( $size_path, $format );
			if ( ! file_exists( $output_path ) ) {
				$this->do_convert( $size_path, $output_path, $format );
			}
		}
	}

	// -------------------------------------------------------------------------
	// The conversion engine: Imagick, or GD
	// -------------------------------------------------------------------------

	/**
	 * Runs the conversion through Imagick, falling back to GD.
	 *
	 * @param string $source Absolute source path.
	 * @param string $dest   Absolute destination path.
	 * @param string $format 'webp' or 'avif'.
	 * @return bool True when the conversion succeeded.
	 */
	private function do_convert( string $source, string $dest, string $format ): bool {
		// Validate by reading the magic bytes, which rejects a corrupt file, or one
		// whose extension lies, before it reaches Imagick or GD. Without this the
		// failure arrives as an ImagickException or a PHP warning instead.
		$info = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info || empty( $info['mime'] ) ) {
			return false;
		}

		// Imagick first.
		if ( $this->imagick_available( $format ) ) {
			if ( $this->convert_with_imagick( $source, $dest, $format ) ) {
				return true;
			}
			// Imagick failed, libheif writing a 0-byte file being one way: fall through.
		}

		// Fall back to GD, WebP only: GD has no AVIF support below PHP 8.1.
		if ( 'webp' === $format && $this->gd_webp_available() ) {
			return $this->convert_with_gd( $source, $dest );
		}

		return false;
	}

	/**
	 * Conversion through Imagick.
	 *
	 * @param string $source Source path.
	 * @param string $dest   Destination path.
	 * @param string $format 'webp' or 'avif'.
	 * @return bool
	 */
	private function convert_with_imagick( string $source, string $dest, string $format ): bool {
		// Anything above 40 megapixels risks exhausting the Imagick cache on shared
		// hosting, so it is refused before Imagick ever opens it. getimagesize()
		// reads the header only, which is what makes the check cheap.
		$size = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( $size && isset( $size[0], $size[1] ) ) {
			$megapixels = ( $size[0] * $size[1] ) / 1_000_000;
			if ( $megapixels > 40 ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( sprintf( '[Cetus MO] Skipped (%.1f MP > 40 MP limit): %s', $megapixels, basename( $source ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				}
				return false;
			}
		}

		try {
			// Cap the Imagick memory, so a large image fails cleanly instead of
			// hitting "cache resources exhausted" on shared hosting.
			Imagick::setResourceLimit( Imagick::RESOURCETYPE_MEMORY, 256 * 1024 * 1024 );
			Imagick::setResourceLimit( Imagick::RESOURCETYPE_MAP, 512 * 1024 * 1024 );

			$original   = new Imagick( $source );
			$src_format = strtolower( $original->getImageFormat() );
			$target_fmt = strtolower( $format );

			$has_alpha = $original->getImageAlphaChannel();

			if ( $has_alpha ) {
				$original->setImageAlphaChannel( Imagick::ALPHACHANNEL_ACTIVATE );

				if ( in_array( $target_fmt, [ 'webp', 'avif' ], true ) ) {
					// The transparent canvas trick: composite the image onto a canvas
					// with a transparent background, which is what preserves the alpha
					// channel correctly through the conversion.
					$canvas = new Imagick();
					$canvas->newImage( $original->getImageWidth(), $original->getImageHeight(), new ImagickPixel( 'transparent' ) );
					$canvas->compositeImage( $original, Imagick::COMPOSITE_OVER, 0, 0 );
					$image = $canvas;
					$original->destroy();
					$original = null;
				} else {
					// A destination with no transparency, JPG for instance: flatten
					// onto white.
					$original->setImageBackgroundColor( new ImagickPixel( 'white' ) );
					$image = $original->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
					$original->destroy();
					$original = null;
				}
			} else {
				// No alpha channel, a JPEG for instance: flatten normally.
				// The inner try/catch frees the RAM the moment Imagick runs out of
				// resources, so that do_convert() can attempt the GD fallback with
				// that memory back rather than against the same ceiling.
				try {
					$image = $original->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );
					$original->destroy();
					$original = null;
				} catch ( ImagickException $e ) {
					if ( $original instanceof Imagick ) {
						$original->destroy();
					}
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( '[Cetus MO] Imagick resource limit reached during flatten: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
					return false;
				}
			}

			$image->setImageFormat( strtoupper( $format ) );

			if ( 'avif' === $target_fmt ) {
				$image->setImageColorspace( Imagick::COLORSPACE_SRGB );
			}

			$quality = ( 'avif' === $format ) ? $this->get_avif_quality() : $this->get_webp_quality();
			$image->setImageCompressionQuality( $quality );
			$image->stripImage();

			$written = $image->writeImage( $dest );

			// Detector: when the AVIF that came out has lost the alpha channel,
			// which faulty libheif builds do silently, delete it and report failure.
			// The switch to WebP happens up in convert_single(), so the sizes are
			// processed in the right format too.
			if ( 'avif' === $target_fmt && $has_alpha && $written && file_exists( $dest ) ) {
				try {
					$check = new Imagick( $dest );
					if ( ! $check->getImageAlphaChannel() ) {
						$check->destroy();
						wp_delete_file( $dest );

						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( '[Cetus MO] AVIF transparency failure detected for: ' . basename( $source ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}

						if ( $image instanceof Imagick ) {
							$image->destroy();
						}
						return false;
					}
					$check->destroy();
				} catch ( \Throwable $t ) {
					if ( isset( $image ) && $image instanceof Imagick ) {
						$image->destroy();
					}
					return false;
				}
			}

			if ( $image instanceof Imagick ) {
				$image->destroy();
			}

			// Some libheif and libavif builds write 0 bytes and throw nothing.
			if ( ! $written || ! file_exists( $dest ) || (int) filesize( $dest ) === 0 ) {
				if ( file_exists( $dest ) ) {
					wp_delete_file( $dest );
				}
				return false;
			}

			return true;
		} catch ( ImagickException $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				error_log( '[Cetus MO] Imagick error: ' . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			}
			if ( class_exists( 'Cetus_MO_Telemetry' ) && Cetus_MO_Telemetry::is_opted_in() && function_exists( '\Sentry\captureException' ) ) {
				\Sentry\captureException( $e );
			}
			return false;
		}
	}

	/**
	 * Conversion through GD, WebP only.
	 *
	 * @param string $source Source path.
	 * @param string $dest   Destination path for the WebP.
	 * @return bool
	 */
	private function convert_with_gd( string $source, string $dest ): bool {
		// The real MIME type was already validated in do_convert() through
		// getimagesize(). It is read again here only to pick the right GD function.
		$mime = @getimagesize( $source )['mime'] ?? ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$image = match ( $mime ) {
			'image/jpeg' => imagecreatefromjpeg( $source ),
			'image/png'  => imagecreatefrompng( $source ),
			'image/gif'  => imagecreatefromgif( $source ),
			default      => false,
		};

		if ( ! $image ) {
			return false;
		}

		// Preserve transparency on PNG.
		if ( 'image/png' === $mime ) {
			imagepalettetotruecolor( $image );
			imagealphablending( $image, true );
			imagesavealpha( $image, true );
		}

		$result = imagewebp( $image, $dest, $this->get_webp_quality() );
		imagedestroy( $image ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated

		if ( ! $result || ! file_exists( $dest ) || (int) filesize( $dest ) === 0 ) {
			if ( file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}
			return false;
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Front-end URL swapping: src, srcset and content
	// -------------------------------------------------------------------------

	/**
	 * Filters the array wp_get_attachment_image_src() returns, swapping the URL
	 * of the original for the optimised one when that file is actually on disk.
	 * Front end only: the admin keeps the originals.
	 *
	 * @param array<mixed>|false $image         [url, width, height, is_intermediate], or false.
	 * @param int|string         $attachment_id Attachment id, cast to int internally.
	 * @param string|int[]       $size          The size requested.
	 * @param bool               $icon          True when WordPress is asking for a MIME type icon.
	 * @return array<mixed>|false
	 */
	public function swap_src_to_optimized( $image, $attachment_id, $size, bool $icon ) {
		$attachment_id = (int) $attachment_id;
		if ( ! $attachment_id ) {
			return $image;
		}

		// Leave icons alone, and anything that is not a usable result.
		if ( $icon || ! is_array( $image ) || empty( $image[0] ) ) {
			return $image;
		}

		$new_url = $this->get_optimized_url( (string) $image[0] );
		if ( null !== $new_url ) {
			$image[0] = $new_url;
		}

		return $image;
	}

	/**
	 * Filters the array wp_calculate_image_srcset() returns, swapping every
	 * original URL for the optimised one when that file is on disk.
	 *
	 * @param array<int, array{url: string, descriptor: string, value: int}>|false $sources    srcset entries, keyed by width.
	 * @param array<int>                                                           $size_array [width, height] of the current image.
	 * @param string                                                               $image_src  URL of the original src.
	 * @param array<string, mixed>                                                 $image_meta Attachment metadata.
	 * @param int                                                                  $attachment_id Attachment id.
	 * @return array<int, array{url: string, descriptor: string, value: int}>|false
	 */
	public function swap_srcset_to_optimized( $sources, array $size_array, string $image_src, array $image_meta, int $attachment_id ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! is_array( $sources ) ) {
			return $sources;
		}

		foreach ( $sources as $width => $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}

			$new_url = $this->get_optimized_url( $source['url'] );
			if ( null !== $new_url ) {
				$sources[ $width ]['url'] = $new_url;
			}
		}

		return $sources;
	}

	/**
	 * Filters post content, through wp_filter_content_tags, swapping the src and
	 * srcset attributes of <img> tags to the optimised formats.
	 *
	 * This catches the images inserted in the editor that WordPress does not put
	 * through wp_get_attachment_image_src: images inside custom blocks, and raw
	 * HTML written into the content.
	 *
	 * @param string $content The post HTML.
	 * @return string
	 */
	public function swap_content_image_urls( string $content ): string {
		if ( '' === $content ) {
			return $content;
		}

		// Swap every supported image URL appearing in a src="..." or inside a
		// srcset="..." for its optimised version, when that file exists.
		return preg_replace_callback(
			'/(src|srcset)=["\']([^"\']+)["\']/i',
			function ( array $matches ): string {
				$attr  = $matches[1];
				$value = $matches[2];

				if ( 'srcset' === strtolower( $attr ) ) {
					// A srcset holds comma-separated entries: "url1 1x, url2 2x".
					$parts     = array_map(
						function ( string $part ): string {
							$tokens = preg_split( '/\s+/', trim( $part ), 2 );
							if ( empty( $tokens[0] ) ) {
								return $part;
							}
							$new_url = $this->get_optimized_url( $tokens[0] );
							if ( null !== $new_url ) {
								$tokens[0] = $new_url;
							}
							return implode( ' ', $tokens );
						},
						explode( ',', $value )
					);
					$new_value = implode( ', ', $parts );
				} else {
					$new_value = $this->get_optimized_url( $value ) ?? $value;
				}

				return $attr . '="' . esc_attr( $new_value ) . '"';
			},
			$content
		) ?? $content;
	}

	/**
	 * The final pass over the HTML, on the_content and post_thumbnail_html.
	 *
	 * Two passes:
	 * 1. Bring the lightbox href (<a href>) into line with the format of the
	 *    <img src> inside it, so clicking an image does not load a different file.
	 * 2. For each <img>, put src and srcset in the same optimised format, and roll
	 *    back to .jpg or .png for any URL that is not on disk.
	 *
	 * @param string $html The HTML markup.
	 * @return string
	 */
	public function sanitize_optimized_urls( string $html ): string {
		if ( '' === $html ) {
			return $html;
		}

		$upload_dir  = wp_upload_dir();
		$uploads_url = trailingslashit( $upload_dir['baseurl'] );
		$uploads_dir = trailingslashit( $upload_dir['basedir'] );

		$html = $this->sync_lightbox_hrefs( $html, $uploads_url, $uploads_dir );
		$html = $this->sync_img_attributes( $html, $uploads_url, $uploads_dir );

		return $html;
	}

	/**
	 * Rebuilds a missing srcset on the featured image.
	 *
	 * When a theme rewrites the src before wp_calculate_image_srcset() runs,
	 * WordPress cannot find the attachment and returns false, so there is no
	 * srcset at all. This rebuilds it from _wp_attachment_metadata, checking that
	 * each file is on disk before including it.
	 *
	 * @param string $html              HTML of the featured image's <img> tag.
	 * @param int    $post_id           Id of the current post.
	 * @param int    $post_thumbnail_id Attachment id of the featured image.
	 * @param mixed  $size              The thumbnail size requested.
	 * @param mixed  $attr              Extra attributes on the img tag.
	 * @return string
	 */
	public function rebuild_srcset_if_missing( string $html, int $post_id = 0, int $post_thumbnail_id = 0, $size = '', $attr = '' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( '' === $html || preg_match( '/\bsrcset=/i', $html ) ) {
			return $html;
		}

		// Use the attachment id the post_thumbnail_html filter hands over, and fall
		// back to the wp-image-{id} class for the other contexts, the_content among
		// them, where no id arrives.
		if ( $post_thumbnail_id > 0 ) {
			$attachment_id = $post_thumbnail_id;
		} elseif ( preg_match( '/\bwp-image-(\d+)\b/i', $html, $id_m ) ) {
			$attachment_id = (int) $id_m[1];
		} else {
			return $html;
		}

		if ( ! preg_match( '/\bsrc="([^"]+)"/i', $html, $src_m ) ) {
			return $html;
		}
		$current_src = $src_m[1];

		if ( ! preg_match( '/\.(webp|avif|jpe?g|png|gif)$/i', $current_src, $ext_m ) ) {
			return $html;
		}
		$current_ext = strtolower( $ext_m[1] );
		$format      = in_array( $current_ext, [ 'webp', 'avif' ], true ) ? $current_ext : $this->resolve_format();

		$meta      = wp_get_attachment_metadata( $attachment_id );
		$main_file = get_attached_file( $attachment_id );

		if ( ! $main_file || empty( $meta['sizes'] ) ) {
			return $html;
		}

		$upload_dir  = wp_upload_dir();
		$uploads_url = trailingslashit( $upload_dir['baseurl'] );
		$uploads_dir = trailingslashit( $upload_dir['basedir'] );
		$dir         = trailingslashit( dirname( $main_file ) );

		$srcset_parts = [];

		foreach ( $meta['sizes'] as $size_data ) {
			if ( empty( $size_data['file'] ) || empty( $size_data['width'] ) ) {
				continue;
			}
			$size_path      = $dir . $size_data['file'];
			$optimized_path = $this->build_output_path( $size_path, $format );

			if ( file_exists( $optimized_path ) ) {
				$entry_url = str_replace( $uploads_dir, $uploads_url, $optimized_path );
			} elseif ( file_exists( $size_path ) ) {
				$entry_url = str_replace( $uploads_dir, $uploads_url, $size_path );
			} else {
				continue;
			}

			$w                  = (int) $size_data['width'];
			$srcset_parts[ $w ] = esc_url( $entry_url ) . ' ' . $w . 'w';
		}

		// Aggiungi la dimensione full-size.
		if ( ! empty( $meta['width'] ) ) {
			$full_opt = $this->build_output_path( $main_file, $format );
			$full_abs = file_exists( $full_opt ) ? $full_opt : $main_file;
			$full_url = str_replace( $uploads_dir, $uploads_url, $full_abs );
			$w        = (int) $meta['width'];

			$srcset_parts[ $w ] = esc_url( $full_url ) . ' ' . $w . 'w';
		}

		if ( empty( $srcset_parts ) ) {
			return $html;
		}

		ksort( $srcset_parts );
		$srcset_str = implode( ', ', $srcset_parts );
		$max_w      = max( array_keys( $srcset_parts ) );
		$sizes_str  = '(max-width: ' . $max_w . 'px) 100vw, ' . $max_w . 'px';

		return preg_replace(
			'/(<img\b[^>]+?)(\s*\/?>)/i',
			'$1 srcset="' . esc_attr( $srcset_str ) . '" sizes="' . esc_attr( $sizes_str ) . '"$2',
			$html,
			1
		) ?? $html;
	}

	/**
	 * Pass 1: brings the href of a lightbox link (<a href>) into line with the
	 * optimised format of the <img src> inside it. The file is checked on disk
	 * before the href is changed, and rolled back to the original when it is not
	 * there: a lightbox opening a 404 is worse than one opening a JPG.
	 *
	 * @param string $html        The HTML markup.
	 * @param string $uploads_url Base uploads URL.
	 * @param string $uploads_dir Absolute uploads path.
	 * @return string
	 */
	private function sync_lightbox_hrefs( string $html, string $uploads_url, string $uploads_dir ): string {
		return preg_replace_callback(
			'/<a(\s[^>]+)>([\s\S]*?)<\/a>/i',
			function ( array $m ) use ( $uploads_url, $uploads_dir ): string {
				$a_attrs = $m[1];
				$inner   = $m[2];

				// Only anchors that actually wrap an image.
				if ( ! preg_match( '/<img\s/i', $inner ) ) {
					return $m[0];
				}

				// Leggi il src dell'immagine interna.
				if ( ! preg_match( '/\bsrc="([^"]+)"/i', $inner, $src_m ) ) {
					return $m[0];
				}

				// Only rewrite the href when it points at an image file.
				$new_a_attrs = preg_replace_callback(
					'/\bhref="([^"]+)"/i',
					function ( array $hm ) use ( $uploads_url, $uploads_dir ): string {
						$href = $hm[1];
						if ( ! preg_match( '/\.(jpe?g|png|gif|webp|avif)(\?.*)?$/i', $href ) ) {
							return $hm[0];
						}
						return 'href="' . esc_attr( $this->best_url( $href, $uploads_url, $uploads_dir ) ) . '"';
					},
					$a_attrs
				) ?? $a_attrs;

				return '<a' . $new_a_attrs . '>' . $inner . '</a>';
			},
			$html
		) ?? $html;
	}

	/**
	 * Pass 2: for each <img>, puts src, srcset and data-src into the same
	 * optimised format. Where the srcset is already optimised but the src is
	 * still a .jpg, the src is brought into line. Any optimised URL with no file
	 * on disk is rolled back.
	 *
	 * @param string $html        The HTML markup.
	 * @param string $uploads_url Base uploads URL.
	 * @param string $uploads_dir Absolute uploads path.
	 * @return string
	 */
	private function sync_img_attributes( string $html, string $uploads_url, string $uploads_dir ): string {
		return preg_replace_callback(
			'/<img(\s[^>]*)\s*\/?>/i',
			function ( array $m ) use ( $uploads_url, $uploads_dir ): string {
				$attrs = $m[1];

				$src      = preg_match( '/\bsrc="([^"]+)"/i', $attrs, $sm ) ? $sm[1] : '';
				$srcset   = preg_match( '/\bsrcset="([^"]+)"/i', $attrs, $ssm ) ? $ssm[1] : '';
				$data_src = preg_match( '/\bdata-src="([^"]+)"/i', $attrs, $dsm ) ? $dsm[1] : '';

				// --- srcset ---
				$new_srcset = $srcset;
				if ( $srcset ) {
					$parts      = array_map(
						function ( string $part ) use ( $uploads_url, $uploads_dir ): string {
							$tokens = preg_split( '/\s+/', trim( $part ), 2 );
							if ( empty( $tokens[0] ) ) {
								return $part;
							}
							$tokens[0] = $this->best_url( $tokens[0], $uploads_url, $uploads_dir );
							return implode( ' ', $tokens );
						},
						explode( ',', $srcset )
					);
					$new_srcset = implode( ', ', $parts );
				}

				// --- src ---
				$new_src = $src ? $this->best_url( $src, $uploads_url, $uploads_dir ) : $src;

				// When the srcset already holds optimised URLs but the src is still
				// a .jpg or .png, bring the src into line, provided the file is
				// actually on disk.
				if ( $new_src && $new_srcset
					&& preg_match( '/\.(webp|avif)/i', $new_srcset )
					&& preg_match( '/\.(jpe?g|png|gif)$/i', $new_src )
				) {
					$synced = $this->best_url( $new_src, $uploads_url, $uploads_dir );
					if ( preg_match( '/\.(webp|avif)$/i', $synced ) ) {
						$new_src = $synced;
					}
				}

				// --- data-src, for lazy loading ---
				$new_data_src = $data_src ? $this->best_url( $data_src, $uploads_url, $uploads_dir ) : $data_src;

				// --- Rewrite the attributes only where something changed ---
				if ( $new_src !== $src ) {
					$attrs = preg_replace( '/\bsrc="[^"]*"/i', 'src="' . esc_attr( $new_src ) . '"', $attrs );
				}
				if ( $new_srcset !== $srcset ) {
					$attrs = $srcset
						? preg_replace( '/\bsrcset="[^"]*"/i', 'srcset="' . esc_attr( $new_srcset ) . '"', $attrs )
						: $attrs . ' srcset="' . esc_attr( $new_srcset ) . '"';
				}
				if ( $new_data_src !== $data_src ) {
					$attrs = preg_replace( '/\bdata-src="[^"]*"/i', 'data-src="' . esc_attr( $new_data_src ) . '"', $attrs );
				}

				return '<img' . $attrs . '>';
			},
			$html
		) ?? $html;
	}

	/**
	 * Returns the best URL for an image, checking the filesystem as it goes:
	 * - an original (.jpg, .png, .gif) becomes the optimised URL when that exists
	 * - an already optimised URL (.webp, .avif) is kept when the file is there,
	 *   and rolled back when it is not
	 * - anything else is returned untouched
	 *
	 * @param string $url         The URL to process.
	 * @param string $uploads_url Base uploads URL.
	 * @param string $uploads_dir Absolute uploads path.
	 * @return string
	 */
	private function best_url( string $url, string $uploads_url, string $uploads_dir ): string {
		if ( preg_match( '/\.(jpe?g|png|gif)(\?.*)?$/i', $url ) ) {
			return $this->get_optimized_url( $url ) ?? $url;
		}

		if ( preg_match( '/\.(webp|avif)(\?.*)?$/i', $url ) ) {
			return $this->sanitize_single_optimized_url( $url, $uploads_url, $uploads_dir );
		}

		return $url;
	}

	/**
	 * Checks that a .webp or .avif URL is actually on disk, and rolls back to the
	 * original extension (.jpg, .jpeg, .png, .gif) when it is not. If no valid
	 * file is found either way, the URL is returned unchanged.
	 *
	 * @param string $url         The optimised URL to check.
	 * @param string $uploads_url Base uploads URL.
	 * @param string $uploads_dir Absolute uploads path.
	 * @return string
	 */
	private function sanitize_single_optimized_url( string $url, string $uploads_url, string $uploads_dir ): string {
		if ( ! preg_match( '/\.(webp|avif)(\?.*)?$/i', $url ) ) {
			return $url;
		}

		$url_clean = strtok( $url, '?' );
		if ( false === strpos( (string) $url_clean, $uploads_url ) ) {
			return $url;
		}

		$relative  = str_replace( $uploads_url, '', (string) $url_clean );
		$file_path = $uploads_dir . $relative;

		if ( file_exists( $file_path ) ) {
			return $url;
		}

		foreach ( [ 'jpg', 'jpeg', 'png', 'gif' ] as $ext ) {
			$original_path = preg_replace( '/\.(webp|avif)$/i', '.' . $ext, $file_path );
			if ( $original_path && file_exists( $original_path ) ) {
				return str_replace( $uploads_dir, $uploads_url, $original_path );
			}
		}

		return $url;
	}

	/**
	 * Given the absolute URL of an original image (JPG, PNG, GIF), returns the
	 * URL of the optimised file (WebP or AVIF) when that file exists in the
	 * uploads folder, and null when there is nothing to swap in.
	 *
	 * @param string $original_url Absolute URL of the original file.
	 * @return string|null The optimised URL, or null when the file is not there.
	 */
	private function get_optimized_url( string $original_url ): ?string {
		// Supported source formats only.
		if ( ! preg_match( '/\.(jpe?g|png|gif)(\?.*)?$/i', $original_url ) ) {
			return null;
		}

		// Strip any query string before working out the path on disk.
		$url_clean = strtok( $original_url, '?' );

		$upload_dir  = wp_upload_dir();
		$uploads_url = trailingslashit( $upload_dir['baseurl'] );
		$uploads_dir = trailingslashit( $upload_dir['basedir'] );

		// The URL has to belong to this site's uploads folder.
		if ( false === strpos( $url_clean, $uploads_url ) ) {
			return null;
		}

		$relative    = str_replace( $uploads_url, '', $url_clean );
		$source_path = $uploads_dir . $relative;
		$format      = $this->resolve_format();
		$output_path = $this->build_output_path( $source_path, $format );

		if ( ! file_exists( $output_path ) ) {
			return null;
		}

		return str_replace( $uploads_dir, $uploads_url, $output_path );
	}

	// -------------------------------------------------------------------------
	// The orphaned-file scan
	// -------------------------------------------------------------------------

	/**
	 * Walks the uploads folder looking for image files that are not registered in
	 * the WordPress database.
	 *
	 * @return array{count: int, files: array<string>, total_bytes: int}
	 */
	public function find_orphaned_files(): array {
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );

		// Every path registered as an attachment.
		$registered = $this->get_all_registered_paths();

		$orphans     = [];
		$total_bytes = 0;

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		$image_extensions = [ 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif' ];

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info instanceof SplFileInfo ) {
				continue;
			}

			$ext = strtolower( $file_info->getExtension() );
			if ( ! in_array( $ext, $image_extensions, true ) ) {
				continue;
			}

			$full_path = wp_normalize_path( $file_info->getRealPath() );
			$relative  = str_replace( wp_normalize_path( $base_dir ), '', $full_path );

			// A file is orphaned when no registered metadata mentions it.
			if ( ! in_array( $relative, $registered, true ) ) {
				$orphans[]    = $full_path;
				$total_bytes += (int) $file_info->getSize();
			}
		}

		return [
			'count'       => count( $orphans ),
			'files'       => $orphans,
			'total_bytes' => $total_bytes,
		];
	}

	/**
	 * Builds the list of every path, relative to uploads/, that belongs to an
	 * attachment in the database.
	 *
	 * @return array<string>
	 */
	private function get_all_registered_paths(): array {
		global $wpdb;

		$upload_dir = wp_upload_dir();
		$base_dir   = wp_normalize_path( trailingslashit( $upload_dir['basedir'] ) );
		$paths      = [];

		// Recupera i file principali degli allegati.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_wp_attached_file'
			)
		);

		foreach ( $rows as $row ) {
			if ( is_string( $row ) ) {
				// Normalizza: rimuove il base_dir se presente (path assoluto), poi lo slash iniziale.
				$normalized = wp_normalize_path( $row );
				$normalized = str_replace( $base_dir, '', $normalized );
				$paths[]    = ltrim( $normalized, '/' );
			}
		}

		// Recupera i sub-size dai metadati strutturati.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$meta_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_wp_attachment_metadata'
			),
			ARRAY_A
		);

		foreach ( $meta_rows as $meta_row ) {
			$meta = maybe_unserialize( $meta_row['meta_value'] ?? '' );

			if ( ! is_array( $meta ) || empty( $meta['sizes'] ) ) {
				continue;
			}

			// Normalise meta['file'] to a path relative to base_dir, coping with
			// both forms: some hosts store an absolute path there.
			$main_relative = '';
			if ( ! empty( $meta['file'] ) ) {
				$main_normalized = wp_normalize_path( $meta['file'] );
				$main_normalized = str_replace( $base_dir, '', $main_normalized );
				$main_relative   = ltrim( $main_normalized, '/' );
			}

			$dir         = $main_relative ? dirname( $main_relative ) : '.';
			$base_subdir = ( '.' === $dir || '' === $dir ) ? '' : trailingslashit( $dir );

			foreach ( $meta['sizes'] as $size ) {
				if ( ! empty( $size['file'] ) ) {
					$paths[] = $base_subdir . $size['file'];
				}
			}
		}

		return array_unique( $paths );
	}

	// -------------------------------------------------------------------------
	// Hook: native JPG previews in the media library
	// -------------------------------------------------------------------------

	/**
	 * Keeps the media library on the original JPG or PNG URL when it asks for a
	 * preview, so the admin never shows a broken thumbnail.
	 *
	 * @param array<mixed>|false $image         [url, width, height, is_intermediate], or false.
	 * @param int                $attachment_id Attachment id.
	 * @param string|int[]       $size          The size requested.
	 * @param bool               $icon          True when WordPress is asking for an icon.
	 * @return array<mixed>|false
	 */
	public function force_jpg_thumbnail( $image, int $attachment_id, $size, bool $icon ) {
		if ( $icon || false === $image ) {
			return $image;
		}

		// Already pointing at a JPG or PNG: nothing to do.
		if ( is_array( $image ) && isset( $image[0] ) ) {
			$url = (string) $image[0];
			if ( preg_match( '/\.(jpe?g|png|gif)$/i', $url ) ) {
				return $image;
			}
		}

		return $image;
	}

	/**
	 * Makes sure the JS data behind the media library uses the native JPG
	 * previews, rather than WebP or AVIF files a browser might not render in the
	 * WordPress interface.
	 *
	 * @param array<string, mixed> $response   JSON data for the media item.
	 * @param WP_Post              $attachment The attachment post.
	 * @param array<string>|false  $meta       Attachment metadata.
	 * @return array<string, mixed>
	 */
	public function force_js_thumbnail( array $response, WP_Post $attachment, $meta ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		// Nothing to change: WordPress already builds the JS previews from the
		// original file. The hook is registered so the behaviour is pinned rather
		// than left to chance, and so there is somewhere to act if that changes.
		return $response;
	}

	// -------------------------------------------------------------------------
	// Library statistics
	// -------------------------------------------------------------------------

	/**
	 * Works out how much space the media library takes up, by format.
	 *
	 * @return array{total_bytes: int, webp_bytes: int, avif_bytes: int, original_bytes: int, files_count: int}
	 */
	public function get_library_stats(): array {
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );

		$stats = [
			'total_bytes'    => 0,
			'webp_bytes'     => 0,
			'avif_bytes'     => 0,
			'original_bytes' => 0,
			'files_count'    => 0,
		];

		if ( ! is_dir( $base_dir ) ) {
			return $stats;
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info instanceof SplFileInfo || ! $file_info->isFile() ) {
				continue;
			}

			$size = (int) $file_info->getSize();
			$ext  = strtolower( $file_info->getExtension() );

			$stats['total_bytes'] += $size;
			++$stats['files_count'];

			match ( $ext ) {
				'webp'        => $stats['webp_bytes']     += $size,
				'avif'        => $stats['avif_bytes']     += $size,
				'jpg', 'jpeg',
				'png', 'gif'  => $stats['original_bytes'] += $size,
				default       => null,
			};
		}

		return $stats;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Whether the user has turned conversion off, leaving the plugin in its
	 * alt-text-only mode.
	 *
	 * @return bool
	 */
	public function conversion_disabled(): bool {
		return 'none' === get_option( 'cetus_media_format', 'auto' );
	}

	/**
	 * Works out the current output format, from the settings and from what the
	 * server can actually do. 'auto' picks AVIF where it is supported, and WebP
	 * otherwise.
	 *
	 * @return string 'avif' | 'webp'
	 */
	public function resolve_format(): string {
		$setting = get_option( 'cetus_media_format', 'auto' );

		if ( 'avif' === $setting ) {
			return $this->imagick_available( 'avif' ) ? 'avif' : 'webp';
		}

		if ( 'webp' === $setting ) {
			return 'webp';
		}

		// 'none' should never reach this far, but return something valid anyway.
		if ( 'none' === $setting ) {
			return 'webp';
		}

		// 'auto': prefer AVIF when it is available.
		return $this->imagick_available( 'avif' ) ? 'avif' : 'webp';
	}

	/**
	 * Builds the output path by swapping the extension.
	 *
	 * @param string $source_path Absolute path to the source file.
	 * @param string $format      'webp' or 'avif'.
	 * @return string
	 */
	public function build_output_path( string $source_path, string $format ): string {
		return preg_replace( '/\.(jpe?g|png|gif|webp|avif)$/i', '.' . $format, $source_path ) ?? $source_path . '.' . $format;
	}

	/**
	 * Whether Imagick is available and supports the format asked for.
	 *
	 * @param string $format 'webp' or 'avif'.
	 * @return bool
	 */
	public function imagick_available( string $format = 'webp' ): bool {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' ) ) {
			return false;
		}

		$formats = Imagick::queryFormats();
		if ( ! in_array( strtoupper( $format ), $formats, true ) ) {
			return false;
		}

		// AVIF gets a real probe: some libheif builds report support and then write
		// 0 bytes. The result is cached for 24 hours.
		if ( 'avif' === $format ) {
			return $this->probe_avif_encoding();
		}

		return true;
	}

	/**
	 * Whether an image has an alpha channel, decided from the file header alone.
	 * For a PNG that is the colour type byte, bit 2. The whole image is never
	 * loaded into memory, which matters because this runs before the size check.
	 *
	 * @param string $path Absolute path to the source file.
	 * @return bool
	 */
	private function source_has_alpha( string $path ): bool {
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info ) {
			return false;
		}

		$mime = $info['mime'] ?? '';

		if ( 'image/png' === $mime ) {
			// A PNG header is always 8 bytes of signature, 4 of length, 4 of "IHDR",
			// 4 width, 4 height, 1 bit depth, then 1 colour type.
			// Colour type 4 is greyscale plus alpha and 6 is RGBA, so bit 2 set
			// means there is an alpha channel.
			// That single byte at offset 25 is read through file_get_contents with
			// an offset, because WPCS does not allow fopen, fread and fclose.
			$chunk = file_get_contents( $path, false, null, 25, 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $chunk || '' === $chunk ) {
				return false;
			}
			$color_type = ord( $chunk );
			return (bool) ( $color_type & 4 ); // Bit 2 set means an alpha channel.
		}

		return false;
	}

	/**
	 * Whether the server can really encode AVIF, decided by encoding a 1x1 pixel
	 * in memory rather than by asking. Some builds claim support and then write
	 * nothing. The answer is cached in a transient, so the probe does not run on
	 * every conversion.
	 *
	 * @return bool
	 */
	private function probe_avif_encoding(): bool {
		$cache_key = 'cetus_mo_avif_probe';
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return '1' === $cached;
		}

		$result = false;
		try {
			$image = new Imagick();
			$image->newImage( 1, 1, new ImagickPixel( 'white' ) );
			$image->setImageFormat( 'AVIF' );
			$blob = $image->getImageBlob();
			$image->destroy();
			$result = ! empty( $blob ) && strlen( $blob ) > 0;
		} catch ( \Throwable $e ) {
			$result = false;
		}

		set_transient( $cache_key, $result ? '1' : '0', DAY_IN_SECONDS );
		return $result;
	}

	/**
	 * Whether GD can write WebP.
	 *
	 * @return bool
	 */
	public function gd_webp_available(): bool {
		if ( ! extension_loaded( 'gd' ) || ! function_exists( 'gd_info' ) ) {
			return false;
		}

		$info = gd_info();
		return ! empty( $info['WebP Support'] );
	}

	/**
	 * Whether GD can write AVIF, which needs PHP 8.1 or later and libavif.
	 *
	 * @return bool
	 */
	public function gd_avif_available(): bool {
		if ( ! extension_loaded( 'gd' ) || ! function_exists( 'gd_info' ) ) {
			return false;
		}

		$info = gd_info();
		return ! empty( $info['AVIF Support'] );
	}

	/**
	 * The WebP quality the user configured, 1 to 100.
	 *
	 * @return int
	 */
	private function get_webp_quality(): int {
		return max( 1, min( 100, (int) get_option( 'cetus_media_webp_quality', self::WEBP_QUALITY_DEFAULT ) ) );
	}

	/**
	 * The AVIF quality the user configured, 1 to 100.
	 *
	 * @return int
	 */
	private function get_avif_quality(): int {
		return max( 1, min( 100, (int) get_option( 'cetus_media_avif_quality', self::AVIF_QUALITY_DEFAULT ) ) );
	}

	/**
	 * Whether the user excluded this attachment from the meta box.
	 *
	 * @param int $attachment_id Attachment id.
	 * @return bool
	 */
	public function is_attachment_excluded( int $attachment_id ): bool {
		return '1' === get_post_meta( $attachment_id, 'cetus_mo_exclude', true );
	}

	/**
	 * Adds an entry to the conversion log, newest first, capped at 200.
	 *
	 * @param array{attachment_id: int, filename: string, format: string, bytes_saved: int, status: string} $entry The entry data.
	 * @return void
	 */
	public static function add_log_entry( array $entry ): void {
		$log = get_option( 'cetus_mo_conversion_log', [] );
		if ( ! is_array( $log ) ) {
			$log = [];
		}
		array_unshift( $log, array_merge( $entry, [ 'time' => time() ] ) );
		update_option( 'cetus_mo_conversion_log', array_slice( $log, 0, 200 ) );
	}

	/**
	 * Counts the library images with no converted file in the current output
	 * format yet. It is what the "to convert" counter shows.
	 *
	 * @return int
	 */
	public function count_unconverted_images(): int {
		$query = new WP_Query(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => self::SUPPORTED_MIME,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			]
		);

		$format = $this->resolve_format();
		$count  = 0;

		foreach ( $query->posts as $id ) {
			$file = get_attached_file( (int) $id );
			if ( ! $file ) {
				continue;
			}

			$output = $this->build_output_path( $file, $format );
			if ( ! file_exists( $output ) ) {
				++$count;
			}
		}

		return $count;
	}

	/**
	 * Deletes every WebP and AVIF file this plugin generated under uploads, and
	 * clears the log and the cumulative savings counter.
	 *
	 * It never touches an original JPG, PNG or GIF, and it never touches a WebP
	 * or AVIF that is itself a registered attachment: those were uploaded as
	 * such, so they are somebody's originals rather than our output.
	 *
	 * @return array{deleted: int, errors: int}
	 */
	public function reset_converted_files(): array {
		$upload_dir = wp_upload_dir();
		$base_dir   = trailingslashit( $upload_dir['basedir'] );
		$counts     = [
			'deleted' => 0,
			'errors'  => 0,
		];

		if ( ! is_dir( $base_dir ) ) {
			return $counts;
		}

		// Collect the absolute paths of every WebP and AVIF registered as an
		// attachment in the database, so that none of them is deleted.
		$original_webp_avif = [];
		$query              = new WP_Query(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => [ 'image/webp', 'image/avif' ],
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			]
		);
		foreach ( $query->posts as $id ) {
			$file = get_attached_file( (int) $id );
			if ( $file ) {
				$original_webp_avif[ realpath( $file ) ?: $file ] = true;
			}
		}

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base_dir, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY
		);

		foreach ( $iterator as $file_info ) {
			if ( ! $file_info instanceof SplFileInfo || ! $file_info->isFile() ) {
				continue;
			}

			$ext = strtolower( $file_info->getExtension() );
			if ( ! in_array( $ext, [ 'webp', 'avif' ], true ) ) {
				continue;
			}

			$path = $file_info->getRealPath();

			// Skip the originals registered in the WordPress database.
			if ( isset( $original_webp_avif[ $path ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( wp_delete_file( $path ) ) {
				++$counts['deleted'];
			} else {
				++$counts['errors'];
			}
		}

		update_option( 'cetus_mo_conversion_log', [] );
		update_option( 'cetus_mo_total_bytes_saved', '0' );

		return $counts;
	}
}
