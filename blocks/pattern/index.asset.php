<?php
/**
 * ブロック gpbpn/pattern の編集画面スクリプト(index.js)の依存とバージョン.
 *
 * 編集画面のスクリプト(index.js)は手書き・ビルドなしなので、@wordpress/scripts が生成する index.asset.php の代わりに手書きする.
 * block.json の editorScript が `file:./index.js` のとき、WP はこのファイルがあれば依存とバージョンを読む
 * (register_block_script_handle()). 依存を足し忘れると、編集画面で wp.* が未定義になる.
 *
 * @package GetPatternsByPatternName
 * @since   1.5.0
 */

// WordPress 環境外からの直接アクセスを禁止する.
defined( 'ABSPATH' ) || exit;

return array(
	'dependencies' => array(
		'wp-api-fetch',
		'wp-block-editor',
		'wp-blocks',
		'wp-components',
		'wp-core-data',
		'wp-element',
		'wp-i18n',
		'wp-server-side-render',
		'wp-url',
	),
	// index.js を変更するたびに手でバージョンを上げなくて済むよう、更新日時をキャッシュ対策に使う.
	'version'      => (string) filemtime( __DIR__ . '/index.js' ),
);
