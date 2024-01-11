<?php

namespace Nuvei\Checkout\Model\Request\Payment;

use Magento\Framework\Exception\PaymentException;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\AbstractResponse;
use Nuvei\Checkout\Model\Payment;
use Nuvei\Checkout\Model\Request\AbstractPayment;
use Nuvei\Checkout\Model\RequestInterface;

/**
 * Nuvei Checkout void payment request model.
 */
class Cancel extends AbstractPayment implements RequestInterface
{
    protected $readerWriter;
    protected $request;
    
    private $params; // in case of AutoVoid we will pass them all
    
    /**
     * Refund constructor.
     *
     * @param Config            $config
     * @param Curl              $curl
     * @param ResponseFactory   $responseFactory
     * @param OrderPayment      $orderPayment
     * @param Http              $request
     * @param ReaderWriter      $readerWriter
     */
    public function __construct(
        \Nuvei\Checkout\Model\Config $config,
        \Nuvei\Checkout\Lib\Http\Client\Curl $curl,
        \Nuvei\Checkout\Model\Response\Factory $responseFactory,
        \Magento\Sales\Model\Order\Payment $orderPayment,
        \Magento\Framework\App\Request\Http $request,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
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
    }
    
    public function setParams(array $params)
    {
        $this->params = $params;
        return $this;
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
        return AbstractRequest::PAYMENT_VOID_METHOD;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getResponseHandlerType()
    {
        return AbstractResponse::PAYMENT_VOID_HANDLER;
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getParams()
    {
        // we can create Void for Settle and Auth only!
        $orderPayment   = $this->orderPayment;
        $order          = $orderPayment->getOrder();
        
        // AutoVoid flow when we pass all params from the DMN Class
        if (!empty($this->params) && is_array($this->params)) {
            // set notify url
            if (0 == $this->config->getConfigValue('disable_notify_url')) {
                $this->params['urlDetails']['notificationUrl'] = $this->config->getCallbackDmnUrl(
                    $order->getIncrementId(),
                    $order->getStoreId()//,
                );
            }

            $this->params = array_merge_recursive($this->params, parent::getParams());

            return $this->params;
        }
        
        $ord_trans_addit_info   = $orderPayment->getAdditionalInformation(Payment::ORDER_TRANSACTIONS_DATA);
        $inv_id                 = $this->request->getParam('invoice_id');
        $trans_to_void_data     = [];
        $last_voidable          = [];
        
        if (!is_array($ord_trans_addit_info) || empty($ord_trans_addit_info)) {
            $msg = 'Void Error - wrong Order transaction additional info.';
            
            $this->readerWriter->createLog($ord_trans_addit_info, $msg);
            
            throw new PaymentException(__($msg));
        }
        
        foreach (array_reverse($ord_trans_addit_info) as $key => $trans) {
            if (strtolower($trans[Payment::TRANSACTION_STATUS]) == 'approved'
                && in_array(strtolower($trans[Payment::TRANSACTION_TYPE]), ['auth', 'settle', 'sale'])
                && empty($trans[Payment::IS_SUBSCR])
            ) {
                if (0 == $key) {
                    $last_voidable = $trans;
                }

                // settle
                if (!empty($trans['invoice_id'])
                    && !empty($inv_id)
                    && $trans['invoice_id'] == $inv_id
                ) {
                    $trans_to_void_data = $trans;
                    break;
                }
            }
        }
        
        /**
         * there was not settle Transaction, or we can not find transaction
         * based on Invoice ID. In this case use last voidable transaction.
         */
        if (empty($trans_to_void_data)) {
            $trans_to_void_data = $last_voidable;
        }
        
        if (empty($trans_to_void_data)) {
            $msg = 'Void Error - Missing mandatory data for the Void.';
            
            $this->readerWriter->createLog(
                [
                    '$ord_trans_addit_info' => $ord_trans_addit_info,
                    '$trans_to_void_data'   => $trans_to_void_data,
                    'error'                 => $msg,
                ],
                $msg
            );
            
            throw new PaymentException(__($msg));
        }
        
        $this->readerWriter->createLog([$order->getBaseTotalInvoiced(), $trans_to_void_data], 'Transaction to Cancel');
        
//        $amount     = (float) $trans_to_void_data[Payment::TRANSACTION_TOTAL_AMOUN];
//        $amount     = round($order->getBaseTotalInvoiced(), 2);
        $amount     = round($order->getBaseGrandTotal(), 2);
        $auth_code  = !empty($trans_to_void_data[Payment::TRANSACTION_AUTH_CODE])
            ? $trans_to_void_data[Payment::TRANSACTION_AUTH_CODE] : '';
        
        if (empty($amount) || $amount < 0) {
            $this->readerWriter->createLog(
                $trans_to_void_data,
                'Void error - Transaction does not contain total amount.'
            );
            
            throw new PaymentException(__('Void error - Transaction does not contain total amount.'));
        }
        
        $params = [
            'clientUniqueId'        => $order->getIncrementId(),
            'currency'              => $order->getBaseCurrencyCode(),
            'amount'                => $amount,
            'relatedTransactionId'  => $trans_to_void_data[Payment::TRANSACTION_ID],
            'authCode'              => $auth_code,
            'comment'               => '',
            'merchant_unique_id'    => $order->getIncrementId(),
            'customData'            => [
                'prev_status'   => $order->getStatus()
            ],
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
            'authCode',
            'comment',
            'urlDetails',
            'timeStamp',
        ];
    }
}
