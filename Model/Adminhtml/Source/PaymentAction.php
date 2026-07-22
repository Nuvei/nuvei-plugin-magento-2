<?php

namespace Nuvei\Checkout\Model\Adminhtml\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Nuvei Checkout payment action source model.
 */
class PaymentAction implements ArrayInterface
{
    /**
     * Possible actions on order place.
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            ''              => __('Please, select an option...'),
            'Sale'          => __('Authorize and Capture'),
            'Auth'          => __('Authorize'),
        //            'autoSettle'    => __('Authorize and Auto Settle'),
        ];
    }
}
