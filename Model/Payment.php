<?php

namespace Nuvei\Checkout\Model;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactory;
use Magento\Payment\Gateway\Command\CommandManagerInterface;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\Data\CartInterface;
use Nuvei\Checkout\Model\Config as ModuleConfig;
use Nuvei\Checkout\Model\Request\Payment\Factory as PaymentRequestFactory;

/**
 * Nuvei Checkout payment model.
 */
class Payment implements MethodInterface
{
    /**
     * Method code const.
     */
    const METHOD_CODE   = 'nuvei';
    const MODE_LIVE     = 'live';

    /**
     * Additional information const.
     */
    const KEY_LAST_ST           = 'last_session_token';
    const KEY_CC_TEMP_TOKEN     = 'cc_temp_token';
    const KEY_CHOSEN_APM_METHOD = 'chosen_apm_method';

    /**
     * Transaction keys const.
     */
    const TRANSACTION_REQUEST_ID        = 'transaction_request_id';
    const TRANSACTION_ORDER_ID          = 'nuvei_order_id';
    const TRANSACTION_AUTH_CODE         = 'authorization_code';
    const TRANSACTION_ID                = 'transaction_id';
    const TRANSACTION_PAYMENT_SOLUTION  = 'payment_solution';
    const TRANSACTION_PAYMENT_METHOD    = 'external_payment_method';
    const TRANSACTION_STATUS            = 'status';
    const TRANSACTION_TYPE              = 'transaction_type';
    const SUBSCR_IDS                    = 'subscr_ids'; // list with subscription IDs
    const TRANSACTION_UPO_ID            = 'upo_id';
    const TRANSACTION_TOTAL_AMOUN       = 'total_amount';
    const REFUND_TRANSACTION_AMOUNT     = 'refund_amount';
    const AUTH_PARAMS                   = 'auth_params';
    const SALE_SETTLE_PARAMS            = 'sale_settle_params';
    const ORDER_TRANSACTIONS_DATA       = 'nuvei_order_transactions_data';
    const CREATE_ORDER_DATA             = 'nuvei_create_order_data';

    /**
     * Order statuses.
     */
    const SC_AUTH               = 'nuvei_auth';
    const SC_SETTLED            = 'nuvei_settled';
    const SC_VOIDED             = 'nuvei_voided';
    const SC_REFUNDED           = 'nuvei_refunded';
    const SC_PROCESSING         = 'nuvei_processing';
    const SC_CANCELED           = 'nuvei_canceled';
    const SC_SUBSCRT_STARTED    = 'nuvei_subscr_started';
    const SC_SUBSCRT_ENDED      = 'nuvei_subscr_ended';

    const SOLUTION_INTERNAL     = 'internal';
    const SOLUTION_EXTERNAL     = 'external';
    const APM_METHOD_CC         = 'cc_card';
    // it is same for Void
    const PAYMETNS_SUPPORT_REFUND = ['cc_card', 'apmgw_expresscheckout'];

    /**
     * @var PaymentRequestFactory
     */
    private $paymentRequestFactory;

    /**
     * @var ModuleConfig
     */
    private $moduleConfig;

    /**
     * @var int
     */
    private $storeId;
    
    /**
     * @var InfoInterface
     */
    private $infoInstance;
    
    /**
     * @var PaymentDataObjectFactory
     */
    private $paymentDataObjectFactory;
    
    /**
     * @var \Magento\Payment\Gateway\Command\CommandManagerInterface
     */
    private $commandExecutor;
    
    /**
     * @var CommandPoolInterface
     */
    private $commandPool;
    
    /**
     * @var ManagerInterface
     */
    private $eventManager;
    
    private $orderResourceModel;
    private $readerWriter;
    private $scopeConfig;

    /**
     * Payment constructor.
     *
     * @param PaymentRequestFactory         $paymentRequestFactory
     * @param ModuleConfig                  $moduleConfig
     * @param Order                         $orderResourceModel
     * @param ReaderWriter                  $readerWriter
     * @param ManagerInterface              $eventManager
     * @param PaymentDataObjectFactory      $paymentDataObjectFactory
     * @param CommandManagerInterface|null  $commandExecutor
     * @param CommandPoolInterface|null     $commandPool
     */
    public function __construct(
        PaymentRequestFactory $paymentRequestFactory,
        ModuleConfig $moduleConfig,
        \Magento\Sales\Model\ResourceModel\Order $orderResourceModel,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        ManagerInterface $eventManager,
        PaymentDataObjectFactory $paymentDataObjectFactory,
        CommandManagerInterface $commandExecutor = null,
        CommandPoolInterface $commandPool = null
    ) {
        $this->paymentRequestFactory    = $paymentRequestFactory;
        $this->orderResourceModel       = $orderResourceModel;
        $this->readerWriter             = $readerWriter;
        $this->paymentDataObjectFactory = $paymentDataObjectFactory;
        $this->commandExecutor          = $commandExecutor;
        $this->commandPool              = $commandPool;
        $this->moduleConfig             = $moduleConfig;
        $this->eventManager             = $eventManager;
    }

