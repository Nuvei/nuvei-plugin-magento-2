/**
 * Nuvei Checkout js component renderer file.
 * Magento needs a renderer file for the layout - this file.
 * And a file to be rendered/loaded in the page - method-renderer/nuveiCheckout.js.
 *
 * @category Nuvei
 * @package  Nuvei_Checkout
 */

/**
 * Get the code of the module.
 * 
 * @returns {String}
 */
function nuveiGetCode() {
	return 'nuvei';
};

define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component, rendererList) {
        'use strict';
        
        if (0 == window.checkoutConfig.payment[nuveiGetCode()].isActive) {
            return;
        }
        
        // add custom page blocker
        jQuery(function(){
            if (jQuery('body').find('.loading-mask').length < 1) {
                jQuery('body').append('<div class="nuvei-loading-mask" data-role="loader" style="display: none; z-index: 9999; bottom: 0; left: 0; margin: auto; position: fixed; right: 0; top: 0; background: rgba(255,255,255,0.5);"><div class="loader"><img alt="Loading..." src="' + window.checkoutConfig.payment[nuveiGetCode()].loadingImg + '" style="bottom: 0; left: 0; margin: auto; position: fixed; right: 0; top: 0; z-index: 100; max-width: 100%; height: auto; border: 0;"></div></div>');
            }
        });
        
        let simplyConectUrl = window.checkoutConfig.payment[nuveiGetCode()].simplyUrl;

        // load SimplyConnect SDK
        rendererList.push({
            type: 'nuvei',
            component: simplyConectUrl
        });
        
        // load the render file
        rendererList.push({
			type: 'nuvei',
			component: 'Nuvei_Checkout/js/view/payment/method-renderer/nuveiCheckout'
		});

        return Component.extend({});
    }
);
