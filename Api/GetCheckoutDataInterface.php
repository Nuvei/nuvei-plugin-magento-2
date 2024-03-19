<?php
namespace Nuvei\Checkout\Api;
interface GetCheckoutDataInterface
{
    /**
     * @param int    $quoteId
     * @param string $neededData Available options - NUVEI_REST_API_PLUGIN_METHODS
     * 
     * @return string
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function getData(string $quoteId, string $neededData);
}
