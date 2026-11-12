<?php

namespace S3S\WP\DiviMigration\Rules;

use S3S\WP\DiviMigration\Converter;
use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\StyleSet;
use S3S\WP\DiviMigration\Targets\FormData;
use S3S\WP\DiviMigration\Targets\FormTarget;

defined( 'ABSPATH' ) || exit;

/**
 * Converts a contact form into its title and the form rebuilt in a form
 * plugin (the `form` target), with the same fields, button text, success
 * message and recipient.
 *
 * Without a form target the module falls back like a module without a rule.
 * Divi's math captcha has no counterpart; the form plugin's spam protection
 * applies.
 */
class ContactForm extends Module {

	/**
	 * Divi's default fields, used when the module has none of its own.
	 */
	const DEFAULT_FIELDS = [
		[ 'Name', 'input', true ],
		[ 'Email Address', 'email', true ],
		[ 'Message', 'text', false ],
	];

	/**
	 * Divi's space below the form title.
	 */
	const TITLE_GAP = '16px';

	/**
	 * Divi field types, mapped to form field types.
	 */
	const TYPES = [
		'input'    => 'text',
		'email'    => 'email',
		'text'     => 'textarea',
		'select'   => 'select',
		'radio'    => 'radio',
		'checkbox' => 'checkbox',
	];

	/**
	 * Constructor.
	 *
	 * @param Rule $fallback Rule for a contact form without a form target.
	 */
	public function __construct( private Rule $fallback = new Fallback() ) {}

	/**
	 * {@inheritDoc}
	 */
	public function convert( Node $node, Converter $converter ): array {
		$target = $converter->target( 'form', $node->tag );

		if ( ! $target instanceof FormTarget || ! $converter->is_available( $target ) ) {
			$converter->note( $node->tag, 'kept: no form plugin' );
			return $this->fallback->convert( $node, $converter );
		}

		$form = $this->form( $node );
		$spec = $target->build( $form, ! $converter->dry_run );

		if ( null === $spec ) {
			$converter->note( $node->tag, 'kept: ' . $target->error() );
			return $this->fallback->convert( $node, $converter );
		}

		$this->note_unconverted( $node, $converter, 'redirect after submit', [ 'use_redirect' ] );

		$specs = [];
		$title = trim( $node->attr( 'title' ) );

		if ( '' !== $title ) {
			$title_style = new StyleSet();
			$title_style->set_value( [ 'spacing', 'padding', 'bottom' ], self::TITLE_GAP );
			$this->typography( $node, 'title', $title_style, $converter->presets );

			// The key order keeps the saved markup unchanged.
			$specs[] = Spec::block(
				'core/heading',
				[
					'content' => esc_html( $title ),
					'level'   => $this->heading_level( $node, 'title_level', 1 ),
					'style'   => $title_style->to_style(),
				]
			);
		}

		$specs[] = $spec;

		$style = new StyleSet();
		$this->design( $node, $style, $converter->presets, [], $converter );

		return $this->wrap( $node, $specs, $style, $this->common_attributes( $node ) );
	}

