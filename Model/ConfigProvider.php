<?php

namespace Nuvei\Checkout\Model;

use Magento\Framework\UrlInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\CcConfig;
use Magento\Payment\Model\CcGenericConfigProvider;
use Nuvei\Checkout\Model\Config as ModuleConfig;

/**
 * Nuvei Checkout config provider model.
 */
class ConfigProvider extends CcGenericConfigProvider
{
    private $moduleConfig;
    private $urlBuilder;
    private $apmsRequest;
    private $scopeConfig;
    private $assetRepo;
    private $paymentsPlans;
    private $readerWriter;
    private $locale;
    private $config; // the config for the SDK
    private $isPaymentPlan;

    /**
     * ConfigProvider constructor.
     *
     * @param CcConfig              $ccConfig
     * @param PaymentHelper         $paymentHelper
     * @param Config                $moduleConfig
     * @param UrlInterface          $urlBuilder
     * @param ScopeConfigInterface  $scopeConfig
     * @param Repository            $assetRepo
     * @param PaymentsPlans         $paymentsPlans
     * @param ReaderWriter          $readerWriter
     */
    public function __construct(
        CcConfig $ccConfig,
        PaymentHelper $paymentHelper,
        ModuleConfig $moduleConfig,
        UrlInterface $urlBuilder,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Nuvei\Checkout\Model\PaymentsPlans $paymentsPlans,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        array $methodCodes
    ) {
        $this->moduleConfig     = $moduleConfig;
        $this->urlBuilder       = $urlBuilder;
        $this->scopeConfig      = $scopeConfig;
        $this->assetRepo        = $assetRepo;
        $this->paymentsPlans    = $paymentsPlans;
        $this->readerWriter     = $readerWriter;

        $methodCodes = array_merge_recursive(
            $methodCodes,
            [Payment::METHOD_CODE]
        );

        parent::__construct(
            $ccConfig,
            $paymentHelper,
            $methodCodes
        );
    }

