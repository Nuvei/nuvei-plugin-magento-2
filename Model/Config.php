<?php

namespace Nuvei\Checkout\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Module\ModuleListInterface;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Nuvei Checkout config model.
 */
class Config
{
    const MODULE_NAME                           = 'Nuvei_Checkout';
    
    const PAYMENT_PLANS_ATTR_NAME               = 'nuvei_payment_plans';
    const PAYMENT_PLANS_ATTR_LABEL              = 'Nuvei Payment Plans';
    const PAYMENT_PLANS_FILE_NAME               = 'nuvei_payment_plans.json';
    
    const PAYMENT_SUBS_GROUP                    = 'Nuvei Subscription';
    
    const PAYMENT_SUBS_ENABLE_LABEL             = 'Enable Subscription';
    const PAYMENT_SUBS_ENABLE                   = 'nuvei_sub_enabled';
    
    const PAYMENT_SUBS_INTIT_AMOUNT_LABEL       = 'Initial Amount';
    const PAYMENT_SUBS_INTIT_AMOUNT             = 'nuvei_sub_init_amount';
    const PAYMENT_SUBS_REC_AMOUNT_LABEL         = 'Recurring Amount';
    const PAYMENT_SUBS_REC_AMOUNT               = 'nuvei_sub_rec_amount';
    
    const PAYMENT_SUBS_RECURR_UNITS             = 'nuvei_sub_recurr_units';
    const PAYMENT_SUBS_RECURR_UNITS_LABEL       = 'Recurring Units';
    const PAYMENT_SUBS_RECURR_PERIOD            = 'nuvei_sub_recurr_period';
    const PAYMENT_SUBS_RECURR_PERIOD_LABEL      = 'Recurring Period';
    
    const PAYMENT_SUBS_TRIAL_UNITS              = 'nuvei_sub_trial_units';
    const PAYMENT_SUBS_TRIAL_UNITS_LABEL        = 'Trial Units';
    const PAYMENT_SUBS_TRIAL_PERIOD             = 'nuvei_sub_trial_period';
    const PAYMENT_SUBS_TRIAL_PERIOD_LABEL       = 'Trial Period';
    
    const PAYMENT_SUBS_END_AFTER_UNITS          = 'nuvei_sub_end_after_units';
    const PAYMENT_SUBS_END_AFTER_UNITS_LABEL    = 'End After Units';
    const PAYMENT_SUBS_END_AFTER_PERIOD         = 'nuvei_sub_end_after_period';
    const PAYMENT_SUBS_END_AFTER_PERIOD_LABEL   = 'End After Period';
    
    const STORE_SUBS_DROPDOWN                   = 'nuvei_sub_store_dropdown';
    const STORE_SUBS_DROPDOWN_LABEL             = 'Nuvei Subscription Options';
    
    const NUVEI_SDK_AUTOCLOSE_URL               = 'https://cdn.safecharge.com/safecharge_resources/v1/websdk/autoclose.html';
    
    // the allowed methods when call the plugin REST API
    const NUVEI_REST_API_PLUGIN_METHODS         = [
        'get-web-sdk-data',
        'get-simply-connect-data',
        'get-apm-redirect-url',
        'update-order'
    ];
    
    private $traceId;
    
    /**
     * Scope config object.
     *
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * Store manager object.
     *
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @var ModuleListInterface
     */
    private $moduleList;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var UrlInterface
     */
    private $urlBuilder;

    /**
     * Store id.
     *
     * @var int
     */
    private $storeId;

    /**
     * Already fetched config values.
     *
     * @var array
     */
    private $config = [];
    
    /**
     * Magento version like integer.
     *
     * @var int
     */
    private $versionNum = '';
    
    /**
     * Use it to validate the redirect
     *
     * @var FormKey
     */
    private $formKey;
    
    private $directory;
    private $httpHeader;
    private $remoteIp;
    private $customerSession;
    private $cookie;
    private $quoteFactory;

