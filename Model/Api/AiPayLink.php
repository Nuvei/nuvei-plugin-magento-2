<?php

namespace Nuvei\Checkout\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Nuvei\Checkout\Api\AiPayLinkInterface;
use Nuvei\Checkout\Model\AbstractRequest;

/**
 * @author Nuvei
 */
class AiPayLink implements AiPayLinkInterface
{
    private $readerWriter;
    private $requestFactory;
    private $moduleConfig;
    private $apiRequest;
    
    public function __construct(
        \Nuvei\Checkout\Model\Config $moduleConfig,
        \Magento\Framework\Webapi\Rest\Request $apiRequest,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        \Nuvei\Checkout\Model\Request\Factory $requestFactory
    ) {
        $this->readerWriter         = $readerWriter;
        $this->moduleConfig         = $moduleConfig;
        $this->requestFactory       = $requestFactory;
        $this->apiRequest           = $apiRequest;
    }
    
    /**
     * Example of expected JSON structure:
     * {
     *   "amount": 10.00,
     *   "quantity": 1,
     *   "currency": "EUR",
     *   "description": "Nike Air Zoom",
     *   "sku": "nike-air-zoom-42",
     *   "customer_email": "john@example.com",
     *   "success_url": "https://mystore.com/thank-you",
     *   "failure_url": "https://mystore.com/error"
     * }
     */
    public function generate()
    {
        // check for errors
        if (!$this->moduleConfig->getConfigValue('active')) {
            $msg = 'Mudule is not active.';
            $this->readerWriter->createLog($msg);
            
            throw new LocalizedException(__($msg));
        }
        
        $params = $this->apiRequest->getBodyParams();
        
        // validate the parameters
        $this->validateInputData($params);
        
        $request    = $this->requestFactory->create(AbstractRequest::GET_PAYMENT_LINK);
        $response   = $request->setParams($params)->process();
        
        // success
        if (!empty($response['status']) && 'success' == strtolower($response['status'])) {
           return [
               "status"        => "success",
               "paylink_url"   => $response['paymentPageUrl'],
           ];
        }
        
        // TODO - implement 4xx response code according to the error
        return $response;
    }
    
    /**
     * @param array $params
     * @return void
     * @throws LocalizedException
     */
    private function validateInputData($params)
    {
        if (empty($params['amount']) || !is_numeric($params['amount'])) {
            throw new LocalizedException(__('Invalid or missing amount.'));
        }
        if (empty($params['quantity']) || !is_int($params['quantity'])) {
            throw new LocalizedException(__('Invalid or missing quantity.'));
        }
        if (empty($params['currency']) 
            || !is_string($params['currency'])
            || strlen($params['currency']) != 3
        ) {
            throw new LocalizedException(__('Invalid or missing currency.'));
        }
        if (empty($params['description'])) {
            throw new LocalizedException(__('Invalid or missing description.'));
        }
        if (empty($params['sku'])) {
            throw new LocalizedException(__('Invalid or missing sku.'));
        }
        if (empty($params['customer_email']) || !filter_var($params['customer_email'], FILTER_VALIDATE_EMAIL)) {
            throw new LocalizedException(__('Invalid or missing customer_email.'));
        }
        if (empty($params['success_url']) || !filter_var($params['success_url'], FILTER_VALIDATE_URL)) {
            throw new LocalizedException(__('Invalid or missing success_url.'));
        }
        if (empty($params['failure_url']) || !filter_var($params['failure_url'], FILTER_VALIDATE_URL)) {
            throw new LocalizedException(__('Invalid or missing failure_url.'));
        }
        
        return;
    }
    
}
