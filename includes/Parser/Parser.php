<?php

namespace S3S\WP\DiviMigration\Parser;

defined( 'ABSPATH' ) || exit;

/**
 * Reads Divi Builder shortcodes into a tree of nodes.
 *
 * Only `et_pb_*` tags become nodes. Other shortcodes, such as a form
 * shortcode inside a text module, stay in the node content as text.
 */
class Parser {

	/**
	 * Matches one opening, closing or self-closing `et_pb_*` tag.
	 *
	 * Divi encodes `]` inside attribute values, so an attribute string never
	 * holds a closing bracket.
	 */
	const TAG_PATTERN = '/\[(\/?)(et_pb_[a-z0-9_]+)([^\]]*)\]/';

	/**
	 * Divi's encoding of characters that would break the shortcode syntax.
	 */
	const ENCODED_CHARACTERS = [
		'%22' => '"',
		'%91' => '[',
		'%93' => ']',
		'%92' => '\\',
	];

	/**
	 * Matches a quoted attribute value, or a value delimited by curly or
	 * encoded quotes.
	 *
	 * The first two alternatives skip values in straight double or single
	 * quotes. In the third, the opening quote follows `=`, and the closing
	 * quote is the first one that ends the attribute text or is followed by
	 * the next attribute: a name with a value, or a name alone.
	 */
	const CURLY_VALUE_PATTERN = '/"[^"]*"|\'[^\']*\'|=\s*(?:[\x{201C}\x{201D}\x{2033}]|&#8220;|&#8221;|&#8243;)(.*?)(?:[\x{201C}\x{201D}\x{2033}]|&#8220;|&#8221;|&#8243;)(?=\s+[A-Za-z0-9_-]+(?:\s*=|\s|\/?\s*$)|\s*\/?\s*$)/su';

	/**
	 * Unresolved dynamic content types, with the tag of the module that uses them.
	 *
	 * @var array<int, array{tag: string, type: string}>
	 */
	private array $unresolved = [];

	/**
	 * Parses Divi content into a root node that holds the top-level modules.
	 *
	 * @param string $content Post content written by the Divi Builder.
	 * @return Node
	 */
	public function parse( string $content ): Node {
		$content          = $this->normalize( $content );
		$this->unresolved = [];
		$root             = new Node( 'root' );
		$stack            = [ $root ];
		$offset           = 0;

		preg_match_all( self::TAG_PATTERN, $content, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE );

		foreach ( $matches as $match ) {
			$position = $match[0][1];
			$current  = end( $stack );

			$text              = substr( $content, $offset, $position - $offset );
			$current->content .= $text;

			if ( $current === $root ) {
				$this->add_segment( $root, $text );
			}

			$offset = $position + strlen( $match[0][0] );

			$is_closing = '/' === $match[1][0];
			$tag        = $match[2][0];

			if ( $is_closing ) {
				$this->close( $stack, $tag );
				continue;
			}

			// A tag closes itself with ` /]`, or with `/]` right after its name or a quoted value. An unquoted value can end in `/`, such as a URL.
			$attribute_text = $this->straighten_quotes( $match[3][0] );
			$is_self_closed = 1 === preg_match( '/(?:^|[\s"\'])\/\s*$/', $attribute_text );

			if ( $is_self_closed ) {
				$attribute_text = (string) preg_replace( '/\/\s*$/', '', $attribute_text );
			}

			$node = new Node( $tag, $this->parse_attributes( $attribute_text, $tag ) );

			end( $stack )->children[] = $node;

			if ( ! $is_self_closed ) {
				$stack[] = $node;
			}
		}

		$tail = substr( $content, $offset );
		$last = end( $stack );

		$last->content .= $tail;

		if ( $last === $root ) {
			$this->add_segment( $root, $tail );
		}

		return $root;
	}

	/**
	 * Returns the dynamic content values that the last parse could not resolve.
	 *
	 * @return array<int, array{tag: string, type: string}>
	 */
	public function unresolved(): array {
		return $this->unresolved;
	}

	/**
	 * Records root text under the index of the child that follows it.
	 *
	 * @param Node   $root Root node.
	 * @param string $text Text found at the root.
	 * @return void
	 */
	private function add_segment( Node $root, string $text ): void {
		if ( '' === $text ) {
			return;
		}

		$index = count( $root->children );

		$root->segments[ $index ] = ( $root->segments[ $index ] ?? '' ) . $text;
	}

	/**
	 * Replaces Divi's line break markers, which are not real modules.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	private function normalize( string $content ): string {
		return (string) preg_replace( '/(<!--\s*)?\[et_pb_line_break_holder\](\s*-->)?/', "\n", $content );
	}

	/**
	 * Closes the most recent open node with the given tag.
	 *
	 * A closing tag with no matching open node is ignored. Open nodes above
	 * the match are closed with it, which recovers from unclosed tags.
	 *
	 * @param Node[] $stack Open nodes, the root first.
	 * @param string $tag   Tag being closed.
	 * @return void
	 */
	private function close( array &$stack, string $tag ): void {
		for ( $index = count( $stack ) - 1; $index > 0; $index-- ) {
			if ( $stack[ $index ]->tag === $tag ) {
				$stack = array_slice( $stack, 0, $index );
				return;
			}
		}
	}

	/**
	 * Parses a shortcode attribute string, decodes Divi's escapes and resolves
	 * dynamic content.
	 *
	 * @param string $text Attribute string.
	 * @param string $tag  Tag of the module that holds the attributes.
	 * @return array<string, string>
	 */
	private function parse_attributes( string $text, string $tag ): array {
		$attributes = shortcode_parse_atts( $text );

		if ( ! is_array( $attributes ) ) {
			return [];
		}

		$decoded = [];

		foreach ( $attributes as $name => $value ) {
			if ( ! is_string( $name ) ) {
				continue;
			}

			$unresolved = '';

			$decoded[ $name ] = DynamicContent::resolve( strtr( (string) $value, self::ENCODED_CHARACTERS ), $unresolved );

			if ( '' !== $unresolved ) {
				$this->unresolved[] = [
					'tag'  => $tag,
					'type' => $unresolved,
				];
			}
		}

		return $decoded;
	}

	/**
	 * Replaces the curly and encoded quotes that delimit attribute values with
	 * straight quotes.
	 *
	 * A straight quote inside such a value becomes `%22`, which parse_attributes()
	 * decodes. Other quote characters inside a value stay as they are.
	 *
	 * @param string $text Attribute string.
	 * @return string
	 */
	private function straighten_quotes( string $text ): string {
		$result = preg_replace_callback(
			self::CURLY_VALUE_PATTERN,
			fn( array $found ) => isset( $found[1] ) ? '="' . str_replace( '"', '%22', $found[1] ) . '"' : $found[0],
			$text
		);

		return is_string( $result ) ? $result : $text;
	}
}