    /**
     * Object initialization.
     *
     * @param ScopeConfigInterface      $scopeConfig Scope config object.
     * @param StoreManagerInterface     $storeManager Store manager object.
     * @param ProductMetadataInterface  $productMetadata
     * @param ModuleListInterface       $moduleList
     * @param CheckoutSession           $checkoutSession
     * @param UrlInterface              $urlBuilder
     * @param FormKey                   $formKey
     * @param DirectoryList             $directory
     * @param Header                    $httpHeader
     * @param RemoteAddress             $remoteIp
     * @param Session                   $customerSession
     * @param CookieManagerInterface    $cookie
     * @param QuoteFactory              $quoteFactory
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        ProductMetadataInterface $productMetadata,
        ModuleListInterface $moduleList,
        CheckoutSession $checkoutSession,
        UrlInterface $urlBuilder,
        \Magento\Framework\Data\Form\FormKey $formKey,
        \Magento\Framework\Filesystem\DirectoryList $directory,
        \Magento\Framework\HTTP\Header $httpHeader,
        \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteIp,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\Stdlib\CookieManagerInterface $cookie,
        \Magento\Quote\Model\QuoteFactory $quoteFactory
    ) {
        $this->scopeConfig      = $scopeConfig;
        $this->storeManager     = $storeManager;
        $this->productMetadata  = $productMetadata;
        $this->moduleList       = $moduleList;
        $this->checkoutSession  = $checkoutSession;
        $this->urlBuilder       = $urlBuilder;
        $this->httpHeader       = $httpHeader;
        $this->remoteIp         = $remoteIp;
        $this->customerSession  = $customerSession;

        $this->storeId              = $this->getStoreId();
        $this->formKey              = $formKey;
        $this->directory            = $directory;
        $this->cookie               = $cookie;
        $this->quoteFactory         = $quoteFactory;
        
        $git_version = $this->productMetadata->getVersion();
        
        if (!empty($git_version)) {
            $this->versionNum = (int) str_replace('.', '', $git_version);
        }
    }

    public function getTempPath()
    {
        return $this->directory->getPath('log');
    }
    
    public function getUsedSdk()
    {
        return $this->getConfigValue('sdk');
    }
    
    public function getMerchantApplePayLabel()
    {
        return $this->getConfigValue('apple_pay_label');
    }
    
    /**
     * Function getSourceApplication
     * Get the value of one more parameter for the REST API
     *
     * @return string
     */
    public function getSourceApplication()
    {
        return 'Magento 2 Plugin';
    }

    /**
     * Function get_device_details
     * Get browser and device based on HTTP_USER_AGENT.
     * The method is based on D3D payment needs.
     *
     * @return array $device_details
     */
    public function getDeviceDetails()
    {
        $SC_DEVICES         = ['iphone', 'ipad', 'android', 'silk', 'blackberry', 'touch', 'linux', 'windows', 'mac'];
        $SC_BROWSERS        = ['ucbrowser', 'firefox', 'chrome', 'opera', 'msie', 'edge', 'safari',
            'blackberry', 'trident'];
        $SC_DEVICES_TYPES   = ['macintosh', 'tablet', 'mobile', 'tv', 'windows', 'linux', 'tv', 'smarttv',
            'googletv', 'appletv', 'hbbtv', 'pov_tv', 'netcast.tv', 'bluray'];
        
        $device_details = [
            'deviceType'    => 'UNKNOWN', // DESKTOP, SMARTPHONE, TABLET, TV, and UNKNOWN
            'deviceName'    => '',
            'deviceOS'      => '',
            'browser'       => '',
            'ipAddress'     => '',
        ];
        
        // get ip
        try {
            $device_details['ipAddress']    = (string) $this->remoteIp->getRemoteAddress();
            $ua                                = $this->httpHeader->getHttpUserAgent();
        } catch (\Exception $ex) {
//            $this->createLog($ex->getMessage(), 'getDeviceDetails Exception');
            return $device_details;
        }
        
        if (empty($ua)) {
            return $device_details;
        }
        
        $user_agent = strtolower($ua);
        $device_details['deviceName'] = $ua;

        foreach ($SC_DEVICES_TYPES as $d) {
            if (strstr($user_agent, $d) !== false) {
                if (in_array($d, ['linux', 'windows', 'macintosh'], true)) {
                    $device_details['deviceType'] = 'DESKTOP';
                } elseif ('mobile' === $d) {
                    $device_details['deviceType'] = 'SMARTPHONE';
                } elseif ('tablet' === $d) {
                    $device_details['deviceType'] = 'TABLET';
                } else {
                    $device_details['deviceType'] = 'TV';
                }

                break;
            }
        }

        foreach ($SC_DEVICES as $d) {
            if (strstr($user_agent, $d) !== false) {
                $device_details['deviceOS'] = $d;
                break;
            }
        }

        foreach ($SC_BROWSERS as $b) {
            if (strstr($user_agent, $b) !== false) {
                $device_details['browser'] = $b;
                break;
            }
        }

        return $device_details;
    }
    
    /**
     * Return store manager.
     * @return StoreManagerInterface
     */
    public function getStoreManager()
    {
        return $this->storeManager;
    }

    /**
     * Return store manager.
     * @return StoreManagerInterface
     */
    public function getCheckoutSession()
    {
        return $this->checkoutSession;
    }

