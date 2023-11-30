<?php

namespace Nuvei\Checkout\Controller\Payment\Callback;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Nuvei\Checkout\Model\Payment;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Nuvei\Checkout\Model\AbstractRequest;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Exception\PaymentException;

/**
 * Nuvei Checkout payment redirect controller.
 */
class Dmn extends Action implements CsrfAwareActionInterface
{
    private $is_partial_settle  = false;
    private $curr_trans_info    = []; // collect the info for the current transaction (action)
    private $refund_msg         = '';
    private $orderIncrementId   = 0;
    private $quoteId            = 0;
    private $loop_tries         = 0; // count loops
    private $loop_wait_time     = 3; // sleep time in seconds
    private $loop_max_tries; // loop max tries count
    
    /**
     * @var CurrencyFactory
     */
    private $currencyFactory;
    
    /**
     * @var ModuleConfig
     */
    private $moduleConfig;

    /**
     * @var CaptureCommand
     */
    private $captureCommand;

    /**
     * @var DataObjectFactory
     */
    private $dataObjectFactory;

    /**
     * @var CartManagementInterface
     */
    private $cartManagement;

    /**
     * @var JsonFactory
     */
    private $jsonResultFactory;
    
    private $transaction;
    private $invoiceService;
    private $invoiceRepository;
    private $transObj;
    private $quoteFactory;
    private $request;
    private $orderRepo;
    private $searchCriteriaBuilder;
    private $orderResourceModel;
    private $requestFactory;
    private $httpRequest;
    private $order;
    private $orderPayment;
    private $transactionType;
    private $sc_transaction_type;
    private $jsonOutput;
    private $paymentModel;
    private $transactionRepository;
    private $params;
    private $readerWriter;

