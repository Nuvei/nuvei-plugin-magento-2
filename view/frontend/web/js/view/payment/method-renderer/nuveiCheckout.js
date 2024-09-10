/**
 * Nuvei Payments js component.
 *
 * @category Nuvei
 * @package  Nuvei_Checkout
 */

var nuveiAgreementsConfig = window.checkoutConfig ? window.checkoutConfig.checkoutAgreements : {};
/**
 * Set it true when prePayment check is resolved, and set it false in the nuveiAfterSdkResponse().
 * 
 * @type Boolean
 */
var nuveiWaitSdkResponse = false;

/**
 * Validate checkout agreements.
 *
 * @returns {Boolean}
 */
function nuveiValidateAgreement(hideError) {
	console.log('nuveiValidateAgreement()');

	var nuveiAgreementsInputPath	= '.payment-method._active div.checkout-agreements input';
	var isValid						= true;

	if (!nuveiAgreementsConfig.isEnabled
		|| jQuery(nuveiAgreementsInputPath).length === 0
	) {
	   return isValid;
	}

	jQuery(nuveiAgreementsInputPath).each(function (index, element) {
	   if (!jQuery.validator.validateSingleElement(element, {
		   errorElement: 'div',
		   hideError: hideError || false
	   })) {
		   isValid = false;
	   }
	});

	return isValid;
};

/**
 * Use it as last check before complete the Order.
 * 
 * @param {object} paymentDetails
 * @returns {Promise}
 */
function nuveiPrePayment(paymentDetails) {
	console.log('nuveiPrePayment()');
	
	return new Promise((resolve, reject) => {
		// validate user agreement
		if (!nuveiValidateAgreement()) {
			reject(jQuery.mage.__('Please, accept required agreement!'));
			nuveiHideLoader();
			return;
		}
        
        // check if the hidden submit button is enabled
        if(jQuery('#nuvei_default_pay_btn').hasClass('disabled')) {
            reject(jQuery.mage.__('Please, check all required fields are filled!'));
			nuveiHideLoader();
			return;
        }
		
		nuveiUpdateOrder(resolve, reject);
	});
};

function nuveiUpdateOrder(resolve, reject) {
    var paramsStr   = '?nuveiAction=nuveiPrePayment';
    var xmlhttp     = new XMLHttpRequest();
    
    xmlhttp.onreadystatechange = function() {
        if (xmlhttp.readyState == XMLHttpRequest.DONE) {   // XMLHttpRequest.DONE == 4
            console.log('Request response', xmlhttp.response);
            
            if (xmlhttp.status == 200) {
				var resp = JSON.parse(xmlhttp.response);
                
                if (!resp.hasOwnProperty('success') || 0 == resp.success) {
                    reject();
                    window.location.reload();
                    return;
                }
                
                nuveiWaitSdkResponse = true;
                resolve();
                return;
            }
           
			if (xmlhttp.status == 400) {
                console.log('There was an error.');
                reject();
                nuveiHideLoader();
                return;
            }
		   
			console.log('Unexpected response code.');
			reject();
			nuveiHideLoader();
			return;
        }
    };
    
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
	console.log('nuveiAfterSdkResponse() resp', resp);
    
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
            nuveiHideLoader();
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
                nuveiHideLoader();
                return;
            }
        }
        
		if(!alert(jQuery.mage.__('Your Payment was DECLINED. Please try another payment method!'))) {
			nuveiHideLoader();
			return;
		}
	}

    // on Approved or Pending
    if (resp.result == 'APPROVED' || resp.result == 'PENDING') {
        jQuery('#nuvei_default_pay_btn').trigger('click');
        return;
    }

	// when not Declined, but not Approved also
    var respError = 'Error with your Payment. Please try again later!';

    if(resp.hasOwnProperty('errorDescription') && '' != resp.errorDescription) {
        respError = resp.errorDescription;
    }
    else if(resp.hasOwnProperty('reason') && '' != resp.reason) {
        respError = resp.reason;
    }

    if(!alert(jQuery.mage.__(respError))) {
        nuveiHideLoader();
        return;
    }

	// on Success, Approved
};

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
        'mage/translate'
    ],
    function(
        $,
        Component,
        ko,
        quote,
        mage
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
                
                return self;
            },
            
            context: function() {
                return self;
            },

            getCode: function() {
                return nuveiGetCode();
            },

			getSessionToken: function() {
                var paymentMethod = quote.paymentMethod();
                
                self.writeLog('getSessionToken', paymentMethod);
                
                // Errors
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
                
                // Cart with mixed products
                if(window.checkoutConfig.payment[self.getCode()].isPaymentPlan
                    && quote.getItems().length > 1
                ) {
                    self.showGeneralError(jQuery.mage.__('You can not combine a Product with Nuvei Payment with another product. To continue, please remove some of the Product in your Cart!'));
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
                                
                                console.log('nuveiLoadCheckout update order sessionToken problem, reload the page');

                                alert(jQuery.mage.__('Missing mandatory payment details. Please reload the page and try again!'));

                                nuveiHideLoader();
                                return;
                            }
                            
                            console.log('sessionToken', resp.sessionToken);

                            self.nuveiCollectSdkParams();
                            self.checkoutSdkParams.sessionToken = resp.sessionToken;
                            self.checkoutSdkParams.amount       = self.checkoutSdkParams.amount.toString();
                            
                            console.log('load checkout sdk', self.checkoutSdkParams);
                            console.log('is #nuvei_checkout exists', $('#nuvei_checkout').length);
                            
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

                self.checkoutSdkParams.prePayment	= nuveiPrePayment;
                self.checkoutSdkParams.onResult		= nuveiAfterSdkResponse;
            },
			
			showGeneralError: function(msg) {
				jQuery('#nuvei_general_error .message div').html(jQuery.mage.__(msg));
				jQuery('#nuvei_general_error').show();
				document.getElementById("nuvei_general_error").scrollIntoView();
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
				if(window.checkoutConfig.payment[self.getCode()].isTestMode !== true) {
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
