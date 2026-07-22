/**
 * Nuvei Payments js component.
 *
 * @category Nuvei
 * @package  Nuvei_Checkout
 */

const nuveiWallets = ['ppp_ApplePay', 'ppp_GooglePay', 'ppp_Paze', 'apmgw_Venmo', 'apmgw_VenmoPP'];

var nuveiAgreementsConfig		= window.checkoutConfig ? window.checkoutConfig.checkoutAgreements : {};
// Set it true when prePayment check is resolved, and set it false in the nuveiAfterSdkResponse().
var nuveiWaitSdkResponse		= false;
var nuveiIsSimplyFormValid		= false;
var nuveiSelectedPaymentMethod	= '';

/**
 * Checks if the SDK form is valid and set it to a global variable.
 * 
 * @param {object} params
 */
function nuveiIsSdkFormValid(params) {
    nuveiIsSimplyFormValid = params.isFormValid;
}

/**
 * Use it as last check before complete the Order.
 * 
 * @param {object} paymentDetails
 * @returns {Promise}
 */
function nuveiPrePayment(paymentDetails) {
    console.log('nuveiPrePayment');
    
    return new Promise((resolve, reject) => {
        // For wallets the SDK button is used directly — Magento order creation was
        // never triggered by the default Place Order button, so we do it here first.
        if (nuveiWallets.indexOf(nuveiSelectedPaymentMethod) >= 0 && !window.nuveiSavedOrderId) {
            window.nuveiCreateMagentoOrder()
                .then(function(orderId) {
                    window.nuveiSavedOrderId = orderId;
                    nuveiUpdateOrderRequest(resolve, reject);
                })
                .catch(reject);
            return;
        }

        if (!window.nuveiSavedOrderId) {
            alert(window.checkoutConfig.payment[nuveiGetCode()].missingOrderIdMsg)
            reject(new Error(window.checkoutConfig.payment[nuveiGetCode()].missingOrderIdMsg));
            return;
        }

        nuveiUpdateOrderRequest(resolve, reject);
    });
}

function nuveiUpdateOrderRequest(resolve, reject) {
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
}

/**
 * Here we receive the response from the Checkout SDK Order.
 * 
 * @param {object} resp
 * @returns {void|Boolean}
 */
function nuveiAfterSdkResponse(resp) {
//	console.log('nuveiAfterSdkResponse()', resp);
    
    nuveiWaitSdkResponse = false;

    // expired session
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
        if(!alert(resp.reason)) {
            nuveiWhenTransDeclined();
            return;
        }
    }
	
    // on unexpected error
	if(typeof resp == 'undefined'
		|| !resp.hasOwnProperty('result')
		|| !resp.hasOwnProperty('transactionId')
	) {
        var errorMsg = jQuery.mage.__('Unexpected error, please try again later!');
        
        if (resp.hasOwnProperty('error') && '' != resp.error) {
            errorMsg = resp.error;
        }

		if(!alert(errorMsg)) {
            nuveiWhenTransDeclined();
			return;
		}
	}

	// on Declined
	if(resp.result == 'DECLINED') {
        if (resp.hasOwnProperty('errorDescription')
            && 'insufficient funds' == resp.errorDescription.toLowerCase()
        ) {
            if(!alert(jQuery.mage.__('You have Insufficient funds, please go back and remove some of the items in your shopping cart, or use another card.'))
            ) {
                nuveiWhenTransDeclined();
                return;
            }
        }
        
        nuveiWhenTransDeclined();
        return;
	}

    // on Approved or Pending
    if (resp.result == 'APPROVED' || resp.result == 'PENDING') {
        var checkoutForm = jQuery('#nuvei_default_pay_btn').closest('form');
		
		console.log(resp.transactionId);
        
        document.getElementById('nuvei_transaction_id').value = resp.transactionId;
        
        checkoutForm.attr('action', window.checkoutConfig.payment[nuveiGetCode()].checkoutFormAction);
        checkoutForm.attr('method', 'POST');
        
        // there must be nuveiSuccessUrl
        if (window.nuveiSuccessUrl) {
            console.log(window.nuveiSuccessUrl);
            
            window.location = window.nuveiSuccessUrl;
            return;
        }
        
        // submit the form
        checkoutForm.submit();
        return;
    }

    nuveiWhenTransDeclined();
    return;
};

