<?php

namespace Nuvei\Checkout\Block\System\Config;

class GetPluginVersion implements \Magento\Config\Model\Config\CommentInterface
{
    private $config;
    
    public function __construct(\Nuvei\Checkout\Model\Config $config)
    {
        $this->config = $config;
    }

    public function getCommentText($elementValue)  //the method has to be named getCommentText
    {
        return $this->config->getModuleVersion();
    }
}
