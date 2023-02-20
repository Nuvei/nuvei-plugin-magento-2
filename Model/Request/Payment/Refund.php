<?php

namespace Nuvei\Checkout\Model\Request\Payment;

use Magento\Framework\Exception\PaymentException;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\AbstractResponse;
use Nuvei\Checkout\Model\Payment;

/**
 * Nuvei Checkout refund payment request model.
 */
class Refund extends \Nuvei\Checkout\Model\Request\AbstractPayment implements \Nuvei\Checkout\Model\RequestInterface
{
    protected $readerWriter;

    private $request;
    private $amount;

    /**
     * Refund constructor.
     *
     * @param Config                            $config
     * @param Curl                              $curl
     * @param RequestFactory                    $requestFactory
     * @param Factory                           $paymentRequestFactory
     * @param ResponseFactory                   $responseFactory
     * @param OrderPayment                      $orderPayment
     * @param TransactionRepositoryInterface    $transactionRepository
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
        $orderPayment           = $this->orderPayment;
        $ord_trans_addit_info   = $orderPayment->getAdditionalInformation(Payment::ORDER_TRANSACTIONS_DATA);
        $order                  = $orderPayment->getOrder();
        $trans_to_refund_data   = [];
        $inv_id                 = $this->request->getParam('invoice_id');
        
        if (!empty($ord_trans_addit_info) && is_array($ord_trans_addit_info)) {
            foreach (array_reverse($ord_trans_addit_info) as $trans) {
                if (strtolower($trans[Payment::TRANSACTION_STATUS]) == 'approved'
                    && in_array(strtolower($trans[Payment::TRANSACTION_TYPE]), ['sale', 'settle'])
                ) {
                    // sale
                    if (strtolower($trans[Payment::TRANSACTION_TYPE]) == 'sale') {
                        $trans_to_refund_data = $trans;
                        break;
                    } elseif (!empty($trans['invoice_id'])
                        && !empty($inv_id)
                        && $trans['invoice_id'] == $inv_id
                    ) { // settle
                        $trans_to_refund_data = $trans;
                        break;
                    }
                }
            }
        }
        
        if (empty($trans_to_refund_data[Payment::TRANSACTION_ID])) {
            $this->readerWriter->createLog(
                [
                    '$ord_trans_addit_info' => $ord_trans_addit_info,
                    '$trans_to_refund_data' =>$trans_to_refund_data
                ],
                'Refund Error - Transaction ID is empty.'
            );
            
            throw new PaymentException(__('Refund Error - Transaction ID is empty.'));
        }

        if (Payment::APM_METHOD_CC == $trans_to_refund_data[Payment::TRANSACTION_PAYMENT_METHOD]
            && empty($trans_to_refund_data[Payment::TRANSACTION_AUTH_CODE])
        ) {
            $msg = 'Refund Error - CC Transaction does not contain authorization code.';
            
            $this->readerWriter->createLog($trans_to_refund_data, $msg);
            
            throw new PaymentException(__($msg));
        }
        
        $auth_code = !empty($trans_to_refund_data[Payment::TRANSACTION_AUTH_CODE])
            ? $trans_to_refund_data[Payment::TRANSACTION_AUTH_CODE] : '';

        $params = [
            'clientUniqueId'        => $order->getIncrementId(),
            'currency'              => $order->getBaseCurrencyCode(),
            'amount'                => (float) $this->amount,
            'relatedTransactionId'  => $trans_to_refund_data[Payment::TRANSACTION_ID],
            'authCode'              => $auth_code,
            'comment'               => '',
            'merchant_unique_id'    => $order->getIncrementId(),
            'urlDetails'            => [
                'notificationUrl' => $this->config
                    ->getCallbackDmnUrl(
                        $order->getIncrementId(),
                        $order->getStoreId(),
                        ['invoice_id' => $inv_id]
                    ),
            ],
        ];

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
