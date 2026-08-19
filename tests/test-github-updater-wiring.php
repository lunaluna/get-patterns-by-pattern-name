<?php
/**
 * @package GetPatternsByPatternName
 */

/**
 * GitHub 自動アップデート機構の「配線」を検証するテスト.
 *
 * ライブラリ本体(lib/l2d-updater/tests/)が check_for_update() 等のロジックを
 * 各メソッド直接呼び出しで厚くカバーしているため、ここでは gpbpn 側が
 * ライブラリを正しく呼び出しているか(設定値・フック登録・ヘッダー)と、
 * テスト環境でのキルスイッチが実際に効いているかのみを検証する.
 */
class GPBPN_Test_GitHub_Updater_Wiring extends WP_UnitTestCase {

	public function test_updater_registers_with_correct_config() {
		global $l2dwpghul_updater_registry;

		$config = end( $l2dwpghul_updater_registry['configs'] );

		$this->assertSame( dirname( __DIR__ ) . '/get-patterns-by-pattern-name.php', $config['plugin_file'] );
		$this->assertSame( 'lunaluna/get-patterns-by-pattern-name', $config['github_repo'] );
	}

	public function test_updater_registers_exactly_one_instance() {
		global $l2dwpghul_updater_registry;

		$this->assertCount( 1, $l2dwpghul_updater_registry['configs'] );
	}

	public function test_updater_hooks_are_registered() {
		$this->assertNotFalse( has_filter( 'pre_set_site_transient_update_plugins' ) );
		$this->assertNotFalse( has_filter( 'plugins_api' ) );
		$this->assertNotFalse( has_action( 'upgrader_process_complete' ) );
		$this->assertNotFalse( has_filter( 'upgrader_source_selection' ) );
		$this->assertNotFalse( has_filter( 'upgrader_pre_download' ) );
	}

	public function test_update_uri_header_is_false() {
		$headers = get_file_data(
			dirname( __DIR__ ) . '/get-patterns-by-pattern-name.php',
			array( 'UpdateURI' => 'Update URI' )
		);

		$this->assertSame( 'false', $headers['UpdateURI'] );
	}

	public function test_kill_switch_prevents_http_request_when_update_hook_fires() {
		$requested_github = false;

		$guard = function ( $preempt, $parsed_args, $url ) use ( &$requested_github ) {
			if ( false !== strpos( $url, 'api.github.com' ) ) {
				$requested_github = true;
			}
			return $preempt;
		};
		add_filter( 'pre_http_request', $guard, 10, 3 );

		apply_filters( 'pre_set_site_transient_update_plugins', new stdClass() );

		remove_filter( 'pre_http_request', $guard, 10 );

		$this->assertFalse( $requested_github );
	}
}
