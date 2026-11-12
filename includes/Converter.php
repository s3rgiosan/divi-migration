<?php

namespace S3S\WP\DiviMigration;

use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Parser\Parser;
use S3S\WP\DiviMigration\Rules;
use S3S\WP\DiviMigration\Styles\Presets;

defined( 'ABSPATH' ) || exit;

/**
 * Converts Divi content into block specs, one rule per module type.
 */
class Converter {

	/**
	 * Whether the conversion only reports: targets do not create anything,
	 * such as forms in a form plugin.
	 *
	 * @var bool
	 */
	public bool $dry_run = false;

	/**
	 * Conversion outcome per module tag: `converted` or `fallback` counts.
	 *
	 * @var array<string, array<string, int>>
	 */
	private array $report = [];

	/**
	 * Library items being converted, which stops a global module that
	 * contains itself from looping.
	 *
	 * @var array<int, true>
	 */
	private array $resolving = [];

	/**
	 * Space between modules in each column being converted, innermost last.
	 *
	 * @var array<int, array{desktop: string, tablet: string, phone: string}>
	 */
	private array $module_gaps = [];

	/**
	 * Targets for module types that can convert into different block plugins.
	 *
	 * @var Targets\Targets
	 */
	private Targets\Targets $targets;

	/**
	 * Creates a converter.
	 *
	 * @param Settings                  $settings Site-wide settings.
	 * @param Presets                   $presets  Theme presets.
	 * @param array<string, Rules\Rule> $rules    Rules keyed by shortcode tag.
	 * @param Rules\Rule                $fallback Rule for modules without a rule.
	 * @param Map                       $map      Migration map.
	 * @param ModulePresets             $module_presets Divi's Global Presets.
	 */
	public function __construct(
		public Settings $settings,
		public Presets $presets,
		private array $rules,
		private Rules\Rule $fallback,
		public Map $map = new Map(),
		public ModulePresets $module_presets = new ModulePresets(),
	) {
		$this->targets = new Targets\Targets( $settings->targets );
	}

	/**
	 * Creates a converter with the default rules, the active theme's presets
	 * and the migration map.
	 *
	 * @param Map|null $map Migration map.
	 * @return Converter
	 */
	public static function create( ?Map $map = null ): Converter {
		$map ??= new Map();

		$rules = [
			'et_pb_section'          => new Rules\Section(),
			'et_pb_row'              => new Rules\Row(),
			'et_pb_row_inner'        => new Rules\Row(),
			'et_pb_column'           => new Rules\Column(),
			'et_pb_column_inner'     => new Rules\Column(),
			'et_pb_text'             => new Rules\Text(),
			'et_pb_image'            => new Rules\Image(),
			'et_pb_fullwidth_image'  => new Rules\Image(),
			'et_pb_button'           => new Rules\Button(),
			'et_pb_code'             => new Rules\Code(),
			'et_pb_fullwidth_code'   => new Rules\Code(),
			'et_pb_menu'             => new Rules\Menu(),
			'et_pb_fullwidth_menu'   => new Rules\Menu(),
			'et_pb_slider'           => new Rules\Slider(),
			'et_pb_fullwidth_slider' => new Rules\Slider(),
			'et_pb_blurb'            => new Rules\Blurb(),
			'et_pb_cta'              => new Rules\CallToAction(),
			'et_pb_testimonial'      => new Rules\Testimonial(),
			'et_pb_counters'         => new Rules\Counters(),
			'et_pb_number_counter'   => new Rules\NumberCounter(),
			'et_pb_video'            => new Rules\Video(),
			'et_pb_post_title'       => new Rules\PostTitle(),
			'et_pb_blog'             => new Rules\Blog(),
			'et_pb_sidebar'          => new Rules\Sidebar(),
			'et_pb_accordion'        => new Rules\Accordion(),
			'et_pb_toggle'           => new Rules\Accordion(),
			'et_pb_contact_form'     => new Rules\ContactForm(),
		];

		/**
		 * Filters the conversion rules.
		 *
		 * Add a rule for a module type, or replace a default one, keyed by the
		 * module's shortcode tag (`et_pb_text`).
		 *
		 * @param array<string, Rules\Rule> $rules Rules keyed by shortcode tag.
		 */
		$rules = apply_filters( 's3s_divi_migration_rules', $rules );

		return new self(
			Settings::from_options( $map ),
			Presets::from_global_settings()->with( $map->colors, $map->fonts ),
			$rules,
			new Rules\Fallback(),
			$map,
			ModulePresets::from_options(),
		);
	}