    /**
     * Assign data.
     *
     * @param DataObject $data Data object.
     *
     * @return Payment
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function assignData(DataObject $data)
    {
//        $additionalData = $data->getData(PaymentInterface::KEY_ADDITIONAL_DATA);
//
//        $chosenApmMethod = !empty($additionalData[self::KEY_CHOSEN_APM_METHOD])
//            ? $additionalData[self::KEY_CHOSEN_APM_METHOD] : null;
//        
//        $info = $this->getInfoInstance();
//        $info->setAdditionalInformation(self::KEY_CHOSEN_APM_METHOD, $chosenApmMethod);

        return $this;
    }

    /**
     * Validate payment method information object.
     *
     * @return Payment
     * @throws LocalizedException
     */
    public function validate()
    {
        return $this;
    }

    /**
     * Check if payment method can be used for provided currency.
     *
     * @param string $currencyCode
     * @return bool
     * 
     * @inheritdoc
     */
    public function canUseForCurrency($currencyCode)
    {
        return true;
    }

    /**
     * Authorize payment method.
     *
     * @param InfoInterface $payment
     * @param float         $amount
     *
     * @return Payment
     * @throws \Magento\Framework\Exception\LocalizedException
     *
     * @api
     */
    public function authorize(InfoInterface $payment, $amount)
    {
//        $this->processPayment($payment, $amount);
        return $this;
    }

    /**
     * Refund payment method.
     *
     * @param InfoInterface $payment
     * @param float         $amount
     *
     * @return Payment
     * @throws \Magento\Framework\Exception\LocalizedException
     *
     * @api
     */
    public function refund(InfoInterface $payment, $amount)
    {
        $order  = $payment->getOrder();
        $order->setStatus(Payment::SC_PROCESSING);
//        $this->orderResourceModel->save($order);
        
        /** @var RequestInterface $request */
        $request = $this->paymentRequestFactory->create(
            AbstractRequest::PAYMENT_REFUND_METHOD,
            $payment,
            $amount
        );
        
        $resp = $request->process();
//        $this->readerWriter->createLog($resp, 'Paymen->refund()');
        
        if(empty($resp['transactionStatus']) || 'APPROVED' != $resp['transactionStatus']) {
            if (!empty($resp['gwErrorReason'])) {
                throw new \Magento\Framework\Exception\LocalizedException(__($resp['gwErrorReason']));
            }
            
            throw new \Magento\Framework\Exception\LocalizedException(
                __('Refund request error, you can check Nuvei log for more information.'));
        }

        return $this;
    }

    /**
     * Cancel payment method.
     *
     * @param InfoInterface $payment
     *
     * @return Payment
     * @throws \Magento\Framework\Exception\LocalizedException
     *
     * @api
     */
    public function cancel(InfoInterface $payment)
    {
        $this->void($payment);

        return $this;
    }

    /**
     * Refund payment method.
     *
     * @param InfoInterface $payment
     * @return Payment
     * @throws \Magento\Framework\Exception\LocalizedException
     *
     * @api
     */
    public function void(InfoInterface $payment)
    {
        $order  = $payment->getOrder();
        $total  = $order->getBaseGrandTotal();
        $status = $order->getStatus();
        
        $this->readerWriter->createLog([$total, $status], 'Payment Void.');
        
        // Void of Zero Total amount
        if (0 == (float) $total && self::SC_AUTH == $status) {
            $success = $this->cancelSubscription($payment);
            
            if (!$success) {
                throw new LocalizedException(__('This Order can not be Cancelled.'));
            }
            
            return $this;
            
        }
        // /Void of Zero Total amount
        
        /** @var RequestInterface $request */
        $request = $this->paymentRequestFactory->create(
            AbstractRequest::PAYMENT_VOID_METHOD,
            $payment
        );
        
        $order->setStatus(self::SC_PROCESSING);
//        $this->orderResourceModel->save($order);
        
        $request->process();
        return $this;
    }
    
