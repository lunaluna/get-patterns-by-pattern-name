<?php
/**
 * @package GetPatternsByPatternName
 */

/**
 * REST ルート gpbpn/v1/resolve のテスト.
 */
class GPBPN_Test_Rest_Resolve extends WP_UnitTestCase {

	/**
	 * REST サーバーを作り直す(テスト間でルートが残らないようにする).
	 *
	 * @return void
	 */
	public function set_up() {
		parent::set_up();
		global $wp_rest_server;
		$wp_rest_server = null;
	}

	/**
	 * REST サーバーを破棄する.
	 *
	 * @return void
	 */
	public function tear_down() {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * テスト用の同期パターンを作る.
	 *
	 * @param string $title 名前.
	 * @param string $slug  スラッグ(空なら名前から自動で決まる).
	 * @return int 投稿 ID.
	 */
	private function make_pattern( $title, $slug = '' ) {
		$args = array(
			'post_type'   => 'wp_block',
			'post_title'  => $title,
			'post_status' => 'publish',
		);
		if ( '' !== $slug ) {
			$args['post_name'] = $slug;
		}
		return self::factory()->post->create( $args );
	}

	/**
	 * ルートにリクエストを送る.
	 *
	 * @param string|null $value value パラメータ.
	 * @return WP_REST_Response レスポンス.
	 */
	private function request( $value ) {
		$request = new WP_REST_Request( 'GET', '/gpbpn/v1/resolve' );
		if ( null !== $value ) {
			$request->set_param( 'value', $value );
		}
		return rest_do_request( $request );
	}

	public function test_route_is_registered() {
		$routes = rest_get_server()->get_routes( 'gpbpn/v1' );

		$this->assertArrayHasKey( '/gpbpn/v1/resolve', $routes );
	}

	public function test_logged_out_user_gets_401() {
		wp_set_current_user( 0 );

		$this->assertSame( 401, $this->request( 'anything' )->get_status() );
	}

	public function test_subscriber_gets_403_and_editor_gets_200() {
		$this->make_pattern( 'Resolvable' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, $this->request( 'Resolvable' )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->assertSame( 200, $this->request( 'Resolvable' )->get_status() );
	}

	public function test_missing_value_parameter_is_rejected() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$this->assertSame( 400, $this->request( null )->get_status() );
	}

	public function test_matched_by_title() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$id = $this->make_pattern( 'Hero Block', 'hero-block' );

		$response = $this->request( 'Hero Block' );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $id, $data['id'] );
		$this->assertSame( 'Hero Block', $data['title'] );
		$this->assertSame( 'hero-block', $data['slug'] );
		$this->assertSame( 'title', $data['matched_by'] );
		$this->assertNull( $data['conflict'] );
	}

	public function test_matched_by_slug_after_rename() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$id = $this->make_pattern( '改名後の名前', 'old-slug' );

		$data = $this->request( 'old-slug' )->get_data();

		$this->assertSame( $id, $data['id'] );
		$this->assertSame( 'slug', $data['matched_by'] );
		$this->assertNull( $data['conflict'] );
	}

	public function test_conflict_is_reported_when_name_matches_another_patterns_slug() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$other = $this->make_pattern( 'Pattern B', 'target' );
		$named = $this->make_pattern( 'target', 'something-else' );

		$data = $this->request( 'target' )->get_data();

		$this->assertSame( $named, $data['id'], '名前が優先される.' );
		$this->assertSame( 'title', $data['matched_by'] );
		$this->assertSame(
			array(
				'id'    => $other,
				'title' => 'Pattern B',
			),
			$data['conflict']
		);
	}

	public function test_returns_404_when_not_found() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );

		$response = $this->request( 'no-such-pattern' );

		$this->assertSame( 404, $response->get_status() );
		$this->assertSame( 'gpbpn_pattern_not_found', $response->as_error()->get_error_code() );
	}

	public function test_edit_url_points_to_post_edit_screen() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$id = $this->make_pattern( 'Editable' );

		$data = $this->request( 'Editable' )->get_data();

		$this->assertSame( admin_url( 'post.php?post=' . $id . '&action=edit' ), $data['edit_url'] );
	}

	public function test_japanese_slug_is_returned_as_stored_and_resolvable_when_decoded() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$id = $this->make_pattern( '別の名前', '会社概要nav' );

		$data = $this->request( '会社概要nav' )->get_data();

		$this->assertSame( $id, $data['id'] );
		$this->assertSame( 'slug', $data['matched_by'] );
		$this->assertSame( '会社概要nav', rawurldecode( $data['slug'] ) );
	}
}
