<?php
namespace Nuvei\Checkout\Controller\Paybylink;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;

class Manifest extends Action
{
    public function __construct(Context $context)
    {
        parent::__construct($context);
    }

    public function execute()
    {
        $filePath   = BP . '/app/code/Nuvei/Checkout/view/frontend/web/.well-known/nuvei-ai.json';
        $data       = [];

        if (file_exists($filePath)) {
            $json = file_get_contents($filePath);
            $data = json_decode($json, true) ?: [];
        }

        return $this->resultFactory->create(ResultFactory::TYPE_JSON)
            ->setData($data);
    }
}