function nuveiWhenTransDeclined() {
    console.log('nuveiWhenTransDeclined');
    
    const paramsStr = '?nuveiAction=transactionDeclined&nuveiSavedOrderId=' + window.nuveiSavedOrderId;
    const xmlhttp   = new XMLHttpRequest();

    xmlhttp.onreadystatechange = function() {
        if (xmlhttp.readyState == XMLHttpRequest.DONE) {   // XMLHttpRequest.DONE == 4
            console.log('Request response', xmlhttp.response);

            if (xmlhttp.status == 200) {
                const resp = JSON.parse(xmlhttp.response);

                if (!resp.hasOwnProperty('success') || 0 == resp.success) {
                    window.location = window.checkoutConfig.payment[nuveiGetCode()].cartUrl;
                    return;
                }

                // refresh the minicart
                require([
                    'Magento_Customer/js/customer-data',
                    'domReady!'
                ], function (customerData) {
                    let sections = ['cart'];

                    customerData.initStorage();
                    customerData.invalidate(sections);
                    customerData.reload(sections, true);
                });

                // then redirect to the cart\
				console.log
                window.location = window.checkoutConfig.payment[nuveiGetCode()].cartUrl;
                return;
            }

            if (xmlhttp.status == 400) {
                console.log('There was an error.');
                window.location = window.checkoutConfig.payment[nuveiGetCode()].cartUrl;
                return;
            }

            console.log('Unexpected response code.');
            window.location = window.checkoutConfig.payment[nuveiGetCode()].cartUrl;
            return;
        }
    };

    xmlhttp.open("GET", window.checkoutConfig.payment[nuveiGetCode()].getUpdateOrderUrl + paramsStr, true);
    xmlhttp.send();
}

function nuveiPmChange(params) {
    console.log(params.paymentMethodName);
    
    nuveiSelectedPaymentMethod = params.paymentMethodName;

    if (nuveiWallets.indexOf(nuveiSelectedPaymentMethod) >= 0) {
        nuveiIsSimplyFormValid = true;
        
        jQuery('#nuvei_default_pay_btn').hide();
    }
    else {
        nuveiIsSimplyFormValid = false;
        
        jQuery('#nuvei_default_pay_btn').show();
    }
}

// when the SDK Pay button was clicked and the script wait for a reponse, try to prevent user leave the page.
window.addEventListener('beforeunload', function(e) {
    if (nuveiWaitSdkResponse) {
        e.preventDefault();
        e.returnValue = ''; // for Chrome
    }
});

