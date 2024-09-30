<?php

namespace Nuvei\Checkout\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultFactory;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\Config as ModuleConfig;
use Nuvei\Checkout\Model\Request\Factory as RequestFactory;

/**
 * Nuvei Checkout OpenOrder controller.
 */
class OpenOrder extends Action
{
    /**
     * @var ModuleConfig
     */
    private $moduleConfig;

    /**
     * @var JsonFactory
     */
    private $jsonResultFactory;

    /**
     * @var RequestFactory
     */
    private $requestFactory;
    
    private $readerWriter;
//    private $cart;
    private $quoteFactory;
//    private $checkoutSession;
    private $quoteRepository;
//    private $cartItemFactory;
    private $productRepository;
    private $orderRepository;
    private $addressFactory;
    private $quoteItemFactory;
    private $orderResourceModel;

    /**
     * Redirect constructor.
     *
     * @param Context        $context
     * @param ModuleConfig   $moduleConfig
     * @param JsonFactory    $jsonResultFactory
     * @param RequestFactory $requestFactory
     * @param ReaderWriter   $readerWriter
     * @param Cart           $cart
     */
    public function __construct(
        Context $context,
        ModuleConfig $moduleConfig,
        JsonFactory $jsonResultFactory,
        RequestFactory $requestFactory,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
//        \Magento\Checkout\Model\Cart $cart,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
//        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
//        \Magento\Quote\Api\Data\CartItemInterfaceFactory $cartItemFactory,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Quote\Api\Data\AddressInterfaceFactory $addressFactory,
        \Magento\Quote\Model\Quote\ItemFactory $quoteItemFactory,
        \Magento\Sales\Model\ResourceModel\Order $orderResourceModel
    ) {
        parent::__construct($context);

        $this->moduleConfig         = $moduleConfig;
        $this->jsonResultFactory    = $jsonResultFactory;
        $this->requestFactory       = $requestFactory;
        $this->readerWriter         = $readerWriter;
//        $this->cart                 = $cart;
        $this->quoteFactory         = $quoteFactory;
//        $this->checkoutSession      = $checkoutSession;
        $this->quoteRepository      = $quoteRepository;
//        $this->cartItemFactory      = $cartItemFactory;
        $this->productRepository    = $productRepository;
        $this->orderRepository      = $orderRepository;
        $this->addressFactory       = $addressFactory;
        $this->quoteItemFactory     = $quoteItemFactory;
        $this->orderResourceModel   = $orderResourceModel;
    }

    /**
     * @return ResponseInterface
     */
    public function execute()
    {
        $this->readerWriter->createLog($this->getRequest()->getParams(), 'openOrder Controller');
        
        $result = $this->jsonResultFactory->create()
            ->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
        
        // if plugin is not active
        if (!$this->moduleConfig->getConfigValue('active')) {
            $this->readerWriter->createLog(
                'OpenOrder error - '
                . 'Nuvei checkout module is not active at the moment!'
            );
            
            return $result->setData(
                ['error_message' => __('OpenOrder error - Nuvei checkout module is not active at the moment!')]
            );
        }
        
        $request = $this->requestFactory->create(AbstractRequest::OPEN_ORDER_METHOD);
        
        // Simply Connect flow
        if ('checkout' == $this->moduleConfig->getUsedSdk()) {
            // Pre-Payment check
            if ($this->getRequest()->getParam('nuveiAction') == 'nuveiPrePayment') {
                return $this->nuveiPrePayment();
            }
            
            if ($this->getRequest()->getParam('nuveiAction') == 'transactionDeclined') {
                return $this->onTransactionDeclined();
            }
        }
        
        // when use Web SDK we have to call the OpenOrder class because have to decide will we create UPO
        $save_upo   = $this->getRequest()->getParam('saveUpo') ?? null;
        $pmType     = $this->getRequest()->getParam('pmType') ?? '';
        
        if (true === strpos($pmType, 'upo')) {
            $save_upo = 1;
        }
        
        $resp = $request
            ->setSaveUpo($save_upo)
            ->process();
        
        // some error
        if (isset($resp->error, $resp->reason)
            && 1 == $resp->error
        ) {
            $resp_array = [
                "error"     => 1,
                'reason'    => $resp->reason,
            ];
            
            if (isset($resp->outOfStock)) {
                $resp_array['outOfStock'] = 1;
            }
            
            return $result->setData($resp_array);
        }
        
        // success
        return $result->setData([
            "error"         => 0,
            "sessionToken"  => $resp->sessionToken,
            "amount"        => $resp->ooAmount,
            "message"       => "Success"
        ]);
    }
    
