<?php

namespace S3S\WP\DiviMigration\Serializer;

defined( 'ABSPATH' ) || exit;

use WP_Block_Type;
use WP_Block_Type_Registry;

/**
 * Turns the converter's block specs into block markup on the server.
 *
 * Static blocks get the markup their `save()` writes (see `Saves`). Dynamic
 * blocks get their comment delimiters. A static block with no save is
 * listed in `unsupported`: the serializer writes only its comment
 * delimiters, so the caller skips the post to keep it valid. Raw HTML specs
 * go through `RawHandler` first.
 *
 * Block class names translate through `Classes`, and Shortcode blocks whose
 * shortcode a `ShortcodeReplacement` handles become its blocks.
 */
final class Serializer {

	/**
	 * Block names in the specs that are not registered.
	 *
	 * @var string[]
	 */
	public array $missing = [];

	/**
	 * Registered static blocks without a save. The markup holds only their
	 * comment delimiters, so the caller skips the post.
	 *
	 * @var string[]
	 */
	public array $unsupported = [];

	/**
	 * Notes from the shortcode replacements, for the conversion report.
	 *
	 * @var string[]
	 */
	public array $notes = [];

	/**
	 * Saves keyed by block name.
	 *
	 * @var array<string, callable>
	 */
	private array $saves;

	/**
	 * Shortcode replacements keyed by shortcode tag.
	 *
	 * @var array<string, ShortcodeReplacement>
	 */
	private array $replacements = [];

	/**
	 * Constructor.
	 *
	 * @param RawHandler             $raw_handler  Raw handler.
	 * @param Classes                $classes      Class translations.
	 * @param ShortcodeReplacement[] $replacements Shortcode replacements.
	 */
	public function __construct(
		private RawHandler $raw_handler = new RawHandler(),
		public readonly Classes $classes = new Classes(),
		array $replacements = [],
	) {
		$this->saves = Saves::all();

		$this->raw_handler->set_notifier(
			function ( string $note ): void {
				$this->notes[] = $note;
			}
		);

		foreach ( $replacements as $replacement ) {
			foreach ( $replacement->tags() as $tag ) {
				$this->replacements[ $tag ] = $replacement;
			}
		}
	}

	/**
	 * Returns the block markup for block specs.
	 *
	 * @param array[] $specs Block specs.
	 * @return string
	 */
	public function serialize( array $specs ): string {
		return implode( "\n\n", array_map( 'serialize_block', $this->blocks( $specs ) ) );
	}

	/**
	 * Returns parsed blocks, as `parse_blocks()` returns them, for block specs.
	 *
	 * @param array[] $specs Block specs.
	 * @return array[]
	 */
	public function blocks( array $specs ): array {
		$blocks = [];

		foreach ( $this->expand( $specs ) as $spec ) {
			$blocks[] = $this->block( $spec );
		}

		return $blocks;
	}

	/**
	 * Replaces raw HTML specs with the block specs the raw handler makes of
	 * them, with the spec's `apply` attributes merged in.
	 *
	 * @param array[] $specs Block specs.
	 * @return array[]
	 */
	public function expand( array $specs ): array {
		$expanded = [];

		foreach ( $specs as $spec ) {
			$has_blocks = isset( $spec['markup'] ) && 1 === preg_match( '/<!--\s*wp:/', (string) $spec['markup'] );

			if ( isset( $spec['markup'] ) && ! $has_blocks ) {
				$spec = [ 'raw' => $spec['markup'] ];
			}

			if ( ! isset( $spec['raw'] ) ) {
				$replaced = 'core/shortcode' === ( $spec['name'] ?? '' ) ? $this->replace( (string) ( $spec['attributes']['text'] ?? '' ) ) : null;

				array_push( $expanded, ...( null !== $replaced ? $this->expand( $replaced ) : [ $spec ] ) );
				continue;
			}

			$apply = json_decode( (string) wp_json_encode( $spec['apply'] ?? [] ), true );
			$apply = is_array( $apply ) ? $apply : [];

			foreach ( $this->raw_handler->specs( (string) $spec['raw'] ) as $block ) {
				$replaced = 'core/shortcode' === ( $block['name'] ?? '' ) ? $this->replace( (string) ( $block['attributes']['text'] ?? '' ) ) : null;

				if ( null === $replaced ) {
					$expanded[] = $this->apply( $block, $apply );
					continue;
				}

				foreach ( $this->expand( $replaced ) as $replacement ) {
					$expanded[] = $this->apply( $replacement, $apply );
				}
			}
		}

		return $expanded;
	}

