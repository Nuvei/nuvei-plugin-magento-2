<?php

namespace Nuvei\Checkout\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
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
    private $cart;
    private $checkoutSession;
    private $productFactory;

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
        \Magento\Checkout\Model\Session $checkoutSession,
		\Magento\Catalog\Model\ProductFactory $productFactory
    ) {
        parent::__construct($context);

        $this->moduleConfig         = $moduleConfig;
        $this->jsonResultFactory    = $jsonResultFactory;
        $this->requestFactory       = $requestFactory;
        $this->readerWriter         = $readerWriter;
        $this->cart                 = $cart;
        $this->checkoutSession      = $checkoutSession;
        $this->productFactory		= $productFactory;
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
			
			$cartBackup = $this->checkoutSession->getData('backup_cart');
			
			$this->readerWriter->createLog($cartBackup, '$cartBackup');

			if (!empty($cartBackup)) {
				foreach ($cartBackup as $productData) {
					// Determine if we have configurable product options by checking if info_buyRequest exists
					if (!empty($productData['options']['info_buyRequest'])) {
						// Decode info_buyRequest (which is a JSON string)
						$decodedBuyRequest = json_decode($productData['options']['info_buyRequest'], true);
//						$decodedBuyRequest['selected_configurable_option']	= $productData['options']['simple_product'];
						
						if (!is_array($decodedBuyRequest)) {
							continue; // Skip if invalid JSON
						}

						// Re-encode the info_buyRequest
//						$newInfoBuyRequest = json_encode($decodedBuyRequest);
						
						// Build the buy request array
						$buyRequestData = [
//							'product'						=> $productData['product_id'],
							'qty'							=> $productData['qty'],
							'super_attribute'				=> $decodedBuyRequest['super_attribute'],
//							'info_buyRequest'				=> $newInfoBuyRequest,
//							'selected_configurable_option'	=> $productData['options']['simple_product'],
//							'custom_unique'					=> $decodedBuyRequest['custom_unique'],
						];
						
						$buyRequest = new \Magento\Framework\DataObject($buyRequestData);

						$parentProduct = $this->productFactory->create()
							->setStoreId($this->moduleConfig->getStoreId())
							->load($productData['product_id']);

						$this->cart->addProduct($parentProduct, $buyRequest->getData());
					} else { // For simple products
						$product = $this->productFactory->create()
							->setStoreId($this->moduleConfig->getStoreId())
							->load($productData['product_id']);
						
						$this->cart->addProduct($product, ['qty' => $productData['qty']]);
					}
				}
			}

//            // Save the cart (quote)
            $this->cart->save();
			$this->cart->getQuote()->setTotalsCollectedFlag(false)->collectTotals()->save();
			
			$cartItems = $this->cart->getQuote()->getAllVisibleItems();
			foreach ($cartItems as $item) {
				$this->readerWriter->createLog((array) $item->getOptions(), 'check getOptions');
			}


            $this->messageManager->addErrorMessage(__('Your Payment was declined. Please, try again!'));
            
            return $this->_redirect('checkout/cart');
        }
        catch (\Exception $e) {
            $this->readerWriter->createLog($e->getMessage(), 'Exception when we try to create new Quote by Order.', 'WARN');
            
			$this->messageManager->addErrorMessage(__('Unexpected error. Please, check you order and try again!'));
			
			return $this->_redirect('checkout/cart');
        }
    }
    
}