    /**
     * Object constructor.
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Nuvei\Checkout\Model\Config $moduleConfig,
        \Magento\Sales\Model\Order\Payment\State\CaptureCommand $captureCommand,
        \Magento\Framework\DataObjectFactory $dataObjectFactory,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory,
        \Magento\Framework\DB\Transaction $transaction,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Sales\Api\InvoiceRepositoryInterface $invoiceRepository,
        \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transObj,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        RequestInterface $request,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepo,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Magento\Sales\Model\ResourceModel\Order $orderResourceModel,
        \Nuvei\Checkout\Model\Request\Factory $requestFactory,
        \Magento\Framework\App\Request\Http $httpRequest,
        \Nuvei\Checkout\Model\Payment $paymentModel,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        \Magento\Sales\Model\Order\Payment\Transaction\Repository $transactionRepository,
        \Magento\Directory\Model\CurrencyFactory $currencyFactory
    ) {
        $this->moduleConfig             = $moduleConfig;
        $this->captureCommand           = $captureCommand;
        $this->dataObjectFactory        = $dataObjectFactory;
        $this->cartManagement           = $cartManagement;
        $this->jsonResultFactory        = $jsonResultFactory;
        $this->transaction              = $transaction;
        $this->invoiceService           = $invoiceService;
        $this->invoiceRepository        = $invoiceRepository;
        $this->transObj                 = $transObj;
        $this->quoteFactory             = $quoteFactory;
        $this->request                  = $request;
        $this->_eventManager            = $eventManager;
        $this->orderRepo                = $orderRepo;
        $this->searchCriteriaBuilder    = $searchCriteriaBuilder;
        $this->orderResourceModel       = $orderResourceModel;
        $this->requestFactory           = $requestFactory;
        $this->httpRequest              = $httpRequest;
        $this->paymentModel             = $paymentModel;
        $this->readerWriter             = $readerWriter;
        $this->transactionRepository    = $transactionRepository;
        $this->currencyFactory          = $currencyFactory;
        
        parent::__construct($context);
        
		$this->loop_max_tries  = $this->moduleConfig->isTestModeEnabled() ? 10 : 4;
    }
    
    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(
        RequestInterface $request
    ): ?InvalidRequestException {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    /**
     * @return JsonFactory
     */
    public function execute()
    {
        $this->jsonOutput = $this->jsonResultFactory->create();
        $this->jsonOutput->setHttpResponseCode(200);
        
        // set some variables
        $order_status   = '';
        $order_tr_type  = '';
        $last_record    = []; // last transaction data
        
        if (!$this->moduleConfig->getConfigValue('active')) {
            $msg = 'DMN Error - Nuvei payment module is not active!';
            
            $this->readerWriter->createLog($msg);
            $this->jsonOutput->setData($msg);

            return $this->jsonOutput;
        }
        
        try {
            $this->params = array_merge(
                $this->request->getParams(),
                $this->request->getPostValue()
            );
            
            $this->readerWriter->createLog([
                    'Request params'    => $this->params,
                    'REMOTE_ADDR'       => @$_SERVER['REMOTE_ADDR'],
                    'REMOTE_PORT'       => @$_SERVER['REMOTE_PORT'],
                    'REQUEST_METHOD'    => @$_SERVER['REQUEST_METHOD'],
                    'HTTP_USER_AGENT'   => @$_SERVER['HTTP_USER_AGENT'],
                ],
                'DMN params:'
            );
            
            ### DEBUG
//            $this->jsonOutput->setData('DMN manually stopped.');
//            return $this->jsonOutput;
            ### DEBUG
            
            if (!empty($this->params['type']) && 'CARD_TOKENIZATION' == $this->params['type']) {
                $msg = 'DMN report - this is Card Tokenization DMN.';
            
                $this->readerWriter->createLog($msg);
                $this->jsonOutput->setData($msg);

                return $this->jsonOutput;
            }
            
            // try to find Status
            $status = !empty($this->params['Status']) ? strtolower($this->params['Status']) : null;
            
            if ('pending' == $status) {
                $msg = 'DMN report - Pending DMN, wait for the next one.';
            
                $this->readerWriter->createLog($msg);
                $this->jsonOutput->setData($msg);

                return $this->jsonOutput;
            }
            
            // try to validate the Cheksum
            $success = $this->validateChecksum();
            
            if (!$success) {
                return $this->jsonOutput;
            }
            // /try to validate the Cheksum
            
            /**
             * Here will be set $this->orderIncrementId or $this->quoteId
             */
            $this->getOrderIdentificators();
            
            /**
             * Try to create the Order.
             * With this call if there are no errors we set:
             *
             * $this->order
             * $this->orderPayment
             */
            $success = $this->getOrCreateOrder();
            
            if (!$success) {
                return $this->jsonOutput;
            }
            // /Try to create the Order.
            
            # For Auth and Settle check the internal Nuvei Order ID
            if (in_array($this->params['transactionType'], ['Auth', 'Sale'])) {
                $createOrderData = $this->orderPayment->getAdditionalInformation(Payment::CREATE_ORDER_DATA);
                
                if (empty($this->params['PPP_TransactionID'])
                    || empty($createOrderData['orderId'])
                    || $createOrderData['orderId'] != $this->params['PPP_TransactionID']
                ) {
                    $msg = 'DMN Error - PPP_TransactionID is different from the saved for the current Order.';
            
                    $this->readerWriter->createLog(
                        [
                            'PPP_TransactionID' => @$this->params['PPP_TransactionID'],
                            'orderId'           => @$createOrderData['orderId'],
                        ],
                        $msg
                    );
                    $this->jsonOutput->setData($msg);

                    return $this->jsonOutput;
                }
            }
            # /For Auth and Settle check the internal Nuvei Order ID
            
            // set additional data
            if (!empty($this->params['payment_method'])) {
                $this->orderPayment->setAdditionalInformation(
                    Payment::TRANSACTION_PAYMENT_METHOD,
                    $this->params['payment_method']
                );
            }
            if (!empty($this->params['customField2'])) {
                $this->orderPayment->setAdditionalInformation(
                    Payment::SUBSCR_DATA,
                    json_decode($this->params['customField2'], true)
                );
            }
            
            $this->orderPayment->save();
            // /set additional data
            
            // the saved Additional Info for the transaction
            $ord_trans_addit_info = $this->orderPayment->getAdditionalInformation(Payment::ORDER_TRANSACTIONS_DATA);
            
            $this->readerWriter->createLog(
                $this->orderPayment->getAdditionalInformation(),
                'DMN order payment AdditionalInformation'
            );
            
            if (empty($ord_trans_addit_info) || !is_array($ord_trans_addit_info)) {
                $ord_trans_addit_info = [];
            } else {
                $last_record    = end($ord_trans_addit_info);
                
                $order_status   = !empty($last_record[Payment::TRANSACTION_STATUS])
                    ? $last_record[Payment::TRANSACTION_STATUS] : '';
                
                $order_tr_type  = !empty($last_record[Payment::TRANSACTION_TYPE])
                    ? $last_record[Payment::TRANSACTION_TYPE] : '';
            }
            
            // do not save same DMN data more than once
            if (isset($this->params['TransactionID'])
                && array_key_exists($this->params['TransactionID'], $ord_trans_addit_info)
            ) {
                $msg = 'Same transaction already saved. Stop proccess';
                
                $this->readerWriter->createLog($msg);
                $this->jsonOutput->setData($msg);

                return $this->jsonOutput;
            }
            
            // prepare current transaction data for save
            $this->prepareCurrTrInfo();
            
            // check for Subscription State DMN
            $stop = $this->processSubscrDmn($ord_trans_addit_info);
            
            if ($stop) {
                $msg = 'Process Subscr DMN ends for order #' . $this->orderIncrementId;
                
                $this->readerWriter->createLog($msg);
                $this->jsonOutput->setData($msg);

                return $this->jsonOutput;
            }
            // /check for Subscription State DMN
            
            if (!in_array($status, ['declined', 'error', 'approved', 'success'])) { // UNKNOWN DMN
                $msg = 'DMN for Order #' . $this->orderIncrementId . ' was not recognized.';
            
                $this->readerWriter->createLog($msg);
                $this->jsonOutput->setData($msg);

                return $this->jsonOutput;
            }
            // /try to find Status
            
            if (empty($this->params['transactionType'])) {
                $msg = 'DMN error - missing Transaction Type.';
            
                $this->readerWriter->createLog($msg);
                $this->jsonOutput->setData($msg);

                return $this->jsonOutput;
            }
            
            if (empty($this->params['TransactionID'])) {
                $msg = 'DMN error - missing Transaction ID.';
            
                $this->readerWriter->createLog($msg);
                $this->jsonOutput->setData($msg);

                return $this->jsonOutput;
            }
            
            $tr_type_param = strtolower($this->params['transactionType']);

            # Subscription transaction DMN
            if (!empty($this->params['dmnType'])
                && 'subscriptionPayment' == $this->params['dmnType']
                && !empty($this->params['TransactionID'])
            ) {
                $this->order->addStatusHistoryComment(
                    __('<b>Subscription Payment</b> with Status ') . $this->params['Status']
                        . __(' was made. <br/>Plan ID: ') . $this->params['planId']
                        . __(', <br/>Subscription ID: ') . $this->params['subscriptionId']
                        . __(', <br/>Amount: ') . $this->params['totalAmount'] . ' ' . $this->params['currency'] 
                        . __(', <br/>TransactionId: ') . $this->params['TransactionID']
                );
                
                $this->orderResourceModel->save($this->order);
                
                $msg = 'DMN process end for order #' . $this->orderIncrementId;
            
                $this->readerWriter->createLog($msg);
                $this->jsonOutput->setData($msg);

                return $this->jsonOutput;
            }
            # /Subscription transaction DMN
            
            // do not overwrite Order status
            $stop = $this->keepOrderStatusFromOverride( $order_tr_type, $order_status, $status);
            
            if ($stop) {
                return $this->jsonOutput;
            }
            // /do not overwrite Order status

            // compare them later
            $order_total    = round((float) $this->order->getBaseGrandTotal(), 2);
            $dmn_total      = round((float) $this->params['totalAmount'], 2);
            
            // PENDING TRANSACTION
            if ($status === "pending") {
                $this->order
                    ->setState(Order::STATE_NEW)
                    ->setStatus('pending');
            }
            
            // APPROVED TRANSACTION
            if (in_array($status, ['approved', 'success'])) {
                $this->sc_transaction_type = Payment::SC_PROCESSING;
                
                // try to recognize DMN type
//                $this->processAuthDmn($order_total, $dmn_total, $params); // AUTH
                $this->processAuthDmn(); // AUTH
//                $this->processSaleAndSettleDMN($order_total, $dmn_total, $last_record); // SALE and SETTLE
                $this->processSaleAndSettleDMN(); // SALE and SETTLE
                $this->processVoidDmn($tr_type_param); // VOID
                $this->processRefundDmn($ord_trans_addit_info); // REFUND/CREDIT
                
                $this->order->setStatus($this->sc_transaction_type);

                $msg_transaction = '<b>';
                
                if ($this->is_partial_settle === true) {
                    $msg_transaction .= __("Partial ");
                }
                
                $msg_transaction .= __($this->params['transactionType']) . ' </b> request.<br/>';

                $this->order->addStatusHistoryComment(
                    $msg_transaction
                        . __("Response status: ") . ' <b>' . $this->params['Status'] . '</b>.<br/>'
                        . __('Payment Method: ') . $this->params['payment_method'] . '.<br/>'
                        . __('Transaction ID: ') . $this->params['TransactionID'] . '.<br/>'
                        . __('Related Transaction ID: ') . $this->params['relatedTransactionId'] . '.<br/>'
                        . __('Transaction Amount: ') . number_format($this->params['totalAmount'], 2, '.', '')
                        . ' ' . $this->params['currency'] . '.'
                        . $this->refund_msg,
                    $this->sc_transaction_type
                );
            }
            
            // DECLINED/ERROR TRANSACTION
            if (in_array($status, ['declined', 'error'])) {
                $this->processDeclinedSaleOrSettleDmn();
                
                $this->params['ErrCode']      = (isset($this->params['ErrCode'])) ? $this->params['ErrCode'] : "Unknown";
                $this->params['ExErrCode']    = (isset($this->params['ExErrCode'])) ? $this->params['ExErrCode'] : "Unknown";
                
                $this->order->addStatusHistoryComment(
                    '<b>' . $this->params['transactionType'] . '</b> '
                        . __("request, response status is") . ' <b>' . $this->params['Status'] . '</b>.<br/>('
                        . __('Code: ') . $this->params['ErrCode'] . ', '
                        . __('Reason: ') . $this->params['ExErrCode'] . '.',
                    $this->sc_transaction_type
                );
            }
            
            $ord_trans_addit_info[$this->params['TransactionID']] = $this->curr_trans_info;
        } catch (\Exception $e) {
            $msg = $e->getMessage();

            $this->readerWriter->createLog(
                $msg . "\n\r" . $e->getTraceAsString(),
                'DMN Excception:',
                'WARN'
            );

            $this->jsonOutput->setData('Error: ' . $msg);
            $this->order->addStatusHistoryComment($msg);
        }
        
        $resp_save_data = $this->finalSaveData($ord_trans_addit_info);
        
        if (!$resp_save_data) {
            return $this->jsonOutput;
        }
        
        $this->readerWriter->createLog('DMN process end for order #' . $this->orderIncrementId);
        $this->jsonOutput->setData('DMN process end for order #' . $this->orderIncrementId);

        # try to create Subscription plans
        $this->createSubscription($this->orderIncrementId);

        return $this->jsonOutput;
    }
    
