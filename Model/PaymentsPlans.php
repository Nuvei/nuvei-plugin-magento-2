<?php

namespace Nuvei\Checkout\Model;

use \Nuvei\Checkout\Model\Config;

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
    
    public function __construct(
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        \Magento\Catalog\Api\ProductRepositoryInterface $productRepository,
        \Magento\ConfigurableProduct\Model\Product\Type\Configurable $configurable,
        \Magento\Eav\Model\ResourceModel\Entity\Attribute $eavAttribute,
        \Magento\Catalog\Model\Product $productObj,
        \Magento\Checkout\Model\Session $checkoutSession
    ) {
        $this->readerWriter         = $readerWriter;
        $this->productRepository    = $productRepository;
        $this->configurable         = $configurable;
        $this->eavAttribute         = $eavAttribute;
        $this->productObj           = $productObj;
        $this->quote                = $checkoutSession->getQuote();
    }
    
    /**
     * Search for the product with Payment Plan.
     *
     * @param int $product_id
     * @param array $params pairs option key id with option value
     *
     * @return array $return_arr
     */
    public function getProductPlanData($product_id = 0, array $params = [])
    {
        $items_data = [];
        $plan_data  = [];
        $return_arr = [];
        
        try {
            # 1. when we search in the Cart
            if (0 == $product_id && empty($params)) {
                $items = $this->quote->getItems();
            
                if (empty($items) || !is_array($items)) {
                    $this->readerWriter->createLog(
                        $items,
                        'getProductPlanData() - there are no Items in the Cart or $items is not an array'
                    );

                    return $return_arr;
                }
                
                // if there are more than 1 products in the Cart we assume there are no product with a Plan
//                if (count($items) > 1) {
//                    $this->readerWriter->createLog('getProductPlanData() - the Items in the Cart are more than 1. '
//                        . 'We assume there is no Product with a plan amongs them.');
//                    return $return_arr;
//                }
                
                foreach($items as $item) {
                    $item = current($items);

                    if (!is_object($item)) {
                        $this->readerWriter->createLog('getProductPlanData() Error - '
                            . 'the Item in the Cart is not an Object.');
//                        return $return_arr;
                            continue;
                    }

                    $options = $item->getProduct()->getTypeInstance(true)
                        ->getOrderOptions($item->getProduct());
                    
                    $this->readerWriter->createLog($options, 'getProductPlanData $options');

                    // stop the proccess
                    if (empty($options['info_buyRequest'])
                        || !is_array($options['info_buyRequest'])
                    ) {
//                        return $return_arr;
                            continue;
                    }

                    // 1.1 in case of configurable product
                    // 1.1.1. when we have selected_configurable_option paramter
                    if (!empty($options['info_buyRequest']['selected_configurable_option'])) {
                        $product_id = $options['info_buyRequest']['selected_configurable_option'];
                        $product    = $this->productObj->load($product_id);

                        $nuvei_sub_enabled = $product->getCustomAttribute('nuvei_sub_enabled');
                        $this->readerWriter->createLog(
                            $nuvei_sub_enabled,
                            'getProductPlanData get nuvei_sub_enabled on configurable product'
                        );

                        if (!is_object($nuvei_sub_enabled)) {
//                            return $return_arr;
                            continue;
                        }
                    }
                    // 1.1.2. when we have super_attribute
                    elseif (!empty($options['info_buyRequest']['super_attribute'])
                            && !empty($options['info_buyRequest']['product'])
                        ) {
                        $parent     = $this->productRepository->getById($options['info_buyRequest']['product']);
                        $product    = $this->configurable->getProductByAttributes(
                            $options['info_buyRequest']['super_attribute'],
                            $parent
                        );
                        $product_id = $product->getId();

                        $nuvei_sub_enabled = $product->getCustomAttribute('nuvei_sub_enabled');
                        $this->readerWriter->createLog(
                            $nuvei_sub_enabled,
                            'getProductPlanData get nuvei_sub_enabled on configurable product'
                        );

                        if (!is_object($nuvei_sub_enabled)) {
//                            return $return_arr;
                            continue;
                        }
                    }

                    if (!empty($product) && 0 != $product_id) {
    //                        $plan_data[$product_id]     = $this->buildPlanDetailsArray($product);
                        $plan_data                  = $this->buildPlanDetailsArray($product);
                        $items_data[$product_id]    = [
                            'quantity'  => $item->getQty(),
                            'price'     => round((float) $item->getPrice(), 2),
                        ];

                        $plan_data['recurringAmount'] *= $items_data[$product_id]['quantity'];

                        $this->readerWriter->createLog(
                            $plan_data,
                            'getProductPlanData $plan_data'
                        );

                        // return plan details only if the subscription is enabled
    //                        if (!empty($plan_data[$product_id])) {
                        if (!empty($plan_data)) {
                            $return_arr = [
                                'subs_data'     => $plan_data,
                                'items_data'    => $items_data,
                            ];
                        }

                        return $return_arr;
                    }

                    // 1.2 in case of simple product
                    $product            = $this->productObj->load($options['info_buyRequest']['product']);
                    $nuvei_sub_enabled  = $product->getCustomAttribute('nuvei_sub_enabled');

                    $this->readerWriter->createLog(
                        $product->getCustomAttribute('nuvei_sub_enabled'),
                        'getProductPlanData get nuvei_sub_enabled on simple product'
                    );

                    if (!is_object($nuvei_sub_enabled)) {
//                        return $return_arr;
                        continue;
                    }

//                    $plan_data[$options['info_buyRequest']['product']] = $this->buildPlanDetailsArray($product);
                    $plan_data                  = $this->buildPlanDetailsArray($product);
                    $items_data[$item->getId()] = [
                        'quantity'  => $item->getQty(),
                        'price'     => round((float) $item->getPrice(), 2),
                    ];
                    
                    $plan_data['recurringAmount'] *= $items_data[$item->getId()]['quantity'];

//                    if (!empty($plan_data[$options['info_buyRequest']['product']])) {
                    if (!empty($plan_data)) {
//                        $return_arr = [
                        return [
                            'subs_data'     => $plan_data,
                            'items_data'    => $items_data,
                        ];
                    }

//                    return $return_arr;
                }
                
                return $return_arr;
            }

            # 2. in case we pass product ID and product options as array.
            # we do not serach in the Cart and may be there is not Item data
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
                        'SubscriptionsHistory Error - attribute ID must be int.'
                    );
                    continue;
                }

                $prod_options[$attributeId] = $val;
            }

            if (empty($prod_options)) {
                return [];
            }

            $parent     = $this->productRepository->getById($product_id);
            $product    = $this->configurable->getProductByAttributes($prod_options, $parent);
            
//            $nuvei_sub_enabled  = $product->getCustomAttribute('nuvei_sub_enabled');
//
//            $this->readerWriter->createLog(
//                $product->getCustomAttribute('nuvei_sub_enabled'),
//                'getProductPlanData get nuvei_sub_enabled on simple product'
//            );

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
            $this->readerWriter->createLog('buildPlanDetailsArray() - '
                . 'there is no subscription attribute PAYMENT_SUBS_ENABLE');
            return [];
        }
        
        $subscription_enabled = $attr->getValue();
        
        if (0 == $subscription_enabled) {
            $this->readerWriter->createLog('buildPlanDetailsArray() - '
                . 'for this product the Subscription is not enabled or not set.');
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
}
