<?php

namespace Nuvei\Checkout\Controller\Paybylink;

use Magento\Checkout\Model\Session;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\UrlInterface;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Sales\Api\Data\OrderSearchResultInterfaceFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\StoreManagerInterface;
use Nuvei\Checkout\Model\Config as ModuleConfig;
use Nuvei\Checkout\Model\Payment;
use Nuvei\Checkout\Model\ReaderWriter;

/**
 * This class is a middleware. The client comes here with its payLink. 
 * Here we will create the Order and will redirect they to the Cashier.
 * 
 * In case the client cancel the payment and click on the Back button, they will
 * come also here, so we can prepare and show a message, before redirect them to
 * the final page.
 * 
 * @author Nuvei
 */
class Redirect extends Action
{
    private $moduleConfig;
    private $readerWriter;
    private $quoteIdMaskFactory;
    private $quoteRepository;
    private $orderRepository;
    private $cartManagement;
    private $checkoutSession;
    private $storeManager;
    private $params;
    private $quote;
    private $orderSearch;
    private $searchCriteriaBuilder;
    private $order;
     private $urlBuilder;

    public function __construct(
        Context $context,
        ModuleConfig $moduleConfig,
        ReaderWriter $readerWriter,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        CartRepositoryInterface $quoteRepository,
        OrderRepositoryInterface $orderRepository,
        CartManagementInterface $cartManagement,
        Session $checkoutSession,
        StoreManagerInterface $storeManager,
        OrderSearchResultInterfaceFactory $orderSearch,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        UrlInterface $urlBuilder
    ) {
        parent::__construct($context);

        $this->moduleConfig             = $moduleConfig;
        $this->readerWriter             = $readerWriter;
        $this->quoteIdMaskFactory       = $quoteIdMaskFactory;
        $this->quoteRepository          = $quoteRepository;
        $this->orderRepository          = $orderRepository;
        $this->cartManagement           = $cartManagement;
        $this->checkoutSession          = $checkoutSession;
        $this->storeManager             = $storeManager;
        $this->orderSearch              = $orderSearch;
        $this->searchCriteriaBuilder    = $searchCriteriaBuilder;
        $this->urlBuilder               = $urlBuilder;
    }

    public function execute()
    {
        $this->params       = $params
                            = $this->getRequest()->getParams();
        $cartUrl            = $this->moduleConfig->getBackUrl();
        $resultRedirect     = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        $this->readerWriter->createLog($params);

        try {
            $quoteIdMask        = $this->quoteIdMaskFactory->create()->load($params['quote'], 'masked_id');
            $quoteId            = $quoteIdMask->getQuoteId();
            $this->quote        = $quote
                                = $this->quoteRepository->get($quoteId);

            # Check if the Quote is still active. If it is not - then we have an Order.
            if (!$quote->getIsActive()) {
                return $this->checkExistingOrder($cartUrl);
            }

            // attach the Quote to the user session
            $this->checkoutSession->setQuoteId($quoteId);

            return $this->generateCashierLink();
        }
        catch (\Exception $ex) {
            $msg = __('Exception.');

            $this->readerWriter->createLog(
                [$ex->getMessage()],
                $msg,
                'WARN'
            );

            $resultRedirect->setUrl($cartUrl);

            return $resultRedirect;
        }
    }

