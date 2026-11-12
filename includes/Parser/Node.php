<?php

namespace S3S\WP\DiviMigration\Parser;

defined( 'ABSPATH' ) || exit;

/**
 * One Divi module, read from its shortcode.
 */
class Node {

	/**
	 * Child modules, in document order.
	 *
	 * @var Node[]
	 */
	public array $children = [];

	/**
	 * Raw text between the opening and closing tags, child modules removed.
	 *
	 * @var string
	 */
	public string $content = '';

	/**
	 * Root text by position. Each key is the index of the child that follows
	 * the text, and `count( children )` holds the trailing text. Only the
	 * root node records it.
	 *
	 * @var array<int, string>
	 */
	public array $segments = [];

	/**
	 * Creates a node.
	 *
	 * @param string $tag   Shortcode tag, such as `et_pb_text`.
	 * @param array  $attrs Decoded shortcode attributes.
	 */
	public function __construct(
		public string $tag,
		public array $attrs = [],
	) {}

	/**
	 * Returns an attribute value, or the fallback when it is missing or empty.
	 *
	 * @param string $name     Attribute name.
	 * @param string $fallback Value to return when the attribute is not set.
	 * @return string
	 */
	public function attr( string $name, string $fallback = '' ): string {
		$value = $this->attrs[ $name ] ?? '';
		return '' === $value ? $fallback : (string) $value;
	}

	/**
	 * Whether a Divi on/off attribute is on.
	 *
	 * @param string $name Attribute name.
	 * @return bool
	 */
	public function is_on( string $name ): bool {
		return 'on' === $this->attr( $name );
	}

	/**
	 * Returns the node as an array, for previews and logs.
	 *
	 * @return array
	 */
	public function to_array(): array {
		return [
			'tag'      => $this->tag,
			'attrs'    => $this->attrs,
			'content'  => $this->content,
			'children' => array_map( fn( Node $child ) => $child->to_array(), $this->children ),
		];
	}
}
