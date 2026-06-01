<?php
namespace Nuvei\Checkout\Api;

interface AiPayLinkInterface
{
    /**
     * @return string
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function generate();
}
