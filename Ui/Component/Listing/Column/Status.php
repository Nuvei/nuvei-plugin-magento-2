<?php

namespace Nuvei\Checkout\Ui\Component\Listing\Column;

use Magento\Ui\Component\Listing\Columns\Column;
use Magento\Framework\View\Element\UiComponent\ContextInterface;
use Magento\Framework\View\Element\UiComponentFactory;
use Nuvei\Checkout\Model\Payment;

/**
 * Add additional marker for Nuvei Payment Plan.
 */
class Status extends Column
{
    private $collection;
    private $readerWriter;

    /**
     * Constructor
     *
     * @param ContextInterface $context
     * @param UiComponentFactory $uiComponentFactory
     * @param array $components
     * @param array $data
     */
    public function __construct(
        ContextInterface $context,
        UiComponentFactory $uiComponentFactory,
        \Magento\Sales\Model\Order $collection,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        array $components = [],
        array $data = []
    ) {
        $this->collection   = $collection;
        $this->readerWriter = $readerWriter;
        
        parent::__construct($context, $uiComponentFactory, $components, $data);
    }

    /**
     * Prepare Data Source
     *
     * @param array $dataSource
     * @return array
     */
    public function prepareDataSource(array $dataSource)
    {
        if (isset($dataSource['data']['items'])) {
            foreach ($dataSource['data']['items'] as $key => $item) {
                try {
                    $order_info     = $this->collection->loadByIncrementId($item['increment_id']);
                    $orderPayment   = $order_info->getPayment();
                    $ord_trans_data = $orderPayment->getAdditionalInformation(Payment::ORDER_TRANSACTIONS_DATA);
                    $subscr_ids     = '';
                    
                    if (2000000116 <= $item['increment_id']) {
                        $this->readerWriter->createLog($item['increment_id']);
                        $this->readerWriter->createLog($ord_trans_data);
                    }
                    
//                    $this->readerWriter->createLog(
//                        [
//                            $item['increment_id'],
//                            $orderPayment->getAdditionalInformation(Payment::SUBSCR_ID),
//                        ], 
//                        'getAdditionalInformation'
//                    );
                    
                    $dataSource['data']['items'][$key]['has_nuvei_subscr']
                        = (bool) $orderPayment->getAdditionalInformation(Payment::SUBSCR_ID);
                } catch (\Exception $e) {
                    $this->readerWriter->createLog($e->getMessage(), 'Exeception in Order Grid Status class:');
                    $dataSource['data']['items'][$key]['has_nuvei_subscr'] = 0;
                }
            }
        }

        return $dataSource;
    }
}