	/**
	 * Converts Divi content into block specs.
	 *
	 * @param string $content Divi content.
	 * @return array[]
	 */
	public function convert_content( string $content ): array {
		$parser = new Parser();
		$root   = $parser->parse( $content );

		foreach ( $parser->unresolved() as $item ) {
			$this->note( $item['tag'], 'unresolved dynamic content: ' . $item['type'] );
		}

		$this->module_presets->apply( $root );

		return $this->convert_children( $root );
	}

	/**
	 * Converts a post's Divi content into block specs.
	 *
	 * A product whose builder layout holds only WooCommerce modules (Divi's
	 * product page layout) gets its description back from the content Divi
	 * kept before the builder took over. The description stays classic
	 * content, because WooCommerce edits product descriptions in the classic
	 * editor. The block theme's product template then renders the page.
	 *
	 * @param \WP_Post $post Post.
	 * @return array[]
	 */
	public function convert_post( \WP_Post $post ): array {
		$description = $this->product_description( $post );

		if ( null !== $description ) {
			$trimmed = trim( $description );

			return '' === $trimmed ? [] : [ Spec::classic( $description ) ];
		}

		return $this->convert_content( Content::source( $post ) );
	}

	/**
	 * Returns the description Divi kept for a product whose builder layout
	 * holds only WooCommerce modules, or null for any other post.
	 *
	 * @param \WP_Post $post Post.
	 * @return string|null
	 */
	public function product_description( \WP_Post $post ): ?string {
		if ( 'product' !== $post->post_type || ! $this->is_product_layout( ( new Parser() )->parse( Content::source( $post ) ) ) ) {
			return null;
		}

		$this->report['et_pb_wc_*']['restored description'] = 1;

		return (string) get_post_meta( $post->ID, '_et_pb_old_content', true );
	}

	/**
	 * Converts one module node.
	 *
	 * @param Node $node Module node.
	 * @return array[]
	 */
	public function convert_node( Node $node ): array {
		$layout_id = (int) $node->attr( 'global_module' );
		$global    = $this->resolve_global( $node, $layout_id );

		if ( null !== $global ) {
			$this->resolving[ $layout_id ] = true;

			try {
				return $this->convert_node( $global );
			} finally {
				unset( $this->resolving[ $layout_id ] );
			}
		}

		$action = $this->map->module( $node->tag );

		if ( null !== $action ) {
			$outcome = 'skip' === $action ? 'skipped (map)' : 'replaced (map)';

			$this->report[ $node->tag ][ $outcome ] = ( $this->report[ $node->tag ][ $outcome ] ?? 0 ) + 1;

			return 'skip' === $action ? [] : [ Spec::markup( $action ) ];
		}

		$rule    = $this->rules[ $node->tag ] ?? null;
		$outcome = $rule instanceof Rules\Rule ? 'converted' : 'fallback';

		$this->report[ $node->tag ][ $outcome ] = ( $this->report[ $node->tag ][ $outcome ] ?? 0 ) + 1;

		return ( $rule ?? $this->fallback )->convert( $node, $this );
	}

