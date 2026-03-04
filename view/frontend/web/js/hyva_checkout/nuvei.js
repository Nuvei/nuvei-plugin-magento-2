let nuveiLastData               = '';
let nuveiIsSimplyFormValid      = false;
let nuveiIsSimplyLoading        = false;
let nuveiHyvaLastPm             = '';
let nuveiResolvePaymentPromise;
let nuveiPlaceOrder;


function yourCustomJSMethod(conf) {
    console.log(conf);
    alert('test');
}

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
        text: 'Unexpected error.'
    }]);

    // unblock the pay button
    document.querySelector('.btn-place-order').disabled = false;
    nuveiHideLoader();
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

    return nuveiUpdateOrder(true); // we will return the Promise here
}

/**
 * Use it as last check before complete the Order.
 *
 * @param {boolean} isPrepayment
 * @returns Promise
 */
function nuveiUpdateOrder(isPrepayment = false) {
    console.log('nuveiUpdateOrder, isPrepayment', isPrepayment);

    nuveiShowLoader();

    return new Promise((resolve, reject) => {
        if (!document.getElementById('nuvei_checkout')) {
            if (isPrepayment) {
                reject();
            }

            return false;
        }

        let allParams = JSON.parse(
            document.getElementById('nuvei_checkout').dataset.config
        );

        const paramsStr = '?nuveiAction=hyvaPrePayment&isPrepayment=' + isPrepayment;
        const xmlhttp   = new XMLHttpRequest();

        xmlhttp.onreadystatechange = function() {
            if (xmlhttp.readyState == XMLHttpRequest.DONE) {   // XMLHttpRequest.DONE == 4
                console.log('Request response', xmlhttp.response);

                // successful request
                if (xmlhttp.status == 200) {
                    var resp = JSON.parse(xmlhttp.response);

                    // successful response
                    if (resp.hasOwnProperty('nuveiCheckoutParams')
                        && resp.nuveiCheckoutParams.hasOwnProperty('sessionToken')
                        && '' != resp.nuveiCheckoutParams.sessionToken
                    ) {
                        console.log(
                            'success',
                            allParams.nuveiCheckoutParams.sessionToken,
                            resp.nuveiCheckoutParams.sessionToken,
                            isPrepayment
                        );

                        // in case we call this method from SimplyConnect prePayment method
                        // continue with thee transaction
                        if (isPrepayment) {
                            resolve();
                            return true;
                        }

                        // if we just do an order update, reload Simply Connect
                        document.getElementById('nuvei_checkout').dataset.config = xmlhttp.response;
                        resolve(); // just to be clean
                        nuveiLoadSimplyConnect();
                        return true;
                    }
                }

                // error in any other case
                // if there is a message - show it.
                if (resp?.message && '' != resp.message) {
                    window.dispatchMessages([{
                        type: 'error',
                        text: resp.message
                    }]);
                }

                if (isPrepayment) {
                    reject();

                    document.querySelector('.btn-place-order').disabled = false;

                    nuveiHideLoader();
                    return false;
                }

                reject(); // just to be clean

                if (!alert(allParams.unexpectedErrorMsg)) {
                    window.location.reload();
                }

                return false;
            }
        };

        xmlhttp.open("GET", allParams.getUpdateOrderUrl + paramsStr, true);
        xmlhttp.send();
    });
}

function nuveiLoadSimplyConnect() {
    console.log('nuveiLoadSimplyConnect');

    // error
    if (!document.getElementById('nuvei_checkout')) {
        console.log('nuvei_checkout is missing');
        return;
    }

//    if (document.querySelector('#nuvei_checkout #sfc-main')) {
//        console.log('nuvei_checkout is already loaded.');
//        return;
//    }

    // error
    if (!document.getElementById('nuvei_data')) {
        console.log('Nuvei Data is missing, probably Nuvei is not the selected PM.');
        return;
    }

    let nuveiData = document.getElementById('nuvei_data').dataset.config;

    console.log(nuveiData);

    if (nuveiLastData === nuveiData && document.querySelector('#nuvei_checkout #sfc-main')) {
        console.log('nuvei data is same.');
        return;
    }
    
    if (nuveiIsSimplyLoading) {
        console.log('Simply is already loading.');
        return;
    }
    
    nuveiIsSimplyLoading = true;

    nuveiLastData = nuveiData;

    let allParams = JSON.parse(
        document.getElementById('nuvei_data').dataset.config
    );

    // add the image to the loader
    document.querySelector('.nuvei-loading-mask img').src = allParams.loadingImg;

    // error - missing sessionToken
    if (!allParams.hasOwnProperty('nuveiCheckoutParams')
        || !allParams.nuveiCheckoutParams.hasOwnProperty('sessionToken')
    ) {
        if (allParams.hasOwnProperty('message')) {
            console.error(allParams.message);
            nuveiShowGeneralError(allParams.message);
            
            nuveiIsSimplyLoading = false;
            return;
        }

        // TODO - show default error message
        console.error('Default error message');
        nuveiShowGeneralError();
        
        nuveiIsSimplyLoading = false;
        return;
    }

    let simplyParams = allParams.nuveiCheckoutParams;

    console.log('simplyParams', simplyParams);

    simplyParams.renderTo           = '#nuvei_checkout';
    simplyParams.payButton          = 'noButton';
    simplyParams.prePayment         = nuveiPrePayment;
    simplyParams.onFormValidated    = nuveiIsSdkFormValid;
    simplyParams.onResult           = nuveiAfterSdkResponse;

    document.getElementById('nuvei_checkout').style.display = 'block';
    simplyConnect(simplyParams);

    setTimeout(() => {
        nuveiIsSimplyLoading = false;
        nuveiHideLoader();
    }, 200);
}

