<?php

namespace Nuvei\Checkout\Model\Adminhtml\Source;

class ApmsWindowType extends \Magento\Eav\Model\Entity\Attribute\Source\AbstractSource
{
    public function getAllOptions()
    {
        $this->_options = [
            [
                'label' => __('Popup'),
                'value' => ''
            ],
            [
                'label' => __('New tab'),
                'value' => 'newTab'
            ],
            [
                'label' => __('Redirect'),
                'value' => 'redirect'
            ],
         ];
        
        return $this->_options;
    }
}
