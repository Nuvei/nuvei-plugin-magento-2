<?php

namespace Nuvei\Checkout\Model\Request\Payment;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\RequestInterface;

/**
 * Nuvei Checkout payment request factory model.
 */
class Factory
{
    /**
     * Set of requests.
     *
     * @var array
     */
    private $invokableClasses = [
        AbstractRequest::PAYMENT_REFUND_METHOD      => \Nuvei\Checkout\Model\Request\Payment\Refund::class,
        AbstractRequest::PAYMENT_VOID_METHOD        => \Nuvei\Checkout\Model\Request\Payment\Cancel::class,
        AbstractRequest::CANCEL_SUBSCRIPTION_METHOD => \Nuvei\Checkout\Model\Request\CancelSubscription::class,
    ];

    /**
     * Object manager object.
     *
     * @var ObjectManagerInterface
     */
    private $objectManager;

    /**
     * Construct
     *
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     */
    public function __construct(
        ObjectManagerInterface $objectManager
        //        ,\Nuvei\Checkout\Model\Config $config
    ) {
        $this->objectManager = $objectManager;
        //        $this->config           = $config;
    }

    /**
     * Create request model.
     *
     * @param string       $method
     * @param OrderPayment $orderPayment
     * @param float        $amount
     * @param int          $invoice_id
     *
     * @return RequestInterface
     * @throws LocalizedException
     */
    public function create($method, $orderPayment, $amount = 0.0, $invoice_id = 0)
    {
        $className = !empty($this->invokableClasses[$method])
            ? $this->invokableClasses[$method]
            : null;

        if ($className === null) {
            throw new LocalizedException(
                __('%1 request payment method is not supported.', $method)
            );
        }

        $model = $this->objectManager->create(
            $className,
            [
                'orderPayment'  => $orderPayment,
                'amount'        => $amount,
                'invoiceId'     => $invoice_id
            ]
        );
        
        if (!$model instanceof RequestInterface) {
            throw new LocalizedException(
                __(
                    "%1 doesn't implement \Nuvei\Checkout\Mode\RequestInterface",
                    $className
                )
            );
        }

        return $model;
    }
}
