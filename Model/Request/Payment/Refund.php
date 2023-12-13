<?php

namespace Nuvei\Checkout\Model\Request\Payment;

use Magento\Framework\Exception\PaymentException;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\AbstractResponse;
use Nuvei\Checkout\Model\Payment;
use Nuvei\Checkout\Model\Request\AbstractPayment;
use Nuvei\Checkout\Model\RequestInterface;

/**
 * Nuvei Checkout refund payment request model.
 */
class Refund extends AbstractPayment implements RequestInterface
{
    protected $readerWriter;

    private $request;
    private $amount;

    /**
     * Refund constructor.
     *
     * @param Config                            $config
     * @param Curl                              $curl
     * @param ResponseFactory                   $responseFactory
     * @param OrderPayment                      $orderPayment
     * @param Http                              $request
     * @param float                             $amount
     */
    public function __construct(
        \Nuvei\Checkout\Model\Config $config,
        \Nuvei\Checkout\Lib\Http\Client\Curl $curl,
        \Nuvei\Checkout\Model\Response\Factory $responseFactory,
        \Magento\Sales\Model\Order\Payment $orderPayment,
        \Magento\Framework\App\Request\Http $request,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        $amount = 0.0
    ) {
        parent::__construct(
            $config,
            $curl,
            $responseFactory,
            $orderPayment,
            $readerWriter
        );

        $this->request      = $request;
        $this->readerWriter = $readerWriter;
        $this->amount       = $amount;
    }
    
    public function process()
    {
        return $this->sendRequest(true);
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getRequestMethod()
    {
        return AbstractRequest::PAYMENT_REFUND_METHOD;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getResponseHandlerType()
    {
        return AbstractResponse::PAYMENT_REFUND_HANDLER;
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
        if (!in_array(
            $this->orderPayment->getAdditionalInformation(Payment::TRANSACTION_PAYMENT_METHOD),
            Payment::PAYMETNS_SUPPORT_REFUND
        )) {
            $msg = 'Refund Error - The Transaction Payment method does not support Refund.';
            
            $this->readerWriter->createLog(
                $this->orderPayment->getAdditionalInformation(Payment::TRANSACTION_PAYMENT_METHOD),
                $msg
            );
            
            throw new PaymentException(__($msg));
        }
        
        $ord_trans_addit_info = $this->orderPayment->getAdditionalInformation(Payment::ORDER_TRANSACTIONS_DATA);
        
        $this->readerWriter->createLog($ord_trans_addit_info, 'ord_trans_addit_info');
        
        if (empty($ord_trans_addit_info) || !is_array($ord_trans_addit_info)) {
            $msg = 'Refund Error - missing specific Nuvei Order transaction data.';
            
            $this->readerWriter->createLog($ord_trans_addit_info, $msg);
            
            throw new PaymentException(__($msg));
        }
        
        $order                  = $this->orderPayment->getOrder();
        $inv_id                 = $this->request->getParam('invoice_id');
        $trans_to_refund_data   = [];
        
        foreach (array_reverse($ord_trans_addit_info) as $trans) {
            $transaction_type = strtolower($trans[Payment::TRANSACTION_TYPE]);
            
            if (strtolower($trans[Payment::TRANSACTION_STATUS]) == 'approved'
                && in_array($transaction_type, ['sale', 'settle'])
                && empty($trans[Payment::IS_SUBSCR])
            ) {
                $trans_to_refund_data = $trans;
                break;
            }
        }
        
        if (empty($trans_to_refund_data[Payment::TRANSACTION_ID])) {
            $msg = 'Refund Error - Transaction ID is empty.';
            
            $this->readerWriter->createLog($trans_to_refund_data, $msg);
            
            throw new PaymentException(__($msg));
        }

        $params = [
            'clientUniqueId'        => $order->getIncrementId(),
            'currency'              => $order->getBaseCurrencyCode(),
            'amount'                => (float) $this->amount,
            'relatedTransactionId'  => $trans_to_refund_data[Payment::TRANSACTION_ID],
            'merchant_unique_id'    => $order->getIncrementId(),
        ];
        
        // set notify url
        if (0 == $this->config->getConfigValue('disable_notify_url')) {
            $params['urlDetails']['notificationUrl'] = $this->config->getCallbackDmnUrl(
                $order->getIncrementId(),
                $order->getStoreId(),
                ['invoice_id' => $inv_id]
            );
        }

        $params = array_merge_recursive($params, parent::getParams());

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
            'clientUniqueId',
            'amount',
            'currency',
            'relatedTransactionId',
            'urlDetails',
            'timeStamp',
        ];
    }
}