    /**
     * Return store id.
     *
     * @return int
     */
    public function getStoreId()
    {
        return $this->storeManager->getStore()->getId();
    }

    /**
     * Return config field value.
     *
     * @param string $fieldKey Field key.
     * @param string $sub_group The beginning of the the Sub group
     *
     * @return mixed
     */
    public function getConfigValue($fieldKey, $sub_group = '')
    {
        if (!isset($this->config[$fieldKey])) {
            $path = 'payment/' . Payment::METHOD_CODE . '/';
            
            if (!empty($sub_group)) {
                $path .= $sub_group . '_configuration/';
            }
            
            $path .= $fieldKey;
            
            $this->config[$fieldKey] = $this->scopeConfig->getValue(
                $path,
                ScopeInterface::SCOPE_STORE,
                $this->storeId
            );
        }
        
        return $this->config[$fieldKey];
    }

    /**
     * Return merchant id.
     *
     * @return string
     */
    public function getMerchantId()
    {
        if ($this->isTestModeEnabled() === true) {
            return $this->getConfigValue('sandbox_merchant_id');
        }

        return $this->getConfigValue('merchant_id');
    }

    /**
     * Return merchant site id.
     *
     * @return string
     */
    public function getMerchantSiteId()
    {
        if ($this->isTestModeEnabled() === true) {
            return $this->getConfigValue('sandbox_merchant_site_id');
        }

        return $this->getConfigValue('merchant_site_id');
    }
    
    /**
     * Return merchant secret key.
     *
     * @return string
     */
    public function getMerchantSecretKey()
    {
        if ($this->isTestModeEnabled() === true) {
            return $this->getConfigValue('sandbox_merchant_secret_key');
        }

        return $this->getConfigValue('merchant_secret_key');
    }

    /**
     * Return bool value depends of that if payment method sandbox mode
     * is enabled or not.
     *
     * @return bool
     */
    public function isTestModeEnabled()
    {
        if ($this->getConfigValue('mode') === Payment::MODE_LIVE) {
            return false;
        }

        return true;
    }
    
    public function showCheckoutLogo()
    {
        if ($this->getConfigValue('show_checkout_logo') == 1) {
            return true;
        }

        return false;
    }
    
    public function canSaveUpos()
    {
        return $this->getConfigValue('save_upos');
    }
    
    public function canShowUpos()
    {
        return $this->getConfigValue('show_upos');
    }
    
    public function isUserLogged()
    {
        return $this->customerSession->isLoggedIn() ? true : false;
    }
    
    public function allowGuestsSubscr()
    {
        if (!$this->customerSession->isLoggedIn()) {
            return false;
        }
        
        return true;
    }

    /**
     * Return bool value depends of that if payment method debug mode
     * is enabled or not.
     *
     * @param bool $return_value - by default is false, set true to get int value
     * @return bool
     */
    public function isDebugEnabled($return_value = false)
    {
        if ($return_value) {
            return (int) $this->getConfigValue('debug');
        }
        
        if ((int) $this->getConfigValue('debug') == 0) {
            return false;
        }
        
        return true;
    }
    
    public function getCheckoutTransl()
    {
        $transl             = $this->getConfigValue('checkout_transl', 'checkout');
        $checkout_transl    = '';
        
        if (!empty($transl)) {
            $checkout_transl = str_replace("'", '"', $transl);
        }
        
        return json_decode($checkout_transl, true);
    }

    public function getSourcePlatformField()
    {
        $plugin_v   = $this->getModuleVersion();
        $text       = 'Magento Plugin' . (empty($plugin_v) ? '' : ' ' . $plugin_v);
        
        return $text . '; Magento v' . $this->getMagentoVersion();
    }
    
    /**
     * Get the version for the current plugin.
     * 
     * @return string
     */
    public function getModuleVersion()
    {
        $module_data = $this->moduleList->getOne(self::MODULE_NAME);

        if (!empty($module_data['setup_version'])) {
            return $module_data['setup_version'];
        }
        
        return '';
    }
    
    public function getMagentoVersion()
    {
        return $this->productMetadata->getVersion();
    }

    /**
     * @params int $quoteId Passed from plugin REST API call.
     * @return string
     */
    public function getCallbackSuccessUrl($quoteId = '')
    {
        $params = [
            'quote'     => !empty($quoteId) ? $quoteId : $this->checkoutSession->getQuoteId(),
            'form_key'  => $this->formKey->getFormKey(),
        ];
        
        return $this->urlBuilder->getUrl(
            'nuvei_checkout/payment/callback_complete',
            $params
        );
    }

