<?php
/**
 * Plugin Name: Get Patterns by Pattern Name
 * Description: 同期パターン（wp_block）を「名前」で取得するヘルパー関数を提供します。
 * Version:     1.0.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Author:      Hiroki Saiki
 * Author URI:  https://profiles.wordpress.org/lunaluna_dev/
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: get-patterns-by-pattern-name
 *
 * @package GetPatternsByPatternName
 */

// WordPress 環境外からの直接アクセスを禁止する.
defined( 'ABSPATH' ) || exit;

/**
 * 名前（post_title）の完全一致で同期パターンを取得します.
 *
 * WP_Query の title パラメータは SQL の WHERE post_title = '...' による
 * 完全一致検索を行うため、部分一致にはなりません. 日本語名にも対応しています.
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
 *                             空文字・null の場合は null を返します.
 * @return WP_Post|null 見つかった場合は WP_Post オブジェクト、見つからなければ null.
 */
function get_pattern_by_name( $pattern_name ) {
	// 空値は早期リターンしてクエリを発行しない.
	if ( empty( $pattern_name ) ) {
		return null;
	}

	// XSS・SQL インジェクション対策としてサニタイズを適用する.
	$pattern_name = sanitize_text_field( $pattern_name );

	$query = new WP_Query(
		array(
			// 同期パターンの投稿タイプを指定する.
			'post_type'              => 'wp_block',
			// post_title の完全一致で絞り込む.
			'title'                  => $pattern_name,
			// 公開済みのみを対象とする.
			'post_status'            => 'publish',
			// 最初の 1 件のみ取得する.
			'posts_per_page'         => 1,
			// COUNT クエリを省略してパフォーマンスを改善する.
			'no_found_rows'          => true,
			// タクソノミーキャッシュの更新を省略する.
			'update_post_term_cache' => false,
			// メタデータキャッシュの更新を省略する.
			'update_post_meta_cache' => false,
		)
	);

	// パターンが見つからない場合は null を返す.
	if ( ! $query->have_posts() ) {
		return null;
	}

	// ID を返す.
	return $query->posts[0];
}
