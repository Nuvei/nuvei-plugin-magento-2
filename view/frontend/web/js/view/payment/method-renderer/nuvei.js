/**
 * Nuvei Payments js component.
 *
 * @category Nuvei
 * @package  Nuvei_Checkout
 */

var nuveiAgreementsConfig   = window.checkoutConfig ? window.checkoutConfig.checkoutAgreements : {};

/**
 * Get the code of the module.
 * 
 * @returns {String}
 */
function nuveiGetCode() {
	return 'nuvei';
};

/**
 * Validate checkout agreements
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
 * Use it as last check before complete the Order with the Checkout SDK.
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
			jQuery('body').trigger('processStop');
			return;
		}
        
        // check if the hidden submit button is enabled
        if(jQuery('#nuvei_default_pay_btn').hasClass('disabled')) {
            reject(jQuery.mage.__('Please, check all required fields are filled!'));
			jQuery('body').trigger('processStop');
			return;
        }
		
		nuveiUpdateOrder(resolve, reject);
	});
};

function nuveiUpdateOrder(resolve, reject, secondCall = false) {
	var xmlhttp = new XMLHttpRequest();

    xmlhttp.onreadystatechange = function() {
        if (xmlhttp.readyState == XMLHttpRequest.DONE) {   // XMLHttpRequest.DONE == 4
           if (xmlhttp.status == 200) {
				console.log('status == 200', xmlhttp.response);
				resolve();
				return;
           }
           
			if (xmlhttp.status == 400) {
              console.log('There was an error 400');
			  reject();
			  Query('body').trigger('processStop');
			  return;
           }
		   
			console.log('something else other than 200 was returned');

			reject();
			Query('body').trigger('processStop');
			return;
        }
    };

    xmlhttp.open("GET", window.checkoutConfig.payment[nuveiGetCode()].getUpdateOrderUrl, true);
    xmlhttp.send();
}

/**
 * Here we receive the response from the Checkout SDK Order
 * @param {object} resp
 * @returns {void|Boolean}
 */
function nuveiAfterSdkResponse(resp) {
	console.log('nuveiAfterSdkResponse() resp', resp);

	// on unexpected error
	if(typeof resp == 'undefined'
		|| !resp.hasOwnProperty('result')
		|| !resp.hasOwnProperty('transactionId')
	) {
		if(!alert(jQuery.mage.__('Unexpected error, please try again later!'))) {
			window.location.reload();
			return;
		}
	}

	// on Declined
	if(resp.result == 'DECLINED') {
        if (resp.hasOwnProperty('errorDescription')
            && 'insufficient funds' == resp.errorDescription.toLowerCase
        ) {
            if(!alert(jQuery.mage.__('You have Insufficient funds, please go back and remove some of the items in your shopping cart, or use another card.'))) {
                jQuery('body').trigger('processStop');
                return;
            }
        }
        
		if(!alert(jQuery.mage.__('Your Payment was DECLINED. Please try another payment method!'))) {
			jQuery('body').trigger('processStop');
			return;
		}
	}

	// when not Declined, but not Approved also
	if(resp.result != 'APPROVED' || isNaN(resp.transactionId)) {
		var respError = 'Error with your Payment. Please try again later!';

		if(resp.hasOwnProperty('errorDescription') && '' != resp.errorDescription) {
			respError = resp.errorDescription;
		}
		else if(resp.hasOwnProperty('reason') && '' != resp.reason) {
			respError = resp.reason;
		}

		if(!alert(jQuery.mage.__(respError))) {
			jQuery('body').trigger('processStop');
			return;
		}
	}

	// on Success, Approved
//	window.location.href = window.checkoutConfig.payment[nuveiGetCode()].successUrl;
    jQuery('#nuvei_default_pay_btn').trigger('click');
	jQuery('body').trigger('processStop');
	return;
};

