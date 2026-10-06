<?php
/**
 * 管理画面の左カラムに、同期パターンの一覧(edit.php?post_type=wp_block)を開くメニューを追加する.
 *
 * 投稿タイプ wp_block は show_in_menu が false のため、コアは左カラムにパターンへの入口を出さない.
 * ブロックテーマの「外観 > エディター」などは edit_theme_options が必要で、編集者には見えない.
 * そこで、編集者がパターンを直接開いて編集できるよう、トップレベルのメニューを 1 つ足す.
 *
 * @package GetPatternsByPatternName
 * @since   1.6.0
 */

// WordPress 環境外からの直接アクセスを禁止する.
defined( 'ABSPATH' ) || exit;

/**
 * 「パターン」メニューを左カラムに追加する.
 *
 * スラッグを edit.php?post_type=wp_block にしているのは、コアが一覧(edit.php)・編集(post.php)・
 * 新規作成(post-new.php)の各画面で $parent_file と $submenu_file をこの値にするため.
 * フィルターで補正しなくても、メニューの現在地のハイライトが合う.
 * wp_block の show_in_menu を true にする方法は、管理バーの「新規」にパターンが増えるため採用しない.
 *
 * add_menu_page() の第 5 引数(コールバック)は渡さない. このメニューは一覧へ直接リンクするだけで、
 * このプラグイン自身は画面を描画しない. サブメニューも作らない(新規作成はサイトエディターで行う).
 *
 * @since 1.6.0
 *
 * @return void
 */
function gpbpn_register_admin_menu() {
	// 他のプラグインなどが wp_block を登録解除していた場合は、何も追加せずに戻る.
	$post_type = get_post_type_object( 'wp_block' );
	if ( ! $post_type ) {
		return;
	}

	/**
	 * 左カラムの「パターン」メニューを表示するかどうかを変更します.
	 *
	 * 値が false のとき、メニューを追加しません. 一覧は URL を直接開けば、これまでどおり使えます.
	 *
	 * @since 1.6.0
	 *
	 * @param bool $show メニューを表示するか. 既定は true.
	 */
	if ( ! apply_filters( 'gpbpn_show_admin_menu', true ) ) {
		return;
	}

	// ラベルはコアのものを使う. 一覧には同期していないパターンも出るため、「同期パターン」とは書かない.
	// コアの翻訳に任せるので、このプラグインに新しい文言は増えない.
	$defaults = array(
		'page_title' => $post_type->labels->name,
		'menu_title' => $post_type->labels->menu_name,
		// 寄稿者・投稿者は他人のパターンを編集できず、入口として役に立たないため、既定は編集者以上.
		'capability' => 'edit_others_posts',
		'icon_url'   => 'dashicons-layout',
		// 「固定ページ」(20)の直後. 位置が衝突した場合は add_menu_page() が小数でずらす.
		'position'   => 21,
	);

	/**
	 * 「パターン」メニューの引数を変更します.
	 *
	 * キーは add_menu_page() の引数に対応します. 配列以外を返した場合や、キーが欠けている場合は
	 * 既定値で補います. メニューのスラッグ(edit.php?post_type=wp_block)は変更できません.
	 *
	 * @since 1.6.0
	 *
	 * @param array $args {
	 *     add_menu_page() に渡す引数.
	 *
	 *     @type string     $page_title ページタイトル. 既定はコアのラベル(「パターン」).
	 *     @type string     $menu_title メニューに出る文言. 既定はコアのラベル(「パターン」).
	 *     @type string     $capability メニューを見るのに必要な権限. 既定は 'edit_others_posts'.
	 *     @type string     $icon_url   アイコン(dashicons のクラス名や URL). 既定は 'dashicons-layout'.
	 *     @type int|float  $position   メニューの位置. 既定は 21.
	 * }
	 */
	$args = apply_filters( 'gpbpn_admin_menu_args', $defaults );

	// フィルターの戻り値を信頼しない. 配列以外なら既定値に戻し、欠けているキーは既定値で補う.
	$args = is_array( $args ) ? array_merge( $defaults, $args ) : $defaults;

	add_menu_page(
		// add_menu_page() は文言をエスケープしない. menu-header.php がメニューの文言をそのまま出力するため、ここで行う.
		esc_html( (string) $args['page_title'] ),
		esc_html( (string) $args['menu_title'] ),
		(string) $args['capability'],
		'edit.php?post_type=wp_block',
		'',
		(string) $args['icon_url'],
		$args['position']
	);
}
add_action( 'admin_menu', 'gpbpn_register_admin_menu' );
