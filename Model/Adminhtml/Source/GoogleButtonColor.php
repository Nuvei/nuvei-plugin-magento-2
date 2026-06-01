<?php

namespace Nuvei\Checkout\Model\Adminhtml\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Nuvei Checkout Google Button color source model.
 */
class GoogleButtonColor implements ArrayInterface
{
    /**
     * The possible options.
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            'black' => __('Black'),
            'white' => __('White'),
        ];
    }
}
