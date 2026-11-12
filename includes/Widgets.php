<?php

namespace S3S\WP\DiviMigration;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Turns a widget area into block specs, for a template part.
 *
 * Text widgets (core and Black Studio TinyMCE) become their HTML as blocks,
 * Search and Custom HTML widgets become their block counterparts, and any
 * other widget becomes a Legacy Widget block. Widget titles become headings.
 */
class Widgets {

	/**
	 * Widget types whose HTML converts to blocks, mapped to the instance key
	 * that holds the HTML.
	 */
	const TEXT_WIDGETS = [
		'text'                 => 'text',
		'black-studio-tinymce' => 'text',
		'custom_html'          => 'content',
	];

	/**
	 * Heading level for widget titles.
	 */
	const TITLE_LEVEL = 4;

	/**
	 * Returns block specs for a widget area.
	 *
	 * @param string $area_id Widget area ID, such as `sidebar-1`.
	 * @return array[]|WP_Error Block specs, or an error when the area is not found or has no widgets.
	 */
	public static function to_specs( string $area_id ): array|WP_Error {
		$areas = wp_get_sidebars_widgets();

		if ( ! is_array( $areas[ $area_id ] ?? null ) ) {
			return new WP_Error( 's3s_divi_migration_widget_area', sprintf( 'Widget area %s not found.', $area_id ) );
		}

		$widgets = $areas[ $area_id ];

		if ( [] === $widgets ) {
			return new WP_Error( 's3s_divi_migration_widget_area', sprintf( 'Widget area %s has no widgets.', $area_id ) );
		}

		$specs = [];

		foreach ( $widgets as $widget_id ) {
			array_push( $specs, ...self::widget_specs( (string) $widget_id ) );
		}

		return $specs;
	}

	/**
	 * Returns block specs for one widget.
	 *
	 * @param string $widget_id Widget ID, such as `text-3`.
	 * @return array[]
	 */
	private static function widget_specs( string $widget_id ): array {
		$matched = preg_match( '/^(.+)-(\d+)$/', $widget_id, $matches );

		if ( 1 !== $matched ) {
			return [];
		}

		$id_base   = $matches[1];
		$instances = get_option( 'widget_' . $id_base, [] );
		$instance  = is_array( $instances ) && is_array( $instances[ (int) $matches[2] ] ?? null ) ? $instances[ (int) $matches[2] ] : [];
		$specs     = [];
		$title     = trim( (string) ( $instance['title'] ?? '' ) );

		if ( '' !== $title ) {
			$specs[] = Spec::block(
				'core/heading',
				[
					'level'     => self::TITLE_LEVEL,
					'content'   => esc_html( $title ),
					'className' => 'widget-title',
				]
			);
		}

		if ( isset( self::TEXT_WIDGETS[ $id_base ] ) ) {
			$html = trim( (string) ( $instance[ self::TEXT_WIDGETS[ $id_base ] ] ?? '' ) );

			if ( '' !== $html ) {
				$specs[] = 'custom_html' === $id_base
					? Spec::block( 'core/html', [ 'content' => $html ] )
					: Spec::raw( wpautop( $html ) );
			}
		} elseif ( 'search' === $id_base ) {
			$specs[] = Spec::block(
				'core/search',
				[
					'label'      => __( 'Search', 'divi-migration' ),
					'showLabel'  => false,
					'buttonText' => __( 'Search', 'divi-migration' ),
				]
			);
		} else {
			$specs[] = Spec::block(
				'core/legacy-widget',
				[
					'id' => $widget_id,
				]
			);
		}

		return [
			Spec::block(
				'core/group',
				[
					'className' => 'widget widget-' . $id_base,
					'layout'    => [ 'type' => 'default' ],
				],
				$specs
			),
		];
	}
}
