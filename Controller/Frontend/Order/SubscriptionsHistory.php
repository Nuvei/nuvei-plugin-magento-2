<?php

namespace Nuvei\Checkout\Controller\Frontend\Order;

use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Nuvei\Checkout\Model\Config;
use Magento\Framework\App\CsrfAwareActionInterface;

class SubscriptionsHistory extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    protected $resultPageFactory;
    protected $moduleManager;
    protected $objectManager;
    
    private $httpRequest;
    private $jsonResultFactory;
    private $request;
    private $helper;
    private $eavAttribute;
    private $config;
    private $uri;
    private $readerWriter;
    private $paymentsPlans;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Framework\App\Request\Http $httpRequest,
        \Magento\Framework\Controller\Result\JsonFactory $jsonResultFactory,
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\Pricing\Helper\Data $helper,
        \Magento\Eav\Model\ResourceModel\Entity\Attribute $eavAttribute,
        Config $config,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        \Nuvei\Checkout\Model\PaymentsPlans $paymentsPlans,
        \Magento\Framework\Module\Manager $moduleManager,
        \Magento\Framework\ObjectManagerInterface $objectManager
    ) {
        $this->resultPageFactory    = $resultPageFactory;
        $this->httpRequest          = $httpRequest;
        $this->jsonResultFactory    = $jsonResultFactory;
        $this->request              = $request;
        $this->helper               = $helper;
        $this->eavAttribute         = $eavAttribute;
        $this->config               = $config;
        $this->readerWriter         = $readerWriter;
        $this->paymentsPlans        = $paymentsPlans;
        $this->moduleManager        = $moduleManager;
        $this->objectManager        = $objectManager;
        
        // search for vendor classes
        $directory      = $this->objectManager->get('\Magento\Framework\Filesystem\DirectoryList');
        $root           = $directory->getRoot();
        $instanceName   = '';
        
        if ($this->readerWriter->fileExists($root . '/vendor/zendframework/zend-uri/src/Uri.php')) {
            $instanceName =  'Zend\Uri\Uri';
        } elseif ($this->readerWriter->fileExists($root . '/vendor/laminas/laminas-uri/src/Uri.php')) {
            $instanceName = 'Laminas\Uri\Uri';
        }
        
        if (empty($instanceName)) {
            return;
        }
        
        $this->uri = $this->objectManager->create($instanceName);
        
        parent::__construct($context);
    }
    
    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
    
    /**
     * Customer order subscriptions history
     *
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        if ($this->httpRequest->isAjax()) {
            $data = $this->getProductDetails();
            
            $jsonOutput = $this->jsonResultFactory->create();
            
            $jsonOutput->setHttpResponseCode(200);
            $jsonOutput->setData($data);
            
            return $jsonOutput;
        }
        
        $resultPage = $this->resultPageFactory->create();
        $resultPage->getConfig()->getTitle()->set(__('My Nuvei Subscriptions'));

        //        $block = $resultPage->getLayout()->getBlock('customer.account.link.back');
        //        if ($block) {
        //            $block->setRefererUrl($this->_redirect->getRefererUrl());
        //        }
        return $resultPage;
    }
    
    /**
     * Get Product and Product child based on attributes combination.
     *
     * @return array
     */
    private function getProductDetails()
    {
        try {
            $params         = $this->request->getParams();
            $hash_params    = [];
            $prod_options   = []; // the final array to pass

            $this->readerWriter->createLog($params, 'SubscriptionsHistory $params');
            
            if (empty($params)
                || empty($params['prodId'])
                || !is_numeric($params['prodId'])
                || empty($params['params'])
            ) {
                $this->readerWriter->createLog($params, 'Params are empty');
                return [];
            }
            
            if (is_string($params['params'])) {
                $this->uri->setQuery($params['params']);
                $hash_params = $this->uri->getQueryAsArray();
            } else {
                $hash_params = $params['params'];
            }
            
            if (empty($hash_params)
                || !is_array($hash_params)
            ) {
                $this->readerWriter->createLog($hash_params, 'Hash Params are empty');
                return [];
            }
            
            // sometimes the key can be the options codes, we need the IDs
            foreach ($hash_params as $key => $val) {
                if (is_numeric($key)) {
                    $prod_options[$key] = $val;
                    continue;
                }
                
                // get the option ID by its key
                $attributeId = $this->eavAttribute->getIdByCode('catalog_product', $key);
                
                if (!$attributeId) {
                    $this->readerWriter->createLog(
                        $attributeId, 
                        'SubscriptionsHistory Error - attribute ID must be int.'
                    );
                    continue;
                }
                
                $prod_options[$attributeId] = $val;
            }
            
            if (empty($prod_options)) {
                $this->readerWriter->createLog($prod_options, 'Product options are empty');
                return [];
            }
            
//            $product_data = $this->paymentsPlans->getProductPlanData($params['prodId'], $prod_options);
            $product_data = $this->paymentsPlans->getProductPlanDataById($params['prodId'], $prod_options);
            
            if (empty($product_data) || !is_array($product_data)) {
                $this->readerWriter->createLog(
                    [
                        '$product_data' => $product_data,
                        '$params'       => $params,
                        '$prod_options' => $prod_options,
                    ],
                    'Product data is empty'
                );
                return [];
            }
            
            $units      = [
                'day'       => __('day'),
                'days'      => __('days'),
                'month'     => __('month'),
                'months'    => __('months'),
                'year'      => __('year'),
                'years'     => __('years'),
            ];
            
            //
            $period     = current($product_data['endAfter']);
            $unit       = current(array_keys($product_data['endAfter']));
            $rec_len    = $period . ' ';

            if ($period > 1) {
                $rec_len .= $units[$unit . 's'];
            } else {
                $rec_len .= $units[$unit];
            }
            
            //
            $period     = current($product_data['recurringPeriod']);
            $unit       = current(array_keys($product_data['recurringPeriod']));
            $rec_period = __('Every') . ' ' . $period . ' ';
                
            if ($period > 1) {
                $rec_period .= $units[$unit . 's'];
            } else {
                $rec_period .= $units[$unit];
            }
            
            //
            $period         = current($product_data['startAfter']);
            $unit           = current(array_keys($product_data['startAfter']));
            $trial_period   = $period . ' ';
                
            if ($period > 1) {
                $trial_period .= $units[$unit . 's'];
            } elseif (1 == $period) {
                $trial_period .= $units[$unit];
            } else {
                $trial_period = __('None');
            }
            
            return [
                'rec_enabled'   => 1,
                'rec_len'       => $rec_len,
                'rec_period'    => $rec_period,
                'trial_period'  => $trial_period,
                'rec_amount'    => $this->helper->currency(
                    $product_data['recurringAmount'],
                    true,
                    false
                ),
            ];
        } catch (\Exception $e) {
            $this->readerWriter->createLog($e->getMessage(), 'SubscriptionsHistory getProductDetails() Exception:');
            return [];
        }
    }
}
