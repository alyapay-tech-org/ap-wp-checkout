( function( wcBlocksRegistry, wcSettings, wpElement ) {
    'use strict';

    var settings = wcSettings.getSetting( 'alyapay_data', {} );
    var label    = wpElement.createElement(
        'span',
        null,
        settings.title || 'AlyaPay'
    );

    var AlyaPayComponent = function() {
        var w = settings.widget || {};
        var ref = wpElement.useRef( null );

        wpElement.useEffect( function() {
            if ( !ref.current || !w.enabled ) return;
            var el = document.createElement( 'alya-placement' );
            el.setAttribute( 'key', 'checkout' );
            el.setAttribute( 'price', w.cart_total || '0.00' );
            el.setAttribute( 'currency', w.currency || 'MAD' );
            el.setAttribute( 'lang', w.lang || 'en' );
            el.setAttribute( 'installments', '4' );
            el.setAttribute( 'theme', w.theme || 'light' );
            el.setAttribute( 'variant', w.variant || 'default' );
            el.setAttribute( 'detail', w.detail || 'modal' );
            el.setAttribute( 'logo-position', w.logo_position || 'right' );
            ref.current.appendChild( el );
        }, [] );

        if ( !w.enabled ) {
            return wpElement.createElement( 'p', null, settings.description || '' );
        }

        return wpElement.createElement( 'div', {
            ref: ref,
            className: 'alyapay-block-payment'
        } );
    };

    wcBlocksRegistry.registerPaymentMethod( {
        name:           'alyapay',
        label:          label,
        content:        wpElement.createElement( AlyaPayComponent, null ),
        edit:           wpElement.createElement( AlyaPayComponent, null ),
        canMakePayment: function( cartData ) {
            var min = settings.amount_min || 500;
            var max = settings.amount_max || 15000;
            try {
                var totals     = cartData.cartTotals;
                var minorUnit  = parseInt( totals.currency_minor_unit, 10 ) || 2;
                var total      = parseInt( totals.total_price, 10 ) / Math.pow( 10, minorUnit );
                return total >= min && total <= max;
            } catch ( e ) {
                return true;
            }
        },
        ariaLabel:      settings.title || 'AlyaPay',
        supports: {
            features: settings.supports || [ 'products' ],
        },
    } );

} )(
    window.wc.wcBlocksRegistry,
    window.wc.wcSettings,
    window.wp.element
);
