<?php

namespace Nuvei\Checkout\Model\Request;

use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\AbstractResponse;
use Nuvei\Checkout\Model\RequestInterface;
use Magento\Framework\Exception\PaymentException;

/**
 * Nuvei Checkout open order request model.
 */
class UpdateOrder extends AbstractRequest implements RequestInterface
{
    /**
     * @var array
     */
    protected $orderData;

    private $quoteId        = '';
    private $cart;
    private $paymentsPlans;
    
    /**
     * @param Config            $config
     * @param Curl              $curl
     * @param Factory           $responseFactory
     * @param Cart              $cart
     * @param ReaderWriter      $readerWriter
     * @param PaymentsPlans     $paymentsPlans
     */
    public function __construct(
        \Nuvei\Checkout\Model\Config $config,
        \Nuvei\Checkout\Lib\Http\Client\Curl $curl,
        \Nuvei\Checkout\Model\Response\Factory $responseFactory,
        \Magento\Checkout\Model\Cart $cart,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        \Nuvei\Checkout\Model\PaymentsPlans $paymentsPlans
    ) {
        parent::__construct(
            $config,
            $curl,
            $responseFactory,
            $readerWriter
        );

        $this->cart             = $cart;
        $this->paymentsPlans    = $paymentsPlans;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getRequestMethod()
    {
        return self::UPDATE_ORDER_METHOD;
    }

    /**
     * @param array $orderData
     *
     * @return OpenOrder
     */
    public function setOrderData(array $orderData)
    {
        $this->orderData = $orderData;
        
        return $this;
    }
    
    public function setQuoteId($quoteId = '')
    {
        $this->quoteId = $quoteId;
        
        return $this;
    }
    
    /**
     * @return AbstractResponse
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws PaymentException
     */
    public function process()
    {
        $req_resp = $this->sendRequest(true, true);
        
        return $req_resp;
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
     * @throws \Magento\Framework\Exception\PaymentException
     */
    protected function getParams()
    {
        if (null === $this->cart || empty($this->cart)) {
            $this->readerWriter->createLog('UpdateOrder Error - There is no Cart data.');
            
            throw new PaymentException(__('There is no Cart data.'));
        }
        
        $quoteId = empty($this->quoteId) ? $this->config->getCheckoutSession()->getQuoteId() : $this->quoteId;
        
        // iterate over Items and search for Subscriptions
        $items_data = $this->paymentsPlans->getProductPlanData();
        $subs_data  = isset($items_data['subs_data']) ? $items_data['subs_data'] : [];
        
        $this->config->setNuveiUseCcOnly(!empty($subs_data) ? true : false);
        
        $billing_address    = $this->config->getQuoteBillingAddress($quoteId);
        $amount             = $this->config->getQuoteBaseTotal($quoteId);
        $currency           = $this->config->getQuoteBaseCurrency($quoteId);
        
        $this->readerWriter->createLog([
            '$subs_data' => $subs_data,
            'quoteId' => $quoteId,
            'getQuoteBaseCurrency' => $currency,
        ]);
        
        $params = array_merge_recursive(
            parent::getParams(),
            [
                'currency'          => $currency,
                'amount'            => $amount,
                'billingAddress'    => $billing_address,
                'shippingAddress'   => $this->config->getQuoteShippingAddress($quoteId),
                
                'items'             => [[
                    'name'      => 'magento_order',
                    'price'     => $amount,
                    'quantity'  => 1,
                ]],
                
                'merchantDetails'   => [
                    'customField1'  => $amount,
                    'customField2'  => json_encode($subs_data),
                    'customField3'  => $this->config->getReservedOrderId($quoteId),
                    // customField4 will be set in AbstractRequest class
                    'customField5' => $amount,
                    'customField6' => $currency,
                ],
            ]
        );
        
        $params['userDetails']      = $params['billingAddress'];
        $params['sessionToken']     = $this->orderData['sessionToken'];
        $params['orderId']          = isset($this->orderData['orderId']) ? $this->orderData['orderId'] : '';
        $params['clientRequestId']  = isset($this->orderData['clientRequestId'])
            ? $this->orderData['clientRequestId'] : '';
        
        $params['checksum'] = hash(
            $this->config->getConfigValue('hash'),
            $this->config->getMerchantId() 
                . $this->config->getMerchantSiteId() 
                . $params['clientRequestId']
                . $params['amount'] 
                . $params['currency'] 
                . $params['timeStamp'] 
                . $this->config->getMerchantSecretKey()
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
            'clientRequestId',
            'amount',
            'currency',
            'timeStamp',
        ];
    }
    
    /**
     * Get attribute options
     *
     * @param \Magento\Eav\Api\Data\AttributeInterface $attribute
     * @return array
     */
    private function getOptions(\Magento\Eav\Api\Data\AttributeInterface $attribute) : array
    {
        $return = [];

        try {
            $options = $attribute->getOptions();
            foreach ($options as $option) {
                if ($option->getValue()) {
                    $return[] = [
                        'value' => $option->getLabel(),
                        'label' => $option->getLabel(),
                        'parentAttributeLabel' => $attribute->getDefaultFrontendLabel()
                    ];
                }
            }

            return $return;
        } catch (\Exception $e) {
            $this->readerWriter->createLog($e->getMessage(), 'getOptions() Exception');
        }

        return $return;
    }
}