    /**
     */
    private function processAuthDmn()
    {
        if ('auth' != strtolower($this->params['transactionType'])) {
            return;
        }
        
        $this->sc_transaction_type = Payment::SC_AUTH;
        
        if ($this->fraudCheck()) {
            $this->sc_transaction_type = 'fraud';

            $this->order->addStatusHistoryComment(
                __('<b>Attention!</b> - There is a problem with the Order. The Order amount is ')
                    . $this->order->getOrderCurrencyCode() . ' '
                    . round((float) $this->order->getBaseGrandTotal(), 2) . ', ' 
                    . __('but the Authorized amount is ') . $this->params['currency'] 
                    . ' ' . $this->params['totalAmount'],
                $this->sc_transaction_type
            );
        }
        # /Fraud check
        
        $this->orderPayment
            ->setAuthAmount($this->params['totalAmount'])
            ->setIsTransactionPending(true)
            ->setIsTransactionClosed(false);

        // set transaction
        $transaction = $this->transObj->setPayment($this->orderPayment)
            ->setOrder($this->order)
            ->setTransactionId($this->params['TransactionID'])
            ->setFailSafe(true)
            ->build(Transaction::TYPE_AUTH);

        $transaction->save();
    }
    
    /**
     */
    private function processSaleAndSettleDMN()
    {
        $order_total    = round((float) $this->order->getBaseGrandTotal(), 2);
        $dmn_total      = round((float) $this->params['totalAmount'], 2);
        $tr_type_param = strtolower($this->params['transactionType']);
        
        if (!in_array($tr_type_param, ['sale', 'settle']) || isset($this->params['dmnType'])) {
            return;
        }
        
        $this->readerWriter->createLog('processSaleAndSettleDMN()');
        
        $invCollection  = $this->order->getInvoiceCollection();
        // wait for magento to finish its work and prevent DB deadlock
        do {
            $this->loop_tries++;
            
            if (Payment::SC_PROCESSING != $this->order->getStatus()) {
                $this->readerWriter->createLog(
                    [
                        'order status'          => $this->order->getStatus(),
                        'tryouts'               => $this->loop_tries,
                        'count($invCollection)' => count($invCollection),
                    ],
                    'processSaleAndSettleDMN() wait for Magento to set Proccessing status.'
                );
                
                sleep($this->loop_wait_time);
                $this->getOrCreateOrder();
            }
        }
        while(Payment::SC_PROCESSING == $this->order->getStatus() && $this->loop_tries < $this->loop_max_tries);
        
        $this->readerWriter->createLog(
            [
                'order status'          => $this->order->getStatus(),
                'tryouts'               => $this->loop_tries,
                'count($invCollection)' => count($invCollection),
            ]
            , 'processSaleAndSettleDMN() - after the Order Status check.');
        
        $this->sc_transaction_type  = Payment::SC_SETTLED;
        
        $dmn_inv_id         = $this->httpRequest->getParam('invoice_id');
        $is_cpanel_settle   = false;
        
        if (!empty($this->params["customData"]) || 'store-request' != $this->params["customData"]) {
            $is_cpanel_settle = true;
        }
        
//        if (!empty($this->params["merchant_unique_id"] && isset($this->params["order"]))
//            && $this->params["merchant_unique_id"] != $this->params["order"]
//        ) {
//            $is_cpanel_settle = true;
//        }

        if ($this->params["payment_method"] == 'cc_card') {
            $this->order->setCanVoidPayment(true);
            $this->orderPayment->setCanVoid(true);
        }
        
        // add Partial Settle flag
        if ('settle' == $tr_type_param
            && ($order_total - round(floatval($this->params['totalAmount']), 2) > 0.00)
        ) {
            $this->is_partial_settle = true;
        }
        // in case of Sale check the currency and the amount
        else {
//            $order_curr = $this->order->getQuoteBaseCurrency();
//            $fraud      = false;
            // check the total
//            if ($order_total != $dmn_total
//                && isset($this->params['customField5'])
//                && $order_total != $this->params['customField5']
//            ) {
//                $fraud = true;
//            }
//            
//            // check the currency
//            if ($order_curr != $this->params['currency']
//                && isset($this->params['customField6'])
//                && $order_curr != $this->params['customField6']
//            ) {
//                $fraud = true;
//            }
            
            if ($this->fraudCheck()) {
                $this->sc_transaction_type = 'fraud';

                $this->order->addStatusHistoryComment(
                    __('<b>Attention!</b> - There is a problem with the Order. The Order amount is ')
                    . $this->order->getOrderCurrencyCode() . ' '
                    . $order_total . ', ' . __('but the Paid amount is ')
                    . $this->params['currency'] . ' ' . $dmn_total,
                    $this->sc_transaction_type
                );
            }
        }

        // there are invoices
        if (count($invCollection) > 0 && !$is_cpanel_settle) {
            $this->readerWriter->createLog('There are Invoices');
            
            foreach ($invCollection as $invoice) {
                // Settle
                if ($dmn_inv_id == $invoice->getId()) {
                    $this->curr_trans_info['invoice_id'] = $invoice->getId();

                    $this->readerWriter->createLog([
                        '$dmn_inv_id' => $dmn_inv_id,
                        '$invoice->getId()' => $invoice->getId()
                    ]);
                    
                    $invoice->setCanVoidFlag(true);
                    $invoice
                        ->setTransactionId($this->params['TransactionID'])
                        ->setState(Invoice::STATE_PAID)
                        ->pay();
                    
                    $this->invoiceRepository->save($invoice);
                    
                    return;
                }
            }
            
            return;
        }
        
        // Force Invoice creation when we have CPanel Partial Settle
        if (!$this->order->canInvoice() && !$is_cpanel_settle) {
            $this->readerWriter->createLog('We can NOT create invoice.');
            return;
        }
        
        $this->readerWriter->createLog('There are no Invoices');
        
        // there are not invoices, but we can create
//        if (
////            ( $this->order->canInvoice() || $is_cpanel_settle )
////            && (
//            (
//                'sale' == $tr_type_param // Sale flow
//                || ( // APMs flow
//                    $this->params["order"] == $this->params["merchant_unique_id"]
//                    && $this->params["payment_method"] != 'cc_card'
//                )
//                || $is_cpanel_settle
//            )
        if ('sale' == $tr_type_param
            || $this->params["payment_method"] != 'cc_card'
            || $is_cpanel_settle
        ) {
            $this->readerWriter->createLog('We can create Invoice');
            
            $this->orderPayment
                ->setIsTransactionPending(0)
                ->setIsTransactionClosed(0);
            
            $invoice = $this->invoiceService->prepareInvoice($this->order);
            $invoice->setCanVoidFlag(true);
            
            $invoice
                ->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE)
                ->setTransactionId($this->params['TransactionID'])
                ->setState(Invoice::STATE_PAID);
            
            // in case of Cpanel Partial Settle
            if ($is_cpanel_settle && (float) $this->params['totalAmount'] < $order_total) {
                $order_total = round((float) $this->params['totalAmount'], 2);
            }
            
            $invoice
                ->setBaseSubtotal($this->order->getBaseSubtotal())
                ->setSubtotal($this->order->getSubtotal())
                ->setBaseGrandTotal($this->order->getBaseGrandTotal())
                ->setGrandTotal($this->order->getGrandTotal());
            
            $invoice->register();
            $invoice->getOrder()->setIsInProcess(true);
            $invoice->pay();
            
            $transactionSave = $this->transaction
                ->addObject($invoice)
                ->addObject($invoice->getOrder());
            
            $transactionSave->save();

            $this->curr_trans_info['invoice_id'] = $invoice->getId();

            // set transaction
            $transaction = $this->transObj
                ->setPayment($this->orderPayment)
                ->setOrder($this->order)
                ->setTransactionId($this->params['TransactionID'])
                ->setFailSafe(true)
                ->build(Transaction::TYPE_CAPTURE);

            $transaction->save();

//            $tr_type    = $this->orderPayment->addTransaction(Transaction::TYPE_CAPTURE);
//            $msg        = $this->orderPayment->prependMessage($message);
//
//            $this->orderPayment->addTransactionCommentsToOrder($tr_type, $msg);
            
            return;
        }
    }
    
    /**
     *
     * @param string $tr_type_param
     * @return void
     */
    private function processVoidDmn($tr_type_param)
    {
        if ('void' !=  $tr_type_param) {
            return;
        }
        
        $this->readerWriter->createLog('processVoidDmn()');
        
        // wait Magento to set its Canceled status so our DMN can change it to Nuvei Voided
        do {
            $this->loop_tries++;
            
            if (Payment::SC_PROCESSING != $this->order->getStatus()) {
                $this->readerWriter->createLog(
                    [
                        'order status'  => $this->order->getStatus(),
                        'tryouts'       => $this->loop_tries,
                    ],
                    'processVoidDmn() wait for Magento to set Proccessing status.'
                );
                
                sleep($this->loop_wait_time);
                $this->getOrCreateOrder();
            }
        }
        while(Payment::SC_PROCESSING == $this->order->getStatus() && $this->loop_tries < $this->loop_max_tries);
        
        $this->transactionType        = Transaction::TYPE_VOID;
        $this->sc_transaction_type    = Payment::SC_VOIDED;

        // set the Canceld Invoice
        $this->curr_trans_info['invoice_id'] = $this->httpRequest->getParam('invoice_id');

        // mark the Order Invoice as Canceld
        $invCollection = $this->order->getInvoiceCollection();

        $this->readerWriter->createLog(
            [
                'invoice_id'        => $this->curr_trans_info['invoice_id'],
                '$invCollection'    => count($invCollection)
            ],
            'Void DMN data:'
        );

        if (!empty($invCollection)) {
            foreach ($invCollection as $invoice) {
                $this->readerWriter->createLog($invoice->getId(), 'Invoice');

                if ($invoice->getId() == $this->curr_trans_info['invoice_id']) {
                    $this->readerWriter->createLog($invoice->getId(), 'Invoice to be Canceld');

                    $invoice->setState(Invoice::STATE_CANCELED);
                    $this->invoiceRepository->save($invoice);

                    break;
                }
            }
        }
        // mark the Order Invoice as Canceld END
        
        $this->order->setData('state', Order::STATE_CLOSED);

        // Cancel active Subscriptions, if there are any
        $succsess = $this->paymentModel->cancelSubscription($this->orderPayment);

        // if we cancel any subscription set state Close
//        if ($succsess) {
//            $this->order->setData('state', Order::STATE_CLOSED);
//        }
    }
    
    /**
     * @param array $ord_trans_addit_info   Previous transactions data.
     */
    private function processRefundDmn($ord_trans_addit_info)
    {
        if (!in_array(strtolower($this->params['transactionType']), ['credit', 'refund'])) {
            return;
        }
        
        $this->readerWriter->createLog('', 'processRefundDmn', 'INFO');
        
        $this->transactionType      = Transaction::TYPE_REFUND;
        $this->sc_transaction_type  = Payment::SC_REFUNDED;
        $total_amount               = (float) $this->params['totalAmount'];
        
        if ( (!empty($this->params['totalAmount']) && 'cc_card' == $this->params["payment_method"])
            || false !== strpos($this->params["merchant_unique_id"], 'gwp')
        ) {
            $this->refund_msg = '<br/>Refunded amount: '
                . number_format($this->params['totalAmount'], 2, '.', '') . ' ' . $this->params['currency'];
        }
        
        // set Order Refund amounts
        foreach($ord_trans_addit_info as $tr) {
            if(in_array(strtolower($tr['transaction_type']), ['credit', 'refund'])) {
                $total_amount += $tr['total_amount']; // this is in Base value
            }
        }
        
        $converted_amount = $total_amount;
        
        $this->order->setBaseTotalRefunded($total_amount);
        
        if($this->order->getOrderCurrencyCode() != $this->order->getBaseCurrencyCode()) {
            // Get rate Base to Order Curr
            $rate = $this->currencyFactory->create()
                ->load($this->order->getBaseCurrencyCode())->getAnyRate($this->order->getOrderCurrencyCode());
            // Get amount in Order curr
            $converted_amount = $total_amount * $rate;
        }
        
        $this->order->setTotalRefunded($converted_amount);
        // /set Order Refund amounts

        $this->curr_trans_info['invoice_id'] = $this->httpRequest->getParam('invoice_id');
    }
    
    private function processDeclinedSaleOrSettleDmn()
    {
        $this->readerWriter->createLog('processDeclinedSaleOrSettleDmn()');
        
        $invCollection  = $this->order->getInvoiceCollection();
        $dmn_inv_id     = (int) $this->httpRequest->getParam('invoice_id');
        
        try {
            if ('Settle' == $this->params['transactionType']) {
                $this->sc_transaction_type = Payment::SC_AUTH;
                
                foreach ($invCollection as $invoice) {
                    if ($dmn_inv_id == $invoice->getId()) {
                        $invoice
                            ->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE)
                            ->setTransactionId($this->params['TransactionID'])
                            ->setState(Invoice::STATE_PAID);
                        
                        $this->invoiceRepository->save($invoice);
                        
                        break;
                    }
                }
            } elseif ('Sale' == $this->params['transactionType']) {
                $invCollection                          = $this->order->getInvoiceCollection();
                $invoice                                = current($invCollection);
                $this->curr_trans_info['invoice_id'][]  = $invoice->getId();
                $this->sc_transaction_type              = Payment::SC_CANCELED;

                $invoice
                    ->setTransactionId($this->params['TransactionID'])
                    ->setState(Invoice::STATE_CANCELED)
                ;
                
                $this->invoiceRepository->save($invoice);
            }
        } catch (\Exception $ex) {
            $this->readerWriter->createLog($ex->getMessage(), 'processDeclinedSaleOrSettleDmn() Exception.');
            return;
        }
        
        // there are invoices
