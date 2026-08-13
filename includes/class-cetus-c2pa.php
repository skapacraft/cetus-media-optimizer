<?php
/**
 * Detection of C2PA manifests, the data behind "Content Credentials".
 *
 * Converting an image re-encodes its pixels, and that destroys any C2PA
 * manifest the file carried: the manifest holds a cryptographic hash of the
 * asset, so it cannot survive a re-encode and cannot simply be copied across
 * either. Copying it would produce a derivative that fails validation as
 * tampered, which is a worse outcome than having no provenance at all.
 *
 * This class only answers "is there a manifest in this file", so the plugin can
 * leave such a file alone, or at least say out loud what it is about to
 * destroy. It does no cryptographic validation whatsoever: a file that returns
 * true here may well carry a manifest that is expired, self-signed or invalid.
 * Presence is the only question, and presence is what the warning is about.
 *
 * The manifest lives in a different place in each container:
 *   JPEG  APP11 marker segments carrying a JUMBF box
 *   PNG   a 'caBX' ancillary chunk
 *   WebP  a 'C2PA' RIFF chunk
 *   AVIF  a top-level ISOBMFF 'uuid' box with the C2PA UUID
 *
 * @package CetusMediaOptimizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( class_exists( 'Cetus_MO_C2PA' ) ) {
	return;
}

/**
 * Class Cetus_MO_C2PA
 */
class Cetus_MO_C2PA {

	/**
	 * Post meta flag: conversion of this attachment was skipped because it
	 * carries a manifest and the guard is on.
	 *
	 * @var string
	 */
	public const META_SKIPPED = 'cetus_mo_c2pa_skipped';

	/**
	 * Post meta flag: this attachment carried a manifest and was converted
	 * anyway, so the provenance data is gone from the derivative.
	 *
	 * @var string
	 */
	public const META_STRIPPED = 'cetus_mo_c2pa_stripped';

	/**
	 * The UUID that marks a C2PA manifest store inside an ISOBMFF 'uuid' box,
	 * which is how AVIF and HEIF carry one.
	 *
	 * @var string
	 */
	private const BMFF_C2PA_UUID = "\xd8\xfe\xc3\xd6\x1b\x0e\x48\x3c\x92\x97\x58\x28\x87\x7e\xc4\x81";

	/**
	 * How many segments, chunks or boxes to walk before giving up. A manifest
	 * always sits near the front of a file, so a malformed or hostile file
	 * cannot turn this into a long loop.
	 *
	 * @var int
	 */
	private const MAX_RECORDS = 256;

	/**
	 * Memoised results, keyed by path, mtime and size. A single conversion run
	 * asks about the same file more than once.
	 *
	 * @var array<string, bool>
	 */
	private static array $cache = [];

	// -------------------------------------------------------------------------
	// Public API
	// -------------------------------------------------------------------------

	/**
	 * Whether the file carries a C2PA manifest.
	 *
	 * @param string $path Absolute path to the image file.
	 * @return bool
	 */
	public static function has_manifest( string $path ): bool {
		if ( ! is_readable( $path ) ) {
			return false;
		}

		$size = (int) filesize( $path );
		if ( $size < 16 ) {
			return false;
		}

		$key = $path . '|' . filemtime( $path ) . '|' . $size;
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}

		// Sniff the container from the magic bytes rather than from the
		// extension or from getimagesize(), which does not report WebP and AVIF
		// consistently across PHP versions.
		$head   = self::read_at( $path, 0, 12 );
		$result = false;

		if ( str_starts_with( $head, "\xFF\xD8\xFF" ) ) {
			$result = self::jpeg_has_manifest( $path, $size );
		} elseif ( str_starts_with( $head, "\x89PNG\r\n\x1a\n" ) ) {
			$result = self::png_has_manifest( $path, $size );
		} elseif ( 'RIFF' === substr( $head, 0, 4 ) && 'WEBP' === substr( $head, 8, 4 ) ) {
			$result = self::webp_has_manifest( $path, $size );
		} elseif ( 'ftyp' === substr( $head, 4, 4 ) ) {
			$result = self::bmff_has_manifest( $path, $size );
		}

		// GIF is deliberately absent: the C2PA specification defines no
		// embedding for it, so there is nothing to look for.

