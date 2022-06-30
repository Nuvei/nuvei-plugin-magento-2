<?php

namespace Nuvei\Checkout\Controller\Payment\Callback;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
//use Magento\Framework\Controller\ResultFactory;
//use Magento\Framework\Controller\ResultInterface;

/**
 * Nuvei Checkout payment place controller.
 */
class ErrorOld extends Action
{
    private $readerWriter;

    /**
     * Error constructor.
     *
     * @param Context       $context
     * @param ModuleConfig    $readerWriter
     */
    public function __construct(
        Context $context,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        parent::__construct($context);

        $this->readerWriter = $readerWriter;
    }
    
    /**
     * @return ResultInterface
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function execute()
    {
        $params = $this->getRequest()->getParams();

        $this->readerWriter->createLog($params, 'Error Callback Response: ');
        $this->messageManager->addErrorMessage(
            __('Your payment failed.')
        );
        
        $form_key        = filter_input(INPUT_GET, 'form_key');
        $resultRedirect    = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        
        $resultRedirect->setUrl(
            $this->_url->getUrl('checkout/cart')
            . (!empty($form_key) ? '?form_key=' . $form_key : '')
        );

        return $resultRedirect;
    }
}
