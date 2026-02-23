//const NUVEI_HYVA_JS = true;

let nuveiIsSimplyFormValid    = false;
let nuveiResolvePaymentPromise;
let nuveiPlaceOrder;

/**
 * Here we receive the response from the Checkout SDK Order.
 *
 * @param object resp
 * @returns void
 */
function nuveiAfterSdkResponse(resp) {
    console.log('nuveiAfterSdkResponse()', resp);
   
    let allParams = JSON.parse(
        document.getElementById('nuvei_checkout').dataset.config
    );
   
    // on Approved or Pending
    if (resp.result == 'APPROVED' || resp.result == 'PENDING') {
        console.log('APPROVED');
        nuveiPlaceOrder();
        return;
    }
    
    console.error('Probem with the transacrtion.');

    // error - expired session - reload the page
    if (resp.hasOwnProperty('session_expired') && resp.session_expired) {
        window.location.reload();
        return;
    }

    // a specific Error
    if(resp.hasOwnProperty('status')
        && resp.status == 'ERROR'
        && resp.hasOwnProperty('reason')
        && resp.reason.toLowerCase().search('the currency is not supported') >= 0
    ) {
        errorMsg = resp.reason;
    }

    // on unexpected error
    if(typeof resp == 'undefined'
        || !resp.hasOwnProperty('result')
        || !resp.hasOwnProperty('transactionId')
    ) {
        let errorMsg = allParams.unexpectedErrorMsg;

        if (resp.hasOwnProperty('error') && '' != resp.error) {
            errorMsg = resp.error;
        }
    }

    // on Declined
    if(resp.result == 'DECLINED') {
        if (resp.hasOwnProperty('errorDescription')
            && 'insufficient funds' == resp.errorDescription.toLowerCase()
        ) {
            reject(new Error(allParams.InsufficientFunds));
        }
        
        errorMsg = allParams.TransactionDeclined;
    }

    window.dispatchMessages([{
        type: 'error',
        text: errorMsg
    }]);

    // unblock the pay button
    document.querySelector('.btn-place-order').disabled = false;

    return;
};

/**
 * Checks if the SDK form is valid and set it to a global variable.
 *
 * @param {object} params
 */
function nuveiIsSdkFormValid(params) {
   nuveiIsSimplyFormValid = params.isFormValid;
   
    if (!nuveiIsSimplyFormValid) {
        // unblock the pay button
        document.querySelector('.btn-place-order').disabled = false;
    }
}

function nuveiPrePayment(paymentDetails) {
    console.log('nuveiPrePayment');
    
    return new Promise((resolve, reject) => {
        let resp = nuveiUpdateOrder();
        
        resp === true ? resolve() : reject();
    });
}

/**
 * Use it as last check before complete the Order.
 *
 * @returns {boolean}
 */
function nuveiUpdateOrder() {
    console.log('nuveiUpdateOrder');
    
    nuveiShowLoader();

    let allParams = JSON.parse(
        document.getElementById('nuvei_checkout').dataset.config
    );

    const paramsStr = '?nuveiAction=hyvaPrePayment';
    const xmlhttp   = new XMLHttpRequest();

    xmlhttp.onreadystatechange = function() {
        if (xmlhttp.readyState == XMLHttpRequest.DONE) {   // XMLHttpRequest.DONE == 4
            console.log('Request response', xmlhttp.response);

            if (xmlhttp.status == 200) {
                var resp = JSON.parse(xmlhttp.response);
                
                // success, reaload the Simply Connect because of the change
                if (resp.hasOwnProperty('nuveiCheckoutParams') 
                    && resp.nuveiCheckoutParams.hasOwnProperty('sessionToken') 
                    && '' != resp.nuveiCheckoutParams.sessionToken
                ) {
                    console.log('success', allParams.nuveiCheckoutParams.sessionToken, resp.nuveiCheckoutParams.sessionToken);
                    
                    document.getElementById('nuvei_checkout').dataset.config = xmlhttp.response;
                    nuveiLoadSimplyConnect();
                    return true;
                }

                // error, refresh the page after a message
                if (!alert(allParams.unexpectedErrorMsg)) {
                    window.location.reload();
                }

                return false;
            }

            if (xmlhttp.status == 400) {
                console.error('There was an error.');

                if (!alert(allParams.unexpectedErrorMsg)) {
                    window.location.reload();
                }

                return false;
            }

            console.error('Unexpected response code.');
            
            if (!alert(allParams.unexpectedErrorMsg)) {
//                    nuveiWhenTransDeclined();
            }

            nuveiHideLoader();
            return false;
        }
    };

    xmlhttp.open("GET", allParams.getUpdateOrderUrl + paramsStr, true);
    xmlhttp.send();
}

