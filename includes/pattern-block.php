<?php
/**
 * ブロック gpbpn/pattern(名前、見つからなければスラッグで同期パターンを引いて表示する)の登録と描画.
 *
 * ブロックには文字列を 1 つだけ指定する. get_pattern_by_name_or_slug() で探し、見つかったパターンは
 * コアの core/block(同期パターンのブロック)に描画を任せる. 投稿 ID を書かないので、
 * 環境ごとに ID がずれてもテンプレートやパターンの中身を変えずに済む.
 *
 * @package GetPatternsByPatternName
 * @since   1.5.0
 */

// WordPress 環境外からの直接アクセスを禁止する.
defined( 'ABSPATH' ) || exit;

/**
 * ブロック gpbpn/pattern を登録する.
 *
 * 描画は block.json の render(render.php)ではなく render_callback で渡す.
 * 名前の付いた関数にしておくと PHPUnit から直接呼べて、静的解析の対象にも入れやすいため.
 * 編集画面の JS(blocks/pattern/index.js)は block.json の editorScript で登録される.
 * その翻訳(wp.i18n の文字列)を読み込めるよう、登録されたスクリプトのハンドルに翻訳を紐づける.
 *
 * @since 1.5.0
 *
 * @return void
 */
function gpbpn_register_pattern_block() {
	$block_type = register_block_type(
		dirname( __DIR__ ) . '/blocks/pattern',
		array(
			'render_callback' => 'gpbpn_render_pattern_block',
		)
	);

	if ( $block_type instanceof WP_Block_Type ) {
		foreach ( $block_type->editor_script_handles as $handle ) {
			wp_set_script_translations( $handle, 'get-patterns-by-pattern-name' );
		}
	}
}
add_action( 'init', 'gpbpn_register_pattern_block' );

/**
 * ブロック gpbpn/pattern を描画する.
 *
 * 1. 属性 pattern(名前またはスラッグ)でパターンを探す. 見つからなければ何も出さない.
 *    (get_pattern_by_name_or_slug() が gpbpn_pattern_not_found を発火する.)
 * 2. 見つかったパターンの投稿 ID を ref に持つ core/block を作って描画する. 外側の要素は足さない.
 *    - 公開済み(publish)でない・パスワード付きのパターンは core/block が空文字を返す.
 *    - 自分自身を含むパターンの無限ループは、core/block の静的変数 $seen_refs が止める.
 *      別の WP_Block から呼んでも同じ静的変数を使うので、このブロック経由でも止まる.
 *
 * 文脈(postId / postType / queryId)は、このブロックの usesContext で受け取ったものを
 * core/block の WP_Block にそのまま渡す. WP_Block::$available_context は protected なので、
 * 中のブロックへ文脈を渡すには、コンストラクタの第 2 引数に渡すしかない.
 *
 * @since 1.5.0
 *
 * @param array<string, mixed> $attributes ブロックの属性.
 * @param string               $content    ブロックの内容(このブロックは内側のブロックを持たないので空).
 * @param WP_Block|null        $block      ブロックのインスタンス.
 * @return string 描画結果. 見つからない・表示できない場合は空文字.
 */
function gpbpn_render_pattern_block( $attributes, $content = '', $block = null ) {
	$value = isset( $attributes['pattern'] ) && is_string( $attributes['pattern'] ) ? $attributes['pattern'] : '';

	if ( '' === trim( $value ) ) {
		return '';
	}

	$pattern = get_pattern_by_name_or_slug( $value );

	if ( ! $pattern instanceof WP_Post ) {
		return '';
	}

	$parsed_block = array(
		'blockName'    => 'core/block',
		'attrs'        => array( 'ref' => $pattern->ID ),
		'innerBlocks'  => array(),
		'innerHTML'    => '',
		'innerContent' => array(),
	);

	$context = $block instanceof WP_Block ? $block->context : array();

	return ( new WP_Block( $parsed_block, $context ) )->render();
}