    /**
     * Cancel Subscriptions.
     *
     * @param object $payment
     * @return bool
     */
    public function cancelSubscription(InfoInterface $payment)
    {
        $this->readerWriter->createLog('cancelSubscription()');
        
        try {
//            $ord_trans_addit_info = $payment->getAdditionalInformation(Payment::ORDER_TRANSACTIONS_DATA);
//
//            if (empty($ord_trans_addit_info) || !is_array($ord_trans_addit_info)) {
//                $this->readerWriter->createLog(
//                    $ord_trans_addit_info,
//                    'cancelSubscription() Error - $ord_trans_addit_info is empty or not an array.'
//                );
//                return false;
//            }

//            $last_record    = end($ord_trans_addit_info);
//            $subs_id        = $last_record[self::SUBSCR_IDS];
            $subs_id    = $payment->getAdditionalInformation('nuvei_subscription_id');
            $subs_state = $payment->getAdditionalInformation('nuvei_subscription_state');
            
            if (!is_numeric($subs_id) || $subs_id <= 0 || 'active' != $subs_state) {
                $this->readerWriter->createLog(
                    [
                        '$subs_id'      => $subs_id,
                        '$subs_state'   => $subs_state,
                    ],
                    'cancelSubscription() Error - missing mandatory Subscription data.'
                );
                return false;
            }
            
//            $this->readerWriter->createLog($ord_trans_addit_info, 'cancelSubscription()');

            if (empty($subs_id)) {
                $this->readerWriter->createLog(
                    $subs_id,
                    'cancelSubscription() Error - $subsc_ids is empty or not an array.'
                );
                return false;
            }

            $request = $this->paymentRequestFactory->create(
                AbstractRequest::CANCEL_SUBSCRIPTION_METHOD,
                $payment
            );

            $order  = $payment->getOrder();
            $msg    = '';

            $resp = $request
                ->setSubscrId($subs_id)
                ->process();

                // add note to the Order - Success
            if (!$resp || !is_array($resp) || 'SUCCESS' != $resp['status']) {
                $msg = __("<b>Error</b> when try to Cancel Subscription by this Order. ");

                if (!empty($resp['reason'])) {
                    $msg .= '<br/>' . __('Reason: ') . $resp['reason'];
                }
            }

            $order->addStatusHistoryComment($msg);
            $this->orderResourceModel->save($order);

            return empty($msg) ? true : false;
        } catch (\Exception $ex) {
            $this->readerWriter->createLog($ex->getMessage());
        }
        
        return false;
    }

    /**
     * Check void availability
     * 
     * @return bool
     * @internal param \Magento\Framework\DataObject $payment
     * 
     * @inheritdoc
     */
    public function canVoid()
    {
        return true;
    }
    
    /**
     * @inheritdoc
     */
    public function getCode()
    {
        return self::METHOD_CODE;
    }
    
    /**
     * Retrieve block type for method form generation
     *
     * @return string
     *
     * @deprecated 100.0.2
     * 
     * @inheritdoc
     */
    public function getFormBlockType()
    {
        return \Magento\Payment\Block\Transparent\Info::class;
    }

    /**
     * Retrieve payment method title
     *
     * @return string
     * 
     * @inheritdoc
     *
     */
    public function getTitle()
    {
        return $this->moduleConfig->getConfigValue('title');
    }

    /**
     * Store id setter
     * 
     * @param int $storeId
     * @return void
     * 
     * @inheritdoc
     */
    public function setStore($storeId)
    {
        $this->storeId = (int)$storeId;
    }

    /**
     * Store id getter
     * 
     * @return int
     * 
     * @inheritdoc
     */
    public function getStore()
    {
        return $this->storeId;
    }

    /**
     * Check order availability
     *
     * @return bool
     * 
     * @inheritdoc
     */
    public function canOrder()
    {
        return true;
    }

    /**
     * Check capture availability
     *
     * @return bool
     * 
     * @inheritdoc
     */
    public function canCapture()
    {
//        $this->readerWriter->createLog(@$_REQUEST, 'Payment->canCapture()');
        
        return true;
    }

    /**
     * Check partial capture availability
     *
     * @return bool
     * 
     * @inheritdoc
     */
    public function canCapturePartial()
    {
//        $this->readerWriter->createLog(@$_REQUEST, 'Payment->canCapturePartial()');
        
        return true;
    }

    /**
     * Check whether capture can be performed once and no further capture possible
     *
     * @return bool
     * 
     * @inheritdoc
     */
    public function canCaptureOnce()
    {
        return false;
    }