	/**
	 * Converts a node's child modules.
	 *
	 * Text outside any module, such as content written before the page used
	 * the builder, becomes raw HTML in its place before, between or after the
	 * top-level modules.
	 *
	 * @param Node $node Parent node.
	 * @return array[]
	 */
	public function convert_children( Node $node ): array {
		$specs      = [];
		$is_root    = 'root' === $node->tag;
		$content    = trim( $node->content );
		$segments   = $node->segments;
		$has_pieces = [] !== $segments;

		// A root built without segments keeps its text before the first module.
		if ( $is_root && ! $has_pieces && '' !== $content ) {
			$specs[] = Spec::raw( $node->content );
		}

		foreach ( $node->children as $index => $child ) {
			$segment = trim( $segments[ $index ] ?? '' );

			if ( $is_root && '' !== $segment ) {
				$specs[] = Spec::raw( $segments[ $index ] );
			}

			array_push( $specs, ...$this->convert_node( $child ) );
		}

		$tail         = $segments[ count( $node->children ) ] ?? '';
		$trimmed_tail = trim( $tail );

		if ( $is_root && '' !== $trimmed_tail ) {
			$specs[] = Spec::raw( $tail );
		}

		return $specs;
	}

	/**
	 * Returns a serializer for the converter's block specs, whose raw handler
	 * converts tables with the table target, matches inline styles to the
	 * theme's presets, and replaces the shortcodes the map names. Classes
	 * translate as the map says, and Bloom opt-ins become forms of the form
	 * target.
	 *
	 * @return Serializer\Serializer
	 */
	public function serializer(): Serializer\Serializer {
		$table = $this->targets->resolve( 'table' );
		$table = null !== $table && $table[1] instanceof Targets\TableTarget ? $table[1] : new Targets\CoreTable();
		$form  = $this->targets->resolve( 'form' );
		$form  = null !== $form && $form[1] instanceof Targets\FormTarget ? $form[1] : null;

		$classes = new Serializer\Classes( $this->map->classes );

		return new Serializer\Serializer(
			new Serializer\RawHandler( $table, $this->presets, $this->map->shortcodes, 'drop' === ( $this->map->settings['emptyParagraphs'] ?? 'keep' ), $classes ),
			$classes,
			[ new Shortcodes\Bloom( $form, ! $this->dry_run, $this->presets, $this->targets ) ],
		);
	}

	/**
	 * Returns the target for a kind, and notes its name in the report.
	 *
	 * @param string $kind Kind, such as `carousel`.
	 * @param string $tag  Tag of the module being converted.
	 * @return Targets\Target|null
	 */
	public function target( string $kind, string $tag ): ?Targets\Target {
		$resolved = $this->targets->resolve( $kind );

		if ( null === $resolved ) {
			return null;
		}

		[ $name, $target ] = $resolved;

		$this->report[ $tag ][ 'target: ' . $name ] = ( $this->report[ $tag ][ 'target: ' . $name ] ?? 0 ) + 1;

		return $target;
	}

	/**
	 * Whether all of a target's blocks are registered.
	 *
	 * @param Targets\Target $target Target.
	 * @return bool
	 */
	public function is_available( Targets\Target $target ): bool {
		return $this->targets->is_available( $target );
	}

	/**
	 * Adds a note on a module to the report.
	 *
	 * @param string $tag  Module tag.
	 * @param string $note Note.
	 * @return void
	 */
	public function note( string $tag, string $note ): void {
		$this->report[ $tag ][ $note ] = ( $this->report[ $tag ][ $note ] ?? 0 ) + 1;
	}

	/**
	 * Runs a conversion inside a column, with the space Divi leaves between the
	 * column's modules.
	 *
	 * @param array{desktop: string, tablet: string, phone: string} $gap     Space between modules, per viewport.
	 * @param callable                                              $convert Conversion; returns block specs.
	 * @return array[]
	 */
	public function with_module_gap( array $gap, callable $convert ): array {
		$this->module_gaps[] = $gap;

		try {
			return $convert();
		} finally {
			array_pop( $this->module_gaps );
		}
	}

	/**
	 * Returns the space between modules in the column being converted, or
	 * null outside a column.
	 *
	 * @return array{desktop: string, tablet: string, phone: string}|null
	 */
	public function module_gap(): ?array {
		return [] !== $this->module_gaps ? $this->module_gaps[ array_key_last( $this->module_gaps ) ] : null;
	}

	/**
	 * Returns how many modules of each type were converted or fell back.
	 *
	 * @return array<string, array<string, int>>
	 */
	public function report(): array {
		ksort( $this->report );
		return $this->report;
	}