    /**
     * @param bool $saveOrder
     * @return string
     */
    private function generateCashierLink($saveOrder = true)
    {
        $billingAddress     = $this->quote->getBillingAddress();
        $billingAddressData = $billingAddress->getData();
        $storeId            = $this->quote->getStoreId();
        $store              = $this->storeManager->getStore($storeId);

        // Save the Order form the Quote
        if ($saveOrder) {
            $orderId    = $this->cartManagement->placeOrder($this->quote->getId());
            $order      = $this->orderRepository->get($orderId);

            if ($order) {
                // Set custom state and status
                $order->setState(Order::STATE_PROCESSING);
                $order->setStatus(Payment::SC_PROCESSING);

                $this->orderRepository->save($order);
            }
        }
        // Get existing Order by the Quote in checkExistingOrder method
        else {
            $order = $this->order;
        }

        # Prepare the Cashier link
        $total_amount = (string) number_format( (float) $order->getGrandTotal(), 2, '.', '' );
        $shipping     = (string) number_format( (float) $order->getShippingInclTax(), 2, '.', '' );;
        $handling     = '0.00'; // put the tax here, because for Cashier the tax is in %
        $discount     = '0.00';

        $cashierParams = [
            'merchant_id'           => $this->moduleConfig->getMerchantId(),
            'merchant_site_id'      => $this->moduleConfig->getMerchantSiteId(),
            'merchant_unique_id'    => $order->getIncrementId(),
            'version'               => '4.0.0',
            'time_stamp'            => gmdate( 'Y-m-d H:i:s' ),

            'first_name'            => $billingAddressData['firstname'],
            'last_name'             => $billingAddressData['lastname'],
            'email'                 => $billingAddressData['email'],
            'country'               => $billingAddressData['country_id'],
            'state'                 => $billingAddressData['state'] ?? '',
            'city'                  => $billingAddressData['city'],
            'zip'                   => $billingAddressData['postcode'],
            'address1'              => $billingAddressData['street'],
            'phone1'                => $billingAddressData['telephone'],
            'merchantLocale'        => $store->getLocaleCode() ?? 'en',

            'notify_url'            => $this->moduleConfig->getCallbackDmnUrl($order->getIncrementId()),
            'success_url'           => $this->moduleConfig->getCallbackSuccessUrl($this->quote->getId()),
            'error_url'             => $this->moduleConfig->getCallbackErrorUrl($this->quote->getId()),
            'pending_url'           => $this->moduleConfig->getCallbackPendingUrl($this->quote->getId()),
            'back_url'              => $this->urlBuilder->getUrl(
                'nuvei_checkout/paybylink/redirect/',
                [
                    'quote'     => $this->params['quote'],
                    'status'    => 'canceled'
                ]
            ),

            'customField1'          => $total_amount,
            'customField2'          => $order->getBaseCurrencyCode(),
            'customField3'          => time(), // create time time()
            'customField4'          => 'payByLink',

            'currency'              => $order->getBaseCurrencyCode(),
            'total_tax'             => 0,
            'total_amount'          => $total_amount,
            'encoding'              => 'UTF-8',
            'webMasterId'           => $this->moduleConfig->getSourcePlatformField(),
            'sourceApplication'     => $this->moduleConfig->getSourceApplication(),
        ];

        // collect items
        $cnt                            = 1;
        $contol_amount                  = 0;
        $cashierParams['numberofitems'] = 0;

        foreach ($this->quote->getAllVisibleItems() as $item) {
            $cashierParams[ 'item_name_' . $cnt ] = str_replace(
                array( '"', "'" ),
                array( '', '' ),
                stripslashes( $item->getName() )
            );

            $cashierParams[ 'item_amount_' . $cnt ] = number_format( (float) round( $item->getRowTotalInclTax(), 2 ), 2, '.', '' );

            $cashierParams[ 'item_quantity_' . $cnt ] = (int) $item->getQty();

            $contol_amount += $cashierParams[ 'item_quantity_' . $cnt ] * $cashierParams[ 'item_amount_' . $cnt ];
            ++$cashierParams['numberofitems'];
            ++$cnt;
        }

        if ( $total_amount > $contol_amount ) {
            $handling = round( ( $total_amount - $contol_amount ), 2 );

            $this->readerWriter->createLog($handling, '$handling', 'DEBUG');
        }
        elseif ( $total_amount < $contol_amount ) {
            $discount += ( $contol_amount - $total_amount );

            $this->readerWriter->createLog($discount, '$discount', 'DEBUG');
        }

        $cashierParams['discount'] = number_format( (float) $discount, 2, '.', '' );
        $cashierParams['shipping'] = number_format( (float) $shipping, 2, '.', '' );
        $cashierParams['handling'] = number_format( (float) $handling, 2, '.', '' );

        $cashierParams['checksum'] = hash(
            $this->moduleConfig->getConfigValue('hash'),
            (string) $this->moduleConfig->getMerchantSecretKey() . implode( '', $cashierParams )
        );

        $payLink = $this->moduleConfig->isTestModeEnabled()
            ? ModuleConfig::SANDBOX_ENDPOINT : ModuleConfig::PROD_ENDPOINT;
        $payLink .= 'purchase.do?' . http_build_query( $cashierParams );

        $this->readerWriter->createLog($payLink, '$payLink', 'DEBUG');

        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        $resultRedirect->setUrl($payLink);

        return $resultRedirect;
    }

    /**
     * The client comes here when Cancel the payment, 
     * or when try to pay for already paid Order.
     *
     * @param string $cartUrl
     * @return string
     */
    private function checkExistingOrder($cartUrl)
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('quote_id', $this->quote->getId())
            ->create();

        $orderList      = $this->orderRepository->getList($searchCriteria);
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);

        # The Order exists
        if ($orderList->getTotalCount() > 0) {
            // Order exists for this quote
            $orders         = $orderList->getItems();
            $this->order    = $order
                            = reset($orders); // get the first order
            $state          = $order->getState();
            $status         = $order->getStatus();
            $incrementId    = $order->getIncrementId();
            $orderPayment   = $order->getPayment();

            $this->readerWriter->createLog(
                [
                    $state,
                    $status,
                    $incrementId,
                    $orderPayment,
                    $orderPayment->getAdditionalInformation(Payment::ORDER_TRANSACTIONS_DATA),
                    $this->quote->getId()
                ],
                'Order already exists for this quote',
                'DEBUG'
            );

            // with this, the client will see its Order ID
            $this->checkoutSession->setLastQuoteId($this->quote->getId())
                ->setLastSuccessQuoteId($this->quote->getId())
                ->setLastOrderId($order->getId())
                ->setLastRealOrderId($order->getIncrementId());

            # In case when the client cancel the payment.
            if (!empty($this->params['status']) && 'canceled' == $this->params['status']) {
                $this->messageManager->addWarningMessage(
                    __('Your order #%1 is awaiting payment. Please, open you payment link again to finish the payment!', $incrementId)
                );
                
                $this->readerWriter->createLog($incrementId, 'The Order was canceled. We will show the message to the client.');

                $resultRedirect->setUrl($this->moduleConfig->getBackUrl());

                return $resultRedirect;
            }

            # The payment wait to be paid - provide direct link to the Cashier
            if ($status == Payment::SC_PROCESSING
                && null == $orderPayment->getAdditionalInformation(Payment::ORDER_TRANSACTIONS_DATA)
            ) {
                return $this->generateCashierLink(false);
            }

            $this->readerWriter->createLog('The Order is finished. No more payments are allowed!');
        }

        # Unexpected error - missing Order.
        $this->readerWriter->createLog(
            'The Quote is inactive, but still there is no Order.'
        );
        
        // add warrning message for the client
        $this->messageManager->addWarningMessage(__(
            'Your payment link is invalid or expired. Please return to the store and create a new order.'
        ));
        
        $resultRedirect->setUrl($this->moduleConfig->getBackUrl());

        return $resultRedirect;
    }

}
