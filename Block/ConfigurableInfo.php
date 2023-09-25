<?php

namespace Nuvei\Checkout\Block;

use Nuvei\Checkout\Model\Payment;
use Magento\Framework\Phrase;
use Magento\Framework\View\Element\Template\Context;
use Magento\Payment\Gateway\ConfigInterface;

/**
 * Nuvei Checkout configurable info block.
 */
class ConfigurableInfo extends \Magento\Payment\Block\ConfigurableInfo
{
    /**
     * Object constructor.
     *
     * @param Context         $context
     * @param ConfigInterface $config
     * @param array           $data
     */
    public function __construct(
        Context $context,
        ConfigInterface $config,
        array $data = []
    ) {
        $data['methodCode'] = Payment::METHOD_CODE;

        parent::__construct(
            $context,
            $config,
            $data
        );
    }

    /**
     * Returns label.
     *
     * @param string $field
     *
     * @return string|Phrase
     */
    protected function getLabel($field)
    {
        $labels = [
            Payment::TRANSACTION_ID                 => __('Transaction Id'),
            Payment::TRANSACTION_AUTH_CODE          => __('Authorization Code'),
            Payment::TRANSACTION_ORDER_ID           => __('Nuvei Order Id'),
            Payment::TRANSACTION_REQUEST_ID         => __('Internal Request Id'),
            Payment::TRANSACTION_PAYMENT_SOLUTION   => __('Payment Solution'),
            Payment::TRANSACTION_PAYMENT_METHOD     => __('Payment Method'),
        ];

        $label = $field;
        if (isset($labels[$field])) {
            $label = $labels[$field];
        }

        return $label;
    }
}