	/**
	 * Returns the form: the module's fields, or Divi's default ones, labelled
	 * by placeholders as Divi shows them.
	 *
	 * @param Node $node Contact form node.
	 * @return FormData
	 */
	private function form( Node $node ): FormData {
		$fields = [];

		foreach ( $node->children as $child ) {
			if ( 'et_pb_contact_field' !== $child->tag ) {
				continue;
			}

			$divi_type = $child->attr( 'field_type', 'input' );
			$type      = self::TYPES[ $divi_type ] ?? 'text';
			$title     = $child->attr( 'field_title' );
			$options   = $this->options( $child->attr( $divi_type . '_options' ) );

			// A checkbox without options is one option labelled by the field title.
			if ( 'checkbox' === $type && [] === $options && '' !== $title ) {
				$options = [ $title ];
			}

			$fields[] = $this->field(
				$title,
				$type,
				'off' !== $child->attr( 'required_mark', 'on' ),
				! $child->is_on( 'fullwidth_field' ) && 'textarea' !== $type,
				$options
			);
		}

		// The form key reads the untranslated default strings, so it stays the same in every locale.
		$key_fields = $fields;

		if ( [] === $fields ) {
			foreach ( self::DEFAULT_FIELDS as [ $label, $divi_type, $half ] ) {
				// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText, WordPress.WP.I18n.TextDomainMismatch -- Divi's own default strings, in Divi's translations.
				$fields[]     = $this->field( __( $label, 'et_builder' ), self::TYPES[ $divi_type ], true, $half );
				$key_fields[] = $this->field( $label, self::TYPES[ $divi_type ], true, $half );
			}
		}

		$submit  = $node->attr( 'submit_button_text' );
		$success = $node->attr( 'success_message' );
		$label   = trim( $node->attr( 'admin_label' ) );
		$title   = trim( $node->attr( 'title' ) );

		$form = new FormData(
			key: '',
			label: 'Divi: ' . ( '' !== $label ? $label : ( '' !== $title ? wp_html_excerpt( $title, 40, '…' ) : 'Contact Form' ) ),
			fields: $fields,
			// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Divi's own default strings, in Divi's translations.
			submit: '' !== $submit ? $submit : __( 'Submit', 'et_builder' ),
			// phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- Divi's own default strings, in Divi's translations.
			success: '' !== $success ? $success : __( 'Thanks for contacting us', 'et_builder' ),
			email: $this->addresses( $node->attr( 'email' ) ),
			labels: false,
			submit_full: false,
		);

		// Equal forms, such as one in a global module, share one key and one rebuilt form.
		$key_submit  = '' !== $submit ? $submit : 'Submit';
		$key_success = '' !== $success ? $success : 'Thanks for contacting us';

		$form->key = 'divi-contact:' . md5( (string) wp_json_encode( [ $key_fields, $key_submit, $key_success, $form->email ] ) );

		return $form;
	}

	/**
	 * Returns the valid addresses of a comma-separated list.
	 *
	 * @param string $value Recipient addresses.
	 * @return string[]
	 */
	private function addresses( string $value ): array {
		$addresses = [];

		foreach ( explode( ',', $value ) as $part ) {
			$address = sanitize_email( trim( $part ) );

			if ( '' !== $address && ! in_array( $address, $addresses, true ) ) {
				$addresses[] = $address;
			}
		}

		return $addresses;
	}

	/**
	 * Returns a field.
	 *
	 * @param string   $label    Label.
	 * @param string   $type     Form field type.
	 * @param bool     $required Whether the field is required.
	 * @param bool     $half     Whether the field takes half the row.
	 * @param string[] $options  Options of a select, radio or checkbox field.
	 * @return array
	 */
	private function field( string $label, string $type, bool $required, bool $half, array $options = [] ): array {
		return [
			'type'        => $type,
			'label'       => $label,
			'placeholder' => in_array( $type, [ 'text', 'email', 'textarea' ], true ) ? $label : '',
			'required'    => $required,
			'width'       => $half ? 0.5 : 1,
			'options'     => $options,
		];
	}

	/**
	 * Returns the option values Divi stores for a select, radio or checkbox
	 * field, as JSON with `%22`, `%91` and `%93` for quotes and brackets.
	 *
	 * @param string $value Stored options.
	 * @return string[]
	 */
	private function options( string $value ): array {
		$options = json_decode( str_replace( [ '%22', '%91', '%93' ], [ '"', '[', ']' ], $value ), true );

		if ( ! is_array( $options ) ) {
			return [];
		}

		return array_values( array_filter( array_map( fn( $option ) => is_array( $option ) ? (string) ( $option['value'] ?? '' ) : '', $options ), 'strlen' ) );
	}
}
