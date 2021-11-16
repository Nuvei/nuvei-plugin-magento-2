<?php

namespace Nuvei\Checkout\Cron;

use Nuvei\Checkout\Model\Config as ModuleConfig;
use Nuvei\Checkout\Model\ResourceModel\RequestLog\CollectionFactory;

/**
 * Nuvei Checkout delete old request log entries cron job.
 */
class DeleteOldRequestLog
{
    const DELETE_AGE = '7 DAY';

    /**
     * @var ModuleConfig
     */
    private $moduleConfig;

    /**
     * @var CollectionFactory
     */
    private $collectionFactory;

    /**
     * DeleteOldRequestLog constructor.
     *
     * @param ModuleConfig      $moduleConfig
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        ModuleConfig $moduleConfig,
        CollectionFactory $collectionFactory
    ) {
        $this->moduleConfig = $moduleConfig;
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * @return DeleteOldRequestLog
     */
    public function execute()
    {
        if ($this->moduleConfig->isActive() === false) {
            return $this;
        }

        $collection = $this->collectionFactory->create();
        $collection
            ->getSelect()
            ->where('updated_at < NOW() - INTERVAL ' . self::DELETE_AGE);

        $collection->walk('delete');

        return $this;
    }
}