    /**
     * Check refund availability
     *
     * @return bool
     * 
     * @inheritdoc
     */
    public function canRefund()
    {
        return true;
    }

    /**
     * Check partial refund availability for invoice
     *
     * @return bool
     * 
     * @inheritdoc
     */
    public function canRefundPartialPerInvoice()
    {
        return true;
    }

    /**
     * Using internal pages for input payment data
     * Can be used in admin
     *
     * TODO ???
     * 
     * @return bool
     * 
     * @inheritdoc
     */
    public function canUseInternal()
    {
        return true;
    }

    /**
     * Can be used in regular checkout
     *
     * @return bool
     * 
     * @inheritdoc
     */
    public function canUseCheckout()
    {
        return true;
    }

    /**
     * Can be edit order (renew order)
     *
     * TODO ???
     * 
     * @return bool
     * 
     * @inheritdoc
     */
    public function canEdit()
    {
        return true;
    }

    /**
     * Check fetch transaction info availability
     *
     * @return bool
     * 
     * @inheritdoc
     */
    public function canFetchTransactionInfo()
    {
        return true;
    }

    /**
     * Fetch transaction info
     *
     * TODO ???
     * 
     * @param InfoInterface $payment
     * @param string $transactionId
     * 
     * @return array
     * 
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * 
     * @inheritdoc
     *
     */
    public function fetchTransactionInfo(InfoInterface $payment, $transactionId)
    {
        return $this->executeCommand(
            'fetch_transaction_information',
            ['payment' => $payment, 'transactionId' => $transactionId]
        );
    }

    /**
     * Retrieve payment system relation flag
     *
     * @return bool
     * 
     * @inheritdoc
     */
    public function isGateway()
    {
        return true;
    }

    /**
     * Retrieve payment method online/offline flag
     *
     * @return bool
     * 
     * @inheritdoc
     */
    public function isOffline()
    {
        return false;
    }

    /**
     * Flag if we need to run payment initialize while order place
     *
     * @return bool
     * 
     * @inheritdoc
     */
    public function isInitializeNeeded()
    {
        return false;
    }

    /**
     * To check billing country is allowed for the payment method
     *
     * @param string $country
     * @return bool
     * 
     * @inheritdoc
     */
    public function canUseForCountry($country)
    {
        return true;
    }

    /**
     * Retrieve block type for display method information
     *
     * @return string
     *
     * @deprecated 100.0.2
     * 
     * @inheritdoc
     */
    public function getInfoBlockType()
    {
        return \Nuvei\Checkout\Block\ConfigurableInfo::class;
    }

    /**
     * Retrieve payment information model object
     *
     * @return InfoInterface
     * 
     * @throws \Magento\Framework\Exception\LocalizedException
     *
     * @deprecated 100.0.2
     * 
     * @inheritdoc
     */
    public function getInfoInstance()
    {
        return $this->infoInstance;
    }

    /**
     * Retrieve payment information model object
     *
     * @param InfoInterface $info
     * @return void
     * 
     * @inheritdoc
     *
     * @deprecated 100.0.2
     */
    public function setInfoInstance(InfoInterface $info)
    {
        $this->infoInstance = $info;
    }


    /**
     * Order payment abstract method
     *
     * @param InfoInterface $payment
     * @param float $amount
     * 
     * @return $this
     * 
     * @inheritdoc
     */
    public function order(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->executeCommand(
            'order',
            ['payment' => $payment, 'amount' => $amount]
        );

        return $this;
    }

    /**
     * Capture payment abstract method
     *
     * @param InfoInterface $payment
     * @param float $amount
     * 
     * @return $this
     * 
     * @inheritdoc
     */
    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->readerWriter->createLog('Payment->capture()');
        
        $order          = $payment->getOrder();
        $invCollection  = $order->getInvoiceCollection();
        
        $order->setCanCreditmemo(true);
        
        $this->readerWriter->createLog(empty($invCollection), 'Payment->capture() empty($invCollection)');
        
        if (!empty($invCollection)) {
            foreach ($invCollection as $inv) {
                $inv->setCanVoidFlag(true);
            }
        }
        
        $this->executeCommand(
            'capture',
            ['payment' => $payment, 'amount' => $amount]
        );

