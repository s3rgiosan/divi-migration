<?php

namespace S3S\WP\DiviMigration\Serializer;

defined( 'ABSPATH' ) || exit;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use S3S\WP\DiviMigration\Spec;
use S3S\WP\DiviMigration\Styles\Presets;
use S3S\WP\DiviMigration\Targets\CoreTable;
use S3S\WP\DiviMigration\Targets\TableCell;
use S3S\WP\DiviMigration\Targets\TableData;
use S3S\WP\DiviMigration\Targets\TableTarget;
use WP_Block_Type;
use WP_Block_Type_Registry;
use WP_Post;

// PHP's DOM classes name their properties in camel case.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase

/**
 * Turns HTML into block specs.
 *
 * Paragraphs, headings, lists, images, quotes, separators, tables, videos,
 * embeds (a URL on its own) and shortcodes on their own line become their
 * blocks: `[caption]` an Image, `[gallery]` a Gallery, `[audio]`, `[video]`
 * and `[embed]` their blocks, other shortcodes a Shortcode block. Any other
 * element becomes a Custom HTML block, so no markup is lost.
 *
 * Tables go to the table target. Gallery images get their URL, alternative
 * text, caption and link from the attachment.
 *
 * Inline styles on paragraphs, headings and list items become block
 * attributes (palette colors, font sizes and font families as their
 * presets), text colored with a `<span>` becomes the text color format,
 * Divi's `[button]` shortcode becomes a Buttons block, and the migration map
 * can replace or drop other shortcodes.
 *
 * Before the raw handler steps, serialized blocks in the HTML stay as they
 * are, a `<div>` with classes becomes a Group with those classes, its ID and
 * its inline styles, and a paragraph with an image next to other images or
 * text stays a paragraph. Empty paragraphs, such as the `<p>&nbsp;</p>`
 * spacers of classic content, stay unless the handler drops them.
 */
final class RawHandler {

	/**
	 * Elements that sit inside a paragraph.
	 */
	const PHRASING = [ 'a', 'abbr', 'audio', 'b', 'bdi', 'bdo', 'br', 'canvas', 'code', 'data', 'del', 'dfn', 'em', 'embed', 'i', 'img', 'ins', 'kbd', 'mark', 'object', 'q', 'rp', 'rt', 'ruby', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'video', 'wbr' ];

