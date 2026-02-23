<?php

namespace Nuvei\Checkout\ViewModel;

use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Nuvei\Checkout\Helper\CheckoutHelper;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\Config;
use Nuvei\Checkout\Model\Payment;
use Nuvei\Checkout\Model\ReaderWriter;
use Nuvei\Checkout\Model\Request\Factory as RequestFactory;

/**
 * The view model for the Hyva Checkout template.
 *
 * @author Nuvei
 */
class HyvaCheckoutViewModel implements ArgumentInterface
{
    private $helper;
    private $json;
    private $requestFactory;
    private $config;
    private $logger;
    
    public function __construct(
        CheckoutHelper $helper,
        Json $json,
        RequestFactory $requestFactory,
        Config $config,
        ReaderWriter $logger
    ) {
        $this->helper           = $helper;
        $this->json             = $json;
        $this->requestFactory   = $requestFactory;
        $this->config           = $config;
        $this->logger           = $logger;
    }

    /**
     * This method will be called from our template
     */
    public function getFormattedData()
    {
        // 1. get basic checkout params
        $checkoutParams = $this->helper->getCheckoutSdkConfig();
        
        // error - nuveiCheckoutParams are missing
        if (!isset($checkoutParams['payment'][Payment::METHOD_CODE]['nuveiCheckoutParams'])) {
            $this->logger->createLog($checkoutParams, 'Missing nuveiCheckoutParams.', 'WARN');
            
            return [
                'message' => __('Unexpected error.'),
            ];
        }
        
        // 2. we need and session token
        $request    = $this->requestFactory->create(AbstractRequest::OPEN_ORDER_METHOD);
        $ooResp     = $request
            ->setIsUserLogged($this->config->isUserLogged())
            ->setEntityId($this->config->getQuoteId()) // this ID is actualy the Entity ID
            ->setCallerSdk('simplyConnect')
            ->process();
        
        // some error
        if (empty($ooResp->sessionToken)) {
            if (isset($ooResp->error, $ooResp->reason) && 1 == $ooResp->error) {
                return [
                    'message' => $ooResp->reason,
                ];
            }
            
            return [
                'message' => __('Unexpected error.'),
            ];
        }
        
        // add the sessionToken
        $checkoutParams['payment'][Payment::METHOD_CODE]['nuveiCheckoutParams']['sessionToken'] = $ooResp->sessionToken;
        
        // return the clean data
        return $checkoutParams['payment'][Payment::METHOD_CODE];
    }
    
    public function getJsonConfig()
    {
        $resp = $this->json->serialize($this->getFormattedData());
        
        $this->logger->createLog($resp, '$checkoutParams json');
        
        return $resp;
    }
    
}