function nuveiShowLoader() {
    console.log('nuveiShowLoader');

    if (document.querySelector('.nuvei-loading-mask')) {
        document.querySelector('.nuvei-loading-mask').style.display = 'block';
    }
}

function nuveiHideLoader() {
    console.log('nuveiHideLoader');

    if (document.querySelector('.nuvei-loading-mask')) {
        document.querySelector('.nuvei-loading-mask').style.display = 'none';
    }
}

(() => {
    'use strict';

    const nuveiSimplyConnectScript = document.getElementById('nuvei-simply-connect-script');

    // the selected payment method, gw
    let methodCode = '';

    // if Simply connect is loaded run our logic
    if (nuveiSimplyConnectScript) {
        nuveiSimplyConnectScript.addEventListener('load', () => {
            console.log('simply is loaded...', typeof simplyConnect);

            try {
                if ('nuvei' == methodCode) {
                    document.getElementById('nuvei_checkout').style.display = 'block';
                    nuveiLoadSimplyConnect();
                }
            }
            catch(e) {
                console.error(e);
            }
        });
    }
    
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

    // Wait for Hyva Checkout to be ready
//    window.addEventListener('hyva-checkout-loaded', () => {
//        console.log('Hyva Checkout is ready, initializing My_App SDK...');
//
//        // You can now access the SDK or register
//        // listeners to the Hyva Checkout Events
//        // Example: window.myAppSdk.init();
//    });

    // detect when arrived at the Payment page from Shipping
    window.addEventListener('checkout:step:loaded', (event) => {
        const stepName      = event.detail.route; // 'shipping' or 'payment'
        const isNavigation  = event.detail.subsequent; // true if the user clicked 'Next'

        if (stepName === 'payment' && isNavigation) {
            console.log('User just arrived at the Payment page from Shipping.');
            nuveiLoadSimplyConnect();
        }
    });

    // event for payment method change
    window.addEventListener('checkout:payment:method-activate', (event) => {
        // Access the selected payment method code
        nuveiHyvaLastPm = methodCode = event.detail.method;

        console.log("Payment Method Changed to", methodCode);

        try {
            if ('nuvei' == methodCode && typeof simplyConnect != 'undefined') {
                console.log('nuvei is selected and simplyConnect is loaded');
                document.getElementById('nuvei_checkout').style.display = 'block';
            }
            else {
                document.getElementById('nuvei_checkout').style.display = 'none';
            }
        }
        catch(e) {
            console.error(e);
        }
    });
    
    // Alpine is ready
//    document.addEventListener('alpine:init', () => {
//        console.log('Alpine is loaded');
//
//    });

    // listent for total and address changes
    document.addEventListener('magewire:load', () => {
        console.log('magewire:load');

        Magewire.hook('message.processed', (message, component) => {
            // we force this to refresh when the billing address is changed
            if (component.name.includes('checkout.payment.methods')) {
                console.log('checkout.payment.methods re-render finished');
                nuveiLoadSimplyConnect();
                return;
            }

            // on total change
//            if ('price-summary.total-segments' === component.name) {
//                console.log('total-segments');
//                nuveiLoadSimplyConnect();
//                return;
//            }

            // on billing details change
            if (component.name.includes('billing-details')) {
                console.log('billingAsShipping', component.serverMemo.data.billingAsShipping);

                /**
                 * We have the following cases here:
                 * 1. true - the user set the billing to be as shipping address
                 * 2. false - the user unset the checkbox for billing = shipping, but still didn't change it
                 * 3. undefined - the user changed the billing address from the dropdown
                 *
                 * so there is no real change only in case of false
                 */
                if (false !== component?.serverMemo?.data?.billingAsShipping) {
                    // refresh the payment methods on billing address change
                    Magewire.find('checkout.payment.methods')?.$refresh();
//                    nuveiUpdateOrder();
//                    nuveiLoadSimplyConnect();
//                    return;
                }
            }

        });
        
        // when Magwite is initialized
//        Magewire.hook('component.initialized', (component) => {
//            if (component.name.includes('billing-details')) {
//                console.log('first activeAddressEntity:',
//                    component.serverMemo.data.activeAddressEntity
//                );
//            }
//        });

    });

})();