	/**
	 * Merges attributes into a block spec and its inner blocks as defaults:
	 * an attribute the block already has keeps its value.
	 *
	 * @param array $spec  Block spec.
	 * @param array $apply Attributes keyed by block name (`core/paragraph`) or heading level
	 *                     (`core/heading:2`); a level key wins over the block name key.
	 * @return array
	 */
	private function apply( array $spec, array $apply ): array {
		if ( [] === $apply || ! isset( $spec['name'] ) ) {
			return $spec;
		}

		$attributes = $apply[ $spec['name'] ] ?? [];

		if ( 'core/heading' === $spec['name'] ) {
			$attributes = self::merge( $attributes, $apply[ 'core/heading:' . ( $spec['attributes']['level'] ?? 2 ) ] ?? [] );
		}

		$spec['attributes']  = self::merge( $attributes, $spec['attributes'] ?? [] );
		$spec['innerBlocks'] = array_map( fn( array $inner ) => $this->apply( $inner, $apply ), $spec['innerBlocks'] ?? [] );

		return $spec;
	}

	/**
	 * Returns the parsed block for a block spec.
	 *
	 * @param array $spec Block spec.
	 * @return array
	 */
	private function block( array $spec ): array {
		if ( isset( $spec['classic'] ) ) {
			$html = (string) $spec['classic'];

			return [
				'blockName'    => null,
				'attrs'        => [],
				'innerBlocks'  => [],
				'innerHTML'    => $html,
				'innerContent' => [ $html ],
			];
		}

		if ( isset( $spec['markup'] ) ) {
			return $this->markup_block( (string) $spec['markup'] );
		}

		$name = (string) $spec['name'];
		$type = WP_Block_Type_Registry::get_instance()->get_registered( $name );

		if ( 'core/html' === $name ) {
			$content = (string) ( $spec['attributes']['content'] ?? '' );

			// A block delimiter in the HTML would end the block early. Removing one can join the text around it into another.
			$has_delimiter = 1 === preg_match( RawHandler::BLOCK_DELIMITER, $content );

			while ( $has_delimiter ) {
				$content       = (string) preg_replace( RawHandler::BLOCK_DELIMITER, '', $content );
				$has_delimiter = 1 === preg_match( RawHandler::BLOCK_DELIMITER, $content );
			}

			return self::parsed( $name, [], [ "\n" . $content . "\n" ], [] );
		}

		if ( ! $type instanceof WP_Block_Type ) {
			$this->missing[] = $name;

			return self::parsed(
				'core/html',
				[],
				[
					"\n" . Html::element(
						'div',
						[
							'hidden'                 => true,
							'data-divi-module-block' => $name,
						]
					) . "\n",
				],
				[]
			);
		}

		$attributes = $this->classes->block( $spec['attributes'] ?? [] );
		$inner      = $this->blocks( $spec['innerBlocks'] ?? [] );
		$comment    = $this->comment_attributes( $type, $attributes );

		if ( ! isset( $this->saves[ $name ] ) ) {
			if ( ! $type->is_dynamic() ) {
				$this->unsupported[] = $name;
			}

			return self::parsed( $name, $comment, self::wrap( '', '', count( $inner ) ), $inner );
		}

		[ $before, $after ] = call_user_func( $this->saves[ $name ], $this->with_defaults( $type, $attributes ), new Supports( $type ) );

		return self::parsed( $name, $comment, self::wrap( $before, $after, count( $inner ) ), $inner );
	}

	/**
	 * Returns the blocks that replace a shortcode on its own, or null to keep
	 * it.
	 *
	 * @param string $text Shortcode block text.
	 * @return array[]|null
	 */
	private function replace( string $text ): ?array {
		// Code modules store the shortcode's quotes as entities.
		$text = html_entity_decode( trim( $text ), ENT_QUOTES, 'UTF-8' );

		$is_shortcode = preg_match( '/^\[([a-z][a-z0-9_-]*)(\s[^\]]*)?\]$/i', $text, $matches );

		if ( 1 !== $is_shortcode ) {
			return null;
		}

		$replacement = $this->replacements[ strtolower( $matches[1] ) ] ?? null;

		if ( ! $replacement instanceof ShortcodeReplacement ) {
			return null;
		}

		$attrs = shortcode_parse_atts( trim( $matches[2] ?? '' ) );
		$specs = $replacement->specs( strtolower( $matches[1] ), is_array( $attrs ) ? $attrs : [] );

		array_push( $this->notes, ...$replacement->notes() );

		return $specs;
	}

