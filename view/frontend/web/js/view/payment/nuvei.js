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
        
        var usedSdk     = window.checkoutConfig.payment['nuvei'].sdk.toString().toLowerCase();
        var fileName    = 'nuvei' + window.checkoutConfig.payment['nuvei'].sdk;
        
        // load WebSDK and style
        if ('web' == usedSdk) {
            rendererList.push({
                type: 'nuvei',
                component: 'https://cdn.safecharge.com/safecharge_resources/v1/websdk/safecharge.js'
            });
        }
        
        rendererList.push({
			type: 'nuvei',
			component: 'Nuvei_Checkout/js/view/payment/method-renderer/' + fileName
		});

        return Component.extend({});
    }
);