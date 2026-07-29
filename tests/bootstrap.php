<?php
/**
 * PHPUnit bootstrap file for get-patterns-by-pattern-name.
 *
 * @package GetPatternsByPatternName
 */

// wp-env の tests-cli コンテナ内では WP_TESTS_DIR が設定済み.
// それ以外の環境では composer 経由でインストールした wp-phpunit/wp-phpunit の
// テストスイートを利用する.
$gpbpn_tests_dir = getenv( 'WP_TESTS_DIR' );

if ( ! $gpbpn_tests_dir ) {
	$gpbpn_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
}

if ( ! $gpbpn_tests_dir ) {
	$gpbpn_tests_dir = dirname( __DIR__ ) . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( "{$gpbpn_tests_dir}/includes/functions.php" ) ) {
	// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CLI 実行時のみの診断メッセージであり HTML 出力ではない.
	echo "Could not find {$gpbpn_tests_dir}/includes/functions.php, have you run `composer install` (or `npm run wp-env start`)?" . PHP_EOL;
	exit( 1 );
}

require_once "{$gpbpn_tests_dir}/includes/functions.php";

/**
 * プラグイン本体を読み込む.
 *
 * @return void
 */
function gpbpn_tests_manually_load_plugin() {
	require dirname( __DIR__ ) . '/get-patterns-by-pattern-name.php';
}
tests_add_filter( 'muplugins_loaded', 'gpbpn_tests_manually_load_plugin' );

require "{$gpbpn_tests_dir}/includes/bootstrap.php";
