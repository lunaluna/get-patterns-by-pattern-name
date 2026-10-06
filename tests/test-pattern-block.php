<?php
/**
 * @package GetPatternsByPatternName
 */

/**
 * ブロック gpbpn/pattern(サーバー側の登録と描画)のテスト.
 */
class GPBPN_Test_Pattern_Block extends WP_UnitTestCase {

	/**
	 * 文脈を表示するテスト用ブロックを登録する.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		register_block_type(
			'gpbpn-test/context',
			array(
				'uses_context'    => array( 'postId', 'postType', 'queryId' ),
				'render_callback' => function ( $attributes, $content, $block ) {
					$parts = array();
					foreach ( array( 'postId', 'postType', 'queryId' ) as $key ) {
						$parts[] = $key . '=' . ( isset( $block->context[ $key ] ) ? $block->context[ $key ] : '-' );
					}
					return '[ctx ' . implode( ' ', $parts ) . ']';
				},
			)
		);
	}

	/**
	 * テスト用ブロックの登録を解除する.
	 *
	 * @return void
	 */
	public function tear_down() {
		unregister_block_type( 'gpbpn-test/context' );
		parent::tear_down();
	}

	/**
	 * テスト用の同期パターンを作る.
	 *
	 * @param string $title   名前.
	 * @param string $content 中身(ブロックマークアップ).
	 * @param array  $extra   wp_insert_post に足す引数.
	 * @return int 投稿 ID.
	 */
	private function make_pattern( $title, $content, array $extra = array() ) {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_type'    => 'wp_block',
					'post_title'   => $title,
					'post_content' => $content,
					'post_status'  => 'publish',
				),
				$extra
			)
		);
	}

	/**
	 * gpbpn/pattern のブロックマークアップを作る.
	 *
	 * @param string $value 属性 pattern の値.
	 * @return string ブロックマークアップ.
	 */
	private function block_markup( $value ) {
		return '<!-- wp:gpbpn/pattern ' . wp_json_encode( array( 'pattern' => $value ), JSON_UNESCAPED_UNICODE ) . ' /-->';
	}

	public function test_block_is_registered_with_expected_definition() {
		$type = WP_Block_Type_Registry::get_instance()->get_registered( 'gpbpn/pattern' );

		$this->assertInstanceOf( WP_Block_Type::class, $type );
		$this->assertSame( 'string', $type->attributes['pattern']['type'] );
		$this->assertSame( '', $type->attributes['pattern']['default'] );
		$this->assertSame( array( 'postId', 'postType', 'queryId' ), $type->uses_context );
		$this->assertTrue( $type->is_dynamic() );
	}

	public function test_renders_pattern_found_by_name() {
		$this->make_pattern( 'Hero Block', '<!-- wp:paragraph --><p>HERO-BODY</p><!-- /wp:paragraph -->' );

		$html = do_blocks( $this->block_markup( 'Hero Block' ) );

		$this->assertStringContainsString( 'HERO-BODY', $html );
	}

	public function test_renders_pattern_found_by_slug_after_title_change() {
		$id = $this->make_pattern(
			'Original Name',
			'<!-- wp:paragraph --><p>SLUG-BODY</p><!-- /wp:paragraph -->',
			array( 'post_name' => 'original-name' )
		);
		wp_update_post(
			array(
				'ID'         => $id,
				'post_title' => '改名後',
			)
		);

		$html = do_blocks( $this->block_markup( 'original-name' ) );

		$this->assertStringContainsString( 'SLUG-BODY', $html );
	}

	public function test_renders_nothing_and_fires_not_found_when_missing() {
		$fired = array();
		add_action(
			'gpbpn_pattern_not_found',
			function ( $name, $field ) use ( &$fired ) {
				$fired[] = array( $name, $field );
			},
			10,
			2
		);

		$this->assertSame( '', do_blocks( $this->block_markup( 'no-such-pattern' ) ) );
		$this->assertSame( array( array( 'no-such-pattern', 'name_or_slug' ) ), $fired );
	}

	public function test_renders_nothing_without_querying_when_pattern_is_empty() {
		$count   = 0;
		$counter = function ( $query ) use ( &$count ) {
			if ( 'wp_block' === $query->get( 'post_type' ) ) {
				++$count;
			}
		};
		add_action( 'parse_query', $counter );

		$this->assertSame( '', do_blocks( '<!-- wp:gpbpn/pattern /-->' ) );
		$this->assertSame( '', do_blocks( $this->block_markup( '   ' ) ) );

		remove_action( 'parse_query', $counter );
		$this->assertSame( 0, $count );
	}

	public function test_renders_nothing_for_draft_and_password_protected_patterns() {
		$this->make_pattern( 'Draft Pattern', '<!-- wp:paragraph --><p>DRAFT</p><!-- /wp:paragraph -->', array( 'post_status' => 'draft' ) );
		$this->make_pattern( 'Locked Pattern', '<!-- wp:paragraph --><p>LOCKED</p><!-- /wp:paragraph -->', array( 'post_password' => 'secret' ) );

		$this->assertSame( '', do_blocks( $this->block_markup( 'Draft Pattern' ) ) );
		// パスワード付きは公開済みなので見つかるが、core/block が空文字を返す.
		$this->assertSame( '', do_blocks( $this->block_markup( 'Locked Pattern' ) ) );
	}

	public function test_does_not_add_wrapper_element() {
		$this->make_pattern( 'Plain', '<!-- wp:paragraph --><p>ONLY</p><!-- /wp:paragraph -->' );

		$html = trim( do_blocks( $this->block_markup( 'Plain' ) ) );

		// 出力は中身の段落だけで始まり終わる(コアが段落に付ける wp-block-paragraph クラスは中身側の話).
		$this->assertStringStartsWith( '<p', $html );
		$this->assertStringEndsWith( '</p>', $html );
		$this->assertSame( 1, substr_count( $html, '<p' ) );
	}

	public function test_self_referencing_pattern_does_not_loop() {
		$id = $this->make_pattern( 'Self Ref', '' );
		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => '<!-- wp:paragraph --><p>ONCE</p><!-- /wp:paragraph -->' . $this->block_markup( 'Self Ref' ),
			)
		);

		$html = do_blocks( $this->block_markup( 'Self Ref' ) );

		// 無限ループにならず、中身は 1 回だけ出る.
		$this->assertSame( 1, substr_count( $html, 'ONCE' ) );
	}

	public function test_passes_query_loop_context_to_inner_blocks() {
		$this->make_pattern( 'Ctx Pattern', '<!-- wp:gpbpn-test/context /-->' );

		$parsed = parse_blocks( $this->block_markup( 'Ctx Pattern' ) );
		$block  = new WP_Block(
			$parsed[0],
			array(
				'postId'   => 123,
				'postType' => 'post',
				'queryId'  => 7,
			)
		);

		$this->assertSame( '[ctx postId=123 postType=post queryId=7]', trim( $block->render() ) );
	}

	public function test_pattern_update_is_reflected_in_next_render() {
		$id = $this->make_pattern( 'Live Pattern', '<!-- wp:paragraph --><p>BEFORE</p><!-- /wp:paragraph -->' );
		$this->assertStringContainsString( 'BEFORE', do_blocks( $this->block_markup( 'Live Pattern' ) ) );

		wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => '<!-- wp:paragraph --><p>AFTER</p><!-- /wp:paragraph -->',
			)
		);

		$html = do_blocks( $this->block_markup( 'Live Pattern' ) );
		$this->assertStringContainsString( 'AFTER', $html );
		$this->assertStringNotContainsString( 'BEFORE', $html );
	}

	public function test_render_function_ignores_non_string_attribute() {
		$this->assertSame( '', gpbpn_render_pattern_block( array( 'pattern' => array( 'x' ) ) ) );
		$this->assertSame( '', gpbpn_render_pattern_block( array() ) );
	}
}
