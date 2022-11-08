<?php

namespace Nuvei\Checkout\Plugin\Block\Widget\Button;

use Nuvei\Checkout\Model\Payment;

class Toolbar
{
    private $orderRepository;
    private $request;
    private $readerWriter;
    
    public function __construct(
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Framework\App\RequestInterface $request,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        $this->orderRepository  = $orderRepository;
        $this->request          = $request;
        $this->readerWriter     = $readerWriter;
    }
    
    /**
     * @param ToolbarContext $toolbar
     * @param AbstractBlock $context
     * @param ButtonList $buttonList
     * @param Config $config
     *
     * @return array
     */
    public function beforePushButtons(
        \Magento\Backend\Block\Widget\Button\Toolbar $toolbar,
        \Magento\Framework\View\Element\AbstractBlock $context,
        \Magento\Backend\Block\Widget\Button\ButtonList $buttonList
    ) {
        if (!$context instanceof \Magento\Sales\Block\Adminhtml\Order\View) {
            return [$context, $buttonList];
        }
        
        try {
            $orderId                = $this->request->getParam('order_id');
            $order                  = $this->orderRepository->get($orderId);
            $ord_status             = $order->getStatus();
            $order_total            = round((float) $order->getBaseGrandTotal(), 2);
            $orderPayment           = $order->getPayment();
            $ord_trans_addit_info   = $orderPayment->getAdditionalInformation(Payment::ORDER_TRANSACTIONS_DATA);
            $payment_method         = '';
            
            if ($orderPayment->getMethod() !== Payment::METHOD_CODE) {
                return [$context, $buttonList];
            }
            
            if (!empty($ord_trans_addit_info) && is_array($ord_trans_addit_info)) {
                foreach ($ord_trans_addit_info as $trans) {
                    if (!empty($trans[Payment::TRANSACTION_PAYMENT_METHOD])) {
                        $payment_method = $trans[Payment::TRANSACTION_PAYMENT_METHOD];
                        break;
                    }
                }
            }
            
//            $payment_method        = $orderPayment->getAdditionalInformation(
//                Payment::TRANSACTION_PAYMENT_METHOD
//            );
            
            // Examples
            //        $buttonList->update('order_edit', 'class', 'edit');
            //
            //        $buttonList->add('order_review',
            //            [
            //                'label' => __('Review'),
            //                'onclick' => 'setLocation(\'' . $context->getUrl('sales/*/review') . '\')',
            //                'class' => 'review'
            //            ]
            //        );
            
            $this->readerWriter->createLog($buttonList->getItems(), 'buttonList');
            
            // the plugin does not support reorder from the admin
            $buttonList->remove('order_reorder');
            
            if (!in_array($payment_method, Payment::PAYMETNS_SUPPORT_REFUND)
                || in_array($ord_status, [Payment::SC_VOIDED, Payment::SC_PROCESSING])
            ) {
                $buttonList->remove('order_creditmemo');
                $buttonList->remove('credit-memo');
            }
            
            /**
             * We want to hide Invoice button when:
             * 1. The order was voided.
             * 2. The order is with total 0. Usually it will be with status Auth,
             * but the merchant can manually change the status, so we will check
             * for the total only.
             */
            if (Payment::SC_VOIDED == $ord_status || 0 == $order_total) {
                $buttonList->remove('order_invoice');
            }
            
            if ('cc_card' !== $payment_method
                || in_array($ord_status, [Payment::SC_REFUNDED, Payment::SC_PROCESSING, Payment::SC_VOIDED, 'closed'])
            ) {
                $this->readerWriter->createLog('Toolbar remove void_paiment button');
                
                $buttonList->remove('void_payment');
            }
//            elseif (!isset($buttonList->getItems()[0]['void_payment'])) {
//                // workaround in case of missing Void button on Sale transaction
//                $message = __('Are you sure you want to void the payment?');
//                $url = $context->getUrl('sales/*/voidPayment', ['order_id' => $orderId]);
//
//                $buttonList->add(
//                    'void_payment',
//                    [
//                        'label' => __('Void'),
//                        'onclick' => "confirmSetLocation('{$message}', '{$url}')"
//                    ]
//                );
//            }
            
            if (isset($buttonList->getItems()[0]['void_payment'])) {
                $message    = __('Are you sure you want to void the payment?');
                $url        = $context->getUrl('sales/*/voidPayment', ['order_id' => $orderId]);

                $buttonList->getItems()[0]['void_payment']['onclick'] = "confirmSetLocation('{$message}', '{$url}')";
            }
        } catch (\Exception $e) {
            $this->readerWriter->createLog($e->getMessage(), 'Class Toolbar exception:');
            return [$context, $buttonList];
        }

        return [$context, $buttonList];
    }
}
