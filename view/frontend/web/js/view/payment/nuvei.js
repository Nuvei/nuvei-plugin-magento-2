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

/**
 * Common function to show error messages.
 * 
 * @param string msg
 * @returns void
 */
function nuveiShowGeneralError(msg) {
    jQuery('#nuvei_general_error .message div').html(jQuery.mage.__(msg));
    jQuery('#nuvei_general_error').show();
    document.getElementById("nuvei_general_error").scrollIntoView({behavior: 'smooth'});
}

define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (Component, rendererList) {
        'use strict';
        
        // add custom page blocker
        jQuery(function(){
            if (jQuery('body').find('.loading-mask').length < 1) {
                jQuery('body').append('<div class="nuvei-loading-mask" data-role="loader" style="display: none; z-index: 9999; bottom: 0; left: 0; margin: auto; position: fixed; right: 0; top: 0; background: rgba(255,255,255,0.5);"><div class="loader"><img alt="Loading..." src="' + window.checkoutConfig.payment[nuveiGetCode()].loadingImg + '" style="bottom: 0; left: 0; margin: auto; position: fixed; right: 0; top: 0; z-index: 100; max-width: 100%; height: auto; border: 0;"></div></div>');
            }
        });
        
        var usedSdk         = window.checkoutConfig.payment['nuvei'].sdk.toString().toLowerCase();
        var webSdkUrl       = 'https://cdn.safecharge.com/safecharge_resources/v1/websdk/safecharge.js';
        var simplyConectUrl = "https://cdn.safecharge.com/safecharge_resources/v1/checkout/simplyConnect.js";

        // set Tag URLs for QA sites
        try {
            if ('magentoautomation.sccdev-qa.com' === window.location.host
                || 'oldmagentoautomation.gw-4u.com' === window.location.host
            ) {
                webSdkUrl       = 'https://devmobile.sccdev-qa.com/checkoutNext/websdk/safecharge.js';
                simplyConectUrl = 'https://devmobile.sccdev-qa.com/checkoutNext/simplyConnect.js';
            }
        }
        catch (_exception) {
            console.log('Nuvei Error', _exception);
        }
        
        // load WebSDK
        if ('web' == usedSdk) {
            rendererList.push({
                type: 'nuvei',
                component: webSdkUrl
            });
        }
        // load SimplyConnect
        else {
            // Load Nuvei Chekout SDK and add it ot a local variable
            rendererList.push({
                type: 'nuvei',
                component: simplyConectUrl
            });
        }
        
        // load the render file
        rendererList.push({
			type: 'nuvei',
			component: 'Nuvei_Checkout/js/view/payment/method-renderer/nuvei' 
                + window.checkoutConfig.payment['nuvei'].sdk
		});

        return Component.extend({});
    }
);
