<?php

namespace Nuvei\Checkout\Model\Request;

use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\AbstractResponse;
use Nuvei\Checkout\Model\RequestInterface;

class DeleteUPO extends AbstractRequest implements RequestInterface
{
    protected $config;
    
    private $upo_id;
    
    /**
     * @param Config          $config
     * @param Curl            $curl
     * @param ResponseFactory $responseFactory
     * @param ReaderWriter    $readerWriter
     */
    public function __construct(
        \Nuvei\Checkout\Model\Config $config,
        \Nuvei\Checkout\Lib\Http\Client\Curl $curl,
        \Nuvei\Checkout\Model\Response\Factory $responseFactory,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        parent::__construct(
            $config,
            $curl,
            $responseFactory,
            $readerWriter
        );
    }
    
    /**
     * @return AbstractResponse
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws PaymentException
     */
    public function process()
    {
        $resp = $this->sendRequest(true);
        
        if (!empty($resp['status'])) {
            return strtolower($resp['status']);
        }
        
        return 'error';
    }
    
    public function setUpoId($upo_id)
    {
        $this->upo_id = $upo_id;
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    protected function getParams()
    {
        $billing_address    = $this->config->getQuoteBillingAddress();
        $email              = $billing_address['email'] ?: $this->config->getUserEmail(true);
        
        $params = array_merge_recursive(
            parent::getParams(),
            [
                'userTokenId'            => $email, // logged user email
                'userPaymentOptionId'    => $this->upo_id,
            ]
        );
        
        return $params;
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
            'userTokenId',
            'clientRequestId',
            'userPaymentOptionId',
            'timeStamp',
        ];
    }
    
    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getRequestMethod()
    {
        return self::DELETE_UPOS_METHOD;
    }
    
    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getResponseHandlerType()
    {
        return '';
    }
}
