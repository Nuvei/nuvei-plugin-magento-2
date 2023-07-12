<?php

/**
 * @author Nuvei
 * 
 * Create Settle before create Invoice.
 */

namespace Nuvei\Checkout\Plugin;

use Nuvei\Checkout\Model\Payment;

class BeforeCreateInvoice
{
    private $objectManager;
    private $readerWriter;
//    private $request;
    private $params;
    
    public function __construct(
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        \Magento\Framework\App\RequestInterface $request
    ) {
        $this->objectManager    = $objectManager;
        $this->readerWriter     = $readerWriter;
        $this->params           = $request->getParams();
    }
    
    public function beforeToInvoice(
        \Magento\Sales\Model\Convert\Order $subject,
        \Magento\Sales\Model\Order $order
    ) {
        $this->readerWriter->createLog($this->params, 'beforeToInvoice');
        
        // the second condition is when the merchant click on "UpdateQty's" button
        if (empty($this->params['invoice']['items']) || isset($this->params['isAjax'])) {
            $this->readerWriter->createLog('There are not any Invoice Items.');
            return;
        }
        
        $this->readerWriter->createLog(
            [
                'getBaseGrandTotal' => $order->getBaseGrandTotal(),
                'getBaseTotalInvoiced' => $order->getBaseTotalInvoiced(),
                'getBaseDiscountAmount' => $order->getBaseDiscountAmount(),
                'getBaseShippingInclTax' => $order->getBaseShippingInclTax(),
                'getBaseShippingAmount' => $order->getBaseShippingAmount(),
                'getBaseShippingInvoiced' => $order->getBaseShippingInvoiced(),
                'getBaseShippingDiscountAmount' => $order->getBaseShippingDiscountAmount(),
                'getBaseTaxAmount' => $order->getBaseTaxAmount(),
            ],
            'Order amounts',
            'DEBUG'
        );
        
        $order_shipping_inc_tax     = round($order->getBaseShippingInclTax(), 2);
        $order_shipping_invoiced    = round((float) $order->getBaseShippingInvoiced(), 2);
        $order_shipping_disc        = round($order->getBaseShippingDiscountAmount(), 2);
        
        $inv_amount                 = $order_shipping_inc_tax - $order_shipping_invoiced - $order_shipping_disc;
        $items_amounts              = []; // for debug only
        
        foreach ($order->getAllItems() as $item) {
            if (!array_key_exists($item->getId(), $this->params['invoice']['items'])
                || 0 == $this->params['invoice']['items'][$item->getId()]
            ) {
                continue;
            }
            
            $items_cnt          = $this->params['invoice']['items'][$item->getId()];
            $items_ordered      = round($item->getQtyOrdered(), 2);
            $items_disc         = round($item->getBaseDiscountAmount(), 2);
            $item_disc          = round($items_disc / $items_ordered, 2);
            $item_price_no_tax  = round($item->getBasePrice(), 2);
            $items_tax          = round($item->getBaseTaxAmount(), 2);
            $item_tax           = round($items_tax / $items_ordered, 2);
            
            // debug log
            $items_amounts[$item->getId()] = [
                'getBasePrice'          => $item->getBasePrice(),
                'getBasePriceInclTax'   => $item->getBasePriceInclTax(),
                'getBaseDiscountAmount' => $item->getBaseDiscountAmount(),
                'getBaseDiscountInvoiced' => $item->getBaseDiscountInvoiced(),
                'getBaseTaxAmount' => $item->getBaseTaxAmount(),
                'getBaseTaxInvoiced' => $item->getBaseTaxInvoiced(),
                'getQtyOrdered' => $item->getQtyOrdered(),
            ];

            $inv_amount += ($item_price_no_tax * $items_cnt)
                - ($item_disc * $items_cnt)
                + ($item_tax * $items_cnt);
        }
        
        $this->readerWriter->createLog(
            [
                '$inv_amount'       => $inv_amount,
                '$items_amounts'    => $items_amounts,
            ],
            'amounts',
            "DEBUG"
        );
            
        if (0 == $inv_amount) {
            $this->readerWriter->createLog(
                'Calculated Invoice amoutn is Zero.'
            );
            
            throw new \Magento\Framework\Exception\LocalizedException(__('Calculated Invoice amoutn is Zero.'));
        }
        
        /** @var OrderPayment $payment */
        $payment = $order->getPayment();

        if (!is_object($payment)) {
            $this->readerWriter->createLog('beforeToInvoice - $payment is not an object.');
            throw new \Magento\Framework\Exception\LocalizedException(__('The Payment is not an object.'));
        }

        if ($payment->getMethod() !== Payment::METHOD_CODE) {
            $this->readerWriter->createLog($payment->getMethod(), 'The Payment does not belong to Nuvei, but to');
            return;
        }

        // Settle request
        $authCode               = '';
        $ord_trans_addit_info   = $payment->getAdditionalInformation(Payment::ORDER_TRANSACTIONS_DATA);
        
        // probably a Sale
        if (!is_array($ord_trans_addit_info)
            || empty($ord_trans_addit_info)
        ) {
            $msg = __('There is no Order Transaction data.');
            
            $this->readerWriter->createLog($msg);
            throw new \Magento\Framework\Exception\LocalizedException($msg);
        }
        
        foreach ($ord_trans_addit_info as $trans) {
            if (strtolower($trans[Payment::TRANSACTION_STATUS]) == 'approved'
                && strtolower($trans[Payment::TRANSACTION_TYPE]) == 'auth'
            ) {
                $authCode = $trans[Payment::TRANSACTION_AUTH_CODE];
                break;
            }
        }
        
        if (empty($authCode)) {
            $this->readerWriter->createLog($ord_trans_addit_info, 'BeforeCreateInvoice - $authCode is empty.');
            throw new \Magento\Framework\Exception\LocalizedException(
                __('The Order Auth transaction missing the Auth code parameter.'));
        }
        
        $request = $this->objectManager->create(\Nuvei\Checkout\Model\Request\SettleTransaction::class);

        $resp = $request
            ->setPayment($payment)
//            ->setInvoiceId($invoice->getId())
            ->setInvoiceAmount($inv_amount)
            ->process();
        
        if(empty($resp['transactionStatus']) || 'APPROVED' != $resp['transactionStatus']) {
            $msg = __('Settle request error, you can check Nuvei log for more information.');
            
            $this->readerWriter->createLog($msg);
            throw new \Magento\Framework\Exception\LocalizedException($msg);
        }
        
        
        return;
    }
}
