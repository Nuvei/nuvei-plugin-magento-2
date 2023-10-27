<?php

namespace Nuvei\Checkout\Plugin\Model\Method;

use Nuvei\Checkout\Model\Payment;

/**
 * When there is a product with Nuvei Payment plan, or is allowed to use Nuvei GW for ordinary Zero-Total orders,
 * remove all other payment providers.
 */
class MethodAvailable
{
    private $paymentsPlans;
    private $readerWriter;
    private $config;
    
    public function __construct(
        \Nuvei\Checkout\Model\Config $config,
        \Nuvei\Checkout\Model\PaymentsPlans $paymentsPlans,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        $this->paymentsPlans    = $paymentsPlans;
        $this->readerWriter     = $readerWriter;
        $this->config           = $config;
    }
    
    public function afterGetAvailableMethods(\Magento\Payment\Model\MethodList $subject, $result)
    {
        $allow_zero_total   = $this->config->getConfigValue('allow_zero_total');
        $total              = $this->config->getQuoteBaseTotal();
        
        $this->readerWriter->createLog(
            [json_encode($result), $allow_zero_total, $total], 
            'MethodAvailable afterGetAvailableMethods'
        );
        
        // remove all GWs except Nuvei
        if (!empty($this->paymentsPlans->getProductPlanData())
            || ($allow_zero_total && 0 == $total)
        ) {
            foreach ($result as $key => $_result) {
                if ($_result->getCode() != Payment::METHOD_CODE) {
                    unset($result[$key]);
                }
            }
        }
        
        return $result;
    }
}