define(
    [
        'jquery',
        'Magento_Payment/js/view/payment/cc-form',
        'ko',
        'Magento_Checkout/js/model/quote',
        'mage/translate',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/model/payment/additional-validators',
        'Magento_Checkout/js/model/error-processor',
        'Magento_Customer/js/customer-data'
    ],
    function(
        $,
        Component,
        ko,
        quote,
        mage,
        placeOrderAction, 
        additionalValidators, 
        errorProcessor,
        customerData
    ) {
        'use strict';

		if(0 == window.checkoutConfig.payment[nuveiGetCode()].isActive) {
			return;
		}

        var self = null;
        
        return Component.extend({
            defaults: {
                template: 'Nuvei_Checkout/payment/nuveiCheckout',
                chosenApmMethod: '',
                countryId: ''
            },
            
            orderFullName: '',
            
            checkoutSdkParams: {},
			
            initObservable: function() {
                self = this;
				
                self._super()
                    .observe([
                        'chosenApmMethod',
                        'countryId'
                    ]);
                   
                // subscribe for few events
				try {
                    // we use this condition, because the amount in SimpyConnect is used only for the Pay button.
                    if('amountButton' == window.checkoutConfig.payment[nuveiGetCode()]['nuveiCheckoutParams'].payButton
                        && typeof quote.totals != 'undefined'
                    ) {
                        quote.totals.subscribe(self.scTotalsChange, this, 'change');
                    }
                    
                    if(typeof quote.billingAddress != 'undefined') {
                        quote.billingAddress.subscribe(self.scBillingAddrChange, this, 'change');
                    }
				}
				catch(_error) {
					console.error(_error);
				}

                // Expose order creation so it can be called both from the default
                // Place Order button and programmatically (e.g. wallet prePayment).
                window.nuveiCreateMagentoOrder = function() {
					console.log('nuveiCreateMagentoOrder');
					
                    return new Promise(function(resolve, reject) {
                        if (!self.validate() || !additionalValidators.validate()) {
                            reject(new Error('Validation failed'));
                            return;
                        }

                        placeOrderAction(self.getData(), self.messageContainer)
                            .done(function(orderId) {
                                if (isNaN(orderId)) {
                                    self.messageContainer.addErrorMessage({
                                        message: jQuery.mage.__('There was an issue placing the order. Please try again.')
                                    });
                                    reject(new Error('Invalid order ID'));
                                    return;
                                }

                                resolve(orderId);
                            })
                            .fail(function(response) {
                                errorProcessor.process(response, self.messageContainer);
                                reject(new Error('Order placement failed'));
                            });
                    });
                };
                
                return self;
            },
            
            context: function() {
                return self;
            },
            
            getCode: function() {
                return nuveiGetCode();
            },

			getSessionToken: function() {
                let paymentMethod   = quote.paymentMethod();
                let shippingMethod  = quote.shippingMethod();
                
                self.writeLog('getSessionToken', paymentMethod);
                
                // Check for payment method
                if (null == paymentMethod
                    || !paymentMethod
                    || ( paymentMethod.hasOwnProperty('method') 
                        && "nuvei" !== paymentMethod.method )
                    || 0 < $("#nuvei_checkout").html().length
                ) {
                    console.log(
                        'getSessionToken abort process.', 
                        {
                            'payment method': paymentMethod,
                            '#nuvei_checkout length': $("#nuvei_checkout").html().length
                        }
                    );
            
                    return;
                }
                
                // Check for shipping method
                if (!quote.isVirtual()) {
                    if (null == shippingMethod
                        || !shippingMethod
                        || !shippingMethod.hasOwnProperty('method_code') 
                    ) {
                        nuveiShowGeneralError(jQuery.mage.__('Please, select a Shipping method!'));
                
                        console.log('shippingMethod is empty.', shippingMethod);
                        return;
                    }
                    else {
                        jQuery('#nuvei_general_error').hide();
                    }
                }
                
                // Cart with mixed products
                if(window.checkoutConfig.payment[nuveiGetCode()].isPaymentPlan
                    && quote.getItems().length > 1
                ) {
                    nuveiShowGeneralError(jQuery.mage.__('You can not combine a Product with Nuvei Payment with another product. To continue, please remove some of the Product in your Cart!'));
                    return;
                }
                
                ///////////////////////////////////
                
                nuveiShowLoader();
                
                var xmlhttp = new XMLHttpRequest();
                
                xmlhttp.onreadystatechange = function() {
                    console.log('xmlhttp', xmlhttp);
                    
                    if (xmlhttp.readyState == XMLHttpRequest.DONE) {   // XMLHttpRequest.DONE == 4
                        if (xmlhttp.status == 200) {
                            var resp = JSON.parse(xmlhttp.response);
                            console.log('status 200', resp);
                            
                            // error, show message
                            if(!resp.hasOwnProperty('sessionToken') || '' == resp.sessionToken) {
                                if (resp.hasOwnProperty('outOfStock') && 1 == resp.outOfStock) {
                                    window.location = window.checkoutConfig.payment[nuveiGetCode()].cartUrl;
                                    return;
                                }
                                
                                alert(jQuery.mage.
                                    __('Missing mandatory payment details. Please reload the page and try again!'));

                                nuveiHideLoader();
                                return;
                            }
                            
                            self.nuveiCollectSdkParams();
                            self.checkoutSdkParams.sessionToken = resp.sessionToken;
                            self.checkoutSdkParams.amount       = self.checkoutSdkParams.amount.toString();
                            
                            $('#nuvei_session_token').val(resp.sessionToken);
                            
                            self.loadSdk();
                            return;
                        }

                        if (xmlhttp.status == 400) {
                            console.log('nuveiLoadCheckout update order faild');

                            nuveiHideLoader();
                            return;
                        }
                    }
                };
                
                console.log(typeof $, $)
                
                xmlhttp.open(
                    "GET", 
                    window.checkoutConfig.payment[nuveiGetCode()].getUpdateOrderUrl + '?nuveiAction=getSessionToken', 
                    true
                );
                xmlhttp.send();
			},
            
            nuveiCollectSdkParams: function() {
                console.log('nuveiCollectSdkParams');
                
                self.checkoutSdkParams = JSON.parse(JSON.stringify(
                    window.checkoutConfig.payment[nuveiGetCode()].nuveiCheckoutParams
                ));
                
                // check the billing country
                if(quote.billingAddress()
                    && quote.billingAddress().hasOwnProperty('countryId')
                    && quote.billingAddress().countryId
                    && quote.billingAddress().countryId != self.checkoutSdkParams.country
                ) {
                    self.checkoutSdkParams.country = quote.billingAddress().countryId;
                }
                
                // check the total amount
                if (quote.totals()
                    && quote.totals().hasOwnProperty('base_grand_total')
                    && parseFloat(quote.totals().base_grand_total).toFixed(2) != self.checkoutSdkParams.amount
                ) {
                    self.checkoutSdkParams.amount
                        = parseFloat(quote.totals().base_grand_total).toFixed(2).toString();
                }

                self.checkoutSdkParams.payButton				= 'noButton';
                self.checkoutSdkParams.prePayment				= nuveiPrePayment;
                self.checkoutSdkParams.onFormValidated			= nuveiIsSdkFormValid;
                self.checkoutSdkParams.onResult					= nuveiAfterSdkResponse;
				self.checkoutSdkParams.onSelectPaymentMethod	= nuveiPmChange;
                self.checkoutSdkParams.crossBrowserApplePay		= true;
                
                if (nuveiIsQaSite()) {
                    self.checkoutSdkParams.webSdkEnv = 'devmobile';
                }
            },
			
            // event function
			scBillingAddrChange: function(_address) {
				console.log('scBillingAddrChange');
				
				if(quote.billingAddress() == null) {
					self.writeLog('scBillingAddrChange - the BillingAddr is null. Stop here.');
					return;
				}
				
				if(typeof self.checkoutSdkParams.sessionToken == 'undefined' 
                    || quote.billingAddress().countryId == self.checkoutSdkParams.country
                ) {
					self.writeLog('scBillingAddrChange - the country is same. Stop here.');
					return;
				}
                
				console.log('scBillingAddrChange - the country was changed', quote.billingAddress().countryId);
				
				// reload the checkout
                let sessionToken = self.checkoutSdkParams.sessionToken;
                
                self.nuveiCollectSdkParams();
                self.checkoutSdkParams.sessionToken = sessionToken;
                
                self.loadSdk();
			},
			
            // event function
			scTotalsChange: function() {
				self.writeLog(quote.totals(), 'scTotalsChange()');
				
				var currentTotal = parseFloat(quote.totals().base_grand_total).toFixed(2);
				
				if(typeof self.checkoutSdkParams.sessionToken == 'undefined'
                    || currentTotal == self.checkoutSdkParams.amount
                ) {
					self.writeLog('scTotalsChange() - the total is same. Stop here.');
					return;
				}
                
				console.log('scTotalsChange() - the total was changed', currentTotal);
				
                let sessionToken = self.checkoutSdkParams.sessionToken;
                
                self.nuveiCollectSdkParams();
                self.checkoutSdkParams.sessionToken = sessionToken;
                
                self.loadSdk();
			},
			
            /**
             * A help method to load the checkout sdk.
             * 
             * @returns void
             */
            loadSdk: function() {
                console.log('load SDK');
                
                // in case of some reloads or when Zero Checkout method is active
                if ($('#nuvei_checkout').length == 0) { // TODO get it as variable!
                    console.log('Missing nuvei_checkout container. Do not load SimplyConnect');

                    nuveiHideLoader();
                    return;
                }
                
                // call the SDK
                simplyConnect(self.checkoutSdkParams);

                nuveiHideLoader();
                return;
            },
            
            // Override the default place order action
            placeOrder: function (data, event) {
                console.log('custom placeOrder');
                
                if (!nuveiIsSimplyFormValid) {
                    nuveiShowGeneralError(jQuery.mage.__('Please, fill all fields of the selected payment method!'));
                    return false;
                }

                nuveiWaitSdkResponse = true;

                if (event) {
                    event.preventDefault();
                }

                window.nuveiCreateMagentoOrder()
                    .then(function(orderId) {
                        window.nuveiSavedOrderId = orderId;
                        checkout.submitPayment();
                    })
                    .catch(function() {
                        nuveiWaitSdkResponse = false;
                    });

                return true;
            },
            
			/**
			 * Help function to show some logs in Sandbox
			 * 
			 * @param string _text text to print
			 * @param mixed _param parameter to print
			 * @param string _mode show log or error
			 * 
			 * @returns void
			 */
			writeLog: function(_text, _param = null, _mode = 'log') {
				if(window.checkoutConfig.payment[nuveiGetCode()].isTestMode !== true) {
					return;
				}
				
				if('log' == _mode) {
					if(null === _param) {
						console.log(_text);
					}
					else {
						console.log(_text, _param);
					}
				}
				else if('error' == _mode) {
					if(null === _param) {
						console.error(_text);
					}
					else {
						console.error(_text, _param);
					}
				}
			}
			
        });
    }
);
