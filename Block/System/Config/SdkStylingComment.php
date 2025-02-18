<?php

namespace Nuvei\Checkout\Block\System\Config;

class SdkStylingComment implements \Magento\Config\Model\Config\CommentInterface
{
    private $config;
    
    public function __construct(\Nuvei\Checkout\Model\Config $config)
    {
        $this->config = $config;
    }

    public function getCommentText($elementValue)  //the method has to be named getCommentText
    {
        return __('Set your style in JSON format like in the example:') . '<br/>'
            . '<code>{ "base": { "iconColor": "#c4f0ff" }, "invalid": { "iconColor": "#FFC7EE" } }</code></br>'
            . __(
                'For more information, please check the <a href="'
                . 'https://docs.nuvei.com/documentation/accept-payment/web-sdk/nuvei-fields/nuvei-fields-styling/#example-javascript'
                . 'ui-customization/#text-and-translation" target="_blank">Documentation</a>.'
            );
    }
}