function nuveiLoadSimplyConnect() {
    // Debug: log the raw JSON string before parsing
    console.log('nuveiLoadSimplyConnect');

    let allParams = JSON.parse(
        document.getElementById('nuvei_checkout').dataset.config
    );

    // add the image to the loader
    document.querySelector('.nuvei-loading-mask img').src = allParams.loadingImg;

    // error - missing sessionToken
    if (!allParams.hasOwnProperty('nuveiCheckoutParams')
        || !allParams.nuveiCheckoutParams.hasOwnProperty('sessionToken')
    ) {
        if (allParams.hasOwnProperty('message')) {
            // TODO - show error message
            console.error(allParams.message);
            return;
        }

        // TODO - show default error message
        console.error('Default error message');
        return;
    }

    let simplyParams = allParams.nuveiCheckoutParams;

    simplyParams.payButton        = 'noButton';
    simplyParams.prePayment       = nuveiPrePayment;
    simplyParams.onFormValidated  = nuveiIsSdkFormValid;
    simplyParams.onResult         = nuveiAfterSdkResponse;

    simplyConnect(simplyParams);
    
    setTimeout(() => {
        nuveiHideLoader();
    }, 200);
}

function nuveiShowLoader() {
    console.log('nuveiShowLoader');
    document.querySelector('.nuvei-loading-mask').style.display = 'block';
}

function nuveiHideLoader() {
    console.log('nuveiHideLoader');
    document.querySelector('.nuvei-loading-mask').style.display = 'none';
}

