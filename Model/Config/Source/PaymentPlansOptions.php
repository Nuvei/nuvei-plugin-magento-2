<?php

namespace Nuvei\Checkout\Model\Config\Source;

class PaymentPlansOptions extends \Magento\Eav\Model\Entity\Attribute\Source\AbstractSource
{
    private $directory;
    private $driverManager;
    private $readerWriter;
    
    public function __construct(
        \Magento\Framework\Filesystem\DirectoryList $directory,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        $this->directory    = $directory;
        $this->readerWriter = $readerWriter;
    }
    
    public function getAllOptions()
    {
        $this->readerWriter->createLog('getAllOptions()');
        
        if (!empty($this->_options)) {
            $this->readerWriter->createLog($this->_options, 'The _options are not empty');
            return $this->_options;
        }
        
        $this->_options[] = [
            'label' => __('No Plan'),
            'value' => 1 // need to be greater than 0

        ];
        
        # json version
        $file_name = $this->directory->getPath('log') . DIRECTORY_SEPARATOR
            . \Nuvei\Checkout\Model\Config::PAYMENT_PLANS_FILE_NAME;
        
        $cont = json_decode($this->readerWriter->readFile($file_name), true);
        
        if (is_array($cont) && !empty($cont['plans']) && is_array($cont['plans'])) {
            foreach ($cont['plans'] as $data) {
                $this->_options[] = [
                    'label' => $data['name'],
                    'value' => $data['planId']

                ];
            }
        } else {
            $this->readerWriter->createLog('PaymentPlansOption Error - '
                . 'problem when try to extract plans from ' . $file_name);
        }
        # json version END

        $this->readerWriter->createLog($this->_options, 'getAllOptions');
        return $this->_options;
    }
}