//        if (count($invCollection) > 0) {
//            $this->readerWriter->createLog(count($invCollection), 'The Invoices count is');
//
//            foreach ($this->order->getInvoiceCollection() as $invoice) {
//                // Sale
//                if (0 == $dmn_inv_id) {
//                    $this->curr_trans_info['invoice_id'][] = $invoice->getId();
//
//                    $this->sc_transaction_type = Payment::SC_CANCELED;
//
//                    $invoice
//                        ->setTransactionId($this->params['TransactionID'])
//                        ->setState(Invoice::STATE_CANCELED)
//                        ->pay()
//                        ->save()
//                    ;
//
//
//
//                } elseif ($dmn_inv_id == $invoice->getId()) { // Settle
//                    $this->curr_trans_info['invoice_id'][] = $invoice->getId();
//
//                    $this->sc_transaction_type = Payment::SC_AUTH;
//
//                    $this->readerWriter->createLog('Declined Settle');
//
//                    $invoice
////                        ->setTransactionId($this->params['TransactionID'])
////                        ->setState(Invoice::STATE_CANCELED)
//                        ->setState(Invoice::STATE_OPEN)
////                        ->pay()
////                        ->save()
//                    ;
//
//                    $this->invoiceRepository->save($invoice);
//
//                    break;
//                }
//            }
//        }
    }
    
    /**
     * Work with Subscription status DMN.
     *
     * @param array $ord_trans_addit_info
     *
     * @return bool
     */
    private function processSubscrDmn($ord_trans_addit_info)
    {
        if (empty($this->params['dmnType'])
            || 'subscription' != $this->params['dmnType']
            || empty($this->params['subscriptionState'])
        ) {
            return false;
        }
        
        $this->readerWriter->createLog('processSubscrDmn()');
        
        $subs_state = strtolower($this->params['subscriptionState']);
        
        if ('active' == $subs_state) {
            $this->order->addStatusHistoryComment(
                __("<b>Subscription</b> is Active. ")
                . __("<br/>Subscription ID: ") . $this->params['subscriptionId']. ', <br/>'
                . __('Plan ID: ') . $this->params['planId']
            );

            // Save the Subscription ID
            foreach (array_reverse($ord_trans_addit_info) as $key => $data) {
                if (!in_array(strtolower($data['transaction_type']), ['sale', 'settle', 'auth'])) {
                    $this->readerWriter->createLog($data['transaction_type'], 'processSubscrDmn() active continue');
                    continue;
                }

//                $ord_trans_addit_info[$key][Payment::SUBSCR_IDS]    = $this->params['subscriptionId'];
                
                // set additional data
                $this->orderPayment->setAdditionalInformation(
                    Payment::ORDER_TRANSACTIONS_DATA,
                    $ord_trans_addit_info
                );
                break;
            }
        }
        
        if ('inactive' == $subs_state) {
            $subscr_msg = __('<b>Subscription</b> is Inactive. ');

            if (!empty($this->params['subscriptionId'])) {
                $subscr_msg .= __('Subscription ID: ') . $this->params['subscriptionId'];
            }

            if (!empty($this->params['subscriptionId'])) {
                $subscr_msg .= __(', Plan ID: ') . $this->params['planId'];
            }

            $this->order->addStatusHistoryComment($subscr_msg);
        }
        
        if ('canceled' == $subs_state) {
            $this->order->addStatusHistoryComment(
                __('<b>Subscription</b> was canceled. ') . '<br/>'
                . __('<b>Subscription ID:</b> ') . $this->params['subscriptionId']
            );
        }
        
        // save Subscription info into the Payment
        $this->orderPayment->setAdditionalInformation('nuvei_subscription_state',   $subs_state);
        $this->orderPayment->setAdditionalInformation('nuvei_subscription_id',      $this->params['subscriptionId']);
        $this->orderPayment->save();
        
        $this->orderResourceModel->save($this->order);
        $this->readerWriter->createLog($this->order->getStatus(), 'Process Subscr DMN Order Status', 'DEBUG');
        
        return true;
    }

    /**
     * Place order.
     *
     * @param array $params
     */
    private function placeOrder($params)
    {
        $this->readerWriter->createLog('PlaceOrder()');
        
        $result = $this->dataObjectFactory->create();
        
        if (empty($params['quote'])) {
            return $result
                ->setData('error', true)
                ->setData('message', 'Missing Quote parameter.');
        }
        
        try {
            $quote = $this->quoteFactory->create()->loadByIdWithoutStore((int) $params['quote']);
            
            if (!is_object($quote)) {
                $this->readerWriter->createLog($quote, 'placeOrder error - the quote is not an object.');

                return $result
                    ->setData('error', true)
                    ->setData('message', 'The quote is not an object.');
            }
            
            $method = $quote->getPayment()->getMethod();
            
            $this->readerWriter->createLog(
                [
                    'quote payment Method'  => $method,
                    'quote id'              => $quote->getEntityId(),
                    'quote is active'       => $quote->getIsActive(),
                    'quote reserved ord id' => $quote->getReservedOrderId(),
                ],
                'Quote data'
            );

            if ((int) $quote->getIsActive() == 0) {
                $this->readerWriter->createLog($quote->getQuoteId(), 'Quote ID');

                return $result
                    ->setData('error', true)
                    ->setData('message', 'Quote is not active.');
            }

            if ($method !== Payment::METHOD_CODE) {
                return $result
                    ->setData('error', true)
                    ->setData('message', 'Quote payment method is "' . $method . '"');
            }

//            $params = array_merge(
//                $this->request->getParams(),
//                $this->request->getPostValue()
//            );
            
            $orderId = $this->cartManagement->placeOrder($params);

            $result
                ->setData('success', true)
                ->setData('order_id', $orderId);

            $this->_eventManager->dispatch(
                'nuvei_place_order',
                [
                    'result' => $result,
                    'action' => $this,
                ]
            );
        } catch (\Exception $exception) {
            $this->readerWriter->createLog($exception->getMessage(), 'DMN placeOrder Exception: ');
            
            return $result
                ->setData('error', true)
                ->setData('message', $exception->getMessage());
        }
        
        return $result;
    }
    
    /**
     * Function validateChecksum
     *
     * @param array $params
     * @param string $orderIncrementId
     *
     * @return mixed
     */
