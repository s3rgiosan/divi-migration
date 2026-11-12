<?php

namespace S3S\WP\DiviMigration;

use WP_Error;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Reads, backs up and restores the original content of posts.
 *
 * The original content is kept in post meta, so a post can be converted
 * again from its Divi or classic source, or restored.
 */
class Content {

	/**
	 * Meta key for the original content.
	 */
	const BACKUP_META = '_s3s_divi_migration_content';

	/**
	 * Meta key for the time of the last conversion.
	 */
	const CONVERTED_META = '_s3s_divi_migration_converted_at';

	/**
	 * Meta key for the MD5 hash of the content the last conversion saved.
	 */
	const MARKUP_HASH_META = '_s3s_divi_migration_markup_hash';

	/**
	 * Divi's meta key that turns the builder on for a post.
	 */
	const DIVI_BUILDER_META = '_et_pb_use_builder';

	/**
	 * Meta key for the original value of Divi's builder meta: `exists` says
	 * whether the post had the meta, and `value` holds its value.
	 */
	const BUILDER_BACKUP_META = '_s3s_divi_migration_builder';

	/**
	 * Whether content holds Divi Builder modules.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	public static function is_divi( string $content ): bool {
		return str_contains( $content, '[et_pb_' );
	}

	/**
	 * Whether a post holds classic content: no Divi modules and no blocks, or
	 * a backup of classic content from an earlier conversion.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_classic( WP_Post $post ): bool {
		$backup = get_post_meta( $post->ID, self::BACKUP_META, true );

		if ( is_string( $backup ) && '' !== $backup ) {
			return ! self::is_divi( $backup );
		}

		$content = trim( $post->post_content );

		if ( '' === $content ) {
			return false;
		}

		return ! self::is_divi( $content ) && ! has_blocks( $content );
	}

	/**
	 * Returns the content to convert: the backup when the post was converted
	 * before, otherwise the current content.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function source( WP_Post $post ): string {
		$backup = get_post_meta( $post->ID, self::BACKUP_META, true );
		return is_string( $backup ) && '' !== $backup ? $backup : $post->post_content;
	}

	/**
	 * Whether a post was converted.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_converted( WP_Post $post ): bool {
		$converted_at = get_post_meta( $post->ID, self::CONVERTED_META, true );
		return is_string( $converted_at ) && '' !== $converted_at;
	}

	/**
	 * Whether a converted post's content changed after its last conversion.
	 *
	 * A converted post with no stored hash counts as not edited.
	 *
	 * @param WP_Post $post Post.
	 * @return bool
	 */
	public static function is_edited( WP_Post $post ): bool {
		if ( ! self::is_converted( $post ) ) {
			return false;
		}

		$hash = get_post_meta( $post->ID, self::MARKUP_HASH_META, true );

		if ( ! is_string( $hash ) || '' === $hash ) {
			return false;
		}

		$current = md5( $post->post_content );

		return $current !== $hash;
	}

	/**
	 * Backs up a post's current content when it has no backup yet.
	 *
	 * @param WP_Post $post Post.
	 * @return bool Whether the post has a backup.
	 */
	public static function backup( WP_Post $post ): bool {
		if ( metadata_exists( 'post', $post->ID, self::BACKUP_META ) ) {
			$backup = get_post_meta( $post->ID, self::BACKUP_META, true );
			return is_string( $backup ) && '' !== $backup;
		}

		if ( '' === $post->post_content ) {
			return false;
		}

		update_post_meta( $post->ID, self::BACKUP_META, wp_slash( $post->post_content ) );

		$saved = get_post_meta( $post->ID, self::BACKUP_META, true );

		return $saved === $post->post_content;
	}

	/**
	 * Marks a post as converted after its block markup was saved.
	 *
	 * Keeps the time of the conversion and the hash of the saved content, and
	 * turns the Divi Builder off for a post converted from Divi content. The
	 * original builder meta is backed up once, so a reset restores it.
	 *
	 * @param WP_Post $post    Post.
	 * @param bool    $classic Whether the post was converted from classic content.
	 * @return void
	 */
	public static function mark_converted( WP_Post $post, bool $classic ): void {
		$saved = (string) get_post_field( 'post_content', $post->ID, 'raw' );

		// A converted post with no builder backup has `off` already: a backup would store `off`.
		$is_legacy = metadata_exists( 'post', $post->ID, self::CONVERTED_META ) && ! metadata_exists( 'post', $post->ID, self::BUILDER_BACKUP_META );

		update_post_meta( $post->ID, self::CONVERTED_META, gmdate( 'c' ) );
		update_post_meta( $post->ID, self::MARKUP_HASH_META, md5( $saved ) );

		if ( ! $classic ) {
			if ( ! $is_legacy && ! metadata_exists( 'post', $post->ID, self::BUILDER_BACKUP_META ) ) {
				$exists = metadata_exists( 'post', $post->ID, self::DIVI_BUILDER_META );
				$value  = $exists ? (string) get_post_meta( $post->ID, self::DIVI_BUILDER_META, true ) : '';

				update_post_meta(
					$post->ID,
					self::BUILDER_BACKUP_META,
					wp_slash(
						[
							'exists' => $exists,
							'value'  => $value,
						]
					)
				);
			}

			update_post_meta( $post->ID, self::DIVI_BUILDER_META, 'off' );
		}
	}

