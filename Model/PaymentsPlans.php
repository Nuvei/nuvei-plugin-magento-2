<?php

namespace Nuvei\Checkout\Model;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Checkout\Model\Session;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Model\ResourceModel\Entity\Attribute;
use Magento\Quote\Api\CartRepositoryInterface;
use Nuvei\Checkout\Model\Config;
use Nuvei\Checkout\Model\ReaderWriter;

/**
 * Helper class to provide information about eventual payment plans for products.
 *
 * @author Nuvei
 */
class PaymentsPlans
{
    private $readerWriter;
    private $config;
    private $productRepository;
    private $configurable;
    private $eavAttribute;
    private $productObj;
    private $quote;
    private $quoteId;
    private $cartRepo;
    private $checkoutSession;
    private $order;
    
    public function __construct(
        ReaderWriter $readerWriter,
        ProductRepositoryInterface $productRepository,
        Configurable $configurable,
        Attribute $eavAttribute,
        Product $productObj,
        CartRepositoryInterface $cartRepo,
        Session $checkoutSession
    ) {
        $this->readerWriter         = $readerWriter;
        $this->productRepository    = $productRepository;
        $this->configurable         = $configurable;
        $this->eavAttribute         = $eavAttribute;
        $this->productObj           = $productObj;
        $this->cartRepo             = $cartRepo;
        $this->checkoutSession      = $checkoutSession;
    }
    
    /**
     * Search for the product with Payment Plan in the Quote or the Order.
     *
     * @param int   $product_id
     * @param array $params     Pairs option key id with option value.
     *
     * @return array $return_arr
     */
    public function getProductPlanData()
    {
        // Get from Quote.
        if (!is_object($this->order)) {
            return $this->getProductPlanDataFromQuote();
        }
        
        return $this->getProductPlanDataFromOrder();
    }
    
    /**
     * Search for the product with Payment Plan by product ID.
     * We use this method for the rebilling logic only.
     *
     * @param int $product_id
     * @param array $params     Pairs option key id with option value.
     *
     * @return array $return_arr
     */
    public function getProductPlanDataById($product_id, array $params)
    {
//        $items_data = [];
        $plan_data  = [];
        $return_arr = [];
//        $quote      = empty($this->quoteId) ? $this->checkoutSession->getQuote() 
//            : $this->cartRepo->get($this->quoteId);
        
        $this->readerWriter->createLog(
            [
                '$product_id'   => $product_id,
                '$params'       => $params,
            ],
            'Input params.'
        );
        
        try {
            // 2. in case we pass product ID and product options as array.
            // we do not serach in the Cart and may be there is not Item data
            if (0 == $product_id || empty($params)) {
                return $return_arr;
            }

            $prod_options = [];

            // sometimes the key can be the options codes, we need the IDs
            foreach ($params as $key => $val) {
                if (is_numeric($key)) {
                    $prod_options[$key] = $val;
                    continue;
                }

                // get the option ID by its key
                $attributeId = $this->eavAttribute->getIdByCode('catalog_product', $key);

                if (!$attributeId) {
                    $this->readerWriter->createLog(
                        [$key, $attributeId],
                        'Attribute ID must be int.'
                    );
                    continue;
                }

                $prod_options[$attributeId] = $val;
            }

            if (empty($prod_options)) {
                $this->readerWriter->createLog('$prod_options are empty.');
                return [];
            }

            $parent     = $this->productRepository->getById($product_id);
            $product    = $this->configurable->getProductByAttributes($prod_options, $parent);
            
            $this->readerWriter->createLog(
                $product->getCustomAttribute('nuvei_sub_enabled'),
                'getProductPlanData get nuvei_sub_enabled on simple product'
            );

            $plan_data = $this->buildPlanDetailsArray($product);
            
            $this->readerWriter->createLog(
                $plan_data,
                'getProductPlanData $plan_data of incoming product'
            );

            if (!empty($plan_data)) {
                return $plan_data;
            }

            return $return_arr;
        } catch (\Exception $e) {
            $this->readerWriter->createLog($e->getMessage(), 'getProductPlanData() Exception:');
            return [];
        }
    }
    