    /**
     * Return config array.
     *
     * @return array
     */
    public function getConfig()
    {
        if (!$this->moduleConfig->getConfigValue('active')) {
            $this->readerWriter->createLog('Mudule is not active');
            
            return [
                'payment' => [
                    Payment::METHOD_CODE => [
                        'isActive' => 0,
                    ],
                ],
            ];
        }
        
        $this->locale = $this->scopeConfig->getValue(
            'general/locale/code',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        
        $used_sdk = $this->moduleConfig->getUsedSdk();
        
        switch ($used_sdk) {
            case 'checkout':
                $config = $this->getCheckoutSdkConfig();
                break;
            
            case 'web':
                $config = $this->getWebSdkConfig();
                break;
            
            default:
                $config = [];
        }
        
        // will be concatenated into a JS
        $config['payment'][Payment::METHOD_CODE]['sdk']         = ucfirst($used_sdk);
        $config['payment'][Payment::METHOD_CODE]['isTestMode']  = $this->moduleConfig->isTestModeEnabled();
        $config['payment'][Payment::METHOD_CODE]['countryId']   = $this->moduleConfig->getQuoteCountryCode();
        $config['payment'][Payment::METHOD_CODE]['loadingImg']  = $this->assetRepo->getUrl("Nuvei_Checkout::images/loader-2.gif");
        
        $this->readerWriter->createLog([$used_sdk, $config], 'get front end config');
        
        return $config;
    }
    
    private function getCheckoutSdkConfig()
    {
        $this->readerWriter->createLog('getCheckoutSdkConfig()');
        
        $blocked_cards      = $this->getBlockedCards();
        $blocked_pms        = $this->moduleConfig->getConfigValue('block_pms', 'checkout');
        $is_user_logged     = $this->moduleConfig->isUserLogged();
        $billing_address    = $this->moduleConfig->getQuoteBillingAddress();
        $payment_plan_data  = $this->paymentsPlans->getProductPlanData();
        $isPaymentPlan      = !empty($payment_plan_data) ? true : false;
        $show_upos          = ($is_user_logged && $this->moduleConfig->canShowUpos()) ? true : false;
        $save_pm            = $this->moduleConfig->getSaveUposSetting($isPaymentPlan);
        $total              = $this->moduleConfig->getQuoteBaseTotal();
        $useDCC             = $this->moduleConfig->getConfigValue('use_dcc');
        
        if ($total == 0) {
            $useDCC = 'false';
        }
        
        $config = [
            'payment' => [
                Payment::METHOD_CODE => [
                    'cartUrl'                   => $this->urlBuilder->getUrl('checkout/cart/'),
                    'getUpdateOrderUrl'         => $this->urlBuilder->getUrl('nuvei_checkout/payment/OpenOrder'),
                    'isPaymentPlan'             =>$isPaymentPlan,
                    
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
                        'fullName'                  => trim($billing_address['firstName'] . ' ' . $billing_address['lastName']),
                        'email'                     => $billing_address['email'],
                        'payButton'                 => $this->moduleConfig->getConfigValue('pay_btn_text'),
                        'showResponseMessage'       => false, // shows/hide the response popups
                        'locale'                    => substr($this->locale, 0, 2),
                        'autoOpenPM'                => (bool) $this->moduleConfig->getConfigValue('auto_expand_pms'),
                        'logLevel'                  => $this->moduleConfig->getConfigValue('checkout_log_level'),
                        'maskCvv'                   => true,
                        'i18n'                      => $this->moduleConfig->getCheckoutTransl(),
                        'theme'                     => $this->moduleConfig->getConfigValue('sdk_theme', 'checkout'),
                        'apmWindowType'             => $this->moduleConfig->getConfigValue('apm_window_type', 'checkout'),
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
        
        return $config;
    }
    
    private function getWebSdkConfig()
    {
        $this->readerWriter->createLog('getWebSdkConfig()');
        
        $userTokenId            = '';
        $payment_plan_data      = $this->paymentsPlans->getProductPlanData();
        $isPaymentPlan    = !empty($payment_plan_data) ? true : false;
        
        $config = [
            'payment' => [
                Payment::METHOD_CODE => [
                    'getMerchantPaymentMethodsUrl' => $this->urlBuilder
                        ->getUrl('nuvei_checkout/payment/GetMerchantPaymentMethods'),
                    
                    'successUrl'            => $this->moduleConfig->getCallbackSuccessUrl(),
                    'errorUrl'              => $this->moduleConfig->getCallbackErrorUrl(),
                    'redirectUrl'           => $this->urlBuilder->getUrl('nuvei_checkout/payment/redirect'),
                    'paymentApmUrl'         => $this->urlBuilder->getUrl('nuvei_checkout/payment/apm'),
                    'getUPOsUrl'            => $this->urlBuilder->getUrl('nuvei_checkout/payment/GetUpos'),
                    'getUpdateOrderUrl'     => $this->urlBuilder->getUrl('nuvei_checkout/payment/OpenOrder'),
                    'getRemoveUpoUrl'       => $this->urlBuilder->getUrl('nuvei_checkout/payment/DeleteUpo'),
                    'checkoutApplePayBtn'   => $this->assetRepo->getUrl("Nuvei_Checkout::images/ApplePay-Button.png"),
                    'showUpos'              => ($this->moduleConfig->canShowUpos() && $this->moduleConfig->isUserLogged()),
                    'saveUpos'              => $this->moduleConfig->getSaveUposSetting($isPaymentPlan),
                    // we need this for the WebSDK
                    'merchantSiteId'        => $this->moduleConfig->getMerchantSiteId(),
                    'merchantId'            => $this->moduleConfig->getMerchantId(),
                    'locale'                => substr($this->locale, 0, 2),
                    'webMasterId'           => $this->moduleConfig->getSourcePlatformField(),
                    'sourceApplication'     => $this->moduleConfig->getSourceApplication(),
                    'userTokenId'           => $this->moduleConfig->getQuoteBillingAddress()['email'],
                    'applePayLabel'         => $this->moduleConfig->getConfigValue('apple_pay_label', 'web_sdk'),
                    'currencyCode'          => $this->moduleConfig->getQuoteBaseCurrency(), 
//                    'apmWindowType'         => $this->moduleConfig->getConfigValue('apm_window_type'),
                ],
            ],
        ];
        
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