	/**
	 * Restores a post's original content from the backup, and keeps its
	 * status and dates (see keep_dates_and_status()).
	 *
	 * The backup is deleted only when the saved content equals it.
	 *
	 * Call it with the kses and custom CSS filters removed, so the content
	 * saves as it was.
	 *
	 * @param WP_Post $post Post.
	 * @return true|WP_Error True when the backup was restored.
	 */
	public static function restore( WP_Post $post ): true|WP_Error {
		$backup = get_post_meta( $post->ID, self::BACKUP_META, true );

		if ( ! is_string( $backup ) || '' === $backup ) {
			return new WP_Error( 's3s_divi_migration_no_backup', 'The post has no backup.' );
		}

		$result = self::update_content( $post, $backup );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$kept = self::keep_dates_and_status( $post );

		if ( is_wp_error( $kept ) ) {
			return $kept;
		}

		$restored = (string) get_post_field( 'post_content', $post->ID, 'raw' );

		if ( $restored !== $backup ) {
			return new WP_Error( 's3s_divi_migration_restore_mismatch', 'The saved content differs from the backup. The backup is kept.' );
		}

		delete_post_meta( $post->ID, self::BACKUP_META );
		delete_post_meta( $post->ID, self::CONVERTED_META );
		delete_post_meta( $post->ID, self::MARKUP_HASH_META );

		self::restore_builder_meta( $post, $backup );

		return true;
	}

	/**
	 * Saves new content for a post, and keeps its status and dates.
	 *
	 * A save through wp_update_post() gives a draft without a date the
	 * current date, and publishes a scheduled post whose date is less than
	 * a minute ahead. A `wp_insert_post_data` filter for this post writes the
	 * original status and dates into the saved data, so the post keeps them
	 * and no status transition, such as a publish, happens. The filter runs
	 * last and is removed after the save.
	 *
	 * The save of a scheduled post schedules its publish event again, at the
	 * post date. After the save, the event is put back as it was: at its old
	 * time, or removed when the post had none. So the save never changes
	 * whether or when the post is published.
	 *
	 * The save keeps the page template meta of the post. The save allows a
	 * template that the active theme does not have, so core does not return
	 * an error for it.
	 *
	 * The save does not run the hook that schedules pings and enclosure
	 * checks for a published post.
	 *
	 * Call keep_dates_and_status() after the save to put back the modified
	 * date.
	 *
	 * @param WP_Post $post    Post as it was before the save.
	 * @param string  $content New content, unslashed.
	 * @return int|WP_Error Post ID, or the error of the save.
	 */
	public static function update_content( WP_Post $post, string $content ): int|WP_Error {
		$event_args = [ $post->ID ];
		$event_time = wp_next_scheduled( 'publish_future_post', $event_args );

		$keep = function ( array $data, array $postarr ) use ( $post ): array {
			$id = (int) ( $postarr['ID'] ?? 0 );

			if ( $id !== $post->ID ) {
				return $data;
			}

			$data['post_status']   = $post->post_status;
			$data['post_date']     = $post->post_date;
			$data['post_date_gmt'] = $post->post_date_gmt;

			return $data;
		};

		$template = get_post_meta( $post->ID, '_wp_page_template', true );

		$allow_template = function ( $templates, $theme, $current ) use ( $post, $template ) {
			if ( ! is_array( $templates ) || ! $current instanceof WP_Post || $current->ID !== $post->ID ) {
				return $templates;
			}

			if ( is_string( $template ) && '' !== $template && ! isset( $templates[ $template ] ) ) {
				$templates[ $template ] = $template;
			}

			return $templates;
		};

		$ping_priority = has_action( 'publish_post', '_publish_post_hook' );

		add_filter( 'wp_insert_post_data', $keep, PHP_INT_MAX, 2 );
		add_filter( 'theme_templates', $allow_template, PHP_INT_MAX, 3 );

		if ( false !== $ping_priority ) {
			remove_action( 'publish_post', '_publish_post_hook', $ping_priority );
		}

		try {
			$result = wp_update_post(
				[
					'ID'           => $post->ID,
					'post_content' => wp_slash( $content ),
				],
				true
			);
		} finally {
			remove_filter( 'wp_insert_post_data', $keep, PHP_INT_MAX );
			remove_filter( 'theme_templates', $allow_template, PHP_INT_MAX );

			if ( false !== $ping_priority ) {
				add_action( 'publish_post', '_publish_post_hook', $ping_priority, 1 );
			}

			wp_clear_scheduled_hook( 'publish_future_post', $event_args );

			if ( is_int( $event_time ) ) {
				wp_schedule_single_event( $event_time, 'publish_future_post', $event_args );
			}
		}

		return is_wp_error( $result ) ? $result : (int) $result;
	}

