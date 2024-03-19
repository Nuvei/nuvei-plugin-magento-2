<?php

namespace Nuvei\Checkout\Model\Request;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\RequestInterface;

/**
 * Nuvei Checkout request factory model.
 */
class Factory
{
    /**
     * Set of requests.
     *
     * @var array
     */
    private $invokableClasses = [
        AbstractRequest::GET_MERCHANT_PAYMENT_METHODS_METHOD
            => \Nuvei\Checkout\Model\Request\GetMerchantPaymentMethods::class,
        
        AbstractRequest::CREATE_SUBSCRIPTION_METHOD         => \Nuvei\Checkout\Model\Request\CreateSubscription::class,
        AbstractRequest::CANCEL_SUBSCRIPTION_METHOD         => \Nuvei\Checkout\Model\Request\CancelSubscription::class,
        AbstractRequest::GET_UPOS_METHOD                    => \Nuvei\Checkout\Model\Request\GetUserUPOs::class,
        AbstractRequest::OPEN_ORDER_METHOD                  => \Nuvei\Checkout\Model\Request\OpenOrder::class,
        AbstractRequest::UPDATE_ORDER_METHOD                => \Nuvei\Checkout\Model\Request\UpdateOrder::class,
        AbstractRequest::PAYMENT_APM_METHOD                 => \Nuvei\Checkout\Model\Request\PaymentApm::class,
        AbstractRequest::GET_MERCHANT_PAYMENT_PLANS_METHOD  => \Nuvei\Checkout\Model\Request\GetPlansList::class,
        AbstractRequest::CREATE_MERCHANT_PAYMENT_PLAN       => \Nuvei\Checkout\Model\Request\CreatePlan::class,
        AbstractRequest::GET_SESSION_TOKEN                  => \Nuvei\Checkout\Model\Request\GetSessionToken::class,
        AbstractRequest::DELETE_UPOS_METHOD                 => \Nuvei\Checkout\Model\Request\DeleteUPO::class,
        AbstractRequest::PAYMENT_VOID_METHOD                => \Nuvei\Checkout\Model\Request\Payment\Cancel::class,
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
    public function __construct(ObjectManagerInterface $objectManager)
    {
        $this->objectManager = $objectManager;
    }

    /**
     * Create request model.
     *
     * @param string $method - the name of the method
     * @param array  $args   - arguments to pass
     *
     * @return RequestInterface
     * @throws LocalizedException
     */
    public function create($method, $args = [])
    {
        $className = !empty($this->invokableClasses[$method])
            ? $this->invokableClasses[$method] : null;

        if ($className === null) {
            throw new LocalizedException(
                __('%1 request method is not supported.', $method)
            );
        }

        $model = $this->objectManager->create($className, $args);
        
        if (!$model instanceof RequestInterface) {
            throw new LocalizedException(
                __(
                    '%1 doesn\'t implement \Nuvei\Checkout\Model\RequestInterface',
                    $className
                )
            );
        }

        return $model;
    }
}
