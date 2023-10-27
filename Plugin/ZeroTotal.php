<?php

/**
 * Override original logic in Magento 2 and allow Orders with Total Amount of 0.
 */

namespace Nuvei\Checkout\Plugin;

class ZeroTotal
{
    public function afterIsApplicable($subject, $result)
    {
        return true;
    }
}
