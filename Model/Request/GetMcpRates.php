<?php

namespace Nuvei\Checkout\Model\Request;

use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\RequestInterface;

/**
 * Get the currency rates.
 *
 * @author Nuvei
 */
class GetMcpRates extends AbstractRequest implements RequestInterface
{
    private $fromCurrency   = '';
    private $toCurrency     = [];
    private $paymentMethods = [];
    private $sessionToken   = '';
    
    public function process()
    {
        $this->readerWriter->createLog('GetMcpRates class.');
        
        return $this->sendRequest(true, true);
    }

    /**
     * The base currency.
     * 
     * @param string $curr
     */
    public function setFromCurrency($curr)
    {
        $this->fromCurrency = $curr;
        return $this;
    }
    
    /**
     * The payment methods for the conversion.
     * 
     * @param array $methods A list with the methods.
     */
    public function setPaymentMethods($methods)
    {
        $this->paymentMethods = $methods;
        return $this;
    }
    
    /**
     * @param string $token A session token.
     */
    public function setSessionToken($token)
    {
        $this->sessionToken = $token;
        return $this;
    }
    
    /**
     * The currencies we need the rates for.
     * 
     * @param array $curr A list with currencies
     */
    public function setToCurrency($curr)
    {
        $this->toCurrency = $curr;
        return $this;
    }
    
    protected function getParams()
    {
        return [
            'merchantId'        => $this->config->getMerchantId(),
            'merchantSiteId'    => $this->config->getMerchantSiteId(),
            'sessionToken'      => $this->sessionToken,
            'fromCurrency'      => $this->fromCurrency,
            'toCurrency'        => $this->toCurrency,
            'paymentMethods'    => $this->paymentMethods,
        ];
    }
    
    protected function getRequestMethod(): string
    {
        return self::GET_MCP_RATES;
    }

    protected function getResponseHandlerType(): string
    {
        return '';
    }
    
    protected function getChecksumKeys()
    {
        return [];
    }
    
}
