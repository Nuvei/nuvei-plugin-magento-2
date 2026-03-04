/**
 * Nuvei Checkout js component renderer file.
 * Magento needs a renderer file for the layout - this file.
 * And a file to be rendered/loaded in the page - method-renderer/nuveiChecout.js.
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

/**
 * Just check if the plugin is used on the QA site.
 * 
 * @returns boolean
 */
function nuveiIsQaSite() {
    if ('magentoautomation.sccdev-qa.com' === window.location.host
        || 'oldmagentoautomation.gw-4u.com' === window.location.host
    ) {
        return true;
    }
    
    return false;
}

define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component, rendererList) {
        'use strict';
        
        if(0 == window.checkoutConfig.payment[nuveiGetCode()].isActive) {
			return;
		}
        
        // add custom page blocker
        jQuery(function(){
            if (jQuery('body').find('.loading-mask').length < 1) {
                jQuery('body').append('<div class="nuvei-loading-mask" data-role="loader" style="display: none; z-index: 9999; bottom: 0; left: 0; margin: auto; position: fixed; right: 0; top: 0; background: rgba(255,255,255,0.5);"><div class="loader"><img alt="Loading..." src="' + window.checkoutConfig.payment[nuveiGetCode()].loadingImg + '" style="bottom: 0; left: 0; margin: auto; position: fixed; right: 0; top: 0; z-index: 100; max-width: 100%; height: auto; border: 0;"></div></div>');
            }
        });
        
        const usedSdk       = window.checkoutConfig.payment['nuvei'].sdk.toString().toLowerCase();
        let simplyConectUrl = "https://cdn.safecharge.com/safecharge_resources/v1/checkout/simplyConnect.js";

        // set Tag URLs for QA sites
        if (nuveiIsQaSite()) {
            simplyConectUrl = 'https://devmobile.sccdev-qa.com/checkoutNext/simplyConnect.js';
        }
        
        // load SimplyConnect and add it ot a local variable
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
