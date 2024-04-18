<?php

namespace Nuvei\Checkout\Model\Request;

use Magento\Framework\Exception\PaymentException;
use Nuvei\Checkout\Model\Request\Factory as RequestFactory;
use Nuvei\Checkout\Model\Payment;
use Nuvei\Checkout\Lib\Http\Client\Curl;
use Nuvei\Checkout\Model\AbstractRequest;
use Nuvei\Checkout\Model\AbstractResponse;
use Nuvei\Checkout\Model\Config;
use Nuvei\Checkout\Model\RequestInterface;
use Nuvei\Checkout\Model\Response\Factory as ResponseFactory;

/**
 * Nuvei Checkout open order request model.
 */
class OpenOrder extends AbstractRequest implements RequestInterface
{
    // public variables for the REST API
    public $sessionToken;
    public $ooAmount;
    public $subsData;
    public $error;
    public $outOfStock;
    public $reason;
    
    /**
     * @var RequestFactory
     */
    protected $requestFactory;

    /**
     * @var array
     */
    protected $orderData;
    
    protected $readerWriter;
    
    private $items_data     = [];
    private $subs_data      = [];
    private $requestParams  = [];
    private $isUserLogged   = null;
    private $saveUpo        = null;
    private $callerSdk      = null;
    private $quoteId        = '';
    private $stockState;
    private $countryCode; // string
    private $quote;
    private $cart;
    private $items; // the products in the cart
    private $paymentsPlans;
    private $quoteFactory;
    private $httpRequest;
    private $serializer;
    
    /**
     * OpenOrder constructor.
     *
     * @param Config          $config
     * @param Curl            $curl
     * @param ResponseFactory $responseFactory
     * @param Factory         $requestFactory
     * @param Cart            $cart
     * @param ReaderWriter    $readerWriter
     * @param PaymentsPlans   $paymentsPlans
     * @param StockState      $stockState
     */
    public function __construct(
        Config $config,
        Curl $curl,
        ResponseFactory $responseFactory,
        RequestFactory $requestFactory,
        \Magento\Checkout\Model\Cart $cart,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        \Nuvei\Checkout\Model\PaymentsPlans $paymentsPlans,
        \Magento\CatalogInventory\Model\StockState $stockState,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Framework\App\RequestInterface $httpRequest,
        \Magento\Framework\Serialize\Serializer\Serialize $serializer
    ) {
        parent::__construct(
            $config,
            $curl,
            $responseFactory,
            $readerWriter
        );

        $this->requestFactory   = $requestFactory;
        $this->cart             = $cart;
        $this->paymentsPlans    = $paymentsPlans;
        $this->stockState       = $stockState;
        $this->quoteFactory     = $quoteFactory;
        $this->httpRequest      = $httpRequest;
        $this->serializer       = $serializer;
    }

