/**
 * Nuvei Checkout js component.
 *
 * @category Nuvei
 * @package  Nuvei_Checkout
 */

// set here some variables and function common for web and checkout SDKs

/**
 * Get the code of the module.
 * 
 * @returns {String}
 */
function nuveiGetCode() {
	return 'nuvei';
};

function nuveiShowLoader() {
    console.log('nuveiShowLoader');
    
    if (jQuery('body').find('.loading-mask').length > 0) {
        jQuery('body').trigger('processStart');
        return;
    }
    
    jQuery('.nuvei-loading-mask').css('display', 'block');
}

function nuveiHideLoader() {
    console.log('nuveiHideLoader');
    
    if (jQuery('body').find('.loading-mask').length > 0) {
        jQuery('body').trigger('processStop');
        return;
    }
    
    jQuery('.nuvei-loading-mask').css('display', 'none');
}

define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component, rendererList) {
        'use strict';
        
//        console.log(nuveiGetCode());
//        console.log(typeof $);
//        console.log(typeof jQuery);
        
        // add custom page blocker
        jQuery(function(){
            if (jQuery('body').find('.loading-mask').length < 1) {
                jQuery('body').append('<div class="nuvei-loading-mask" data-role="loader" style="display: none; z-index: 9999; bottom: 0; left: 0; margin: auto; position: fixed; right: 0; top: 0; background: rgba(255,255,255,0.5);"><div class="loader"><img alt="Loading..." src="' + window.checkoutConfig.payment[nuveiGetCode()].loadingImg + '" style="bottom: 0; left: 0; margin: auto; position: fixed; right: 0; top: 0; z-index: 100; max-width: 100%; height: auto; border: 0;"></div></div>');
            }
        });
        
        var usedSdk     = window.checkoutConfig.payment['nuvei'].sdk.toString().toLowerCase();
        var fileName    = 'nuvei' + window.checkoutConfig.payment['nuvei'].sdk;
        
        // load WebSDK
        if ('web' == usedSdk) {
            rendererList.push({
                type: 'nuvei',
                component: 'https://cdn.safecharge.com/safecharge_resources/v1/websdk/safecharge.js'
            });
        }
        // load SimplyConnect
        else {
            // Load Nuvei Chekout SDK and add it ot a local variable
            var magentoTmpCheckout	= window.checkout;
            var nuveiCheckoutSdkScr	= document.createElement('script');

            nuveiCheckoutSdkScr.src     = "https://cdn.safecharge.com/safecharge_resources/v1/checkout/checkout.js";
            nuveiCheckoutSdkScr.onload  = function () {
                window.nuveiCheckoutSdk     = checkout;
                window.checkout             = magentoTmpCheckout;
            };
            
            document.head.appendChild(nuveiCheckoutSdkScr);
        }
        
        // load the render file
        rendererList.push({
			type: 'nuvei',
			component: 'Nuvei_Checkout/js/view/payment/method-renderer/' + fileName
		});

        return Component.extend({});
    }
);