//    private function validateChecksum($params, $orderIncrementId)
    private function validateChecksum()
    {
        if (empty($this->params["advanceResponseChecksum"]) && empty($this->params['responsechecksum'])) {
            $msg = 'Required keys advanceResponseChecksum and '
                . 'responsechecksum for checksum calculation are missing.';
                
            $this->readerWriter->createLog($msg);
            $this->jsonOutput->setData($msg);
            
            return false;
        }
        
        // most of the DMNs with advanceResponseChecksum
        if (!empty($this->params["advanceResponseChecksum"])) {
            $concat     = $this->moduleConfig->getMerchantSecretKey();
            $params_arr = ['totalAmount', 'currency', 'responseTimeStamp', 'PPP_TransactionID', 'Status', 'productId'];

            foreach ($params_arr as $checksumKey) {
                if (!isset($this->params[$checksumKey])) {
                    $msg = 'Required key '. $checksumKey .' for checksum calculation is missing.';

                    $this->readerWriter->createLog($msg);
                    $this->jsonOutput->setData($msg);
                    
                    return false;
                }

                if (is_array($this->params[$checksumKey])) {
                    foreach ($this->params[$checksumKey] as $subVal) {
                        $concat .= $subVal;
                    }
                } else {
                    $concat .= $this->params[$checksumKey];
                }
            }

            $checksum = hash($this->moduleConfig->getConfigValue('hash'), $concat);

            if ($this->params["advanceResponseChecksum"] !== $checksum) {
                $msg = 'Checksum validation failed for advanceResponseChecksum and Order #' . $this->orderIncrementId;

                if ($this->moduleConfig->isTestModeEnabled() && null !== $this->order) {
                    $this->order->addStatusHistoryComment(__($msg)
                        . ' ' . __('Transaction type ') . $this->params['type']);
                }
                
                $this->readerWriter->createLog($msg);
                $this->jsonOutput->setData($msg);

                return false;
            }

            return true;
        }
        
        // subscription DMN with responsechecksum
        $param_responsechecksum = $this->params['responsechecksum'];
        unset($this->params['responsechecksum']);
        
        $concat = implode('', $this->params);
        
        if (empty($concat)) {
            $msg = 'Checksum string before hash is empty for Order #' . $this->orderIncrementId;
            
            $this->readerWriter->createLog($msg);
            $this->jsonOutput->setData($msg);

            return false;
        }
        
        $concat_final   = $concat . $this->moduleConfig->getMerchantSecretKey();
        $checksum       = hash($this->moduleConfig->getConfigValue('hash'), $concat_final);

        if ($param_responsechecksum !== $checksum) {
            $msg = 'Checksum validation failed for responsechecksum and Order #' . $this->orderIncrementId;

            if ($this->moduleConfig->isTestModeEnabled() && null !== $this->order) {
                $this->order->addStatusHistoryComment(__($msg)
                    . ' ' . __('Transaction type ') . $this->params['type']);
            }
            
            $this->readerWriter->createLog($msg);
            $this->jsonOutput->setData($msg);

            return false;
        }
        
        return true;
    }
    
    /**
     * Try to create Subscriptions.
     *
     * @param int   $orderIncrementId
     * @return void
     */
    
