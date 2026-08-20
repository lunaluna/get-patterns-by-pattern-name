=== Get Patterns by Pattern Name ===
Contributors: lunaluna_dev
Tags: block patterns, synced patterns, wp_block, helper, query
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.4.0
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
* `no_found_rows` およびタクソノミー/メタキャッシュの更新省略による軽量なクエリ
* オブジェクトキャッシュによる解決結果のキャッシュ（パターンの保存・更新・削除時に自動的に無効化されます。負の結果＝見つからなかった場合も含めてキャッシュします）
* `gpbpn_strict_title_match` フィルタで、DB の照合順序（大文字小文字・全角半角を区別しない場合がある）に依存しない厳密なタイトル一致を要求可能（既定は無効。後方互換のため opt-in）
* `gpbpn_synced_only` フィルタで、同期パターン（`wp_pattern_sync_status` が未設定/空のもの）のみに限定して取得可能（既定は無効。後方互換のため opt-in）
* wp.org には掲載していないため、GitHub Releases を版元とした自動更新に対応（管理画面での更新通知 → ワンクリック更新）。更新チェックのためのキャッシュ済み HTTP 通信のみ発生し、`l2dwpghul_updater_enabled` フィルタで `false` を返すことで完全に無効化可能

**使い方**

```php
$pattern = get_pattern_by_name( 'ヘッダーバナー' );

if ( $pattern ) {
    echo apply_filters( 'the_content', $pattern->post_content );
}
```

**フック**

外部から挙動を拡張するための 8 つのフック（`gpbpn_` 接頭辞）を提供します。

* `gpbpn_pre_get_pattern`（filter）— クエリ発行前の短絡。外部のキャッシュ層や transient によるクエリ回避に使用できます（1.3.0 以降、内部でもオブジェクトキャッシュを利用しているため、多くの場合はこのフックを使わずに済みます）。
* `gpbpn_query_args`（filter）— `WP_Query` に渡す引数を変更します。`post_status` の拡張や Polylang/WPML 連携などに使用できます。`post_type` と `posts_per_page` の上限は内部で強制されます。
* `gpbpn_pattern_not_found`（action）— パターンが見つからないときに発火します。ロギング・監視用途に使用できます。
* `gpbpn_result`（filter）— 取得結果（`WP_Post|null`）を返す直前に加工・差し替えます。戻り値は `WP_Post|null` に丸められます。
* `gpbpn_duplicate_pattern_found`（action）— 同名の同期パターンが複数存在するときに発火します。監視・通知用途に使用できます。
* `gpbpn_max_name_length`（filter）— パターン名として受け付ける最大長（バイト数）を変更します。既定は 255 です。
* `gpbpn_strict_title_match`（filter）— `true` を返すと、取得した `post_title` が問い合わせ文字列と完全に一致する場合のみパターンを返します（既定 `false`）。DB の照合順序は大文字小文字や全角/半角を区別しない場合があるため、権限の低いユーザーが紛らわしい名前で作成したパターンに差し替えられるのを防げます。
* `gpbpn_synced_only`（filter）— `true` を返すと、`wp_pattern_sync_status` メタが未設定または空の（完全に同期している）パターンのみを取得対象にします（既定 `false`）。

使用例:

