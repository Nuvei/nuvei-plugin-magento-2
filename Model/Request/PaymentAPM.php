<?php

namespace Nuvei\Checkout\Model\Request;

use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\AbstractResponse;
use Nuvei\Checkout\Model\Payment;
use Nuvei\Checkout\Model\RequestInterface;

/**
 * Used from REST API calls.
 */
class PaymentApm extends AbstractRequest implements RequestInterface
{
    protected $requestFactory;

    private $urlDetails             = [];
    private $checkoutSession;
    private $paymentMethod;
    private $paymentMethodFields;
    private $savePaymentMethod;
    private $quoteId;
    private $quoteFactory;

    /**
     * @param Config            $config
     * @param Curl              $curl
     * @param ResponseFactory   $responseFactory
     * @param Factory           $requestFactory
     * @param ReaderWriter      $readerWriter
     * @param QuoteFactory      $quoteFactory
     */
    public function __construct(
        \Nuvei\Checkout\Model\Config $config,
        \Nuvei\Checkout\Lib\Http\Client\Curl $curl,
        \Nuvei\Checkout\Model\Response\Factory $responseFactory,
        \Nuvei\Checkout\Model\Request\Factory $requestFactory,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        \Magento\Quote\Model\QuoteFactory $quoteFactory
    ) {
        parent::__construct(
            $config,
            $curl,
            $responseFactory,
            $readerWriter
        );

        $this->requestFactory   = $requestFactory;
        $this->checkoutSession  = $checkoutSession;
        $this->quoteFactory     = $quoteFactory;
    }
    
    public function process()
    {
        $resp               = $this->sendRequest(true, true);
        $transactionStatus  = '';
        $return             = [
            'status' => $resp['status']
        ];
        
        $this->readerWriter->createLog($resp);
        
        if (!empty($resp['transactionStatus'])) {
            $transactionStatus = (string) $resp['transactionStatus'];
        }
        
        if (!empty($resp['redirectURL'])) {
            $return['redirectUrl'] = (string) $resp['redirectURL'];
        }
        elseif (!empty($resp['paymentOption']['redirectUrl'])) {
            $return['redirectUrl'] = (string) $resp['paymentOption']['redirectUrl'];
        }
        // some error
        else {
            if (!empty($resp['reason'])) {
                $return['message'] = $resp['reason'];
            }
            else {
                $return['message'] = 'Unexpected error.';
            }
        }
        
        return $return;
    }

    /**
     * @param string $paymentMethod
     * @return $this
     */
    public function setPaymentMethod($paymentMethod)
    {
        $this->paymentMethod = trim((string)$paymentMethod);
        return $this;
    }
    
    /**
     * Because this array includes also chosenApmMethod and savePm
     * params we will unset them here.
     * 
     * @param array $paymentMethodFields
     * @return $this
     */
    public function setPaymentMethodFields($paymentMethodFields)
    {
        if (isset($paymentMethodFields['chosenApmMethod'])) {
            unset($paymentMethodFields['chosenApmMethod']);
        }
        if (isset($paymentMethodFields['savePm'])) {
            unset($paymentMethodFields['savePm']);
        }
        
        $this->paymentMethodFields = $paymentMethodFields;
        return $this;
    }
    
    public function setSavePaymentMethod($savePaymentMethod)
    {
        $this->savePaymentMethod = $savePaymentMethod;
        return $this;
    }
    
    public function setQuoteId($quoteId = '')
    {
        $this->quoteId = $quoteId;
        
        return $this;
    }
    
    public function setUrlDetails($urlDetails = [])
    {
        $this->urlDetails = $urlDetails;
        
        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getRequestMethod()
    {
        return is_numeric($this->paymentMethod) ? self::PAYMENT_UPO_APM_METHOD : self::PAYMENT_APM_METHOD;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getResponseHandlerType()
    {
        return AbstractResponse::PAYMENT_APM_HANDLER;
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getParams()
    {
        $quote = empty($this->quoteId) ? $this->checkoutSession->getQuote()
            : $this->quoteFactory->create()->load($this->quoteId);
        
        $quotePayment   = $quote->getPayment();
        $order_data     = $quotePayment->getAdditionalInformation(Payment::CREATE_ORDER_DATA);
        
        $this->readerWriter->createLog([
            'quote id' => $this->quoteId,
            '$order_data' => $order_data,
        ]);
        
        if (empty($order_data['sessionToken'])) {
            $msg = 'PaymentApm Error - missing Session Token.';
            
            $this->readerWriter->createLog($order_data, $msg);
            
            throw new \Exception(__($msg));
        }
        
        $billingAddress     = $this->config->getQuoteBillingAddress($this->quoteId);
        $amount             = (string) number_format($this->config->getQuoteBaseTotal($this->quoteId), 2, '.', '');
        $reservedOrderId    = $quotePayment->getAdditionalInformation(Payment::TRANSACTION_ORDER_ID)
            ?: $this->config->getReservedOrderId();
        
        $params = [
            'clientUniqueId'    => $reservedOrderId . '_' . time(),
            'currency'          => $this->config->getQuoteBaseCurrency($this->quoteId),
            'amount'            => $amount,
            
            'items'             => [[
                'name'      => 'magento_order',
                'price'     => $amount,
                'quantity'  => 1,
            ]],
            
            'urlDetails'        => [
                'successUrl'        => !empty($this->urlDetails['successUrl'])
                    ? $this->urlDetails['successUrl'] : $this->config->getCallbackSuccessUrl($this->quoteId),
                'failureUrl'        => !empty($this->urlDetails['failureUrl'])
                    ? $this->urlDetails['failureUrl'] : $this->config->getCallbackErrorUrl($this->quoteId),
                'pendingUrl'        => !empty($this->urlDetails['pendingUrl'])
                    ? $this->urlDetails['pendingUrl'] : $this->config->getCallbackPendingUrl($this->quoteId),
                'backUrl'           => !empty($this->urlDetails['backUrl'])
                    ? $this->urlDetails['backUrl'] : $this->config->getBackUrl(),
                'notificationUrl'   => $this->config->getCallbackDmnUrl($reservedOrderId, null, [], $this->quoteId),
            ],
            
            'amountDetails'     => [
                'totalShipping'     => '0.00',
                'totalHandling'     => '0.00',
                'totalDiscount'     => '0.00',
                'totalTax'          => '0.00',
            ],
            
            'billingAddress'    => $billingAddress,
            'shippingAddress'   => $this->config->getQuoteShippingAddress($this->quoteId),
            'userDetails'       => $billingAddress,
            
            'sessionToken'      => $order_data['sessionToken'],
            'paymentMethod'     => $this->paymentMethod,
        ];
        
        // UPO APM
        if (is_numeric($this->paymentMethod)) {
            $params['paymentOption']['userPaymentOptionId'] = $this->paymentMethod;
            $params['userTokenId'] = $billingAddress['email'];
        } elseif (!empty($this->paymentMethodFields)) {
            $params['userAccountDetails'] = $this->paymentMethodFields;
        }
        
        // APM
        if ((int) $this->savePaymentMethod === 1) {
            $params['userTokenId'] = $billingAddress['email'];
        }

        return array_merge_recursive($params, parent::getParams());
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
}
