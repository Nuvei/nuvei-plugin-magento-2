<?php

namespace Nuvei\Checkout\Model\Api;

use Nuvei\Checkout\Api\GetCheckoutDataInterface;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\Config;

/**
 * @author Nuvei
 */
class GetCheckoutData implements GetCheckoutDataInterface
{
    private $readerWriter;
    private $requestFactory;
    private $moduleConfig;
    private $jsonResultFactory;
    private $scopeConfig;
    private $apiRequest;
    private $paymentsPlans;
    
    public function __construct(
        Config $moduleConfig,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory,
        \Magento\Framework\Webapi\Rest\Request $apiRequest,
        \Nuvei\Checkout\Model\PaymentsPlans $paymentsPlans,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        \Nuvei\Checkout\Model\Request\Factory $requestFactory,
    ) {
        $this->readerWriter         = $readerWriter;
        $this->moduleConfig         = $moduleConfig;
        $this->requestFactory       = $requestFactory;
        $this->jsonResultFactory    = $jsonResultFactory;
        $this->scopeConfig          = $scopeConfig;
        $this->apiRequest           = $apiRequest;
        $this->paymentsPlans        = $paymentsPlans;
    }
    
    /**
     * @param int $quoteId
     * @param string $neededData Available options - NUVEI_REST_API_PLUGIN_METHODS
     * 
     * @return string
     */
    public function getData($quoteId, $neededData)
    {
        $this->readerWriter->createLog([
            $quoteId, 
            $neededData, 
            $this->apiRequest->getBodyParams()
        ]);
        
//        $result = $this->jsonResultFactory->create()
//            ->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
        
        # check for errors
        if (!$this->moduleConfig->getConfigValue('active')) {
            $msg = 'Mudule is not active.';
            $this->readerWriter->createLog($msg);
            
            return [
                'message' => $msg,
            ];
        }
        
        if (empty($quoteId)) {
            $msg = 'Quote ID is empty.';
            $this->readerWriter->createLog($msg);
            
            return [
                'message' => $msg,
            ];
        }
        
        if (!in_array($neededData, Config::NUVEI_REST_API_PLUGIN_METHODS)) {
            $msg = 'Passed neededData parameter is not recognized.';
            $this->readerWriter->createLog($msg);
            
            return [
                'message' => $msg,
            ];
        }
        # /check for errors
        
        $method = $this->getMethodName($neededData);
        $resp   = $this->$method($quoteId);
        
        $this->readerWriter->createLog($resp);
        return json_encode($resp);
    }
    
    /**
     * @param string $neededData The incoming neededData parameter
     * @return string
     */
    private function getMethodName($neededData)
    {
        $name_parts = explode('-', $neededData);
        
        return lcfirst(
            implode(
                array_map(
                    function ($elem) {
                        return ucfirst($elem);
                    },
                    $name_parts
                )
            )
        );
    }
    
