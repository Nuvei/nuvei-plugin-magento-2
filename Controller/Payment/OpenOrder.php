<?php

namespace Nuvei\Checkout\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\Config as ModuleConfig;
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

    /**
     * Redirect constructor.
     *
     * @param Context           $context
     * @param ModuleConfig      $moduleConfig
     * @param JsonFactory       $jsonResultFactory
     * @param RequestFactory    $requestFactory
     * @param ReaderWriter      $readerWriter
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
            $this->readerWriter->createLog('OpenOrder error - '
                . 'Nuvei checkout module is not active at the moment!');
            
            return $result->setData([
                'error_message' => __('OpenOrder error - Nuvei checkout module is not active at the moment!')
            ]);
        }
        
        
        
        $save_upo = $this->getRequest()->getParam('saveUpo');
        
        if (strpos($this->getRequest()->getParam('pmType'), 'upo')) {
            $save_upo = 1;
        }
        
        $request    = $this->requestFactory->create(AbstractRequest::OPEN_ORDER_METHOD);
        $resp       = $request
                ->setSaveUpo($this->getRequest()->getParam('saveUpo'))
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
