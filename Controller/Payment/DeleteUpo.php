<?php

namespace Nuvei\Checkout\Controller\Payment;

use Magento\Framework\App\Action\Action;
use Nuvei\Checkout\Model\AbstractRequest;

class DeleteUpo extends Action
{
    /**
     * @var RedirectUrlBuilder
     */
    private $redirectUrlBuilder;

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
     * @param Context               $context
     * @param RedirectUrlBuilder    $redirectUrlBuilder
     * @param ModuleConfig          $moduleConfig
     * @param JsonFactory           $jsonResultFactory
     * @param RequestFactory        $requestFactory
     * @param ReaderWriter          $readerWriter
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Nuvei\Checkout\Model\Redirect\Url $redirectUrlBuilder,
        \Nuvei\Checkout\Model\Config $moduleConfig,
        \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory,
        \Nuvei\Checkout\Model\Request\Factory $requestFactory,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        parent::__construct($context);

        $this->redirectUrlBuilder   = $redirectUrlBuilder;
        $this->moduleConfig         = $moduleConfig;
        $this->jsonResultFactory    = $jsonResultFactory;
        $this->requestFactory       = $requestFactory;
        $this->readerWriter         = $readerWriter;
    }
    
    public function execute()
    {
        $result = $this->jsonResultFactory->create()->setHttpResponseCode(\Magento\Framework\Webapi\Response::HTTP_OK);

        if (!$this->moduleConfig->getConfigValue('active')) {
            $this->readerWriter->createLog('Nuvei payments module is not active at the moment!');
            
            return $result->setData([
                'error_message' => __('Nuvei payments module is not active at the moment!')
            ]);
        }
        
        try {
            $request    = $this->requestFactory->create(AbstractRequest::DELETE_UPOS_METHOD);
            $resp        = $request
                ->setUpoId($this->getRequest()->getParam('upoId'))
                ->process();
        } catch (Exception $ex) {
            return $result->setData(["success" => 0]);
        }
        
        return $result->setData(["success" => $resp === 'success' ? 1 : 0]);
    }
}
