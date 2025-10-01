<?php

namespace Nuvei\Checkout\Model\Request;

use Nuvei\Checkout\Lib\Http\Client\Curl;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\Config;
use Nuvei\Checkout\Model\RequestInterface;
use Nuvei\Checkout\Model\Response\Factory as ResponseFactory;

/**
 * @author Nuvei
 */
class GetPaymentPageUrl extends AbstractRequest implements RequestInterface
{
    private $incomingParams;
    
    /**
     * @param Config          $moduleConfig
     * @param Curl            $curl
     * @param ResponseFactory $responseFactory
     * @param ReaderWriter    $readerWriter
     */
    public function __construct(
        Config $moduleConfig,
        Curl $curl,
        ResponseFactory $responseFactory,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        parent::__construct(
            $moduleConfig,
            $curl,
            $responseFactory,
            $readerWriter
        );
    }
    
    public function process()
    {
        return $this->sendRequest(true, true);
    }
    
    public function setParams($params)
    {
        $this->incomingParams = $params;
        
        return $this;
    }
    
    protected function getRequestMethod()
    {
        return self::GET_PAYMENT_LINK;
    }

    protected function getResponseHandlerType()
    {
        return '';
    }
    
    protected function getParams()
    {
        $params = array_merge_recursive(
            [
                "apiClient" => "MERCHANT",
                "currency"  => $this->incomingParams['currency'],
                "amount"    => $this->incomingParams['amount'],
                "items"     => [
                    [
                        "name"      => $this->incomingParams['description'],
                        "price"     => $this->incomingParams['amount'],
                        "quantity"  => "1",
                    ]
                ],
                "urlDetails" => [
//                    'backUrl'           => $this->config->getBackUrl(),
                    'successUrl'        => $this->incomingParams['success_url'],
                    'failureUrl'        => $this->incomingParams['failure_url'],
                    'pendingUrl'        => $this->incomingParams['success_url'],
//                    "notificationUrl"   => null,
//                    "appUrl" => null,
//                    "appReturnLink" => null
                ],
                "userTokenId"       => $this->incomingParams['customer_email'],
                "clientUniqueId"    => $this->incomingParams['sku'] . '_' . time(),
                "isNative"          => "0",
            ],
            parent::getParams()
        );
        
        return $params;
    }
    
    protected function getChecksumKeys()
    {
        return ["merchantId","merchantSiteId","clientRequestId","amount","currency","timeStamp"];
    }
}
