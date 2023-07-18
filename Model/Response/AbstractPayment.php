<?php

namespace Nuvei\Checkout\Model\Response;

use Nuvei\Checkout\Lib\Http\Client\Curl;
use Nuvei\Checkout\Model\AbstractResponse;
use Magento\Sales\Model\Order\Payment as OrderPayment;

/**
 * Nuvei Checkout abstract payment response model.
 */
abstract class AbstractPayment extends AbstractResponse
{
    /**
     * @var OrderPayment
     */
    protected $orderPayment;

    /**
     * AbstractPayment constructor.
     *
     * @param int               $requestId
     * @param Curl              $curl
     * @param OrderPayment|null $orderPayment
     */
    public function __construct(
        $requestId,
        Curl $curl,
        OrderPayment $orderPayment,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        parent::__construct(
            $requestId,
            $curl,
            $readerWriter
        );

        $this->orderPayment = $orderPayment;
    }

    /**
     * @return AbstractResponse
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function process()
    {
        parent::process();

        $this
            ->processResponseData()
            ->updateTransaction();

        return $this;
    }
    
    protected function processResponseData()
    {
        return $this;
    }

    /**
     * @return AbstractPayment
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function updateTransaction()
    {
        $body = $this->getBody();
        $transactionKeys = $this->getRequiredResponseDataKeys();

        $transactionInformation = [];
        foreach ($transactionKeys as $transactionKey) {
            if (!isset($body[$transactionKey])) {
                continue;
            }

            $transactionInformation[$transactionKey] = $body[$transactionKey];
        }
        ksort($transactionInformation);

        return $this;
    }
}
