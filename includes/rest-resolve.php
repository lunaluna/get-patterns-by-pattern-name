<?php
/**
 * REST ルート gpbpn/v1/resolve(編集画面用. 指定した文字列がどのパターンに解決されるかを返す).
 *
 * 編集画面の JS で名前・スラッグの照合(DB の照合順序・sanitize_title())を再現すると、PHP の結果とずれる.
 * そこで PHP と同じ経路(get_pattern_by_name_or_slug())で解決した結果を、このルートで JS に返す.
 * ブロックのサイドバーが「どちらで一致したか」「スラッグ」「編集リンク」を表示するのに使う.
 *
 * @package GetPatternsByPatternName
 * @since   1.5.0
 */

// WordPress 環境外からの直接アクセスを禁止する.
defined( 'ABSPATH' ) || exit;

/**
 * REST ルートを登録する.
 *
 * GET /wp-json/gpbpn/v1/resolve?value={名前またはスラッグ}
 *
 * @since 1.5.0
 *
 * @return void
 */
function gpbpn_register_rest_routes() {
	register_rest_route(
		'gpbpn/v1',
		'/resolve',
		array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => 'gpbpn_rest_resolve',
			// wp_block を読める権限(編集者以上)と同じ edit_posts を要求する. ブロックを置けるのは編集画面だけ.
			'permission_callback' => 'gpbpn_rest_resolve_permission',
			'args'                => array(
				'value' => array(
					'description' => 'Pattern name, or slug if no pattern has that name.',
					'type'        => 'string',
					'required'    => true,
					'minLength'   => 1,
				),
			),
		)
	);
}
add_action( 'rest_api_init', 'gpbpn_register_rest_routes' );

/**
 * REST ルート gpbpn/v1/resolve の権限チェック.
 *
 * @since 1.5.0
 *
 * @return bool 編集権限(edit_posts)があれば true.
 */
function gpbpn_rest_resolve_permission() {
	return current_user_can( 'edit_posts' );
}

/**
 * REST ルート gpbpn/v1/resolve の処理.
 *
 * 返す値:
 * - id: パターンの投稿 ID.
 * - title: パターンの名前.
 * - slug: パターンのスラッグ(保存されている値. 日本語は URL エンコード済み. 表示側でデコードする).
 * - matched_by: 'title'(名前で一致)または 'slug'(スラッグで一致).
 * - edit_url: 編集画面の URL. 編集権限が無ければ空文字.
 * - conflict: 名前で一致したが、同じ文字列が別のパターンのスラッグとも一致する場合に { id, title }. 無ければ null.
 *   (ブロックは名前を優先するので、別のパターンのスラッグは使われない. 乗っ取りの注意表示に使う.)
 *
 * 見つからなければ 404.
 *
 * @since 1.5.0
 *
 * @param WP_REST_Request $request リクエスト.
 * @return WP_REST_Response|WP_Error 解決結果. 見つからなければ WP_Error(404).
 */
function gpbpn_rest_resolve( $request ) {
	$value = (string) $request->get_param( 'value' );

	// ブロックの描画と同じ関数で解決する(フックや厳密一致の既定も同じになる).
	$pattern = get_pattern_by_name_or_slug( $value );

	if ( ! $pattern instanceof WP_Post ) {
		return new WP_Error(
			'gpbpn_pattern_not_found',
			__( 'Pattern not found.', 'get-patterns-by-pattern-name' ),
			array( 'status' => 404 )
		);
	}

	// どちらで一致したかは、名前で探した結果(キャッシュ済みで追加クエリは発行されない)と ID を比べて決める.
	// フックを発火させないよう、公開関数ではなく内部関数を使う.
	$by_title   = gpbpn_lookup_pattern( $value, 'title', true, 'name_or_slug' );
	$matched_by = ( $by_title['pattern'] instanceof WP_Post && $by_title['pattern']->ID === $pattern->ID ) ? 'title' : 'slug';

	$conflict = null;
	if ( 'title' === $matched_by ) {
		// 名前で一致したときだけ、同じ文字列が別のパターンのスラッグでもあるかを調べる(編集画面だけで行う追加の 1 クエリ).
		$by_slug = gpbpn_lookup_pattern( $value, 'slug', false, 'slug' );
		if ( $by_slug['pattern'] instanceof WP_Post && $by_slug['pattern']->ID !== $pattern->ID ) {
			$conflict = array(
				'id'    => $by_slug['pattern']->ID,
				'title' => $by_slug['pattern']->post_title,
			);
		}
	}

	return rest_ensure_response(
		array(
			'id'         => $pattern->ID,
			'title'      => $pattern->post_title,
			'slug'       => $pattern->post_name,
			'matched_by' => $matched_by,
			'edit_url'   => current_user_can( 'edit_post', $pattern->ID ) ? admin_url( 'post.php?post=' . $pattern->ID . '&action=edit' ) : '',
			'conflict'   => $conflict,
		)
	);
}
