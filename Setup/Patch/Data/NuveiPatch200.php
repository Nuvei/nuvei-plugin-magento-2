<?php

/**
 * Add new patch file or change the namer of the current one,
 * every time we need to add something new into it.
 */

namespace Nuvei\Checkout\Setup\Patch\Data;

use Magento\Sales\Model\Order\StatusFactory as OrderStatusFactory;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;
//use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory; // this class will be generated during the compile

class NuveiPatch200 implements DataPatchInterface
{
    /**
     * 
     *
     * @var ModuleDataSetupInterface 
     */
    private $moduleDataSetup;

    /**
     * 
     *
     * @var EavSetupFactory 
     */
    private $eavSetupFactory;
    
    /**
     * 
     *
     * @var OrderStatusFactory 
     */
    private $orderStatusFactory;
    
    private $readerWriter;
   
    /**
     * @param OrderStatusFactory       $orderStatusFactory
     * @param ModuleDataSetupInterface $moduleDataSetup
     * @param EavSetupFactory          $eavSetupFactory
     */
    public function __construct(
        OrderStatusFactory          $orderStatusFactory,
        ModuleDataSetupInterface    $moduleDataSetup,
        EavSetupFactory             $eavSetupFactory
    ) {
        $this->moduleDataSetup      = $moduleDataSetup;
        $this->eavSetupFactory      = $eavSetupFactory;
        $this->orderStatusFactory   = $orderStatusFactory;
    }
    
    /**
     * {@inheritdoc}
     */
    public function apply()
    {
        /**
* 
         *
 * @var EavSetup $eavSetup 
*/
        $eavSetup = $this->eavSetupFactory->create(['setup' => $this->moduleDataSetup]);
        
        // add few new Order States
        $scVoided = $this->orderStatusFactory->create()
            ->setData('status', 'nuvei_voided')
            ->setData('label', 'Nuvei Voided')
            ->save();
        $scVoided->assignState(Order::STATE_PROCESSING, false, true);

        $scSettled = $this->orderStatusFactory->create()
            ->setData('status', 'nuvei_settled')
            ->setData('label', 'Nuvei Settled')
            ->save();
        $scSettled->assignState(Order::STATE_PROCESSING, false, true);

        $scAuth = $this->orderStatusFactory->create()
            ->setData('status', 'nuvei_auth')
            ->setData('label', 'Nuvei Auth')
            ->save();
        $scAuth->assignState(Order::STATE_PROCESSING, false, true);

        $scProcessing = $this->orderStatusFactory->create()
            ->setData('status', 'nuvei_processing')
            ->setData('label', 'Nuvei Processing')
            ->save();
        $scProcessing->assignState(Order::STATE_PROCESSING, false, true);

        $scRefunded = $this->orderStatusFactory->create()
            ->setData('status', 'nuvei_refunded')
            ->setData('label', 'Nuvei Refunded')
            ->save();
        $scRefunded->assignState(Order::STATE_PROCESSING, false, true);

        $scCanceled = $this->orderStatusFactory->create()
            ->setData('status', 'nuvei_canceled')
            ->setData('label', 'Nuvei Canceled')
            ->save();
        $scCanceled->assignState(Order::STATE_CANCELED, false, true);
        // /add few new Order States
        
        // Admin > Product > Nuvei Subscription details
        // Enable subscription
        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_ENABLE,
            [
                'label'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_ENABLE_LABEL,
                'global'    => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'source'    => \Magento\Eav\Model\Entity\Attribute\Source\Boolean::class,
                'group'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_GROUP,

                'type'                      => 'int',
                'input'                     => 'boolean',
                'visible'                   => true,
                'required'                  => false,
                'user_defined'              => true,
                'default'                   => '',
                'searchable'                => false,
                'filterable'                => false,
                'visible_on_front'          => false,
                'used_in_product_listing'   => true,
                'sort_order'                => 10,
                'class'                     => 'sc_enable_subscr',
            ]
        );
        