        return $this;
    }


    /**
     * Whether this method can accept or deny payment
     * 
     * @return bool
     * 
     * @inheritdoc
     */
    public function canReviewPayment()
    {
        return true;
    }

    /**
     * Attempt to accept a payment that us under review
     *
     * @param InfoInterface $payment
     * @return false
     * @throws \Magento\Framework\Exception\LocalizedException
     *
     * @inheritdoc
     */
    public function acceptPayment(InfoInterface $payment)
    {
        $this->executeCommand('accept_payment', ['payment' => $payment]);

        return $this;
    }

    /**
     * Attempt to deny a payment that us under review
     *
     * @param InfoInterface $payment
     * @return false
     * @throws \Magento\Framework\Exception\LocalizedException
     *
     * @inheritdoc
     */
    public function denyPayment(InfoInterface $payment)
    {
        $this->executeCommand('deny_payment', ['payment' => $payment]);

        return $this;
    }

    /**
     * Retrieve information from payment configuration
     *
     * @param string $field
     * @param int|string|null|\Magento\Store\Model\Store $storeId
     *
     * @return mixed
     * 
     * @inheritdoc
     */
    public function getConfigData($field, $storeId = null)
    {
        return $this->moduleConfig->getConfigValue($field);
    }

    /**
     * Check whether payment method can be used
     *
     * @param CartInterface|null $quote
     * @return bool
     *
     * @inheritdoc
     */
    public function isAvailable(CartInterface $quote = null)
    {
        if (!$this->isActive($quote ? $quote->getStoreId() : null)) {
            return false;
        }
        
        return true;

//        $checkResult = new DataObject();
//        $checkResult->setData('is_available', true);
//        try {
//            $infoInstance = $this->getInfoInstance();
//            if ($infoInstance !== null) {
//                $validator = $this->getValidatorPool()->get('availability');
//                $result = $validator->validate(
//                    [
//                        'payment' => $this->paymentDataObjectFactory->create($infoInstance)
//                    ]
//                );
//
//                $checkResult->setData('is_available', $result->isValid());
//            }
//        // phpcs:ignore Magento2.CodeAnalysis.EmptyBlock
//        } catch (\Exception $e) {
//            // pass
//        }
//
//        // for future use in observers
//        $this->eventManager->dispatch(
//            'payment_method_is_active',
//            [
//                'result' => $checkResult,
//                'method_instance' => $this,
//                'quote' => $quote
//            ]
//        );
//
//        return $checkResult->getData('is_available');
    }

    /**
     * Is active
     *
     * @param int|null $storeId
     * @return bool
     *
     * @inheritdoc
     */
    public function isActive($storeId = null)
    {
        return $this->moduleConfig->getConfigValue('active');
    }

    /**
     * Method that will be executed instead of authorize or capture
     * if flag isInitializeNeeded set to true
     *
     * @param string $paymentAction
     * @param object $stateObject
     *
     * @return $this
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * 
     * @inheritdoc
     *
     */
    public function initialize($paymentAction, $stateObject)
    {
        $this->executeCommand(
            'initialize',
            [
                'payment' => $this->getInfoInstance(),
                'paymentAction' => $paymentAction,
                'stateObject' => $stateObject
            ]
        );
        return $this;
    }

    /**
     * Get config payment action url
     * Used to universalize payment actions when processing payment place
     *
     * @return string
     *
     * @inheritdoc
     */
    public function getConfigPaymentAction()
    {
        return $this->moduleConfig->getConfigValue('payment_action');
    }
    
    /**
     * Check authorize availability
     *
     * @return bool
     *
     * @inheritdoc
     */
    public function canAuthorize()
    {
        return true;
    }
    
    private function executeCommand($commandCode, array $arguments = [])
    {
        if (!$this->canPerformCommand($commandCode)) {
            return null;
        }

        /** @var InfoInterface|null $payment */
        $payment = null;
        if (isset($arguments['payment']) && $arguments['payment'] instanceof InfoInterface) {
            $payment = $arguments['payment'];
            $arguments['payment'] = $this->paymentDataObjectFactory->create($arguments['payment']);
        }

        if ($this->commandExecutor !== null) {
            return $this->commandExecutor->executeByCode($commandCode, $payment, $arguments);
        }

        if ($this->commandPool === null) {
            throw new \DomainException("The command pool isn't configured for use.");
        }

        $command = $this->commandPool->get($commandCode);

        return $command->execute($arguments);
    }
    
    /**
     * Whether payment command is supported and can be executed
     *
     * @param string $commandCode
     * @return bool
     */
    private function canPerformCommand($commandCode)
    {
        return $this->moduleConfig->canPerformCommand($commandCode);
    }
    
}
