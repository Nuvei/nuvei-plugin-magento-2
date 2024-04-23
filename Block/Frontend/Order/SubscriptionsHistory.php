<?php

namespace Nuvei\Checkout\Block\Frontend\Order;

use \Magento\Framework\App\ObjectManager;
use \Magento\Sales\Model\ResourceModel\Order\CollectionFactoryInterface;

class SubscriptionsHistory extends \Magento\Sales\Block\Order\History
{
    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\CollectionFactory
     */
    protected $_orderCollectionFactory;

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $_customerSession;

    /**
     * @var \Magento\Sales\Model\Order\Config
     */
    protected $_orderConfig;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\Collection
     */
    protected $orders;

    /**
     * @var CollectionFactoryInterface
     */
    private $orderCollectionFactory;
    
    private $config;
    private $request;
    private $get_params;
    
    /**
     * @param \Magento\Framework\View\Element\Template\Context           $context
     * @param \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory
     * @param \Magento\Customer\Model\Session                            $customerSession
     * @param \Magento\Sales\Model\Order\Config                          $orderConfig
     * @param array                                                      $data
     */
    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Sales\Model\ResourceModel\Order\CollectionFactory $orderCollectionFactory,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Sales\Model\Order\Config $orderConfig,
        \Nuvei\Checkout\Model\Config $config,
        \Magento\Framework\App\RequestInterface $request
    ) {
        $this->_orderCollectionFactory  = $orderCollectionFactory;
        $this->_customerSession         = $customerSession;
        $this->_orderConfig             = $orderConfig;
        $this->config                   = $config;
        $this->get_params               = $request->getParams();
        
        parent::__construct($context, $orderCollectionFactory, $customerSession, $orderConfig);
    }
    
    /**
     * @return CollectionFactoryInterface
     *
     * @deprecated 100.1.1
     */
    private function getOrderCollectionFactory()
    {
        if ($this->orderCollectionFactory === null) {
            $this->orderCollectionFactory = ObjectManager::getInstance()->get(CollectionFactoryInterface::class);
        }
        return $this->orderCollectionFactory;
    }
    
    /**
     * @return bool|\Magento\Sales\Model\ResourceModel\Order\Collection
     */
    public function getOrders()
    {
        if (!($customerId = $this->_customerSession->getCustomerId())) {
            return false;
        }
        if (!$this->orders) {
            $this->orders = $this->getOrderCollectionFactory()->create($customerId)
                ->addFieldToSelect('*')
                ->addFieldToFilter(
                    'status',
                    ['in' => $this->_orderConfig->getVisibleOnFrontStatuses()]
                )
                ->setOrder(
                    'created_at',
                    'desc'
                );
            
            $this->orders->getSelect()
                ->join(
                    ["sop" => "sales_order_payment"],
                    'main_table.entity_id = sop.parent_id',
                    ['additional_information']
                )
                ->where('sop.additional_information LIKE \'%"is_active_subs_order":1%\'');
        }
        
        return $this->orders;
    }
    
    /**
     * @return string
     */
//    public function getPagerHtml()
//    {
//        return $this->getChildHtml('pager');
//    }

    /**
     * @param  object $order
     * @return string
     */
//    public function getViewUrl($order)
//    {
//        return $this->getUrl('sales/order/view', ['order_id' => $order->getId()]);
//    }

    /**
     * @param  object $order
     * @return string
     */
    public function getTrackUrl($order)
    {
        return $this->getUrl('sales/order/track', ['order_id' => $order->getId()]);
    }

    /**
     * @param  object $order
     * @return string
     */
//    public function getReorderUrl($order)
//    {
//        return $this->getUrl('sales/order/reorder', ['order_id' => $order->getId()]);
//    }

    /**
     * @return string
     */
//    public function getBackUrl()
//    {
//        return $this->getUrl('customer/account/');
//    }
}
