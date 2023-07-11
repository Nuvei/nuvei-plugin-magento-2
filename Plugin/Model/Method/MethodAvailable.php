<?php

namespace Nuvei\Checkout\Plugin\Model\Method;

use Nuvei\Checkout\Model\Payment;

/**
 * When there is a product with Nuvei Payment plan, remove all other payment providers.
 */
class MethodAvailable
{
    private $paymentsPlans;
    private $readerWriter;
    
    public function __construct(
        \Nuvei\Checkout\Model\PaymentsPlans $paymentsPlans,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        $this->paymentsPlans    = $paymentsPlans;
        $this->readerWriter     = $readerWriter;
    }
    
    public function afterGetAvailableMethods(\Magento\Payment\Model\MethodList $subject, $result)
    {
        $this->readerWriter->createLog($result, 'MethodAvailable afterGetAvailableMethods');
        
        if (empty($this->paymentsPlans->getProductPlanData())) {
            return $result;
        }
        
        foreach ($result as $key => $_result) {
            if ($_result->getCode() != Payment::METHOD_CODE) {
                unset($result[$key]);
            }
        }
        
        return $result;
    }
}
