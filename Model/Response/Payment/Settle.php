<?php

namespace Nuvei\Checkout\Model\Response\Payment;

use Nuvei\Checkout\Model\Payment;
use Nuvei\Checkout\Model\Response\AbstractPayment;
use Nuvei\Checkout\Model\ResponseInterface;

/**
 * Nuvei Checkout payment settle response model.
 */
class Settle extends AbstractPayment implements ResponseInterface
{
    /**
     * @var int
     */
    protected $transactionId;

    /**
     * @var string
     */
    protected $authCode;

    /**
     * @return Settle
     */
    protected function processResponseData()
    {
        $body = $this->getBody();

        $this->transactionId = $body['transactionId'];
        $this->authCode = $body['authCode'];

        return $this;
    }

    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    protected function getRequestStatus()
    {
        if (parent::getRequestStatus() === false) {
            return false;
        }

        $body = $this->getBody();
        if (strtolower($body['transactionStatus']) === 'error') {
            return false;
        }

        return true;
    }

    /**
     * @return int
     */
    public function getTransactionId()
    {
        return $this->transactionId;
    }

    /**
     * @return string
     */
    public function getAuthCode()
    {
        return $this->authCode;
    }

    /**
     * @return Settle
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function updateTransaction()
    {
        parent::updateTransaction();

        try {
            if (!empty($this->getAuthCode())) {
                $this->orderPayment->setAdditionalInformation(
                    Payment::TRANSACTION_AUTH_CODE,
                    $this->getAuthCode()
                );
            }
            
            if (!empty($this->getTransactionId())) {
                $this->orderPayment->setAdditionalInformation(
                    Payment::TRANSACTION_ID,
                    $this->getTransactionId()
                );
            }
        } catch (\Exception $ex) {
            $this->readerWriter->createLog($ex->getMessage(), 'updateTransaction exception:');
        }

        $this->orderPayment
            ->setParentTransactionId($this->orderPayment->getTransactionId())
            ->setTransactionId($this->getTransactionId())
            ->setIsTransactionClosed(1);

        return $this;
    }

    /**
     * @return array
     */
    protected function getRequiredResponseDataKeys()
    {
        return array_merge_recursive(
            parent::getRequiredResponseDataKeys(),
            [
                'transactionId',
                'authCode',
                'transactionStatus',
            ]
        );
    }
}