    private function nuveiPrePayment()
    {
        $result = $this->jsonResultFactory->create()
            ->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
        
        $request = $this->requestFactory->create(AbstractRequest::OPEN_ORDER_METHOD);
        
        $quoteId    = $this->getRequest()->getParam('quoteId'); // it comes form REST call as parameter
        $resp       = $request
            ->setQuoteId($quoteId)
            ->prePaymentCheck();

        $successUrl = $this->_url->getUrl('checkout/onepage/success/');

        $this->readerWriter->createLog([$successUrl, $resp->orderId], 'nuveiPrePayment()');

        return $result->setData([
            "success"       => (int) !$resp->error,
            'sessionToken'  => isset($resp->sessionToken) ? $resp->sessionToken : '',
            'successUrl'    => $successUrl,
            'orderId'       => isset($resp->orderId) ? $resp->orderId : 0,
        ]);
    }
    
    private function onTransactionDeclined()
    {
        try {
            $result = $this->jsonResultFactory->create()
                ->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);

            # create new quote by created Order
            // get order
            $order = $this->orderRepository->get((int) $this->getRequest()->getParam('nuveiSavedOrderId'));

            if (!$order->getId()) {
                throw new \Magento\Framework\Exception\NoSuchEntityException(__('Order not found.'));
            }

            // create new quote
            $quote = $this->quoteFactory->create();
            $quote->setStoreId($order->getStoreId()); // Set the store from the order
            $quote->setCustomerId($order->getCustomerId()); // Set customer ID
            $quote->setCustomerEmail($order->getCustomerEmail()); // Set customer email

            // Handle guest customers
            if ($order->getCustomerIsGuest()) {
                $quote->setCustomerIsGuest(true);
                $quote->setCustomerGroupId(\Magento\Customer\Api\Data\GroupInterface::NOT_LOGGED_IN_ID);
            }

            $payment        = $order->getPayment();
            $method         = $payment->getMethod();
            $quotePayment   = $quote->getPayment();

            $quotePayment->setMethod($method);

            $orderBillingAddress    = $order->getBillingAddress();
            $billingAddress         = $this->addressFactory->create();

            // Map the order's billing address data to the quote's billing address
            $billingAddress
                ->setFirstname($orderBillingAddress->getFirstname())
                ->setLastname($orderBillingAddress->getLastname())
                ->setStreet($orderBillingAddress->getStreet())
                ->setCity($orderBillingAddress->getCity())
                ->setRegion($orderBillingAddress->getRegion())
                ->setRegionId($orderBillingAddress->getRegionId())
                ->setPostcode($orderBillingAddress->getPostcode())
                ->setCountryId($orderBillingAddress->getCountryId())
                ->setTelephone($orderBillingAddress->getTelephone())
                ->setEmail($orderBillingAddress->getEmail());

            $quote->setBillingAddress($billingAddress);

            if (!$order->getIsVirtual()) {
                $orderShippingAddress   = $order->getShippingAddress();
                $shippingMethod         = $order->getShippingMethod();
                $shippingAddress        = $this->addressFactory->create();

                $shippingAddress
                    ->setFirstname($orderShippingAddress->getFirstname())
                    ->setLastname($orderShippingAddress->getLastname())
                    ->setStreet($orderShippingAddress->getStreet())
                    ->setCity($orderShippingAddress->getCity())
                    ->setRegion($orderShippingAddress->getRegion())
                    ->setRegionId($orderShippingAddress->getRegionId())
                    ->setPostcode($orderShippingAddress->getPostcode())
                    ->setCountryId($orderShippingAddress->getCountryId())
                    ->setTelephone($orderShippingAddress->getTelephone())
                    ->setEmail($orderShippingAddress->getEmail())
                    ->setCollectShippingRates(true)
                    ->setShippingMethod($shippingMethod);

                $quote->setShippingAddress($shippingAddress);
                $quote->collectTotals();
            }

            // add the items
            foreach ($order->getAllItems() as $orderItem) {
                $product = $this->productRepository->getById($orderItem->getProductId());

                // Check if the product is in stock and enabled
                if (!$product->getIsSalable()) {
                    $this->readerWriter->createLog('Product ' . $product->getSku() . ' is not available for sale.');
                    continue;
                }

                $quoteItem = $this->quoteItemFactory->create();
                $quoteItem->setProduct($product);
                $quoteItem->setQty($orderItem->getQtyOrdered());
                $quote->addItem($quoteItem);
            }

            $this->quoteRepository->save($quote);

            // TODO delete the order at the end?
    //                $this->orderResourceModel->delete($order);

            return $result->setData([
                "success" => 1,
            ]);
        }
        catch (\Exception $e) {
            $this->readerWriter->createLog($e->getMessage(), 'Exception when we try to create new Quote by Order.', 'WARN');
            
            return $result->setData([
                "success" => 0,
            ]);
        }
    }
    
}
