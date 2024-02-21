<?php

namespace Nuvei\Checkout\Model\Response;

use Magento\Framework\Locale\Resolver;
use Nuvei\Checkout\Lib\Http\Client\Curl;
use Nuvei\Checkout\Model\AbstractResponse;
use Nuvei\Checkout\Model\Config;
use Nuvei\Checkout\Model\ResponseInterface;

/**
 * Nuvei Checkout get merchant payment methods response model.
 */
class GetMerchantPaymentMethods extends AbstractResponse implements ResponseInterface
{

    protected $localeResolver;
    protected $scPaymentMethods = [];
    
    /**
     *
     * @var string - the session token returned from APMs
     */
    protected $sessionToken = '';
    
    private $assetRepo;
    private $config;
    private $paymentsPlans;

    /**
     * AbstractResponse constructor.
     *
     * @param Config        $config
     * @param int           $requestId
     * @param Curl          $curl
     * @param Resolver      $localeResolver
     * @param Repository    $assetRepo
     * @param ReaderWriter  $readerWriter
     * @param PaymentsPlans $paymentsPlans
     */
    public function __construct(
        Config $config,
        $requestId,
        Curl $curl,
        Resolver $localeResolver,
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        \Nuvei\Checkout\Model\PaymentsPlans $paymentsPlans
    ) {
        parent::__construct(
            $requestId,
            $curl,
            $readerWriter
        );

        $this->localeResolver   = $localeResolver;
        $this->assetRepo        = $assetRepo;
        $this->paymentsPlans    = $paymentsPlans;
        $this->config           = $config;
    }

    /**
     * @return AbstractResponse
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function process($countryCode = null)
    {
        parent::process();

        $body               = $this->getBody();
        $this->sessionToken = (string) $body['sessionToken'];
        $langCode           = $this->getStoreLocale(true);
        $countryCode        = $countryCode ?? $this->config->getQuoteCountryCode();
        // check for products with rebilling
        $useCcOnly          = !empty($this->paymentsPlans->getProductPlanData()) ? true : false;
        
        // check for zero-total and enabled Nuvei GW for Zero-Total
        if (1 == $this->config->getConfigValue('allow_zero_total')
            && 0 == $this->config->getQuoteBaseTotal()
        ) {
            $useCcOnly = true;
        }
        
        foreach ((array) $body['paymentMethods'] as $method) {
            if (!$countryCode && isset($method["paymentMethod"]) && $method["paymentMethod"] !== 'cc_card') {
                continue;
            }
            
            // when we have product with a Payment plan, skip all APMs
            if ($useCcOnly
                && !empty($method["paymentMethod"])
                && $method["paymentMethod"] !== 'cc_card'
            ) {
                continue;
            }
            
            $default_dnames = [];
            $locale_dnames  = [];
            $pm             = $method;
            
            if (isset($method["paymentMethodDisplayName"]) && is_array($method["paymentMethodDisplayName"])) {
                foreach ($method["paymentMethodDisplayName"] as $dname) {
                    if ($dname["language"] === $langCode) {
                        $locale_dnames = $dname;
                        break;
                    } elseif ($dname["language"] == 'en') { // default language
                        $default_dnames = $dname;
                    }
                }
            }
            
            // set custom payment logo for CC
            if ('cc_card' == $method["paymentMethod"]) {
                $pm["logoURL"] = $this->assetRepo->getUrl("Nuvei_Checkout::images/visa_mc_maestro.svg");
            } elseif (!empty($method["logoURL"])) {
                $pm["logoURL"] = preg_replace('/\.svg\.svg$/', '.svg', $method["logoURL"]);
            }
            
            // check for Apple Pay
            if ('ppp_ApplePay' == $method["paymentMethod"]) {
                $pm['logoURL'] = $this->assetRepo->getUrl("Nuvei_Checkout::images/applepay.svg");
                
                // fix for the payment name
                if (!isset($method['paymentMethodDisplayName']['message'])
                    && isset($method['paymentMethodDisplayName'][0]['message'])
                ) {
                    $pm['paymentMethodDisplayName']['message']
                        = $method['paymentMethodDisplayName'][0]['message'];
                }
            }
            
            // fix for the Neteller field
            if ('apmgw_Neteller' == $method["paymentMethod"]
                && 1 == count($method['fields'])
                && 'nettelerAccount' == $method['fields'][0]['name']
            ) {
                $pm['fields'][0]['type'] = 'email';
            }
            
            if (!empty($locale_dnames)) {
                $pm["paymentMethodDisplayName"] = $locale_dnames;
                $this->scPaymentMethods[]        = $pm;
            } elseif (!empty($default_dnames)) {
                $pm["paymentMethodDisplayName"] = $default_dnames;
                $this->scPaymentMethods[]       = $pm;
            }
        }
        
        return $this;
    }

    public function getPaymentMethods()
    {
        return $this->scPaymentMethods;
    }
    
    /**
     * Get the session token from the APMs
     *
     * @return string
     */
    public function getSessionToken()
    {
        return $this->sessionToken;
    }

    /**
     * Return store locale.
     *
     * @return string
     */
    protected function getStoreLocale($twoLetters = true)
    {
        $locale = $this->localeResolver->getLocale();
        return ($twoLetters) ? substr($locale, 0, 2) : $locale;
    }

    /**
     * @return array
     */
    protected function getRequiredResponseDataKeys()
    {
        return ['paymentMethods'];
    }
}
