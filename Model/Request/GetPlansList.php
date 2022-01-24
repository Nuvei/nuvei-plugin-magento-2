<?php

namespace Nuvei\Checkout\Model\Request;

use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\RequestInterface;

class GetPlansList extends AbstractRequest implements RequestInterface
{
    protected $requestFactory;
    protected $config;
    
    private $fileSystem;
    
    public function __construct(
        \Nuvei\Checkout\Model\Logger $logger,
        \Nuvei\Checkout\Model\Config $config,
        \Nuvei\Checkout\Lib\Http\Client\Curl $curl,
        \Nuvei\Checkout\Model\Response\Factory $responseFactory,
        \Nuvei\Checkout\Model\Request\Factory $requestFactory,
        \Magento\Framework\Filesystem\DriverInterface $fileSystem
    ) {
        parent::__construct(
            $logger,
            $config,
            $curl,
            $responseFactory
        );

        $this->requestFactory   = $requestFactory;
        $this->fileSystem       = $fileSystem;
    }
    
    public function process()
    {
        $plans = $this->sendRequest(true);
        
        $this->config->createLog($plans, 'Get Plans response');
        
        // there are no active plans, we must create at least one active
        if (!isset($plans['plans']) || !isset($plans['total']) || 0 == $plans['total']) {
            $create_plan_request = $this->requestFactory
                ->create(AbstractRequest::CREATE_MERCHANT_PAYMENT_PLAN);
            
            $resp = $create_plan_request->process();
            
            // on success try to get the new plan
            if (!empty($resp['planId'])) {
                $plans = $this->sendRequest(true);
            }
        }
        
        return $this->savePlansFile($plans);
    }
    
    protected function getRequestMethod()
    {
        return self::GET_MERCHANT_PAYMENT_PLANS_METHOD;
    }
    
    protected function getResponseHandlerType()
    {
        return '';
    }
    
    protected function getParams()
    {
        $params = array_merge_recursive(
            [
                'planStatus'    => 'ACTIVE',
                'currency'      => '',
            ],
            parent::getParams()
        );
        
        return $params;
    }
    
    protected function getChecksumKeys()
    {
        return [
            'merchantId',
            'merchantSiteId',
            'currency',
            'planStatus',
            'timeStamp',
        ];
    }
    
    private function savePlansFile($plans)
    {
        try {
            $tempPath = $this->config->getTempPath();

            if (empty($plans['status']) || $plans['status'] != 'SUCCESS'
                || empty($plans['total']) || (int) $plans['total'] < 1
            ) {
                $this->config->createLog('GetPlansList error - status error or missing plans. '
                    . 'Check the response above!');
                return false;
            }

            $this->fileSystem->filePutContents(
                $tempPath. DIRECTORY_SEPARATOR . \Nuvei\Checkout\Model\Config::PAYMENT_PLANS_FILE_NAME,
                json_encode($plans)
            );
        } catch (Exception $e) {
            $this->config->createLog($e->getMessage(), 'GetPlansList Exception');
            
            return false;
        }
        
        return true;
    }
}
