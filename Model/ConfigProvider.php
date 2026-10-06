<?php

namespace Nuvei\Checkout\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Asset\Repository as AssetRepository;
use Nuvei\Checkout\Helper\CheckoutHelper;
use Nuvei\Checkout\Model\Config as ModuleConfig;
use Nuvei\Checkout\Model\PaymentsPlans;
use Nuvei\Checkout\Model\ReaderWriter;

/**
 * Nuvei Checkout config provider model.
 */
class ConfigProvider implements ConfigProviderInterface
{
    private $moduleConfig;
    private $urlBuilder;
    private $apmsRequest;
    private $scopeConfig;
    private $assetRepo;
    private $paymentsPlans;
    private $readerWriter;
    private $config; // the config for the SDK
    private $isPaymentPlan;
    private $checkoutHelper;

    /**
     * ConfigProvider constructor.
     *
     * @param ModuleConfig          $moduleConfig
     * @param UrlInterface          $urlBuilder
     * @param ScopeConfigInterface  $scopeConfig
     * @param AssetRepository       $assetRepo
     * @param PaymentsPlans         $paymentsPlans
     * @param ReaderWriter          $readerWriter
     * @param CheckoutHelper        $checkoutHelper
     */
    public function __construct(
        ModuleConfig $moduleConfig,
        UrlInterface $urlBuilder,
        ScopeConfigInterface $scopeConfig,
        AssetRepository $assetRepo,
        PaymentsPlans $paymentsPlans,
        ReaderWriter $readerWriter,
        CheckoutHelper $checkoutHelper
    ) {
        $this->moduleConfig     = $moduleConfig;
        $this->urlBuilder       = $urlBuilder;
        $this->scopeConfig      = $scopeConfig;
        $this->assetRepo        = $assetRepo;
        $this->paymentsPlans    = $paymentsPlans;
        $this->readerWriter     = $readerWriter;
        $this->checkoutHelper   = $checkoutHelper;
    }

    /**
     * Return config array.
     *
     * @param string $requiredConfig The name of the used SDK. We will pass this parameter when call this method from another class.
     * @return array
     */
    public function getConfig($requiredConfig = '')
    {
        // error
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
        
        $this->readerWriter->createLog($requiredConfig, '$requiredConfig');
        
        $usedSdk            = $this->moduleConfig->getUsedSdk();
        $returnSdkBlockOnly = false;
        $config             = [];
                
        if (!empty($requiredConfig)) {
            $usedSdk            = $requiredConfig;
            $returnSdkBlockOnly = true;
        }
        
        switch ($usedSdk) {
            case 'checkout':
                $config = $this->checkoutHelper->getCheckoutSdkConfig($returnSdkBlockOnly);
                break;

            // deprecated
            case 'web':
                $config = $this->getWebSdkConfig($returnSdkBlockOnly);
                break;
        }
        
        // will be concatenated into a JS
        if (!$returnSdkBlockOnly) {
            $config['payment'][Payment::METHOD_CODE]['sdk']         = ucfirst($usedSdk);
            $config['payment'][Payment::METHOD_CODE]['isTestMode']  = $this->moduleConfig->isTestModeEnabled();
            $config['payment'][Payment::METHOD_CODE]['countryId']   = $this->moduleConfig->getQuoteCountryCode();
            $config['payment'][Payment::METHOD_CODE]['loadingImg']  
                = $this->assetRepo->getUrl("Nuvei_Checkout::images/loader-2.gif");
        }
        
        $this->readerWriter->createLog([$usedSdk, $config], 'get front end config');
        
        return $config;
    }
    
    /**
     * @param bool $returnSdkBlockOnly  If it is true return only the part for the SDK - $config['payment'][Payment::METHOD_CODE]. We will pass true only when need the configuration from the headless implementation.
     * @return array
     * 
     * @deprecated sice 3.5.0
     */
    private function getWebSdkConfig($returnSdkBlockOnly)
    {
        $this->readerWriter->createLog('getWebSdkConfig()');
        
        $userTokenId        = '';
        $payment_plan_data  = $this->paymentsPlans->getProductPlanData();
        $isPaymentPlan      = !empty($payment_plan_data) ? true : false;
        $sdkStyle			= (string) $this->moduleConfig->getConfigValue('sdk_style', 'basic');
        $locale             = $this->scopeConfig->getValue(
            'general/locale/code',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        
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
                    'locale'                => substr($locale, 0, 2),
                    'webMasterId'           => $this->moduleConfig->getSourcePlatformField(),
                    'sourceApplication'     => $this->moduleConfig->getSourceApplication(),
                    'userTokenId'           => $this->moduleConfig->getQuoteBillingAddress()['email'],
                    'applePayLabel'         => $this->moduleConfig->getConfigValue('apple_pay_label', 'web_sdk'),
                    'currencyCode'          => $this->moduleConfig->getQuoteBaseCurrency(), 
					'style'					=> json_decode($sdkStyle, true),
                ],
            ],
        ];
        
        if ($returnSdkBlockOnly) {
            return $config['payment'][Payment::METHOD_CODE];
        }
        
        return $config;
    }
    
}