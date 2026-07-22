<?php

namespace Nuvei\Checkout\Model\Request\Payment;

use Nuvei\Checkout\Model\AbstractRequest;
//use Nuvei\Checkout\Model\AbstractResponse;
//use Nuvei\Checkout\Model\Payment;
use Nuvei\Checkout\Model\Request\AbstractPayment;
use Nuvei\Checkout\Model\RequestInterface;


/**
 * @author Nuvei
 */
class GetStatus extends AbstractPayment implements RequestInterface
{
    protected $readerWriter;
    
    private $sessionToken = '';
    
    /**
     * Refund constructor.
     *
     * @param Http              $request
     * @param Payment           $orderPayment
     * @param Curl              $curl
     * @param Config            $config
     * @param ReaderWriter      $readerWriter
     * @param ResponseFactory   $responseFactory
     */
    public function __construct(
        \Magento\Framework\App\Request\Http $request,
        \Magento\Sales\Model\Order\Payment $orderPayment,
        \Nuvei\Checkout\Lib\Http\Client\Curl $curl,
        \Nuvei\Checkout\Model\Config $config,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        \Nuvei\Checkout\Model\Response\Factory $responseFactory
    ) {
        parent::__construct(
            $config,
            $curl,
            $responseFactory,
            $orderPayment,
            $readerWriter
        );

//        $this->request      = $request;
        $this->readerWriter = $readerWriter;
    }
    
    public function process()
    {
        return $this->sendRequest(true, true);
    }
    
    public function setSessionToken($sessionToken)
    {
        $this->sessionToken = $sessionToken;
        
        return $this;
    }
    
    /**
     * {@inheritdoc}
     *
     * @return array
     * @throws \Magento\Framework\Exception\PaymentException
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getParams()
    {
        return [
            'sessionToken' => $this->sessionToken
        ];
    }
    
    /**
     * {@inheritdoc}
     *
     * @return array
     */
    protected function getChecksumKeys()
    {
        return [];
    }
    
    protected function getRequestMethod(): string
    {
        return AbstractRequest::GET_PAYMENT_STATUS;
    }

    protected function getResponseHandlerType(): string
    {
        return '';
    }
}
