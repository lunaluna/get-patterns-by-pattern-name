/**
 * ブロック gpbpn/pattern の編集画面(手書き・ビルドなし).
 *
 * - 未指定のとき: プレースホルダーに「同期パターンの選択欄」を出す. 選ぶと名前が属性 pattern に入る.
 * - 指定したとき: ServerSideRender でプレビューする. 見つからなければ「見つかりません」を出す.
 * - サイドバー: 選択欄、pattern を直接書き換える入力欄、解決結果(名前・スラッグ・どちらで一致したか)、
 *   スラッグ指定への切り替えボタン、編集リンク、別のパターンのスラッグと衝突している場合の注意.
 *
 * 名前・スラッグの照合は PHP と結果がずれないよう、REST ルート gpbpn/v1/resolve に任せる.
 * JSX は使わない(ビルドしないため). 依存は index.asset.php に書く.
 */
( function ( wp ) {
	var registerBlockType = wp.blocks.registerBlockType;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var components = wp.components;
	var Placeholder = components.Placeholder;
	var ComboboxControl = components.ComboboxControl;
	var TextControl = components.TextControl;
	var PanelBody = components.PanelBody;
	var Button = components.Button;
	var Notice = components.Notice;
	var Spinner = components.Spinner;
	var ExternalLink = components.ExternalLink;
	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var useMemo = wp.element.useMemo;
	var __ = wp.i18n.__;
	var sprintf = wp.i18n.sprintf;
	var useEntityRecords = wp.coreData.useEntityRecords;
	var apiFetch = wp.apiFetch;
	var addQueryArgs = wp.url.addQueryArgs;
	var ServerSideRender = wp.serverSideRender;

	/**
	 * 名前の入力が止まってから resolve を呼ぶまでの待ち時間(ミリ秒).
	 * 未実測の値. 1 文字ごとに REST を呼ばないための目安.
	 */
	var RESOLVE_DELAY = 400;

	/**
	 * URL エンコード済みのスラッグを、読める形にデコードする. 不正な並びのときは元の値を返す.
	 */
	function decodeSlug( slug ) {
		try {
			return decodeURIComponent( slug );
		} catch ( e ) {
			return slug;
		}
	}

	/**
	 * 同期パターンの選択肢(名前)を取得する.
	 * 選択欄だけ同期パターンに絞る(描画は絞らない). REST の wp_pattern_sync_status が 'unsynced' のものは除く.
	 */
	function usePatternOptions() {
		var result = useEntityRecords( 'postType', 'wp_block', { per_page: -1, status: 'publish' } );
		var records = result.records;

		return useMemo(
			function () {
				var seen = {};
				var options = [];

				( records || [] ).forEach( function ( record ) {
					var name = record.title && ( record.title.raw || record.title.rendered );
					if ( 'unsynced' === record.wp_pattern_sync_status || ! name || seen[ name ] ) {
						return;
					}
					seen[ name ] = true;
					options.push( { value: name, label: name } );
				} );

				return options;
			},
			[ records ]
		);
	}

	/**
	 * 指定した文字列を PHP と同じ経路で解決する(REST: gpbpn/v1/resolve).
	 *
	 * @return {{status: string, data: ?Object, message: ?string}} status は idle / loading / found / notfound / error.
	 */
	function useResolvedPattern( value ) {
		var state = useState( { status: 'idle', data: null, message: null } );
		var resolved = state[ 0 ];
		var setResolved = state[ 1 ];

		useEffect(
			function () {
				var trimmed = value.trim();
				var cancelled = false;

				if ( '' === trimmed ) {
					setResolved( { status: 'idle', data: null, message: null } );
					return undefined;
				}

				setResolved( { status: 'loading', data: null, message: null } );

				var timer = setTimeout( function () {
					apiFetch( { path: addQueryArgs( '/gpbpn/v1/resolve', { value: trimmed } ) } )
						.then( function ( data ) {
							if ( ! cancelled ) {
								setResolved( { status: 'found', data: data, message: null } );
							}
						} )
						.catch( function ( error ) {
							if ( cancelled ) {
								return;
							}
							if ( error && 'gpbpn_pattern_not_found' === error.code ) {
								setResolved( { status: 'notfound', data: null, message: null } );
							} else {
								setResolved( { status: 'error', data: null, message: error && error.message } );
							}
						} );
				}, RESOLVE_DELAY );

				// 入力が変わった・ブロックが消えたときは、前の問い合わせの結果を捨てる.
				return function () {
					cancelled = true;
					clearTimeout( timer );
				};
			},
			[ value ]
		);

		return resolved;
	}

	/**
	 * パターンの選択欄. 文字で絞り込める. 選ぶと名前が pattern に入る.
	 */
	function PatternPicker( props ) {
		var options = usePatternOptions();

		return el( ComboboxControl, {
			__next40pxDefaultSize: true,
			__nextHasNoMarginBottom: true,
			label: __( 'Synced pattern', 'get-patterns-by-pattern-name' ),
			help: __( 'Choose a synced pattern. Its name is saved in the block.', 'get-patterns-by-pattern-name' ),
			options: options,
			value: props.value,
			onChange: function ( newValue ) {
				props.onChange( newValue || '' );
			},
		} );
	}

	/**
	 * サイドバーの解決結果(名前・スラッグ・どちらで一致したか)と、関連する操作.
	 */
	function ResolvedInfo( props ) {
		var resolved = props.resolved;
		var data = resolved.data;

		if ( 'loading' === resolved.status ) {
			return el( Spinner );
		}

		if ( 'notfound' === resolved.status ) {
			return el(
				Notice,
				{ status: 'warning', isDismissible: false },
				sprintf(
					/* translators: %s: the text entered in the block. */
					__( 'No published pattern has the name or slug "%s".', 'get-patterns-by-pattern-name' ),
					props.value
				)
			);
		}

		if ( 'error' === resolved.status ) {
			return el( Notice, { status: 'error', isDismissible: false }, resolved.message || __( 'Could not look up the pattern.', 'get-patterns-by-pattern-name' ) );
		}

		if ( 'found' !== resolved.status || ! data ) {
			return null;
		}

		var slug = decodeSlug( data.slug );

		return el(
			Fragment,
			null,
			el( 'p', null, el( 'strong', null, __( 'Name:', 'get-patterns-by-pattern-name' ) ), ' ', data.title ),
			el( 'p', null, el( 'strong', null, __( 'Slug:', 'get-patterns-by-pattern-name' ) ), ' ', slug ),
			el(
				'p',
				null,
				'title' === data.matched_by
					? __( 'Matched by name.', 'get-patterns-by-pattern-name' )
					: __( 'Matched by slug.', 'get-patterns-by-pattern-name' )
			),
			'title' === data.matched_by &&
				el(
					Fragment,
					null,
					el(
						Button,
						{
							variant: 'secondary',
							onClick: function () {
								props.onChange( slug );
							},
						},
						__( 'Use the slug instead', 'get-patterns-by-pattern-name' )
					),
					el(
						'p',
						{ className: 'components-base-control__help' },
						__( 'If the pattern is renamed later, a block that uses the slug keeps working.', 'get-patterns-by-pattern-name' )
					)
				),
			data.conflict &&
				el(
					Notice,
					{ status: 'warning', isDismissible: false },
					sprintf(
						/* translators: %s: name of another pattern. */
						__( 'Another pattern ("%s") has this text as its slug. The pattern whose name matches is shown.', 'get-patterns-by-pattern-name' ),
						data.conflict.title
					)
				),
			data.edit_url &&
				el( 'p', null, el( ExternalLink, { href: data.edit_url }, __( 'Edit this pattern', 'get-patterns-by-pattern-name' ) ) )
		);
	}

	/**
	 * ブロックの編集画面.
	 */
	function Edit( props ) {
		var attributes = props.attributes;
		var setAttributes = props.setAttributes;
		var value = 'string' === typeof attributes.pattern ? attributes.pattern : '';
		var blockProps = useBlockProps();
		var resolved = useResolvedPattern( value );

		function setPattern( newValue ) {
			setAttributes( { pattern: newValue } );
		}

		// 本体: 未指定は選択欄、指定済みはプレビュー(または見つからない旨).
		var body;
		if ( '' === value.trim() ) {
			body = el(
				Placeholder,
				{ icon: 'controls-repeat', label: __( 'Pattern by name or slug', 'get-patterns-by-pattern-name' ) },
				el( 'div', { style: { width: '100%' } }, el( PatternPicker, { value: '', onChange: setPattern } ) )
			);
		} else if ( 'found' === resolved.status ) {
			body = el( ServerSideRender, {
				block: 'gpbpn/pattern',
				attributes: attributes,
				// 見つかったのに空のとき(パスワード付きなど).
				EmptyResponsePlaceholder: function () {
					return el( Placeholder, { icon: 'controls-repeat', label: __( 'This pattern cannot be displayed.', 'get-patterns-by-pattern-name' ) } );
				},
			} );
		} else if ( 'notfound' === resolved.status ) {
			body = el(
				Placeholder,
				{ icon: 'warning', label: __( 'Pattern not found', 'get-patterns-by-pattern-name' ) },
				sprintf(
					/* translators: %s: the text entered in the block. */
					__( 'Pattern not found: %s', 'get-patterns-by-pattern-name' ),
					value
				)
			);
		} else if ( 'error' === resolved.status ) {
			body = el( Placeholder, { icon: 'warning', label: __( 'Could not look up the pattern.', 'get-patterns-by-pattern-name' ) }, resolved.message );
		} else {
			body = el( Placeholder, { icon: 'controls-repeat', label: __( 'Pattern by name or slug', 'get-patterns-by-pattern-name' ) }, el( Spinner ) );
		}

		return el(
			Fragment,
			null,
			el(
				InspectorControls,
				null,
				el(
					PanelBody,
					{ title: __( 'Pattern', 'get-patterns-by-pattern-name' ) },
					el( PatternPicker, { value: value, onChange: setPattern } ),
					el( TextControl, {
						__next40pxDefaultSize: true,
						__nextHasNoMarginBottom: true,
						label: __( 'Name or slug', 'get-patterns-by-pattern-name' ),
						help: __( 'The name is tried first. If no pattern has that name, the slug is tried.', 'get-patterns-by-pattern-name' ),
						value: value,
						onChange: setPattern,
					} ),
					el( ResolvedInfo, { resolved: resolved, value: value, onChange: setPattern } )
				)
			),
			el( 'div', blockProps, body )
		);
	}

	// 設定(タイトル・属性など)は PHP 側の block.json から渡される. ここでは edit と save だけを渡す.
	// 動的ブロックなので save は null(中身は保存せず、フロントで PHP が描画する).
	registerBlockType( 'gpbpn/pattern', {
		edit: Edit,
		save: function () {
			return null;
		},
	} );
}( window.wp ) );
