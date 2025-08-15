<?php

namespace Nuvei\Checkout\Block\System\Config;

class GoogleMerchantIdComment implements \Magento\Config\Model\Config\CommentInterface
{
    private $config;
    
    public function __construct(\Nuvei\Checkout\Model\Config $config)
    {
        $this->config = $config;
    }

    public function getCommentText($elementValue)  //the method has to be named getCommentText
    {
        return __('For tests use BCR2DN6TZ6DP7P3X. For more information, please check the <a href="'
            . 'https://docs.nuvei.com/documentation/global-guides/google-pay/google-pay-integration/google-pay-guide-checkout-sdk/#2-collect-the-card-details'
            . 'ui-customization/#text-and-translation" target="_blank">Documentation</a>.'
        );
    }
}
