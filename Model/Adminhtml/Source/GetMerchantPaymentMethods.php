<?php

namespace Nuvei\Checkout\Model\Adminhtml\Source;

use Magento\Framework\Option\ArrayInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\Config as ModuleConfig;
use Nuvei\Checkout\Model\Request\Factory as RequestFactory;

/**
 * Nuvei Checkout GetMerchantPaymentMethods controller.
 */
class GetMerchantPaymentMethods extends Action implements ArrayInterface
{
    private $moduleConfig;
    private $requestFactory;
    private $readerWriter;

    /**
     * Redirect constructor.
     *
     * @param Context           $context
     * @param ModuleConfig      $moduleConfig
     * @param RequestFactory    $requestFactory
     * @param ReaderWriter      $readerWriter
     */
    public function __construct(
        Context $context,
        ModuleConfig $moduleConfig,
        RequestFactory $requestFactory,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        parent::__construct($context);

        $this->moduleConfig     = $moduleConfig;
        $this->requestFactory   = $requestFactory;
        $this->readerWriter     = $readerWriter;
        
        $this->readerWriter->createLog('GetMerchantPaymentMethods() __construct');
    }

    /**
     * @return ResponseInterface
     */
    public function execute()
    {
        if (!$this->moduleConfig->getConfigValue('active')) {
            $this->readerWriter->createLog('GetMerchantPaymentMethods error - '
                . 'Nuvei checkout module is not active at the moment!');
            
            return [];
        }
        
        if (empty($this->moduleConfig->getMerchantId())
            || empty($this->moduleConfig->getMerchantSiteId())
            || empty($this->moduleConfig->getMerchantSecretKey())
        ) {
            $this->readerWriter->createLog('Missing mandatory merchant data.');
            
            return [];
        }
        
        $pms_array      = [];
        $pms_array[]    = [
            'value' => '',
            'label' => __('None')
        ];
        
        foreach ($this->getApmMethods() as $data) {
            if (empty($data['paymentMethod'])) {
                continue;
            }
            
            $title = $data['paymentMethod'];
            
            if (!empty($data['paymentMethodDisplayName'][0]['message'])) {
                $title = $data['paymentMethodDisplayName'][0]['message'];
            }
            
            $pms_array[] = [
                'value' => $data['paymentMethod'],
                'label' => $title,
            ];
        }
        
        return $pms_array;
    }

    /**
     * Return AMP Methods.
     * We pass both parameters from JS via Ajax request
     *
     * @return array
     */
    private function getApmMethods()
    {
        try {
            $request    = $this->requestFactory->create(AbstractRequest::GET_MERCHANT_PAYMENT_METHODS_METHOD);
            $apmMethods = $request
                ->setBillingAddress($this->getRequest()->getParam('billingAddress'))
                ->process(true);

//            if (!is_object($apmMethods)) {
            if (empty($apmMethods['paymentMethods'])) {
                return [];
            }
            
//            return $apmMethods->getPaymentMethods();
            return $apmMethods['paymentMethods'];
        } catch (\Exception $e) {
            $this->readerWriter->createLog($e->getMessage(), 'Get APMs exception');
            return [];
        }
    }

    public function toOptionArray(): array
    {
        $pms = $this->execute();
//        $this->readerWriter->createLog($pms);
        return $pms;
    }
}