    /**
     * @params int $quoteId Passed from plugin REST API call.
     * @return string
     */
    public function getCallbackPendingUrl($quoteId = '')
    {
        $params = [
            'quote'     => !empty($quoteId) ? $quoteId : $this->checkoutSession->getQuoteId(),
            'form_key'  => $this->formKey->getFormKey(),
        ];
        
        return $this->urlBuilder->getUrl(
            'nuvei_checkout/payment/callback_complete',
            $params
        );
    }

    /**
     * @params int $quoteId Passed from plugin REST API call.
     * @return string
     */
    public function getCallbackErrorUrl($quoteId = '')
    {
        $params = [
            'quote'     => !empty($quoteId) ? $quoteId : $this->checkoutSession->getQuoteId(),
            'form_key'  => $this->formKey->getFormKey(),
        ];

        return $this->urlBuilder->getUrl(
            'nuvei_checkout/payment/callback_error',
            $params
        );
    }

    /**
     * @param int   $incrementId
     * @param int   $storeId
     * @param array $url_params
     * @params int  $quoteId Passed from plugin REST API call.
     *
     * @return string
     */
    public function getCallbackDmnUrl($incrementId = null, $storeId = null, $url_params = [], $quoteId = '')
    {
        $url = $this->getStoreManager()
            ->getStore(null === $incrementId ? $this->storeId : $storeId)
            ->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
        
        $params = [
            'order'     => null === $incrementId ? $this->getReservedOrderId($quoteId) : $incrementId,
            'form_key'  => $this->formKey->getFormKey(),
            'quote'     => !empty($quoteId) ? $quoteId : $this->checkoutSession->getQuoteId(),
        ];
        
        $params_str = '';
        
        if (!empty($url_params) && is_array($url_params)) {
            $params = array_merge($params, $url_params);
        }
        
        foreach ($params as $key => $val) {
            if (empty($val)) {
                continue;
            }
            
            $params_str .= $key . '/' . $val . '/';
        }
        
        return $url . 'nuvei_checkout/payment/callback_dmn/' . $params_str;
    }

    /**
     * @return string
     */
    public function getBackUrl()
    {
        return $this->urlBuilder->getUrl('checkout/cart');
    }
    
    public function canPerformCommand($commandCode)
    {
        return $this->getConfigValue('can_' . $commandCode);
    }
    
    public function getQuoteId()
    {
        return ($quote = $this->checkoutSession->getQuote()) ? $quote->getId() : null;
    }
    
    /**
     * @param int $quoteId Optional.
     * @return string
     */
    public function getReservedOrderId($quoteId = '')
    {
        $quote = empty($quoteId) ? $this->checkoutSession->getQuote() 
            : $this->quoteFactory->create()->load($quoteId);
        
        $reservedOrderId = $quote->getReservedOrderId();
        
        if (!$reservedOrderId) {
            $quote->reserveOrderId()->save();
            $reservedOrderId = $quote->getReservedOrderId();
        }
        
        return $reservedOrderId;
    }

    /**
     * Get default country code.
     * @return string
     */
    public function getDefaultCountry()
    {
        return $this->scopeConfig->getValue('general/country/default', ScopeInterface::SCOPE_STORE, $this->storeId);
    }
    
    /**
     * @param int $quoteId Eventually passed form REST API call
     * @return string
     */
    public function getQuoteCountryCode($quoteId = 0)
    {
        $quote = empty($quoteId) ? $this->checkoutSession->getQuote() 
            : $this->quoteFactory->create()->load($quoteId);
        
        $billing        = ($quote) ? $quote->getBillingAddress() : null;
        $countryCode    = ($billing) ? $billing->getCountryId() : null;
        
        if (!$countryCode) {
            $shipping       = ($quote) ? $quote->getShippingAddress() : null;
            $countryCode    = ($shipping && $shipping->getSameAsBilling()) ? $shipping->getCountryId() : null;
        }
        
        if (!$countryCode) {
            $countryCode = $this->getDefaultCountry();
        }
        
        return $countryCode;
    }
    
    /**
     * Get base currency code from the Quote. This must be same as the Magento Base currency.
     *
     * @param int $quoteId Eventually passed from REST API call.
     * @return string
     */
    public function getQuoteBaseCurrency($quoteId = 0)
    {
        $quote = empty($quoteId) ? $this->checkoutSession->getQuote() 
            : $this->quoteFactory->create()->load($quoteId);
        
        return $quote->getBaseCurrencyCode();
    }
    
    /**
     * Get currency code from the Quote. This must be same as the Magento store Visual currency.
     *
     * @return string
     */
    public function getQuoteVisualCurrency()
    {
        return $this->checkoutSession->getQuote()->getQuoteCurrencyCode();
    }
    
