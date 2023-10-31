<?php

namespace Nuvei\Checkout\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\Config as ModuleConfig;
use Nuvei\Checkout\Model\Payment;
use Nuvei\Checkout\Model\Request\Factory as RequestFactory;

/**
 * Nuvei Checkout OpenOrder controller.
 */
class OpenOrder extends Action
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
    private $cart;
    private $quoteFactory;

    /**
     * Redirect constructor.
     *
     * @param Context           $context
     * @param ModuleConfig      $moduleConfig
     * @param JsonFactory       $jsonResultFactory
     * @param RequestFactory    $requestFactory
     * @param ReaderWriter      $readerWriter
     * @param Cart              $cart
     */
    public function __construct(
        Context $context,
        ModuleConfig $moduleConfig,
        JsonFactory $jsonResultFactory,
        RequestFactory $requestFactory,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Quote\Model\QuoteFactory $quoteFactory
    ) {
        parent::__construct($context);

        $this->moduleConfig         = $moduleConfig;
        $this->jsonResultFactory    = $jsonResultFactory;
        $this->requestFactory       = $requestFactory;
        $this->readerWriter         = $readerWriter;
        $this->cart                 = $cart;
        $this->quoteFactory         = $quoteFactory;
    }

    /**
     * @return ResponseInterface
     */
    public function execute()
    {
        $this->readerWriter->createLog($this->getRequest()->getParams(), 'openOrder Controller');
        
        $result = $this->jsonResultFactory->create()
            ->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);
        
        if (!$this->moduleConfig->getConfigValue('active')) {
            $this->readerWriter->createLog('OpenOrder error - '
                . 'Nuvei checkout module is not active at the moment!');
            
            return $result->setData([
                'error_message' => __('OpenOrder error - Nuvei checkout module is not active at the moment!')
            ]);
        }
        
        $request = $this->requestFactory->create(AbstractRequest::OPEN_ORDER_METHOD);
        
        # when use Checkout SDK and its pre-payment do not call OpenOrder class at all
        if ('checkout' == $this->moduleConfig->getUsedSdk()
            && $this->getRequest()->getParam('nuveiAction') == 'nuveiPrePayment'
        ) {
            $quoteId = $this->getRequest()->getParam('quoteId'); // it comes form REST call as parameter
//            $quote              = empty($quoteId) ? $this->cart->getQuote() : $this->quoteFactory->create()->load($quoteId);
//            $order_data         = $quote->getPayment()
//                    ->getAdditionalInformation(Payment::CREATE_ORDER_DATA); // we need itemsBaseInfoHash
//            $items              = $quote->getItems();
//            $items_base_data    = [];
//            
//            $this->readerWriter->createLog($order_data);
//
//            if (!empty($items) && is_array($items)) {
//                foreach ($items as $item) {
//                    $items_base_data[] = [
//                        'id'    => $item->getId(),
//                        'name'  => $item->getName(),
//                        'qty'   => $item->getQty(),
//                        'price' => $item->getPrice(),
//                    ];
//                }
//            }
//
//            $this->readerWriter->createLog($items_base_data);
//            
//            // success
//            if (!empty($order_data['itemsBaseInfoHash'])
//                && $order_data['itemsBaseInfoHash'] == md5(serialize($items_base_data))
//            ) {
//                return $result->setData([
//                    "success" => 1,
//                ]);
//            }
//            
//            return $result->setData([
//                "success" => 0,
//            ]);
            
            $resp = $request
                ->setQuoteId($quoteId)
                ->prePaymentCheck();
            
            return $result->setData([
                "success" => 0 == $resp->error ? 1 : 0,
            ]);
        }
        
        # when use Web SDK we have to call the OpenOrder class because have to decide will we create UPO
        $save_upo   = $this->getRequest()->getParam('saveUpo') ?? null;
        $pmType     = $this->getRequest()->getParam('pmType') ?? '';
        
        if (true === strpos($pmType, 'upo')) {
            $save_upo = 1;
        }
        
        $resp = $request
                ->setSaveUpo($save_upo)
                ->process();
        
        // some error
        if (isset($resp->error, $resp->reason)
            && 1 == $resp->error
        ) {
            $resp_array = [
                "error"     => 1,
                'reason'    => $resp->reason,
            ];
            
            if (isset($resp->outOfStock)) {
                $resp_array['outOfStock'] = 1;
            }
            
            return $result->setData($resp_array);
        }
        
        // success
        return $result->setData([
            "error"         => 0,
            "sessionToken"  => $resp->sessionToken,
            "amount"        => $resp->ooAmount,
            "message"       => "Success"
        ]);
    }
}
