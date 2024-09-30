<?php

namespace Nuvei\Checkout\Controller\Payment\Callback;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\PaymentException;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
//use Magento\Sales\Api\OrderRepositoryInterface;
use Nuvei\Checkout\Model\Payment;

/**
 * Nuvei Checkout redirect success controller.
 */
class Complete extends Action implements CsrfAwareActionInterface
{
    /**
     * @var DataObjectFactory
     */
    private $dataObjectFactory;

    /**
     * @var CartManagementInterface
     */
    private $cartManagement;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var Onepage
     */
    private $onepageCheckout;
    
    private $readerWriter;
    private $orderFactory;
//    private $order;
//    private $orderPayment;
    private $cart;
    private $moduleConfig;
    private $orderCollectionFactory;
//    private $scopeConfig;
//    private $getPaymentStatus;
//    private $params;
//    private $quoteRepository;
    
    /**
     * Object constructor.
     *
     * @param Context                   $context
     * @param DataObjectFactory         $dataObjectFactory
     * @param CartManagementInterface   $cartManagement
     * @param Cart                      $cart
     * @param CheckoutSession           $checkoutSession
     * @param Onepage                   $onepageCheckout
     * @param CollectionFactory         $orderCollectionFactory
     * @param OrderFactory              $orderFactory
     * @param Config                    $moduleConfig
     * @param ReaderWriter              $readerWriter
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
//        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\DataObjectFactory $dataObjectFactory,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Checkout\Model\Type\Onepage $onepageCheckout,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Nuvei\Checkout\Model\Config $moduleConfig,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
//        \Nuvei\Checkout\Model\Request\Payment\GetStatus $getPaymentStatus,
//        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository
    ) {
        parent::__construct($context);

        $this->dataObjectFactory        = $dataObjectFactory;
        $this->cartManagement           = $cartManagement;
        $this->checkoutSession          = $checkoutSession;
        $this->onepageCheckout          = $onepageCheckout;
        $this->readerWriter             = $readerWriter;
        $this->orderFactory             = $orderFactory;
        $this->cart                     = $cart;
        $this->moduleConfig             = $moduleConfig;
        $this->orderCollectionFactory   = $orderCollectionFactory;
//        $this->scopeConfig              = $scopeConfig;
//        $this->getPaymentStatus         = $getPaymentStatus;
//        $this->quoteRepository          = $quoteRepository;
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
     * @return ResultInterface
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function execute()
    {
        $this->params   = $this->getRequest()->getParams();
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $form_key       = $this->params['form_key'];
        $quote          = $this->cart->getQuote();
//        $isOrderPlaced  = false;
        
        $this->readerWriter->createLog(
            [
                'params'            => $this->params,
                'payment method'    => $quote->getPayment()->getMethod(),
            ], 
            'Complete class params'
        );

        try {
//            if ((int) $this->checkoutSession->getQuote()->getIsActive() === 1) {
            if ((int) $quote->getIsActive() === 1) {
                // if the option for save the order in the Redirect is ON, skip placeOrder !!!
                $result = $this->placeOrder();
                
                if ($result->getSuccess() !== true) {
                    $this->readerWriter->createLog(
                        [
                            'message'           => $result->getMessage(),
//                            'payment method'    => $this->checkoutSession->getQuote()->getPayment()->getMethod(),
                            'payment method'    => $quote->getPayment()->getMethod(),
                        ],
                        'Complete Callback error - place order error',
                        'WARN'
                    );

                    throw new PaymentException(__($result->getMessage()));
                }
                
                $this->order = $this->orderFactory->create()->load($result->getOrderId());
//                $isOrderPlaced  = true;
                
                // check the Order status
//                if (!empty($this->params['nuvei_session_token'])) {
//                    $this->getPaymentStatus();
//                }
                
                // save Nuvei transaction ID into the Order
                if (!empty($this->params['nuvei_transaction_id'])) {
                    $orderPayment = $this->order->getPayment();
                    
                    $orderPayment->setAdditionalInformation(
                        Payment::TRANSACTION_ID,
                        $this->params['nuvei_transaction_id']
                    );
                    
                    $orderPayment->save();
                    $this->order->save();
                    
                    $this->readerWriter->createLog(
                        $this->params['nuvei_transaction_id'],
                        'The transacion ID was added to the $orderPayment'
                    );
                }
            }
            else {
                $this->readerWriter->createLog(
                    'Attention - the Quote is not active! '
                    . 'The Order can not be created here. May be it is already placed. Checking for saved Order.'
                );
                
                $this->order = $this->getOrderByQuoteId($quote->getId());
                
                if (empty($this->order)) {
                    $msg = 'There is no Order with this Quote ID.';
                    
                    $this->readerWriter->createLog(
                        ['quote id' => $quote->getId()],
                        $msg,
                        'WARN'
                    );
                    
                    throw new PaymentException(__($msg));
                }
                
//                $isOrderPlaced = true;
            }
            
            if (isset($this->params['Status'])
                && !in_array(strtolower($this->params['Status']), ['approved', 'success'])
            ) {
                throw new PaymentException(__('Your payment failed.'));
            }
        } catch (PaymentException $e) {
            $this->readerWriter->createLog($e->getMessage(), 'Complete Callback Process Error:');
            $this->messageManager->addErrorMessage($e->getMessage());
            
            $resultRedirect->setUrl(
                $this->_url->getUrl('checkout/cart')
                . (!empty($form_key) ? '?form_key=' . $form_key : '')
            );
            
            return $resultRedirect;
        }

        // go to success page
        $resultRedirect->setUrl(
            $this->_url->getUrl('checkout/onepage/success/')
                . (!empty($form_key) ? '?form_key=' . $form_key : '')
        );
        
        return $resultRedirect;
    }

    /**
     * Place order.
     */
    private function placeOrder()
    {
        $result = $this->dataObjectFactory->create();

        try {
            /**
             * Current workaround depends on Onepage checkout model defect
             * Method Onepage::getCheckoutMethod performs setCheckoutMethod
             */
//            $this->onepageCheckout->getCheckoutMethod();
            
//            $orderId = $this->cartManagement->placeOrder($this->getQuoteId());
            $orderId = $this->cartManagement->placeOrder($this->moduleConfig->getQuoteId());
            
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
            $this->readerWriter->createLog(
                [
                    'Message'   => $exception->getMessage(),
                    'Trace'     => $exception->getTrace(),
                ],
                'Success Callback Response Exception',
                'WARN'
            );
            
            $result
                ->setData('error', true)
                ->setData(
                    'message',
                    __(
                        'An error occurred on the server. '
                        . 'Please check your Order History and if the Order is not there, try to place it again!'
                    )
                );
        }

        return $result;
    }

    /**
     * @return int
     * @throws PaymentException
     * 
     * @deprecated since version 3.1.10
     */
    private function getQuoteId()
    {
        $quoteId = (int)$this->getRequest()->getParam('quote');

        if ((int) $this->checkoutSession->getQuoteId() === $quoteId) {
            return $quoteId;
        }
        
        $this->readerWriter->createLog('Success error: Session has expired, order has been not placed.');

        throw new PaymentException(
            __('Session has expired, order has been not placed.')
        );
    }
    
    /**
     * @param int $quoteId
     * @return Order|null
     */
    private function getOrderByQuoteId($quoteId)
    {
        $order = $this->orderCollectionFactory->create()
            ->addFieldToFilter('quote_id', $quoteId)
            ->getFirstItem();
        
        if ($order->getId()) {
            return $order;
        }
        
        return null;
    }
    
//    private function getPaymentStatus()
//    {
//        $status = $this->getPaymentStatus
//            ->setSessionToken($this->params['nuvei_session_token'])->process();
//        
//    }
    
}
