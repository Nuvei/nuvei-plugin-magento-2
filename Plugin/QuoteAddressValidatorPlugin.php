<?php

namespace Nuvei\Checkout\Plugin;

use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\QuoteAddressValidator;

class QuoteAddressValidatorPlugin
{
    /**
     * @param QuoteAddressValidator $subject
     * @param CartInterface         $cart
     * @param AddressInterface      $address
     */
    public function beforeValidateForCart(
        QuoteAddressValidator $subject,
        CartInterface $cart,
        AddressInterface $address
    ): void {
        if ($cart->getCustomer()->getId()) {
            $cart->setCustomerIsGuest(0);
        }
    }
}
