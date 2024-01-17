<?php

namespace Nuvei\Checkout\Controller\Payment\Callback;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\PaymentException;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;

/**
 * Nuvei Checkout redirect success controller.
 */
//class Success extends Action
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

    /**
     * Object constructor.
     *
     * @param Context                 $context
     * @param PaymentRequestFactory   $paymentRequestFactory
     * @param ModuleConfig            $moduleConfig
     * @param DataObjectFactory       $dataObjectFactory
     * @param CartManagementInterface $cartManagement
     * @param CheckoutSession         $checkoutSession
     * @param Onepage                 $onepageCheckout
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\DataObjectFactory $dataObjectFactory,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Checkout\Model\Type\Onepage $onepageCheckout,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        parent::__construct($context);

        $this->dataObjectFactory        = $dataObjectFactory;
        $this->cartManagement           = $cartManagement;
        $this->checkoutSession          = $checkoutSession;
        $this->onepageCheckout          = $onepageCheckout;
        $this->readerWriter             = $readerWriter;
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
        $params = $this->getRequest()->getParams();
        
        $this->readerWriter->createLog($params, 'Complete class params');
        
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $form_key       = $params['form_key'];

        try {
            if ((int) $this->checkoutSession->getQuote()->getIsActive() === 1) {
                // if the option for save the order in the Redirect is ON, skip placeOrder !!!
                $result = $this->placeOrder();
                
                if ($result->getSuccess() !== true) {
                    $this->readerWriter->createLog(
                        [
                            'message'           => $result->getMessage(),
                            'payment method'    => $this->checkoutSession->getQuote()->getPayment()->getMethod(),
                        ],
                        'Complete Callback error - place order error',
                        'WARN'
                    );

                    throw new PaymentException(__($result->getMessage()));
                }
            } else {
                $this->readerWriter->createLog('Attention - the Quote is not active! '
                    . 'The Order can not be created here. May be it is already placed.');
            }
            
            if (isset($params['Status'])
                && !in_array(strtolower($params['Status']), ['approved', 'success'])
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
            $this->onepageCheckout->getCheckoutMethod();
            
            $orderId = $this->cartManagement->placeOrder($this->getQuoteId());
            
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
                    __('An error occurred on the server. '
                        . 'Please check your Order History and if the Order is not there, try to place it again!')
                );
        }

        return $result;
    }

    /**
     * @return int
     * @throws PaymentException
     */
    private function getQuoteId()
    {
        $quoteId = (int)$this->getRequest()->getParam('quote');

        if ((int)$this->checkoutSession->getQuoteId() === $quoteId) {
            return $quoteId;
        }
        
        $this->readerWriter->createLog('Success error: Session has expired, order has been not placed.');

        throw new PaymentException(
            __('Session has expired, order has been not placed.')
        );
    }
}
