<?php
/**
 * @package GetPatternsByPatternName
 */

/**
 * get_pattern_by_slug() / get_pattern_by_name_or_slug() の統合テスト.
 */
class GPBPN_Test_Get_Pattern_By_Slug extends WP_UnitTestCase {

	/**
	 * テスト用の同期パターンを作る.
	 *
	 * @param string $title  名前.
	 * @param string $slug   スラッグ(空なら名前から自動で決まる).
	 * @param string $status 投稿ステータス.
	 * @return int 投稿 ID.
	 */
	private function make_pattern( $title, $slug = '', $status = 'publish' ) {
		$args = array(
			'post_type'   => 'wp_block',
			'post_title'  => $title,
			'post_status' => $status,
		);
		if ( '' !== $slug ) {
			$args['post_name'] = $slug;
		}
		return self::factory()->post->create( $args );
	}

	public function test_get_by_slug_finds_ascii_slug() {
		$id = $this->make_pattern( 'Discover Alpine Intro', 'discover-alpine-intro' );

		$this->assertSame( $id, get_pattern_by_slug( 'discover-alpine-intro' )->ID );
		$this->assertNull( get_pattern_by_slug( 'discover-alpine' ), '部分一致では取れない.' );
	}

	public function test_get_by_slug_finds_japanese_slug_in_any_encoding() {
		$id = $this->make_pattern( '会社概要nav', '会社概要nav' );

		$this->assertSame( $id, get_pattern_by_slug( '会社概要nav' )->ID, 'デコード済み' );
		$this->assertSame( $id, get_pattern_by_slug( rawurlencode( '会社概要nav' ) )->ID, 'エンコード済み' );
		$this->assertSame( $id, get_pattern_by_slug( '会社概要NAV' )->ID, '大文字' );
	}

