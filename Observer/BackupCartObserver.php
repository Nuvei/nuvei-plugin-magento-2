<?php

namespace Nuvei\Checkout\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
use Magento\Checkout\Model\Session as CheckoutSession;

class BackupCartObserver implements ObserverInterface
{
	protected $checkoutSession;
	
	private $readerWriter;
	private $configurableProduct;
	private $grouped;
	private $bundleSelection;

    public function __construct(
        CheckoutSession $checkoutSession,
		\Nuvei\Checkout\Model\ReaderWriter $readerWriter,
		\Magento\ConfigurableProduct\Model\ResourceModel\Product\Type\Configurable $configurableProduct,
		\Magento\GroupedProduct\Model\Product\Type\Grouped $grouped,
		\Magento\Bundle\Model\Product\Type $bundleSelection
    ) {
        $this->checkoutSession		= $checkoutSession;
        $this->readerWriter			= $readerWriter;
        $this->configurableProduct	= $configurableProduct;
        $this->grouped				= $grouped;
        $this->bundleSelection		= $bundleSelection;
    }

    public function execute(Observer $observer)
    {
		$this->readerWriter->createLog('BackupCartObserver.');
		
		try {
			// Get the quote before it becomes an order
			$quote = $observer->getEvent()->getQuote();

			foreach ($quote->getAllVisibleItems() as $item) {
				$product = $item->getProduct();
				$options = $item->getProduct()->getCustomOptions();
				
				$backupItem = [
					'product_id'		=> $product->getId(),  // Child product ID
					'qty'				=> $item->getQty(),
					'options'			=> [], // Store selected options if available
					'parent_product_id'	=> null
				];

				// Check if it's a configurable product
//				if ($item->getProductType() === 'configurable') {
//				if ($item->getProductType() === 'simple') {
					$parentIds = $this->configurableProduct->getParentIdsByChild($product->getId());
					if (!empty($parentIds)) {
						$backupItem['parent_product_id'] = $parentIds[0]; // Store parent product
					}
					
					$groupIds = $this->grouped->getParentIdsByChild($product->getId());
//					if (isset($groupIds[0])) {
//						$parent_id = $groupIds[0];
//						return $parent_id;
//					}
					
					$budle_id = $this->bundleSelection->getParentIdsByChild($product->getId());
//					if (isset($budle_id[0])) {
//						$parent_id = $budle_id[0];
//						return $budle_id;
//					}
					
					$this->readerWriter->createLog([
						'child id' => $product->getId(),
						'$parentIds' => (array) $parentIds,
						'$groupIds' => (array) $groupIds,
						'$budle_id' => (array) $budle_id,
						'product type' => $product->getTypeId(),
					]);	
					
					// Store configurable product options
					if ($options) {
						foreach ($options as $option) {
							$backupItem['options'][$option->getCode()] = $option->getValue();
						}
					}
//				}

				$cartBackup[] = $backupItem;
			}

			$this->checkoutSession->setData('backup_cart', $cartBackup);
		}
		catch(\Exception $e) {
			$this->readerWriter->createLog($e->getMessage(), 'BackupCartObserver Exception.');
			return;
		}
		
		$this->readerWriter->createLog('BackupCartObserver after save Cart backup.');
    }
}