define(
    [
        'jquery',
        'Magento_Payment/js/view/payment/cc-form',
        'Magento_Paypal/js/action/set-payment-method',
        'ko',
        'Magento_Checkout/js/model/quote',
        'mage/translate',
		'mage/validation'
    ],
    function(
        $,
        Component,
        setPaymentMethodAction,
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
                template: 'Nuvei_Checkout/payment/nuvei',
                chosenApmMethod: '',
                countryId: ''
            },
            
            changedOrderAmout: 0,
            
            changedOrderCountry: '',
            
            useCcOnly: false, // set it true when have product with a Payment plan
            
            orderFullName: '',
            
            checkoutSdkParams: {},
			
			selectedProvider: '',
			
            initObservable: function() {
                self = this;
				
                self._super()
                    .observe([
                        'chosenApmMethod',
                        'countryId'
                    ]);
                   
				try {
                    if(typeof quote.paymentMethod != 'undefined') {
                        quote.paymentMethod.subscribe(self.changePaymentProvider, this, 'change');
                    }
                    
					if(quote.paymentMethod._latestValue != null) {
						self.selectedProvider = quote.paymentMethod._latestValue.method;
						self.scUpdateQuotePM();
					}
                    
                    if(typeof quote.totals != 'undefined') {
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

            isShowLegend: function() {
                return true;
            },

            getCode: function() {
//                return 'nuvei';
                return nuveiGetCode();
            },

//			getNuveiIconUrl: function() {
//				return window.checkoutConfig.payment[self.getCode()].checkoutLogoUrl;
//			},

            getPaymentApmUrl: function() {
                return window.checkoutConfig.payment[self.getCode()].paymentApmUrl;
            },
			
			getUpdateQuotePM: function() {
                return window.checkoutConfig.payment[self.getCode()].updateQuotePM;
            },
			
			getSessionToken: function() {
                console.log('getSessionToken');
                
                if(window.checkoutConfig.payment[self.getCode()].isPaymentPlan) {
                    self.useCcOnly = true;
                    
                    if(quote.getItems().length > 1) {
                        self.showGeneralError('You can not combine a Product with Nuvei Payment with another product. To continue, please remove some of the Product in your Cart!');
                        return;
                    }
                }
                
                ///////////////////////////////////
                
                jQuery('body').trigger('processStart');

                self.checkoutSdkParams = JSON.parse(JSON.stringify(window.checkoutConfig.payment[nuveiGetCode()].nuveiCheckoutParams));

                // call openOrder here and get the session token
                 jQuery.ajax({
                    dataType: 'json',
                    url: window.checkoutConfig.payment[nuveiGetCode()].getUpdateOrderUrl
                })
                .error(function(jqXHR, textStatus, errorThrown){
                    // TODO show unexpected error
                    console.log('nuveiLoadCheckout update order fail textStatus', textStatus);
                    console.log('nuveiLoadCheckout update order fail errorThrown', errorThrown);

                    //window.location.reload();

                    jQuery('body').trigger('processStop');
                    return;
                })
                .success(function(resp) {
                    self.checkoutSdkParams.sessionToken = resp.sessionToken;

                    if(!self.checkoutSdkParams.hasOwnProperty('sessionToken')
                        || typeof self.checkoutSdkParams.sessionToken == 'undefined'
                        || '' == self.checkoutSdkParams.sessionToken
                    ) {
                        console.log('nuveiLoadCheckout update order sessionToken problem, reload the page');

                        alert(jQuery.mage.__('Missing mandatory payment details. Please reload the page and try again!'));

                        jQuery('body').trigger('processStop');
                        return;
                    }
                    
                    self.checkoutSdkParams.amount   = quote.totals().base_grand_total;
//                    self.checkoutSdkParams.fullName = quote.billingAddress().firstname 
//                        + ' ' + quote.billingAddress().lastname;

                    if(self.useCcOnly) {
                        self.checkoutSdkParams.pmBlacklist  = null;
                        self.checkoutSdkParams.pmWhitelist  = ['cc_card'];
                    }

                    if(self.checkoutSdkParams.savePM) {
                        self.checkoutSdkParams.userTokenId = self.checkoutSdkParams.email;
                    }

                    // check for changed amout
                    if(self.changedOrderAmout > 0 && self.changedOrderAmout != self.checkoutSdkParams.amount) {
                        self.checkoutSdkParams.amount = self.changedOrderAmout;
                    }
                    
                    // check for changed country
                    if(self.changedOrderCountry != '' && self.changedOrderCountry != self.checkoutSdkParams.country) {
                        self.checkoutSdkParams.country = self.changedOrderCountry;
                    }

                    console.log('nuveiLoadCheckout', self.checkoutSdkParams);

                    self.checkoutSdkParams.prePayment	= nuveiPrePayment;
                    self.checkoutSdkParams.onResult		= nuveiAfterSdkResponse;

                    nuveiCheckoutSdk(self.checkoutSdkParams);

                    jQuery('body').trigger('processStop');
                    return;
                });
			},
			
			showGeneralError: function(msg) {
				jQuery('#nuvei_general_error .message div').html(jQuery.mage.__(msg));
				jQuery('#nuvei_general_error').show();
				document.getElementById("nuvei_general_error").scrollIntoView();
			},
			
			scBillingAddrChange: function() {
				self.writeLog('scBillingAddrChange()');
				
				if(quote.billingAddress() == null) {
					self.writeLog('scBillingAddrChange() - the BillingAddr is null. Stop here.');
					return;
				}
				
				if(typeof self.checkoutSdkParams.sessionToken == 'undefined' 
                    || quote.billingAddress().countryId == self.checkoutSdkParams.country
                ) {
					self.writeLog('scBillingAddrChange() - the country is same. Stop here.');
					return;
				}
				
				self.writeLog('scBillingAddrChange() - the country was changed to', quote.billingAddress().countryId);
				
				// reload the checkout
                self.changedOrderCountry = quote.billingAddress().countryId;
                self.getSessionToken();
			},
			
			scTotalsChange: function() {
				self.writeLog(quote.totals(), 'scTotalsChange()');
				
				var currentTotal = parseFloat(quote.totals().base_grand_total).toFixed(2);
				
				if(typeof self.checkoutSdkParams.sessionToken == 'undefined'
                    || currentTotal == self.checkoutSdkParams.amount
                ) {
					self.writeLog('scTotalsChange() - the total is same. Stop here.');
					return;
				}
				
				self.writeLog('scTotalsChange() - the total was changed to', currentTotal);
				
				// reload the checkout
                self.changedOrderAmout = currentTotal
                self.getSessionToken();
			},
			
			changePaymentProvider: function() {
                self.writeLog('changePaymentProvider()', quote.paymentMethod._latestValue.method);
				
				if(quote.paymentMethod._latestValue != null
					&& self.selectedProvider != quote.paymentMethod._latestValue.method
				) {
					self.selectedProvider = quote.paymentMethod._latestValue.method;
					self.scUpdateQuotePM();
				}
			},
			
			scUpdateQuotePM: function() {
				self.writeLog('scUpdateQuotePM()', self.selectedProvider);
				
				// update new payment method
				if('' != self.selectedProvider) {
					var scAjaxQuoteUpdateParams = {
						dataType	: "json",
						url			: self.getUpdateQuotePM(),
						cache		: false,
						showLoader	: true,
						data		: { paymentMethod: self.selectedProvider }
					};

					$.ajax(scAjaxQuoteUpdateParams)
						.success(function(resp) {
//							nuveiGetSessionToken();
						})
						.error(function(e) {
							self.writeLog(e.responseText, null, 'error');
						});
				}
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
