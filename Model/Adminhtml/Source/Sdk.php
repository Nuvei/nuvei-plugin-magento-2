<?php

namespace Nuvei\Checkout\Model\Adminhtml\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Nuvei Checkout mode source model.
 */
class Sdk implements ArrayInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            'checkout'  => __('SimplyConnect'),
            'web'       => __('Web SDK'),
        ];
    }
}
