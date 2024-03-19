<?php

namespace Nuvei\Checkout\Model\Request;

use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\AbstractResponse;
use Nuvei\Checkout\Model\RequestInterface;

/**
 * Nuvei Payments get user payment options request model.
 */
class GetUserUPOs extends AbstractRequest implements RequestInterface
{
    private $email;
    
    /**
     * @param ReaderWriter    $readerWriter
     * @param Config          $config
     * @param Curl            $curl
     * @param ResponseFactory $responseFactory
     */
    public function __construct(
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        \Nuvei\Checkout\Model\Config $config,
        \Nuvei\Checkout\Lib\Http\Client\Curl $curl,
        \Nuvei\Checkout\Model\Response\Factory $responseFactory
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
        $this->readerWriter->createLog('GetUserUpos');
        
        $res = $this->sendRequest(true, true);
        $pms = [];
        
        if (!empty($res['paymentMethods']) && is_array($res['paymentMethods'])) {
            foreach ($res['paymentMethods'] as $method) {
                if (!empty($method['expiryDate']) && date('Ymd') > $method['expiryDate']) {
                    continue;
                }

                if (empty($method['upoStatus']) || $method['upoStatus'] !== 'enabled') {
                    continue;
                }

                $pms[] = $method;
            }
        }
        
        return $pms;
    }
    
    /**
     * @param  string $email
     * @return $this
     */
    public function setEmail($email)
    {
        $this->email = $email;
        return $this;
    }
    
    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getRequestMethod()
    {
        return self::GET_UPOS_METHOD;
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
    
    /**
     * {@inheritdoc}
     *
     * @return array
     */
    protected function getParams()
    {
        $params = array_merge_recursive(
            parent::getParams(),
            ['userTokenId' => $this->email]
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
            'timeStamp',
        ];
    }
}
