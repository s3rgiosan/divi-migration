<?php

namespace S3S\WP\DiviMigration;

use S3S\WP\DiviMigration\Parser\Node;
use S3S\WP\DiviMigration\Parser\Parser;
use WP_CLI;
use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Converts Divi content into blocks, inspects it, and restores converted
 * posts.
 */
class CLI extends BaseModule {

	/**
	 * {@inheritDoc}
	 */
	public function can_register(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register(): void {
		WP_CLI::add_command( 'divi-migration', $this );
	}

	/**
	 * Counts the Divi modules used across posts, and the settings each one uses.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types.
	 * ---
	 * default: post,page,product,et_pb_layout,et_header_layout,et_body_layout,et_footer_layout
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function analyze( array $args, array $assoc_args ): void {
		$ids    = Content::find( $this->list_option( $assoc_args, 'post_type' ), [ 'publish', 'draft', 'private', 'future', 'pending' ] );
		$parser = new Parser();
		$counts = [];
		$builds = [];

		foreach ( $ids as $index => $id ) {
			Content::flush_runtime_cache( $index );

			$post = get_post( $id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$source = Content::source( $post );
			$builds = DiviVersion::merge( $builds, DiviVersion::census( $source ) );

			$this->count( $parser->parse( $source ), $id, $counts );
		}

		$this->report_versions( $builds, 'table' === $assoc_args['format'] );

		$rows = [];

		foreach ( $counts as $tag => $count ) {
			$rows[] = [
				'module'   => $tag,
				'uses'     => $count['uses'],
				'posts'    => count( $count['posts'] ),
				'settings' => count( $count['settings'] ),
			];
		}

		usort( $rows, fn( $a, $b ) => $b['uses'] <=> $a['uses'] );

		WP_CLI\Utils\format_items( $assoc_args['format'], $rows, [ 'module', 'uses', 'posts', 'settings' ] );
	}

	/**
	 * Lists posts that hold Divi content or were converted from it.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types.
	 * ---
	 * default: post,page,product
	 * ---
	 *
	 * [--status=<statuses>]
	 * : Comma-separated post statuses.
	 * ---
	 * default: publish,draft,private,future,pending
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function list( array $args, array $assoc_args ): void {
		$ids  = Content::find( $this->list_option( $assoc_args, 'post_type' ), $this->list_option( $assoc_args, 'status' ) );
		$rows = [];

		foreach ( $ids as $index => $id ) {
			Content::flush_runtime_cache( $index );

			$post = get_post( $id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$rows[] = [
				'ID'        => $id,
				'post_type' => $post->post_type,
				'status'    => $post->post_status,
				'title'     => $post->post_title,
				'converted' => Content::is_converted( $post ) ? 'yes' : 'no',
			];
		}

		WP_CLI\Utils\format_items( $assoc_args['format'], $rows, [ 'ID', 'post_type', 'status', 'title', 'converted' ] );
	}

	/**
	 * Prints the block specs and the conversion report for a post.
	 *
	 * The preview creates no forms.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Post ID.
	 *
	 * [--tree]
	 * : Print only the parsed Divi tree.
	 *
	 * [--map=<file>]
	 * : Migration map (JSON).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function preview( array $args, array $assoc_args ): void {
		$post = get_post( (int) $args[0] );

		if ( ! $post instanceof WP_Post ) {
			WP_CLI::error( 'Post not found.' );
		}

		$this->warn_versions( DiviVersion::census( Content::source( $post ) ) );

		if ( isset( $assoc_args['tree'] ) ) {
			WP_CLI::line( (string) wp_json_encode( ( new Parser() )->parse( Content::source( $post ) )->to_array(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		$converter          = Converter::create( $this->map( $assoc_args ) );
		$converter->dry_run = true;
		$specs              = $converter->convert_post( $post );

		WP_CLI::line(
			(string) wp_json_encode(
				[
					'specs'  => $specs,
					'report' => $converter->report(),
				],
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
			)
		);
	}

	/**
	 * Moves Divi's site-wide settings to the block theme.
	 *
	 * Sets the Site Logo from Divi's logo and carries the menus over, then
	 * prints Divi's integration code, which moves by hand.
	 *
	 * With classic menus (the default), each menu stays assigned to the same
	 * location in the active theme; the theme registers those locations and
	 * binds its Navigation blocks to them. With Navigation menus, each menu is
	 * copied into a Navigation post and its ID is printed per location.
	 *
	 * Run it with the block theme active and before converting content, so menu
	 * modules convert into Navigation blocks bound to the same menus.
	 *
	 * ## OPTIONS
	 *
	 * [--theme=<stylesheet>]
	 * : Theme whose menu locations to read, such as a Divi child theme. Defaults to
	 * the active theme when it runs on Divi. Required when the active theme does not
	 * run on Divi: core clears the record of the previous theme on the first load
	 * after a theme switch.
	 *
	 * [--map=<file>]
	 * : Migration map (JSON), for its `menus` setting.
	 *
	 * [--menus=<mode>]
	 * : How menus carry over. Defaults to the `menus` setting.
	 * ---
	 * options:
	 *   - classic
	 *   - navigation
	 * ---
	 *
	 * [--force]
	 * : Replace a site logo that is already set and, with classic menus, the menus of
	 * locations that are already set. With Navigation menus, `site` always updates
	 * the Navigation posts, matched by slug.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function site( array $args, array $assoc_args ): void {
		$template = get_template();

		if ( ! isset( $assoc_args['theme'] ) && 'Divi' !== $template ) {
			WP_CLI::error( sprintf( 'The active theme is %s, not Divi. Pass the Divi theme with --theme=<stylesheet>.', $template ) );
		}

		$force = isset( $assoc_args['force'] );
		$logo  = SiteSettings::import_logo( $force );

		if ( is_wp_error( $logo ) ) {
			WP_CLI::warning( $logo->get_error_message() );
		} elseif ( $logo['kept'] ) {
			WP_CLI::log( sprintf( 'Site logo: kept attachment %d.', $logo['attachment'] ) );
		} else {
			WP_CLI::log( sprintf( 'Site logo: attachment %d.', $logo['attachment'] ) );
		}

		$stylesheet = (string) ( $assoc_args['theme'] ?? '' );
		$mode       = (string) ( $assoc_args['menus'] ?? Settings::from_options( $this->map( $assoc_args ) )->menus );
		$results    = 'navigation' === $mode ? SiteSettings::import_menus( $stylesheet ) : SiteSettings::keep_menus( $stylesheet, $force );
		$rows       = [];
		$kept       = 0;

		foreach ( $results as $location => $result ) {
			if ( is_wp_error( $result ) ) {
				WP_CLI::warning( sprintf( '%s: %s', $location, $result->get_error_message() ) );
				continue;
			}

			if ( 'navigation' === $mode ) {
				$rows[] = array_merge( [ 'location' => $location ], $result );
				continue;
			}

			$kept  += $result['kept'] ? 1 : 0;
			$rows[] = [
				'location' => $location,
				'menu'     => $result['menu'],
				'status'   => $result['kept'] ? 'kept' : 'assigned',
			];
		}

		$total = count( $rows );

		if ( 0 === $total ) {
			WP_CLI::warning( 'No menu locations found. Pass the Divi theme with --theme.' );
		} elseif ( 'navigation' === $mode ) {
			WP_CLI\Utils\format_items( 'table', $rows, [ 'location', 'menu', 'navigation' ] );
		} else {
			WP_CLI::log( $kept === $total ? 'All menu locations are already set; pass --force to replace them.' : 'Classic menus kept. Register these locations in the theme:' );
			WP_CLI\Utils\format_items( 'table', $rows, [ 'location', 'menu', 'status' ] );
		}

		foreach ( SiteSettings::integration_code() as $key => $code ) {
			// The label and the code both go to stdout, so the label prints first.
			WP_CLI::log( sprintf( 'Move this %s code by hand:', $key ) );
			WP_CLI::line( $code );
		}
	}

	/**
	 * Converts Divi content into blocks and saves it.
	 *
	 * Each post converts from its original content. The first conversion
	 * keeps that content in post meta before it saves, so running the command
	 * again starts over. `reset` puts the original content back. Each post
	 * keeps its status, its dates and its modified date: a scheduled post
	 * whose date has passed stays scheduled.
	 *
	 * A post is skipped when its blocks need a block type that is not
	 * registered, or a static block that the converter has no save for.
	 * A post whose conversion is empty is skipped. A converted post is
	 * skipped when its content changed after the conversion. `--force`
	 * converts these posts too.
	 *
	 * A post ID whose content is not Divi content is skipped. With
	 * `--classic`, a post ID whose content is not classic content is skipped.
	 * A post ID whose post type or status is not selected is skipped.
	 * Revisions are always skipped.
	 *
	 * The markup saves without the kses filters when the post author has the
	 * `unfiltered_html` capability, and without the filter that strips block
	 * custom CSS when the author has the `edit_css` capability. These filters
	 * run on the content only. When they or other save filters change the
	 * markup, the post gets the note `filtered on save`.
	 *
	 * A post that fails gets the status `failed: <reason>`, and the other
	 * posts still convert. The command exits with code 1 when a post fails.
	 *
	 * With `--classic`, the command converts classic content: posts without
	 * Divi modules or blocks, such as blog posts written in the classic
	 * editor, through the same HTML conversion as text modules.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Post IDs. Defaults to every post with Divi content, or with classic content with `--classic`.
	 *
	 * [--classic]
	 * : Convert classic content: posts without Divi modules or blocks.
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types. Defaults to `post,page,product`, or to `post,page` with `--classic`, because WooCommerce edits product descriptions as classic content.
	 *
	 * [--status=<statuses>]
	 * : Comma-separated post statuses.
	 * ---
	 * default: publish,draft,private,future,pending
	 * ---
	 *
	 * [--map=<file>]
	 * : Migration map (JSON).
	 *
	 * [--dry-run]
	 * : Report without saving.
	 *
	 * [--force]
	 * : Save posts with missing blocks too; each missing block becomes a hidden placeholder. Also save posts whose conversion is empty, and convert posts whose content changed after their conversion, which discards those changes.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function convert( array $args, array $assoc_args ): void {
		$map     = $this->map( $assoc_args );
		$dry_run = isset( $assoc_args['dry-run'] );
		$force   = isset( $assoc_args['force'] );
		$classic = isset( $assoc_args['classic'] );
		$types   = $this->list_option( $assoc_args, 'post_type', $classic ? 'post,page' : 'post,page,product' );
		$states  = $this->list_option( $assoc_args, 'status' );
		$ids     = [] !== $args ? array_map( 'intval', $args ) : ( $classic ? Content::find_classic( $types, $states ) : Content::find( $types, $states ) );
		$builds  = [];

		foreach ( $ids as $index => $id ) {
			Content::flush_runtime_cache( $index );

			$post = get_post( $id );

			if ( $post instanceof WP_Post ) {
				$builds = DiviVersion::merge( $builds, DiviVersion::census( Content::source( $post ) ) );
			}
		}

		$this->warn_versions( $builds );

		// Divi content holds markup the site stored unfiltered, such as scripts and iframes in code
		// modules, and converts into per-block custom CSS. save() adds the filters back for authors
		// who may not save them.
		$this->remove_save_filters();

		$rows   = [];
		$counts = [
			'converted' => 0,
			'skipped'   => 0,
			'failed'    => 0,
		];

		try {
			foreach ( $ids as $index => $id ) {
				Content::flush_runtime_cache( $index );

				$post = get_post( $id );

				if ( ! $post instanceof WP_Post ) {
					$rows[] = [
						'ID'        => $id,
						'post_type' => '',
						'title'     => '',
						'status'    => 'failed: not found',
						'fallback'  => '',
						'missing'   => '',
						'notes'     => '',
					];

					++$counts['failed'];

					continue;
				}

				$row   = $this->convert_one( $post, $map, $classic, $force, $dry_run, $types, $states );
				$group = explode( ':', $row['status'], 2 )[0];

				++$counts[ $group ];

				$rows[] = $row;
			}
		} finally {
			$this->restore_save_filters();
		}

		WP_CLI\Utils\format_items( $assoc_args['format'], $rows, [ 'ID', 'post_type', 'title', 'status', 'fallback', 'missing', 'notes' ] );

		$summary = sprintf( '%s %d posts, skipped %d, failed %d.', $dry_run ? 'Would convert' : 'Converted', $counts['converted'], $counts['skipped'], $counts['failed'] );

		if ( $counts['skipped'] + $counts['failed'] > 0 ) {
			WP_CLI::warning( $summary );
		} else {
			WP_CLI::success( $summary );
		}

		if ( $counts['failed'] > 0 ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Lists the classes the converted markup keeps that the block editor does
	 * not write and the map does not translate, with their uses and posts.
	 *
	 * Run it while writing the map's `classes`: each class either becomes a
	 * block style, preset classes or block attributes, or is dropped.
	 *
	 * Each post is read as `convert` reads it: classic content (see
	 * `convert --classic`) through the HTML conversion, Divi content through
	 * the rules. A post ID whose post type or status is not selected is
	 * skipped with a warning.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Post IDs. Defaults to every post with Divi or classic content.
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types. Defaults to `post,page,product` for posts with Divi content and to `post,page` for posts with classic content, as `convert` does.
	 *
	 * [--status=<statuses>]
	 * : Comma-separated post statuses.
	 * ---
	 * default: publish,draft,private,future,pending
	 * ---
	 *
	 * [--map=<file>]
	 * : Migration map (JSON).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function classes( array $args, array $assoc_args ): void {
		$map           = $this->map( $assoc_args );
		$divi_types    = $this->list_option( $assoc_args, 'post_type', 'post,page,product' );
		$classic_types = $this->list_option( $assoc_args, 'post_type', 'post,page' );
		$states        = $this->list_option( $assoc_args, 'status' );
		$census        = [];

		if ( [] !== $args ) {
			$ids = array_map( 'intval', $args );
		} else {
			$ids = array_values( array_unique( [ ...Content::find( $divi_types, $states ), ...Content::find_classic( $classic_types, $states ) ] ) );
			sort( $ids );
		}

		foreach ( $ids as $index => $id ) {
			Content::flush_runtime_cache( $index );

			$post = get_post( $id );

			if ( ! $post instanceof WP_Post ) {
				continue;
			}

			$classic = Content::is_classic( $post );

			if ( ! $classic && ! Content::is_divi( Content::source( $post ) ) ) {
				continue;
			}

			if ( ! $this->is_selected( $post, $classic ? $classic_types : $divi_types, $states ) ) {
				WP_CLI::warning( sprintf( 'Post %d: skipped: post type or status not selected', $id ) );
				continue;
			}

			$converter          = Converter::create( $map );
			$converter->dry_run = true;
			$serializer         = $converter->serializer();

			$this->post_markup( $post, $converter, $serializer, $classic );

			foreach ( $serializer->classes->census as $class_name => $uses ) {
				$census[ $class_name ]['uses']    = ( $census[ $class_name ]['uses'] ?? 0 ) + $uses;
				$census[ $class_name ]['posts'][] = $id;
			}
		}

		uasort( $census, fn( array $a, array $b ) => $b['uses'] <=> $a['uses'] );

		$rows = [];

		foreach ( $census as $class_name => $entry ) {
			$rows[] = [
				'class' => $class_name,
				'uses'  => $entry['uses'],
				'posts' => implode( ',', $entry['posts'] ),
			];
		}

		WP_CLI\Utils\format_items( $assoc_args['format'], $rows, [ 'class', 'uses', 'posts' ] );
	}

	/**
	 * Lists the shortcode tags in content and widgets, with the code that
	 * registers each tag on this site.
	 *
	 * Divi module shortcodes (`et_pb_*`) are left out. A tag with an empty
	 * `registered_by` does not render on this site. Run the command on the
	 * Divi site, or on the copy before the theme switch, to see the tags that
	 * Divi or its plugins register: those stop rendering without them.
	 *
	 * ## OPTIONS
	 *
	 * [--post_type=<types>]
	 * : Comma-separated post types.
	 * ---
	 * default: post,page,product,et_pb_layout,et_header_layout,et_body_layout,et_footer_layout
	 * ---
	 *
	 * [--status=<statuses>]
	 * : Comma-separated post statuses.
	 * ---
	 * default: publish,draft,private,future,pending
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function shortcodes( array $args, array $assoc_args ): void {
		$census = new Shortcodes\Census();

		$census->add_posts( $this->list_option( $assoc_args, 'post_type' ), $this->list_option( $assoc_args, 'status' ) );
		$census->add_widgets();

		WP_CLI\Utils\format_items( $assoc_args['format'], $census->rows(), [ 'tag', 'uses', 'places', 'registered_by' ] );
	}

	/**
	 * Prints the block markup for Divi content, without saving it.
	 *
	 * For template parts and patterns: the content of a post, a Theme Builder
	 * layout or a Library item; Divi content from a file, such as a child
	 * theme's template built from shortcodes; or a widget area.
	 *
	 * The command creates the forms that a form target needs, because its
	 * output goes into theme templates. Run it with `--user=<admin>`: WS Form
	 * creates forms only for a user who may.
	 *
	 * ## OPTIONS
	 *
	 * [<id>]
	 * : Post ID.
	 *
	 * [--content=<file>]
	 * : File that holds Divi content.
	 *
	 * [--widget-area=<id>]
	 * : Widget area ID, such as `sidebar-1`. The command stops with an error when the area is not found or has no widgets.
	 *
	 * [--specs=<file>]
	 * : JSON file with a list of block specs. Prints a JSON list with the markup of each spec.
	 *
	 * [--map=<file>]
	 * : Migration map (JSON).
	 *
	 * [--portable]
	 * : Drop attachment IDs from media blocks and make upload URLs root-relative, for theme files.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function markup( array $args, array $assoc_args ): void {
		$converter  = Converter::create( $this->map( $assoc_args ) );
		$serializer = $converter->serializer();

		if ( isset( $assoc_args['specs'] ) ) {
			$specs = json_decode( (string) $this->read_file( $assoc_args['specs'] ), true );

			if ( ! is_array( $specs ) || ! array_is_list( $specs ) ) {
				WP_CLI::error( 'The specs file must hold a JSON list of block specs.' );
			}

			foreach ( $specs as $index => $spec ) {
				if ( ! is_array( $spec ) ) {
					WP_CLI::error( sprintf( 'The spec at index %d is not a JSON object.', $index ) );
				}
			}

			$portable = isset( $assoc_args['portable'] );
			$markup   = array_map(
				function ( array $spec ) use ( $serializer, $portable ) {
					$markup = $serializer->serialize( [ $spec ] );

					return $portable ? Serializer\Portable::markup( $markup ) : $markup;
				},
				$specs
			);

			$this->warn_missing( $serializer );

			WP_CLI::line( (string) wp_json_encode( $markup, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
			return;
		}

		if ( isset( $assoc_args['widget-area'] ) ) {
			$specs = Widgets::to_specs( (string) $assoc_args['widget-area'] );

			if ( is_wp_error( $specs ) ) {
				WP_CLI::error( $specs->get_error_message() );
			}
		} elseif ( isset( $assoc_args['content'] ) ) {
			$specs = $converter->convert_content( (string) $this->read_file( $assoc_args['content'] ) );
		} else {
			$post = get_post( (int) ( $args[0] ?? 0 ) );

			if ( ! $post instanceof WP_Post ) {
				WP_CLI::error( 'Pass a post ID, --content or --widget-area.' );
			}

			$specs = $converter->convert_post( $post );
		}

		$markup = $serializer->serialize( $specs );

		if ( isset( $assoc_args['portable'] ) ) {
			$markup = Serializer\Portable::markup( $markup );
		}

		$this->warn_missing( $serializer );

		WP_CLI::line( $markup );
	}

	/**
	 * Restores the original Divi or classic content of converted posts.
	 *
	 * The content saves without the kses and custom CSS filters, as it was
	 * before the conversion. Each post keeps its status, its dates and its
	 * modified date. The backup is deleted only when the saved content equals
	 * it.
	 *
	 * A post whose content changed after its conversion is skipped, unless
	 * `--force` is passed. A post that is not found or fails to restore gets a
	 * warning, and the command exits with code 1 after the other posts.
	 *
	 * ## OPTIONS
	 *
	 * [<id>...]
	 * : Post IDs. Pass post IDs or `--all`.
	 *
	 * [--all]
	 * : Restore every post that has a backup of its original content, of any post type and status.
	 *
	 * [--force]
	 * : Restore posts edited after conversion too; discards the edits.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function reset( array $args, array $assoc_args ): void {
		$all = isset( $assoc_args['all'] );

		if ( [] === $args && ! $all ) {
			WP_CLI::error( 'Pass the IDs of the posts to restore, or --all to restore every post with a backup: wp divi-migration reset <id>... | --all' );
		}

		$ids      = $all ? Content::find_backed_up() : array_map( 'intval', $args );
		$force    = isset( $assoc_args['force'] );
		$total    = count( $ids );
		$restored = 0;
		$failed   = 0;

		$this->remove_save_filters();

		try {
			foreach ( $ids as $index => $id ) {
				Content::flush_runtime_cache( $index );

				$post = get_post( $id );

				if ( ! $post instanceof WP_Post ) {
					WP_CLI::warning( sprintf( 'Post %d: failed: not found.', $id ) );
					++$failed;
					continue;
				}

				if ( ! $force && Content::is_edited( $post ) ) {
					WP_CLI::warning( sprintf( 'Post %d: skipped: edited after conversion', $id ) );
					continue;
				}

				try {
					$result = Content::restore( $post );
				} catch ( \Throwable $error ) {
					$result = new WP_Error( 's3s_divi_migration_restore_failed', $error->getMessage() );
				}

				if ( is_wp_error( $result ) ) {
					WP_CLI::warning( sprintf( 'Post %d: failed: %s', $id, $result->get_error_message() ) );
					++$failed;
					continue;
				}

				++$restored;
			}
		} finally {
			$this->restore_save_filters();
		}

		$summary = sprintf( 'Restored %d of %d posts.', $restored, $total );

		if ( $restored < $total ) {
			WP_CLI::warning( $summary );
		} else {
			WP_CLI::success( $summary );
		}

		if ( $failed > 0 ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Returns a post's block markup.
	 *
	 * @param WP_Post               $post       Post.
	 * @param Converter             $converter  Converter.
	 * @param Serializer\Serializer $serializer Serializer.
	 * @param bool                  $classic    Whether the post holds classic content.
	 * @return string
	 */
	private function post_markup( WP_Post $post, Converter $converter, Serializer\Serializer $serializer, bool $classic ): string {
		if ( $classic ) {
			// Classic content gets its paragraphs on display, from wpautop().
			return $serializer->serialize( [ Spec::raw( wpautop( Content::source( $post ) ) ) ] );
		}

		return $serializer->serialize( $converter->convert_post( $post ) );
	}

	/**
	 * Converts one post and returns its table row.
	 *
	 * An error thrown while the post converts or saves fails that post only.
	 *
	 * @param WP_Post  $post    Post.
	 * @param Map      $map     Migration map.
	 * @param bool     $classic Whether to convert classic content.
	 * @param bool     $force   Whether to save posts that would be skipped (see `convert`).
	 * @param bool     $dry_run Whether to report without saving.
	 * @param string[] $types   Selected post types.
	 * @param string[] $states  Selected post statuses.
	 * @return array<string, int|string> Row with the post's status, fallback modules, missing blocks and notes.
	 */
	private function convert_one( WP_Post $post, Map $map, bool $classic, bool $force, bool $dry_run, array $types, array $states ): array {
		$row = [
			'ID'        => $post->ID,
			'post_type' => $post->post_type,
			'title'     => $post->post_title,
			'status'    => $this->skip_reason( $post, $classic, $force, $types, $states ),
			'fallback'  => '',
			'missing'   => '',
			'notes'     => '',
		];

		if ( '' !== $row['status'] ) {
			return $row;
		}

		try {
			// The first pass creates no forms: only a post that is saved gets its forms.
			$converter          = Converter::create( $map );
			$converter->dry_run = true;
			$serializer         = $converter->serializer();
			$markup             = $this->post_markup( $post, $converter, $serializer, $classic );
			$trimmed            = trim( $markup );

			$missing  = array_values( array_unique( array_merge( $serializer->missing, $serializer->unsupported ) ) );
			$fallback = array_keys( array_filter( $converter->report(), fn( array $outcomes ) => isset( $outcomes['fallback'] ) ) );
			$notes    = array_values( array_unique( $serializer->notes ) );

			foreach ( $converter->report() as $tag => $outcomes ) {
				foreach ( array_keys( $outcomes ) as $outcome ) {
					if ( str_starts_with( $outcome, 'kept: ' ) ) {
						$notes[] = $tag . ' ' . $outcome;
					}
				}
			}

			$row['fallback'] = implode( ', ', $fallback );
			$row['missing']  = implode( ', ', $missing );

			if ( [] !== $missing && ! $force ) {
				$row['status'] = 'skipped';
			} elseif ( '' === $trimmed && ! $force ) {
				$row['status'] = 'skipped: empty result';
			} elseif ( $dry_run ) {
				$row['status'] = 'converted';
			} else {
				$converter          = Converter::create( $map );
				$converter->dry_run = false;
				$markup             = $this->post_markup( $post, $converter, $converter->serializer(), $classic );
				$saved              = $this->save( $post, $markup, $classic );
				$row['status']      = $saved['status'];

				if ( $saved['filtered'] ) {
					$notes[] = 'filtered on save';
				}

				if ( '' !== $saved['note'] ) {
					$notes[] = $saved['note'];
				}
			}

			$row['notes'] = implode( '; ', $notes );
		} catch ( \Throwable $error ) {
			$row['status'] = 'failed: ' . $error->getMessage();
		}

		return $row;
	}

	/**
	 * Whether a post has one of the selected post types and statuses. A
	 * revision is never selected.
	 *
	 * @param WP_Post  $post   Post.
	 * @param string[] $types  Selected post types.
	 * @param string[] $states Selected post statuses.
	 * @return bool
	 */
	private function is_selected( WP_Post $post, array $types, array $states ): bool {
		return 'revision' !== $post->post_type
			&& in_array( $post->post_type, $types, true )
			&& in_array( $post->post_status, $states, true );
	}

	/**
	 * Returns why a post is not converted, or an empty string when it is.
	 *
	 * @param WP_Post  $post    Post.
	 * @param bool     $classic Whether to convert classic content.
	 * @param bool     $force   Whether to convert posts edited after their conversion.
	 * @param string[] $types   Selected post types.
	 * @param string[] $states  Selected post statuses.
	 * @return string
	 */
	private function skip_reason( WP_Post $post, bool $classic, bool $force, array $types, array $states ): string {
		if ( ! $this->is_selected( $post, $types, $states ) ) {
			return 'skipped: post type or status not selected';
		}

		if ( $classic && ! Content::is_classic( $post ) ) {
			return 'skipped: not classic content';
		}

		if ( ! $classic && ! Content::is_divi( Content::source( $post ) ) ) {
			return 'skipped: not Divi content';
		}

		if ( ! $force && Content::is_edited( $post ) ) {
			return 'skipped: edited after conversion';
		}

		return '';
	}

	/**
	 * Backs up a post's original content, saves its block markup, and marks
	 * it as converted.
	 *
	 * The post keeps its status, its dates and its modified date, so a
	 * scheduled post whose date has passed is not published. The save
	 * filters are removed again after the save, also when it throws.
	 *
	 * @param WP_Post $post    Post.
	 * @param string  $markup  Block markup.
	 * @param bool    $classic Whether the post holds classic content.
	 * @return array{status: string, filtered: bool, note: string} Status (`converted`, or `failed: <reason>`),
	 *                                                              whether the saved content differs from the
	 *                                                              markup, and a note about the save, or an empty string.
	 */
	private function save( WP_Post $post, string $markup, bool $classic = false ): array {
		if ( ! Content::backup( $post ) ) {
			return [
				'status'   => 'failed: the original content could not be backed up',
				'filtered' => false,
				'note'     => '',
			];
		}

		$this->add_author_save_filters( $post );

		try {
			$result = Content::update_content( $post, $markup );
		} finally {
			$this->remove_save_filters();
		}

		if ( is_wp_error( $result ) ) {
			return [
				'status'   => 'failed: ' . $result->get_error_message(),
				'filtered' => false,
				'note'     => '',
			];
		}

		$kept = Content::keep_dates_and_status( $post );

		Content::mark_converted( $post, $classic );

		$saved = (string) get_post_field( 'post_content', $post->ID, 'raw' );

		return [
			'status'   => 'converted',
			'filtered' => $saved !== $markup,
			'note'     => is_wp_error( $kept ) ? $kept->get_error_message() : '',
		];
	}

	/**
	 * Removes the kses filters and the filters that strip block custom CSS,
	 * so content saves as it is.
	 *
	 * @return void
	 */
	private function remove_save_filters(): void {
		kses_remove_filters();
		remove_filter( 'content_save_pre', 'wp_strip_custom_css_from_blocks', 8 );
		remove_filter( 'content_filtered_save_pre', 'wp_strip_custom_css_from_blocks', 8 );
	}

	/**
	 * Adds the content filters that core applies for the post author: the
	 * kses filters without `unfiltered_html`, and the custom CSS filter
	 * without `edit_css`.
	 *
	 * Only `content_save_pre` gets them, so the title, excerpt and filtered
	 * content, which the save does not change, are not filtered again.
	 *
	 * @param WP_Post $post Post.
	 * @return void
	 */
	private function add_author_save_filters( WP_Post $post ): void {
		$author = (int) $post->post_author;

		if ( ! user_can( $author, 'unfiltered_html' ) ) {
			add_filter( 'content_save_pre', 'wp_filter_global_styles_post', 9 );
			add_filter( 'content_save_pre', 'wp_filter_post_kses' );
		}

		if ( ! user_can( $author, 'edit_css' ) ) {
			add_filter( 'content_save_pre', 'wp_strip_custom_css_from_blocks', 8 );
		}
	}

	/**
	 * Puts back the save filters that core sets for the current user, after
	 * a command removed them.
	 *
	 * @return void
	 */
	private function restore_save_filters(): void {
		kses_init();

		if ( ! current_user_can( 'edit_css' ) ) {
			add_filter( 'content_save_pre', 'wp_strip_custom_css_from_blocks', 8 );
			add_filter( 'content_filtered_save_pre', 'wp_strip_custom_css_from_blocks', 8 );
		}
	}

	/**
	 * Warns about each block that a serializer wrote no markup for.
	 *
	 * @param Serializer\Serializer $serializer Serializer.
	 * @return void
	 */
	private function warn_missing( Serializer\Serializer $serializer ): void {
		foreach ( array_unique( array_merge( $serializer->missing, $serializer->unsupported ) ) as $name ) {
			WP_CLI::warning( sprintf( 'No markup for %s: the block is not registered or has no save.', $name ) );
		}
	}

	/**
	 * Returns the migration map from the `--map` option, or an empty map.
	 *
	 * @param array $assoc_args Associative arguments.
	 * @return Map
	 */
	private function map( array $assoc_args ): Map {
		if ( ! isset( $assoc_args['map'] ) ) {
			return new Map();
		}

		$map = Map::from_file( (string) $assoc_args['map'] );

		if ( $map instanceof WP_Error ) {
			WP_CLI::error( $map->get_error_message() );
		}

		return $map;
	}

	/**
	 * Reads a comma-separated list option: splits it, trims each item and drops
	 * empty items. Stops with an error when no item is left.
	 *
	 * @param array  $assoc_args Associative arguments.
	 * @param string $name       Option name.
	 * @param string $fallback   List to use when the option is not set.
	 * @return string[]
	 */
	private function list_option( array $assoc_args, string $name, string $fallback = '' ): array {
		$value = (string) ( $assoc_args[ $name ] ?? $fallback );
		$items = array_values( array_filter( array_map( 'trim', explode( ',', $value ) ), fn( string $item ) => '' !== $item ) );

		if ( [] === $items ) {
			WP_CLI::error( sprintf( 'The --%s option needs at least one comma-separated value.', $name ) );
		}

		return $items;
	}

	/**
	 * Returns a file's contents, or stops with an error.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private function read_file( string $path ): string {
		if ( ! is_readable( $path ) ) {
			WP_CLI::error( sprintf( 'Cannot read %s.', $path ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
		return (string) file_get_contents( $path );
	}

	/**
	 * Adds a node's modules to the counts.
	 *
	 * @param Node  $node   Parsed node.
	 * @param int   $id     Post ID.
	 * @param array $counts Counts keyed by tag.
	 * @return void
	 */
	private function count( Node $node, int $id, array &$counts ): void {
		foreach ( $node->children as $child ) {
			$counts[ $child->tag ]['uses']         = ( $counts[ $child->tag ]['uses'] ?? 0 ) + 1;
			$counts[ $child->tag ]['posts'][ $id ] = true;

			foreach ( array_keys( $child->attrs ) as $name ) {
				$counts[ $child->tag ]['settings'][ $name ] = true;
			}

			$counts[ $child->tag ]['settings'] ??= [];

			$this->count( $child, $id, $counts );
		}
	}

	/**
	 * Prints the installed, tested and content Divi versions, then warns when
	 * they differ from the tested version.
	 *
	 * @param array<string, int> $builds Module count keyed by `_builder_version`.
	 * @param bool               $verbose Whether to print the versions.
	 * @return void
	 */
	private function report_versions( array $builds, bool $verbose ): void {
		if ( $verbose ) {
			$installed = DiviVersion::installed();
			$versions  = [];

			uksort( $builds, 'version_compare' );

			foreach ( $builds as $version => $count ) {
				$versions[] = sprintf( '%s (%d)', $version, $count );
			}

			WP_CLI::log( sprintf( 'Divi installed: %s', null === $installed ? 'not found' : $installed['version'] . ' (' . $installed['source'] . ')' ) );
			WP_CLI::log( sprintf( 'Divi tested: %s', DiviVersion::TESTED ) );
			WP_CLI::log( sprintf( 'Content builder versions (modules): %s', [] === $versions ? 'none' : implode( ', ', $versions ) ) );
		}

		$this->warn_versions( $builds );
	}

	/**
	 * Warns when the installed Divi version or the content's builder versions
	 * differ from the tested version.
	 *
	 * @param array<string, int> $builds Module count keyed by `_builder_version`.
	 * @return void
	 */
	private function warn_versions( array $builds ): void {
		$installed = DiviVersion::installed();
		$warning   = DiviVersion::warning( null === $installed ? '' : $installed['version'], $builds );

		if ( '' !== $warning ) {
			WP_CLI::warning( $warning );
		}
	}
}
