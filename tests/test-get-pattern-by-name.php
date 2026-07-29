<?php
/**
 * @package GetPatternsByPatternName
 */

/**
 * get_pattern_by_name() の統合テスト.
 */
class GPBPN_Test_Get_Pattern_By_Name extends WP_UnitTestCase {

	public function test_returns_null_for_non_string_input() {
		$this->assertNull( get_pattern_by_name( array( 'not', 'a', 'string' ) ) );
		$this->assertNull( get_pattern_by_name( 123 ) );
		$this->assertNull( get_pattern_by_name( null ) );
	}

	public function test_returns_null_for_empty_or_whitespace_only_input() {
		$this->assertNull( get_pattern_by_name( '' ) );
		$this->assertNull( get_pattern_by_name( '   ' ) );
	}

	public function test_returns_null_for_input_exceeding_max_length() {
		$this->assertNull( get_pattern_by_name( str_repeat( 'a', 256 ) ) );
	}

	public function test_max_name_length_filter_changes_the_limit() {
		add_filter(
			'gpbpn_max_name_length',
			function () {
				return 3;
			}
		);

		$this->assertNull( get_pattern_by_name( 'abcd' ) );
	}

	public function test_finds_pattern_by_exact_japanese_title() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_title'  => 'ヘッダーバナー',
				'post_status' => 'publish',
			)
		);

		$pattern = get_pattern_by_name( 'ヘッダーバナー' );

		$this->assertInstanceOf( WP_Post::class, $pattern );
		$this->assertSame( $id, $pattern->ID );
	}

	public function test_returns_null_when_pattern_not_found() {
		$this->assertNull( get_pattern_by_name( '存在しないパターン' ) );
	}

	public function test_pattern_not_found_action_fires() {
		$fired = false;
		add_action(
			'gpbpn_pattern_not_found',
			function ( $name ) use ( &$fired ) {
				$fired = ( 'no-such-pattern' === $name );
			}
		);

		get_pattern_by_name( 'no-such-pattern' );

		$this->assertTrue( $fired );
	}

	public function test_duplicate_names_resolve_to_oldest_and_fire_action() {
		$older = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_title'  => 'Duplicate',
				'post_status' => 'publish',
			)
		);
		$newer = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_title'  => 'Duplicate',
				'post_status' => 'publish',
			)
		);

		$captured_ids = null;
		add_action(
			'gpbpn_duplicate_pattern_found',
			function ( $name, $ids ) use ( &$captured_ids ) {
				$captured_ids = $ids;
			},
			10,
			2
		);

		$pattern = get_pattern_by_name( 'Duplicate' );

		$this->assertSame( $older, $pattern->ID );
		$this->assertIsArray( $captured_ids );
		$this->assertContains( $older, $captured_ids );
		$this->assertContains( $newer, $captured_ids );
	}

	public function test_pre_get_pattern_filter_short_circuits_query() {
		add_filter(
			'gpbpn_pre_get_pattern',
			function () {
				return new stdClass(); // WP_Post 以外は null に丸められる.
			}
		);

		$this->assertNull( get_pattern_by_name( 'anything' ) );
	}

	public function test_result_filter_can_invalidate_the_return_value() {
		self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_title'  => 'Replaceable',
				'post_status' => 'publish',
			)
		);

		add_filter(
			'gpbpn_result',
			function () {
				return 'not-a-post'; // 不正な戻り値は null に丸められる.
			}
		);

		$this->assertNull( get_pattern_by_name( 'Replaceable' ) );
	}

	public function test_query_args_filter_cannot_override_post_type_or_fields() {
		self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Hijack Target',
				'post_status' => 'publish',
			)
		);

		add_filter(
			'gpbpn_query_args',
			function ( $args ) {
				$args['post_type'] = 'post';
				$args['fields']    = 'all';
				return $args;
			}
		);

		$this->assertNull( get_pattern_by_name( 'Hijack Target' ) );
	}

	public function test_non_publish_status_requires_read_post_capability() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_title'  => 'Private Pattern',
				'post_status' => 'private',
			)
		);

		add_filter(
			'gpbpn_query_args',
			function ( $args ) {
				$args['post_status'] = array( 'publish', 'private' );
				return $args;
			}
		);

		$admin_id = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $admin_id );

		// wp_block は capability_type が独自の "block" のため、read_private_blocks 相当の権限は
		// 既定でどのロールにも付与されていない(readme の FAQ 参照). 付与しない限り admin でも null になる.
		$this->assertNull( get_pattern_by_name( 'Private Pattern' ) );

		get_role( 'administrator' )->add_cap( 'read_private_blocks' );
		// wp_set_current_user() は同じユーザー ID を渡すと再構築をスキップするため、
		// ロールに付与した権限を current user の allcaps に反映させるには
		// get_role_caps() を明示的に呼び直す必要がある.
		wp_get_current_user()->get_role_caps();

		$pattern = get_pattern_by_name( 'Private Pattern' );
		$this->assertInstanceOf( WP_Post::class, $pattern );
		$this->assertSame( $id, $pattern->ID );
	}

	public function test_backslash_in_title_is_silently_stripped_on_save() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_title'  => 'Foo\\Bar',
				'post_status' => 'publish',
			)
		);

		// wp_insert_post() が保存前に入力値を unslash するため、タイトルに含めた
		// バックスラッシュは保存時点で既に失われる(既知の制約. 2.0.0 での
		// sanitize 方式変更まで解消されない). そのため取得自体はできるが、
		// 返ってくる post_title からはバックスラッシュが消えている.
		$pattern = get_pattern_by_name( 'Foo\\Bar' );

		$this->assertInstanceOf( WP_Post::class, $pattern );
		$this->assertSame( $id, $pattern->ID );
		$this->assertSame( 'FooBar', $pattern->post_title );
	}

	public function test_strict_title_match_rejects_collation_based_case_mismatch() {
		self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_title'  => 'HeaderBanner',
				'post_status' => 'publish',
			)
		);

		// 既定(非 strict)では DB の照合順序により大文字小文字を区別せずマッチする.
		$this->assertInstanceOf( WP_Post::class, get_pattern_by_name( 'headerbanner' ) );

		add_filter( 'gpbpn_strict_title_match', '__return_true' );

		$this->assertNull( get_pattern_by_name( 'headerbanner' ) );
		$this->assertInstanceOf( WP_Post::class, get_pattern_by_name( 'HeaderBanner' ) );
	}

	public function test_synced_only_filter_excludes_unsynced_patterns() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_title'  => 'Unsynced Pattern',
				'post_status' => 'publish',
			)
		);
		update_post_meta( $id, 'wp_pattern_sync_status', 'unsynced' );

		// 既定では非同期パターンも取得対象.
		$this->assertInstanceOf( WP_Post::class, get_pattern_by_name( 'Unsynced Pattern' ) );

		add_filter( 'gpbpn_synced_only', '__return_true' );

		$this->assertNull( get_pattern_by_name( 'Unsynced Pattern' ) );
	}

	public function test_synced_only_filter_still_finds_synced_patterns() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_title'  => 'Synced Pattern',
				'post_status' => 'publish',
			)
		);

		add_filter( 'gpbpn_synced_only', '__return_true' );

		$pattern = get_pattern_by_name( 'Synced Pattern' );
		$this->assertInstanceOf( WP_Post::class, $pattern );
		$this->assertSame( $id, $pattern->ID );
	}

	public function test_repeated_lookups_reuse_the_cached_result() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_title'  => 'Cached Pattern',
				'post_status' => 'publish',
			)
		);

		$query_count = 0;
		$counter     = function ( $query ) use ( &$query_count ) {
			if ( 'wp_block' === $query->get( 'post_type' ) && 'Cached Pattern' === $query->get( 'title' ) ) {
				++$query_count;
			}
		};
		add_action( 'parse_query', $counter );

		$first  = get_pattern_by_name( 'Cached Pattern' );
		$second = get_pattern_by_name( 'Cached Pattern' );

		remove_action( 'parse_query', $counter );

		$this->assertSame( 1, $query_count, '2 回目の呼び出しはキャッシュから返され、再クエリは発生しないはず.' );
		$this->assertSame( $id, $first->ID );
		$this->assertSame( $id, $second->ID );
	}

	public function test_updating_the_pattern_invalidates_the_cache() {
		$id = self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_title'  => 'Stale Pattern',
				'post_status' => 'publish',
			)
		);

		get_pattern_by_name( 'Stale Pattern' ); // キャッシュを温める.

		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => 'updated content',
			)
		);

		$query_count = 0;
		$counter     = function ( $query ) use ( &$query_count ) {
			if ( 'wp_block' === $query->get( 'post_type' ) ) {
				++$query_count;
			}
		};
		add_action( 'parse_query', $counter );

		get_pattern_by_name( 'Stale Pattern' );

		remove_action( 'parse_query', $counter );

		$this->assertSame( 1, $query_count, '更新後はキャッシュが無効化され、再クエリが発生するはず.' );
	}

	public function test_unrelated_post_type_update_does_not_invalidate_cache() {
		self::factory()->post->create(
			array(
				'post_type'   => 'wp_block',
				'post_title'  => 'Untouched Pattern',
				'post_status' => 'publish',
			)
		);

		get_pattern_by_name( 'Untouched Pattern' ); // キャッシュを温める.

		self::factory()->post->create(
			array(
				'post_type'   => 'post',
				'post_title'  => 'Some Unrelated Post',
				'post_status' => 'publish',
			)
		);

		$query_count = 0;
		$counter     = function ( $query ) use ( &$query_count ) {
			if ( 'wp_block' === $query->get( 'post_type' ) ) {
				++$query_count;
			}
		};
		add_action( 'parse_query', $counter );

		get_pattern_by_name( 'Untouched Pattern' );

		remove_action( 'parse_query', $counter );

		$this->assertSame( 0, $query_count, '無関係な投稿タイプの更新ではキャッシュを無効化しないはず.' );
	}
}