    /**
     * Get store currency code. Use this when Quote is not available.
     *
     * @return string
     */
    public function getStoreCurrency()
    {
        return trim($this->storeManager->getStore()->getCurrentCurrencyCode());
    }
    
    /**
     * Get the Quote Base Grand Total, based on Display currency.
     *
     * @param int $quoteId Eventually passed from REST API call.
     * @return string
     */
    public function getQuoteBaseTotal($quoteId = 0)
    {
        $quote = empty($quoteId) ? $this->checkoutSession->getQuote() 
            : $this->quoteFactory->create()->load($quoteId);
        
        return (string) number_format((float) $quote->getBaseGrandTotal(), 2, '.', '');
    }
    
    /**
     * Get quote visual.
     *
     * @return string
     */
    public function getQuoteVisualTotal()
    {
        return (string) number_format($this->checkoutSession->getQuote()->getGrandTotal(), 2, '.', '');
    }
    
    /**
     * @param string $quoteId Eventually passed form REST API call
     * @return array
     * @throws Exception
     */
    public function getQuoteBillingAddress($quoteId = '')
    {
        $quote = empty($quoteId) ? $this->checkoutSession->getQuote() 
            : $this->quoteFactory->create()->load($quoteId);
        
        if (!is_object($quote) || empty($quote)) {
            throw new Exception('There is no Quote by quote ID' . $quoteId);
        }
        
        $billingAddress = $quote->getBillingAddress();
        
        $b_f_name = $billingAddress->getFirstname();
        if (empty($b_f_name)) {
            $b_f_name = $quote->getCustomerFirstname();
        }
        
        $b_l_name = $billingAddress->getLastname();
        if (empty($b_l_name)) {
            $b_l_name = $quote->getCustomerLastname();
        }
        
        $billing_country = $billingAddress->getCountry();
        if (empty($billing_country)) {
            $billing_country = $this->getQuoteCountryCode();
        }
        if (empty($billing_country)) {
            $billing_country = $this->getDefaultCountry();
        }
        
        return [
            "firstName" => $b_f_name,
            "lastName"  => $b_l_name,
            "address"   => $billingAddress->getStreetFull(),
            "phone"     => $billingAddress->getTelephone(),
            "zip"       => $billingAddress->getPostcode(),
            "city"      => $billingAddress->getCity(),
            'country'   => $billing_country,
            'email'     => $this->getUserEmail(false, $quoteId),
        ];
    }
    
    /**
     * @param string $quoteId Eventually passed form REST API call
     * @return array
     */
    public function getQuoteShippingAddress($quoteId = '')
    {
        $quote = empty($quoteId) ? $this->checkoutSession->getQuote() 
            : $this->quoteFactory->create()->load($quoteId);
        
        $shipping_address   = $quote->getShippingAddress();
        $shipping_email     = $shipping_address->getEmail();
        
        if (empty($shipping_email)) {
            $shipping_email = $this->getUserEmail();
        }
        
        return [
            "firstName" => $shipping_address->getFirstname(),
            "lastName"  => $shipping_address->getLastname(),
            "address"   => $shipping_address->getStreetFull(),
            "phone"     => $shipping_address->getTelephone(),
            "zip"       => $shipping_address->getPostcode(),
            "city"      => $shipping_address->getCity(),
            'country'   => $shipping_address->getCountry(),
            'email'     => $shipping_email,
        ];
    }
    
//    public function getNuveiUseCcOnly()
//    {
//        return $this->checkoutSession->getNuveiUseCcOnly();
//    }
    
    public function setNuveiUseCcOnly($val)
    {
        $this->checkoutSession->setNuveiUseCcOnly($val);
    }
    
    /**
     * @param bool $empty_on_fail
     * @param string $quoteId Eventually passed form REST API call.
     * 
     * @return string
     */
    public function getUserEmail($empty_on_fail = false, $quoteId = '')
    {
        $quote  = empty($quoteId) ? $this->checkoutSession->getQuote() 
            : $this->quoteFactory->create()->load($quoteId);
        $email  = $quote->getBillingAddress()->getEmail();
        
        if (empty($email)) {
            $email = $quote->getCustomerEmail();
        }
        
        if (empty($email) && $empty_on_fail) {
            return '';
        }
        
        if (empty($email) && !empty($this->cookie->getCookie('guestSippingMail'))) {
            $email = $this->cookie->getCookie('guestSippingMail');
        }
        if (empty($email)) {
            $email = 'quoteID_' . $quote->getId() . '@magentoMerchant.com';
        }
        
        return $email;
    }
}
