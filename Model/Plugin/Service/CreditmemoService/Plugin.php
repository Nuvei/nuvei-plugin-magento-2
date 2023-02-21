<?php

namespace Nuvei\Checkout\Model\Plugin\Service\CreditmemoService;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Registry as CoreRegistry;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Service\CreditmemoService;

/**
 * Nuvei Checkout credit memo service plugin model.
 */
class Plugin
{
    /**
     * @var CoreRegistry
     */
    private $coreRegistry;

    /**
     * Object constructor.
     *
     * @param CoreRegistry $coreRegistry
     */
    public function __construct(CoreRegistry $coreRegistry) {
        $this->coreRegistry = $coreRegistry;
    }

    /**
     * @param CreditmemoService $creditmemoService
     * @param \Closure          $closure
     * @param Creditmemo        $creditmemo
     * @param bool              $offlineRequested
     *
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function aroundRefund(
        CreditmemoService $creditmemoService,
        \Closure $closure,
        Creditmemo $creditmemo,
        $offlineRequested
    ) {
        try {
            $closure($creditmemo, $offlineRequested);
        } catch (LocalizedException $e) {
            throw $e;
        }
    }
}
