<?php
/**
 * @package GetPatternsByPatternName
 */

/**
 * 管理画面の左カラムの「パターン」メニュー(includes/admin-menu.php)のテスト.
 *
 * admin_menu アクションは発火せず、gpbpn_register_admin_menu() を直接呼ぶ.
 * 他のプラグインの admin_menu コールバックを巻き込まないため.
 */
class GPBPN_Test_Admin_Menu extends WP_UnitTestCase {

	/**
	 * メニューのスラッグ.
	 *
	 * @var string
	 */
	const SLUG = 'edit.php?post_type=wp_block';

	/**
	 * テスト前の $menu.
	 *
	 * @var array
	 */
	private $original_menu;

	/**
	 * 登録解除のテストの前に退避した wp_block の投稿タイプ.
	 *
	 * @var WP_Post_Type|null
	 */
	private $original_post_type;

	/**
	 * $menu を空にして、add_menu_page() を使える状態にする.
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();

		// add_menu_page() は wp-admin/includes/plugin.php にあり、管理画面以外では読み込まれない.
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		global $menu, $admin_page_hooks, $_registered_pages;
		$this->original_menu      = $menu;
		$this->original_post_type = null;

		$menu              = array();
		$admin_page_hooks  = array();
		$_registered_pages = array();
	}

	/**
	 * $menu と wp_block の投稿タイプを元に戻す.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $menu, $wp_post_types;
		$menu = $this->original_menu;

		if ( $this->original_post_type ) {
			$wp_post_types['wp_block'] = $this->original_post_type;
		}

		remove_all_filters( 'gpbpn_show_admin_menu' );
		remove_all_filters( 'gpbpn_admin_menu_args' );

		parent::tear_down();
	}

	/**
	 * $menu から、このプラグインのメニュー項目を探す.
	 *
	 * @return array|null 見つかった項目. なければ null.
	 */
	private function find_menu_item() {
		global $menu;

		foreach ( $menu as $item ) {
			if ( self::SLUG === $item[2] ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * $menu の中での、このメニュー項目の位置(キー)を探す.
	 *
	 * @return string|null 見つかった位置. なければ null.
	 */
	private function find_menu_position() {
		global $menu;

		foreach ( $menu as $position => $item ) {
			if ( self::SLUG === $item[2] ) {
				return (string) $position;
			}
		}

		return null;
	}

	/**
	 * admin_menu アクションに登録されている(表 #1 の前提).
	 *
	 * @return void
	 */
	public function test_hooked_to_admin_menu() {
		$this->assertNotFalse( has_action( 'admin_menu', 'gpbpn_register_admin_menu' ) );
	}

	/**
	 * 表 #1: 既定でメニューが 1 つ追加され、既定値が入る.
	 *
	 * @return void
	 */
	public function test_adds_menu_with_defaults() {
		gpbpn_register_admin_menu();

		$item = $this->find_menu_item();
		$this->assertNotNull( $item );

		$labels = get_post_type_object( 'wp_block' )->labels;
		$this->assertSame( esc_html( $labels->menu_name ), $item[0], 'メニューの文言はコアのラベル.' );
		$this->assertSame( 'edit_others_posts', $item[1] );
		$this->assertSame( $labels->name, $item[3], 'ページタイトルはコアのラベル.' );
		$this->assertSame( 'dashicons-layout', $item[6] );
		$this->assertSame( '21', $this->find_menu_position() );
	}

	/**
	 * 同じスラッグの項目は 1 つだけ.
	 *
	 * @return void
	 */
	public function test_adds_only_one_item() {
		gpbpn_register_admin_menu();

		global $menu;
		$count = 0;
		foreach ( $menu as $item ) {
			if ( self::SLUG === $item[2] ) {
				++$count;
			}
		}

		$this->assertSame( 1, $count );
	}

	/**
	 * 表 #1・#2・#3: 既定の権限は edit_others_posts. 管理者・編集者は満たし、投稿者・寄稿者・購読者は満たさない.
	 *
	 * メニュー項目そのものはユーザーによらず $menu に入り、表示の可否は項目の権限で決まる.
	 * ここでは、項目の権限をユーザーが満たすかを確かめる.
	 *
	 * @return void
	 */
	public function test_default_capability_by_role() {
		gpbpn_register_admin_menu();
		$capability = $this->find_menu_item()[1];

		$expected = array(
			'administrator' => true,
			'editor'        => true,
			'author'        => false,
			'contributor'   => false,
			'subscriber'    => false,
		);

		foreach ( $expected as $role => $can ) {
			$user_id = self::factory()->user->create( array( 'role' => $role ) );
			$this->assertSame( $can, user_can( $user_id, $capability ), $role );
		}
	}

	/**
	 * 表 #4: gpbpn_admin_menu_args で権限を edit_posts に下げると、寄稿者も満たす.
	 *
	 * @return void
	 */
	public function test_capability_can_be_lowered_by_filter() {
		add_filter(
			'gpbpn_admin_menu_args',
			function ( $args ) {
				$args['capability'] = 'edit_posts';
				return $args;
			}
		);

		gpbpn_register_admin_menu();
		$capability = $this->find_menu_item()[1];

		$this->assertSame( 'edit_posts', $capability );
		$contributor = self::factory()->user->create( array( 'role' => 'contributor' ) );
		$this->assertTrue( user_can( $contributor, $capability ) );
	}

	/**
	 * 表 #5: gpbpn_show_admin_menu が false ならメニューを追加しない.
	 *
	 * @return void
	 */
	public function test_show_filter_false_adds_nothing() {
		add_filter( 'gpbpn_show_admin_menu', '__return_false' );

		gpbpn_register_admin_menu();

		$this->assertNull( $this->find_menu_item() );
	}

	/**
	 * 表 #6: wp_block が登録されていなければ、何も追加せずにエラーも出さない.
	 *
	 * @return void
	 */
	public function test_post_type_missing_adds_nothing() {
		global $wp_post_types;

		// wp_block は組み込みの投稿タイプで unregister_post_type() では消せないため、グローバルを直接操作する.
		$this->original_post_type = $wp_post_types['wp_block'];
		unset( $wp_post_types['wp_block'] );

		gpbpn_register_admin_menu();

		$this->assertNull( $this->find_menu_item() );
	}

	/**
	 * 表 #7: フィルターが配列以外を返したら既定値に戻す.
	 *
	 * @return void
	 */
	public function test_non_array_filter_result_falls_back_to_defaults() {
		add_filter( 'gpbpn_admin_menu_args', '__return_false' );

		gpbpn_register_admin_menu();

		$item = $this->find_menu_item();
		$this->assertNotNull( $item );
		$this->assertSame( 'edit_others_posts', $item[1] );
		$this->assertSame( '21', $this->find_menu_position() );
	}

	/**
	 * 表 #7: フィルターが一部のキーだけ返したら、欠けたキーは既定値で補う.
	 *
	 * @return void
	 */
	public function test_partial_filter_result_is_merged_with_defaults() {
		add_filter(
			'gpbpn_admin_menu_args',
			function () {
				return array(
					'menu_title' => 'My Patterns',
					'position'   => 30,
				);
			}
		);

		gpbpn_register_admin_menu();

		$item = $this->find_menu_item();
		$this->assertSame( 'My Patterns', $item[0] );
		$this->assertSame( 'edit_others_posts', $item[1], '欠けた権限は既定値.' );
		$this->assertSame( 'dashicons-layout', $item[6], '欠けたアイコンは既定値.' );
		$this->assertSame( '30', $this->find_menu_position() );
	}

	/**
	 * メニューの文言は HTML をエスケープして渡される(menu-header.php が文言をそのまま出力するため).
	 *
	 * @return void
	 */
	public function test_menu_title_is_escaped() {
		add_filter(
			'gpbpn_admin_menu_args',
			function ( $args ) {
				$args['menu_title'] = '<script>alert(1)</script>';
				return $args;
			}
		);

		gpbpn_register_admin_menu();

		$this->assertStringNotContainsString( '<script>', $this->find_menu_item()[0] );
	}

	/**
	 * メニューのスラッグはフィルターで変えられない.
	 *
	 * @return void
	 */
	public function test_slug_cannot_be_changed_by_filter() {
		add_filter(
			'gpbpn_admin_menu_args',
			function ( $args ) {
				$args['menu_slug'] = 'users.php';
				return $args;
			}
		);

		gpbpn_register_admin_menu();

		$this->assertNotNull( $this->find_menu_item() );
	}
}
