//const NUVEI_HYVA_JS = true;

let nuveiIsSimplyFormValid    = false;
let nuveiResolvePaymentPromise;
let nuveiPlaceOrder;
let nuveiCheckoutTranslations;

/**
 * Here we receive the response from the Checkout SDK Order.
 *
 * @param object resp
 * @returns void
 */
function nuveiAfterSdkResponse(resp) {
    console.log('nuveiAfterSdkResponse()', resp);
   
    let errorMsg = nuveiCheckoutTranslations.UnexpectedError;
   
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
        let errorMsg = nuveiCheckoutTranslations.UnexpectedError;

        if (resp.hasOwnProperty('error') && '' != resp.error) {
            errorMsg = resp.error;
        }
    }

    // on Declined
    if(resp.result == 'DECLINED') {
        if (resp.hasOwnProperty('errorDescription')
            && 'insufficient funds' == resp.errorDescription.toLowerCase()
        ) {
            reject(new Error(nuveiCheckoutTranslations.InsufficientFunds));
        }
        
        errorMsg = nuveiCheckoutTranslations.TransactionDeclined;
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

/**
 * Use it as last check before complete the Order.
 *
 * @param {object} paymentDetails
 * @returns {Promise}
 */
function nuveiUpdateOrder(paymentDetails) {
    console.log('nuveiUpdateOrder');

    return new Promise((resolve, reject) => {
        if (!window.nuveiSavedOrderId) {
            alert(window.checkoutConfig.payment[nuveiGetCode()].missingOrderIdMsg)
            reject(new Error(window.checkoutConfig.payment[nuveiGetCode()].missingOrderIdMsg));
            return;
        }

        const paramsStr = '?nuveiAction=nuveiPrePayment&orderId=' + window.nuveiSavedOrderId;
        const xmlhttp   = new XMLHttpRequest();

        xmlhttp.onreadystatechange = function() {
            if (xmlhttp.readyState == XMLHttpRequest.DONE) {   // XMLHttpRequest.DONE == 4
                console.log('Request response', xmlhttp.response);

                if (xmlhttp.status == 200) {
                    var resp = JSON.parse(xmlhttp.response);

                    if (!resp.hasOwnProperty('success') || 0 == resp.success) {
                        reject();

                        if (!alert(window.checkoutConfig.payment[nuveiGetCode()].unexpectedErrorMsg)) {
                            nuveiWhenTransDeclined();
                        }

                        return;
                    }

                    nuveiWaitSdkResponse = true;

                    // if we get new Session Token, update the input
                    if (resp.hasOwnProperty('sessionToken') && '' != resp.sessionToken) {
                        document.getElementById('nuvei_session_token').value = resp.sessionToken;
                    }

                    if (resp.hasOwnProperty('successUrl') && '' != resp.successUrl) {
                        window.nuveiSuccessUrl = resp.successUrl;
                    }

                    resolve();
                    return;
                }

                if (xmlhttp.status == 400) {
                    console.log('There was an error.');
                    reject();

                    if (!alert(window.checkoutConfig.payment[nuveiGetCode()].unexpectedErrorMsg)) {
                        nuveiWhenTransDeclined();
                    }

                    return;
                }

                console.log('Unexpected response code.');
                reject();

                if (!alert(window.checkoutConfig.payment[nuveiGetCode()].unexpectedErrorMsg)) {
                    nuveiWhenTransDeclined();
                }

                return;
            }
        };

        nuveiShowLoader();

        xmlhttp.open("GET", window.checkoutConfig.payment[nuveiGetCode()].getUpdateOrderUrl + paramsStr, true);
        xmlhttp.send();
    });
}

(() => {
    'use strict';

    const nuveiSimplyConnectScript = document.getElementById('nuvei-simply-connect-script');
    
    let methodCode           = '';
    let nuveiWaitSdkResponse = false;
    let simplyParams;

    function nuveiLoadSimplyConnect() {
        let allParams = JSON.parse(
            document.getElementById('nuvei-simply-params').textContent
        );

        nuveiCheckoutTranslations = JSON.parse(
            document.getElementById('nuvei-checkout-translations').textContent
        );

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

        simplyParams = allParams.nuveiCheckoutParams;

        console.log('... and nuvei is selected', simplyParams);

        simplyParams.payButton        = 'noButton';
//        simplyParams.prePayment       = nuveiUpdateOrder;
        simplyParams.onFormValidated  = nuveiIsSdkFormValid;
        simplyParams.onResult         = nuveiAfterSdkResponse;

        simplyConnect(simplyParams);
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

})();