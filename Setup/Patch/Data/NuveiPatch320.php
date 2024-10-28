<?php

namespace Nuvei\Checkout\Setup\Patch\Data;

use Magento\Framework\Setup\Patch\DataPatchInterface;
use Magento\Sales\Model\Order;

/**
 * With this patch we update some of the Nuvei custom statuses, added into 
 * the store with previous patch.
 */
class NuveiPatch320 implements DataPatchInterface
{
    protected $statusFactory;
    protected $statusResource;
    protected $resourceConnection;
    
    private $nuveiStatuses;
   
    public function __construct(
        \Magento\Sales\Model\Order\StatusFactory $statusFactory,
        \Magento\Sales\Model\ResourceModel\Order\Status $statusResource,
        \Magento\Framework\App\ResourceConnection $resourceConnection
    ) {
        $this->statusResource       = $statusResource;
        $this->statusFactory        = $statusFactory;
        $this->resourceConnection   = $resourceConnection;
        
        // the states to update
        $this->nuveiStatuses = [
            'nuvei_voided' => [
                'label'     => 'Nuvei Voided',
                'state'     => Order::STATE_CANCELED,
            ],
            'nuvei_settled' => [
                'label'     => 'Nuvei Settled',
                'state'     => Order::STATE_COMPLETE,
            ],
            'nuvei_refunded' => [
                'label'     => 'Nuvei Refunded',
                'state'     => Order::STATE_COMPLETE,
            ],
            'nuvei_canceled' => [
                'label'     => 'Nuvei Declined',
                'state'     => Order::STATE_CANCELED,
            ],
        ];
    }
    
    /**
     * {@inheritdoc}
     */
    public function apply()
    {
        foreach ($this->nuveiStatuses as $code => $data) {
            // Load the existing status by its code
            $status = $this->statusFactory->create()->load($code, 'status');
            
            // Check if the status exists
            if ($status->getId()) {
                // updates the label in table sales_order_status
                $status->setData('label', $data['label']);
                $status->save();
                
                // If the state assignment exists - update its state in sales_order_status_state table
                if ($this->isStateAssigned($code)) {
                    $this->updateStateAssignment($code, $data['state']);
                }
            }
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function getAliases()
    {
        return [];
    }
    
    private function isStateAssigned($statusCode)
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName  = $this->resourceConnection->getTableName('sales_order_status_state');

        $select = $connection->select()
            ->from($tableName)
            ->where('status = ?', $statusCode);

        $result = $connection->fetchRow($select);
        
        return !empty($result); // Returns true if already assigned
    }
    
    private function updateStateAssignment($statusCode, $state)
    {
        $connection = $this->resourceConnection->getConnection();
        $tableName  = $this->resourceConnection->getTableName('sales_order_status_state');

        $connection->update(
            $tableName,
            ['state' => $state], // key-value pair
            ['status = ?' => $statusCode] // where clause
        );
    }
}
