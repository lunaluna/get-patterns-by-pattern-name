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
* `sanitize_text_field()` による入力のサニタイズ、および文字列以外・極端に長い入力の早期拒否（`gpbpn_max_name_length` フィルタで上限を変更可能。既定 255）
* 同名パターンが複数存在する場合は ID が最も小さい 1 件（最初に作成されたパターン）を返し、重複を `gpbpn_duplicate_pattern_found` アクションで検知可能
* `no_found_rows` およびタクソノミー/メタキャッシュの更新省略による軽量なクエリ（オブジェクトキャッシュ自体を無効化するものではありません）

**使い方**

```php
$pattern = get_pattern_by_name( 'ヘッダーバナー' );

if ( $pattern ) {
    echo apply_filters( 'the_content', $pattern->post_content );
}
```

**フック**

外部から挙動を拡張するための 6 つのフック（`gpbpn_` 接頭辞）を提供します。

* `gpbpn_pre_get_pattern`（filter）— クエリ発行前の短絡。オブジェクトキャッシュや transient によるクエリ回避に使用できます。
* `gpbpn_query_args`（filter）— `WP_Query` に渡す引数を変更します。`post_status` の拡張や Polylang/WPML 連携などに使用できます。`post_type` と `posts_per_page` の上限は内部で強制されます。
* `gpbpn_pattern_not_found`（action）— パターンが見つからないときに発火します。ロギング・監視用途に使用できます。
* `gpbpn_result`（filter）— 取得結果（`WP_Post|null`）を返す直前に加工・差し替えます。戻り値は `WP_Post|null` に丸められます。
* `gpbpn_duplicate_pattern_found`（action）— 同名の同期パターンが複数存在するときに発火します。監視・通知用途に使用できます。
* `gpbpn_max_name_length`（filter）— パターン名として受け付ける最大長（バイト数）を変更します。既定は 255 です。

使用例:

```php
// オブジェクトキャッシュで短絡させる.
add_filter( 'gpbpn_pre_get_pattern', function ( $pre, $pattern_name ) {
    $cached = wp_cache_get( 'gpbpn_' . $pattern_name, 'gpbpn' );
    return false !== $cached ? $cached : $pre;
}, 10, 2 );

// 取得結果をオブジェクトキャッシュに保存する.
add_filter( 'gpbpn_result', function ( $pattern, $pattern_name ) {
    wp_cache_set( 'gpbpn_' . $pattern_name, $pattern, 'gpbpn' );
    return $pattern;
}, 10, 2 );

// post_status を拡張する（publish 以外を返す場合、閲覧権限の確認はプラグイン側で自動的に行われます）.
add_filter( 'gpbpn_query_args', function ( $args ) {
    $args['post_status'] = array( 'publish', 'private' );
    return $args;
} );

// 見つからなかった場合にログを残す（本番環境では WP_DEBUG 時のみ、改行除去・長さ制限を行うこと）.
add_action( 'gpbpn_pattern_not_found', function ( $pattern_name ) {
    if ( ! WP_DEBUG ) {
        return;
    }
    $safe_name = substr( str_replace( array( "\r", "\n" ), ' ', $pattern_name ), 0, 200 );
    error_log( sprintf( 'Pattern not found: %s', $safe_name ) );
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

投稿 ID が最も小さいもの（最初に作成されたパターン）を返します。重複が見つかると `gpbpn_duplicate_pattern_found` アクションが発火するため、監視・通知に利用できます。パターン名は一意にしておくことを推奨します。

= 非同期パターン（unsynced pattern）も取得されますか？ =

はい。現在のバージョンでは、投稿タイプが `wp_block` であれば同期・非同期（`wp_pattern_sync_status` メタが `unsynced` のもの）を問わず取得します。これは既知の制限事項です。同期パターンのみに限定する機能は今後のバージョンで追加予定です。

= パターンが見つからない場合の戻り値は？ =

`null` を返します。呼び出し側で戻り値を必ず確認してください。

== Changelog ==

= 1.2.0 =
* セキュリティ: 同名パターンが複数存在する場合、後から作成されたものが意図せず優先されないよう既定の並び順を ID 昇順に固定。重複は `gpbpn_duplicate_pattern_found` アクションで検知・通知できます。
* セキュリティ: `gpbpn_query_args` フィルタの戻り値を検証し、投稿タイプの固定・取得件数の上限化、および非公開投稿を返す場合の閲覧権限チェック（`current_user_can( 'read_post' )`）を追加。
* セキュリティ: `gpbpn_result` フィルタの戻り値を `WP_Post|null` に丸めるよう修正し、不正な値による fatal error を防止。
* セキュリティ: 入力値の検証を強化し、文字列以外や極端に長い入力を早期に拒否（新規フィルタ `gpbpn_max_name_length`、既定 255）。
* readme: `error_log()` のサンプルコードを、ログインジェクション対策（改行除去・長さ制限・`WP_DEBUG` 条件）を行った安全な形に修正。
* readme: 「最初に見つかった 1 件」という記述を「ID が最も小さい 1 件」に訂正し、非同期パターンも取得対象に含まれる既知の制限事項を明記。

= 1.1.0 =
* `gpbpn_pre_get_pattern`・`gpbpn_query_args`・`gpbpn_pattern_not_found`・`gpbpn_result` の 4 フックを追加。
* `get_pattern_by_name()` に `function_exists()` ガードを追加し、他プラグイン・テーマとの関数名衝突による fatal error を防止。

= 1.0.0 =
* 初回リリース。`get_pattern_by_name()` 関数を追加。

== Upgrade Notice ==

= 1.2.0 =
セキュリティ修正を含みますが、既存の関数シグネチャ・戻り値の型は後方互換です。`gpbpn_query_args` フィルタで `post_status` を拡張している場合、非公開投稿に対する閲覧権限チェックが新たに行われる点にご注意ください。

= 1.1.0 =
フック追加のみで既存の挙動に変更はありません。アップグレード作業は不要です。

= 1.0.0 =
初回リリースです。アップグレード作業は不要です。
