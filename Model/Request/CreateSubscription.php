<?php

namespace Nuvei\Checkout\Model\Request;

use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\RequestInterface;

/**
 * Nuvei Checkout Create Subscription request model.
 */
class CreateSubscription extends AbstractRequest implements RequestInterface
{
    protected $plan_id;
    protected $upo_id;
    
    private $order_id;
    private $request_data;

    /**
     * @return AbstractResponse
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws PaymentException
     */
    public function process()
    {
        return $this->sendRequest(true, true);
    }
    
    public function setOrderId($order_id = 0)
    {
        $this->order_id = $order_id;
        return $this;
    }
    
    public function setData(array $request_data = [])
    {
        $this->request_data = $request_data;
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getRequestMethod()
    {
        return self::CREATE_SUBSCRIPTION_METHOD;
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
        $params = array_merge_recursive($this->request_data, parent::getParams());
        
        // append Order ID to the Request ID
        $params['clientRequestId'] = date('YmdHis') . '_' . $this->order_id;
        
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
            'planId',
            'userPaymentOptionId',
            'initialAmount',
            'recurringAmount',
            'currency',
            'timeStamp',
        ];
    }
}
