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
        
		$orderId = $this->getRequest()->getParam('orderId');
		
		// error - order ID is not valid
		if (!$orderId || !is_numeric($orderId)) {
			$this->readerWriter->createLog($orderId, 'nuveiPrePayment() the passed order ID is not valid.');

			return $result->setData([
				"success"       => false,
				'sessionToken'  => '',
				'successUrl'    => '#',
			]);
		}
		
        $request    = $this->requestFactory->create(AbstractRequest::OPEN_ORDER_METHOD);
        $quoteId    = $this->getRequest()->getParam('quoteId'); // it comes form REST call as parameter
        $resp       = $request
            ->setQuoteId($quoteId)
            ->setOrderId($orderId)
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
		$result = $this->jsonResultFactory->create()
                ->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
		
        try {
			$cartBackup = $this->checkoutSession->getData('backup_cart');
			
			$this->readerWriter->createLog($cartBackup, '$cartBackup');
			
			// [ grouped_product_id => [prduct_1_id => quantity, product_2_id => quantity] ]
			$associatedProducts = [];

			if (!empty($cartBackup)) {
				foreach ($cartBackup as $productData) {
					$this->readerWriter->createLog([
						'product_id'		=> $productData['product_id'],
						'simple_product'	=> $productData['options']['simple_product'] ?? '',
					], 'backup product');
					
					// Determine if we have configurable product options by checking if info_buyRequest exists
					if (!empty($productData['options']['info_buyRequest'])) {
						// Decode info_buyRequest (which is a JSON string)
						$decodedBuyRequest = json_decode($productData['options']['info_buyRequest'], true);
						
						if (!is_array($decodedBuyRequest)) {
							continue; // Skip if invalid JSON
						}
						
						$bundleQuantities	= [];
						$bundleOptions		= $decodedBuyRequest['bundle_option'] ?? [];
						
						$buyRequestData = [
							'qty' => $productData['qty']
						];
						
						// configurable products
						if (isset($decodedBuyRequest['super_attribute'])) {
							$this->readerWriter->createLog('configurable product');
							
							$buyRequestData['super_attribute'] = $decodedBuyRequest['super_attribute'];
							
							$this->addProductToCart($buyRequestData, $productData['product_id']);
						}
						// bundles
						elseif (isset($decodedBuyRequest['bundle_option'])) {
							$this->readerWriter->createLog('bundle product');
							
							foreach ($decodedBuyRequest['bundle_option'] as $optionId => $selectionId) {
								// Find the corresponding quantity key
								$qtyKey							= "selection_qty_" . $selectionId;
								$bundleQuantities[$optionId]	= isset($productData['options'][$qtyKey]) 
									? (int) $productData['options'][$qtyKey] : 1;
							}
							
							$buyRequestData['bundle_option']		= $bundleOptions;
							$buyRequestData['bundle_option_qty']	= $bundleQuantities;
							
							$this->addProductToCart($buyRequestData, $productData['product_id']);
						}
						// grouped products
						elseif (isset($productData['options']['product_type']) 
							&& 'grouped' == $productData['options']['product_type']
							&& isset($decodedBuyRequest['super_product_config']['product_id'])
						) {
							$this->readerWriter->createLog('grouped product');
							
							$associatedProducts[$decodedBuyRequest['super_product_config']['product_id']][$productData['product_id']] = $productData['qty'];
						}
						// simple product
						else {
							$this->readerWriter->createLog('simple product');
						
							$product = $this->productFactory->create()
								->setStoreId($this->moduleConfig->getStoreId())
								->load($productData['product_id']);

							$this->cart->addProduct($product, ['qty' => $productData['qty']]);
						}
						
					}
					else { // simple products
						$this->readerWriter->createLog('simple product');
						
						$product = $this->productFactory->create()
							->setStoreId($this->moduleConfig->getStoreId())
							->load($productData['product_id']);
						
						$this->cart->addProduct($product, ['qty' => $productData['qty']]);
					}
				}
				
				// add groups if any
				if (!empty($associatedProducts)) {
					foreach ($associatedProducts as $grProdId => $prods) {
						$params = [
							'product'		=> $grProdId,  // Grouped product ID
							'super_group'	=> $prods,  // Child products and quantities
							'qty'			=> 1
						];
						
						$this->addProductToCart($params, $grProdId);
					}
				}
				
			}

//            // Save the cart (quote)
            $this->cart->save();
//			$this->cart->getQuote()->setTotalsCollectedFlag(false)->collectTotals()->save();
			
			$cartItems = $this->cart->getQuote()->getAllVisibleItems();
			foreach ($cartItems as $item) {
				$this->readerWriter->createLog((array) $item->getOptions(), 'check getOptions');
			}


            $this->messageManager->addErrorMessage(__('Your Payment was declined. Please, try again!'));
            
			return $result->setData([
				"success" => true,
			]);
        }
        catch (\Exception $e) {
            $this->readerWriter->createLog($e->getMessage(), 'Exception when we try to create new Quote by Order.', 'WARN');
            
			$this->messageManager->addErrorMessage(__('Unexpected error. Please, check you order and try again!'));
			
//			return $this->_redirect('checkout/cart');
			
			return $result->setData([
				"success" => false,
			]);
        }
    }
    
	/**
	 * Just a repeating part of code is here.
	 * 
	 * @param array $params
	 * @param int $prodId
	 */
	private function addProductToCart($params, $prodId)
	{
		$buyRequest = new \Magento\Framework\DataObject($params);

		$this->readerWriter->createLog($buyRequest);

		$parentProduct = $this->productFactory->create()
			->setStoreId($this->moduleConfig->getStoreId())
			->load($prodId);

		$this->cart->addProduct($parentProduct, $buyRequest->getData());
	}
	
}