    /**
     * Pass the Quote ID in case of REST API logic.
     * 
     * @param  int $quoteId
     * @return $this
     */
    public function setQuoteId($quoteId = '')
    {
        $this->quoteId = $quoteId;
        
        return $this;
    }
    
    public function setOrder($order)
    {
        $this->order = $order;
        
        return $this;
    }
    
    /**
     * Help function for getProductPlanData.
     * We moved here few of repeating part of code.
     *
     * @params MagentoProduct
     * @return array
     */
    private function buildPlanDetailsArray($product)
    {
        $attr = $product->getCustomAttribute(Config::PAYMENT_SUBS_ENABLE);
        
        if (null === $attr) {
            $this->readerWriter->createLog(
                'buildPlanDetailsArray() - '
                . 'there is no subscription attribute PAYMENT_SUBS_ENABLE'
            );
            return [];
        }
        
        $subscription_enabled = $attr->getValue();
        
        if (0 == $subscription_enabled) {
            $this->readerWriter->createLog(
                'buildPlanDetailsArray() - '
                . 'for this product the Subscription is not enabled or not set.'
            );
            return [];
        }
        
        try {
            $recurr_unit_obj        = $product->getCustomAttribute(Config::PAYMENT_SUBS_RECURR_UNITS);
            $recurr_unit            = is_object($recurr_unit_obj) ? $recurr_unit_obj->getValue() : 'month';

            $recurr_period_obj      = $product->getCustomAttribute(Config::PAYMENT_SUBS_RECURR_PERIOD);
            $recurr_period          = is_object($recurr_period_obj) ? $recurr_period_obj->getValue() : 0;

            $trial_unit_obj         = $product->getCustomAttribute(Config::PAYMENT_SUBS_TRIAL_UNITS);
            $trial_unit             = is_object($trial_unit_obj) ? $trial_unit_obj->getValue() : 'month';

            $trial_period_obj       = $product->getCustomAttribute(Config::PAYMENT_SUBS_TRIAL_PERIOD);
            $trial_period           = is_object($trial_period_obj) ? $trial_period_obj->getValue() : 0;

            $end_after_unit_obj     = $product->getCustomAttribute(Config::PAYMENT_SUBS_END_AFTER_UNITS);
            $end_after_unit         = is_object($end_after_unit_obj) ? $end_after_unit_obj->getValue() : 'month';

            $end_after_period_obj   = $product->getCustomAttribute(Config::PAYMENT_SUBS_END_AFTER_PERIOD);
            $end_after_period       = is_object($end_after_period_obj) ? $end_after_period_obj->getValue() : 0;

            $rec_amount             = $product->getCustomAttribute(Config::PAYMENT_SUBS_REC_AMOUNT)->getValue();

            $return_arr = [
                'planId'            => $product->getCustomAttribute(Config::PAYMENT_PLANS_ATTR_NAME)->getValue(),
                'initialAmount'     => 0,
                'recurringAmount'   => number_format($rec_amount, 2, '.', ''),
                'recurringPeriod'   => [strtolower($recurr_unit)    => $recurr_period],
                'startAfter'        => [strtolower($trial_unit)     => $trial_period],
                'endAfter'          => [strtolower($end_after_unit) => $end_after_period],
            ];

            $this->readerWriter->createLog($return_arr, 'buildPlanDetailsArray()');

            return $return_arr;
        } catch (\Exception $e) {
            $this->readerWriter->createLog($e->getMessage(), 'buildPlanDetailsArray() Exception');
            return [];
        }
    }
    
