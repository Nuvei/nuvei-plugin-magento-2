/**
 * Nuvei Payments js component.
 *
 * @category Nuvei
 * @package  Nuvei_Payments
 */
define(
    [
        'jquery',
        'Magento_Payment/js/view/payment/cc-form',
        'Magento_Paypal/js/action/set-payment-method',
//        'jquery.redirect',
        'ko',
        'Magento_Checkout/js/model/quote',
        'mage/translate'//,
//		'mage/validation'
    ],
    function(
        $,
        Component,
        setPaymentMethodAction,
//        jqueryRedirect,
        ko,
        quote,
        mage
    ) {
        'use strict';

        var self = null;
        
        // for the WebSDK
        var sfc                 = null;
        var cardNumber          = null;
        var cardExpiry          = null;
        var cardCvc             = null;
        var lastCvcHolder       = ''; // the id of the last used CVC container
        var scFields            = null;
        var scData              = {};

        var isCCNumEmpty        = true;
        var isCCNumComplete     = false;

        var isCVVEmpty          = true;
        var isCVVComplete       = false;

        var isCCDateEmpty       = true;
        var isCCDateComplete    = false;
		
        var fieldsStyle	= {
            base: {
                iconColor: "#c4f0ff",
                color: "#000",
                fontWeight: 400,
                fontFamily: "arial",
                fontSize: '15px',
                fontSmoothing: "antialiased",
                ":-webkit-autofill": {
                        color: "#fce883"
                },
                "::placeholder": {
                    color: "grey",
                    fontFamily: "arial"
                }
            },
            invalid: {
                iconColor: "#ff0000",
                color: "#ff0000"
            }
        };
		
        var elementClasses = {
            focus: 'focus',
            empty: 'empty',
            invalid: 'invalid'
        };
		
        var checkoutConfig	= window.checkoutConfig,
            agreementsConfig	= checkoutConfig ? checkoutConfig.checkoutAgreements : {},
            agreementsInputPath	= '.payment-method._active div.checkout-agreements input';
		
        $(function() {
            $('body').on('change', '#nuvei_cc_owner', function(){
                $('#nuvei_cc_owner').css('box-shadow', 'inherit');
                $('#cc_name_error_msg').hide();
            });

            // when change the Payment Method
            $('body').on('change', 'input[name="nuvei_payment_method"]', function() {
                var _self = $(this);
                self.scCleanCard();

                $('#nuvei_default_pay_btn').show();

                // CC
                if(_self.val() == 'cc_card') {
                    lastCvcHolder = '#sc_card_cvc';
                    self.nuveiInitFields();
                    return;
                }

                // UPO CC
                if ('cc_card' == _self.attr('data-upo-name')) {
                    lastCvcHolder = '#sc_upo_'+ _self.val() +'_cvc';
                    self.nuveiInitFields();
                    return;
                }

                // Apple Pay
                if(_self.val() == 'ppp_ApplePay') {
                    if(!window.ApplePaySession) {
                        $('#nuvei_apple_pay_btn').hide();
                        $('#nuvei_apple_pay_error, #nuvei_default_pay_btn').show();
                        return;
                    }

                    $('#nuvei_apple_pay_error, #nuvei_default_pay_btn').hide();
                    $('#nuvei_apple_pay_btn').show();
                    return;
                }
            });

            // when click on Apple Pay button
            $('body').on('click', '#nuvei_apple_pay_btn', function() {
                $('#nuvei_default_pay_btn').trigger('click');
            });

            $('body').on('change', '#nuvei_save_upo_cont input', function() {
                var _self = $(this);
                _self.val(_self.is(':checked') ? 1 : 0);
            });
        });
		
        return Component.extend({
            defaults: {
                template: 'Nuvei_Checkout/payment/nuveiWeb',
                apmMethods: [],
                upos: [],
                applePayData: {},
                chosenApmMethod: '',
                typeOfChosenPayMethod: '',
                countryId: ''
            },
			
            scOrderTotal: 0,

            scBillingCountry: '',

            scPaymentMethod: '',
			
            initObservable: function() {
				console.log('initObservable()')
				
                self = this;
				
                self._super()
                    .observe([
                        'apmMethods',
                        'upos',
                        'applePayData',
                        'chosenApmMethod',
                        'typeOfChosenPayMethod',
                        'countryId'
                    ]);
                    
                try {
                    if(typeof quote.paymentMethod != 'undefined') {
                        quote.paymentMethod.subscribe(self.scPaymentMethodChange, this, 'change');
                    }
                    
                    if(quote.paymentMethod._latestValue != null) {
                        self.scPaymentMethod = quote.paymentMethod._latestValue.method;
//                        self.scUpdateQuotePM();
                    }
                    
                    // set observer when change the payment method
                    self.chosenApmMethod.subscribe(self.setChosenApmMethod, this, 'change'); 
                    
                    if(typeof quote.totals != 'undefined') {
                        self.scOrderTotal = parseFloat(quote.totals().base_grand_total).toFixed(2);
                        quote.totals.subscribe(self.scTotalsChange, this, 'change');
                    }
                    
                    if(typeof quote.billingAddress != 'undefined') {
                        self.scBillingCountry = quote.billingAddress().countryId;
                        quote.billingAddress.subscribe(self.scBillingAddrChange, this, 'change');
                    }

                    self.getApmMethods();
                } catch(ex) {
                    console.error('Nuvei initObservable Exception', ex);
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
                return 'nuvei';
            },

            isActive: function() {
                return true;
            },

            getData: function() {
                var pmData = {
                    method : self.item.method,
                    additional_data : {
                        chosen_apm_method : self.chosenApmMethod()
                    }
                };
				
                return pmData;
            },
			
            setChosenApmMethod: function() {
                self.writeLog('setChosenApmMethod()', self.chosenApmMethod());

                $('#nuvei_apple_pay_error, #nuvei_apple_pay_btn, #nuvei_general_error').hide();

                // CC
                if(self.chosenApmMethod() == 'cc_card') {
                    self.typeOfChosenPayMethod('cc_card');
                    self.writeLog(self.typeOfChosenPayMethod());

                    if(window.checkoutConfig.payment[self.getCode()].saveUpos == 1) {
                        $('body').find('#nuvei_save_upo_cont').show();
                    }

                    return;
                }

                // Apple Pay
                if(self.chosenApmMethod() == 'ppp_ApplePay') {
                    //self.typeOfChosenPayMethod('cc_card');

                    if(typeof window.ApplePaySession != 'function') {
                        $('#nuvei_apple_pay_error').show();
                        return;
                    }

                    $('body').find('#nuvei_save_upo_cont, #nuvei_apple_pay_error, #nuvei_default_pay_btn').hide();
                    $('#nuvei_apple_pay_btn').show();

                    return;
                }

                // APM
                if(isNaN(self.chosenApmMethod()) && self.chosenApmMethod() != 'ppp_ApplePay') {
                    self.typeOfChosenPayMethod('apm');
                    self.writeLog(self.typeOfChosenPayMethod());
                    self.writeLog('show checkbox');

                    if(window.checkoutConfig.payment[self.getCode()].saveUpos == 1) {
                        $('body').find('#nuvei_save_upo_cont').show();
                    }
                    
                    return;
                }

                // UPOs
                self.writeLog('hide checkbox');

                $('body').find('#nuvei_save_upo_cont').hide();

                var selectedOption = $('body').find('#nuvei_' + self.chosenApmMethod());

                if(selectedOption.attr('data-upo-name') == 'cc_card') {
                    self.typeOfChosenPayMethod('upo_cc');
                }
                else {
                    self.typeOfChosenPayMethod('upo_apm');
                }

                self.writeLog(self.typeOfChosenPayMethod());
            },

            getRedirectUrl: function() {
                return window.checkoutConfig.payment[self.getCode()].redirectUrl;
            },

            getPaymentApmUrl: function() {
                return window.checkoutConfig.payment[self.getCode()].paymentApmUrl;
            },
			
            getUPOsUrl: function() {
                return window.checkoutConfig.payment[self.getCode()].getUPOsUrl;
            },
			
            getUpdateOrderUrl: function() {
                return window.checkoutConfig.payment[self.getCode()].getUpdateOrderUrl;
            },

            getRemoveUpoUrl: function() {
                return window.checkoutConfig.payment[self.getCode()].getRemoveUpoUrl;
            },
	    
//            getUpdateQuotePM: function() {
//                return window.checkoutConfig.payment[self.getCode()].updateQuotePM;
//            },
			
            getMerchantPaymentMethodsUrl: function() {
                return window.checkoutConfig.payment[self.getCode()].getMerchantPaymentMethodsUrl;
            },
			
//            getNuveiIconUrl: function() {
//                return window.checkoutConfig.payment[self.getCode()].checkoutLogoUrl;
//            },

            getApplePayBtnImg: function() {
                return window.checkoutConfig.payment[self.getCode()].checkoutApplePayBtn;
            },
			
            removeUpo: function(_upoId) {
                self.writeLog('removeUpo', _upoId);
				
                if(confirm($.mage.__('Are you sure, you want to delete this Preferred payment method?'))) {
                    $.ajax({
                        dataType: "json",
                                            type: 'post',
                        url: self.getRemoveUpoUrl(),
                        data: { upoId: _upoId },
                        cache: false,
                        showLoader: true
                    })
                    .done(function(res) {
                        self.writeLog(res);

                        if (res && res.hasOwnProperty('success') && res.success == 1) {
                            self.writeLog('success');

                            $('body')
                                .find('#nuvei_upos input#nuvei_' + _upoId)
                                .closest('.nuvei-apm-method-container')
                                .remove();
                        }
                        else {
                            self.writeLog(res, null, 'error');
                            self.isPlaceOrderActionAllowed(false);
                        }

                        $('.loading-mask').css('display', 'none');
                    })
                    .fail(function(e) {
                        self.writeLog(e.responseText, null, 'error');

                        alert($.mage.__('Unexpected error, please try again later!'));

                        $('.loading-mask').css('display', 'none');
                    });
                } 
            },
			
            getApmMethods: function(billingAddress) {
                self.writeLog('getApmMethods()');
				
                if('nuvei' != self.scPaymentMethod) {
                    self.writeLog('getApmMethods() - slected payment method is not Nuvei');
                    return;
                }
				
                $.ajax({
                    dataType: "json",
                    type: 'post',
                    url: self.getMerchantPaymentMethodsUrl(),
                    data: {
                        billingAddress: billingAddress
                    },
                    cache: false,
                    showLoader: true
                })
                .done(function(res) {
                    self.writeLog(res);
					
                    if (res && res.error == 0) {
                        self.apmMethods(res.apmMethods);
                        self.applePayData(res.applePayData);
                        self.upos(res.upos);
						
                        if(typeof window.ApplePaySession == 'function'
                            && typeof res.applePayData == 'object' 
                            && res.applePayData.hasOwnProperty('paymentMethod')
                        ) {
                            $('#nuvei_apple_pay').show();
                        }
						
                        if (res.upos.length > 0) {
                            $('#nuvei_upos_title').show();
                        }
						
                        if (res.apmMethods.length > 0) {
                            $('#nuvei_apms_title').show();

                            var isThereCcOption	= false;

                            for(var i in res.apmMethods) {
                                if('cc_card' == res.apmMethods[i].paymentMethod) {
                                    scData.sessionToken	= res.sessionToken;
                                    isThereCcOption		= true

                                    self.nuveiInitFields();
                                    
                                    var nuvei_cc_card = document.getElementById("nuvei_cc_card");
                                    if(typeof nuvei_cc_card == 'object') {
                                        document.getElementById("nuvei_cc_card").click();
                                    }
                                    
                                    break;
                                }
                            }

                            if(!isThereCcOption && 1 == res.apmMethods.length) {
                                document.getElementById("nuvei_" + res.apmMethods[0].paymentMethod).click();
                            }
                        }
                        else {
                            self.isPlaceOrderActionAllowed(false);
                        }
                    }
                    else {
                        self.writeLog(res, null, 'error');
                        self.isPlaceOrderActionAllowed(false);
                    }

                    $('.loading-mask').css('display', 'none');
                })
                .fail(function(e) {
                    self.writeLog(e.responseText, null, 'error');
                    self.isPlaceOrderActionAllowed(false);
                });
            },
			
            placeOrder: function(data, event) {
                self.writeLog('placeOrder()');

                if(self.chosenApmMethod() === '') {
                    self.writeLog('chosenApmMethod is empty', null, 'error');
                    self.showGeneralError('Please, choose some of the available payment options!');
                    
                    if(typeof quote.billingAddress != 'undefined'
                        && self.apmMethods.length == 0
                    ) {
                        if(typeof quote.paymentMethod._latestValue.method != 'undefined') {
                            self.scPaymentMethod = quote.paymentMethod._latestValue.method;
                        }
                
                        self.getApmMethods(quote.billingAddress);
                    }
                    
                    return;
                }

                $('body').trigger('processStart'); // show loader
				
                if (event) {
                    event.preventDefault();
                }
				
                jQuery.ajax({
                    dataType: 'json',
                    url: self.getUpdateOrderUrl()
                })
                .fail(function(){
                    self.validateOrderData();
                })
                .done(function(resp) {
                    self.writeLog(resp);

                    if(resp.hasOwnProperty('sessionToken')
                        && '' != resp.sessionToken
                        && resp.sessionToken != scData.sessionToken
                    ) {
                        scData.sessionToken = resp.sessionToken;

                        sfc         = SafeCharge(scData);
                        scFields    = sfc.fields({
                            locale: checkoutConfig.payment[self.getCode()].locale
                        });
                    }

                    self.validateOrderData();
                });
            },
            
            validateOrderData: function() {
                self.writeLog('validateOrderData()');

                var payParams = {
                    sessionToken    : scData.sessionToken,
                    merchantId      : window.checkoutConfig.payment[self.getCode()].merchantId,
                    merchantSiteId  : window.checkoutConfig.payment[self.getCode()].merchantSiteId,
                    webMasterId     : window.checkoutConfig.payment[self.getCode()].webMasterId,
                    apmWindowType   : window.checkoutConfig.payment[self.getCode()].apmWindowType,
                    //env           : window.checkoutConfig.payment[self.getCode()].isTestMode == true ? 'int' : 'prod'
                };
                
                // Apple Pay
                if(self.chosenApmMethod() === 'ppp_ApplePay') {
                    if(typeof window.ApplePaySession != 'function') {
                        alert($.mage.__('Unexpected session error. Please, try different payment method!'));
                        $('body').trigger('processStop');

                        self.writeLog('ApplePaySession is not a Function.', null, 'error')
                        return;
                    }

                    payParams.countryCode   = window.checkoutConfig.payment[self.getCode()].countryId;
                    payParams.currencyCode  = window.checkoutConfig.payment[self.getCode()].currencyCode;
//                    payParams.currencyCode  = quote.totals().base_currency_code;
                    payParams.amount        = self.scOrderTotal;
                    payParams.total         = {
                        label   : window.checkoutConfig.payment[self.getCode()].applePayLabel,
                        amount  : self.scOrderTotal
                    };

                    self.writeLog(payParams)

                    self.createPayment(payParams, true);
                    return;
                }
				
                // CC
                if(self.chosenApmMethod() === 'cc_card') {
                    if($('#nuvei_cc_owner').val() == '') {
                        $('#nuvei_cc_owner').css('box-shadow', 'red 0px 0px 3px 1px');
                        $('#cc_name_error_msg').show();

                        document.getElementById("nuvei_cc_owner").scrollIntoView();
                        $('body').trigger('processStop');

                        return;
                    }
					
                    if( (!isCCNumEmpty && !isCCNumComplete) || isCCNumEmpty ) {
                        $('#sc_card_number').css('box-shadow', 'red 0px 0px 3px 1px');
                        $('#cc_num_error_msg').show();

                        document.getElementById("nuvei_cc_owner").scrollIntoView();
                        $('body').trigger('processStop');

                        return;
                    }
					
                    if( (!isCVVEmpty && !isCVVComplete) || isCVVEmpty ) {
                        $('#sc_card_cvc').css('box-shadow', 'red 0px 0px 3px 1px');
                        $('#cc_error_msg').show();

                        document.getElementById("nuvei_cc_owner").scrollIntoView();
                        $('body').trigger('processStop');

                        return;
                    }
					
                    if( (!isCCDateEmpty&& !isCCDateComplete) || isCCDateEmpty ) {
                        $('#sc_card_expiry').css('box-shadow', 'red 0px 0px 3px 1px');
                        $('#cc_error_msg').show();

                        document.getElementById("nuvei_cc_owner").scrollIntoView();
                        $('body').trigger('processStop');

                        return;
                    }
					
                    if(!self.validate()) {
                        $('body').trigger('processStop');
                        return;
                    }

                    if(null == cardNumber) {
                        alert($.mage.__('Unexpected error! If the fields of the selected payment method do not reload in few seconds, please reload the page!'));
                        $('body').trigger('processStop');

                        return;
                    }
					
                    payParams.paymentOption		= cardNumber;
                    payParams.cardHolderName	= document.getElementById('nuvei_cc_owner').value;
                    
                    var canSaveUpo = 1 == window.checkoutConfig.payment[self.getCode()].saveUpos ? true : false;
                    
//                    if (1 == window.checkoutConfig.payment[self.getCode()].submitUserTokenForGuest
//                        || ( 1 == window.checkoutConfig.payment[self.getCode()].useUPOs
//                        && ( self.typeOfChosenPayMethod() == 'cc_card' || self.typeOfChosenPayMethod() == 'apm' )
//                        && $('body').find('#nuvei_save_upo_cont input').val() == 1 )
//                    ) {
                    if ( canSaveUpo
                        && ( self.typeOfChosenPayMethod() == 'cc_card' || self.typeOfChosenPayMethod() == 'apm' )
                        && $('body').find('#nuvei_save_upo_cont input').val() == 1
                    ) {
                        payParams.userTokenId = window.checkoutConfig.payment[self.getCode()].userTokenId;
                    }
					
                    // create payment with WebSDK
                    self.createPayment(payParams);
                    return;
                }
				
                // in case of CC UPO
                if(self.typeOfChosenPayMethod() === 'upo_cc') {
                    // checks
                    if( (!isCVVEmpty && !isCVVComplete) || isCVVEmpty ) {
                        $('#sc_upo_'+ self.chosenApmMethod() +'_cvc').css('box-shadow', 'red 0px 0px 3px 1px');

                        $('#sc_upo_'+ self.chosenApmMethod() +'_cvc')
                            .closest('.nuvei-apm-method-container')
                            .find('fieldset .sc_error')
                            .show();

                        document.getElementById('sc_upo_'+ self.chosenApmMethod() +'_cvc').scrollIntoView();
                        $('body').trigger('processStop');

                        return;
                    }
					
                    if(!self.validate()) {
                        $('body').trigger('processStop');
                        return;
                    }
                    // checks END
					
                    payParams.userTokenId	= window.checkoutConfig.payment[self.getCode()].userTokenId;
                    payParams.paymentOption	= {
                        userPaymentOptionId: self.chosenApmMethod(),
                        card: {
                            CVV: cardCvc
                        }
                    };

                    // create payment with WebSDK
                    self.createPayment(payParams);
                    return;
                }
                
                // in case of APM
                if(self.typeOfChosenPayMethod() === 'apm') {
                    var showApmError = false;
                    
                    // check for empty fields
                    $('#nuvei_'+ self.chosenApmMethod())
                        .closest('.nuvei-apm-method-container')
                        .find('fieldset input')
                        .each(function() {
                            var currField       = $(this);
                            var currFieldError  = $('#nuvei_'+ self.chosenApmMethod())
                                .closest('.nuvei-apm-method-container')
                                .find('#'+ currField.attr('name'));
                    
                            if(currField.val() == '') {
                                currField.css('box-shadow', 'red 0px 0px 3px 1px');
                                currFieldError.show();
                                
                                document.getElementById('nuvei_'+ self.chosenApmMethod()).scrollIntoView();
                                $('body').trigger('processStop');
                                
                                showApmError = true;
                                
                                return false;
                            }
                            else {
                                currField.css('box-shadow', 'none');
                                currFieldError.hide();
                            }
                        });
                    
                    if(!showApmError) {
                        self.continueWithOrder();
                        return;
                    }
                    
                    return;
                }
                
                // all other cases
                self.continueWithOrder();
                return;
            },
			
            // a repeating part of the code
            createPayment: function(payParams, isApplePay) {
                // Apple Pay
                if(typeof isApplePay != 'undefined') {
                    sfc.createApplePayPayment(payParams, function(resp){
                        self.afterSdkResponse(resp);
                    });
                }
                // other payments
                else {
                    sfc.createPayment(payParams, function(resp){
                        self.afterSdkResponse(resp);
                    });
                }
            },
			
            afterSdkResponse: function(resp) {
                self.writeLog('create payment');

                if(typeof resp != 'undefined' && resp.hasOwnProperty('result')) {
                    if(resp.result == 'APPROVED' && resp.transactionId != 'undefined') {
                        self.continueWithOrder(resp.transactionId);
                    }
                    else if(resp.result == 'DECLINED') {
                        if(!alert($.mage.__('Your Payment was DECLINED. Please try another payment method!'))) {
                            $('body').trigger('processStop');
                        }
                    }
                    else {
                        var respError = 'Error with your Payment. Please try again later!';

                        if(resp.hasOwnProperty('errorDescription') && '' != resp.errorDescription) {
                            respError = resp.errorDescription;
                        }
                        else if(resp.hasOwnProperty('reason') && '' != resp.reason) {
                            respError = resp.reason;
                        }

                        self.writeLog(resp, null, 'error');

                        if(!alert($.mage.__(respError))) {
                            self.scCleanCard();
                            self.getApmMethods();
                            $('body').trigger('processStop');

                            return;
                        }
                    }
                }
                else {
                    if(!alert($.mage.__('Unexpected error, please try again later!'))) {
                        window.location.reload();
                        return;
                    }
                }
        },
			
            continueWithOrder: function(transactionId) {
                self.writeLog('continueWithOrder()');

                // stop the proccess
                if(!self.validate()) {
                    console.log('validation error, stop the proccess');
                    
                    $('body').trigger('processStop');
                    return false;
                }

                // continue with the order
                self.isPlaceOrderActionAllowed(false);

                console.log('self.typeOfChosenPayMethod()', self.typeOfChosenPayMethod())

                // APMs and UPO APMs payments
                if (self.typeOfChosenPayMethod() === 'apm'
                    || self.typeOfChosenPayMethod() === 'upo_apm'
                ) {
                    self.writeLog('continueWithOrder() apm or upo_apm');

                    var choosenMethod	= self.chosenApmMethod();
                    var postData	= {
                        chosen_apm_method   : choosenMethod,
                        apm_method_fields   : {}
                    };
                    
                    console.log('postData', postData)

                    // for APMs only
                    if(self.typeOfChosenPayMethod() === 'apm') {
                        $('.fields-' + choosenMethod + ' input').each(function(){
                            var _slef = $(this);
                            postData.apm_method_fields[_slef.attr('name')] = _slef.val();
                        });

                        if(window.checkoutConfig.payment[self.getCode()].saveUpos == 1) {
                            postData.save_payment_method = $('body').find('#nuvei_save_upo_cont input').val();
                        }
                    }

                    self.selectPaymentMethod();
                    		
                    setPaymentMethodAction(self.messageContainer)
                        .done(function() {
                            $('body').trigger('processStart');

                            $.ajax({
                                dataType: "json",
                                type: 'post',
                                data: postData,
                                url: self.getPaymentApmUrl(),
                                cache: false
                            })
                            .done(function(res) {
                                if (res && res.error == 0 && res.redirectUrl) {
                                    window.location.href = res.redirectUrl;
                                    return;
                                }
                                else {
                                    self.writeLog(res, null, 'error');
                                    window.location.reload();
                                    return;
                                }
                            })
                            .fail(function(e) {
                                self.writeLog(e, null, 'error');
                                window.location.reload();
                                return;
                            });
                        }.bind(self)
                    );

                    $('body').trigger('processStop');
                    return;
                }

                var ajaxData = {
                    dataType: "json",
                    url: self.getRedirectUrl(),
                    cache: false
                };

                // in case we use WebSDK
                if(self.typeOfChosenPayMethod() !== 'apm' && transactionId != 'undefined') {
                    ajaxData.url += '?method=web_sdk&transactionId=' + transactionId;
                }

                self.selectPaymentMethod();

                setPaymentMethodAction(self.messageContainer)
                    .done(function() {
                        $.ajax(ajaxData)
                            .done(function(postData) {
                                if (postData) {
                                    if(self.typeOfChosenPayMethod() !== 'apm'
                                        && typeof transactionId != 'undefined'
                                        && !isNaN(transactionId)
                                    ) {
                                        window.location.href = postData.url;
                                        return;
                                    }

                                    $.redirect(postData.url, postData.params, "POST");
                                    return;
                                }
                                else {
                                    window.location.reload();
                                    return;
                                }
                            })
                            .fail(function() {
                                window.location.reload();
                                return;
                            });
                    }.bind(self));

                return true;
            },
            
            showGeneralError: function(msg) {
                jQuery('#nuvei_general_error .message div').html(jQuery.mage.__(msg));
                jQuery('#nuvei_general_error').show();
                document.getElementById("nuvei_general_error").scrollIntoView();
            },
			
            nuveiInitFields: function() {
                self.writeLog('nuveiInitFields()');

                if('nuvei' != self.scPaymentMethod) {
                    self.writeLog('nuveiInitFields() - slected payment method is not Nuvei');

                    $('body').trigger('processStop');

                    return;
                }
				
                // for the Fields
                scData.merchantSiteId       = window.checkoutConfig.payment[self.getCode()].merchantSiteId;
                scData.merchantId           = window.checkoutConfig.payment[self.getCode()].merchantId;
                scData.sourceApplication    = window.checkoutConfig.payment[self.getCode()].sourceApplication;
				
                if(window.checkoutConfig.payment[self.getCode()].isTestMode == true) {
                    scData.env = 'int';
                }
                else {
                    scData.env = 'prod';
                }
				
                sfc = SafeCharge(scData);

                // prepare fields
                scFields = sfc.fields({
                    locale: checkoutConfig.payment[self.getCode()].locale
                });

                if( ( $('#sc_card_number').html() == '' || typeof $('#sc_card_number').html() == 'undefined' )
                    && ( $('#sc_card_expiry').html() == '' || typeof $('#sc_card_expiry').html() == 'undefined' )
                    && ( $('#sc_card_cvc').html() == '' || typeof $('#sc_card_cvc').html() == 'undefined' )
                    && lastCvcHolder !== ''
                ) {
                    self.attachFields();
                }
            },
			
            attachFields: function() {
                self.writeLog('attachFields()');
                self.writeLog('scFields', scFields);
                self.writeLog('lastCvcHolder', lastCvcHolder);
				
                if(null === scFields) {
                    self.writeLog('scFields is null');

                    $('body').trigger('processStop');

                    return;
                }
				
                // CC fields only
                if('#sc_card_cvc' === lastCvcHolder) {
                    // CC number
                    cardNumber = scFields.create('ccNumber', {
                        classes: elementClasses
                        ,style: fieldsStyle
                    });
                    cardNumber.attach('#sc_card_number');

                    // attach events listeners
                    cardNumber.on('focus', function (e) {
                        $('#sc_card_number').css('box-shadow', '0px 0 3px 1px #00699d');
                        $('#cc_num_error_msg').hide();
                    });

                    cardNumber.on('change', function (e) {
                        $('#sc_card_number').css('box-shadow', '0px 0 3px 1px #00699d');
                        $('#cc_num_error_msg').hide();

                        if(e.hasOwnProperty('empty')) {
                            isCCNumEmpty = e.empty;
                        }

                        if(e.hasOwnProperty('complete')) {
                            isCCNumComplete = e.complete;
                        }
                    });
                    // CC number END

                    // CC Expiry
                    cardExpiry = scFields.create('ccExpiration', {
                        classes: elementClasses
                        ,style: fieldsStyle
                    });
                    cardExpiry.attach('#sc_card_expiry');

                    cardExpiry.on('focus', function (e) {
                        $('#sc_card_expiry').css('box-shadow', '0px 0 3px 1px #00699d');
                        $('#cc_error_msg').hide();
                    });

                    cardExpiry.on('change', function (e) {
                        $('#sc_card_expiry').css('box-shadow', '0px 0 3px 1px #00699d');
                        $('#cc_error_msg').hide();

                        if(e.hasOwnProperty('empty')) {
                            isCCDateEmpty = e.empty;
                        }

                        if(e.hasOwnProperty('complete')) {
                            isCCDateComplete = e.complete;
                        }
                    });
                    // // CC Expiry END

                    // CC CVC
                    cardCvc = scFields.create('ccCvc', {
                        classes: elementClasses
                        ,style: fieldsStyle
                    });
                    cardCvc.attach(lastCvcHolder);

                    cardCvc.on('focus', function (e) {
                        $('#sc_card_cvc').css('box-shadow', '0px 0 3px 1px #00699d');
                        $('#cc_error_msg').hide();
                    });

                    cardCvc.on('change', function (e) {
                        $('#sc_card_cvc').css('box-shadow', '0px 0 3px 1px #00699d');
                        $('#cc_error_msg').hide();

                        if(e.hasOwnProperty('empty')) {
                            isCVVEmpty = e.empty;
                        }

                        if(e.hasOwnProperty('complete')) {
                            isCVVComplete = e.complete;
                        }
                    });
                    // CC CVC END

                    $('body').trigger('processStop');
                }
                // UPO CC
                else if('' !== lastCvcHolder) {
                    cardCvc = scFields.create('ccCvc', {
                        classes: elementClasses
                        ,style: fieldsStyle
                    });
                    cardCvc.attach(lastCvcHolder);

                    cardCvc.on('focus', function (e) {
                        $('#sc_upo_'+ self.chosenApmMethod() +'_cvc').css('box-shadow', '0px 0 3px 1px #00699d');

                        $('#sc_upo_'+ self.chosenApmMethod() +'_cvc')
                            .closest('.nuvei-apm-method-container')
                            .find('fieldset .sc_error')
                            .hide();
                    });

                    cardCvc.on('change', function (e) {
                        $('#sc_upo_'+ self.chosenApmMethod() +'_cvc').css('box-shadow', '0px 0 3px 1px #00699d');

                        $('#sc_upo_'+ self.chosenApmMethod() +'_cvc')
                            .closest('.nuvei-apm-method-container')
                            .find('fieldset .sc_error')
                            .hide();

                        if(e.hasOwnProperty('empty')) {
                            isCVVEmpty = e.empty;
                        }

                        if(e.hasOwnProperty('complete')) {
                            isCVVComplete = e.complete;
                        }
                    });

                    $('body').trigger('processStop');
                }
            },
			
            /**
              * Validate checkout agreements
             *
             * @returns {Boolean}
            */
            validate: function (hideError) {
                self.writeLog('validate()');

                var isValid = true;

                if (!agreementsConfig.isEnabled || $(agreementsInputPath).length === 0) {
                   return true;
                }

                $(agreementsInputPath).each(function (index, element) {
                   if (!$.validator.validateSingleElement(
                        element,
                        {
                            errorElement: 'div',
                            hideError: hideError || false
                        }
                    )) {
                        isValid = false;
                   }
                });

                return isValid;
            },
		   
            scCleanCard: function () {
                self.writeLog('scCleanCard()');

                cardNumber = cardExpiry = cardCvc = null;
                $('#sc_card_number, #sc_card_expiry, #sc_card_cvc').html('');

                if(lastCvcHolder !== '') {
                    $(lastCvcHolder).html('');
                }
            },
			
            scBillingAddrChange: function() {
                self.writeLog('scBillingAddrChange()');

                if(quote.billingAddress() == null) {
                    self.writeLog('scBillingAddrChange() - the BillingAddr is null. Stop here.');
                    return;
                }

                if(quote.billingAddress().countryId == self.scBillingCountry) {
                    self.writeLog('scBillingAddrChange() - the country is same. Stop here.');
                    return;
                }

                self.writeLog('scBillingAddrChange() - the country was changed to', quote.billingAddress().countryId);
                self.scBillingCountry = quote.billingAddress().countryId;

                self.scCleanCard();
                self.getApmMethods(JSON.stringify(quote.billingAddress()));
            },
			
            scTotalsChange: function() {
                self.writeLog(quote.totals(), 'scTotalsChange()');

                var currentTotal = parseFloat(quote.totals().base_grand_total).toFixed(2);

                if(currentTotal == self.scOrderTotal) {
                    self.writeLog('scTotalsChange() - the total is same. Stop here.');
                    return;
                }

                self.writeLog('scTotalsChange() - the total was changed to', currentTotal);
                self.scOrderTotal = currentTotal;

                self.scCleanCard();
                self.getApmMethods();
            },
			
            scPaymentMethodChange: function() {
                self.writeLog('scPaymentMethodChange()');

                if(quote.paymentMethod._latestValue != null
                    && self.scPaymentMethod != quote.paymentMethod._latestValue.method
                ) {
                    self.writeLog('new paymentMethod is', quote.paymentMethod._latestValue.method);

//                    self.scUpdateQuotePM();

                    self.scPaymentMethod = quote.paymentMethod._latestValue.method;

                    if('nuvei' == self.scPaymentMethod) {
                        self.writeLog('sfc', sfc);

                        if(null == sfc) {
                                self.getApmMethods();
                        }

                        if('cc_card' == self.typeOfChosenPayMethod() || 'upo_cc' == self.typeOfChosenPayMethod()) {
                                self.nuveiInitFields();
                        }
                    }
                    else {
                        self.scCleanCard();
                    }
                }
            },
			
//            scUpdateQuotePM: function() {
//                self.writeLog('scUpdateQuotePM()');
//
//                var scAjaxQuoteUpdateParams = {
//                    dataType	: "json",
//                    url		: self.getUpdateQuotePM(),
//                    cache	: false,
//                    showLoader	: true,
//                    data	: { paymentMethod: quote.paymentMethod._latestValue.method }
//                };
//
//                // update new payment method
//                if('' != self.scPaymentMethod || quote.paymentMethod._latestValue.method != self.scPaymentMethod) {
//                    self.writeLog('update quote payment method', quote.paymentMethod._latestValue.method);
//
//                    $.ajax(scAjaxQuoteUpdateParams)
//                        .done(function(resp) {})
//                        .fail(function(e) {
//                            self.writeLog(e.responseText, null, 'error');
//                        });
//                }
//            },
			
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
