<?php

namespace Nuvei\Checkout\Plugin\Model\Method;

use Nuvei\Checkout\Model\Payment;

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
        $this->readerWriter->createLog('MethodAvailable afterGetAvailableMethods');
        
        if (!empty($this->paymentsPlans->getProductPlanData())) {
            foreach ($result as $key => $_result) {
                if ($_result->getCode() != Payment::METHOD_CODE) {
                    unset($result[$key]);
                }
            }
        }
        
        return $result;
    }
}