    private function getProductPlanDataFromQuote()
    {
        $this->readerWriter->createLog('getProductPlanDataFromQuote');
        
        $nuveiAttrName  = 'nuvei_sub_enabled';
        $quote          = empty($this->quoteId) ? $this->checkoutSession->getQuote() 
            : $this->cartRepo->get($this->quoteId);
        $items_data     = [];
        $plan_data      = [];
        $return_arr     = [];
        
        try {
            if (!$quote->getItemsCount()) {
                $this->readerWriter->createLog('The Quote is empty - no items.');
                return $return_arr;
            }

            foreach($quote->getAllVisibleItems() as $item) {
                $product    = $item->getProduct();
                $product_id = $product->getId();
                
                try {
                    // For configurable products, getAllVisibleItems() returns the parent.
                    // The nuvei_sub_enabled attribute lives on the simple child, so we
                    // must resolve it via the selected super_attribute options.
                    $buyRequest = $item->getBuyRequest();
                    $superAttr  = $buyRequest ? $buyRequest->getSuperAttribute() : null;

                    if (!empty($superAttr)) {
                        // Configurable product: resolve the simple child
                        $parent         = $this->productRepository->getById($product_id);
                        $childProduct   = $this->configurable->getProductByAttributes($superAttr, $parent);

                        if (!is_object($childProduct)) {
                            $this->readerWriter->createLog(
                                'Could not resolve configurable child for product ID: ' . $product_id
                            );
                            continue;
                        }

                        $fullProduct = $this->productRepository->getById($childProduct->getId());
                    }
                    else {
                        // Simple product: load it fully to get EAV attributes
                        $fullProduct = $this->productRepository->getById($product_id);
                    }

                    // check for nuvei_sub_enabled, it will be an object (Attribute Value)
                    $nuvei_sub_enabled = $fullProduct->getCustomAttribute($nuveiAttrName);
                }
                catch (\Exception $e) {
                    $this->readerWriter->createLog
                        ($e->getMessage(), 
                        'getProductPlanDataFromQuote() item load Exception:'
                    );
                    
                    continue;
                }
                
                // there  is the attribute
                if ($nuvei_sub_enabled && $nuvei_sub_enabled->getValue()) {
                    $this->readerWriter->createLog('Found nuvei_sub_enabled for Product ID: ' 
                        . $fullProduct->getId());

                    // Pass the fully loaded product so buildPlanDetailsArray can read EAV attributes
                    $plan_data = $this->buildPlanDetailsArray($fullProduct);

                    if (empty($plan_data)) {
                        return $return_arr;
                    }

                    $items_data[$fullProduct->getId()] = [
                        'quantity'  => $item->getQty(),
                        'price'     => round((float) $item->getPrice(), 2),
                    ];

                    $plan_data['recurringAmount'] *= $items_data[$fullProduct->getId()]['quantity'];

                    $this->readerWriter->createLog(
                        $plan_data,
                        'getProductPlanData $plan_data'
                    );

                    $return_arr = [
                        'subs_data'     => $plan_data,
                        'items_data'    => $items_data,
                    ];

                    return $return_arr;
                }
            }

            return $return_arr;
        }
        catch (\Exception $e) {
            $this->readerWriter->createLog($e->getMessage(), 'getProductPlanData() Exception:');
            return [];
        }
    }
    
    private function getProductPlanDataFromOrder()
    {
        $nuveiAttrName  = 'nuvei_sub_enabled';
        $items_data     = [];
        $plan_data      = [];
        $return_arr     = [];
        
        try {
            $itemsQty = $this->order->getTotalItemCount();
            
            if (0 == $itemsQty) {
                $this->readerWriter->createLog('Items quantity is 0');
                return $return_arr;
            }
            
            $items = $this->order->getAllItems();
            
            if (empty($items) || !is_array($items)) {
                $this->readerWriter->createLog('There are no Items in the Cart or $items is not an array');

                return $return_arr;
            }
            
            foreach ($items as $orderItem) {
                $product    = $this->productRepository->getById($orderItem->getProductId());
                $nuveiAttr  = $product->getCustomAttribute($nuveiAttrName);

                if (!is_object($nuveiAttr) || !$nuveiAttr->getValue()) {
                    continue;
                }
                
                $plan_data = $this->buildPlanDetailsArray($product);
                    
                if (empty($plan_data)) {
                    continue;
                }

                $items_data[$orderItem->getId()] = [
                    'quantity'  => $orderItem->getQtyOrdered(),
                    'price'     => round((float) $orderItem->getPrice(), 2),
                ];

                $plan_data['recurringAmount'] *= $items_data[$orderItem->getId()]['quantity'];

                $this->readerWriter->createLog([$items_data, $plan_data], '$items_data, $plan_data');
                
                if (!empty($plan_data)) {
                    return [
                        'subs_data'     => $plan_data,
                        'items_data'    => $items_data,
                    ];
                }
            }
        } catch (\Exception $e) {
            $this->readerWriter->createLog($e->getMessage(), 'getProductPlanData() Exception:');
            return [];
        }
    }
    
}
