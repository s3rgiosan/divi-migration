<?php

namespace S3S\WP\DiviMigration;

defined( 'ABSPATH' ) || exit;

/**
 * Divi version that the converter is tested with, and checks of the versions
 * a site uses against it.
 */
class DiviVersion {

	/**
	 * Divi version that the converter is tested with.
	 */
	const TESTED = '4.9.11';

	/**
	 * Theme that bundles the builder, by stylesheet.
	 */
	const THEMES = [ 'Divi', 'Extra' ];

	/**
	 * File in a theme that defines the bundled builder's version.
	 */
	const BUILDER_FILE = 'includes/builder/functions.php';

	/**
	 * Returns the installed Divi version and where it comes from.
	 *
	 * The Divi and Extra themes bundle the builder. The version is the
	 * `ET_BUILDER_PRODUCT_VERSION` the bundled builder defines, read from the
	 * file, so a theme that is not active is found too. Without that file,
	 * the theme's stylesheet header gives the version.
	 *
	 * @return array{source: string, version: string}|null Null when no Divi theme is installed.
	 */
	public static function installed(): ?array {
		foreach ( self::THEMES as $stylesheet ) {
			$theme = wp_get_theme( $stylesheet );

			if ( ! $theme->exists() ) {
				continue;
			}

			$builder = self::builder_version( $theme->get_stylesheet_directory() . '/' . self::BUILDER_FILE );

			if ( '' !== $builder ) {
				return [
					'source'  => $stylesheet . ' theme builder',
					'version' => $builder,
				];
			}

			$version = $theme->get( 'Version' );

			if ( is_string( $version ) && '' !== $version ) {
				return [
					'source'  => $stylesheet . ' theme',
					'version' => $version,
				];
			}
		}

		return null;
	}

	/**
	 * Returns the builder version a builder functions file defines, or an
	 * empty string.
	 *
	 * @param string $path Path to the builder's `functions.php`.
	 * @return string
	 */
	public static function builder_version( string $path ): string {
		if ( ! is_readable( $path ) ) {
			return '';
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
		$code    = (string) file_get_contents( $path );
		$defined = preg_match( "/define\\(\\s*'ET_BUILDER_PRODUCT_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $code, $matches );

		return 1 === $defined ? $matches[1] : '';
	}

	/**
	 * Counts the modules per `_builder_version` value in Divi content.
	 *
	 * The value can sit in double quotes, single quotes, curly quotes or
	 * encoded curly quotes, as the parser accepts them.
	 *
	 * @param string $content Divi content.
	 * @return array<string, int> Module count keyed by version.
	 */
	public static function census( string $content ): array {
		if ( ! str_contains( $content, '_builder_version=' ) ) {
			return [];
		}

		// The curly quotes are UTF-8 byte sequences: U+201C, U+201D and U+2033.
		$quote = '(?:"|\'|\xE2\x80\x9C|\xE2\x80\x9D|\xE2\x80\xB3|&#8220;|&#8221;|&#8243;)';

		preg_match_all( '/\b_builder_version=\s*' . $quote . '(.+?)' . $quote . '/', $content, $matches );

		return array_count_values( $matches[1] );
	}

	/**
	 * Adds the counts of one census to the totals.
	 *
	 * @param array<string, int> $totals Totals keyed by version.
	 * @param array<string, int> $census Counts keyed by version.
	 * @return array<string, int>
	 */
	public static function merge( array $totals, array $census ): array {
		foreach ( $census as $version => $count ) {
			$totals[ $version ] = ( $totals[ $version ] ?? 0 ) + $count;
		}

		return $totals;
	}

	/**
	 * Returns the newest of a list of versions, or an empty string.
	 *
	 * @param string[] $versions Versions.
	 * @return string
	 */
	public static function newest( array $versions ): string {
		$newest = '';

		foreach ( $versions as $version ) {
			$version = (string) $version;

			if ( '' === $newest || version_compare( $version, $newest, '>' ) ) {
				$newest = $version;
			}
		}

		return $newest;
	}

	/**
	 * Compares a version with the tested one.
	 *
	 * @param string $version Version, or an empty string when it is missing.
	 * @return string One of `missing`, `equal`, `older`, `newer` (same major), or `unsupported` (Divi 5 or later).
	 */
	public static function status( string $version ): string {
		if ( '' === $version ) {
			return 'missing';
		}

		$major = (int) $version;

		if ( $major >= 5 ) {
			return 'unsupported';
		}

		$order = version_compare( $version, self::TESTED );

		if ( 0 === $order ) {
			return 'equal';
		}

		return $order < 0 ? 'older' : 'newer';
	}

	/**
	 * Returns the warning for the versions a site uses, or an empty string
	 * when they match the tested one.
	 *
	 * @param string             $installed Installed Divi version, or an empty string.
	 * @param array<string, int> $content   Module count keyed by `_builder_version`.
	 * @return string
	 */
	public static function warning( string $installed, array $content ): string {
		$versions         = array_map( 'strval', array_keys( $content ) );
		$content_newest   = self::newest( $versions );
		$installed_status = self::status( $installed );
		$content_status   = self::status( $content_newest );
		$installed_label  = '' === $installed ? 'none' : $installed;
		$content_label    = '' === $content_newest ? 'none' : $content_newest;

		if ( 'unsupported' === $installed_status || 'unsupported' === $content_status ) {
			return sprintf(
				'Divi 5 is not supported. The converter reads Divi 4 shortcode content only, and is tested with Divi %s (installed: %s; newest content version: %s).',
				self::TESTED,
				$installed_label,
				$content_label
			);
		}

		if ( 'missing' === $installed_status ) {
			return sprintf(
				'Divi not found; content versions: %s. Tested with Divi %s.',
				[] === $versions ? 'none' : implode( ', ', $versions ),
				self::TESTED
			);
		}

		$differs = [ 'older', 'newer' ];

		if ( ! in_array( $installed_status, $differs, true ) && ! in_array( $content_status, $differs, true ) ) {
			return '';
		}

		return sprintf(
			'Divi version differs from the tested version %s (installed: %s; newest content version: %s). Check layout defaults and module output against the old site.',
			self::TESTED,
			$installed_label,
			$content_label
		);
	}
}
