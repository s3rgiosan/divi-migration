<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a sidebar module into a template part.
 *
 * Block themes have no widget areas; the theme provides a `sidebar` template
 * part with the blocks the widget area held. The part slug is a setting.
 */
class Sidebar extends Module {

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		return [
			Spec::block(
				'core/template-part',
				array_merge(
					$this->common_attributes( $node ),
					[ 'slug' => $converter->settings->sidebar_part ]
				)
			),
		];
	}
}
