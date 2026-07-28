=== Get Patterns by Pattern Name ===
Contributors: lunaluna_dev
Tags: block patterns, synced patterns, wp_block, helper, query
Requires at least: 6.0
Tested up to: 7.0.2
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPL-2.0+
License URI: https://www.gnu.org/licenses/gpl-2.0.html

同期パターン（wp_block）を「名前」で取得するヘルパー関数を提供します。

== Description ==

`get_pattern_by_name()` というグローバル関数を追加し、同期パターン（`wp_block` カスタム投稿タイプ）をその**名前（post_title）の完全一致**で取得できるようにします。

**主な特徴**

* `WP_Query` の `title` パラメータを使用した完全一致検索（部分一致にはなりません）
* 日本語名のパターンにも対応
* `sanitize_text_field()` による入力のサニタイズ
* `no_found_rows`・キャッシュ無効化オプションによる軽量クエリ

**使い方**

```php
$pattern = get_pattern_by_name( 'ヘッダーバナー' );

if ( $pattern ) {
    echo apply_filters( 'the_content', $pattern->post_content );
}
```

**フック**

外部から挙動を拡張するための 4 つのフック（`gpbpn_` 接頭辞）を提供します。

* `gpbpn_pre_get_pattern`（filter）— クエリ発行前の短絡。オブジェクトキャッシュや transient によるクエリ回避に使用できます。
* `gpbpn_query_args`（filter）— `WP_Query` に渡す引数を変更します。`post_status` の拡張や Polylang/WPML 連携などに使用できます。
* `gpbpn_pattern_not_found`（action）— パターンが見つからないときに発火します。ロギング・監視用途に使用できます。
* `gpbpn_result`（filter）— 取得結果（`WP_Post|null`）を返す直前に加工・差し替えます。

使用例:

```php
// オブジェクトキャッシュで短絡させる.
add_filter( 'gpbpn_pre_get_pattern', function ( $pre, $pattern_name ) {
    $cached = wp_cache_get( 'gpbpn_' . $pattern_name, 'gpbpn' );
    return false !== $cached ? $cached : $pre;
}, 10, 2 );

// post_status を拡張する.
add_filter( 'gpbpn_query_args', function ( $args ) {
    $args['post_status'] = array( 'publish', 'private' );
    return $args;
} );

// 見つからなかった場合にログを残す.
add_action( 'gpbpn_pattern_not_found', function ( $pattern_name ) {
    error_log( sprintf( 'Pattern not found: %s', $pattern_name ) );
} );
```

== Installation ==

1. プラグインの ZIP ファイルをダウンロードします。
2. WordPress 管理画面の「プラグイン」→「新規追加」→「プラグインのアップロード」からインストールします。
3. プラグインを有効化します。
4. テーマやカスタムコードから `get_pattern_by_name( $name )` を呼び出します。

または、`get-patterns-by-pattern-name` フォルダを `/wp-content/plugins/` に直接アップロードし、管理画面から有効化してください。

== Frequently Asked Questions ==

= 「名前」とは何ですか？ =

WordPress 管理画面の「パターン」（または「再利用ブロック」）一覧に表示されるタイトル（`post_title`）です。スラッグではありません。

= 同名のパターンが複数ある場合はどうなりますか？ =

最初に見つかった 1 件（`posts_per_page => 1`）のみ返します。パターン名を一意にしておくことを推奨します。

= パターンが見つからない場合の戻り値は？ =

`null` を返します。呼び出し側で戻り値を必ず確認してください。

== Changelog ==

= 1.1.0 =
* `gpbpn_pre_get_pattern`・`gpbpn_query_args`・`gpbpn_pattern_not_found`・`gpbpn_result` の 4 フックを追加。
* `get_pattern_by_name()` に `function_exists()` ガードを追加し、他プラグイン・テーマとの関数名衝突による fatal error を防止。

= 1.0.0 =
* 初回リリース。`get_pattern_by_name()` 関数を追加。

== Upgrade Notice ==

= 1.1.0 =
フック追加のみで既存の挙動に変更はありません。アップグレード作業は不要です。

= 1.0.0 =
初回リリースです。アップグレード作業は不要です。