	/**
	 * Puts back a post's modified dates after a save that changes only its
	 * markup, and its status and dates when a filter changed them.
	 *
	 * A save through wp_update_post() always sets the modified date to now.
	 * The post's publish event stays as update_content() left it.
	 *
	 * @param WP_Post $post Post as it was before the save.
	 * @return true|WP_Error True when the fields are back, or the error of the update.
	 */
	public static function keep_dates_and_status( WP_Post $post ): true|WP_Error {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No API saves a post without changing these fields.
		$updated = $wpdb->update(
			$wpdb->posts,
			[
				'post_status'       => $post->post_status,
				'post_date'         => $post->post_date,
				'post_date_gmt'     => $post->post_date_gmt,
				'post_modified'     => $post->post_modified,
				'post_modified_gmt' => $post->post_modified_gmt,
			],
			[ 'ID' => $post->ID ]
		);

		clean_post_cache( $post->ID );

		if ( false === $updated ) {
			return new WP_Error( 's3s_divi_migration_keep_dates', 'The status and dates of the post could not be put back.' );
		}

		return true;
	}

	/**
	 * Returns the IDs of posts with classic content (see is_classic()).
	 *
	 * @param string[] $post_types Post types.
	 * @param string[] $statuses   Post statuses.
	 * @return int[]
	 */
	public static function find_classic( array $post_types, array $statuses ): array {
		$ids = get_posts(
			[
				'post_type'      => $post_types,
				'post_status'    => $statuses,
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
			]
		);

		$processed = 0;

		return array_values(
			array_filter(
				array_map( 'intval', $ids ),
				function ( int $id ) use ( &$processed ) {
					$post = get_post( $id );

					self::flush_runtime_cache( ++$processed );

					return $post instanceof WP_Post && self::is_classic( $post );
				}
			)
		);
	}

	/**
	 * Returns the IDs of posts that hold Divi content, or were converted from it.
	 *
	 * @param string[] $post_types Post types.
	 * @param string[] $statuses   Post statuses.
	 * @return int[]
	 */
	public static function find( array $post_types, array $statuses ): array {
		global $wpdb;

		$types_in    = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$statuses_in = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		// The IN lists hold one placeholder per value, so the SQL is built from placeholders only.
		// phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s
				WHERE p.post_type IN ($types_in) AND p.post_status IN ($statuses_in)
				AND ( p.post_content LIKE %s OR m.meta_id IS NOT NULL )
				ORDER BY p.ID",
				self::BACKUP_META,
				...$post_types,
				...$statuses,
				...[ '%' . $wpdb->esc_like( '[et_pb_' ) . '%' ]
			)
		);
		// phpcs:enable

		// A backup can hold classic content too (`convert --classic`): keep the posts whose original content is Divi.
		$processed = 0;

		return array_values(
			array_filter(
				array_map( 'intval', $ids ),
				function ( int $id ) use ( &$processed ) {
					$is_divi = self::is_divi( (string) get_post_field( 'post_content', $id, 'raw' ) ) || self::is_divi( (string) get_post_meta( $id, self::BACKUP_META, true ) );

					self::flush_runtime_cache( ++$processed );

					return $is_divi;
				}
			)
		);
	}

	/**
	 * Returns the IDs of posts that have a backup of their original content,
	 * of any post type and status.
	 *
	 * @return int[]
	 */
	public static function find_backed_up(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- No API selects posts by meta key across every post type and status.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY post_id",
				self::BACKUP_META
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Empties the in-memory object cache once for every 100 posts of a loop,
	 * so a long run does not fill the memory.
	 *
	 * An external object cache is left as it is, because core's
	 * wp_cache_flush_runtime() flushes it when the drop-in has no runtime
	 * flush.
	 *
	 * @param int $processed Number of posts processed so far.
	 * @return void
	 */
	public static function flush_runtime_cache( int $processed ): void {
		if ( $processed < 1 || 0 !== $processed % 100 || wp_using_ext_object_cache() ) {
			return;
		}

		wp_cache_flush_runtime();
	}

	/**
	 * Puts back Divi's builder meta after a restore.
	 *
	 * The original value comes back, or the meta is deleted when the post had
	 * none. A post with no stored backup gets `on` when its original content is
	 * Divi content.
	 *
	 * @param WP_Post $post   Post.
	 * @param string  $backup Original content.
	 * @return void
	 */
	private static function restore_builder_meta( WP_Post $post, string $backup ): void {
		$original = get_post_meta( $post->ID, self::BUILDER_BACKUP_META, true );

		if ( ! is_array( $original ) ) {
			if ( self::is_divi( $backup ) ) {
				update_post_meta( $post->ID, self::DIVI_BUILDER_META, 'on' );
			}

			return;
		}

		if ( ! empty( $original['exists'] ) ) {
			update_post_meta( $post->ID, self::DIVI_BUILDER_META, wp_slash( (string) ( $original['value'] ?? '' ) ) );
		} else {
			delete_post_meta( $post->ID, self::DIVI_BUILDER_META );
		}

		delete_post_meta( $post->ID, self::BUILDER_BACKUP_META );
	}
}
