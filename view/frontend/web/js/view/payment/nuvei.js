/**
 * Nuvei Checkout js component.
 *
 * @category Nuvei
 * @package  Nuvei_Checkout
 */
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component, rendererList) {
        'use strict';
        
		// Load Nuvei Chekout SDK and add it ot a local variable
		var magentoTmpCheckout	= window.checkout;
		var nuveiCheckoutSdkScr	= document.createElement('script');
        
		nuveiCheckoutSdkScr.onload = function () {
			window.nuveiCheckoutSdk	= checkout;
			window.checkout			= magentoTmpCheckout;
		};
		
        nuveiCheckoutSdkScr.src = 'https://cdn.safecharge.com/safecharge_resources/v1/checkout/checkout.js';
                
		document.head.appendChild(nuveiCheckoutSdkScr);
		// /Load Nuvei Chekout SDK and add it ot a local variable
		
        rendererList.push({
			type: 'nuvei',
			component: 'Nuvei_Checkout/js/view/payment/method-renderer/nuvei'
		});

        return Component.extend({});
    }
);