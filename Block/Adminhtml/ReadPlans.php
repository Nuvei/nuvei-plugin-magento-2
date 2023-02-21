<?php

namespace Nuvei\Checkout\Block\Adminhtml;

class ReadPlans extends \Magento\Backend\Block\Template
{
    protected $_template = 'Nuvei_Checkout::readPlans.phtml';
    
    private $config;
    private $readerWriter;

    public function __construct(
        \Magento\Backend\Block\Template\Context $context,
        \Nuvei\Checkout\Model\Config $config,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        array $data = []
    ) {
        parent::__construct($context, $data);
        
        $this->config       = $config;
        $this->readerWriter = $readerWriter;
    }
    
    /**
     *
     * @return string
     */
    public function getPaymentPlans()
    {
        $file_name = $this->config->getTempPath() . DIRECTORY_SEPARATOR
            . \Nuvei\Checkout\Model\Config::PAYMENT_PLANS_FILE_NAME;
        
        if (!$this->readerWriter->isReadable($file_name)) {
            return '';
        }
        
        $file_cont  = json_decode($this->readerWriter->readFile($file_name), true);
        $plans      = [];
        
        if (empty($file_cont['plans']) || !is_array($file_cont['plans'])) {
            return '';
        }
        
        foreach ($file_cont['plans'] as $data) {
            $plans[$data['planId']] = $data;
        }
        
        $this->readerWriter->createLog($plans);
        
        return json_encode($plans);
    }
}
