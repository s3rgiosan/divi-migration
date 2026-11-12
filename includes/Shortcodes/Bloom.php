<?php

namespace S3S\WP\DiviMigration\Shortcodes;

use S3S\WP\DiviMigration\Serializer\ShortcodeReplacement;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\Presets;
use S3S\WP\DiviMigration\Targets\FormData;
use S3S\WP\DiviMigration\Targets\FormTarget;
use S3S\WP\DiviMigration\Targets\Targets;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces Bloom's inline opt-in forms (`[et_bloom_inline]`) with blocks: the
 * opt-in's title and message, its image, and the form rebuilt in a form
 * plugin with the same fields, button text and success message.
 *
 * The blocks keep Bloom's layout (image, text and form side by side or
 * stacked, as the opt-in places them), its fonts, sizes, colors and padding.
 * The form block gets the `optin` block style, or `optin-light` for opt-ins
 * with light text, for the theme to draw Bloom's fields.
 *
 * The form plugin stores the subscriptions; connecting it to the email
 * service list that Bloom used is left to the site.
 */
class Bloom implements ShortcodeReplacement {

	/**
	 * Notes on opt-ins that were kept.
	 *
	 * @var string[]
	 */
	private array $notes = [];

	/**
	 * Constructor.
	 *
	 * @param FormTarget|null $target  Form target, or null when no form plugin is chosen.
	 * @param bool            $create  Whether to create forms that do not exist yet.
	 * @param Presets|null    $presets Theme presets that Bloom's fonts match.
	 * @param Targets         $targets Targets, to check that the form target's blocks are registered.
	 */
	public function __construct(
		private ?FormTarget $target,
		private bool $create = true,
		private ?Presets $presets = null,
		private Targets $targets = new Targets(),
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function tags(): array {
		return [ 'et_bloom_inline' ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function specs( string $tag, array $attrs ): ?array {
		$optin_id = self::text( $attrs['optin_id'] ?? '' );
		$options  = get_option( 'et_bloom_options' );
		$optin    = is_array( $options ) ? ( $options[ $optin_id ] ?? null ) : null;

		if ( ! is_array( $optin ) ) {
			$this->notes[] = sprintf( 'Bloom opt-in %s not found', $optin_id );
			return null;
		}

		if ( ! $this->target instanceof FormTarget || ! $this->targets->is_available( $this->target ) ) {
			$this->notes[] = sprintf( 'Bloom opt-in %s kept: no form plugin', $optin_id );
			return null;
		}

		$form = $this->target->build( $this->form( $optin_id, $optin ), $this->create );

		if ( null === $form ) {
			$this->notes[] = sprintf( 'Bloom opt-in %s kept: %s', $optin_id, $this->target->error() );
			return null;
		}

		$light = 'light' === ( $optin['text_color'] ?? 'dark' );

		$form['attributes']['className'] = $light ? 'is-style-optin-light' : 'is-style-optin';

		$text  = $this->header_html( $optin );
		$text  = '' !== $text ? [ Spec::raw( $text, $this->text_styles( $optin ) ) ] : [];
		$inner = $this->layout( $optin, $this->image( $optin ), $text, $form );

		return [
			Spec::block(
				'core/group',
				[
					'metadata' => [ 'name' => 'Opt-in: ' . self::text( $optin['optin_name'] ?? $optin_id ) ],
					'layout'   => [ 'type' => 'default' ],
				],
				$inner
			),
		];
	}

	/**
	 * {@inheritDoc}
	 */
	public function notes(): array {
		$notes       = $this->notes;
		$this->notes = [];

		return $notes;
	}

	/**
	 * Returns the opt-in's form: its name and email fields, which Bloom
	 * labels with placeholders, its button text and success message.
	 *
	 * @param string $optin_id Opt-in ID.
	 * @param array  $optin    Opt-in options.
	 * @return FormData
	 */
	private function form( string $optin_id, array $optin ): FormData {
		$names  = self::text( $optin['name_fields'] ?? 'no_name' );
		$fields = [];

		switch ( $names ) {
			case 'first_last_name':
				$fields[] = $this->field( 'text', self::text( $optin['name_text'] ?? '' ), 'First Name' );
				$fields[] = $this->field( 'text', self::text( $optin['last_name'] ?? '' ), 'Last Name' );
				break;
			case 'single_name':
				$fields[] = $this->field( 'text', self::text( $optin['single_name_text'] ?? '' ), 'Name' );
				break;
			default:
				break;
		}

		$email             = $this->field( 'email', self::text( $optin['email_text'] ?? '' ), 'Email' );
		$email['required'] = true;
		$fields[]          = $email;

		$button = trim( self::text( $optin['button_text'] ?? '' ) );

		// Inline opt-ins put the fields and the button on one row.
		$inline = 'inline' === ( $optin['field_orientation'] ?? 'stacked' );
		$share  = 1 / ( count( $fields ) + 1 );

		if ( $inline ) {
			$fields = array_map(
				fn( array $field ) => [
					...$field,
					'width' => $share,
				],
				$fields
			);
		}

		return new FormData(
			key: 'bloom:' . $optin_id,
			label: 'Bloom: ' . self::text( $optin['optin_name'] ?? $optin_id ),
			fields: $fields,
			submit: '' !== $button ? $button : 'SUBSCRIBE!',
			success: wp_strip_all_tags( html_entity_decode( self::text( $optin['success_message'] ?? '' ), ENT_QUOTES, 'UTF-8' ) ),
			labels: false,
			submit_width: $inline ? $share : 1.0,
		);
	}

	/**
	 * Returns a field labelled by its Bloom text, or Bloom's default.
	 *
	 * @param string $type    Field type.
	 * @param string $text    Bloom text.
	 * @param string $fallback Bloom's default text.
	 * @return array
	 */
	private function field( string $type, string $text, string $fallback ): array {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.TextDomainMismatch -- Bloom's own default strings, in Bloom's translations.
		$label = '' !== trim( $text ) ? stripslashes( $text ) : __( $fallback, 'bloom' );

		return [
			'type'        => $type,
			'label'       => $label,
			'placeholder' => $label,
			'required'    => false,
		];
	}

	/**
	 * Returns the opt-in's title and message HTML, as Bloom prints them,
	 * filtered like post content.
	 *
	 * @param array $optin Opt-in options.
	 * @return string
	 */
	private function header_html( array $optin ): string {
		$title   = self::text( $optin['optin_title'] ?? '' );
		$message = self::text( $optin['optin_message'] ?? '' );

		// Bloom stores its default empty title, and prints nothing for it.
		$title = '&lt;h2&gt;&nbsp;&lt;/h2&gt;' !== $title ? str_replace( '&nbsp;', '', $title ) : '';

		return trim( wp_kses_post( html_entity_decode( stripslashes( $title . $message ), ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Returns the opt-in's Image block, or null when it shows none.
	 *
	 * @param array $optin Opt-in options.
	 * @return array|null
	 */
	private function image( array $optin ): ?array {
		$orientation = self::text( $optin['image_orientation'] ?? 'no_image' );
		$image       = is_array( $optin['image_url'] ?? null ) ? $optin['image_url'] : [];
		$url         = self::text( $image['url'] ?? '' );

		if ( 'no_image' === $orientation || '' === $url ) {
			return null;
		}

		$attachment_id = (int) self::text( $image['id'] ?? 0 );

		return Spec::block(
			'core/image',
			array_filter(
				[
					'id'       => $attachment_id > 0 ? $attachment_id : null,
					'url'      => $url,
					'alt'      => $attachment_id > 0 ? (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) : '',
					'sizeSlug' => 'full',
				],
				fn( $value ) => null !== $value
			)
		);
	}

	/**
	 * Returns the blocks of Bloom's layout: the header (image and text) above
	 * the form, or the header and the form side by side, with the image
	 * beside the text, above or below it.
	 *
	 * @param array      $optin Opt-in options.
	 * @param array|null $image Image block spec.
	 * @param array[]    $text  Text block specs.
	 * @param array      $form  Form block spec.
	 * @return array[]
	 */
	private function layout( array $optin, ?array $image, array $text, array $form ): array {
		$image_side = self::text( $optin['image_orientation'] ?? 'no_image' );
		$form_side  = self::text( $optin['form_orientation'] ?? 'bottom' );
		$header     = [];

		switch ( null !== $image ? $image_side : 'no_image' ) {
			case 'left':
				$header = [ self::column( [ $image ], '28%' ), self::column( $text ) ];
				break;
			case 'right':
				$header = [ self::column( $text ), self::column( [ $image ], '28%' ) ];
				break;
			case 'below':
				$header = [ self::column( [ ...$text, $image ] ) ];
				break;
			case 'above':
				$header = [ self::column( [ $image, ...$text ] ) ];
				break;
			default:
				$header = [] !== $text ? [ self::column( $text ) ] : [];
				break;
		}

		switch ( $form_side ) {
			case 'right':
				return [ self::columns( [ ...$header, self::column( [ $form ], '34%', '40px', '30px' ) ] ) ];
			case 'left':
				return [ self::columns( [ self::column( [ $form ], '34%', '40px', '30px' ), ...$header ] ) ];
			default:
				$form = Spec::block(
					'core/group',
					[
						'style'  => [ 'spacing' => [ 'padding' => self::box( '40px', '30px' ) ] ],
						'layout' => [ 'type' => 'default' ],
					],
					[ $form ]
				);

				$header_count = count( $header );

				if ( $header_count > 1 ) {
					return [ self::columns( $header ), $form ];
				}

				$text = array_merge( ...array_map( fn( array $column ) => $column['innerBlocks'], $header ) );

				return [
					Spec::block(
						'core/group',
						[
							'style'  => [ 'spacing' => [ 'padding' => self::box( '15px', '15px' ) ] ],
							'layout' => [ 'type' => 'default' ],
						],
						$text
					),
					$form,
				];
		}
	}

	/**
	 * Returns the attributes Bloom's header text gets: its fonts at Bloom's
	 * sizes, its text colors, centered above a form or left-aligned beside it.
	 *
	 * @param array $optin Opt-in options.
	 * @return array Attributes keyed by block name.
	 */
	private function text_styles( array $optin ): array {
		$align = in_array( $optin['form_orientation'] ?? 'bottom', [ 'left', 'right' ], true ) ? 'left' : 'center';
		$light = 'light' === ( $optin['header_text_color'] ?? 'dark' );

		$text = function ( string $font, string $size, string $line_height, string $color, array $extra = [] ) use ( $align ): array {
			return [
				'fontFamily' => null,
				'fontSize'   => null,
				'textColor'  => null,
				'style'      => [
					'typography' => array_filter(
						[
							'fontFamily' => '' !== $font ? ( $this->presets ? $this->presets->font_family( $font ) : $font ) : null,
							'fontSize'   => $size,
							'lineHeight' => $line_height,
							'textAlign'  => $align,
							...$extra,
						]
					),
					'color'      => [ 'text' => $color ],
				],
			];
		};

		return [
			'core/heading'   => $text( self::text( $optin['header_font'] ?? '' ), '24px', '1.1', $light ? '#ffffff' : 'rgba(0, 0, 0, 0.8)', [ 'fontWeight' => '600' ] ),
			'core/paragraph' => $text( self::text( $optin['body_font'] ?? '' ), '14px', '1.6', $light ? 'rgba(255, 255, 255, 0.8)' : 'rgba(0, 0, 0, 0.5)' ),
		];
	}

	/**
	 * Returns a Column block spec with Bloom's padding: 15px around the
	 * header, 40px by 30px around the form.
	 *
	 * @param array[] $inner      Inner block specs.
	 * @param string  $width      Width, or empty to share the rest.
	 * @param string  $vertical   Top and bottom padding.
	 * @param string  $horizontal Right and left padding.
	 * @return array
	 */
	private static function column( array $inner, string $width = '', string $vertical = '15px', string $horizontal = '15px' ): array {
		return Spec::block(
			'core/column',
			array_filter(
				[
					'width' => '' !== $width ? $width : null,
					'style' => [ 'spacing' => [ 'padding' => self::box( $vertical, $horizontal ) ] ],
				]
			),
			$inner
		);
	}

	/**
	 * Returns a Columns block spec, its columns centered vertically.
	 *
	 * @param array[] $columns Column block specs.
	 * @return array
	 */
	private static function columns( array $columns ): array {
		return Spec::block( 'core/columns', [ 'verticalAlignment' => 'center' ], $columns );
	}

	/**
	 * Returns a padding box.
	 *
	 * @param string $vertical   Top and bottom.
	 * @param string $horizontal Right and left.
	 * @return array<string, string>
	 */
	private static function box( string $vertical, string $horizontal ): array {
		return [
			'top'    => $vertical,
			'right'  => $horizontal,
			'bottom' => $vertical,
			'left'   => $horizontal,
		];
	}

	/**
	 * Returns an option value as a string, or an empty string when it is not
	 * a scalar.
	 *
	 * @param mixed $value Option value.
	 * @return string
	 */
	private static function text( mixed $value ): string {
		return is_scalar( $value ) ? (string) $value : '';
	}
}