	/**
	 * Whether a layout holds only sections, rows, columns and WooCommerce modules.
	 *
	 * @param Node $node Parsed layout.
	 * @return bool
	 */
	public function is_product_layout( Node $node ): bool {
		$has_woocommerce = false;

		foreach ( $node->children as $child ) {
			if ( str_starts_with( $child->tag, 'et_pb_wc_' ) ) {
				$has_woocommerce = true;
				continue;
			}

			$is_layout = preg_match( '/^et_pb_(section|row|row_inner|column|column_inner)$/', $child->tag );

			if ( 1 !== $is_layout ) {
				return false;
			}

			if ( [] !== $child->children && ! $this->is_product_layout( $child ) ) {
				return false;
			}

			$has_woocommerce = $has_woocommerce || $this->contains_woocommerce( $child );
		}

		return $has_woocommerce;
	}

	/**
	 * Returns a global module as Divi renders it.
	 *
	 * Divi starts from the copy saved in the page and replaces each setting
	 * the Library item also sets, except the settings the item leaves out of
	 * the sync (`_et_pb_excluded_global_options`). The content comes from the
	 * item when it is synced, otherwise from the page.
	 *
	 * @param Node $node      Module node.
	 * @param int  $layout_id Library item ID from `global_module`.
	 * @return Node|null The merged module, or null when the node is not global,
	 *                   the item is missing, or the item is already being converted.
	 */
	private function resolve_global( Node $node, int $layout_id ): ?Node {
		if ( $layout_id <= 0 || isset( $this->resolving[ $layout_id ] ) ) {
			return null;
		}

		$layout = get_post( $layout_id );

		if ( ! $layout instanceof \WP_Post || 'et_pb_layout' !== $layout->post_type ) {
			return null;
		}

		// A converted Library item keeps its Divi content in the backup.
		$parser = new Parser();
		$global = $this->find( $parser->parse( Content::source( $layout ) ), $node->tag );

		foreach ( $parser->unresolved() as $item ) {
			$this->note( $item['tag'], 'unresolved dynamic content: ' . $item['type'] );
		}

		if ( null === $global ) {
			return null;
		}

		$excluded = json_decode( (string) get_post_meta( $layout_id, '_et_pb_excluded_global_options', true ), true );
		$excluded = is_array( $excluded ) ? $excluded : [];

		if ( 'et_pb_section' === $node->tag ) {
			array_push( $excluded, 'next_background_color', 'prev_background_color' );
		}

		$saved_tabs = $node->attr( 'saved_tabs' );
		$content    = [] !== $excluded || metadata_exists( 'post', $layout_id, '_et_pb_excluded_global_options' )
			? ! in_array( 'et_pb_content_field', $excluded, true )
			: '' === $saved_tabs || str_contains( $saved_tabs, 'general' ) || 'all' === $saved_tabs;

		$merged = new Node( $node->tag, array_merge( $node->attrs, array_diff_key( $global->attrs, array_flip( $excluded ) ) ) );

		$merged->children = $content ? $global->children : $node->children;
		$merged->content  = $content ? $global->content : $node->content;

		unset( $merged->attrs['global_module'] );

		// The Library item's modules get their presets as the page's modules do.
		$this->module_presets->apply( $merged );

		return $merged;
	}

	/**
	 * Whether a node holds a WooCommerce module at any depth.
	 *
	 * @param Node $node Node.
	 * @return bool
	 */
	private function contains_woocommerce( Node $node ): bool {
		foreach ( $node->children as $child ) {
			if ( str_starts_with( $child->tag, 'et_pb_wc_' ) || $this->contains_woocommerce( $child ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the first node with a tag, searching depth first.
	 *
	 * @param Node   $node Node to search.
	 * @param string $tag  Tag to find.
	 * @return Node|null
	 */
	private function find( Node $node, string $tag ): ?Node {
		foreach ( $node->children as $child ) {
			if ( $child->tag === $tag ) {
				return $child;
			}

			$match = $this->find( $child, $tag );

			if ( null !== $match ) {
				return $match;
			}
		}

		return null;
	}
}