	/**
	 * Returns block markup as one parsed block: the block itself, or a
	 * wrapper-less list when the markup holds several blocks.
	 *
	 * @param string $markup Block markup.
	 * @return array
	 */
	private function markup_block( string $markup ): array {
		$blocks = array_values( array_filter( parse_blocks( $markup ), fn( array $block ) => null !== $block['blockName'] || '' !== trim( $block['innerHTML'] ) ) );

		$count = count( $blocks );

		if ( 1 === $count && null !== $blocks[0]['blockName'] ) {
			return $blocks[0];
		}

		// Several blocks: serialized one after another, with nothing around them.
		return [
			'blockName'    => null,
			'attrs'        => [],
			'innerBlocks'  => [],
			'innerHTML'    => implode( "\n\n", array_map( 'serialize_block', $blocks ) ),
			'innerContent' => [ implode( "\n\n", array_map( 'serialize_block', $blocks ) ) ],
		];
	}

	/**
	 * Returns the attributes that go in the block comment: those the markup
	 * does not hold and that differ from their default.
	 *
	 * @param WP_Block_Type $type       Block type.
	 * @param array         $attributes Attributes.
	 * @return array
	 */
	private function comment_attributes( WP_Block_Type $type, array $attributes ): array {
		$schema  = $type->attributes ?? [];
		$comment = [];

		foreach ( $attributes as $key => $value ) {
			$definition = $schema[ $key ] ?? [];

			if ( null === $value || isset( $definition['source'] ) || 'local' === ( $definition['role'] ?? '' ) ) {
				continue;
			}

			if ( array_key_exists( 'default', $definition ) && $definition['default'] === $value ) {
				continue;
			}

			$comment[ $key ] = self::normalize_numbers( $value );
		}

		return $comment;
	}

	/**
	 * Returns attributes with the block type's defaults for those not set.
	 *
	 * @param WP_Block_Type $type       Block type.
	 * @param array         $attributes Attributes.
	 * @return array
	 */
	private function with_defaults( WP_Block_Type $type, array $attributes ): array {
		foreach ( $type->attributes ?? [] as $key => $definition ) {
			if ( ! array_key_exists( $key, $attributes ) && array_key_exists( 'default', $definition ) ) {
				$attributes[ $key ] = $definition['default'];
			}
		}

		return $attributes;
	}

	/**
	 * Returns a block's inner content: the markup around its inner blocks,
	 * with a placeholder per inner block.
	 *
	 * @param string $before Markup before the inner blocks.
	 * @param string $after  Markup after the inner blocks.
	 * @param int    $count  Number of inner blocks.
	 * @return array<string|null>
	 */
	private static function wrap( string $before, string $after, int $count ): array {
		if ( 0 === $count ) {
			return '' === $before . $after ? [] : [ "\n" . $before . $after . "\n" ];
		}

		$content = [ "\n" . $before ];

		for ( $index = 0; $index < $count; $index++ ) {
			if ( $index > 0 ) {
				$content[] = "\n\n";
			}

			$content[] = null;
		}

		$content[] = $after . "\n";

		return $content;
	}

	/**
	 * Returns a parsed block.
	 *
	 * @param string             $name          Block name.
	 * @param array              $attributes    Comment attributes.
	 * @param array<string|null> $inner_content Inner content.
	 * @param array[]            $inner_blocks  Inner blocks.
	 * @return array
	 */
	private static function parsed( string $name, array $attributes, array $inner_content, array $inner_blocks ): array {
		return [
			'blockName'    => $name,
			'attrs'        => $attributes,
			'innerBlocks'  => $inner_blocks,
			'innerHTML'    => implode( '', array_filter( $inner_content, 'is_string' ) ),
			'innerContent' => $inner_content,
		];
	}

	/**
	 * Merges attributes; nested objects merge, other values replace.
	 *
	 * @param array $target Base attributes.
	 * @param array $source Attributes to merge in.
	 * @return array
	 */
	private static function merge( array $target, array $source ): array {
		foreach ( $source as $key => $value ) {
			$target[ $key ] = is_array( $value ) && ! array_is_list( $value ) && is_array( $target[ $key ] ?? null ) && ! array_is_list( $target[ $key ] )
				? self::merge( $target[ $key ], $value )
				: $value;
		}

		return $target;
	}

	/**
	 * Writes whole floats as integers, as JSON from JavaScript has them.
	 *
	 * @param mixed $value Value.
	 * @return mixed
	 */
	private static function normalize_numbers( mixed $value ): mixed {
		if ( is_float( $value ) ) {
			$floored   = floor( $value );
			$magnitude = abs( $value );

			if ( $floored === $value && $magnitude < PHP_INT_MAX ) {
				return (int) $value;
			}
		}

		return is_array( $value ) ? array_map( [ self::class, 'normalize_numbers' ], $value ) : $value;
	}
}
