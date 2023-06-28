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
		
        var checkoutConfig      = window.checkoutConfig,
            agreementsConfig	= checkoutConfig ? checkoutConfig.checkoutAgreements : {},
            agreementsInputPath = '.payment-method._active div.checkout-agreements input';
		
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
            
            transactionId: 0,
            
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

            // use it into the template
            getCode: function() {
                return nuveiGetCode();
            },
            
            // use it into the template
            showUpos: function() {
                return window.checkoutConfig.payment[nuveiGetCode()].showUpos;
            },
            
            savePm: function() {
                var canSaveUpo = window.checkoutConfig.payment[nuveiGetCode()].saveUpos;
                
                // save UPO only for APM or CC
                if (self.typeOfChosenPayMethod() != 'cc_card' && self.typeOfChosenPayMethod() != 'apm') {
                    return false;
                }
                
                if ('always' != canSaveUpo) {
                    return true;
                }
                
                if ($('body').find('#nuvei_save_upo_cont input').length > 0
                    && $('body').find('#nuvei_save_upo_cont input').val() == 1
                    && 'false' != canSaveUpo
                ) {
                    return true;
                }
                
                return false;
            },
            
            // use it into the template
            showSaveUpos: function() {
                var saveUpos = window.checkoutConfig.payment[nuveiGetCode()].saveUpos;
                
                if ('true' == saveUpos || 'force' == saveUpos) {
                    return true;
                }
                
                return false;
            },
            
            getData: function() {
                var pmData = {
                    method : self.item.method,
                    additional_data : {
                        chosen_apm_method: self.chosenApmMethod()
                    }
                };
				
                return pmData;
            },
			
            setChosenApmMethod: function() {
                console.log('setChosenApmMethod()', self.chosenApmMethod());

                $('#nuvei_apple_pay_error, #nuvei_apple_pay_btn, #nuvei_general_error').hide();

                // CC
                if(self.chosenApmMethod() == 'cc_card') {
                    self.typeOfChosenPayMethod('cc_card');
                    console.log(self.typeOfChosenPayMethod());

                    $('body').find('#nuvei_save_upo_cont').show();

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
                    console.log('show checkbox', self.typeOfChosenPayMethod());

                    $('body').find('#nuvei_save_upo_cont').show();
                    return;
                }

                // UPOs
                console.log('hide checkbox');

                $('body').find('#nuvei_save_upo_cont').hide();

                var selectedOption = $('body').find('#nuvei_' + self.chosenApmMethod());

                if(selectedOption.attr('data-upo-name') == 'cc_card') {
                    self.typeOfChosenPayMethod('upo_cc');
                }
                else {
                    self.typeOfChosenPayMethod('upo_apm');
                }

                console.log(self.typeOfChosenPayMethod());
            },