        // Plan IDs
        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            \Nuvei\Checkout\Model\Config::PAYMENT_PLANS_ATTR_NAME,
            [
                'label'     => \Nuvei\Checkout\Model\Config::PAYMENT_PLANS_ATTR_LABEL,
                'global'    => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'source'    => \Nuvei\Checkout\Model\Config\Source\PaymentPlansOptions::class,
                'group'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_GROUP,

                'type'                      => 'int',
                'input'                     => 'select',
                'visible'                   => true,
                'required'                  => false,
                'user_defined'              => true,
                'default'                   => '',
                'searchable'                => false,
                'filterable'                => false,
                'visible_on_front'          => false,
                'used_in_product_listing'   => true,
                'option'                    => ['values' => []],
                'sort_order'                => 20,
            ]
        );

        // Recurring Amount
        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_REC_AMOUNT,
            [
                'label'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_REC_AMOUNT_LABEL,
                'global'    => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'group'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_GROUP,

                'type'                      => 'decimal',
                'input'                     => 'price',
                'visible'                   => true,
                'required'                  => false,
                'user_defined'              => true,
                'default'                   => '0',
                'searchable'                => false,
                'filterable'                => false,
                'visible_on_front'          => false,
                'used_in_product_listing'   => true,
                'sort_order'                => 40,
            ]
        );

        // Recurring Units
        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_RECURR_UNITS,
            [
                'label'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_RECURR_UNITS_LABEL,
                'global'    => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'source'    => \Nuvei\Checkout\Model\Config\Source\SubscriptionUnits::class,
                'group'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_GROUP,

                'type'                      => 'text',
                'input'                     => 'select',
                'visible'                   => true,
                'required'                  => false,
                'user_defined'              => false,
                'default'                   => 'day',
                'searchable'                => false,
                'filterable'                => false,
                'visible_on_front'          => false,
                'used_in_product_listing'   => false,
                'option'                    => ['values' => []],
                'sort_order'                => 50,
            ]
        );

        // Recurring Period
        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_RECURR_PERIOD,
            [
                'label'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_RECURR_PERIOD_LABEL,
                'global'    => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'group'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_GROUP,

                'type'                      => 'int',
                'input'                     => 'text',
                'visible'                   => true,
                'required'                  => false,
                'user_defined'              => true,
                'default'                   => '0',
                'searchable'                => false,
                'filterable'                => false,
                'visible_on_front'          => false,
                'used_in_product_listing'   => false,
                'sort_order'                => 60,
                'note'                      => __('Integer value, bigger than 0.'),
            ]
        );

        // Trial Units
        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_TRIAL_UNITS,
            [
                'label'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_TRIAL_UNITS_LABEL,
                'global'    => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'source'    => \Nuvei\Checkout\Model\Config\Source\SubscriptionUnits::class,
                'group'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_GROUP,

                'type'                      => 'text',
                'input'                     => 'select',
                'visible'                   => true,
                'required'                  => false,
                'user_defined'              => false,
                'default'                   => 'day',
                'searchable'                => false,
                'filterable'                => false,
                'visible_on_front'          => false,
                'used_in_product_listing'   => false,
                'option'                    => ['values' => []],
                'sort_order'                => 70,
            ]
        );

        // Trial Period
        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_TRIAL_PERIOD,
            [
                'label'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_TRIAL_PERIOD_LABEL,
                'global'    => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'group'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_GROUP,

                'type'                      => 'int',
                'input'                     => 'text',
                'visible'                   => true,
                'required'                  => false,
                'user_defined'              => true,
                'default'                   => '0',
                'searchable'                => false,
                'filterable'                => false,
                'visible_on_front'          => false,
                'used_in_product_listing'   => false,
                'sort_order'                => 80,
                'note'                      => __('Integer value, bigger or equal to 0.'),
            ]
        );

        // End After Units
        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_END_AFTER_UNITS,
            [
                'label'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_END_AFTER_UNITS_LABEL,
                'global'    => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'source'    => \Nuvei\Checkout\Model\Config\Source\SubscriptionUnits::class,
                'group'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_GROUP,

                'type'                      => 'text',
                'input'                     => 'select',
                'visible'                   => true,
                'required'                  => false,
                'user_defined'              => false,
                'default'                   => 'day',
                'searchable'                => false,
                'filterable'                => false,
                'visible_on_front'          => false,
                'used_in_product_listing'   => false,
                'option'                    => ['values' => []],
                'sort_order'                => 90,
            ]
        );

        // End After Period
        $eavSetup->addAttribute(
            \Magento\Catalog\Model\Product::ENTITY,
            \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_END_AFTER_PERIOD,
            [
                'label'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_END_AFTER_PERIOD_LABEL,
                'global'    => \Magento\Eav\Model\Entity\Attribute\ScopedAttributeInterface::SCOPE_GLOBAL,
                'group'     => \Nuvei\Checkout\Model\Config::PAYMENT_SUBS_GROUP,

                'type'                      => 'int',
                'input'                     => 'text',
                'visible'                   => true,
                'required'                  => false,
                'user_defined'              => true,
                'default'                   => '0',
                'searchable'                => false,
                'filterable'                => false,
                'visible_on_front'          => false,
                'used_in_product_listing'   => false,
                'sort_order'                => 100,
                'note'                      => __('Integer value, bigger than 0.'),
            ]
        );
        // Admin > Product > Nuvei Subscription details END
        
        return $this;
    }
    
    /**
     * If the module version number in our database is higher than the version
     * we specified here in our file, the patch will not execute.
     *
     * {@inheritdoc}
     * 
     * @deprecated since version 3.2.0
     */
    public static function getVersion()
    {
        return '2.0.0';
    }
    
    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }
}
