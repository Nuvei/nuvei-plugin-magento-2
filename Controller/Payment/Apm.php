<?php

namespace Nuvei\Checkout\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Exception\PaymentException;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\Config as ModuleConfig;
use Nuvei\Checkout\Model\Request\Factory as RequestFactory;

/**
 * Nuvei Payments paymentApm controller.
 * Combine APMs and UPO APMs payments.
 */
class Apm extends Action
{
    /**
     * @var ModuleConfig
     */
    private $moduleConfig;

    /**
     * @var JsonFactory
     */
    private $jsonResultFactory;

    /**
     * @var RequestFactory
     */
    private $requestFactory;
    
    private $readerWriter;

    /**
     * Redirect constructor.
     *
     * @param Context        $context
     * @param ModuleConfig   $moduleConfig
     * @param JsonFactory    $jsonResultFactory
     * @param RequestFactory $requestFactory
     */
    public function __construct(
        Context $context,
        ModuleConfig $moduleConfig,
        JsonFactory $jsonResultFactory,
        RequestFactory $requestFactory,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        parent::__construct($context);

        $this->moduleConfig         = $moduleConfig;
        $this->jsonResultFactory    = $jsonResultFactory;
        $this->requestFactory       = $requestFactory;
        $this->readerWriter         = $readerWriter;
    }

    /**
     * @return ResponseInterface
     */
    public function execute()
    {
        $result = $this->jsonResultFactory->create()
            ->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);

        if (!$this->moduleConfig->getConfigValue('active')) {
            return $result->setData(
                [
                'error_message' => __('Nuvei payments module is not active at the moment!')
                ]
            );
        }

        $params = array_merge(
            $this->getRequest()->getParams(),
            $this->getRequest()->getPostValue()
        );

        $this->readerWriter->createLog($params, 'Apm Controller incoming params:');
        
        $savePm = 0;
        
        if (!empty($params["save_payment_method"]) && 'false' != $params["save_payment_method"]) {
            $savePm = 1;
        }

        try {
            $request = $this->requestFactory->create(AbstractRequest::PAYMENT_APM_METHOD);
            
            $response = $request
                ->setPaymentMethod(empty($params["chosen_apm_method"]) ? '' : $params["chosen_apm_method"])
                ->setPaymentMethodFields(empty($params["apm_method_fields"]) ? '' : $params["apm_method_fields"])
                ->setSavePaymentMethod($savePm)
                ->process();
        } catch (PaymentException $e) {
            $this->readerWriter->createLog(
                [$e->getMessage(), $e->getTraceAsString()],
                'Apm Controller - Exception:'
            );
            
            return $result->setData(
                [
                "error"        => 1,
                "redirectUrl"    => null,
                "message"        => $e->getMessage()
                ]
            );
        }
        
        $response['error'] = 0;
        
        // on error
        if ('error' == strtolower($response['status'])
            || empty($response['redirectUrl'])
        ) {
            $response['error'] = 1;
            //            
            //            return $result->setData([
            //                "error"         => 1,
            //                "message"       => $response['reason'] ?? __('Unexpected payment error'),
            //            ]);
        }
        
        //        return $result->setData([
        //            "error"         => 0,
        //            "redirectUrl"   => $response['redirectUrl'],
        //            "message"       => $response['status'],
        //        ]);
        
        return $result->setData($response);
    }
}
