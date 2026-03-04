<?php

namespace Nuvei\Checkout\Model\Request;

use Magento\Checkout\Model\Cart;
use Magento\Directory\Api\Data\CountryInformationInterface;
use Magento\Directory\Model\CountryFactory;
use Magento\Framework\Exception\PaymentException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Nuvei\Checkout\Lib\Http\Client\Curl;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\AbstractResponse;
use Nuvei\Checkout\Model\Config;
use Nuvei\Checkout\Model\PaymentsPlans;
use Nuvei\Checkout\Model\ReaderWriter;
use Nuvei\Checkout\Model\RequestInterface;
use Nuvei\Checkout\Model\Response\Factory;

/**
 * Nuvei Checkout open order request model.
 */
class UpdateOrder extends AbstractRequest implements RequestInterface
{
    /**
     * @var array
     */
    protected $orderData;

    private $quoteId        = '';
    private $entityId       = '';
    private $requestParams  = [];
    private $cart;
    private $paymentsPlans;
    private $orderRepo;
    private $countryInfo;
    private $countryFactory;
    
    /**
     * @param Config                        $config
     * @param Curl                          $curl
     * @param Factory                       $responseFactory
     * @param Cart                          $cart
     * @param ReaderWriter                  $readerWriter
     * @param PaymentsPlans                 $paymentsPlans
     * @param OrderRepositoryInterface      $orderRepo
     * @param CountryInformationInterface   $countryInfo
     * @param CountryFactory                $countryFactory
     */
    public function __construct(
        Config $config,
        Curl $curl,
        Factory $responseFactory,
        Cart $cart,
        ReaderWriter $readerWriter,
        PaymentsPlans $paymentsPlans,
        OrderRepositoryInterface $orderRepo,
        CountryInformationInterface $countryInfo,
        CountryFactory $countryFactory
    ) {
        parent::__construct(
            $config,
            $curl,
            $responseFactory,
            $readerWriter
        );

        $this->cart             = $cart;
        $this->paymentsPlans    = $paymentsPlans;
        $this->orderRepo        = $orderRepo;
        $this->countryInfo      = $countryInfo;
        $this->countryFactory   = $countryFactory;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getRequestMethod()
    {
        return self::UPDATE_ORDER_METHOD;
    }

    /**
     * @param array $orderData
     *
     * @return OpenOrder
     */
    public function setOrderData(array $orderData)
    {
        $this->orderData = $orderData;
        
        return $this;
    }
    
    public function setQuoteId($id)
    {
        $this->quoteId = $id;
        
        return $this;
    }
    
    public function setEntityId($id)
    {
        $this->entityId = $id;
        
        return $this;
    }
    
    /**
     * @return AbstractResponse
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws PaymentException
     */
    public function process()
    {
        $req_resp = $this->sendRequest(true, true);
        
        // in the caller class - OpenOrder we needd also the requestParams
        return [
            'requestParams' => $this->requestParams,
            'respParams'    => $req_resp
        ];
    }
    
    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getResponseHandlerType()
    {
        return '';
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     * @throws \Magento\Framework\Exception\PaymentException
     */
    protected function getParams()
    {
        $subs_data  = [];
        $params     = [];
        $currency   = '';
        
        // We can collect the details from the Order or from the Quote
        // Case 1 - when we have Order
        if (!empty ($this->entityId)) {
            $this->readerWriter->createLog($this->entityId, 'entityId');
            
            $order      = $this->orderRepo->get($this->entityId);
            $items_data = $this->paymentsPlans // iterate over Items and search for Subscriptions
                ->setOrder($order)
                ->getProductPlanData();
            
            $subs_data = isset($items_data['subs_data']) ? $items_data['subs_data'] : [];
            
            $this->config->setNuveiUseCcOnly(!empty($subs_data) ? true : false);
            
            $amount         = (string) number_format((float) $order->getBaseGrandTotal(), 2, '.', '');
            $currency       = $order->getBaseCurrencyCode();
            $billingAddress = $order->getBillingAddress();
            $billingCountry = $this->countryFactory->create()
                ->loadByCode($billingAddress->getCountryId())
                ->getName();
            
            $params = array_merge_recursive(
                parent::getParams(),
                [
                    'currency'          => $currency,
                    'amount'            => $amount,
                    'billingAddress'    => [
                        "firstName" => $billingAddress->getFirstname(),
                        "lastName"  => $billingAddress->getLastname(),
                        "address"   => str_replace(
                            array("\n", "\r", '\\'), 
                            ' ', 
                            implode(', ', $billingAddress->getStreet())
                        ),
                        "phone"     => $billingAddress->getTelephone(),
                        "zip"       => $billingAddress->getPostcode(),
                        "city"      => $billingAddress->getCity(),
                        'country'   => $billingAddress->getCountryId(),
                        'email'     => $billingAddress->getEmail(),
                    ],
                    'items'             => [[
                        'name'      => 'Magento Order',
                        'price'     => $amount,
                        'quantity'  => 1,
                    ]],

                    'merchantDetails'   => [
                        'customField1'  => $amount,
                        'customField2'  => json_encode($subs_data),
                        // customField4 will be set in AbstractRequest class
                        'customField5' => $currency,
                        'customField6' => 'orderIncrementId',
                    ],
                ]
            );
            
            $params['sessionToken']     = $this->orderData['sessionToken'];
            $params['orderId']          = isset($this->orderData['orderId']) ? $this->orderData['orderId'] : '';
            $params['clientUniqueId']   = $order->getIncrementId(); // this is the visible Order id
            $params['clientRequestId']  = isset($this->orderData['clientRequestId'])
                ? $this->orderData['clientRequestId'] : $this->initRequest();
            
            if ($shippingAddress = $order->getShippingAddress()) {
                $shippingCountry = $this->countryFactory->create()
                    ->loadByCode($shippingAddress->getCountryId())
                    ->getName();
                
                $params['shippingAddress'] = [
                    "firstName" => $shippingAddress->getFirstname(),
                    "lastName"  => $shippingAddress->getLastname(),
                    "address"   => str_replace(
                        array("\n", "\r", '\\'), 
                        ' ', 
                        implode(', ', $shippingAddress->getStreet())
                    ),
                    "phone"     => $shippingAddress->getTelephone(),
                    "zip"       => $shippingAddress->getPostcode(),
                    "city"      => $shippingAddress->getCity(),
                    'country'   => $shippingAddress->getCountryId(),
                    'email'     => $shippingAddress->getEmail(),
                ];
            }
            
        }
        // Case 2 - when we have Quote
        elseif (!empty($this->quoteId)) {
            $this->readerWriter->createLog($this->quoteId, '$quoteId');
            
            if (null === $this->cart || empty($this->cart)) {
                $this->readerWriter->createLog('UpdateOrder Error - There is no Cart data.');

                throw new PaymentException(__('There is no Cart data.'));
            }

            // iterate over Items and search for Subscriptions
            $items_data = $this->paymentsPlans
                ->setQuoteId($this->quoteId)
                ->getProductPlanData();
            
            $subs_data = isset($items_data['subs_data']) ? $items_data['subs_data'] : [];

            $this->config->setNuveiUseCcOnly(!empty($subs_data) ? true : false);

            $amount     = $this->config->getQuoteBaseTotal($this->quoteId);
            $currency   = $this->config->getQuoteBaseCurrency($this->quoteId);

            $params = array_merge_recursive(
                parent::getParams(),
                [
                    'currency'          => $currency,
                    'amount'            => $amount,
                    'billingAddress'    => $this->config->getQuoteBillingAddress($this->quoteId),
                    'shippingAddress'   => $this->config->getQuoteShippingAddress($this->quoteId),

                    'items'             => [[
                        'name'      => 'magento_order',
                        'price'     => $amount,
                        'quantity'  => 1,
                    ]],

                    'merchantDetails'   => [
                        'customField1'  => $amount,
                        'customField2'  => json_encode($subs_data),
                        // customField4 will be set in AbstractRequest class
                        'customField5' => $currency,
                        'customField6' => 'quoteId',
                    ],
                ]
            );

            $params['sessionToken']     = $this->orderData['sessionToken'];
            $params['orderId']          = isset($this->orderData['orderId']) ? $this->orderData['orderId'] : '';
//            $params['clientUniqueId']   = $this->quoteId . '_' . time();
            $params['clientUniqueId']   = $this->quoteId;
            $params['clientRequestId']  = isset($this->orderData['clientRequestId'])
                ? $this->orderData['clientRequestId'] : $this->initRequest();
        }
        
        $this->readerWriter->createLog([
            '$subs_data'            => $subs_data,
            'getQuoteBaseCurrency'  => $currency,
        ]);
        
        $params['userDetails'] = $params['billingAddress'];
        
        $params['checksum'] = hash(
            $this->config->getConfigValue('hash'),
            $this->config->getMerchantId() 
                . $this->config->getMerchantSiteId() 
                . $params['clientRequestId']
                . $params['amount'] 
                . $params['currency'] 
                . $params['timeStamp'] 
                . $this->config->getMerchantSecretKey()
        );
        
        $this->requestParams = $params;
        
        return $params;
    }

    /**
     * {@inheritdoc}
     *
     * @return array
     */
    protected function getChecksumKeys()
    {
        return [
            'merchantId',
            'merchantSiteId',
            'clientRequestId',
            'amount',
            'currency',
            'timeStamp',
        ];
    }
    
    /**
     * Get attribute options
     *
     * @param  \Magento\Eav\Api\Data\AttributeInterface $attribute
     * @return array
     */
    private function getOptions(\Magento\Eav\Api\Data\AttributeInterface $attribute) : array
    {
        $return = [];

        try {
            $options = $attribute->getOptions();
            foreach ($options as $option) {
                if ($option->getValue()) {
                    $return[] = [
                        'value' => $option->getLabel(),
                        'label' => $option->getLabel(),
                        'parentAttributeLabel' => $attribute->getDefaultFrontendLabel()
                    ];
                }
            }

            return $return;
        } catch (\Exception $e) {
            $this->readerWriter->createLog($e->getMessage(), 'getOptions() Exception');
        }

        return $return;
    }
}