(() => {
    'use strict';

    const nuveiSimplyConnectScript = document.getElementById('nuvei-simply-connect-script');
    
    let methodCode = '';
    let effectiveBillingCountry = null;

    function calculateEffective(componentMap) {
        const billingComponent = componentMap.billing;
        const shippingComponent = componentMap.shipping;

        const billingAsShipping = billingComponent?.serverMemo?.data?.billingAsShipping ?? true;

        const shippingCountry = shippingComponent?.serverMemo?.data?.address?.country_id ?? null;
        const billingCountry  = billingComponent?.serverMemo?.data?.address?.country_id ?? null;

        return billingAsShipping ? shippingCountry : billingCountry;
    }

    // Wait for Hyva Checkout to be ready
    window.addEventListener('hyva-checkout-loaded', () => {
        console.log('Hyva Checkout is ready, initializing My_App SDK...');

        // You can now access the SDK or register
        // listeners to the Hyva Checkout Events
        // Example: window.myAppSdk.init();
    });

    // event for payment method change
    window.addEventListener('checkout:payment:method-activate', (event) => {
        // Access the selected payment method code
        methodCode = event.detail.method;
        console.log("Payment Method Changed to:", methodCode);

        if ('nuvei' == methodCode && typeof simplyConnect != 'undefined') {
            console.log('nuvei is selected and simplyConnect is loaded');
            nuveiLoadSimplyConnect();
        }

    });

    // if Simply connect is loaded run our logic
    if (nuveiSimplyConnectScript) {
        nuveiSimplyConnectScript.addEventListener('load', () => {
            console.log('simply is loaded...', typeof simplyConnect);

            if ('nuvei' == methodCode) {
                nuveiLoadSimplyConnect();
            }
        });
    }

    // Alpine is ready
    document.addEventListener('alpine:init', () => {
        console.log('Alpine is loaded');
        
    });
    
    // modify the Place Order logic and call Simply Connect before submit the form
    hyvaCheckout.payment.registerMethod({
        code: 'nuvei', 
        method: {
            placeOrder: async function({ fallback }) {
                console.log('Place Order clicked. Waiting for SDK...');
                
                // make the fallback global
                nuveiPlaceOrder = fallback;
                // Trigger the transaction
                simplyConnect.submitPayment();
                
                // unblock the pay button
                setTimeout(() => {
                    if (!nuveiIsSimplyFormValid) {
                        document.querySelector('.btn-place-order').disabled = false;
                    }
                }, 500);
            }
        }
    });


    // listent for total and address changes
    document.addEventListener('magewire:load', () => {
        console.log('magewire:load');
        
        const billingComponent = Magewire.find('checkout.billing-details');
        console.log('billingComponent', billingComponent);
        if (billingComponent) {
            console.log('Address Object:', billingComponent.address);
            console.log('Country ID:', billingComponent.address.country);
            
            // If it's a getter function, calling it might return the object
            const address = (typeof billingComponent.address === 'function') ? billingComponent.address() : billingComponent.address;
            console.log('Country:', address.country);
        }
        
        
        
        
        Magewire.hook('message.processed', (message, component) => {
            // id - price-summary.total-segments
//            if (component.name.includes('total')) {
            if ('price-summary.total-segments' === component.name) {
                console.log('total updated', component);
            }
            
            // id - checkout.billing-details
            if (component.name.includes('billing-details')) {
//            if ('checkout.billing-details' === component.name) {
                console.log('billing updated component', component);
                console.log('billing updated message', message);
//                console.log('activeAddressEntity', component.serverMemo.data.activeAddressEntity);
                
//                if (message?.updateQueue?.payload?.name 
//                    && 'billingAsShipping' === message.updateQueue.payload.name
//                ) {
//                    newBillingAsShipping = message.updateQueue.payload.value;
//                }
//                
//                if (message?.updateQueue?.payload?.name
//                    && 'activeAddressEntity' === message.updateQueue.payload.name
//                ) {
//                    newActiveAddressEntity  = message.updateQueue.payload.value;
//                }
//                
//                console.log('newBillingAsShipping - billingAsShipping', newBillingAsShipping, billingAsShipping );
//                console.log('newActiveAddressEntity - activeAddressEntity', newActiveAddressEntity, activeAddressEntity );
//                
//                billingAsShipping = newBillingAsShipping;
//                activeAddressEntity = newActiveAddressEntity;

                const newCountry = calculateEffective(component);
                
                console.log('newCountry', newCountry, effectiveBillingCountry)

                if (newCountry !== effectiveBillingCountry) {

                    effectiveBillingCountry = newCountry;

                    console.log('Billing държавата реално се смени:', newCountry);

                    // тук reload-ваш каквото ти трябва
                    nuveiUpdateOrder();
                }
                
            }
            
        });
        
        // when Magwite is initialized
        Magewire.hook('component.initialized', (component) => {
            if (component.name.includes('billing-details')) {
                console.log('Начален activeAddressEntity:',
                    component.serverMemo.data.activeAddressEntity
                );

                console.log('Начален billingAsShipping:',
                    component.serverMemo.data.billingAsShipping
                );
        
//                if (component?.serverMemo?.data?.activeAddressEntity) {
//                    activeAddressEntity = component.serverMemo.data.activeAddressEntity;
//                }
//                if (component?.serverMemo?.data?.billingAsShipping) {
//                    billingAsShipping = component.serverMemo.data.billingAsShipping;
//                }

                effectiveBillingCountry = calculateEffective(component);
            }

        });
        
    });

})();