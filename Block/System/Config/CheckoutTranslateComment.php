<?php

namespace Nuvei\Checkout\Block\System\Config;

class CheckoutTranslateComment implements \Magento\Config\Model\Config\CommentInterface
{
    private $config;
    
    public function __construct(\Nuvei\Checkout\Model\Config $config)
    {
        $this->config = $config;
    }

    public function getCommentText($elementValue)  //the method has to be named getCommentText
    {
        return __('Set your translations like in the example:') . '<br/>'
            . '<code>{</br>'
                . '"doNotHonor":"you dont have enough money",</br>'
                . '"DECLINE":"declined"'
            . '</br>}</code>'
            . '</br>'
            . __(
                'For more information, please check the <a href="'
                . 'https://docs.nuvei.com/documentation/accept-payment/simply-connect/'
                . 'ui-customization/#text-and-translation" target="_blank">Documentation</a>.'
            );
    }
}
