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
    /**
     * @var RequestFactory
     */
    protected $requestFactory;

    /**
     * @var array
     */
    protected $orderData;
    
    protected $readerWriter;
    
    private $stockState;
    private $countryCode; // string
    private $quote;
    private $cart;
    private $items; // the products in the cart
    private $paymentsPlans;
    private $quoteFactory;
    private $items_data     = [];
    private $subs_data      = [];
    private $requestParams  = [];
    private $isUserLogged   = null;
    private $saveUpo        = null;
    private $quoteId        = '';

    /**
     * OpenOrder constructor.
     *
     * @param Config            $config
     * @param Curl              $curl
     * @param ResponseFactory   $responseFactory
     * @param Factory           $requestFactory
     * @param Cart              $cart
     * @param ReaderWriter      $readerWriter
     * @param PaymentsPlans     $paymentsPlans
     * @param StockState        $stockState
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
        \Magento\Quote\Model\QuoteFactory $quoteFactory
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
        
        # check if each item is in stock
        if (!empty($this->items)) {
            foreach ($this->items as $item) {
                $childItems = $item->getChildren();

                if (count($childItems)) {
                    foreach ($childItems as $childItem) {
                        $stockItemToCheck[] = $childItem->getProduct()->getId();
                    }
                } else {
                    $stockItemToCheck[] = $item->getProduct()->getId();
                }

                foreach ($stockItemToCheck as $productId) {
                    $available = $this->stockState->checkQty($productId, $item->getQty());

                    if (!$available) {
                        $this->error        = 1;
                        $this->outOfStock   = 1;
                        $this->reason       = __('Error! Some of the products are out of stock.');

                        $this->readerWriter->createLog($productId, 'A product is not availavle, product id ');

                        return $this;
                    }
                }
            }
        }
        # /check of each item is in stock
        
        // iterate over Items and search for Subscriptions
        $this->items_data   = $this->paymentsPlans
            ->setQuoteId($this->quoteId)
            ->getProductPlanData();
        
        $this->subs_data    = $this->items_data['subs_data'] ?? [];
        $order_data         = $this->quote->getPayment()->getAdditionalInformation(Payment::CREATE_ORDER_DATA);
        
        $this->readerWriter->createLog([
            '$this->quoteId'    => $this->quoteId,
            '$order_data'       => $order_data,
            '$this->subs_data'  => $this->subs_data,
        ]);
        
        // will we call updateOrder?
        $callUpdateOrder    = false;
        $order_total        = (float) $this->config->getQuoteBaseTotal($this->quoteId);
        
        if (!empty($order_data)) {
            $callUpdateOrder = true;
        }
        
        if (empty($order_data['userTokenId']) && !empty($this->subs_data)) {
            $this->readerWriter->createLog('$order_data[userTokenId] is empty, call openOrder');
            $callUpdateOrder = false;
        }
        
        if (empty($order_data['transactionType'])) {
            $this->readerWriter->createLog('$order_data[transactionType] is empty, call openOrder');
            $callUpdateOrder = false;
        }
        
        // when the total is 0 transaction type must be Auth!
        if ($order_total == 0
            && (empty($order_data['transactionType'])
                || 'Auth' != $order_data['transactionType']
            )
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
        
        $this->orderId      = $req_resp['orderId'];
        $this->sessionToken = $req_resp['sessionToken'];
        $this->ooAmount     = $req_resp['merchantDetails']['customField1'];
        $this->subsData     = $this->subs_data;

        # save the session token in the Quote
        $add_info = [
            'sessionToken'      => $req_resp['sessionToken'],
            'clientRequestId'   => $req_resp['clientRequestId'],
            'orderId'           => $req_resp['orderId'],
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
        # /save the session token in the Quote
        
        $this->readerWriter->createLog([
            'quote id' => $this->quoteId,
            'quote CREATE_ORDER_DATA' => $this->quote->getPayment()->getAdditionalInformation(Payment::CREATE_ORDER_DATA),
        ]);
        
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
     * @param bool|null $isUserLogged
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
     * {@inheritdoc}
     *
     * @return string
     */
    protected function getResponseHandlerType()
    {
        return AbstractResponse::OPEN_ORDER_HANDLER;
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
        
        $quoteId    = empty($this->quoteId) ? $this->config->getCheckoutSession()->getQuoteId() : $this->quoteId;
        $amount     = $this->config->getQuoteBaseTotal($quoteId);
        
        $billing_address = $this->config->getQuoteBillingAddress($quoteId);
        
        if (!empty($this->billingAddress)) {
            $billing_address['firstName']   = $this->billingAddress['firstname'] ?: $billing_address['firstName'];
            $billing_address['lastName']    = $this->billingAddress['lastname'] ?: $billing_address['lastName'];
            
            if (is_array($this->billingAddress['street']) && !empty($this->billingAddress['street'])) {
                $billing_address['address'] = implode(' ', $this->billingAddress['street']);
            }
            
            $billing_address['phone']   = $this->billingAddress['telephone'] ?: $billing_address['phone'];
            $billing_address['zip']     = $this->billingAddress['postcode'] ?: $billing_address['zip'];
            $billing_address['city']    = $this->billingAddress['city'] ?: $billing_address['city'];
            $billing_address['country'] = $this->billingAddress['countryId'] ?: $billing_address['country'];
        }
        
        $params = [
            'clientUniqueId'    => $quoteId . '_' . time(),
            'currency'          => $this->config->getQuoteBaseCurrency($quoteId),
            'amount'            => $amount,
            'deviceDetails'     => $this->config->getDeviceDetails(),
            'shippingAddress'   => $this->config->getQuoteShippingAddress(),
            'billingAddress'    => $billing_address,
            'transactionType'   => (float) $amount == 0 ? 'Auth' : $this->config->getConfigValue('payment_action'),

            'urlDetails'        => [
                'notificationUrl'   => $this->config->getCallbackDmnUrl(null, null, [], $this->quoteId),
                'backUrl'           => $this->config->getBackUrl(),
                'successUrl'        => $this->config->getCallbackSuccessUrl($this->quoteId),
                'failureUrl'        => $this->config->getCallbackErrorUrl($this->quoteId),
                'pendingUrl'        => $this->config->getCallbackPendingUrl($this->quoteId),
            ],

            'merchantDetails'    => [
                // pass amount
                'customField1' => $amount,
                // subscription data
                'customField2' => isset($this->subs_data)
                    ? json_encode($this->subs_data) : '',
                // customField3 is passed in AbstractRequest
                // time when we create the request
                'customField4' => time(),
                // list of Order items
                'customField5' => isset($this->items_data['items_data'])
                    ? json_encode($this->items_data['items_data']) : '',
            ],
        ];
        
        # send userTokenId and save UPO
        # save upo is allowed only for registred user or Guests in case when 'save_guest_upos' is set to Yes.
        if (true === $this->isUserLogged
            || $this->config->isUserLogged()
            || 1 == $this->config->getConfigValue('save_guest_upos')
        ) {
            if (!is_null($this->saveUpo)) {
                if (1 == $this->saveUpo) {
                    $params['userTokenId'] = $params['billingAddress']['email'];
                }
            }
            elseif ('false' != $this->config->canSaveUpos()) {
                 $params['userTokenId'] = $params['billingAddress']['email'];
            }
        }
        
        // auto_close_popup
        if (1 == $this->config->getConfigValue('auto_close_popup')
            && 'redirect' != $this->config->getConfigValue('apm_window_type')
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
     * Get attribute options
     *
     * @param \Magento\Eav\Api\Data\AttributeInterface $attribute
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
