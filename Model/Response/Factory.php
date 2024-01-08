<?php

namespace Nuvei\Checkout\Model\Response;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\ObjectManagerInterface;
use Magento\Sales\Model\Order\Payment as OrderPayment;
use Nuvei\Checkout\Lib\Http\Client\Curl;
use Nuvei\Checkout\Model\AbstractResponse;
use Nuvei\Checkout\Model\ResponseInterface;

/**
 * Nuvei Checkout response factory model.
 */
class Factory
{
    /**
     * Set of requests.
     *
     * @var array
     */
    private $invokableClasses = [
        AbstractResponse::GET_MERCHANT_PAYMENT_METHODS_HANDLER
            => \Nuvei\Checkout\Model\Response\GetMerchantPaymentMethods::class,
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
     * Create response model.
     *
     * @param string            $type
     * @param int               $requestId
     * @param Curl|null         $curl
     * @param OrderPayment|null $payment
     *
     * @return ResponseInterface
     * @throws LocalizedException
     */
    public function create($type, $requestId, $curl = null, $payment = null)
    {
        $className = !empty($this->invokableClasses[$type])
            ? $this->invokableClasses[$type] : null;

        if ($className === null) {
            throw new LocalizedException(__('%1 type is not supported.', $type));
        }

        $model = $this->objectManager->create(
            $className,
            [
                'requestId' => $requestId,
                'curl' => $curl,
                'orderPayment' => $payment,
            ]
        );
        if (!$model instanceof ResponseInterface) {
            throw new LocalizedException(
                __(
                    '%1 doesn\'t implement \Nuvei\Checkout\Mode\ResponseInterface',
                    $className
                )
            );
        }

        return $model;
    }
}
