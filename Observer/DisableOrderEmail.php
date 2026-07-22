<?php

namespace Nuvei\Checkout\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;

/**
 * @author Nuvei
 */
class DisableOrderEmail implements ObserverInterface
{
    private $readerWriter;
    
    public function __construct(
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        $this->readerWriter = $readerWriter;
    }
    
    public function execute(Observer $observer)
    {
        // Get the order object from the observer
        $order = $observer->getEvent()->getOrder();
        
        $orderStatus = $order->getStatus();
        
        $this->readerWriter->createLog($orderStatus, 'DisableOrderEmail Observer Order satatus');

        // Disable customer email
        $order->setCanSendNewEmailFlag(false);

        // Optional: Disable guest email as well (if needed)
         $order->setCanSendNewGuestEmailFlag(false);

        return $this;
    }
}
