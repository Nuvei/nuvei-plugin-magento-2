<?php

namespace Nuvei\Checkout\Model\Adminhtml\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Nuvei Checkout mode source model.
 */
class SaveUpo implements ArrayInterface
{
    /**
     * Options getter
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            'true'      => __('Yes'),
            'false'     => __('No'),
            'force'     => __('Force'),
            'always'    => __('Always '),
        ];
    }
}
