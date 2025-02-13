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
//    protected $messageManager;


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
    private $cart;
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
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
//        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
//        \Magento\Quote\Api\Data\CartItemInterfaceFactory $cartItemFactory,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Quote\Api\Data\AddressInterfaceFactory $addressFactory,
        \Magento\Quote\Model\Quote\ItemFactory $quoteItemFactory,
        \Magento\Sales\Model\ResourceModel\Order $orderResourceModel
//        \Magento\Framework\Message\ManagerInterface $messageManager
    ) {
        parent::__construct($context);

        $this->moduleConfig         = $moduleConfig;
        $this->jsonResultFactory    = $jsonResultFactory;
        $this->requestFactory       = $requestFactory;
        $this->readerWriter         = $readerWriter;
        $this->cart                 = $cart;
        $this->quoteFactory         = $quoteFactory;
//        $this->checkoutSession      = $checkoutSession;
        $this->quoteRepository      = $quoteRepository;
//        $this->cartItemFactory      = $cartItemFactory;
        $this->productRepository    = $productRepository;
        $this->orderRepository      = $orderRepository;
        $this->addressFactory       = $addressFactory;
        $this->quoteItemFactory     = $quoteItemFactory;
        $this->orderResourceModel   = $orderResourceModel;
//        $this->messageManager       = $messageManager;
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
        
        $request    = $this->requestFactory->create(AbstractRequest::OPEN_ORDER_METHOD);
        $quoteId    = $this->getRequest()->getParam('quoteId'); // it comes form REST call as parameter
        $resp       = $request
            ->setQuoteId($quoteId)
            ->setOrderId($this->getRequest()->getParam('orderId'))
            ->prePaymentCheck();

        $successUrl = $this->moduleConfig->getCallbackSuccessUrl($quoteId);

        $respData = [
            "success"       => (int) !$resp->error,
            'sessionToken'  => isset($resp->sessionToken) ? $resp->sessionToken : '',
            'successUrl'    => $successUrl,
//            'orderId'       => isset($resp->orderId) ? $resp->orderId : 0,
        ];
        
        $this->readerWriter->createLog($respData, 'nuveiPrePayment() response data');

        return $result->setData($respData);
    }
    
	/**
	 * When the transaction is declined, rebuild the Cart by the last Order.
	 * 
	 * @return Response
	 * @throws \Magento\Framework\Exception\NoSuchEntityException
	 */
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

            // create Reorder
            // Get all items from the previous order
            $orderItems = $order->getItems();
            
            foreach ($orderItems as $orderItem) {
                if ($orderItem->getParentItemId()) {
                    // Skip parent items in case of configurable or bundle products
                    continue;
                }

                // Load the product by product ID
                $product = $this->productRepository->getById($orderItem->getProductId());

                // Prepare options if the product has custom options (configurable, etc.)
                $options = $orderItem->getProductOptions();
				
				// Check if it's a configurable product and has child SKU
				if (!empty($options['simple_sku'])) {
					// Load the correct simple (child) product by its SKU
					$product = $this->productRepository->get($options['simple_sku']);
				}
				
                $buyRequest = new \Magento\Framework\DataObject($options['info_buyRequest'] ?? []);

                // Add the product to the cart
                $this->cart->addProduct($product, $buyRequest);
            }

            // Save the cart (quote)
            $this->cart->save();

            $this->messageManager->addErrorMessage(__('Your Payment was declined. Please, try again!'));
            
            return $this->_redirect('checkout/cart');
            
            return $result->setData([
                "success" => 1,
                'redirectUrl' => $this->_redirect('checkout/cart')
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