    /**
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getRequestMethod()
    {
        return self::OPEN_ORDER_METHOD;
    }

    /**
     * @return AbstractResponse
     * 
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws PaymentException
     */
    public function process()
    {
        $this->readerWriter->createLog('openOrder');
        
        $this->quote = empty($this->quoteId) ? $this->cart->getQuote() 
            : $this->quoteFactory->create()->load($this->quoteId);
        
        $this->items = $this->quote->getItems();
        
        // check if each item is in stock
        $items_base_data = $this->isProductAvailable();
        // after the above call
        if (1 == $this->error) {
            return $this;
        }
        
        // iterate over Items and search for Subscriptions
        $this->items_data   = $this->paymentsPlans
            ->setQuoteId($this->quoteId)
            ->getProductPlanData();
        
        $this->readerWriter->createLog($this->items_data, 'items_data');
        
        $this->subs_data    = $this->items_data['subs_data'] ?? [];
        $order_data         = $this->quote->getPayment()->getAdditionalInformation(Payment::CREATE_ORDER_DATA);
        
        $this->readerWriter->createLog(
            [
            'quoteId'       => $this->quoteId,
            'order_data'    => $order_data,
            'subs_data'     => $this->subs_data,
            ]
        );
        
        // will we call updateOrder?
        $callUpdateOrder    = false;
        $order_total        = (float) $this->config->getQuoteBaseTotal($this->quoteId);
        
        // check for prevouse OpenOrder data
        if (!empty($order_data)) {
            $callUpdateOrder = true;
        }
        
        // check for newly added product with 
        if (empty($order_data['userTokenId']) && !empty($this->subs_data)) {
            $this->readerWriter->createLog('$order_data[userTokenId] is empty, call openOrder');
            $callUpdateOrder = false;
        }
        
        // if by some reason missing transactionType
        if (empty($order_data['transactionType'])) {
            $this->readerWriter->createLog('$order_data[transactionType] is empty, call openOrder');
            $callUpdateOrder = false;
        }
        
        // when the total is 0 transaction type must be Auth!
        if ($order_total == 0
            && (empty($order_data['transactionType'])
            || 'Auth' != $order_data['transactionType']        )
        ) {
            $this->readerWriter->createLog('$order_total is and transactionType is Auth, call openOrder');
            $callUpdateOrder = false;
        }
        
        if ($order_total > 0
            && !empty($order_data['transactionType'])
            && 'Auth' == $order_data['transactionType']
            && $order_data['transactionType'] != $this->config->getConfigValue('payment_action')
        ) {
            $callUpdateOrder = false;
        }
        
        // in this case pass again the endpoints
        if (empty($order_data['apmWindowType'])
            || $this->config->getConfigValue('apm_window_type') != $order_data['apmWindowType']
        ) {
            $callUpdateOrder = false;
        }
        // /will we call updateOrder?
        
        if ($callUpdateOrder) {
            $update_order_request = $this->requestFactory->create(AbstractRequest::UPDATE_ORDER_METHOD);

            $req_resp = $update_order_request
                ->setOrderData($order_data)
                ->setQuoteId($this->quoteId)
                ->process();
        }
        // /will we call updateOrder?
        
        // if UpdateOrder fails - continue with OpenOrder
        if (empty($req_resp['status']) || 'success' != strtolower($req_resp['status'])) {
            $req_resp = $this->sendRequest(true);
        }
        
        $this->sessionToken = $req_resp['sessionToken'];
        $this->ooAmount     = $req_resp['merchantDetails']['customField1'];
        $this->subsData     = $this->subs_data; // pass the private variable to the public one, used into the API

        // save the session token in the Quote
        $add_info = [
            'sessionToken'      => $req_resp['sessionToken'],
            'clientRequestId'   => $req_resp['clientRequestId'],
            'orderId'           => $req_resp['orderId'],
            'itemsBaseInfoHash' => hash('md5', $this->serializer->serialize($items_base_data)),
            'apmWindowType'     => $this->config->getConfigValue('apm_window_type'),
        ];
        
        if (isset($req_resp['userTokenId'])) {
            $add_info['userTokenId'] = $req_resp['userTokenId'];
        }
        
        // in case of OpenOrder
        if (!empty($this->requestParams['transactionType'])) {
            $add_info['transactionType'] = $this->requestParams['transactionType'];
        }
        // in case of updateOrder the transactionType is not changed
        elseif (!empty($order_data['transactionType'])) {
            $add_info['transactionType'] = $order_data['transactionType'];
        }
        
        $this->quote->getPayment()->setAdditionalInformation(
            Payment::CREATE_ORDER_DATA,
            $add_info
        );
        
        $this->quote->save();
        // /save the session token in the Quote
        
        $this->readerWriter->createLog(
            [
                'quote id' => $this->quoteId,
                'quote CREATE_ORDER_DATA' => $this->quote->getPayment()
                    ->getAdditionalInformation(Payment::CREATE_ORDER_DATA),
            ]
        );
        
        return $this;
    }
    
    public function setQuoteId($quoteId = '')
    {
        $this->quoteId = $quoteId;
        
        return $this;
    }
    
    /**
     * This is about the REST user.
     * 
     * @param  bool|null $isUserLogged
     * @return $this
     */
    public function setIsUserLogged($isUserLogged = null)
    {
        $this->isUserLogged = $isUserLogged;
        
        return $this;
    }
    
    public function setSaveUpo($saveUpo = null)
    {
        $this->saveUpo = $saveUpo;
        
        return $this;
    }
    
    /**
     * When we call this class after REST API request,
     * it is good to know what SDK is used on the custom front-end.
     * 
     * @param  string $callerSdk
     * @return object
     */
    public function setCallerSdk($callerSdk)
    {
        $this->callerSdk = $callerSdk;
        
        return $this;
    }
    