		self::$cache[ $key ] = $result;
		return $result;
	}

	/**
	 * Whether the guard is on, meaning a file carrying a manifest is left
	 * unconverted rather than having its provenance destroyed.
	 *
	 * @return bool
	 */
	public static function guard_enabled(): bool {
		return '1' === get_option( 'cetus_media_c2pa_guard', '1' );
	}

	/**
	 * Counts the attachments carrying one of this class's meta flags.
	 *
	 * @param string $meta_key Either self::META_SKIPPED or self::META_STRIPPED.
	 * @return int
	 */
	public static function count_flagged( string $meta_key ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1'",
				$meta_key
			)
		);
	}

	// -------------------------------------------------------------------------
	// Per-container scanners
	// -------------------------------------------------------------------------

	/**
	 * JPEG: walks the marker segments looking for an APP11 segment whose payload
	 * is a JUMBF box labelled 'c2pa'.
	 *
	 * @param string $path Absolute path.
	 * @param int    $size File size in bytes.
	 * @return bool
	 */
	private static function jpeg_has_manifest( string $path, int $size ): bool {
		$offset = 2; // Past the SOI marker.

		for ( $i = 0; $i < self::MAX_RECORDS; $i++ ) {
			$header = self::read_at( $path, $offset, 4 );
			if ( strlen( $header ) < 4 || "\xFF" !== $header[0] ) {
				return false;
			}

			$marker = ord( $header[1] );

			// SOS starts the compressed image data and EOI ends the file. A
			// manifest always sits in a segment before either of them.
			if ( 0xDA === $marker || 0xD9 === $marker ) {
				return false;
			}

			$length = ( ord( $header[2] ) << 8 ) | ord( $header[3] );
			if ( $length < 2 ) {
				return false;
			}

			if ( 0xEB === $marker ) { // APP11, where JUMBF lives.
				// The payload opens with the 'JP' identifier, 2 bytes of box
				// instance and 4 of packet sequence, then the JUMBF superbox:
				// its length, the 'jumb' type, and a description box carrying the
				// label. For a C2PA manifest that label is 'c2pa', which is what
				// separates it from any other JUMBF payload. All of that sits
				// well inside the first 64 bytes.
				$payload = self::read_at( $path, $offset + 4, min( 64, $length - 2 ) );
				if ( str_contains( $payload, 'jumb' ) && str_contains( $payload, 'c2pa' ) ) {
					return true;
				}
			}

			$offset += 2 + $length;
			if ( $offset >= $size ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * PNG: walks the chunks looking for 'caBX'.
	 *
	 * @param string $path Absolute path.
	 * @param int    $size File size in bytes.
	 * @return bool
	 */
	private static function png_has_manifest( string $path, int $size ): bool {
		$offset = 8; // Past the PNG signature.

		for ( $i = 0; $i < self::MAX_RECORDS; $i++ ) {
			$header = self::read_at( $path, $offset, 8 );
			if ( strlen( $header ) < 8 ) {
				return false;
			}

			$length = self::be32( $header, 0 );
			$type   = substr( $header, 4, 4 );

			if ( 'caBX' === $type ) {
				return true;
			}

			// IEND closes the file, so nothing follows it.
			if ( 'IEND' === $type || $length < 0 ) {
				return false;
			}

			// Length, type, data, then the 4 byte CRC.
			$offset += 12 + $length;
			if ( $offset >= $size ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * WebP: walks the RIFF chunks looking for the 'C2PA' FourCC.
	 *
	 * @param string $path Absolute path.
	 * @param int    $size File size in bytes.
	 * @return bool
	 */
	private static function webp_has_manifest( string $path, int $size ): bool {
		$offset = 12; // Past 'RIFF', the file size and 'WEBP'.

		for ( $i = 0; $i < self::MAX_RECORDS; $i++ ) {
			$header = self::read_at( $path, $offset, 8 );
			if ( strlen( $header ) < 8 ) {
				return false;
			}

			$fourcc = substr( $header, 0, 4 );
			if ( 'C2PA' === $fourcc ) {
				return true;
			}

			// RIFF chunk sizes are little-endian, and an odd size is followed by
			// a pad byte that the size itself does not count.
			$length = self::le32( $header, 4 );
			if ( $length < 0 ) {
				return false;
			}

			$offset += 8 + $length + ( $length % 2 );
			if ( $offset >= $size ) {
				return false;
			}
		}

		return false;
	}

	/**
	 * ISOBMFF, so AVIF and HEIF: walks the top-level boxes looking for a 'uuid'
	 * box carrying the C2PA UUID, or a bare 'jumb' box.
	 *
	 * Only the top level is walked, which is where the tooling in circulation
	 * writes the manifest. A manifest buried inside another box would be missed.
	 *
	 * @param string $path Absolute path.
	 * @param int    $size File size in bytes.
	 * @return bool
	 */
	private static function bmff_has_manifest( string $path, int $size ): bool {
		$offset = 0;

		for ( $i = 0; $i < self::MAX_RECORDS; $i++ ) {
			$header = self::read_at( $path, $offset, 8 );
			if ( strlen( $header ) < 8 ) {
				return false;
			}

			$box_size   = self::be32( $header, 0 );
			$type       = substr( $header, 4, 4 );
			$header_len = 8;

			if ( 1 === $box_size ) {
				// A size of 1 means the real, 64 bit size follows the type.
				$large = self::read_at( $path, $offset + 8, 8 );
				if ( strlen( $large ) < 8 ) {
					return false;
				}
				// The high word is only ever non-zero for files past 4 GB, which
				// is not a case this plugin has to carry.
				$box_size   = ( self::be32( $large, 0 ) << 32 ) | self::be32( $large, 4 );
				$header_len = 16;
			} elseif ( 0 === $box_size ) {
				// A size of 0 means the box runs to the end of the file.
				$box_size = $size - $offset;
			}

			if ( 'uuid' === $type ) {
				$uuid = self::read_at( $path, $offset + $header_len, 16 );
				if ( self::BMFF_C2PA_UUID === $uuid ) {
					return true;
				}
			}

			if ( 'jumb' === $type ) {
				return true;
			}

			if ( $box_size < $header_len ) {
				return false;
			}

			$offset += $box_size;
			if ( $offset >= $size ) {
				return false;
			}
		}

		return false;
	}

	// -------------------------------------------------------------------------
	// Byte helpers
	// -------------------------------------------------------------------------

	/**
	 * Reads a bounded slice of a file.
	 *
	 * It goes through file_get_contents() with an offset rather than fopen and
	 * fread, because WPCS disallows those, the same way source_has_alpha() does
	 * in the optimizer. Nothing here ever reads a whole image into memory.
	 *
	 * @param string $path   Absolute path.
	 * @param int    $offset Byte offset to read from.
	 * @param int    $length How many bytes to read.
	 * @return string The bytes read, possibly shorter than asked for, or ''.
	 */
	private static function read_at( string $path, int $offset, int $length ): string {
		if ( $length <= 0 || $offset < 0 ) {
			return '';
		}

		$bytes = file_get_contents( $path, false, null, $offset, $length ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		return is_string( $bytes ) ? $bytes : '';
	}

	/**
	 * Reads a big-endian 32 bit unsigned integer.
	 *
	 * @param string $bytes The buffer.
	 * @param int    $at    Offset within the buffer.
	 * @return int The value, or -1 when the buffer is too short.
	 */
	private static function be32( string $bytes, int $at ): int {
		if ( strlen( $bytes ) < $at + 4 ) {
			return -1;
		}

		return ( ord( $bytes[ $at ] ) << 24 )
			| ( ord( $bytes[ $at + 1 ] ) << 16 )
			| ( ord( $bytes[ $at + 2 ] ) << 8 )
			| ord( $bytes[ $at + 3 ] );
	}

	/**
	 * Reads a little-endian 32 bit unsigned integer.
	 *
	 * @param string $bytes The buffer.
	 * @param int    $at    Offset within the buffer.
	 * @return int The value, or -1 when the buffer is too short.
	 */
	private static function le32( string $bytes, int $at ): int {
		if ( strlen( $bytes ) < $at + 4 ) {
			return -1;
		}

		return ord( $bytes[ $at ] )
			| ( ord( $bytes[ $at + 1 ] ) << 8 )
			| ( ord( $bytes[ $at + 2 ] ) << 16 )
			| ( ord( $bytes[ $at + 3 ] ) << 24 );
	}
}
