<?php

namespace Nuvei\Checkout\Controller\Adminhtml\System\Config;

use Nuvei\Checkout\Model\AbstractRequest;
use Magento\Framework\Exception\PaymentException;

class GetPlans extends \Magento\Backend\App\Action
{
    protected $jsonResultFactory;
    protected $moduleConfig;
    protected $requestFactory;
    protected $objManager;
    
    private $readerWriter;
    
    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory,
        \Nuvei\Checkout\Model\Config $moduleConfig,
        \Nuvei\Checkout\Model\Request\Factory $requestFactory,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        parent::__construct($context);
        
        $this->jsonResultFactory    = $jsonResultFactory;
        $this->moduleConfig         = $moduleConfig;
        $this->requestFactory       = $requestFactory;
        $this->readerWriter         = $readerWriter;
    }
    
    public function execute()
    {
        $result = $this->jsonResultFactory->create()
            ->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);

        if (!$this->moduleConfig->getConfigValue('active')) {
            $this->readerWriter->createLog('Nuvei payments module is not active at the moment!');
           
            return $result->setData(
                [
                'error_message' => __('Nuvei payments module is not active at the moment!')
                ]
            );
        }
        
        $request = $this->requestFactory->create(AbstractRequest::GET_MERCHANT_PAYMENT_PLANS_METHOD);

        try {
            $resp = $request->process();
        } catch (PaymentException $e) {
            $this->readerWriter->createLog($e->getMessage(), 'GetPlans Exception:');
            
            return $result->setData(
                [
                "success"  => 0,
                "message"  => "Error"
                ]
            );
        }
        
        return $result->setData(
            [
            "success" => $resp ? 1 : 0,
            "message" => "Success"
            ]
        );
    }
}