    public function prePaymentCheck()
    {
        $this->readerWriter->createLog('prePaymentCheck');
        
        $quote = empty($this->quoteId) 
            ? $this->cart->getQuote() : $this->quoteFactory->create()->load($this->quoteId);
        
        $order_data = $quote->getPayment()
            ->getAdditionalInformation(Payment::CREATE_ORDER_DATA); // we need itemsBaseInfoHash
        
        $this->items        = $quote->getItems();
        $items_base_data    = $this->isProductAvailable();
        $this->error        = 1;
        $magentoVersionInt  = str_replace('.', '', (string) $this->config->getMagentoVersion());
        $quotePM            = '';
        
        if ($quote->getPayment()) {
            $quotePM = $quote->getPayment()->getMethod();
        }
        
        // success
        if (!empty($order_data['itemsBaseInfoHash'])
            && $order_data['itemsBaseInfoHash'] == hash('md5', $this->serializer->serialize($items_base_data))
        ) {
            $this->error = 0;
            
            // mod for Magenteo 2.3.x to set in Quote the Payment Method
            if (240 > $magentoVersionInt && $quotePM != Payment::METHOD_CODE) {
                $quote->setPaymentMethod(Payment::METHOD_CODE);
                $quote->getPayment()->importData(['method' => Payment::METHOD_CODE]);
                $quote->save();
            }
        }
        
        return $this;
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
        if (null === $this->cart || empty($this->cart)) {
            $this->readerWriter->createLog('OpenOrder class Error - mising Cart data.');
            throw new PaymentException(__('There is no Cart data.'));
        }
        
        $this->config->setNuveiUseCcOnly(!empty($this->subs_data) ? true : false);
        
        $quoteId            = empty($this->quoteId) ? $this->config->getCheckoutSession()->getQuoteId() : $this->quoteId;
        $amount             = $this->config->getQuoteBaseTotal($quoteId);
        $billing_address    = [];
        
        if (!empty($this->billingAddress)) {
            $billing_address['firstName']   = $this->billingAddress['firstname'] ?: $billing_address['firstName'];
            $billing_address['lastName']    = $this->billingAddress['lastname'] ?: $billing_address['lastName'];
            
            if (is_array($this->billingAddress['street']) && !empty($this->billingAddress['street'])) {
                $address                    = (string) trim(implode(' ', $this->billingAddress['street']));
                $billing_address['address'] = str_replace(array("\n", "\r", '\\'), ' ', $address);
            }
            
            $billing_address['phone']   = $this->billingAddress['telephone'] ?: $billing_address['phone'];
            $billing_address['zip']     = $this->billingAddress['postcode'] ?: $billing_address['zip'];
            $billing_address['city']    = $this->billingAddress['city'] ?: $billing_address['city'];
            $billing_address['country'] = $this->billingAddress['countryId'] ?: $billing_address['country'];
        }
        else {
            $billing_address = $this->config->getQuoteBillingAddress($quoteId);
        }
        
        $currency   = $this->config->getQuoteBaseCurrency($quoteId);
        $params     = [
            'clientUniqueId'    => $quoteId . '_' . time(),
            'currency'          => $currency,
            'amount'            => $amount,
            'deviceDetails'     => $this->config->getDeviceDetails(),
            'shippingAddress'   => $this->config->getQuoteShippingAddress(),
            'billingAddress'    => $billing_address,
            'transactionType'   => (float) $amount == 0 ? 'Auth' : $this->config->getConfigValue('payment_action'),
        //            'isPartialApproval' => 1,

            'urlDetails'        => [
                'backUrl'           => $this->config->getBackUrl(),
                'successUrl'        => $this->config->getCallbackSuccessUrl($quoteId),
                'failureUrl'        => $this->config->getCallbackErrorUrl($quoteId),
                'pendingUrl'        => $this->config->getCallbackPendingUrl($quoteId),
            ],

            'merchantDetails'    => [
                'customField1' => $amount,
                'customField2' => isset($this->subs_data) ? json_encode($this->subs_data) : '',
                //'customField3' => $this->config->getReservedOrderId($quoteId),
                // customField4 will be set in AbstractRequest class
                'customField5' => $currency,
            ],
        ];
        
        $this->readerWriter->createLog(
            [
                $params['merchantDetails'],
                $this->config->getCallbackDmnUrl(null, null, [], $quoteId)
            ],
            'OpenOrder'
        );
        
        // set notify url
        if (0 == $this->config->getConfigValue('disable_notify_url')) {
            $params['urlDetails']['notificationUrl'] = $this->config->getCallbackDmnUrl(null, null, [], $quoteId);
        }
        
        // send userTokenId and save UPO
        if ($this->sendUserTokenId()) {
            $params['userTokenId'] = $params['billingAddress']['email'];
        }
        
        // auto_close_popup
        if (1 == $this->config->getConfigValue('auto_close_popup')
            && 'redirect' != $this->config->getConfigValue('apm_window_type', 'checkout')
        ) {
            $params['urlDetails']['successUrl'] = $params['urlDetails']['pendingUrl']
                                                = $params['urlDetails']['failureUrl']
                                                = Config::NUVEI_SDK_AUTOCLOSE_URL;
        }
        
        $this->requestParams = array_merge_recursive(
            $params,
            parent::getParams()
        );
        
        // for rebilling
        if (!empty($this->subs_data)) {
            $this->requestParams['userTokenId'] = $params['billingAddress']['email'];
        }
            
        $this->requestParams['userDetails'] = $this->requestParams['billingAddress'];
        
        return $this->requestParams;
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
     * Just a help function.
     */
    private function sendUserTokenId()
    {
        $this->readerWriter->createLog($this->saveUpo, 'sendUserTokenId');
        
        // 1. REST API flow
        if (!is_null($this->isUserLogged) && !is_null($this->callerSdk)) {
            // 1.1 checkout - pass it, the desicion is on the front-end
            if ('simplyConnect' == $this->callerSdk) {
                return true;
            }
            
            // 1.2 webSDK
            if (1 != $this->saveUpo) {
                return false;
            }
            
            if (true === $this->isUserLogged // set from the REST API
                || 1 == $this->config->getConfigValue('save_guest_upos') // force saving UPOs
            ) {
                return true;
            }
            
            return false;
        }
        
        // 2. Standard plugin flow - save upo is allowed only for registred user
        // or Guests in case when 'save_guest_upos' is set to Yes.
        if (!$this->config->isUserLogged() // get it when user is logged into magento store
            && 1 != $this->config->getConfigValue('save_guest_upos') // force saving UPOs)
        ) {
            return false;
        }
            
        // For Checkout SDK we always pass userTokenId. The decision to save UPO or not is in the SDK
        if ('checkout' == $this->config->getConfigValue('sdk')) {
            return true;
        }

        $httpParams = array_merge(
            $this->httpRequest->getParams(),
            $this->httpRequest->getPostValue()
        );
                
        // For the WebSDK the decision to save UPO is here - in the OpenOrder
        if ('false' == $this->config->getConfigValue('save_upos')) {
            return false;
        }
        
        if (1 == $this->saveUpo) {
            return true;
        }
        
        if (isset($httpParams['saveUpo']) && 1 == $httpParams['saveUpo']) {
            return true;
        }
        
        if (isset($httpParams['pmType']) && false !== strpos('upo', $httpParams['pmType'])) {
            return true;
        }
        
        return false;
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
    
    /**
     * @return array
     */
    private function isProductAvailable()
    {
        $this->readerWriter->createLog('isProductAvailable');
        
        $items_base_data = [];
        
        if (empty($this->items)) {
            $msg = 'Error! There are no items.';
            
            $this->error        = 1;
            $this->outOfStock   = 0;
            $this->reason       = __($msg);

            $this->readerWriter->createLog($items_base_data, $msg);
            
            return $items_base_data;
        }
        
        foreach ($this->items as $item) {
            $childItems = $item->getChildren();

            if (count($childItems)) {
                foreach ($childItems as $childItem) {
                    $stockItemToCheck[] = $childItem->getProduct()->getId();
                }
            } else {
                $stockItemToCheck[] = $item->getProduct()->getId();
            }

            $items_base_data[] = [
                'id'    => $item->getId(),
                'name'  => $item->getName(),
                'qty'   => $item->getQty(),
                'price' => $item->getPrice(),
            ];

            foreach ($stockItemToCheck as $productId) {
                $available = $this->stockState->checkQty($productId, $item->getQty());

                if (!$available) {
                    $this->error        = 1;
                    $this->outOfStock   = 1;
                    $this->reason       = __('Error! Some of the products are out of stock.');

                    $this->readerWriter->createLog(
                        [
                            '$productId'        => $productId,
                            '$items_base_data'  => $items_base_data,
                        ],
                        'A product is not availavle.'
                    );

                    //                        return $this;
                    return $items_base_data;
                }
            }
        }
        
        $this->readerWriter->createLog($items_base_data, '$items_base_data');
        
        return $items_base_data;
    }
    
}