	public function test_slug_still_resolves_after_title_is_changed() {
		$id = $this->make_pattern( 'Old Name', 'old-name' );
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => '新しい名前',
			)
		);

		$this->assertNull( get_pattern_by_name( 'Old Name' ) );
		$this->assertSame( $id, get_pattern_by_slug( 'old-name' )->ID );
		$this->assertSame( $id, get_pattern_by_name_or_slug( 'old-name' )->ID );
	}

	public function test_only_published_patterns_are_returned() {
		$this->make_pattern( 'Draft One', 'draft-one', 'draft' );

		$this->assertNull( get_pattern_by_slug( 'draft-one' ) );
		$this->assertNull( get_pattern_by_name_or_slug( 'draft-one' ) );
	}

	/**
	 * gpbpn_max_name_length は名前だけに効き、スラッグ指定には影響しない(1.5.0).
	 */
	public function test_max_name_length_filter_does_not_affect_slug_lookup() {
		$id = $this->make_pattern( 'Short', 'a-rather-long-slug-for-this-pattern' );

		add_filter(
			'gpbpn_max_name_length',
			function () {
				return 5;
			}
		);

		$this->assertSame( $id, get_pattern_by_slug( 'a-rather-long-slug-for-this-pattern' )->ID );
		// 名前としては上限超過なので、name_or_slug は名前の段階で null を返す(従来の入力チェックと同じ).
		$this->assertNull( get_pattern_by_name_or_slug( 'a-rather-long-slug-for-this-pattern' ) );
	}

	/**
	 * 長すぎる入力は WP の正規化で 200 バイトに切り詰められ、その値で照合される(WP 本体の URL 解決と同じ).
	 */
	public function test_slug_longer_than_200_bytes_is_matched_after_wp_truncation() {
		$prefix = str_repeat( 'あ', 22 ); // エンコード済みで 198 バイト.
		$id     = $this->make_pattern( 'JP Slug', $prefix );

		$this->assertSame( $id, get_pattern_by_slug( $prefix )->ID );
		$this->assertSame( $id, get_pattern_by_slug( $prefix . str_repeat( 'い', 8 ) )->ID );
	}

	public function test_invalid_input_returns_null() {
		$this->assertNull( get_pattern_by_slug( '' ) );
		$this->assertNull( get_pattern_by_slug( array( 'x' ) ) );
		$this->assertNull( get_pattern_by_name_or_slug( '' ) );
		$this->assertNull( get_pattern_by_name_or_slug( 123 ) );
	}

	// ---- get_pattern_by_name_or_slug(): 3-2 の組み合わせ表 ----

	public function test_name_or_slug_row1_name_match_does_not_search_slug() {
		$id = $this->make_pattern( 'Alpha Block', 'alpha-block' );

		$not_found = 0;
		add_action(
			'gpbpn_pattern_not_found',
			function () use ( &$not_found ) {
				++$not_found;
			}
		);
		$slug_queries = 0;
		add_action(
			'parse_query',
			function ( $query ) use ( &$slug_queries ) {
				if ( 'wp_block' === $query->get( 'post_type' ) && '' !== $query->get( 'name' ) ) {
					++$slug_queries;
				}
			}
		);

		$this->assertSame( $id, get_pattern_by_name_or_slug( 'Alpha Block' )->ID );
		$this->assertSame( 0, $slug_queries, '名前で一致したらスラッグは探さない.' );
		$this->assertSame( 0, $not_found );
	}

	public function test_name_or_slug_row2_falls_back_to_slug() {
		$id = $this->make_pattern( 'Renamed Pattern', 'lp-cta-btn' );

		$not_found = 0;
		add_action(
			'gpbpn_pattern_not_found',
			function () use ( &$not_found ) {
				++$not_found;
			}
		);

		$this->assertSame( $id, get_pattern_by_name_or_slug( 'lp-cta-btn' )->ID );
		$this->assertSame( 0, $not_found, 'スラッグで見つかったら not_found は発火しない.' );
	}

	public function test_name_or_slug_row3_not_found_fires_once_with_name_or_slug_field() {
		$log = array();
		add_action(
			'gpbpn_pattern_not_found',
			function ( $name, $field ) use ( &$log ) {
				$log[] = array( $name, $field );
			},
			10,
			2
		);

		$this->assertNull( get_pattern_by_name_or_slug( 'missing-pattern' ) );
		$this->assertSame( array( array( 'missing-pattern', 'name_or_slug' ) ), $log );
	}

	public function test_name_or_slug_row4_name_wins_over_other_patterns_slug() {
		// B のスラッグは `target`. A の名前も `target`.
		$b = $this->make_pattern( 'Pattern B', 'target' );
		$a = $this->make_pattern( 'target', 'something-else' );

		$this->assertNotSame( $a, $b );
		$this->assertSame( $a, get_pattern_by_name_or_slug( 'target' )->ID, '名前を優先する.' );
		$this->assertSame( $b, get_pattern_by_slug( 'target' )->ID );
	}

	public function test_name_or_slug_row5_invalid_input_does_not_fire_not_found() {
		$fired = 0;
		add_action(
			'gpbpn_pattern_not_found',
			function () use ( &$fired ) {
				++$fired;
			}
		);

		get_pattern_by_name_or_slug( '' );
		get_pattern_by_name_or_slug( array( 'x' ) );
		get_pattern_by_name_or_slug( str_repeat( 'a', 256 ) );

		$this->assertSame( 0, $fired );
	}

	// ---- 厳密一致とスラッグの関係 ----

	public function test_name_or_slug_applies_strict_match_by_default_but_get_by_name_does_not() {
		$id = $this->make_pattern( 'Header Banner', 'hb-1' );

		// 既定の get_pattern_by_name() は照合順序で一致する(1.4.0 と同じ).
		$this->assertSame( $id, get_pattern_by_name( 'header banner' )->ID );
		// 新しい関数は厳密一致が既定. 名前は外れ、スラッグ `header-banner` とも一致しない.
		$this->assertNull( get_pattern_by_name_or_slug( 'header banner' ) );
		$this->assertSame( $id, get_pattern_by_name_or_slug( 'Header Banner' )->ID );
	}

	public function test_strict_default_can_be_disabled_for_name_or_slug_only() {
		$id = $this->make_pattern( 'Header Banner', 'hb-2' );

		add_filter(
			'gpbpn_strict_title_match',
			function ( $strict, $name, $field, $caller ) {
				return 'name_or_slug' === $caller ? false : $strict;
			},
			10,
			4
		);

		$this->assertSame( $id, get_pattern_by_name_or_slug( 'header banner' )->ID );
	}

	public function test_case_only_difference_is_caught_by_slug_when_slug_derived_from_name() {
		$id = $this->make_pattern( 'Discover Alpine Intro' ); // スラッグは discover-alpine-intro.

		$this->assertSame( $id, get_pattern_by_name_or_slug( 'discover alpine intro' )->ID );
	}

	// ---- キャッシュ ----

	public function test_second_slug_lookup_issues_no_query_and_update_invalidates() {
		$id = $this->make_pattern( 'Cached', 'cached-slug' );
		get_pattern_by_slug( 'cached-slug' ); // キャッシュを温める.

		$count   = 0;
		$counter = function ( $query ) use ( &$count ) {
			if ( 'wp_block' === $query->get( 'post_type' ) ) {
				++$count;
			}
		};
		add_action( 'parse_query', $counter );

		get_pattern_by_slug( 'cached-slug' );
		$this->assertSame( 0, $count, '2 回目はクエリを発行しない.' );

		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => 'Cached 2',
			)
		);
		get_pattern_by_slug( 'cached-slug' );
		remove_action( 'parse_query', $counter );

		$this->assertSame( 1, $count, '更新後は再クエリする.' );
	}

	public function test_title_and_slug_caches_do_not_mix() {
		// A の名前は `x-one`. B のスラッグも `x-one`.
		$a = $this->make_pattern( 'x-one', 'a-slug' );
		$b = $this->make_pattern( 'B', 'x-one' );

		$this->assertSame( $a, get_pattern_by_name( 'x-one' )->ID );
		$this->assertSame( $b, get_pattern_by_slug( 'x-one' )->ID );
		// 2 回目(キャッシュ経由)も取り違えない.
		$this->assertSame( $a, get_pattern_by_name( 'x-one' )->ID );
		$this->assertSame( $b, get_pattern_by_slug( 'x-one' )->ID );
	}

	// ---- フックの $field ----

	public function test_hooks_receive_field_for_slug_lookup() {
		$this->make_pattern( 'Hooked', 'hooked-slug' );

		$fields = array();
		add_filter(
			'gpbpn_query_args',
			function ( $args, $name, $field ) use ( &$fields ) {
				$fields['query_args'] = $field;
				return $args;
			},
			10,
			3
		);
		add_filter(
			'gpbpn_result',
			function ( $pattern, $name, $field ) use ( &$fields ) {
				$fields['result'] = $field;
				return $pattern;
			},
			10,
			3
		);

		get_pattern_by_slug( 'hooked-slug' );

		$this->assertSame(
			array(
				'query_args' => 'slug',
				'result'     => 'slug',
			),
			$fields
		);
	}
}
