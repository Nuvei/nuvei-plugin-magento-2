<?php

namespace Nuvei\Checkout\Observer;

use Magento\Framework\Event\ObserverInterface;
use Magento\Framework\Event\Observer;
//use Magento\Sales\Model\Order;
use Magento\Framework\Exception\LocalizedException;

/**
 * @author Nuvei
 */
class OrderStatusChange implements ObserverInterface
{
    protected $orderSender;
    
    /**
     * The statuses are described in the Setup\Patch\Data class.
     * @var array
     */
    private $allowedStatuses = ['nuvei_voided', 'nuvei_settled', 'nuvei_auth', 'nuvei_refunded'];
    private $readerWriter;

    public function __construct(
        \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        $this->orderSender  = $orderSender;
        $this->readerWriter = $readerWriter;
    }
    
    /**
     * Execute the observer.
     *
     * @param Observer $observer
     * @return void
     * @throws LocalizedException
     */
    public function execute(Observer $observer)
    {
        // Get the order from the observer
        $order = $observer->getEvent()->getOrder();
        
        $this->readerWriter->createLog($order->getStatus(), 'OrderStatusChange Observer, Order satatus');

        if (in_array($order->getStatus(), $this->allowedStatuses) && !$order->getEmailSent()) {
            try {
                // Send the order confirmation email
                $this->orderSender->send($order);

                // Optionally, set the email as sent if needed
//                $order->setEmailSent(true);
//                $order->save();
            } catch (\Exception $e) {
                $this->readerWriter->createLog($e->getMessage(), 'Error while sending order email.');
                
                throw new LocalizedException(__('Error while sending order email: %1', $e->getMessage()));
            }
        }
    }
    
}