    /**
     * Collect and return the data for WebSDK (fields) implementation
     * 
     * @param int $quoteId
     * @return array
     */
    private function getWebSdkData($quoteId)
    {
        # 1. Open new order
        $webApiparams   = $this->apiRequest->getBodyParams();
        $isUserLogged   = isset($webApiparams['isUserLogged']) ? (bool) $webApiparams['isUserLogged'] : false;
        $oo_data        = $this->openOrder($quoteId, $isUserLogged, 'webSdk'); // get an array
        
        // in case of error
        if (!empty($oo_data['message'])) {
            return $oo_data;
        }
        # /1. Open new order
        
        # 2. Get merchant APMs
        $apmMethods     = [];
        $request        = $this->requestFactory->create(AbstractRequest::GET_MERCHANT_PAYMENT_METHODS_METHOD);
        $billingAddress = $this->moduleConfig->getQuoteBillingAddress($quoteId);
        
        $apmsResp = $request
            ->setBillingAddress(json_encode($billingAddress))
            ->setSessionToken($oo_data['sessionToken'])
            ->setQuoteId($quoteId)
            ->process();
        
        if (empty($apmsResp['paymentMethods'])) {
            return [
                'message' => __('Missing APM methods.'),
            ];
        }
        
        $useCcOnly  = false;
        $apmMethods = $apmsResp['paymentMethods'];
        
        // when there is subsData only CC allowed
        if (!empty($oo_data['subsData'])) {
            $useCcOnly = true;
            
            foreach ($apmsResp['paymentMethods'] as $key => $apmData) {
                if ('cc_card' == $apmData['paymentMethod']) {
                    $apmMethods     = [];
                    $apmMethods[]   = $apmsResp['paymentMethods'][$key];
                    break;
                }
            }
        }
        # /2. Get merchant APMs
        
        # 3. Optionally get merchant UPOs
        $upos = [];
        
        if ($this->moduleConfig->canShowUpos() && $isUserLogged) {
            $request    = $this->requestFactory->create(AbstractRequest::GET_UPOS_METHOD);
            $upos       = $request
                ->setEmail($billingAddress['email'])
                ->process();
            
            if ($useCcOnly) {
                foreach ($upos as $key => $upoData) {
                    if ('cc_card' != $upoData['paymentMethodName']) {
                        unset($upos[$key]);
                    }
                }
            }
        }
        # /3. Optionally get merchant UPOs
        
        # 4. Prepare WebSDK data
        $locale = $this->scopeConfig->getValue(
            'general/locale/code',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        
        $sdk_data = [
            'sessionToken'      => $oo_data['sessionToken'],
            'total'             => $oo_data['amount'],
            'apms'              => $apmMethods,
            'upos'              => $upos,
//            'getRemoveUpoUrl'           => $this->urlBuilder->getUrl('nuvei_payments/payment/DeleteUpo'),
            'countryId'         => $this->moduleConfig->getQuoteCountryCode($quoteId),
            'useUPOs'           => $this->moduleConfig->getSaveUposSetting(empty($oo_data['subsData']) ? false : true),
            // we need this for the WebSDK
            'merchantSiteId'    => $this->moduleConfig->getMerchantSiteId(),
            'merchantId'        => $this->moduleConfig->getMerchantId(),
            'isTestMode'        => $this->moduleConfig->isTestModeEnabled(),
            'locale'            => substr($locale, 0, 2),
            'webMasterId'       => $this->moduleConfig->getSourcePlatformField(),
            'sourceApplication' => $this->moduleConfig->getSourceApplication(),
            'userTokenId'       => $isUserLogged ? $billingAddress['email'] : '',
//            'applePayLabel'             => $this->moduleConfig->getMerchantApplePayLabel(),
            'currencyCode'      => $this->moduleConfig->getQuoteBaseCurrency($quoteId),
        ];
        
        // return the data
        return $sdk_data;
    }
    
    /**
     * Collect and return the data for SimplyConnect (checkouSDK) implementation
     * 
     * @param int $quoteId
     * @return array
     */
    private function getSimplyConnectData($quoteId)
    {
        # 1. Open new order
        $webApiparams   = $this->apiRequest->getBodyParams();
        $isUserLogged   = isset($webApiparams['isUserLogged']) ? (bool) $webApiparams['isUserLogged'] : false;
        $oo_data        = $this->openOrder($quoteId, $isUserLogged, 'simplyConnect'); // get an array
        
        // in case of error
        if (!empty($oo_data['message'])) {
            return $oo_data;
        }
        # /1. Open new order
        
        # 2. Prepare checkoutSDK data
        $locale = $this->scopeConfig->getValue(
            'general/locale/code',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
        
        // blocked_cards
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
        // /blocked_cards END
        
        $isTestMode         = $this->moduleConfig->isTestModeEnabled();
        $billingAddress     = $this->moduleConfig->getQuoteBillingAddress($quoteId);
        $payment_plan_data  = $this->paymentsPlans->getProductPlanData();
        $isPaymentPlan      = empty($payment_plan_data) ? false : true;
        $save_pm            = $this->moduleConfig->getSaveUposSetting($isPaymentPlan);
        
        $sdk_data = [
            'sessionToken'          => $oo_data['sessionToken'],
            'isTestMode'            => $isTestMode,
            'countryId'             => $this->moduleConfig->getQuoteCountryCode($quoteId),
            'isPaymentPlan'         => $isPaymentPlan,
            'nuveiCheckoutParams'   => [
                'env'                       => $isTestMode ? 'test' : 'prod',
                'merchantId'                => $this->moduleConfig->getMerchantId(),
                'merchantSiteId'            => $this->moduleConfig->getMerchantSiteId(),
                'country'                   => $billingAddress['country'],
                'currency'                  => $this->moduleConfig->getQuoteBaseCurrency($quoteId),
                'amount'                    => $this->moduleConfig->getQuoteBaseTotal($quoteId),
                'renderTo'                  => '#nuvei_checkout',
                'useDCC'                    =>  $this->moduleConfig->getConfigValue('use_dcc'),
                'strict'                    => false,
                'savePM'                    => $save_pm,
                'showUserPaymentOptions'    => ($isUserLogged && $this->moduleConfig->canShowUpos()) ? true : false,
                'alwaysCollectCvv'          => true,
                'fullName'                  => trim($billingAddress['firstName'] . ' ' . $billingAddress['lastName']),
                'email'                     => $billingAddress['email'],
                'payButton'                 => $this->moduleConfig->getConfigValue('pay_btn_text'),
                'showResponseMessage'       => false, // shows/hide the response popups
                'locale'                    => substr($locale, 0, 2),
                'autoOpenPM'                => (bool) $this->moduleConfig->getConfigValue('auto_expand_pms'),
                'logLevel'                  => $this->moduleConfig->getConfigValue('checkout_log_level'),
                'maskCvv'                   => true,
                'i18n'                      => $this->moduleConfig->getCheckoutTransl(),
                'blockCards'                => $blocked_cards,
                'theme'                     => $this->moduleConfig->getConfigValue('sdk_theme', 'checkout'),
                'apmWindowType'             => $this->moduleConfig->getConfigValue('apm_window_type', 'checkout'),
            ],
        ];
        
        if ($isPaymentPlan) {
            $sdk_data['nuveiCheckoutParams']['pmBlacklist'] = null;
            $sdk_data['nuveiCheckoutParams']['pmWhitelist'] = ['cc_card'];
        }
        
        if (in_array($save_pm, [true, 'always'])) {
            $sdk_data['nuveiCheckoutParams']['userTokenId'] = $sdk_data['nuveiCheckoutParams']['email'];
        }
        
        return $sdk_data;
    }
    
    /**
     * Collect and return the data for success APM redirect.
     * Expected parameters: chosenApmMethod, 
     * 
     * @param int $quoteId
     * @return array
     */
    private function getApmRedirectUrl($quoteId)
    {
        $params = $this->apiRequest->getBodyParams();
        
//        $urlDetails         = $params['urlDetails'] ?? [];
        
//        if (isset($params['urlDetails'])) {
//            $urlDetails = $params['urlDetails'];
//            unset($params['urlDetails']);
//        }
        
//        $this->readerWriter->createLog([$params, $urlDetails]);
        $this->readerWriter->createLog($params);
        
        $request    = $this->requestFactory->create(AbstractRequest::PAYMENT_APM_METHOD);
        $response   = $request
//            ->setPaymentMethod(empty($params["chosenApmMethod"]) ? '' : $params["chosenApmMethod"])
            ->setPaymentMethod($params['chosenApmMethod'] ?? '')
//            ->setSavePaymentMethod(empty($params["savePm"]) ? 0 : (int) $params["savePm"])
            ->setSavePaymentMethod($params["savePm"] ?? 0)
//            ->setPaymentMethodFields($params)
            ->setPaymentMethodFields($params['apmMethodFields'] ?? [])
            ->setQuoteId($quoteId)
//            ->setUrlDetails($urlDetails)
            ->setUrlDetails($params['urlDetails'] ?? [])
            ->process();
        
        if (empty($response['redirectUrl'])) {
            if (!empty($response['message'])) {
                return [
                    'message' => $response['message'],
                ];
            }
            
            return [
                'message' => $response['status'],
            ];
        }
        
        return [
            "redirectUrl" => $response['redirectUrl'],
        ];
    }
    
    /**
     * @param int $quoteId
     * @return array
     */
    private function updateOrder($quoteId)
    {
        # 1. Open new order
        $request    = $this->requestFactory->create(AbstractRequest::OPEN_ORDER_METHOD);
        $ooResp     = $request
            ->setQuoteId($quoteId)
            ->process();
        
        // some error
        if (empty($ooResp->sessionToken)) {
            if (isset($ooResp->error, $ooResp->reason) && 1 == $ooResp->error) {
                return [
                    'message' => $ooResp->reason,
                ];
            }
            
            return [
                'message' => __('Unexpected error.'),
            ];
        }
        
        // success
        return [
            'sessionToken' => $ooResp->sessionToken
        ];
    }
    
    /**
     * Just a repeating part of code for WebSDK and checkoutSDK.
     * 
     * @param int $quoteId
     * @param bool $isUserLogged
     * 
     * @return array
     */
    private function openOrder($quoteId, $isUserLogged, $callerSdk)
    {
        $request    = $this->requestFactory->create(AbstractRequest::OPEN_ORDER_METHOD);
        $ooResp     = $request
            ->setIsUserLogged($isUserLogged)
            ->setQuoteId($quoteId)
            ->setCallerSdk($callerSdk)
            ->process();
        
        // some error
        if (empty($ooResp->sessionToken)) {
            if (isset($ooResp->error, $ooResp->reason) && 1 == $ooResp->error) {
                return [
                    'message' => $ooResp->reason,
                ];
            }
            
            return [
                'message' => __('Unexpected error.'),
            ];
        }
        
        // success
        return [
            'sessionToken'  => $ooResp->sessionToken,
            'amount'        => $ooResp->ooAmount,
            'subsData'      => $ooResp->subsData,
        ];
    }
    
}
