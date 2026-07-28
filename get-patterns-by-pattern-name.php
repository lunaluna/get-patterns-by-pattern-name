<?php
/**
 * Plugin Name:       Get Patterns by Pattern Name
 * Plugin URI:        https://github.com/lunaluna/get-patterns-by-pattern-name
 * Description:       同期パターン（wp_block）を「名前」で取得するヘルパー関数を提供します。
 * Version:           1.2.0
 * Requires at least: 6.0
 * Tested up to:      7.0.2
 * Requires PHP:      7.4
 * Author:            lunaluna_dev
 * Author URI:        https://profiles.wordpress.org/lunaluna_dev/
 * Update URI:        https://github.com/lunaluna/get-patterns-by-pattern-name
 * License:           GPL-2.0+
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       get-patterns-by-pattern-name
 *
 * @package GetPatternsByPatternName
 */

// WordPress 環境外からの直接アクセスを禁止する.
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'get_pattern_by_name' ) ) :
	/**
	 * 名前（post_title）の完全一致で同期パターンを取得します.
	 *
	 * WP_Query の title パラメータは SQL の WHERE post_title = '...' による
	 * 完全一致検索を行うため、部分一致にはなりません. 日本語名にも対応しています.
	 *
	 * 同名のパターンが複数存在する場合は、投稿 ID が最も小さいもの（＝最初に
	 * 作成されたパターン）を返します. WP_Query の既定 orderby（post_date DESC）
	 * のままでは、後から同名で作成されたパターンに結果が乗っ取られてしまうため
	 * です. 重複を検知した場合は `gpbpn_duplicate_pattern_found` アクションが
	 * 発火します（監視・通知用）.
	 *
	 * クエリには以下のパフォーマンス最適化を適用しています.
	 *
	 * - no_found_rows: SQL_CALC_FOUND_ROWS を省略し、ページネーション用 COUNT を回避します.
	 * - update_post_term_cache: タクソノミーキャッシュの更新を省略します.
	 * - update_post_meta_cache: メタデータキャッシュの更新を省略します.
	 *
	 * 使用例:
	 *
	 *     $pattern = get_pattern_by_name( 'ヘッダーバナー' );
	 *     if ( $pattern ) {
	 *         echo apply_filters( 'the_content', $pattern->post_content );
	 *     }
	 *
	 * @since 1.0.0
	 *
	 * @param string $pattern_name 取得したいパターンの名前（post_title）.
	 *                             文字列以外・空文字・最大長超過の場合は null を返します.
	 * @return WP_Post|null 見つかった場合は WP_Post オブジェクト、見つからなければ null.
	 */
	function get_pattern_by_name( $pattern_name ) {
		// 文字列以外（配列・オブジェクト等）を渡された場合は早期リターンしてクエリを発行しない.
		if ( ! is_string( $pattern_name ) ) {
			return null;
		}

		$pattern_name = trim( $pattern_name );

		if ( '' === $pattern_name ) {
			return null;
		}

		/**
		 * パターン名の最大長（バイト数）を変更します.
		 *
		 * @since 1.2.0
		 *
		 * @param int $max_length 最大長. 0 以下を指定すると上限チェックを無効化します. 既定 255.
		 */
		$max_length = (int) apply_filters( 'gpbpn_max_name_length', 255 );
		if ( $max_length > 0 && strlen( $pattern_name ) > $max_length ) {
			return null;
		}

		// 多層防御としてサニタイズを適用する(SQL 自体は WP_Query 内部の prepare で保護される).
		$pattern_name = sanitize_text_field( $pattern_name );

		/**
		 * クエリ発行前に結果を短絡させます.
		 *
		 * Null 以外が返された場合、クエリを発行せずその値を返します.
		 * オブジェクトキャッシュや transient によるクエリ回避に使用できます.
		 *
		 * @since 1.1.0
		 *
		 * @param WP_Post|null $pre          短絡値. デフォルト null(短絡しない).
		 * @param string       $pattern_name サニタイズ済みのパターン名.
		 */
		$pre = apply_filters( 'gpbpn_pre_get_pattern', null, $pattern_name );
		if ( null !== $pre ) {
			return $pre instanceof WP_Post ? $pre : null;
		}

		$defaults = array(
			// 同期パターンの投稿タイプを指定する.
			'post_type'              => 'wp_block',
			// post_title の完全一致で絞り込む.
			'title'                  => $pattern_name,
			// 公開済みのみを対象とする.
			'post_status'            => 'publish',
			// 同名パターンが複数あっても、常に最初に作成された 1 件を返す.
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			// 重複検知のため、上限を 2 件にして取得する.
			'posts_per_page'         => 2,
			// COUNT クエリを省略してパフォーマンスを改善する.
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			// タクソノミーキャッシュの更新を省略する.
			'update_post_term_cache' => false,
			// メタデータキャッシュの更新を省略する.
			'update_post_meta_cache' => false,
		);

		/**
		 * WP_Query に渡す引数を変更します.
		 *
		 * @since 1.1.0
		 *
		 * @param array  $args         WP_Query の引数.
		 * @param string $pattern_name サニタイズ済みのパターン名.
		 */
		$args = apply_filters( 'gpbpn_query_args', $defaults, $pattern_name );

		// フィルタの戻り値を信頼しない. 配列以外が返ってきたら既定値にフォールバックする.
		if ( ! is_array( $args ) ) {
			$args = $defaults;
		}

		// 投稿タイプは同期パターン固定とし、外部フィルタからの上書きを許可しない.
		$args['post_type']      = 'wp_block';
		$args['posts_per_page'] = min( 2, max( 1, (int) ( isset( $args['posts_per_page'] ) ? $args['posts_per_page'] : 2 ) ) );

		$query = new WP_Query( $args );
		$posts = $query->posts;

		if ( count( $posts ) > 1 ) {
			/**
			 * 同名の同期パターンが複数存在するときに発火します(監視・通知用).
			 *
			 * @since 1.2.0
			 *
			 * @param string $pattern_name サニタイズ済みのパターン名.
			 * @param int[]  $post_ids     重複しているパターンの投稿 ID の配列.
			 */
			do_action( 'gpbpn_duplicate_pattern_found', $pattern_name, wp_list_pluck( $posts, 'ID' ) );
		}

		$pattern = isset( $posts[0] ) ? $posts[0] : null;

		// post_status に publish 以外を含める拡張を行った場合は、閲覧権限を必ず確認する.
		if ( $pattern instanceof WP_Post && 'publish' !== $pattern->post_status
			&& ! current_user_can( 'read_post', $pattern->ID ) ) {
			$pattern = null;
		}

		if ( null === $pattern ) {
			/**
			 * パターンが見つからなかったときに発火します(ロギング・監視用).
			 *
			 * @since 1.1.0
			 *
			 * @param string $pattern_name サニタイズ済みのパターン名.
			 */
			do_action( 'gpbpn_pattern_not_found', $pattern_name );
		}

		/**
		 * 取得結果を返す直前に加工・差し替えます.
		 *
		 * @since 1.1.0
		 *
		 * @param WP_Post|null $pattern      取得結果.
		 * @param string       $pattern_name サニタイズ済みのパターン名.
		 */
		$result = apply_filters( 'gpbpn_result', $pattern, $pattern_name );

		// フィルタの戻り値を信頼しない. WP_Post 以外が返ってきたら null に丸める.
		return $result instanceof WP_Post ? $result : null;
	}
endif;
