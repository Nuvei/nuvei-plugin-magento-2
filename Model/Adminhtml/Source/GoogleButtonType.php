<?php

namespace Nuvei\Checkout\Model\Adminhtml\Source;

use Magento\Framework\Option\ArrayInterface;

/**
 * Nuvei Checkout Google Button types source model.
 */
class GoogleButtonType implements ArrayInterface
{
    /**
     * The possible options.
     *
     * @return array
     */
    public function toOptionArray()
    {
        return [
            'buy'       => __( 'Buy' ),
            'book'      => __( 'Book' ),
            'checkout'  => __( 'Checkout' ),
            'order'     => __( 'Order' ),
            'pay'       => __( 'Pay' ),
            'plain'     => __( 'Plain' ),
//            'subscribe' => __( 'Subscribe' ),
        ];
    }
}
