( function ( wp ) {
	if ( ! wp || ! wp.element || ! wp.plugins || ! wp.components ) {
		return;
	}

	var blocksCheckout = window.wc && window.wc.blocksCheckout;
	if ( ! blocksCheckout ) {
		return;
	}

	var settings = window.wcSettings && window.wcSettings.getSetting
		? window.wcSettings.getSetting( 'fullmetrix-checkout_data', {} )
		: {};

	if ( ! settings || ! settings.enabled || ! settings.label ) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var registerPlugin = wp.plugins.registerPlugin;
	var CheckboxControl = wp.components.CheckboxControl;
	var ExperimentalOrderMeta = blocksCheckout.ExperimentalOrderMeta;
	var extensionCartUpdate = blocksCheckout.extensionCartUpdate;

	if ( ! ExperimentalOrderMeta ) {
		return;
	}

	function ConsentBlock() {
		var initial = !! settings.defaultChecked;
		var hookState = useState( initial );
		var checked = hookState[ 0 ];
		var setChecked = hookState[ 1 ];

		useEffect( function () {
			if ( typeof extensionCartUpdate === 'function' ) {
				extensionCartUpdate( {
					namespace: 'fullmetrix-checkout',
					data: { consent: checked },
				} );
			}
		}, [ checked ] );

		var labelStyle = {};
		if ( settings.textColor ) {
			labelStyle.color = settings.textColor;
		}

		return el(
			'div',
			{ className: 'fullmetrix-checkout-consent', style: { margin: '12px 0' } },
			el( CheckboxControl, {
				label: settings.label,
				checked: checked,
				onChange: setChecked,
				__nextHasNoMarginBottom: true,
			} )
		);
	}

	function FullmetrixConsentRender() {
		return el(
			ExperimentalOrderMeta,
			{},
			el( ConsentBlock, null )
		);
	}

	registerPlugin( 'fullmetrix-checkout-consent', {
		render: FullmetrixConsentRender,
		scope: 'woocommerce-checkout',
	} );
} )( window.wp );