```php
// DB の照合順序に依存しない厳密なタイトル一致を要求する.
add_filter( 'gpbpn_strict_title_match', '__return_true' );

// 同期パターンのみを取得対象にする.
add_filter( 'gpbpn_synced_only', '__return_true' );

// post_status を拡張する（publish 以外を返す場合、閲覧権限の確認はプラグイン側で自動的に行われます）.
// 注意: wp_block は capability_type が独自の "block" のため、private 等を返す場合に
// 必要となる権限（read_private_blocks 相当）は既定では管理者ロールにも付与されていません。
// 付与されていない場合、この拡張は管理者であっても常に null を返します（安全側の挙動です）。
// 実際にこの拡張を使う場合は、対象ロールに add_cap() などで明示的に権限を付与してください.
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

= `gpbpn_query_args` で post_status に private 等を含めても null が返ってきます =

`wp_block` 投稿タイプは `capability_type` が独自の "block" であるため、非公開状態を読み取るための権限（`read_private_blocks` 相当）が既定では管理者ロールにも付与されていません。この権限が付与されていない環境では、`post_status` を非公開系に拡張しても、管理者を含め全てのユーザーに対して `null` が返ります（意図的な安全側の挙動で、セキュリティ上の問題ではありません）。実際にこの拡張を利用する場合は、対象ロールに `add_cap()` 等で該当の権限を明示的に付与してください。

= 非同期パターン（unsynced pattern）も取得されますか？ =

既定では、投稿タイプが `wp_block` であれば同期・非同期（`wp_pattern_sync_status` メタが `unsynced` のもの）を問わず取得します。同期パターンのみに限定したい場合は `gpbpn_synced_only` フィルタで `true` を返してください。

= パターンが見つからない場合の戻り値は？ =

`null` を返します。呼び出し側で戻り値を必ず確認してください。

= 大文字小文字や全角/半角だけが違う名前でも一致してしまいます =

`post_title = %s` の比較は DB の照合順序（collation）に依存するため、WordPress 標準の照合順序では大文字小文字を区別せず、環境によっては全角/半角のかなの差も同一視されます。厳密な完全一致のみを許可したい場合は `gpbpn_strict_title_match` フィルタで `true` を返してください。

= キャッシュはどのように無効化されますか？ =

`save_post_wp_block` / `post_updated` / `trashed_post` / `untrashed_post` / `deleted_post` のいずれかが `wp_block` 投稿に対して発火すると、内部キャッシュのバージョン番号を進めることで一括無効化します（無関係な投稿タイプの保存では無効化されません）。データベースを直接操作するなど、これらのフックを経由しない変更を行った場合はキャッシュが更新されない点にご注意ください。

== Changelog ==

= 1.4.0 =
* GitHub Releases を版元とした自動更新に対応。wp.org 未掲載のため `Update URI: false` を設定し、管理画面での更新通知・ワンクリック更新を独自に提供します。`l2dwpghul_updater_enabled` フィルタ（第 2 引数はプラグインスラッグ）で `false` を返すと、更新チェックの HTTP 通信を完全に無効化できます。
* WordPress 7.1 で動作確認済み。`Tested up to` を 7.1 に更新。

= 1.3.0 =
* パフォーマンス: 解決結果をオブジェクトキャッシュに保存するようにし、同じパターン名への繰り返しの呼び出しでクエリの再発行を回避。パターンの保存・更新・削除（`save_post_wp_block` / `post_updated` / `trashed_post` / `untrashed_post` / `deleted_post`）で自動的に無効化されます。見つからなかった結果も含めてキャッシュされ、`gpbpn_query_args` フィルタで変わりうるクエリ条件ごとに別のキャッシュとして扱われます。
* パフォーマンス: `WP_Query` を投稿 ID のみ取得する方式に変更し、投稿オブジェクト自体は `get_post()` 経由で WordPress コア標準の投稿オブジェクトキャッシュを活用するように変更。
* セキュリティ: 新規フィルタ `gpbpn_strict_title_match` を追加。`true` を返すと、DB の照合順序（大文字小文字・全角/半角を区別しない場合がある）に依存しない、厳密なタイトル完全一致のみを許可します（既定 `false`。後方互換のため opt-in）。
* 新規フィルタ `gpbpn_synced_only` を追加。`true` を返すと、`wp_pattern_sync_status` メタが未設定/空の同期パターンのみを取得対象にします（既定 `false`。後方互換のため opt-in）。
* テスト: PHPUnit + wp-env によるテストスイートを追加（入力検証、重複名の解決順序、各フィルタ・アクション、非公開投稿の権限チェック、キャッシュのヒット/無効化などを検証）。

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

= 1.4.0 =
既存の関数シグネチャ・戻り値・既定の挙動は後方互換です。GitHub Releases を版元とした自動更新機構が新たに追加され、更新チェックのために api.github.com への外部 HTTP 通信が発生するようになる点にご注意ください（`l2dwpghul_updater_enabled` フィルタで無効化可能）。

= 1.3.0 =
既存の関数シグネチャ・戻り値・既定の挙動は後方互換です（新しいフィルタは全て既定 off）。内部キャッシュが追加されたため、データベースを直接操作するなど `save_post_wp_block` 等のフックを経由しない方法でパターンを変更している場合、変更が反映されるまでにキャッシュの無効化が必要になる点にご注意ください。

= 1.2.0 =
セキュリティ修正を含みますが、既存の関数シグネチャ・戻り値の型は後方互換です。`gpbpn_query_args` フィルタで `post_status` を拡張している場合、非公開投稿に対する閲覧権限チェックが新たに行われる点にご注意ください。

= 1.1.0 =
フック追加のみで既存の挙動に変更はありません。アップグレード作業は不要です。

= 1.0.0 =
初回リリースです。アップグレード作業は不要です。