//            getUPOsUrl: function() {
//                return window.checkoutConfig.payment[nuveiGetCode()].getUPOsUrl;
//            },
			
            // use it into the template
            getApplePayBtnImg: function() {
                return window.checkoutConfig.payment[nuveiGetCode()].checkoutApplePayBtn;
            },
			
            removeUpo: function(_upoId) {
                console.log('removeUpo', _upoId);
				
                if(confirm($.mage.__('Are you sure, you want to delete this Preferred payment method?'))) {
                    $.ajax({
                        dataType: "json",
                        type: 'post',
                        url: window.checkoutConfig.payment[nuveiGetCode()].getRemoveUpoUrl,
                        data: { upoId: _upoId },
                        cache: false,
                        showLoader: true
                    })
                    .done(function(res) {
                        console.log(res);

                        if (res && res.hasOwnProperty('success') && res.success == 1) {
                            console.log('success');

                            $('body')
                                .find('#nuvei_upos input#nuvei_' + _upoId)
                                .closest('.nuvei-apm-method-container')
                                .remove();
                        }
                        else {
                            console.log(res, null, 'error');
                            self.isPlaceOrderActionAllowed(false);
                        }

                        $('.loading-mask').css('display', 'none');
                    })
                    .fail(function(e) {
                        console.log(e.responseText, null, 'error');

                        alert($.mage.__('Unexpected error, please try again later!'));

                        $('.loading-mask').css('display', 'none');
                    });
                } 
            },
			
            getApmMethods: function(billingAddress) {
                console.log('getApmMethods()');
				
                if('nuvei' != self.scPaymentMethod) {
                    console.log('getApmMethods() - slected payment method is not Nuvei, but', self.scPaymentMethod);
                    return;
                }
				
                $.ajax({
                    dataType: "json",
                    type: 'post',
                    url: window.checkoutConfig.payment[nuveiGetCode()].getMerchantPaymentMethodsUrl,
                    data: {
                        billingAddress: billingAddress
                    },
                    cache: false,
                    showLoader: true
                })
                .done(function(res) {
                    console.log(res);
					
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
                                    if(null !== nuvei_cc_card && typeof nuvei_cc_card == 'object') {
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
                        console.log(res, null, 'error');
                        self.isPlaceOrderActionAllowed(false);
                    }

                    $('.loading-mask').css('display', 'none');
                })
                .fail(function(e) {
                    console.log(e.responseText, null, 'error');
                    self.isPlaceOrderActionAllowed(false);
                });
            },
			
            placeOrder: function(data, event) {
                console.log('placeOrder()');
                
                if(self.chosenApmMethod() === '') {
                    console.log('chosenApmMethod is empty', null, 'error');
                    self.showGeneralError($.mage.__('Please, choose some of the available payment options!'));
                    
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

                nuveiShowLoader(); // show loader
				
                if (event) {
                    event.preventDefault();
                }
				
                jQuery.ajax({
                    dataType: 'json',
                    url: window.checkoutConfig.payment[nuveiGetCode()].getUpdateOrderUrl,
                    data: {
                        saveUpo: $('body').find('#nuvei_save_upo_cont input').val(),
                        pmType: self.typeOfChosenPayMethod()
                    }
                })
                .fail(function(){
                    self.validateOrderData();
                })
                .done(function(resp) {
                    console.log(resp);

                    if(resp.hasOwnProperty('sessionToken')
                        && '' != resp.sessionToken
                        && resp.sessionToken != scData.sessionToken
                    ) {
                        scData.sessionToken = resp.sessionToken;

                        sfc         = SafeCharge(scData);
                        scFields    = sfc.fields({
                            locale: checkoutConfig.payment[nuveiGetCode()].locale
                        });
                    }

                    self.validateOrderData();
                });
            },
            
            validateOrderData: function() {
                console.log('validateOrderData()');

                var payParams = {
                    sessionToken    : scData.sessionToken,
                    merchantId      : window.checkoutConfig.payment[nuveiGetCode()].merchantId,
                    merchantSiteId  : window.checkoutConfig.payment[nuveiGetCode()].merchantSiteId,
                    webMasterId     : window.checkoutConfig.payment[nuveiGetCode()].webMasterId,
                    apmWindowType   : window.checkoutConfig.payment[nuveiGetCode()].apmWindowType,
                    //env           : window.checkoutConfig.payment[nuveiGetCode()].isTestMode == true ? 'int' : 'prod'
                };
                
                // Apple Pay
                if(self.chosenApmMethod() === 'ppp_ApplePay') {
                    console.log('validateOrderData() ppp_ApplePay');
                    
                    if(typeof window.ApplePaySession != 'function') {
                        alert($.mage.__('Unexpected session error. Please, try different payment method!'));
                        nuveiHideLoader();

                        console.log('ApplePaySession is not a Function.', null, 'error')
                        return;
                    }

                    payParams.countryCode   = window.checkoutConfig.payment[nuveiGetCode()].countryId;
                    payParams.currencyCode  = window.checkoutConfig.payment[nuveiGetCode()].currencyCode;
//                    payParams.currencyCode  = quote.totals().base_currency_code;
                    payParams.amount        = self.scOrderTotal;
                    payParams.total         = {
                        label   : window.checkoutConfig.payment[nuveiGetCode()].applePayLabel,
                        amount  : self.scOrderTotal
                    };

                    console.log(payParams);

                    self.createPayment(payParams, true);
                    return;
                }
				
                // CC
                if(self.chosenApmMethod() === 'cc_card') {
                    console.log('validateOrderData() cc_card');
                    var anyErrors = false;
                    
                    if($('#nuvei_cc_owner').val() == '') {
                        $('#nuvei_cc_owner').addClass('nuvei_input_error');
                        anyErrors = true;
                    }
					
                    if( (!isCCNumEmpty && !isCCNumComplete) || isCCNumEmpty ) {
                        $('#sc_card_number').addClass('nuvei_input_error');
                        anyErrors = true;
                    }
					
                    if( (!isCVVEmpty && !isCVVComplete) || isCVVEmpty ) {
                        $('#sc_card_cvc').addClass('nuvei_input_error');
                        anyErrors = true;
                    }
					
                    if( (!isCCDateEmpty&& !isCCDateComplete) || isCCDateEmpty ) {
                        $('#sc_card_expiry').addClass('nuvei_input_error');
                        anyErrors = true;
                    }
                    
                    if (anyErrors) {
                        document.getElementById("nuvei_cc_card").scrollIntoView({behavior: 'smooth'});
                        nuveiHideLoader();
                        return;
                    }
					
                    if(!self.validate()) {
                        nuveiHideLoader();
                        return;
                    }

                    if(null == cardNumber) {
                        alert($.mage.__('Unexpected error! If the fields of the selected payment method do not reload in few seconds, please reload the page!'));
                        nuveiHideLoader();
                        return;
                    }
					
                    payParams.paymentOption		= cardNumber;
                    payParams.cardHolderName	= document.getElementById('nuvei_cc_owner').value;
                    payParams.savePm            = self.savePm();
                        
					
                    self.writeLog('payParams', payParams);
                    
                    // create payment with WebSDK
                    self.createPayment(payParams);
                    return;
                }
				
                // in case of CC UPO
                if(self.typeOfChosenPayMethod() === 'upo_cc') {
                    console.log('validateOrderData() upo_cc');
                    
                    // checks
                    if( (!isCVVEmpty && !isCVVComplete) || isCVVEmpty ) {
                        $('#sc_upo_'+ self.chosenApmMethod() +'_cvc').addClass('nuvei_input_error');

                        document.getElementById('sc_upo_'+ self.chosenApmMethod() +'_cvc').scrollIntoView();
                        nuveiHideLoader();

                        return;
                    }
					
                    if(!self.validate()) {
                        nuveiHideLoader();
                        return;
                    }
                    // checks END
					
                    payParams.userTokenId	= window.checkoutConfig.payment[nuveiGetCode()].userTokenId;
                    payParams.paymentOption	= {
                        userPaymentOptionId: self.chosenApmMethod(),
                        card: {
                            CVV: cardCvc
                        }
                    };

                    self.writeLog('payParams', payParams);
                    self.writeLog('payParams', payParams.paymentOption);

                    // create payment with WebSDK
                    self.createPayment(payParams);
                    return;
                }
                
                // in case of APM
                if(self.typeOfChosenPayMethod() === 'apm') {
                    console.log('validateOrderData() apm');
                    var showApmError = false;
                    
                    payParams.paymentOption = {
                        alternativePaymentMethod: {
                            paymentMethod: self.chosenApmMethod()
                        },
                        savePm: self.savePm()
                    };
                    
                    // check for empty fields
                    $('#nuvei_'+ self.chosenApmMethod())
                        .closest('.nuvei-apm-method-container')
                        .find('fieldset input')
                        .each(function() {
                            var currField = $(this);
                    
                            if(currField.val() == '') {
                                currField.addClass('nuvei_input_error');
                                showApmError = true;
                            }
                            else {
                                currField.removeClass('nuvei_input_error');
                                // add field name => value to the payParams
                                payParams.paymentOption.alternativePaymentMethod[currField.attr('name')] = currField.val();
                            }
                        });
                    
                    if(showApmError) {
                        document.getElementById('nuvei_'+ self.chosenApmMethod()).scrollIntoView({behavior: 'smooth'});
                        nuveiHideLoader();
                    }
                    else {
                        self.writeLog('payParams', payParams);
                        self.createPayment(payParams);
                    }
                    
                    return;
                }
                
                // UPO APM
                // get current UPO data
//                var currUpoData = {};
//                
//                console.log(self.upos);
//                
//                for(var i in self.upos) {
//                    if (self.upos[i].userPaymentOptionId == self.chosenApmMethod()) {
//                        currUpoData = self.upos[i].upoData;
//                        break;
//                    }
//                }
                
                payParams.paymentOption	= {
                    alternativePaymentMethod: {
                        paymentMethod: $('#nuvei_'+ self.chosenApmMethod()).attr('data-upo-name'),
//                        upoData: currUpoData
                    },
                    userPaymentOptionId: self.chosenApmMethod(),
                };
                
                console.log(payParams);
                
                self.createPayment(payParams);
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
                console.log('afterSdkResponse', resp, resp.result);

                // wrong response
                if(typeof resp == 'undefined' || !resp.hasOwnProperty('result')) {
                    // reload after click on alert button
                    if(!alert($.mage.__('Unexpected error, please try again later!'))) {
                        window.location.reload();
                        return;
                    }
                }
                
                // approve or pending
                if((resp.result == 'APPROVED' || resp.result == 'PENDING')
                    && typeof resp.transactionId != 'undefined'
                ) {
                    self.transactionId = resp.transactionId;
                    self.continueWithOrder(resp.transactionId);
                    return;
                }
                // decline
                if(resp.result == 'DECLINED') {
                    // reload after click on alert button
                    if(!alert($.mage.__('Your Payment was DECLINED. Please try another payment method!'))) {
                        nuveiHideLoader();
                        return;
                    }
                }
                
                // undefined error
                var respError = 'Error with your Payment. Please try again later!';

                if(resp.hasOwnProperty('errorDescription') && '' != resp.errorDescription) {
                    respError = resp.errorDescription;
                }
                else if(resp.hasOwnProperty('reason') && '' != resp.reason) {
                    respError = resp.reason;
                }

                console.log(resp, null, 'error');

                if(!alert($.mage.__(respError))) {
                    self.scCleanCard();
                    self.getApmMethods();
                    nuveiHideLoader();

                    return;
                }
            },
			
            continueWithOrder: function(transactionId) {
                console.log('continueWithOrder()', self.typeOfChosenPayMethod());

                // stop the proccess
                if(!self.validate()) {
                    console.log('validation error, stop the proccess');
                    
                    nuveiHideLoader();
                    return false;
                }

                // continue with the order
                self.isPlaceOrderActionAllowed(false);
                self.selectPaymentMethod();

                setPaymentMethodAction(self.messageContainer)
                    .done(function() {
                        window.location = window.checkoutConfig.payment[nuveiGetCode()].successUrl;
                        return;
                    }.bind(self));

                return true;
            },
            
            showGeneralError: function(msg) {
                jQuery('#nuvei_general_error .message div').html(jQuery.mage.__(msg));
                jQuery('#nuvei_general_error').show();
                document.getElementById("nuvei_general_error").scrollIntoView({behavior: 'smooth'});
            },
			
            nuveiInitFields: function() {
                console.log('nuveiInitFields()');

                if('nuvei' != self.scPaymentMethod) {
                    console.log('nuveiInitFields() - slected payment method is not Nuvei');
                    nuveiHideLoader();
                    return;
                }
				
                // for the Fields
                scData.merchantSiteId       = window.checkoutConfig.payment[nuveiGetCode()].merchantSiteId;
                scData.merchantId           = window.checkoutConfig.payment[nuveiGetCode()].merchantId;
                scData.sourceApplication    = window.checkoutConfig.payment[nuveiGetCode()].sourceApplication;
				
                if(window.checkoutConfig.payment[nuveiGetCode()].isTestMode == true) {
                    scData.env = 'int';
                }
                else {
                    scData.env = 'prod';
                }
				
                sfc = SafeCharge(scData);

                // prepare fields
                scFields = sfc.fields({
                    locale: checkoutConfig.payment[nuveiGetCode()].locale
                });
                
                // precheck the Save Upo checkbox
                if ('force' == window.checkoutConfig.payment[nuveiGetCode()].saveUpos
                    && $('#nuvei_save_upo_cont input').length > 0
                ) {
                    $('#nuvei_save_upo_cont input').val(1);
                    $('#nuvei_save_upo_cont input').prop('checked', true);
                }

                if( ( $('#sc_card_number').html() == '' || typeof $('#sc_card_number').html() == 'undefined' )
                    && ( $('#sc_card_expiry').html() == '' || typeof $('#sc_card_expiry').html() == 'undefined' )
                    && ( $('#sc_card_cvc').html() == '' || typeof $('#sc_card_cvc').html() == 'undefined' )
                    && lastCvcHolder !== ''
                ) {
                    self.attachFields();
                }
            },
			
            attachFields: function() {
                console.log('attachFields()', {
                    scFields: scFields,
                    lastCvcHolder: lastCvcHolder
                });
				
                if(null === scFields) {
                    console.log('scFields is null');
                    nuveiHideLoader();
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

                    nuveiHideLoader();
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

                    nuveiHideLoader();
                }
            },
			
            /**
              * Validate checkout agreements
             *
             * @returns {Boolean}
            */
            validate: function (hideError) {
                console.log('validate()');

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
                console.log('scCleanCard()');

                cardNumber = cardExpiry = cardCvc = null;
                $('#sc_card_number, #sc_card_expiry, #sc_card_cvc').html('');

                if(lastCvcHolder !== '') {
                    $(lastCvcHolder).html('');
                }
            },
			
            scBillingAddrChange: function() {
                console.log('scBillingAddrChange()');

                if(quote.billingAddress() == null) {
                    console.log('scBillingAddrChange() - the BillingAddr is null. Stop here.');
                    return;
                }

                if(quote.billingAddress().countryId == self.scBillingCountry) {
                    console.log('scBillingAddrChange() - the country is same. Stop here.');
                    return;
                }

                console.log('scBillingAddrChange() - the country was changed to', quote.billingAddress().countryId);
                self.scBillingCountry = quote.billingAddress().countryId;

                self.scCleanCard();
                self.getApmMethods(JSON.stringify(quote.billingAddress()));
            },
			
            scTotalsChange: function() {
                console.log(quote.totals(), 'scTotalsChange()');

                var currentTotal = parseFloat(quote.totals().base_grand_total).toFixed(2);

                if(currentTotal == self.scOrderTotal) {
                    console.log('scTotalsChange() - the total is same. Stop here.');
                    return;
                }

                console.log('scTotalsChange() - the total was changed to', currentTotal);
                self.scOrderTotal = currentTotal;

                self.scCleanCard();
                self.getApmMethods();
            },
			
            scPaymentMethodChange: function() {
                console.log('scPaymentMethodChange()', quote.paymentMethod._latestValue, self.scPaymentMethod);

                if(quote.paymentMethod._latestValue != null
                    && self.scPaymentMethod != quote.paymentMethod._latestValue.method
                ) {
                    console.log('new paymentMethod is', quote.paymentMethod._latestValue.method);

//                    self.scUpdateQuotePM();

                    self.scPaymentMethod = quote.paymentMethod._latestValue.method;

                    if('nuvei' == self.scPaymentMethod) {
                        console.log('sfc', sfc);

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
//                console.log('scUpdateQuotePM()');
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
//                    console.log('update quote payment method', quote.paymentMethod._latestValue.method);
//
//                    $.ajax(scAjaxQuoteUpdateParams)
//                        .done(function(resp) {})
//                        .fail(function(e) {
//                            console.log(e.responseText, null, 'error');
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
