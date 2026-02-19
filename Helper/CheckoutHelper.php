<?php

namespace Nuvei\Checkout\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\App\Helper\Context;
use Magento\Framework\UrlInterface;
use Nuvei\Checkout\Model\Config as ModuleConfig;
use Nuvei\Checkout\Model\Payment;
use Nuvei\Checkout\Model\PaymentsPlans;
use Nuvei\Checkout\Model\ReaderWriter;

/**
 * Just a helper class, to collect the data for the Simply Connect.
 *
 * @author Nuvei
 */
class CheckoutHelper extends AbstractHelper
{
    private $moduleConfig;
    private $nuveiPaymentPlans;
    private $readerWriter;
    private $urlBuilder;

    public function __construct(
        Context $context,
        ModuleConfig $moduleConfig,
        PaymentsPlans $nuveiPaymentPlans,
        ReaderWriter $readerWriter,
        UrlInterface $urlBuilder
    ) {
        $this->moduleConfig         = $moduleConfig;
        $this->nuveiPaymentPlans    = $nuveiPaymentPlans;
        $this->readerWriter         = $readerWriter;
        $this->urlBuilder           = $urlBuilder;

        parent::__construct($context);
    }

    /**
     * @param bool $returnSdkBlockOnly We will pass true only when need the configuration from the headless implementation.In this case it will return only the part for the SDK - $config['payment'][Payment::METHOD_CODE]['nuveiCheckoutParams']. 
     * @return array
     */
    public function getCheckoutSdkConfig($returnSdkBlockOnly = false)
    {
        $this->readerWriter->createLog('getCheckoutSdkConfig()');
        
        $locale = $this->scopeConfig->getValue(
            'general/locale/code',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );

        $blocked_cards      = $this->getBlockedCards();
        $blocked_pms        = $this->moduleConfig->getConfigValue('block_pms', 'checkout');
        $is_user_logged     = $this->moduleConfig->isUserLogged();
        $billing_address    = $this->moduleConfig->getQuoteBillingAddress();
        $payment_plan_data  = $this->nuveiPaymentPlans->getProductPlanData();
        $isPaymentPlan      = !empty($payment_plan_data) ? true : false;
        $show_upos          = ($is_user_logged && $this->moduleConfig->canShowUpos()) ? true : false;
        $save_pm            = $this->moduleConfig->getSaveUposSetting($isPaymentPlan);
        $total              = $this->moduleConfig->getQuoteBaseTotal();
        $useDCC             = $this->moduleConfig->getConfigValue('use_dcc');
        $locale             = substr($locale, 0, 2);
		$sdkStyle			= (string) $this->moduleConfig->getConfigValue('sdk_style', 'basic');
        $checkoutSession    = $this->moduleConfig->getCheckoutSession();

		if (!is_string($sdkStyle)) {
			$sdkStyle = '';
		}

        if ($total == 0) {
            $useDCC = 'false';
        }

        $googlePaySettings = [
            'locale' => $locale
        ];

        if (!empty($gMerchantId = trim((string) $this->moduleConfig->getConfigValue('gpay_merchant_id')))) {
            $googlePaySettings['merchantId'] = $gMerchantId;
        }
        if (!empty($gButtonColor = $this->moduleConfig->getConfigValue('gpay_button_color'))) {
            $googlePaySettings['buttonColor'] = $gButtonColor;
        }
        if (!empty($gButtonType = $this->moduleConfig->getConfigValue('gpay_button_type'))) {
            $googlePaySettings['buttonType'] = $gButtonType;
        }

        $config = [
            'payment' => [
                Payment::METHOD_CODE => [
                    'cartUrl'               => $this->urlBuilder->getUrl('checkout/cart/'),
                    'checkoutFormAction'	=> $this->moduleConfig->getCallbackSuccessUrl(),
                    'getUpdateOrderUrl'     => $this->urlBuilder->getUrl('nuvei_checkout/payment/OpenOrder'),
                    'isPaymentPlan'         => $isPaymentPlan,
                    'reservedOrderId'       => $checkoutSession->getQuote()->getReservedOrderId(),
                    'unexpectedErrorMsg'    => __('Unexpected error. Please try again later!'),
                    'missingOrderIdMsg'		=> __('Order ID is missing. Please, submit the Order using "Place Order" button!'),

                    // we will set some of the parameters in the JS file
                    'nuveiCheckoutParams' => [
                        'env'                       => $this->moduleConfig->isTestModeEnabled() ? 'test' : 'prod',
                        'merchantId'                => $this->moduleConfig->getMerchantId(),
                        'merchantSiteId'            => $this->moduleConfig->getMerchantSiteId(),
                        'country'                   => $billing_address['country'],
                        'currency'                  => $this->moduleConfig->getQuoteBaseCurrency(),
                        'amount'                    => $total,
                        'renderTo'                  => '#nuvei_checkout',
                        'useDCC'                    =>  $useDCC,
                        'strict'                    => false,
                        'savePM'                    => $save_pm,
                        'showUserPaymentOptions'    => $show_upos,
        //                        'pmBlacklist'               => $this->moduleConfig->getConfigValue('block_pms', 'advanced'),
        //                        'pmWhitelist'               => null,
                        'blockCards'                => $blocked_cards,
                        'alwaysCollectCvv'          => true,
                        'fullName'                  => trim((string) $billing_address['firstName'] . ' '
                            . (string) $billing_address['lastName']),
                        'email'                     => $billing_address['email'],
                        'payButton'                 => $this->moduleConfig->getConfigValue('pay_btn_text'),
                        'showResponseMessage'       => false, // shows/hide the response popups
                        'locale'                    => $locale,
                        'webMasterId'               => $this->moduleConfig->getSourcePlatformField(),
                        'autoOpenPM'                => (bool) $this->moduleConfig->getConfigValue('auto_expand_pms'),
                        'logLevel'                  => $this->moduleConfig->getConfigValue('checkout_log_level'),
                        'maskCvv'                   => true,
                        'i18n'                      => $this->moduleConfig->getCheckoutTransl(),
                        'theme'                     => $this->moduleConfig->getConfigValue('sdk_theme', 'checkout'),
                        'apmWindowType'             => $this->moduleConfig->getConfigValue('apm_window_type', 'checkout'),
                        'apmConfig'                 => [
                            'googlePay' => $googlePaySettings,
                            'applePay'  => array(
                                'locale'    => $locale,
                            ),
                        ],
                        'sourceApplication'         => $this->moduleConfig->getSourceApplication(),
                        'fieldStyle'				=> json_decode($sdkStyle, true),
                    ],
                ],
            ],
        ];

        if (!empty($blocked_pms) && null !== $blocked_pms) {
            $config['payment'][Payment::METHOD_CODE]['nuveiCheckoutParams']['pmBlacklist'] = explode(',', $blocked_pms);
        }

        if ($isPaymentPlan
            // for zero-total and enabled Nuvei GW
            || ( 0 == $total && $this->moduleConfig->getConfigValue('allow_zero_total') )
        ) {
            $config['payment'][Payment::METHOD_CODE]['nuveiCheckoutParams']['pmBlacklist'] = null;
            $config['payment'][Payment::METHOD_CODE]['nuveiCheckoutParams']['pmWhitelist'] = ['cc_card'];
        }

        if (in_array($save_pm, [true, 'always'])) {
            $config['payment'][Payment::METHOD_CODE]['nuveiCheckoutParams']['userTokenId']
                = $config['payment'][Payment::METHOD_CODE]['nuveiCheckoutParams']['email'];
        }


        if ($returnSdkBlockOnly) {
            return $config['payment'][Payment::METHOD_CODE]['nuveiCheckoutParams'];
        }

        return $config;
    }

    /**
     * Just a helper function.
     *
     * @return array
     */
    private function getBlockedCards()
    {
        $blocked_cards     = [];
        $blocked_cards_str = $this->moduleConfig->getConfigValue('block_cards', 'advanced');

        // clean the string from brakets and quotes
        if (!empty($blocked_cards_str)) {
            $blocked_cards_str = str_replace('],[', ';', $blocked_cards_str);
            $blocked_cards_str = str_replace('[', '', $blocked_cards_str);
            $blocked_cards_str = str_replace(']', '', $blocked_cards_str);
            $blocked_cards_str = str_replace('"', '', $blocked_cards_str);
            $blocked_cards_str = str_replace("'", '', $blocked_cards_str);
        }

        if (!empty($blocked_cards_str)) {
            $blockCards_sets = explode(';', $blocked_cards_str);

            if (count($blockCards_sets) == 1) {
                $blocked_cards = explode(',', current($blockCards_sets));
            } else {
                foreach ($blockCards_sets as $elements) {
                    $blocked_cards[] = explode(',', $elements);
                }
            }
        }

        return $blocked_cards;
    }

}
