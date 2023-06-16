<?php

namespace Nuvei\Checkout\Model\Adminhtml\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Nuvei Checkout payment action source model.
 */
class SdkTheme implements ArrayInterface
{
    /**
     * Possible actions on order place.
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            'accordion'     => __('Accordion'),
            'tiles'         => __('Tiles'),
            'horizontal'    => __('Horizontal'),
        ];
    }
}