//    private function createSubscription($params, $orderIncrementId)
    private function createSubscription($orderIncrementId)
    {
        $this->readerWriter->createLog('createSubscription()');
        
        if (!in_array($this->params['transactionType'], ['Auth', 'Settle', 'Sale'])) {
            $this->readerWriter->createLog('Not allowed transaction type for rebilling. Stop the proccess.');
            return;
        }
        
        $dmn_subscr_data = json_decode($this->params['customField2'], true);
        
        if (empty($dmn_subscr_data) || !is_array($dmn_subscr_data)) {
            $this->readerWriter->createLog(
                $dmn_subscr_data,
                'There is no rebilling data or it is not an array. Stop the proccess.'
            );
            
            return;
        }
        
        if (!in_array($this->params['transactionType'], ['Sale', 'Settle', 'Auth'])) {
            $this->readerWriter->createLog('We start Rebilling only after Auth, '
                . 'Settle or Sale. Stop the proccess.');
            return;
        }
        
        if ('Auth' == $this->params['transactionType']
            && 0 != (float) $this->params['totalAmount']
        ) {
            $this->readerWriter->createLog('Non Zero Auth. Stop the proccess.');
            return;
        }
        
        $payment_subs_data = $this->orderPayment->getAdditionalInformation('nuvei_subscription_data');
            
        $this->readerWriter->createLog($payment_subs_data, '$payment_subs_data');
        
        if ('Settle' == $this->params['transactionType'] && empty($payment_subs_data)) {
            $this->readerWriter->createLog(
                $payment_subs_data,
                'Missing rebilling data into Order Payment. Stop the proccess.'
            );
            return;
        }
        
        $subsc_data = [];

        // we allow only one Product in the Order to be with Payment Plan
        if (!empty($dmn_subscr_data) && is_array($dmn_subscr_data)) {
            $subsc_data = $dmn_subscr_data;
        } 
        elseif (!empty($payment_subs_data)) {
            $subsc_data = $payment_subs_data;
        }
        
//        if (!empty($last_record[Payment::TRANSACTION_UPO_ID])
//            && is_numeric($last_record[Payment::TRANSACTION_UPO_ID])
//        ) {
//            $subsc_data = $last_record['start_subscr_data'];
//        }
        
//        if (empty($subsc_data) || !is_array($subsc_data)) {
//            $this->readerWriter->createLog($subsc_data, 'createSubscription() problem with the subscription data.');
//            return false;
//        }

        // create subscriptions for each of the Products
        $request = $this->requestFactory->create(AbstractRequest::CREATE_SUBSCRIPTION_METHOD);
        
        $subsc_data['userPaymentOptionId'] = $this->params['userPaymentOptionId'];
        $subsc_data['userTokenId']         = $this->params['email'];
        $subsc_data['currency']            = $this->params['currency'];
            
        try {
//            $params = array_merge(
//                $this->request->getParams(),
//                $this->request->getPostValue()
//            );

            $resp = $request
                ->setOrderId($orderIncrementId)
                ->setData($subsc_data)
                ->process();

            // add note to the Order - Success
            if ('success' == strtolower($resp['status'])) {
                $msg =  __("<b>Subscription</b> was created. Subscription ID "
                    . $resp['subscriptionId']). '. '
                    . __('Recurring amount: ') . $this->params['currency'] . ' '
                    . $subsc_data['recurringAmount'];
            }
            // Error, Decline
            else {
                $msg = __("<b>Error</b> when try to create Subscription by this Order. ");

                if (!empty($resp['reason'])) {
                    $msg .= '<br/>' . __('Reason: ') . $resp['reason'];
                }
            }

            $this->order->addStatusHistoryComment($msg, $this->sc_transaction_type);
            $this->orderResourceModel->save($this->order);
        } catch (PaymentException $e) {
            $this->readerWriter->createLog('createSubscription - Error: ' . $e->getMessage());
        }
            
        return;
    }
    
    private function getOrCreateOrder()
    {
        $this->readerWriter->createLog(
            [
                'quoteId'           => $this->quoteId,
                'orderIncrementId'  => $this->orderIncrementId,
            ],
            'getOrCreateOrder'
        );
        
        if (0 == $this->orderIncrementId && 0 == $this->quoteId) {
            $msg = 'DMN error - orderIncrementId and quoteId are 0.';

            $this->readerWriter->createLog($msg);
            $this->jsonOutput->setData($msg);

            return $this->jsonOutput;
        }
        
        $identificator  = max([$this->quoteId, $this->orderIncrementId]);
        
        $this->readerWriter->createLog([
            '$identificator value'  => $identificator,
            '$identificator name'   => 0 == $this->quoteId ? 'increment_id' : 'quote_id',
        ]);
        
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter(
                0 == $this->quoteId ? 'increment_id' : 'quote_id',
                $identificator,
                'eq'
            )->create();

//        $tryouts    = 0;
//        $max_tries  = 5;
        
        do {
//            $tryouts++;
            $this->loop_tries++;
            
            $orderList = $this->orderRepo->getList($searchCriteria)->getItems();

            if (!$orderList || empty($orderList)) {
//                $this->readerWriter->createLog('DMN try ' . $tryouts
                $this->readerWriter->createLog('DMN try ' . $this->loop_tries
                    . ', there is NO order for TransactionID ' . $this->params['TransactionID'] . ' yet.');
                
                sleep(3);
            }
//        } while ($tryouts < $max_tries && empty($orderList));
        } while ($this->loop_tries < $this->loop_max_tries && empty($orderList));
        
        // in case there is no Order
        if (!is_array($orderList)
            || empty($orderList)
            || null == ($this->order = current($orderList)) // set $this->order here
        ) {
            $msg = 'DMN Callback error - there is no Order for this DMN data.';
            
            $this->readerWriter->createLog($msg);
            $this->jsonOutput->setData($msg);
            $this->jsonOutput->setHttpResponseCode(400);
            
            $this->createAutoVoid();
            
            return false;
        }
        
//        $this->order = current($orderList);
        
//        if (null === $this->order) {
//            $msg = 'DMN error - Order object is null.';
//            
//            $this->readerWriter->createLog($orderList, $msg);
//            $this->jsonOutput->setData($msg);
//            $this->jsonOutput->setHttpResponseCode(400);
//
//            return false;
//        }
        
        $this->orderPayment = $this->order->getPayment();
        
        if (null === $this->orderPayment) {
            $msg = 'DMN error - Order Payment object is null.';
            
            $this->readerWriter->createLog($msg);
            $this->jsonOutput->setData($msg);

            return false;
        }
        
        if (0 == $this->orderIncrementId) {
            $this->orderIncrementId = $this->order->getIncrementId();
        }
        
        // check if the Order belongs to nuvei
        $method = $this->orderPayment->getMethod();

        if ('nuvei' != $method) {
            $msg = 'DMN getOrCreateOrder() error - the order was not made with Nuvei module.';
            
            $this->readerWriter->createLog(
                [
                    'orderIncrementId'  => $identificator,
                    'module'            => $method,
                ],
                $msg
            );
            $this->jsonOutput->setData($msg);

            return false;
        }
        
        return true;
    }
    
    private function createAutoVoid()
    {
        $order_request_time	= $this->params['customField4']; // time of create/update order
        $curr_time          = time();
        $dmnTrType          = $this->params['transactionType'];
        
        $this->readerWriter->createLog(
            [
                $order_request_time,
                $dmnTrType,
                $curr_time
            ],
            'create_auto_void'
        );
        
        // not allowed Auto-Void
        if (!in_array($dmnTrType, ['Sale', 'Auth'])) {
            $msg = 'The Auto Void is allowed only for Sale and Auth.';
            
            $this->readerWriter->createLog($msg);
            $this->jsonOutput->setData($msg);
            $this->jsonOutput->setHttpResponseCode(200);
            
            return;
        }
        
        if (empty($order_request_time)) {
            $msg = 'There is problem with $order_request_time. End process.';
            
            $this->readerWriter->createLog(null, $msg, 'WARINING');
            $this->jsonOutput->setData($msg);
            $this->jsonOutput->setHttpResponseCode(200);
            return false;
        }
        
        if ($curr_time - $order_request_time <= 1800) {
            $msg = "Let's wait one more DMN try.";
            
            $this->readerWriter->createLog($msg);
            $this->jsonOutput->setData($msg);
            
            return false;
        }
        // /not allowed Auto-Void
        
        $request = $this->requestFactory->create(AbstractRequest::PAYMENT_VOID_METHOD);
        
        $resp = $request
            ->setParams([
                'clientUniqueId'        => date('YmdHis') . '_' . uniqid(),
                'currency'              => $this->params['currency'],
                'amount'                => $this->params['totalAmount'],
                'relatedTransactionId'  => $this->params['TransactionID'],
                'customData'            => 'This is an Auto-Void transaction',
            ])
            ->process();
        
        if (!empty($resp->getTransactionId())) {
            $msg = 'The searched Order does not exists, a Void request was made for this Transacrion.';
            
            $this->jsonOutput->setHttpResponseCode(200);
            $this->jsonOutput->setData($msg);
            
            return;
        }
        
        $msg = 'The searched Order does not exists, and the Void request was not successfu!';
        
        $this->readerWriter->createLog(null, $msg, 'CRITICAL');
        $this->jsonOutput->setData($msg);
        
        return;
    }
    
    /**
     */
    private function prepareCurrTrInfo()
    {
        $this->curr_trans_info = [
            Payment::TRANSACTION_ID             => '',
            Payment::TRANSACTION_AUTH_CODE      => '',
            Payment::TRANSACTION_STATUS         => '',
            Payment::TRANSACTION_TYPE           => '',
            Payment::TRANSACTION_UPO_ID         => '',
            Payment::TRANSACTION_TOTAL_AMOUN    => '',
        ];

        // some subscription DMNs does not have TransactionID
        if (isset($this->params['TransactionID'])) {
            $this->curr_trans_info[Payment::TRANSACTION_ID] = $this->params['TransactionID'];
        }
        if (isset($this->params['AuthCode'])) {
            $this->curr_trans_info[Payment::TRANSACTION_AUTH_CODE] = $this->params['AuthCode'];
        }
        if (isset($this->params['Status'])) {
            $this->curr_trans_info[Payment::TRANSACTION_STATUS] = $this->params['Status'];
        }
        if (isset($this->params['transactionType'])) {
            $this->curr_trans_info[Payment::TRANSACTION_TYPE] = $this->params['transactionType'];
        }
        if (isset($this->params['userPaymentOptionId'])) {
            $this->curr_trans_info[Payment::TRANSACTION_UPO_ID] = $this->params['userPaymentOptionId'];
        }
        if (isset($this->params['totalAmount'])) {
            $this->curr_trans_info[Payment::TRANSACTION_TOTAL_AMOUN] = $this->params['totalAmount'];
        }
    }
    
    /**
     * Help method keeping Order status from override with
     * delied or duplicated DMNs.
     *
     * @param string $order_tr_type
     * @param string $order_status
     *
     * return bool
     */
    private function keepOrderStatusFromOverride($order_tr_type, $order_status, $status)
    {
        $tr_type_param = strtolower($this->params['transactionType']);
        
        // default - same transaction type, order was approved, but DMN status is different
        if (strtolower($order_tr_type) == $tr_type_param
            && strtolower($order_status) == 'approved'
            && $order_status != $this->params['Status']
        ) {
            $msg = 'Current Order status is "'. $order_status .'", but incoming DMN status is "'
                . $this->params['Status'] . '", for Transaction type '. $order_tr_type
                .'. Do not apply DMN data on the Order!';

            $this->readerWriter->createLog($msg);
            $this->jsonOutput->setData($msg);
        
            return true;
        }

        /**
         * When all is same for Sale
         * we do this check only for sale, because Settle, Refund and Void
         * can be partial
         */
        if (strtolower($order_tr_type) == $tr_type_param
            && $tr_type_param == 'sale'
            && strtolower($order_status) == 'approved'
            && $order_status == $this->params['Status']
        ) {
            $msg = 'Duplicated Sale DMN. Stop DMN process!';
            
            $this->readerWriter->createLog($msg);
            $this->jsonOutput->setData($msg);
        
            return true;
        }

        // do not override status if the Order is Voided or Refunded
        if ('void' == strtolower($order_tr_type)
            && strtolower($order_status) == 'approved'
            && (strtolower($this->params['transactionType']) != 'void'
                || 'approved' != $status)
        ) {
            $msg = 'No more actions are allowed for order #' . $this->order->getId();
            
            $this->readerWriter->createLog($msg);
            $this->jsonOutput->setData($msg);
        
            return true;
        }

        // after Refund allow only refund, this is in case of Partial Refunds
        if (in_array(strtolower($order_tr_type), ['refund', 'credit'])
            && strtolower($order_status) == 'approved'
            && !in_array(strtolower($this->params['transactionType']), ['refund', 'credit'])
        ) {
            $msg = 'No more actions are allowed for order #' . $this->order->getId();
            
            $this->readerWriter->createLog($msg);
            $this->jsonOutput->setData($msg);
        
            return true;
        }

        // do not replace Settle with Auth
        if ($tr_type_param === 'auth' && strtolower($order_tr_type) === 'settle') {
            $msg = 'Can not set Auth to Settled Order #' . $this->order->getId();
            
            $this->readerWriter->createLog($msg);
            $this->jsonOutput->setData($msg);
        
            return true;
        }
        
        // after Auth only Settle and Void are allowed
        if (strtolower($order_tr_type) === 'auth'
            && !in_array($tr_type_param, ['settle', 'void'])
        ) {
            $msg = 'The only allowed upgrades of Auth are Void and Settle. Order #' . $this->order->getId();
            
            $this->readerWriter->createLog($msg);
            $this->jsonOutput->setData($msg);
        
            return true;
        }
        
        return false;
    }
    
    /**
     *
     * @param array $ord_trans_addit_info
     * @param int $tries
     *
     * @return boolean
     */
    private function finalSaveData($ord_trans_addit_info, $tries = 0)
    {
        $this->readerWriter->createLog('', 'finalSaveData()', 'INFO');
        
        if ($tries > 0) {
            $this->readerWriter->createLog($tries, 'DMN save Order data recursive retry.');
        }
        
        if ($tries > 5) {
            $this->readerWriter->createLog($tries, 'DMN save Order data maximum recursive retries reached.');
            $this->jsonOutput->setData('DMN save Order data maximum recursive retries reached.');
            return false;
        }
        
        try {
            $this->readerWriter->createLog(
                $ord_trans_addit_info, 
                'DMN before save $ord_trans_addit_info', 'DEBUG'
            );
            
            // set additional data
            $this->orderPayment
                ->setAdditionalInformation(Payment::ORDER_TRANSACTIONS_DATA, $ord_trans_addit_info)
                ->save();

            $this->readerWriter->createLog('DMN after save $ord_trans_addit_info', 'DEBUG');

            $this->orderResourceModel->save($this->order);
        } catch (\Exception $e) {
            $msg = $e->getMessage();
            
            $this->readerWriter->createLog($e->getMessage(), 'DMN save Order data exception.');
            
            if (strpos($msg, 'Deadlock found') !== false) {
                $tries++;
                sleep(1);
                $this->finalSaveData($ord_trans_addit_info, $tries);
            }
        }
        
        return true;
    }
    
    /**
     * Try to find Order ID and/or Quote ID from DMN parameters.
     * 
     * @returns string|int
     */
    private function getOrderIdentificators()
    {
        $this->readerWriter->createLog('getOrderIdentificators');
        
        // for subsccription DMNs
        if (!empty($this->params['dmnType'])
            && !empty($this->params['clientRequestId'])
            && in_array($this->params['dmnType'], ['subscriptionPayment', 'subscription'])
        ) {
            $clientRequestId_arr    = explode('_', $this->params["clientRequestId"]);
            $last_elem              = end($clientRequestId_arr);

            $this->readerWriter->createLog($last_elem, '$last_elem');
            
            if (!empty($last_elem) && is_numeric($last_elem)) {
                $this->readerWriter->createLog('set orderIncrementId');
                $this->orderIncrementId = $last_elem;
            }
            
            return;
        }
        
        // for the initial requests use the Quote ID
        if (isset($this->params['transactionType'])
            && in_array($this->params['transactionType'], ['Auth', 'Sale'])
        ) {
            if (!empty($this->params["clientUniqueId"])) {
                $this->readerWriter->createLog('set quoteId');
                $this->quoteId = current(explode('_', $this->params["clientUniqueId"]));
                return;
            }
            
            return;
        }
        
        // for all other requests
//        if (!empty($this->params["order"])) {
//            $this->readerWriter->createLog('set orderIncrementId');
//            $this->orderIncrementId = $this->params["order"];
//            return;
//        }
        
        if (!empty($this->params["clientUniqueId"])) {
            $this->readerWriter->createLog('set orderIncrementId');
//            $this->orderIncrementId = current(explode('_', $this->params["clientUniqueId"]));
            $this->orderIncrementId = $this->params["clientUniqueId"];
            return;
        }
        
        if (!empty($this->params["merchant_unique_id"])) {
            // modified because of the PayPal Sandbox problem with duplicate Orders IDs
            $this->readerWriter->createLog('set orderIncrementId');
//            $this->orderIncrementId = current(explode('_', $this->params["merchant_unique_id"]));
            $this->orderIncrementId = $this->params["merchant_unique_id"];
            return;
        }
        
//        if (!empty($this->params["orderId"])) {
//            $this->readerWriter->createLog('set orderIncrementId');
//            $this->orderIncrementId = $this->params["orderId"];
//            return;
//        }
        
        return;
    }
    
    /**
     * Just a help function to find differences between Order total/currency
     * pair and the incoming DMN data.
     * 
     * @return boolean $fraud
     */
    private function fraudCheck()
    {
        # Fraud check
        $order_total    = round((float) $this->order->getBaseGrandTotal(), 2);
        $order_curr     = $this->order->getBaseCurrencyCode();
        
        $fraud = false;
        
        // amount check
        if ($order_total != $this->params['totalAmount']
            && isset($this->params['customField1'])
            && $order_total != $this->params['customField1']
        ) {
            $this->readerWriter->createLog(
                [
                    '$order_total'          => $order_total,
                    'params totalAmount'    => $this->params['customField1'],
                ],
                'fraudCheck'
            );
            
            $fraud = true;
        }
        
        // currency check
        if ($order_curr != $this->params['currency']
            && isset($this->params['customField5'])
            && $order_curr != $this->params['customField5']
        ) {
            $this->readerWriter->createLog(
                [
                    '$order_curr'           => $order_curr,
                    'params customField5'   => $this->params['customField5'],
                ],
                'fraudCheck'
            );
            
            $fraud = true;
        }
        
        return $fraud;
    }
    
}
