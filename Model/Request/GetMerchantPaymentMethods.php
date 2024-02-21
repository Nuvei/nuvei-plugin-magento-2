<?php

namespace Nuvei\Checkout\Model\Request;

use Nuvei\Checkout\Lib\Http\Client\Curl;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\AbstractResponse;
use Nuvei\Checkout\Model\Config;
use Nuvei\Checkout\Model\Request\Factory as RequestFactory;
use Nuvei\Checkout\Model\RequestInterface;
use Nuvei\Checkout\Model\Response\Factory as ResponseFactory;

/**
 * Nuvei Checkout get merchant payment methods request model.
 */
class GetMerchantPaymentMethods extends AbstractRequest implements RequestInterface
{
    /**
     * @var RequestFactory
     */
    protected $requestFactory;

    /**
     * @var string
     */
    protected $countryCode;
    
    protected $store;
    
    private $billing_address;
    private $sessionToken;
    private $currency;
    private $quoteId;
    
    /**
     * @param Config            $moduleConfig
     * @param Curl              $curl
     * @param ResponseFactory   $responseFactory
     * @param Factory           $requestFactory
     * @param StoreInterface    $store
     * @param ReaderWriter      $readerWriter
     */
    public function __construct(
        Config $moduleConfig,
        Curl $curl,
        ResponseFactory $responseFactory,
        RequestFactory $requestFactory,
        \Magento\Store\Api\Data\StoreInterface $store,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        parent::__construct(
            $moduleConfig,
            $curl,
            $responseFactory,
            $readerWriter
        );

        $this->requestFactory   = $requestFactory;
        $this->store            = $store;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getRequestMethod()
    {
        return self::GET_MERCHANT_PAYMENT_METHODS_METHOD;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getResponseHandlerType()
    {
        return AbstractResponse::GET_MERCHANT_PAYMENT_METHODS_HANDLER;
    }

    /**
     * @param bool $continueProcess When it is true, return return response immediately.
     * 
     * @return AbstractResponse
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws PaymentException
     */
    public function process($continueProcess = false)
    {
        if (!$this->config->getConfigValue('active')) {
            $this->readerWriter->createLog('GetMerchantPaymentMethods Error - '
                . 'Nuvei payments module is not active at the moment!');
            return [];
        }
        if (empty($this->config->getMerchantId())) {
            $this->readerWriter->createLog('GetMerchantPaymentMethods Error - merchantId is empty!');
            return [];
        }
        if (empty($this->config->getMerchantSiteId())) {
            $this->readerWriter->createLog('GetMerchantPaymentMethods Error - merchantSiteId is empty!');
            return [];
        }
        if (empty($this->config->getMerchantSecretKey())) {
            $this->readerWriter->createLog('GetMerchantPaymentMethods Error - merchant secret key is empty!');
            return [];
        }
        if (empty($this->config->getConfigValue('hash'))) {
            $this->readerWriter->createLog('GetMerchantPaymentMethods Error - merchant hash is empty!');
            return [];
        }
        
        // for the REST API return response directly
        if (!empty($this->sessionToken)) {
            return $this->sendRequest(true, true);
        }
        
        if (empty($this->quoteId)) {
            $this->quoteId = $this->config->getQuoteId();
        }
        
        if ($continueProcess) {
            return $this->sendRequest($continueProcess);
        }
        
        $this->sendRequest();

        return $this
            ->getResponseHandler()
            ->process();
    }
    
    /**
     * @param string|null $billing_address
     * @return $this
     */
    public function setBillingAddress($billing_address)
    {
        $this->billing_address = json_decode(is_null($billing_address) ? '' : $billing_address, true);
        return $this;
    }
    
    /**
     * Use this method for REST API calls.
     * 
     * @param string $sessionToken
     * @return $this
     */
    public function setSessionToken($sessionToken)
    {
        $this->sessionToken = $sessionToken;
        return $this;
    }
    
    /**
     * Use this method for REST API calls.
     * 
     * @param string $currency
     * @return $this
     */
    public function setCurrency($currency)
    {
        $this->currency = $currency;
        return $this;
    }
    
    /**
     * Use this method for REST API calls.
     * 
     * @param string $quoteId
     * @return $this
     */
    public function setQuoteId($quoteId)
    {
        $this->quoteId = $quoteId;
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    protected function getParams()
    {
        $params         = [];
        $sessionToken   = $this->sessionToken;
        
        if (empty($sessionToken)) {
            $tokenRequest   = $this->requestFactory->create(AbstractRequest::GET_SESSION_TOKEN);
            $tokenResponse  = $tokenRequest->process();
            $sessionToken   = !empty($tokenResponse['sessionToken']) ? $tokenResponse['sessionToken'] : '';
        }
        // call from the REST API
//        else {
//            $country_code = isset($this->billing_address['countryId']) ? $this->billing_address['countryId'] : '';
//            if (empty($country_code)) {
//                $country_code = $this->config->getQuoteCountryCode($this->quoteId);
//            }
//            
//            $params['countryCode'] = $country_code;
//            
//            if (!empty($this->currency)) {
//                $params['currencyCode'] = $this->currency;
//            }
//        }
        
        if (!empty($this->quoteId)) {
            $params['countryCode'] = !empty($this->billing_address['countryId'])
                ? $this->billing_address['countryId'] : $this->config->getQuoteCountryCode($this->quoteId);
            
            $params['currencyCode'] = !empty($this->currency) 
                ? $this->currency : $this->config->getQuoteBaseCurrency($this->quoteId);
        }
        
        $languageCode = 'en';
        if ($this->store && $this->store->getLocaleCode()) {
            $languageCode = $this->store->getLocaleCode();
        }
        
        $params['sessionToken'] = $sessionToken;
        $params['languageCode'] = $languageCode;
        
        return array_merge_recursive(
            parent::getParams(),
            $params
        );
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    protected function getChecksumKeys()
    {
        return [
            'merchantId',
            'merchantSiteId',
            'clientRequestId',
            'timeStamp',
        ];
    }
}