	/**
	 * Elements that close an open paragraph when they start.
	 */
	const BLOCK_ELEMENTS = [ 'address', 'article', 'aside', 'blockquote', 'details', 'div', 'dl', 'fieldset', 'figcaption', 'figure', 'footer', 'form', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hr', 'main', 'menu', 'nav', 'ol', 'p', 'pre', 'section', 'table', 'ul' ];

	/**
	 * Ordered list types and their list style.
	 */
	const LIST_STYLES = [
		'A' => 'upper-alpha',
		'a' => 'lower-alpha',
		'I' => 'upper-roman',
		'i' => 'lower-roman',
	];

	/**
	 * Embed providers: whether their embeds are responsive, and the URL patterns
	 * that pick the provider.
	 */
	const EMBED_PROVIDERS = [
		'twitter'       => [ true, [ '#^https?://(www\.)?twitter\.com/.+#i' ] ],
		'youtube'       => [ true, [ '#^https?://((m|www)\.)?youtube\.com/.+#i', '#^https?://youtu\.be/.+#i' ] ],
		'facebook'      => [ true, [] ],
		'instagram'     => [ true, [] ],
		'wordpress'     => [ false, [] ],
		'collegehumor'  => [ true, [] ],
		'soundcloud'    => [ true, [ '#^https?://(www\.)?soundcloud\.com/.+#i' ] ],
		'spotify'       => [ true, [ '#^https?://(open|play)\.spotify\.com/.+#i' ] ],
		'flickr'        => [ true, [ '#^https?://(www\.)?flickr\.com/.+#i', '#^https?://flic\.kr/.+#i' ] ],
		'vimeo'         => [ true, [ '#^https?://(www\.)?vimeo\.com/.+#i' ] ],
		'animoto'       => [ true, [ '#^https?://(www\.)?(animoto|video214)\.com/.+#i' ] ],
		'cloudup'       => [ true, [ '#^https?://cloudup\.com/.+#i' ] ],
		'crowdsignal'   => [ true, [ '#^https?://((.+\.)?polldaddy\.com|poll\.fm|.+\.crowdsignal\.net|.+\.survey\.fm)/.+#i' ] ],
		'dailymotion'   => [ true, [ '#^https?://(www\.)?dailymotion\.com/.+#i' ] ],
		'imgur'         => [ true, [ '#^https?://(.+\.)?imgur\.com/.+#i' ] ],
		'issuu'         => [ true, [ '#^https?://(www\.)?issuu\.com/.+#i' ] ],
		'kickstarter'   => [ true, [ '#^https?://(www\.)?kickstarter\.com/.+#i', '#^https?://kck\.st/.+#i' ] ],
		'mixcloud'      => [ true, [ '#^https?://(www\.)?mixcloud\.com/.+#i' ] ],
		'pocket-casts'  => [ true, [ '#^https://pca.st/\w+#i' ] ],
		'reddit'        => [ true, [ '#^https?://(www\.)?reddit\.com/.+#i' ] ],
		'reverbnation'  => [ true, [ '#^https?://(www\.)?reverbnation\.com/.+#i' ] ],
		'scribd'        => [ true, [ '#^https?://(www\.)?scribd\.com/.+#i' ] ],
		'smugmug'       => [ true, [ '#^https?://(.+\.)?smugmug\.com/.*#i' ] ],
		'speaker-deck'  => [ true, [ '#^https?://(www\.)?speakerdeck\.com/.+#i' ] ],
		'tiktok'        => [ true, [ '#^https?://(www\.)?tiktok\.com/.+#i' ] ],
		'ted'           => [ true, [ '#^https?://(www\.|embed\.)?ted\.com/.+#i' ] ],
		'tumblr'        => [ true, [ '#^https?://(.+)\.tumblr\.com/.+#i' ] ],
		'videopress'    => [ true, [ '#^https?://videopress\.com/.+#i' ] ],
		'wordpress-tv'  => [ true, [ '#^https?://wordpress\.tv/.+#i' ] ],
		'amazon-kindle' => [ false, [ '#^https?://([a-z0-9-]+\.)?(amazon|amzn)(\.[a-z]{2,4})+/.+#i', '#^https?://(www\.)?(a\.co|z\.cn)/.+#i' ] ],
		'pinterest'     => [ false, [ '#^https?://([a-z]{2}|www)\.pinterest\.com(\.(au|mx))?/.*#i' ] ],
		'wolfram-cloud' => [ true, [ '#^https?://(www\.)?wolframcloud\.com/obj/.+#i' ] ],
		'bluesky'       => [ false, [ '#^https?://bsky\.app/profile/.+/post/.+#i' ] ],
	];

	/**
	 * Shortcode tag pattern: a letter, then letters, digits, `_` or `-`.
	 */
	const SHORTCODE_TAG = '[a-z][a-z0-9_-]*';

	/**
	 * Shortcodes with their own block, in the order they are tried.
	 * Any other shortcode becomes a Shortcode block.
	 */
	const SHORTCODE_BLOCKS = [
		'caption' => 'core/image',
		'gallery' => 'core/gallery',
		'audio'   => 'core/audio',
		'embed'   => 'core/embed',
		'video'   => 'core/video',
	];

	/**
	 * Block delimiter pattern, as `WP_Block_Parser` reads delimiters.
	 */
	const BLOCK_DELIMITER = '/<!--[ \t\f\r\n]+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)[ \t\f\r\n]+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}[ \t\f\r\n]+\/?-->).)*+)?}[ \t\f\r\n]+)?(?P<void>\/)?-->/s';

	/**
	 * Gallery `link` values and the image link destination they set.
	 */
	const GALLERY_LINKS = [
		'post' => 'attachment',
		'file' => 'media',
	];

	/**
	 * Inline style properties and the typography attributes they set.
	 */
	const TYPOGRAPHY = [
		'font-weight'     => 'fontWeight',
		'font-style'      => 'fontStyle',
		'text-transform'  => 'textTransform',
		'text-decoration' => 'textDecoration',
		'letter-spacing'  => 'letterSpacing',
	];

	/**
	 * How deep `specs()` runs inside a classed `<div>`, whose HTML was already
	 * read once.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Receives a note for the conversion report, or null.
	 *
	 * @var callable|null
	 */
	private $notifier = null;

	/**
	 * Constructor.
	 *
	 * @param TableTarget  $table_target Target for tables.
	 * @param Presets|null $presets      Theme presets that inline colors, font sizes and font families match.
	 * @param array        $shortcodes   Shortcodes to replace or drop, keyed by tag (see `Map`).
	 * @param bool         $drop_empty   Whether to drop empty paragraphs.
	 * @param Classes      $classes      Class translations for inline elements.
	 */
	public function __construct(
		private TableTarget $table_target = new CoreTable(),
		private ?Presets $presets = null,
		private array $shortcodes = [],
		private bool $drop_empty = false,
		private Classes $classes = new Classes(),
	) {}

	/**
	 * Sets the callback that receives notes for the conversion report.
	 *
	 * @param callable|null $notifier Callback that takes one note string.
	 * @return void
	 */
	public function set_notifier( ?callable $notifier ): void {
		$this->notifier = $notifier;
	}

	/**
	 * Returns the embed provider slug and responsive setting for a URL, or an
	 * empty array when no provider pattern matches.
	 *
	 * @param string $url URL.
	 * @return array{slug?: string, responsive?: bool}
	 */
	public static function embed_provider( string $url ): array {
		foreach ( self::EMBED_PROVIDERS as $slug => [ $responsive, $patterns ] ) {
			foreach ( $patterns as $pattern ) {
				$matches = 1 === preg_match( $pattern, $url );

				if ( $matches ) {
					return [
						'slug'       => $slug,
						'responsive' => $responsive,
					];
				}
			}
		}

		return [];
	}

	/**
	 * Returns the block specs for HTML.
	 *
	 * @param string $html HTML.
	 * @return array[]
	 */
	public function specs( string $html ): array {
		$segments = $this->block_segments( $html );

		if ( null !== $segments ) {
			return $this->segment_specs( $segments );
		}

		$body    = $this->load( $html );
		$specs   = [];
		$pending = '';

		$this->color_spans( $body );

		if ( 0 === $this->depth ) {
			$this->inline_classes( $body );
		}

		$flush = function () use ( &$specs, &$pending ) {
			$trimmed = trim( $pending );

			if ( '' !== $trimmed ) {
				array_push( $specs, ...array_filter( $this->raw( $pending ), fn( array $spec ) => ! $this->drop_empty || ! $this->is_empty_paragraph( $spec ) ) );
			}

			$pending = '';
		};

		foreach ( $this->children( $body ) as $node ) {
			$node_tag = $node instanceof DOMElement ? $this->tag( $node ) : '';

			if ( 'div' === $node_tag && '' !== $node->getAttribute( 'class' ) ) {
				$flush();
				$specs[] = $this->group_spec( $node );
				continue;
			}

			// A shortcode that fills a classed `<div>` stands on its own line.
			$text              = trim( $node->textContent );
			$text_is_shortcode = 1 === preg_match( '/^\[[a-z][a-z0-9_-]*(\s[^\[\]]*)?\]$/i', $text );
			$is_shortcode_text = XML_TEXT_NODE === $node->nodeType && $text_is_shortcode;

			if ( $this->depth > 0 && $is_shortcode_text && $this->is_alone( $node ) ) {
				$flush();
				$specs[] = Spec::block( 'core/shortcode', [ 'text' => $text ] );
				continue;
			}

			$shortcode = $node instanceof DOMElement && 'p' === $this->tag( $node ) ? $this->standalone_shortcode( $node ) : null;

			if ( null !== $shortcode ) {
				$flush();
				array_push( $specs, ...$shortcode );
				continue;
			}

			if ( $node instanceof DOMElement && $this->has_inline_images( $node ) && ! $this->has_block_shortcode( $node ) ) {
				$flush();

				$attributes = [ 'content' => $this->inline_buttons( Html::strip_comments( $this->inner_html( $node ) ) ) ];
				$text_align = $this->text_align( $node );

				if ( '' !== $text_align ) {
					$attributes['style'] = [ 'typography' => [ 'textAlign' => $text_align ] ];
				}

				$specs[] = Spec::block( 'core/paragraph', self::merge( $attributes, $this->style_attributes( $node->getAttribute( 'style' ), false ) ) );
				continue;
			}

			if ( $node instanceof DOMElement ) {
				$pending .= $this->inline_buttons( $this->outer_html( $node ) );
			} elseif ( XML_TEXT_NODE === $node->nodeType ) {
				$pending .= $this->inline_buttons( $this->outer_html( $node ) );
			} else {
				$marker = $this->comment_marker( $node );

				if ( null !== $marker ) {
					$pending .= $this->outer_html( $node );
				}
			}
		}

		$flush();

		return $specs;
	}

	/**
	 * The raw handler: shortcodes on their own, then the HTML pieces between
	 * them through the DOM filters and the block transforms.
	 *
	 * @param string $html HTML.
	 * @return array[]
	 */
	private function raw( string $html ): array {
		$specs = [];

		foreach ( $this->shortcode_pieces( $html ) as $piece ) {
			if ( is_array( $piece ) ) {
				$specs[] = $piece;
				continue;
			}

			$body = $this->load( $piece );

			$this->convert_comments( $body );
			$this->filter( $body );
			$this->div_lines( $body );

			$body = $this->load( $this->normalise( $this->inner_html( $body ) ) );

			foreach ( $this->children( $body ) as $node ) {
				if ( $node instanceof DOMElement ) {
					$specs[] = $this->transform( $node );
				}
			}
		}

		return $specs;
	}

	/**
	 * Returns the block spec for a top-level element.
	 *
	 * @param DOMElement $node Element.
	 * @return array
	 */
	private function transform( DOMElement $node ): array {
		$tag  = $this->tag( $node );
		$spec = null;

		if ( 'wp-block' === $tag ) {
			$name = $node->getAttribute( 'data-block' );

			return Spec::block(
				$name,
				'core/more' === $name
					? [
						'customText' => $node->getAttribute( 'data-custom-text' ),
						'noTeaser'   => $node->hasAttribute( 'data-no-teaser' ),
					]
					: []
			);
		}

		$is_heading = 1 === preg_match( '/^h([1-6])$/', $tag, $matches );

		switch ( $tag ) {
			case 'ul':
			case 'ol':
				$spec = $this->list_spec( $node );
				break;
			case 'figure':
				if ( $this->first( $node, 'img' ) instanceof DOMElement ) {
					$spec = $this->image_spec( $node );
				}
				break;
			case 'blockquote':
				$spec = Spec::block( 'core/quote', [], $this->raw( $this->inner_html( $node ) ) );
				break;
			case 'table':
				$spec = $this->table_target->build( $this->table_data( $node ) );
				break;
			case 'hr':
				// The separator reads only block attributes from the element, so it keeps no class.
				return Spec::block( 'core/separator', [] );
			case 'p':
				if ( $this->is_embed( $node ) ) {
					$spec = Spec::block( 'core/embed', $this->embed_attributes( trim( $node->textContent ) ) );
				} elseif ( $this->is_video( $node ) ) {
					$spec = $this->video_spec( $this->first_element( $node ) );
				} elseif ( $this->is_source_video( $node ) ) {
					// A video that plays from `<source>` elements has no Video block attribute for them.
					return Spec::block( 'core/html', [ 'content' => $this->outer_html( $this->first_element( $node ) ) ] );
				} else {
					$spec = Spec::block( 'core/paragraph', $this->text_attributes( $node ) );
				}
				break;
			default:
				if ( $is_heading ) {
					$spec = Spec::block( 'core/heading', $this->text_attributes( $node, [ 'level' => (int) $matches[1] ] ) );
				}
				break;
		}

		if ( null === $spec ) {
			return Spec::block( 'core/html', [ 'content' => $this->outer_html( $node ) ] );
		}

		if ( $node->hasAttribute( 'class' ) ) {
			$spec['attributes']['className'] = $node->getAttribute( 'class' );
		}

		return $spec;
	}

	/**
	 * Returns a paragraph's or heading's attributes: content, anchor and
	 * text alignment.
	 *
	 * @param DOMElement $node       Element.
	 * @param array      $attributes Other attributes.
	 * @return array
	 */
	private function text_attributes( DOMElement $node, array $attributes = [] ): array {
		$content = $this->inner_html( $node );

		// Rich text drops white space and formats around no text; non-breaking spaces and line breaks stay.
		$blank_text = 1 === preg_match( '/^[ \t\n\r\f]*$/', wp_strip_all_tags( $content ) );
		$has_object = 1 === preg_match( '/<(img|br|iframe|video|audio|svg|input)\b/i', $content );
		$empty      = $blank_text && ! $has_object;
		$attributes = array_merge(
			[
				'content' => $empty ? '' : $content,
				'anchor'  => $node->getAttribute( 'id' ),
			],
			$attributes
		);
		$text_align = $this->text_align( $node );

		if ( in_array( $text_align, [ 'left', 'center', 'right' ], true ) ) {
			$attributes['style'] = [ 'typography' => [ 'textAlign' => $text_align ] ];
		} elseif ( '' !== $text_align ) {
			$this->note( 'dropped inline style: text-align' );
		}

		return self::merge( $attributes, $this->style_attributes( $node->getAttribute( 'style' ), false ) );
	}

	/**
	 * Returns the List spec for a list element, with nested lists.
	 *
	 * @param DOMElement $element List element.
	 * @return array
	 */
	private function list_spec( DOMElement $element ): array {
		$type       = $element->getAttribute( 'type' );
		$attributes = array_filter(
			[
				'ordered'  => 'ol' === $this->tag( $element ),
				'anchor'   => $element->getAttribute( 'id' ),
				'start'    => $element->hasAttribute( 'start' ) && '' !== $element->getAttribute( 'start' ) ? (int) $element->getAttribute( 'start' ) : null,
				'reversed' => $element->hasAttribute( 'reversed' ) ? true : null,
				'type'     => self::LIST_STYLES[ $type ] ?? null,
			],
			fn( $value ) => null !== $value && '' !== $value
		);

			$items = [];

			foreach ( $this->children( $element ) as $item ) {
				if ( ! $item instanceof DOMElement ) {
					continue;
				}

				$children = array_values( array_filter( $this->children( $item ), fn( DOMNode $child ) => XML_TEXT_NODE !== $child->nodeType || '' !== trim( $child->textContent ) ) );
				$last     = end( $children );
				$styles   = $this->style_attributes( $item->getAttribute( 'style' ), false );

				// A list item has no text alignment attribute.
				$item_align = $this->text_align( $item );

				if ( '' !== $item_align ) {
					$this->note( 'dropped inline style: text-align' );
				}

				if ( ! $last instanceof DOMElement || ! in_array( $this->tag( $last ), [ 'ul', 'ol' ], true ) ) {
					$items[] = Spec::block( 'core/list-item', array_merge( [ 'content' => $this->inner_html( $item ) ], $styles ) );
					continue;
				}

				// The text before the nested list keeps its entities and the white space between elements.
				$content = '';

				foreach ( $this->children( $item ) as $child ) {
					if ( $child === $last ) {
						break;
					}

					$content .= $this->outer_html( $child );
				}

				$items[] = Spec::block( 'core/list-item', array_merge( [ 'content' => trim( $content ) ], $styles ), [ $this->list_spec( $last ) ] );
			}

			return Spec::block( 'core/list', $attributes, $items );
	}

	/**
	 * Returns the Image spec for a figure that holds an image.
	 *
	 * @param DOMElement $figure Figure element.
	 * @return array
	 */
	private function image_spec( DOMElement $figure ): array {
		$image   = $this->first( $figure, 'img' );
		$link    = $this->first( $figure, 'a' );
		$classes = $figure->getAttribute( 'class' ) . ' ' . $image->getAttribute( 'class' );

		$width  = $this->pixels( $image->getAttribute( 'width' ) );
		$height = $this->pixels( $image->getAttribute( 'height' ) );

		// The link attributes come from the first link that sits directly in a figure.
		$figure_link   = ( new DOMXPath( $figure->ownerDocument ) )->query( 'descendant-or-self::figure/a', $figure )->item( 0 );
		$figure_link   = $figure_link instanceof DOMElement ? $figure_link : null;
		$caption       = $this->first( $figure, 'figcaption' );
		$has_link_href = $link instanceof DOMElement && $link->hasAttribute( 'href' );
		$has_align     = 1 === preg_match( '/(?:^|\s)align(left|center|right)(?:$|\s)/', $classes, $align );
		$has_id        = 1 === preg_match( '/(?:^|\s)wp-image-(\d+)(?:$|\s)/', $classes, $id );

		$attributes = [
			'url'             => $image->getAttribute( 'src' ),
			'alt'             => $image->getAttribute( 'alt' ),
			'caption'         => $caption instanceof DOMElement ? $this->inner_html( $caption ) : null,
			'title'           => $image->getAttribute( 'title' ),
			'href'            => $figure_link?->getAttribute( 'href' ),
			'rel'             => $figure_link?->getAttribute( 'rel' ),
			'linkClass'       => $figure_link?->getAttribute( 'class' ),
			'linkTarget'      => $figure_link?->getAttribute( 'target' ),
			'align'           => $has_align ? $align[1] : null,
			'id'              => $has_id ? (int) $id[1] : null,
			'linkDestination' => $has_link_href ? 'custom' : null,
			'anchor'          => $figure->getAttribute( 'id' ),
			'width'           => null !== $width ? $width : ( null !== $height ? 'auto' : null ),
			'height'          => null !== $width ? 'auto' : $height,
			'aspectRatio'     => null !== $width && null !== $height && (int) $height > 0 ? $this->ratio( (int) $width, (int) $height ) : null,
		];

		return Spec::block( 'core/image', array_filter( $attributes, fn( $value ) => null !== $value && '' !== $value ) );
	}

	/**
	 * Turns text written as one bare `<div>` per line into one paragraph,
	 * with a line break between the lines.
	 *
	 * A Custom HTML block for each `<div>` cannot be edited as text. Divi
	 * shows the lines one under the other with no space between them, as the
	 * line breaks do. The change applies only when every element of the HTML is a `<div>` without
	 * attributes that holds inline content, so no paragraph spacing appears
	 * between the lines and other blocks. Empty `<div>`s, which Divi shows
	 * with no height, are dropped.
	 *
	 * @param DOMElement $body Body element.
	 * @return void
	 */
	private function div_lines( DOMElement $body ): void {
		$lines = [];

		foreach ( $this->children( $body ) as $node ) {
			if ( XML_TEXT_NODE === $node->nodeType && $this->is_empty_node( $node ) ) {
				continue;
			}

			if ( ! $node instanceof DOMElement || ! $this->is_div_line( $node ) ) {
				return;
			}

			$is_empty = '' === trim( $node->textContent ) && 0 === $node->getElementsByTagName( 'img' )->length;

			if ( ! $is_empty ) {
				$lines[] = $node;
			}
		}

		if ( [] === $lines ) {
			return;
		}

		$paragraph = $body->ownerDocument->createElement( 'p' );

		foreach ( $lines as $index => $line ) {
			if ( $index > 0 ) {
				$paragraph->appendChild( $body->ownerDocument->createElement( 'br' ) );
			}

			while ( $line->firstChild ) {
				$paragraph->appendChild( $line->firstChild );
			}
		}

		while ( $body->firstChild ) {
			$body->removeChild( $body->firstChild );
		}

		$body->appendChild( $paragraph );
	}

	/**
	 * Whether an element is a `<div>` without attributes that holds only text
	 * and inline elements, or nothing.
	 *
	 * @param DOMElement $node Element.
	 * @return bool
	 */
	private function is_div_line( DOMElement $node ): bool {
		$node_tag = $this->tag( $node );

		if ( 'div' !== $node_tag || $node->attributes->length > 0 ) {
			return false;
		}

		foreach ( $node->getElementsByTagName( '*' ) as $descendant ) {
			if ( ! in_array( $this->tag( $descendant ), self::PHRASING, true ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Applies the raw handler's DOM filters to every node, children first:
	 * merges split lists, wraps images on their own in a figure and puts
	 * the text of a quote in paragraphs.
	 *
	 * @param DOMNode $node Node.
	 * @return void
	 */
	private function filter( DOMNode $node ): void {
		foreach ( $this->children( $node ) as $child ) {
			$this->filter( $child );

			if ( ! $child instanceof DOMElement || null === $child->parentNode ) {
				continue;
			}

			switch ( $this->tag( $child ) ) {
				case 'ul':
				case 'ol':
					$this->reduce_list( $child );
					break;
				case 'img':
					$this->wrap_image( $child );
					break;
				case 'blockquote':
					$this->replace_children( $child, $this->normalise( $this->inner_html( $child ) ) );
					break;
				default:
					break;
			}
		}
	}

	/**
	 * Merges a one-item list into the list before it, and moves a list that
	 * sits directly in another list into the previous item.
	 *
	 * @param DOMElement $element List element.
	 * @return void
	 */
	private function reduce_list( DOMElement $element ): void {
		$previous     = $this->previous_element( $element );
		$tag          = $this->tag( $element );
		$previous_tag = $previous instanceof DOMElement ? $this->tag( $previous ) : '';
		$item_count   = $this->element_count( $element );

		if ( $previous_tag === $tag && 1 === $item_count ) {
			while ( $element->firstChild ) {
				$previous->appendChild( $element->firstChild );
			}

			$element->parentNode->removeChild( $element );

			return;
		}

		$parent = $element->parentNode;

		$is_lone_item = $parent instanceof DOMElement && 'li' === $this->tag( $parent ) && 1 === $this->element_count( $parent );
		$item_text    = $is_lone_item ? trim( $this->shallow_text( $parent ) ) : '';

		if ( $is_lone_item && '' === $item_text ) {
			$previous_item = $this->previous_element( $parent );

			if ( $previous_item instanceof DOMElement ) {
				$previous_item->appendChild( $element );
				$parent->parentNode->removeChild( $parent );
			}
		}

		$parent = $element->parentNode;

		if ( $parent instanceof DOMElement && in_array( $this->tag( $parent ), [ 'ul', 'ol' ], true ) ) {
			$previous_item = $this->previous_element( $element );

			if ( $previous_item instanceof DOMElement ) {
				$previous_item->appendChild( $element );
			} else {
				while ( $element->firstChild ) {
					$parent->insertBefore( $element->firstChild, $element );
				}

				$parent->removeChild( $element );
			}
		}
	}

	/**
	 * Wraps an image in a figure when it stands alone in its paragraph or
	 * div, or is aligned. A link around only the image goes into the figure.
	 * An image that is already in a figure stays there.
	 *
	 * @param DOMElement $image Image element.
	 * @return void
	 */
	private function wrap_image( DOMElement $image ): void {
		$node   = $image;
		$parent = $image->parentNode;

		$parent_tag = $parent instanceof DOMElement ? $this->tag( $parent ) : '';

		if ( 'a' === $parent_tag && 1 === $parent->childNodes->length ) {
			$node = $parent;
		}

		$container = $node->parentNode;

		$container_tag = $container instanceof DOMElement ? $this->tag( $container ) : '';

		if ( 'figure' === $container_tag ) {
			return;
		}

		$wrapper = $this->closest( $node, [ 'p', 'div' ] );

		if ( $wrapper instanceof DOMElement ) {
			$aligned = 1 === preg_match( '/(?:^|\s)align(left|right|center)(?:$|\s)/', $image->getAttribute( 'class' ) );

			$wrapper_text = $this->js_trim( $wrapper->textContent );

			if ( $aligned || '' === $wrapper_text ) {
				$this->wrap_in_figure( $node, $wrapper );
			}

			return;
		}

		$this->wrap_in_figure( $node, $node );
	}

	/**
	 * Moves a node into a new figure placed before another node.
	 *
	 * @param DOMNode $node   Node to wrap.
	 * @param DOMNode $before Node to place the figure before.
	 * @return void
	 */
	private function wrap_in_figure( DOMNode $node, DOMNode $before ): void {
		$figure = $node->ownerDocument->createElement( 'figure' );

		$before->parentNode->insertBefore( $figure, $before );
		$figure->appendChild( $node );
	}

	/**
	 * Puts loose text and inline elements into paragraphs, turns two line
	 * breaks into a paragraph break, and drops the comments that
	 * `convert_comments()` left.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function normalise( string $html ): string {
		$source = $this->load( $html );
		$doc    = $this->load( '' );
		$target = $doc;

		while ( $source->firstChild ) {
			$node = $source->firstChild;
			$last = $target->lastChild;

			if ( XML_TEXT_NODE === $node->nodeType ) {
				if ( $this->is_empty_node( $node ) ) {
					$source->removeChild( $node );
					continue;
				}

				$this->paragraph_for( $target )->appendChild( $target->ownerDocument->importNode( $node, true ) );
				$source->removeChild( $node );
				continue;
			}

			if ( ! $node instanceof DOMElement ) {
				$source->removeChild( $node );
				continue;
			}

			$tag = $this->tag( $node );

			if ( 'br' === $tag ) {
				$next = $node->nextSibling;

				$next_tag = $next instanceof DOMElement ? $this->tag( $next ) : '';

				if ( 'br' === $next_tag ) {
					$target->appendChild( $target->ownerDocument->createElement( 'p' ) );
					$source->removeChild( $next );
				}

				$last = $target->lastChild;

				$last_tag = $last instanceof DOMElement ? $this->tag( $last ) : '';

				if ( 'p' === $last_tag && $last->hasChildNodes() ) {
					$last->appendChild( $target->ownerDocument->importNode( $node, true ) );
				}

				$source->removeChild( $node );
				continue;
			}

			if ( 'p' !== $tag && in_array( $tag, self::PHRASING, true ) ) {
				$this->paragraph_for( $target )->appendChild( $target->ownerDocument->importNode( $node, true ) );
			} else {
				$target->appendChild( $target->ownerDocument->importNode( $node, true ) );
			}

			$source->removeChild( $node );
		}

		return $this->inner_html( $target );
	}

	/**
	 * Returns the last paragraph of a container, adding one when the last
	 * child is not a paragraph.
	 *
	 * @param DOMElement $container Container.
	 * @return DOMElement
	 */
	private function paragraph_for( DOMElement $container ): DOMElement {
		$last = $container->lastChild;

		$last_tag = $last instanceof DOMElement ? $this->tag( $last ) : '';

		if ( 'p' === $last_tag ) {
			return $last;
		}

		return $container->appendChild( $container->ownerDocument->createElement( 'p' ) );
	}

	/**
	 * Returns the blocks for a paragraph that holds only a shortcode the
	 * converter replaces: Divi's `[button]`, or a shortcode the map replaces
	 * or drops. Returns null for any other paragraph.
	 *
	 * @param DOMElement $paragraph Paragraph.
	 * @return array[]|null Block specs, empty to drop the paragraph, or null.
	 */
	private function standalone_shortcode( DOMElement $paragraph ): ?array {
		$html  = trim( str_replace( [ '&nbsp;', "\u{00a0}" ], ' ', $this->inner_html( $paragraph ) ) );
		$match = '' !== $html ? $this->next_shortcode( $html, 0, '*' ) : null;

		$html_length = strlen( $html );

		$match_length = null !== $match ? strlen( $match['content'] ) : 0;

		if ( null === $match || 0 !== $match['index'] || $match_length !== $html_length ) {
			return null;
		}

		$tag = $match['tag'];

		if ( 'button' === $tag ) {
			return [ $this->button_spec( $match, $this->text_align( $paragraph ) ) ];
		}

		$action = $this->shortcode_action( $tag, $match['attrs'] );

		if ( null === $action ) {
			return null;
		}

		if ( 'skip' === $action ) {
			return [];
		}

		return str_contains( $action, '<!-- wp:' ) ? [ Spec::markup( $action ) ] : $this->specs( $action );
	}

	/**
	 * Returns what the map does with a shortcode: `skip`, markup to put in
	 * its place, or null to keep it.
	 *
	 * A map entry is `"skip"`, `{ "markup": "…" }`, or a list of those with
	 * an `attrs` object that the shortcode's attributes must match.
	 *
	 * @param string $tag   Shortcode tag.
	 * @param array  $attrs Shortcode attributes.
	 * @return string|null
	 */
	private function shortcode_action( string $tag, array $attrs ): ?string {
		$entries = $this->shortcodes[ $tag ] ?? null;

		if ( null === $entries ) {
			return null;
		}

		$entries = is_array( $entries ) && array_is_list( $entries ) ? $entries : [ $entries ];

		foreach ( $entries as $entry ) {
			if ( 'skip' === $entry ) {
				return 'skip';
			}

			if ( ! is_array( $entry ) || array_diff_assoc( (array) ( $entry['attrs'] ?? [] ), $attrs ) ) {
				continue;
			}

			if ( ! empty( $entry['skip'] ) ) {
				return 'skip';
			}

			if ( is_string( $entry['markup'] ?? null ) ) {
				return $entry['markup'];
			}
		}

		return null;
	}

	/**
	 * Returns the Buttons spec for Divi's `[button]` shortcode, justified as
	 * the paragraph that held it.
	 *
	 * @param array  $shortcode  Shortcode match (see `next_shortcode()`).
	 * @param string $text_align The paragraph's text alignment.
	 * @return array
	 */
	private function button_spec( array $shortcode, string $text_align ): array {
		$attrs   = $shortcode['attrs'];
		$new_tab = 'yes' === ( $attrs['newwindow'] ?? 'no' );
		$button  = [
			'text'       => trim( Html::strip_comments( $shortcode['inner'] ) ),
			'url'        => esc_url_raw( (string) ( $attrs['link'] ?? '#' ) ),
			'linkTarget' => $new_tab ? '_blank' : null,
			'rel'        => $new_tab ? 'noreferrer noopener' : null,
			'anchor'     => $attrs['id'] ?? null,
			'className'  => $this->button_classes( $attrs ),
		];

		$justify = in_array( $text_align, [ 'left', 'center', 'right' ], true ) ? $text_align : null;

		return Spec::block(
			'core/buttons',
			null !== $justify ? [
				'layout' => [
					'type'           => 'flex',
					'justifyContent' => $justify,
				],
			] : [],
			[ Spec::block( 'core/button', $button ) ]
		);
	}

	/**
	 * Returns the classes Divi gives a `[button]`: its type, its color, and
	 * the shortcode's own class, so the theme can style them.
	 *
	 * @param array $attrs Shortcode attributes.
	 * @return string
	 */
	private function button_classes( array $attrs ): string {
		$type  = (string) ( $attrs['type'] ?? 'small' );
		$color = (string) ( $attrs['color'] ?? 'blue' );
		$extra = trim( (string) ( $attrs['class'] ?? '' ) );

		$classes = 'icon' === $type
			? [ 'icon-button', ( $attrs['icon'] ?? 'download' ) . '-icon' ]
			: [ $type . '-button', $type . $color ];

		return trim( implode( ' ', $classes ) . ' ' . $extra );
	}

	/**
	 * Turns Divi's `[button]` shortcodes inside text into links styled as
	 * buttons, since the shortcode stops working without Divi.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function inline_buttons( string $html ): string {
		if ( ! str_contains( $html, '[button' ) ) {
			return $html;
		}

		return (string) preg_replace_callback(
			$this->shortcode_pattern( 'button' ),
			function ( array $matches ) {
				if ( '[' === $matches[1] && ']' === ( $matches[7] ?? '' ) ) {
					return $matches[0];
				}

				$attrs  = shortcode_parse_atts( $matches[3] );
				$attrs  = is_array( $attrs ) ? $attrs : [];
				$target = 'yes' === ( $attrs['newwindow'] ?? 'no' ) ? ' target="_blank" rel="noreferrer noopener"' : '';

				return $matches[1] . '<a class="wp-element-button" href="' . esc_url( $attrs['link'] ?? '#' ) . '"' . $target . '>' . ( $matches[5] ?? '' ) . '</a>' . ( $matches[7] ?? '' );
			},
			$html
		);
	}

	/**
	 * Returns block attributes for an element's inline styles: colors, font
	 * sizes and font families that match the theme's presets become their
	 * preset attributes (`textColor`, `fontSize`, …), other values go to the
	 * style attribute. Properties without a block attribute are left out.
	 *
	 * @param string $styles     Inline styles.
	 * @param bool   $text_align Whether to read the text alignment too.
	 * @return array
	 */
	private function style_attributes( string $styles, bool $text_align ): array {
		$attributes = [];

		foreach ( $this->declarations( $styles ) as $property => $value ) {
			switch ( $property ) {
				case 'text-align':
					if ( $text_align && in_array( $value, [ 'left', 'center', 'right' ], true ) ) {
						$attributes['style']['typography']['textAlign'] = $value;
					}
					break;
				case 'color':
					$attributes = self::merge( $attributes, $this->preset( 'color', $value, 'textColor', [ 'color', 'text' ] ) );
					break;
				case 'background-color':
					$attributes = self::merge( $attributes, $this->preset( 'color', $value, 'backgroundColor', [ 'color', 'background' ] ) );
					break;
				case 'font-size':
					$attributes = self::merge( $attributes, $this->preset( 'font-size', $value, 'fontSize', [ 'typography', 'fontSize' ] ) );
					break;
				case 'font-family':
					$attributes = self::merge( $attributes, $this->preset( 'font-family', $value, 'fontFamily', [ 'typography', 'fontFamily' ] ) );
					break;
				case 'line-height':
					$attributes['style']['typography']['lineHeight'] = (string) preg_replace( '/^(\d*\.?\d+)em$/', '$1', $value );
					break;
				case 'margin':
				case 'padding':
					$attributes['style']['spacing'][ $property ] = $this->box( $value );
					break;
				default:
					$is_side = 1 === preg_match( '/^(margin|padding)-(top|right|bottom|left)$/', $property, $side );

					if ( isset( self::TYPOGRAPHY[ $property ] ) ) {
						$attributes['style']['typography'][ self::TYPOGRAPHY[ $property ] ] = $value;
					} elseif ( $is_side ) {
						$attributes['style']['spacing'][ $side[1] ][ $side[2] ] = $value;
					} else {
						$this->note( 'dropped inline style: ' . $property );
					}
			}
		}

		return $attributes;
	}

	/**
	 * Returns the attributes for a color, font size or font family: the
	 * preset attribute when the value matches a theme preset, otherwise the
	 * value in the style attribute.
	 *
	 * @param string   $kind      Preset kind: `color`, `font-size` or `font-family`.
	 * @param string   $value     CSS value.
	 * @param string   $attribute Preset attribute, such as `textColor`.
	 * @param string[] $path      Path in the style attribute, such as `[ 'color', 'text' ]`.
	 * @return array
	 */
	private function preset( string $kind, string $value, string $attribute, array $path ): array {
		$reference = $value;

		if ( $this->presets instanceof Presets ) {
			$reference = match ( $kind ) {
				'color'     => $this->presets->color( $value ),
				'font-size' => $this->presets->font_size( $value ),
				default     => $this->presets->font_family( trim( (string) strtok( $value, ',' ), " \"'" ) ),
			};

			if ( 'font-family' === $kind && ! str_starts_with( $reference, 'var:' ) ) {
				$reference = $value;
			}
		}

		if ( '' === $reference ) {
			return [];
		}

		$prefix = 'var:preset|' . $kind . '|';

		if ( str_starts_with( $reference, $prefix ) ) {
			return [ $attribute => substr( $reference, strlen( $prefix ) ) ];
		}

		return [ 'style' => [ $path[0] => [ $path[1] => $reference ] ] ];
	}

	/**
	 * Returns the sides of a `margin` or `padding` shorthand.
	 *
	 * @param string $value CSS value: one to four lengths.
	 * @return array<string, string>
	 */
	private function box( string $value ): array {
		$parts = preg_split( '/\s+/', trim( $value ) );
		$top   = $parts[0];
		$right = $parts[1] ?? $top;

		return [
			'top'    => $top,
			'right'  => $right,
			'bottom' => $parts[2] ?? $top,
			'left'   => $parts[3] ?? $right,
		];
	}

	/**
	 * Returns an inline style's declarations, without `!important`.
	 *
	 * @param string $styles Inline styles.
	 * @return array<string, string> Values keyed by lowercase property.
	 */
	private function declarations( string $styles ): array {
		$declarations = [];

		foreach ( explode( ';', $styles ) as $declaration ) {
			$separator = strpos( $declaration, ':' );

			if ( false === $separator ) {
				continue;
			}

			$property = strtolower( trim( substr( $declaration, 0, $separator ) ) );
			$value    = trim( (string) preg_replace( '/\s*!important\s*$/i', '', substr( $declaration, $separator + 1 ) ) );

			if ( '' !== $property && '' !== $value ) {
				$declarations[ $property ] = $value;
			}
		}

		return $declarations;
	}

	/**
	 * Turns `<span>`s that only set a text or background color into the
	 * text color format: `<mark class="has-inline-color">`, with the
	 * palette color's class when the color is in the palette.
	 *
	 * @param DOMElement $body Document body.
	 * @return void
	 */
	private function color_spans( DOMElement $body ): void {
		foreach ( iterator_to_array( $body->getElementsByTagName( 'span' ) ) as $span ) {
			$declarations = $this->declarations( $span->getAttribute( 'style' ) );

			$other_properties = array_diff( array_keys( $declarations ), [ 'color', 'background-color' ] );

			if ( [] === $declarations || [] !== $other_properties || $span->hasAttribute( 'class' ) || $span->hasAttribute( 'id' ) ) {
				continue;
			}

			$classes = [ 'has-inline-color' ];
			$styles  = [ 'background-color:' . ( $declarations['background-color'] ?? 'rgba(0, 0, 0, 0)' ) ];

			if ( isset( $declarations['color'] ) ) {
				$color = $this->presets instanceof Presets ? $this->presets->color( $declarations['color'] ) : $declarations['color'];

				if ( str_starts_with( $color, 'var:preset|color|' ) ) {
					$classes[] = 'has-' . _wp_to_kebab_case( substr( $color, strlen( 'var:preset|color|' ) ) ) . '-color';
				} else {
					$styles[] = 'color:' . $declarations['color'];
				}
			}

			$mark = $span->ownerDocument->createElement( 'mark' );
			$mark->setAttribute( 'style', implode( ';', $styles ) );
			$mark->setAttribute( 'class', implode( ' ', $classes ) );

			while ( $span->firstChild ) {
				$mark->appendChild( $span->firstChild );
			}

			$span->parentNode->replaceChild( $mark, $span );
		}
	}

	/**
	 * Merges attributes; nested arrays merge, other values replace.
	 *
	 * @param array $target Base attributes.
	 * @param array $source Attributes to merge in.
	 * @return array
	 */
	private static function merge( array $target, array $source ): array {
		foreach ( $source as $key => $value ) {
			$target[ $key ] = is_array( $value ) && is_array( $target[ $key ] ?? null ) ? self::merge( $target[ $key ], $value ) : $value;
		}

		return $target;
	}

	/**
	 * Splits HTML at shortcodes that stand on their own line, or that wrap
	 * HTML, which become blocks.
	 *
	 * It picks the first shortcode block whose tag appears in the HTML, and
	 * converts the shortcodes with that tag; the HTML around each one goes
	 * through the same steps.
	 *
	 * @param string   $html     HTML.
	 * @param int      $index    Offset to search from.
	 * @param string[] $excluded Shortcode tags to leave to the next block.
	 * @return array<string|array> HTML pieces and block specs.
	 */
	private function shortcode_pieces( string $html, int $index = 0, array $excluded = [] ): array {
		$tag = $this->shortcode_tag( $html, $excluded );

		if ( null === $tag ) {
			return [ $html ];
		}

		$match = $this->next_shortcode( $html, $index, $tag );

		if ( null === $match ) {
			return [ $html ];
		}

		$end    = $match['index'] + strlen( $match['content'] );
		$before = substr( $html, 0, $match['index'] );
		$after  = substr( $html, $end );

		$before_line = '/(\n|<p>|<br\s*\/?>)\s*$/';
		$after_line  = '/^\s*(\n|<\/p>|<br\s*\/?>)/';
		$own_line    = 1 === preg_match( $before_line, $before ) && 1 === preg_match( $after_line, $after );

		if ( ! str_contains( $match['inner'], '<' ) && ! $own_line ) {
			return $this->shortcode_pieces( $html, $end );
		}

		if ( 'gallery' === $tag && ! isset( $match['attrs']['ids'] ) ) {
			return $this->shortcode_pieces( $html, $index, [ ...$excluded, $tag ] );
		}

		return [
			...$this->shortcode_pieces( (string) preg_replace( $before_line, '', $before ) ),
			$this->shortcode_spec( $tag, $match ),
			...$this->shortcode_pieces( (string) preg_replace( $after_line, '', $after ) ),
		];
	}

	/**
	 * Returns the tag of the first shortcode block that appears in the HTML:
	 * a shortcode with its own block, or any shortcode for the Shortcode
	 * block.
	 *
	 * @param string   $html     HTML.
	 * @param string[] $excluded Tags to skip.
	 * @return string|null Shortcode tag, `*` for any shortcode, or null.
	 */
	private function shortcode_tag( string $html, array $excluded ): ?string {
		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( self::SHORTCODE_BLOCKS as $tag => $block ) {
			if ( in_array( $tag, $excluded, true ) || ! $registry->is_registered( $block ) ) {
				continue;
			}

			$found = 1 === preg_match( $this->shortcode_pattern( $tag ), $html );

			if ( $found ) {
				return $tag;
			}
		}

		$found = 1 === preg_match( $this->shortcode_pattern( '*' ), $html );

		return $found ? '*' : null;
	}

	/**
	 * Whether an element holds a shortcode that has its own block, such as
	 * `[caption]`.
	 *
	 * @param DOMElement $node Element.
	 * @return bool
	 */
	private function has_block_shortcode( DOMElement $node ): bool {
		$html = $this->inner_html( $node );
		$tag  = str_contains( $html, '[' ) ? $this->shortcode_tag( $html, [] ) : null;

		return null !== $tag && '*' !== $tag;
	}

	/**
	 * Returns the block spec for a shortcode.
	 *
	 * @param string $tag       Shortcode tag, or `*` for the Shortcode block.
	 * @param array  $shortcode Shortcode match (see `next_shortcode()`).
	 * @return array
	 */
	private function shortcode_spec( string $tag, array $shortcode ): array {
		// A block delimiter in the shortcode would end the block early, so its comments go.
		$shortcode['content'] = Html::strip_comments( $shortcode['content'] );
		$shortcode['inner']   = Html::strip_comments( $shortcode['inner'] );

		$attrs = $shortcode['attrs'];

		switch ( $tag ) {
			case 'caption':
				$spec = Spec::block( 'core/image', $this->caption_attributes( $shortcode['inner'], $attrs ) );
				break;
			case 'gallery':
				$spec = $this->gallery_spec( $attrs );
				break;
			case 'audio':
				$spec = Spec::block(
					'core/audio',
					[
						'src'      => $attrs['src'] ?? $attrs['mp3'] ?? $attrs['m4a'] ?? $attrs['ogg'] ?? $attrs['wav'] ?? $attrs['wma'] ?? null,
						'loop'     => self::flag( $attrs, 'loop' ),
						'autoplay' => self::flag( $attrs, 'autoplay' ),
						'preload'  => $attrs['preload'] ?? null,
					]
				);
				break;
			case 'video':
				$spec = Spec::block(
					'core/video',
					[
						'src'      => $attrs['src'] ?? $attrs['mp4'] ?? $attrs['m4v'] ?? $attrs['webm'] ?? $attrs['ogv'] ?? $attrs['flv'] ?? null,
						'poster'   => $attrs['poster'] ?? null,
						'loop'     => self::flag( $attrs, 'loop' ),
						'autoplay' => self::flag( $attrs, 'autoplay' ),
						'preload'  => $attrs['preload'] ?? null,
					]
				);
				break;
			case 'embed':
				$spec = Spec::block( 'core/embed', $this->embed_attributes( trim( $shortcode['inner'] ) ) );
				break;
			default:
				return Spec::block( 'core/shortcode', [ 'text' => trim( $shortcode['content'] ) ] );
		}

		return $this->fix_attributes( $spec, $shortcode['inner'] );
	}

	/**
	 * Returns an Image block's attributes from a `[caption]` shortcode.
	 *
	 * @param string $content Shortcode content: the image, maybe in a link, then the caption.
	 * @param array  $attrs   Shortcode attributes.
	 * @return array
	 */
	private function caption_attributes( string $content, array $attrs ): array {
		$body  = $this->load( $content );
		$image = $this->first( $body, 'img' );
		$first = $this->first_element( $body );
		$link  = $first instanceof DOMElement && 'a' === $this->tag( $first ) ? $first : null;

		$attributes = [
			'url'       => $image?->hasAttribute( 'src' ) ? $image->getAttribute( 'src' ) : null,
			'alt'       => $image?->hasAttribute( 'alt' ) ? $image->getAttribute( 'alt' ) : null,
			'caption'   => $this->strip_first_image( $content ),
			'href'      => $link?->hasAttribute( 'href' ) ? $link->getAttribute( 'href' ) : null,
			'rel'       => $link?->hasAttribute( 'rel' ) ? $link->getAttribute( 'rel' ) : null,
			'linkClass' => $link?->hasAttribute( 'class' ) ? $link->getAttribute( 'class' ) : null,
			'align'     => preg_replace( '/align/', '', (string) ( $attrs['align'] ?? 'alignnone' ), 1 ),
		];

		$attachment = str_replace( 'attachment_', '', (string) ( $attrs['id'] ?? '' ) );
		$has_id     = 1 === preg_match( '/^\s*(\d+)/', $attachment, $id );

		if ( ! empty( $attrs['id'] ) && $has_id ) {
			$attributes['id'] = (int) $id[1];
		}

		return array_filter( $attributes, fn( $value ) => null !== $value );
	}

	/**
	 * Returns shortcode content without its first image and the element
	 * that holds it, such as a link.
	 *
	 * @param string $content Shortcode content.
	 * @return string
	 */
	private function strip_first_image( string $content ): string {
		$body = $this->load( $content );
		$node = $this->first( $body, 'img' );

		while ( $node instanceof DOMNode && $node->parentNode instanceof DOMNode && $node->parentNode !== $body ) {
			$node = $node->parentNode;
		}

		if ( $node instanceof DOMNode && $node->parentNode === $body ) {
			$body->removeChild( $node );
		}

		return trim( $this->inner_html( $body ) );
	}

	/**
	 * Returns the Gallery spec for a `[gallery]` shortcode, with an Image
	 * block per attachment.
	 *
	 * @param array $attrs Shortcode attributes.
	 * @return array
	 */
	private function gallery_spec( array $attrs ): array {
		$size     = (string) ( $attrs['size'] ?? '' );
		$link_to  = self::GALLERY_LINKS[ $attrs['link'] ?? '' ] ?? 'none';
		$images   = [];
		$ids      = array_filter( array_map( 'trim', explode( ',', (string) $attrs['ids'] ) ), fn( $id ) => 1 === preg_match( '/^-?\d/', $id ) );
		$size_url = '' !== $size ? $size : 'large';

		foreach ( $ids as $id ) {
			$images[] = Spec::block(
				'core/image',
				array_merge(
					[
						'id'       => (int) $id,
						'sizeSlug' => '' !== $size ? $size : null,
					],
					$this->gallery_image( (int) $id, $size_url, $link_to )
				)
			);
		}

		return Spec::block(
			'core/gallery',
			[
				'columns'     => (int) ( $attrs['columns'] ?? 3 ),
				'linkTo'      => $link_to,
				'randomOrder' => 'rand' === ( $attrs['orderby'] ?? '' ),
				'sizeSlug'    => '' !== $size ? $size : null,
			],
			$images
		);
	}

	/**
	 * Returns a gallery image's attributes from its attachment: URL at the
	 * gallery's size, alternative text, caption and link. Empty when the
	 * attachment does not exist.
	 *
	 * @param int    $id      Attachment ID.
	 * @param string $size    Image size.
	 * @param string $link_to Gallery link destination: `none`, `media` or `attachment`.
	 * @return array
	 */
	private function gallery_image( int $id, string $size, string $link_to ): array {
		$attachment = get_post( $id );

		if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
			return [];
		}

		$source = wp_get_attachment_image_src( $id, $size );
		$link   = [
			'media'      => wp_get_attachment_url( $id ),
			'attachment' => get_permalink( $id ),
		];

		return [
			'url'             => is_array( $source ) ? $source[0] : wp_get_attachment_url( $id ),
			'alt'             => (string) get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'         => $attachment->post_excerpt,
			'sizeSlug'        => $size,
			'href'            => isset( $link[ $link_to ] ) && is_string( $link[ $link_to ] ) ? $link[ $link_to ] : null,
			'linkDestination' => $link_to,
		];
	}

	/**
	 * Fixes a block made from a shortcode: classes,
	 * ID and label on the content's first element carry over when the block
	 * supports them.
	 *
	 * @param array  $spec    Block spec.
	 * @param string $content Shortcode content.
	 * @return array
	 */
	private function fix_attributes( array $spec, string $content ): array {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( $spec['name'] );

		if ( ! $type instanceof WP_Block_Type ) {
			return $spec;
		}

		$root     = $this->first_element( $this->load( $content ) );
		$supports = $type->supports ?? [];

		if ( false !== ( $supports['customClassName'] ?? true ) ) {
			$without = $spec;
			unset( $without['attributes']['className'] );
			$without['innerBlocks'] = [];

			$markup   = (string) preg_replace( '/<!--.*?-->/s', '', ( new Serializer() )->serialize( [ $without ] ) );
			$defaults = $this->classes( $this->first_element( $this->load( $markup ) ) );
			$custom   = array_values( array_diff( $this->classes( $root ), $defaults ) );

			$trimmed_markup = trim( $markup );

			if ( [] !== $custom ) {
				$spec['attributes']['className'] = implode( ' ', $custom );
			} elseif ( '' !== $trimmed_markup ) {
				unset( $spec['attributes']['className'] );
			}
		}

		if ( ! empty( $supports['ariaLabel'] ) && $root?->hasAttribute( 'aria-label' ) && '' !== $root->getAttribute( 'aria-label' ) ) {
			$spec['attributes']['ariaLabel'] = $root->getAttribute( 'aria-label' );
		}

		if ( ! empty( $supports['anchor'] ) && $root?->hasAttribute( 'id' ) && '' !== $root->getAttribute( 'id' ) ) {
			$spec['attributes']['anchor'] = $root->getAttribute( 'id' );
		}

		return $spec;
	}

	/**
	 * Returns the pattern that matches a shortcode with a tag.
	 *
	 * @param string $tag Shortcode tag, or `*` for any shortcode.
	 * @return string
	 */
	private function shortcode_pattern( string $tag ): string {
		$tag = '*' === $tag ? self::SHORTCODE_TAG : preg_quote( $tag, '/' );

		return '/\[(\[?)(' . $tag . ')(?![\w-])([^\]\/]*(?:\/(?!\])[^\]\/]*)*?)(?:(\/)\]|\](?:([^\[]*(?:\[(?!\/\2\])[^\[]*)*)(\[\/\2\]))?)(\]?)/';
	}

	/**
	 * Finds the next shortcode with a tag, skipping escaped ones (`[[tag]]`).
	 *
	 * @param string $html  HTML.
	 * @param int    $index Offset to search from.
	 * @param string $tag   Shortcode tag, or `*` for any shortcode.
	 * @return array{index: int, content: string, tag: string, inner: string, attrs: array}|null
	 */
	private function next_shortcode( string $html, int $index, string $tag ): ?array {
		$found = 1 === preg_match( $this->shortcode_pattern( $tag ), $html, $matches, PREG_OFFSET_CAPTURE, $index );

		if ( ! $found ) {
			return null;
		}

		$open  = $matches[1][0] ?? '';
		$close = $matches[7][0] ?? '';

		if ( '[' === $open && ']' === $close ) {
			return $this->next_shortcode( $html, $matches[0][1] + strlen( $matches[0][0] ), $tag );
		}

		$content = $matches[0][0];
		$offset  = $matches[0][1];

		if ( '' !== $open ) {
			$content = substr( $content, 1 );
			++$offset;
		}

		if ( '' !== $close ) {
			$content = substr( $content, 0, -1 );
		}

		$attrs = shortcode_parse_atts( $matches[3][0] ?? '' );

		return [
			'index'   => $offset,
			'content' => $content,
			'tag'     => $matches[2][0],
			'inner'   => isset( $matches[5] ) && $matches[5][1] >= 0 ? $matches[5][0] : '',
			'attrs'   => is_array( $attrs ) ? $attrs : [],
		];
	}

	/**
	 * Returns a table's sections, cells and caption.
	 *
	 * Rows outside a section go to the body, as browsers read them. The rows
	 * of several sections of one kind join in their order.
	 *
	 * @param DOMElement $table Table element.
	 * @return TableData
	 */
	private function table_data( DOMElement $table ): TableData {
		$sections = [];
		$loose    = [];
		$caption  = null;

		$add = function ( string $section, array $rows ) use ( &$sections ) {
			$sections[ $section ] = array_merge( $sections[ $section ] ?? [], $this->table_rows( $rows ) );
		};

		$flush = function () use ( $add, &$loose ) {
			if ( [] !== $loose ) {
				$add( 'body', $loose );
				$loose = [];
			}
		};

		foreach ( $this->children( $table ) as $child ) {
			if ( ! $child instanceof DOMElement ) {
				continue;
			}

			$tag = $this->tag( $child );

			if ( 'tr' === $tag ) {
				$loose[] = $child;
				continue;
			}

			$flush();

			if ( in_array( $tag, [ 'thead', 'tbody', 'tfoot' ], true ) ) {
				$add( substr( $tag, 1 ), $this->children( $child ) );
			} elseif ( 'caption' === $tag ) {
				$caption = $child;
			}
		}

		$flush();

		return new TableData(
			sections: $sections,
			styles: $table->getAttribute( 'style' ),
			class_name: $table->getAttribute( 'class' ),
			caption: $caption instanceof DOMElement ? $this->inner_html( $caption ) : '',
			caption_styles: $caption instanceof DOMElement ? $caption->getAttribute( 'style' ) : '',
		);
	}

	/**
	 * Returns the cells of table rows; rows without cells are left out.
	 *
	 * @param DOMNode[] $rows Row elements.
	 * @return TableCell[][]
	 */
	private function table_rows( array $rows ): array {
		$cells = [];

		foreach ( $rows as $row ) {
			$row_elements = $row instanceof DOMElement ? $this->element_count( $row ) : 0;

			if ( ! $row instanceof DOMElement || 0 === $row_elements ) {
				continue;
			}

			$cells[] = array_map(
				fn( DOMElement $cell ) => new TableCell(
					tag: $this->tag( $cell ),
					content: $this->inner_html( $cell ),
					styles: $cell->getAttribute( 'style' ),
					class_name: $cell->getAttribute( 'class' ),
					id: $cell->getAttribute( 'id' ),
					headers: $cell->getAttribute( 'headers' ),
					scope: $cell->getAttribute( 'scope' ),
					rowspan: $cell->getAttribute( 'rowspan' ),
					colspan: $cell->getAttribute( 'colspan' ),
				),
				array_values( array_filter( $this->children( $row ), fn( DOMNode $node ) => $node instanceof DOMElement ) )
			);
		}

		return $cells;
	}

	/**
	 * Whether a paragraph holds only a video element with a source URL.
	 *
	 * @param DOMElement $node Paragraph.
	 * @return bool
	 */
	private function is_video( DOMElement $node ): bool {
		$video = $this->lone_video( $node );
		$src   = $video instanceof DOMElement ? $video->getAttribute( 'src' ) : '';

		return '' !== $src;
	}

	/**
	 * Whether a paragraph holds only a video element without a source URL,
	 * such as a video that plays from `<source>` elements.
	 *
	 * @param DOMElement $node Paragraph.
	 * @return bool
	 */
	private function is_source_video( DOMElement $node ): bool {
		$video = $this->lone_video( $node );
		$src   = $video instanceof DOMElement ? $video->getAttribute( 'src' ) : null;

		return '' === $src;
	}

	/**
	 * Returns the video element that a paragraph holds on its own, or null.
	 * White space around the video is allowed, since rich text in a
	 * paragraph would drop the video.
	 *
	 * @param DOMElement $node Paragraph.
	 * @return DOMElement|null
	 */
	private function lone_video( DOMElement $node ): ?DOMElement {
		$video = $this->first_element( $node );
		$count = $this->element_count( $node );
		$text  = trim( $node->textContent );

		$video_tag = $video instanceof DOMElement ? $this->tag( $video ) : '';

		if ( 1 !== $count || 'video' !== $video_tag || '' !== $text ) {
			return null;
		}

		return $video;
	}

	/**
	 * Returns the Video spec for a video element.
	 *
	 * @param DOMElement $video Video element.
	 * @return array
	 */
	private function video_spec( DOMElement $video ): array {
		return Spec::block(
			'core/video',
			array_filter(
				[
					'autoplay'    => $video->hasAttribute( 'autoplay' ) ? true : null,
					'controls'    => $video->hasAttribute( 'controls' ) ? null : false,
					'loop'        => $video->hasAttribute( 'loop' ) ? true : null,
					'muted'       => $video->hasAttribute( 'muted' ) ? true : null,
					'preload'     => $this->attribute( $video, 'preload' ),
					'playsInline' => $video->hasAttribute( 'playsinline' ) ? true : null,
					'poster'      => $this->attribute( $video, 'poster' ),
					'src'         => $this->attribute( $video, 'src' ),
				],
				fn( $value ) => null !== $value
			)
		);
	}

	/**
	 * Returns an attribute's value, or null when it is empty or missing.
	 *
	 * @param DOMElement $node Element.
	 * @param string     $name Attribute name.
	 * @return string|null
	 */
	private function attribute( DOMElement $node, string $name ): ?string {
		$value = $node->getAttribute( $name );

		return '' !== $value ? $value : null;
	}

	/**
	 * Whether a paragraph holds only one HTTPS URL, not to a file, which
	 * becomes an embed.
	 *
	 * @param DOMElement $node Paragraph.
	 * @return bool
	 */
	private function is_embed( DOMElement $node ): bool {
		$url       = trim( $node->textContent );
		$is_https  = 1 === preg_match( '#^https://#i', $url );
		$url_count = preg_match_all( '#https://#i', $url );
		$valid     = filter_var( $url, FILTER_VALIDATE_URL );

		if ( ! $is_https || 1 !== $url_count || false === $valid ) {
			return false;
		}

		$path     = (string) wp_parse_url( $url, PHP_URL_PATH );
		$filename = basename( $path );
		$is_file  = 1 === preg_match( '/\.(?!(html?|php)$)[a-z0-9]+$/i', $filename );

		return ! $is_file;
	}

	/**
	 * Returns an embed's attributes: its URL, and the provider and responsive
	 * setting when a provider's pattern matches.
	 *
	 * @param string $url URL.
	 * @return array
	 */
	private function embed_attributes( string $url ): array {
		// x.com links embed as twitter.com.
		$url = (string) preg_replace( '#^(https?://)x\.com(?=[/?\#:]|$)#i', '$1twitter.com', $url );

		$provider = self::embed_provider( $url );

		if ( [] === $provider ) {
			return [ 'url' => $url ];
		}

		return array_filter(
			[
				'url'              => $url,
				'providerNameSlug' => $provider['slug'],
				'responsive'       => $provider['responsive'],
			]
		);
	}

	/**
	 * Returns true when a shortcode attribute turns a media option on, or
	 * null when the attribute is absent or off.
	 *
	 * @param array  $attrs Shortcode attributes.
	 * @param string $name  Attribute name.
	 * @return bool|null
	 */
	private static function flag( array $attrs, string $name ): ?bool {
		return isset( $attrs[ $name ] ) && wp_validate_boolean( $attrs[ $name ] ) ? true : null;
	}

	/**
	 * Reports a note to the conversion report.
	 *
	 * @param string $note Note.
	 * @return void
	 */
	private function note( string $note ): void {
		if ( null !== $this->notifier ) {
			( $this->notifier )( $note );
		}
	}

	/**
	 * Whether a paragraph holds an image next to other images or text.
	 *
	 * @param DOMElement $node Element.
	 * @return bool
	 */
	private function has_inline_images( DOMElement $node ): bool {
		$node_tag = $this->tag( $node );

		if ( 'p' !== $node_tag ) {
			return false;
		}

		$images = $node->getElementsByTagName( 'img' )->length;
		$text   = preg_replace( '/[\s\x{00a0}]/u', '', $node->textContent );

		return $images > 1 || ( 1 === $images && '' !== $text );
	}

	/**
	 * Whether a spec is a paragraph without visible content, such as the
	 * `<p>&nbsp;</p>` spacers of classic content.
	 *
	 * @param array $spec Block spec.
	 * @return bool
	 */
	private function is_empty_paragraph( array $spec ): bool {
		if ( 'core/paragraph' !== ( $spec['name'] ?? '' ) ) {
			return false;
		}

		$html       = (string) ( $spec['attributes']['content'] ?? '' );
		$has_object = 1 === preg_match( '/<img|<iframe|<br/i', $html );
		$text       = preg_replace( '/[\s\x{00a0}]|&nbsp;/u', '', wp_strip_all_tags( $html ) );

		return ! $has_object && '' === $text;
	}

	/**
	 * Loads HTML and returns its body.
	 *
	 * @param string $html HTML.
	 * @return DOMElement
	 */
	private function load( string $html ): DOMElement {
		$doc = new DOMDocument();

		// HTML parsers read a stray `</br>` as a line break, and a stray `</p>` as an empty paragraph.
		$html = $this->stray_paragraph_ends( (string) preg_replace( '#</br\s*>#i', '<br>', $html ) );

		$doc->loadHTML(
			'<!DOCTYPE html><html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head><body>' . $html . '</body></html>',
			LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_NONET
		);

		$body = $doc->getElementsByTagName( 'body' )->item( 0 );

		if ( ! $body instanceof DOMElement ) {
			$body = $doc->appendChild( $doc->createElement( 'body' ) );
		}

		return $body;
	}

	/**
	 * Turns each `</p>` that closes no paragraph into `<p></p>`, as HTML
	 * parsers do. A paragraph closes when one opens, and before a block
	 * element.
	 *
	 * @param string $html HTML.
	 * @return string
	 */
	private function stray_paragraph_ends( string $html ): string {
		if ( ! str_contains( strtolower( $html ), '</p' ) ) {
			return $html;
		}

		$open = false;

		return (string) preg_replace_callback(
			'#<(/?)([a-z][a-z0-9]*)\b[^>]*>#i',
			function ( array $matches ) use ( &$open ) {
				$closing = '/' === $matches[1];
				$tag     = strtolower( $matches[2] );

				if ( 'p' === $tag ) {
					if ( $closing && ! $open ) {
						return '<p></p>';
					}

					$open = ! $closing;
				} elseif ( ! $closing && in_array( $tag, self::BLOCK_ELEMENTS, true ) ) {
					$open = false;
				}

				return $matches[0];
			},
			$html
		);
	}

	/**
	 * Replaces an element's children with HTML.
	 *
	 * @param DOMElement $node Element.
	 * @param string     $html HTML.
	 * @return void
	 */
	private function replace_children( DOMElement $node, string $html ): void {
		while ( $node->firstChild ) {
			$node->removeChild( $node->firstChild );
		}

		foreach ( $this->children( $this->load( $html ) ) as $child ) {
			$node->appendChild( $node->ownerDocument->importNode( $child, true ) );
		}
	}

	/**
	 * Returns a node's children as an array, safe to change while looping.
	 *
	 * @param DOMNode $node Node.
	 * @return DOMNode[]
	 */
	private function children( DOMNode $node ): array {
		return $node->hasChildNodes() ? iterator_to_array( $node->childNodes ) : [];
	}

	/**
	 * Returns a node's HTML, written the way a browser serializes it.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private function outer_html( DOMNode $node ): string {
		if ( XML_TEXT_NODE === $node->nodeType ) {
			$parent = $node->parentNode ? $this->tag( $node->parentNode ) : '';

			return in_array( $parent, [ 'script', 'style', 'iframe', 'noscript', 'xmp' ], true )
				? $node->textContent
				: str_replace( [ '&', "\u{00a0}", '<', '>' ], [ '&amp;', '&nbsp;', '&lt;', '&gt;' ], $node->textContent );
		}

		if ( XML_COMMENT_NODE === $node->nodeType ) {
			return '<!--' . $node->textContent . '-->';
		}

		if ( XML_CDATA_SECTION_NODE === $node->nodeType ) {
			return $node->textContent;
		}

		if ( ! $node instanceof DOMElement ) {
			return '';
		}

		$tag  = $this->tag( $node );
		$html = '<' . $tag;

		foreach ( $node->attributes as $attribute ) {
			$html .= ' ' . $attribute->name . '="' . str_replace( [ '&', "\u{00a0}", '"' ], [ '&amp;', '&nbsp;', '&quot;' ], $attribute->value ) . '"';
		}

		$html .= '>';

		if ( in_array( $tag, [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr' ], true ) ) {
			return $html;
		}

		return $html . $this->inner_html( $node ) . '</' . $tag . '>';
	}

	/**
	 * Returns the HTML inside an element.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private function inner_html( DOMNode $node ): string {
		$html = '';

		foreach ( $this->children( $node ) as $child ) {
			$html .= $this->outer_html( $child );
		}

		return $html;
	}

	/**
	 * Returns an element's lowercase tag name.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private function tag( DOMNode $node ): string {
		return strtolower( $node->nodeName );
	}

	/**
	 * Returns a node's first child element.
	 *
	 * @param DOMNode $node Node.
	 * @return DOMElement|null
	 */
	private function first_element( DOMNode $node ): ?DOMElement {
		foreach ( $this->children( $node ) as $child ) {
			if ( $child instanceof DOMElement ) {
				return $child;
			}
		}

		return null;
	}

	/**
	 * Returns an element's class names.
	 *
	 * @param DOMElement|null $node Element.
	 * @return string[]
	 */
	private function classes( ?DOMElement $node ): array {
		return $node instanceof DOMElement ? array_values( array_filter( preg_split( '/\s+/', trim( $node->getAttribute( 'class' ) ) ) ) ) : [];
	}

	/**
	 * Returns the first descendant with a tag name.
	 *
	 * @param DOMElement $node Element.
	 * @param string     $tag  Tag name.
	 * @return DOMElement|null
	 */
	private function first( DOMElement $node, string $tag ): ?DOMElement {
		$found = $node->getElementsByTagName( $tag )->item( 0 );

		return $found instanceof DOMElement ? $found : null;
	}

	/**
	 * Returns the node or its nearest ancestor with one of the tag names,
	 * inside the document body.
	 *
	 * @param DOMNode  $node Node.
	 * @param string[] $tags Tag names.
	 * @return DOMElement|null
	 */
	private function closest( DOMNode $node, array $tags ): ?DOMElement {
		$current = $node;

		while ( $current instanceof DOMElement ) {
			$tag = $this->tag( $current );

			if ( 'body' === $tag ) {
				return null;
			}

			if ( in_array( $tag, $tags, true ) ) {
				return $current;
			}

			$current = $current->parentNode;
		}

		return null;
	}

	/**
	 * Returns the previous sibling element.
	 *
	 * @param DOMNode $node Node.
	 * @return DOMElement|null
	 */
	private function previous_element( DOMNode $node ): ?DOMElement {
		for ( $sibling = $node->previousSibling; null !== $sibling; $sibling = $sibling->previousSibling ) {
			if ( $sibling instanceof DOMElement ) {
				return $sibling;
			}
		}

		return null;
	}

	/**
	 * Returns how many child elements a node has.
	 *
	 * @param DOMNode $node Node.
	 * @return int
	 */
	private function element_count( DOMNode $node ): int {
		return count( array_filter( $this->children( $node ), fn( DOMNode $child ) => $child instanceof DOMElement ) );
	}

	/**
	 * Returns the text of a node's own text children.
	 *
	 * @param DOMNode $node Node.
	 * @return string
	 */
	private function shallow_text( DOMNode $node ): string {
		$text = '';

		foreach ( $this->children( $node ) as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType ) {
				$text .= $child->textContent;
			}
		}

		return $text;
	}

	/**
	 * Whether a text node holds only white space.
	 *
	 * @param DOMNode $node Text node.
	 * @return bool
	 */
	private function is_empty_node( DOMNode $node ): bool {
		return 1 === preg_match( '/^[ \f\n\r\t\v\x{00a0}]*$/u', (string) $node->nodeValue );
	}

	/**
	 * Whether a node is a comment or a text node with only white space.
	 *
	 * @param DOMNode $node Node.
	 * @return bool
	 */
	private function is_blank( DOMNode $node ): bool {
		if ( XML_COMMENT_NODE === $node->nodeType ) {
			return true;
		}

		$text = trim( $node->textContent );

		return XML_TEXT_NODE === $node->nodeType && '' === $text;
	}

	/**
	 * Trims white space, non-breaking spaces included.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private function js_trim( string $text ): string {
		return (string) preg_replace( '/^[\s\x{00a0}\x{feff}]+|[\s\x{00a0}\x{feff}]+$/u', '', $text );
	}

	/**
	 * Returns the text alignment in an element's inline style.
	 *
	 * @param DOMElement $node Element.
	 * @return string
	 */
	private function text_align( DOMElement $node ): string {
		$found = 1 === preg_match( '/(?:^|;)\s*text-align\s*:\s*([a-z-]+)/i', $node->getAttribute( 'style' ), $matches );

		return $found ? strtolower( $matches[1] ) : '';
	}

	/**
	 * Returns a pixel dimension from a bare number, or null.
	 *
	 * @param string $value Attribute value.
	 * @return string|null
	 */
	private function pixels( string $value ): ?string {
		$is_number = 1 === preg_match( '/^\d+$/', $value );

		return $is_number ? $value . 'px' : null;
	}

	/**
	 * Returns an aspect ratio as JavaScript prints the division.
	 *
	 * @param int $width  Width.
	 * @param int $height Height.
	 * @return string
	 */
	private function ratio( int $width, int $height ): string {
		return Html::number( $width / $height );
	}

	/**
	 * Returns the block specs for the HTML of a classed `<div>`.
	 *
	 * @param string $html HTML.
	 * @return array[]
	 */
	private function nested_specs( string $html ): array {
		++$this->depth;

		try {
			return $this->specs( $html );
		} finally {
			--$this->depth;
		}
	}

	/**
	 * Returns the Group spec for a classed `<div>`, with its ID as anchor and
	 * its inline styles as block attributes. A `<div>` with other attributes,
	 * such as `data-*`, becomes a Custom HTML block, so the attributes stay.
	 *
	 * @param DOMElement $node Element.
	 * @return array
	 */
	private function group_spec( DOMElement $node ): array {
		foreach ( $node->attributes as $attribute ) {
			if ( ! in_array( $attribute->name, [ 'class', 'id', 'style' ], true ) ) {
				return Spec::block( 'core/html', [ 'content' => $this->outer_html( $node ) ] );
			}
		}

		$attributes = [
			'className' => $node->getAttribute( 'class' ),
			'anchor'    => $node->getAttribute( 'id' ),
			'layout'    => [ 'type' => 'default' ],
		];

		$group_align = $this->text_align( $node );

		if ( '' !== $group_align ) {
			$this->note( 'dropped inline style: text-align' );
		}

		return Spec::block(
			'core/group',
			self::merge( $attributes, $this->style_attributes( $node->getAttribute( 'style' ), false ) ),
			$this->nested_specs( $this->inner_html( $node ) )
		);
	}

	/**
	 * Splits HTML at the serialized blocks it holds at the top level. Returns
	 * the pieces in order, each `[ 'html' => … ]` or `[ 'markup' => … ]`, or
	 * null when the HTML holds no block delimiter or its delimiters do not
	 * pair up.
	 *
	 * @param string $html HTML.
	 * @return array<array{html?: string, markup?: string}>|null
	 */
	private function block_segments( string $html ): ?array {
		$found = preg_match_all( self::BLOCK_DELIMITER, $html, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

		if ( ! $found ) {
			return null;
		}

		$segments = [];
		$stack    = [];
		$offset   = 0;
		$start    = 0;

		foreach ( $matches as $match ) {
			[ $delimiter, $position ] = $match[0];

			$end       = $position + strlen( $delimiter );
			$namespace = '' !== $match['namespace'][0] ? $match['namespace'][0] : 'core/';
			$name      = $namespace . $match['name'][0];
			$is_closer = '' !== ( $match['closer'][0] ?? '' );
			$is_void   = '' !== ( $match['void'][0] ?? '' );

			if ( $is_closer ) {
				$open = end( $stack );

				if ( $open !== $name ) {
					return null;
				}

				array_pop( $stack );

				if ( [] === $stack ) {
					$segments[] = [ 'markup' => $this->unwrap_delimiters( substr( $html, $start, $end - $start ) ) ];
					$offset     = $end;
				}

				continue;
			}

			if ( [] === $stack ) {
				$segments[] = [ 'html' => substr( $html, $offset, $position - $offset ) ];
				$start      = $position;
			}

			if ( $is_void ) {
				if ( [] === $stack ) {
					$segments[] = [ 'markup' => $delimiter ];
					$offset     = $end;
				}

				continue;
			}

			$stack[] = $name;
		}

		if ( [] !== $stack ) {
			return null;
		}

		$segments[] = [ 'html' => substr( $html, $offset ) ];

		return $segments;
	}

	/**
	 * Removes the paragraph tags that `wpautop()` writes around block
	 * delimiters: a paragraph end right after an opening delimiter, and a
	 * paragraph start right before a closing delimiter.
	 *
	 * @param string $markup Block markup.
	 * @return string
	 */
	private function unwrap_delimiters( string $markup ): string {
		preg_match_all( self::BLOCK_DELIMITER, $markup, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

		$result      = '';
		$offset      = 0;
		$after_start = false;

		foreach ( $matches as $match ) {
			[ $delimiter, $position ] = $match[0];

			$is_closer = '' !== ( $match['closer'][0] ?? '' );
			$is_void   = '' !== ( $match['void'][0] ?? '' );
			$between   = substr( $markup, $offset, $position - $offset );

			if ( $after_start ) {
				$between = (string) preg_replace( '#^(\s*)</p>#i', '$1', $between );
			}

			if ( $is_closer ) {
				$between = (string) preg_replace( '#<p>(\s*)$#i', '$1', $between );
			}

			$result     .= $between . $delimiter;
			$offset      = $position + strlen( $delimiter );
			$after_start = ! $is_closer && ! $is_void;
		}

		return $result . substr( $markup, $offset );
	}

	/**
	 * Starts a paragraph when a paragraph end comes before any paragraph
	 * start: the text before it is the rest of a paragraph that a block
	 * split.
	 *
	 * @param string $html HTML after a block.
	 * @return string
	 */
	private function reopen_paragraph( string $html ): string {
		$has_end = 1 === preg_match( '#</p\s*>#i', $html, $end, PREG_OFFSET_CAPTURE );

		if ( ! $has_end ) {
			return $html;
		}

		$has_start = 1 === preg_match( '#<p[\s>]#i', $html, $start, PREG_OFFSET_CAPTURE );

		if ( $has_start && $start[0][1] < $end[0][1] ) {
			return $html;
		}

		return '<p>' . $html;
	}

	/**
	 * Returns the block specs for HTML split at serialized blocks: the blocks
	 * stay as they are, and the HTML around them is converted.
	 *
	 * `wpautop()` writes a paragraph start and a line break before a block,
	 * and a line break and a paragraph end after it. These go, so they leave
	 * no empty paragraph and no line break at the end of a paragraph. Text
	 * after a block that ends a paragraph gets its own paragraph.
	 *
	 * @param array<array{html?: string, markup?: string}> $segments Pieces from `block_segments()`.
	 * @return array[]
	 */
	private function segment_specs( array $segments ): array {
		$specs = [];
		$last  = count( $segments ) - 1;

		foreach ( $segments as $index => $segment ) {
			if ( isset( $segment['markup'] ) ) {
				$specs[] = Spec::markup( $segment['markup'] );
				continue;
			}

			$html = $segment['html'];

			if ( $index < $last ) {
				$html = (string) preg_replace( '#(?:\s|<br\s*/?>|<p(?:\s[^>]*)?>)+$#i', '', $html );
			}

			if ( $index > 0 ) {
				$html = $this->reopen_paragraph( (string) preg_replace( '#^(?:\s|</p\s*>|<br\s*/?>)+#i', '', $html ) );
			}

			$trimmed = trim( $html );

			if ( '' !== $trimmed ) {
				array_push( $specs, ...$this->specs( $html ) );
			}
		}

		return $specs;
	}

	/**
	 * Translates the classes of inline elements, and unwraps the `<span>`s
	 * left without attributes. Block elements keep theirs: their blocks
	 * translate them as class names.
	 *
	 * @param DOMElement $body Body.
	 * @return void
	 */
	private function inline_classes( DOMElement $body ): void {
		foreach ( iterator_to_array( $body->getElementsByTagName( '*' ) ) as $element ) {
			if ( ! $element instanceof DOMElement || ! $element->hasAttribute( 'class' ) || ! in_array( $this->tag( $element ), self::PHRASING, true ) ) {
				continue;
			}

			$classes = $this->classes->element( $element->getAttribute( 'class' ) );

			if ( '' !== $classes ) {
				$element->setAttribute( 'class', $classes );
				continue;
			}

			$element->removeAttribute( 'class' );

			$element_tag = $this->tag( $element );

			if ( 'span' === $element_tag && 0 === $element->attributes->length ) {
				while ( $element->firstChild ) {
					$element->parentNode->insertBefore( $element->firstChild, $element );
				}

				$element->parentNode->removeChild( $element );
			}
		}
	}

	/**
	 * Whether a node has no inline content beside it: its siblings are block
	 * elements or blank text.
	 *
	 * @param DOMNode $node Node.
	 * @return bool
	 */
	private function is_alone( DOMNode $node ): bool {
		foreach ( [ 'previousSibling', 'nextSibling' ] as $direction ) {
			$sibling = $node->$direction;

			while ( $sibling instanceof DOMNode && $this->is_blank( $sibling ) ) {
				$sibling = $sibling->$direction;
			}

			$is_block = $sibling instanceof DOMElement && in_array( $this->tag( $sibling ), self::BLOCK_ELEMENTS, true );

			if ( $sibling instanceof DOMNode && ! $is_block ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Replaces the top-level `more` and `nextpage` comments, bare or inside a
	 * paragraph, with `wp-block` elements that `transform()` turns into
	 * blocks. A paragraph splits at the comment. Other comments in a split
	 * paragraph are dropped. A `noteaser` comment after a `more` comment
	 * becomes its `data-no-teaser` attribute.
	 *
	 * @param DOMElement $body Body.
	 * @return void
	 */
	private function convert_comments( DOMElement $body ): void {
		$this->remove_comments( $body );

		foreach ( $this->children( $body ) as $node ) {
			if ( null === $node->parentNode ) {
				continue;
			}

			$node_tag = $node instanceof DOMElement ? $this->tag( $node ) : '';

			if ( XML_COMMENT_NODE === $node->nodeType ) {
				$nodes = [ $node ];
			} elseif ( 'p' === $node_tag ) {
				$nodes = $this->children( $node );
			} else {
				continue;
			}

			$pieces = $this->split_at_markers( $node, $nodes );

			if ( null === $pieces ) {
				continue;
			}

			foreach ( $pieces as $piece ) {
				$node->parentNode->insertBefore( $piece, $node );
			}

			$node->parentNode->removeChild( $node );
		}
	}

	/**
	 * Splits the content of a top-level comment or paragraph at its `more` and
	 * `nextpage` comments. Returns the `wp-block` elements and the paragraphs
	 * around them, without the other comments and without empty paragraphs, or
	 * null when the content holds no such comment.
	 *
	 * @param DOMNode   $node  Top-level node.
	 * @param DOMNode[] $nodes Child nodes of a paragraph, or the comment itself.
	 * @return DOMNode[]|null
	 */
	private function split_at_markers( DOMNode $node, array $nodes ): ?array {
		$count   = count( $nodes );
		$pieces  = [];
		$segment = [];
		$found   = false;

		for ( $index = 0; $index < $count; $index++ ) {
			$child  = $nodes[ $index ];
			$marker = $this->comment_marker( $child );

			if ( XML_COMMENT_NODE !== $child->nodeType ) {
				$segment[] = $child;
				continue;
			}

			if ( null === $marker || 'noteaser' === $marker['kind'] ) {
				continue;
			}

			$found = true;

			array_push( $pieces, ...$this->paragraph_piece( $node, $segment ) );
			$segment = [];

			$element = $node->ownerDocument->createElement( 'wp-block' );
			$element->setAttribute( 'data-block', 'more' === $marker['kind'] ? 'core/more' : 'core/nextpage' );

			if ( 'more' === $marker['kind'] ) {
				if ( '' !== $marker['text'] ) {
					$element->setAttribute( 'data-custom-text', $marker['text'] );
				}

				$this->read_no_teaser( $node, $nodes, $index, $element );
			}

			$pieces[] = $element;
		}

		if ( ! $found ) {
			return null;
		}

		array_push( $pieces, ...$this->paragraph_piece( $node, $segment ) );

		return $pieces;
	}

	/**
	 * Marks a `more` element when a `noteaser` comment follows it: anywhere
	 * later in the same paragraph, or as a later top-level node, which this
	 * removes.
	 *
	 * @param DOMNode   $node    Top-level node.
	 * @param DOMNode[] $nodes   Nodes being split.
	 * @param int       $index   Index of the `more` comment.
	 * @param DOMNode   $element The `wp-block` element for `more`.
	 * @return void
	 */
	private function read_no_teaser( DOMNode $node, array $nodes, int $index, DOMNode $element ): void {
		$count = count( $nodes );

		for ( $next = $index + 1; $next < $count; $next++ ) {
			$marker = $this->comment_marker( $nodes[ $next ] );

			if ( null !== $marker && 'noteaser' === $marker['kind'] ) {
				$element->setAttribute( 'data-no-teaser', 'true' );

				return;
			}
		}

		$following = $node->nextSibling;

		while ( $following instanceof DOMNode ) {
			$following_comment = $this->lone_comment( $following );
			$following_marker  = $following_comment instanceof DOMNode ? $this->comment_marker( $following_comment ) : null;
			$after             = $following->nextSibling;

			if ( null !== $following_marker && 'noteaser' === $following_marker['kind'] ) {
				$element->setAttribute( 'data-no-teaser', 'true' );
				$following->parentNode->removeChild( $following );

				return;
			}

			$following = $after;
		}
	}

	/**
	 * Removes every comment except the `more`, `nextpage` and `noteaser`
	 * comments that sit at the top level or directly in a top-level
	 * paragraph. A block delimiter never stays in rich-text content.
	 *
	 * @param DOMElement $body Body.
	 * @return void
	 */
	private function remove_comments( DOMElement $body ): void {
		$xpath = new DOMXPath( $body->ownerDocument );

		foreach ( iterator_to_array( $xpath->query( './/comment()', $body ) ) as $comment ) {
			$parent    = $comment->parentNode;
			$is_marker = null !== $this->comment_marker( $comment );
			$is_top    = $parent === $body || ( $parent instanceof DOMElement && 'p' === $this->tag( $parent ) && $parent->parentNode === $body );

			if ( ! $is_marker || ! $is_top ) {
				$parent->removeChild( $comment );
			}
		}
	}

	/**
	 * Returns a paragraph that holds nodes, with the attributes of the
	 * paragraph they came from, or no paragraph when the nodes hold only
	 * blank text and line breaks.
	 *
	 * @param DOMNode   $source Top-level node the nodes came from.
	 * @param DOMNode[] $nodes  Nodes.
	 * @return DOMElement[]
	 */
	private function paragraph_piece( DOMNode $source, array $nodes ): array {
		$has_content = false;

		foreach ( $nodes as $node ) {
			$is_break = $node instanceof DOMElement && 'br' === $this->tag( $node );

			if ( ! $is_break && ( ! $node instanceof DOMText || ! $this->is_empty_node( $node ) ) ) {
				$has_content = true;
			}
		}

		if ( ! $has_content ) {
			return [];
		}

		$paragraph = $source->ownerDocument->createElement( 'p' );

		if ( $source instanceof DOMElement ) {
			foreach ( $source->attributes as $attribute ) {
				$paragraph->setAttribute( $attribute->name, $attribute->value );
			}
		}

		foreach ( $nodes as $node ) {
			$paragraph->appendChild( $node );
		}

		return [ $paragraph ];
	}

	/**
	 * Returns the comment a node stands for: the node itself, or the only
	 * comment in a paragraph that holds nothing else but blank text.
	 *
	 * @param DOMNode $node Node.
	 * @return DOMNode|null
	 */
	private function lone_comment( DOMNode $node ): ?DOMNode {
		if ( XML_COMMENT_NODE === $node->nodeType ) {
			return $node;
		}

		$node_tag = $node instanceof DOMElement ? $this->tag( $node ) : '';

		if ( 'p' !== $node_tag ) {
			return null;
		}

		$found = null;

		foreach ( $this->children( $node ) as $child ) {
			if ( XML_TEXT_NODE === $child->nodeType && $this->is_empty_node( $child ) ) {
				continue;
			}

			if ( XML_COMMENT_NODE !== $child->nodeType || null !== $found ) {
				return null;
			}

			$found = $child;
		}

		return $found;
	}

	/**
	 * Reads a comment that becomes a block: `more` with
	 * optional text, `nextpage`, or the `noteaser` that follows `more`.
	 *
	 * @param DOMNode $node Node.
	 * @return array{kind: string, text: string}|null Null for any other node.
	 */
	private function comment_marker( DOMNode $node ): ?array {
		if ( XML_COMMENT_NODE !== $node->nodeType ) {
			return null;
		}

		$data    = trim( $node->textContent );
		$is_more = 1 === preg_match( '/^more(?:\s+(.*))?$/s', $data, $matches );

		if ( 'nextpage' === $data || 'noteaser' === $data ) {
			return [
				'kind' => $data,
				'text' => '',
			];
		}

		if ( $is_more ) {
			return [
				'kind' => 'more',
				'text' => trim( $matches[1] ?? '' ),
			];
		}

		return null;
	}
}
