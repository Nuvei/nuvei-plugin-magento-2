<?php

namespace Nuvei\Checkout\Controller\Payment\Callback;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Exception\PaymentException;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;

/**
 * Nuvei Checkout redirect success controller.
 */
class Complete extends Action implements CsrfAwareActionInterface
{
    /**
     * @var DataObjectFactory
     */
    private $dataObjectFactory;

    /**
     * @var CartManagementInterface
     */
    private $cartManagement;

    /**
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var Onepage
     */
    private $onepageCheckout;
    
    private $readerWriter;
    private $orderFactory;
    private $order;
    private $orderPayment;
    private $cart;
    private $moduleConfig;

    /**
     * Object constructor.
     *
     * @param Context                   $context
     * @param DataObjectFactory         $dataObjectFactory
     * @param CartManagementInterface   $cartManagement
     * @param Cart                      $cart
     * @param CheckoutSession           $checkoutSession
     * @param Onepage                   $onepageCheckout
     * @param OrderFactory              $orderFactory
     * @param Config                    $moduleConfig
     * @param ReaderWriter              $readerWriter
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\DataObjectFactory $dataObjectFactory,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        \Magento\Checkout\Model\Cart $cart,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Checkout\Model\Type\Onepage $onepageCheckout,
        \Magento\Sales\Model\OrderFactory $orderFactory,
        \Nuvei\Checkout\Model\Config $moduleConfig,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
    ) {
        parent::__construct($context);

        $this->dataObjectFactory    = $dataObjectFactory;
        $this->cartManagement       = $cartManagement;
        $this->checkoutSession      = $checkoutSession;
        $this->onepageCheckout      = $onepageCheckout;
        $this->readerWriter         = $readerWriter;
        $this->orderFactory         = $orderFactory;
        $this->cart                 = $cart;
        $this->moduleConfig         = $moduleConfig;
    }
    
    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(
        RequestInterface $request
    ): ?InvalidRequestException {
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
     * @return ResultInterface
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function execute()
    {
        $params = $this->getRequest()->getParams();
        
        $this->readerWriter->createLog($params, 'Complete class params');
        
        $resultRedirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        $form_key       = $params['form_key'];
        $quote          = $this->cart->getQuote();
        $isOrderPlaced  = false;

        try {
//            if ((int) $this->checkoutSession->getQuote()->getIsActive() === 1) {
            if ((int) $quote->getIsActive() === 1) {
                // if the option for save the order in the Redirect is ON, skip placeOrder !!!
                $result = $this->placeOrder();
                
                if ($result->getSuccess() !== true) {
                    $this->readerWriter->createLog(
                        [
                            'message'           => $result->getMessage(),
//                            'payment method'    => $this->checkoutSession->getQuote()->getPayment()->getMethod(),
                            'payment method'    => $quote->getPayment()->getMethod(),
                        ],
                        'Complete Callback error - place order error',
                        'WARN'
                    );

                    throw new PaymentException(__($result->getMessage()));
                }
                
                $isOrderPlaced = true;
            }
            else {
                $this->readerWriter->createLog(
                    'Attention - the Quote is not active! '
                    . 'The Order can not be created here. May be it is already placed.'
                );
                
                // TODO - try to get the Order by its Quote ID
            }
            
            if (isset($params['Status'])
                && !in_array(strtolower($params['Status']), ['approved', 'success'])
            ) {
                throw new PaymentException(__('Your payment failed.'));
            }
        } catch (PaymentException $e) {
            $this->readerWriter->createLog($e->getMessage(), 'Complete Callback Process Error:');
            $this->messageManager->addErrorMessage($e->getMessage());
            
            $resultRedirect->setUrl(
                $this->_url->getUrl('checkout/cart')
                . (!empty($form_key) ? '?form_key=' . $form_key : '')
            );
            
            return $resultRedirect;
        }

        // go to success page
        $resultRedirect->setUrl(
            $this->_url->getUrl('checkout/onepage/success/')
            . (!empty($form_key) ? '?form_key=' . $form_key : '')
        );
        
        return $resultRedirect;
        
        // go to Cashier
//        return $this->generateCashierLink();
    }

    /**
     * Place order.
     */
    private function placeOrder()
    {
        $result = $this->dataObjectFactory->create();

        try {
            /**
             * Current workaround depends on Onepage checkout model defect
             * Method Onepage::getCheckoutMethod performs setCheckoutMethod
             */
            $this->onepageCheckout->getCheckoutMethod();
            
//            $orderId = $this->cartManagement->placeOrder($this->getQuoteId());
            $orderId = $this->cartManagement->placeOrder($this->moduleConfig->getQuoteId());
            
            $result
                ->setData('success', true)
                ->setData('order_id', $orderId);

            $this->_eventManager->dispatch(
                'nuvei_place_order',
                [
                    'result' => $result,
                    'action' => $this,
                ]
            );
        } catch (\Exception $exception) {
            $this->readerWriter->createLog(
                [
                    'Message'   => $exception->getMessage(),
                    'Trace'     => $exception->getTrace(),
                ],
                'Success Callback Response Exception',
                'WARN'
            );
            
            $result
                ->setData('error', true)
                ->setData(
                    'message',
                    __(
                        'An error occurred on the server. '
                        . 'Please check your Order History and if the Order is not there, try to place it again!'
                    )
                );
        }

        return $result;
    }

    /**
     * @return int
     * @throws PaymentException
     * 
     * @deprecated since version 3.1.10
     */
    private function getQuoteId()
    {
        $quoteId = (int)$this->getRequest()->getParam('quote');

        if ((int)$this->checkoutSession->getQuoteId() === $quoteId) {
            return $quoteId;
        }
        
        $this->readerWriter->createLog('Success error: Session has expired, order has been not placed.');

        throw new PaymentException(
            __('Session has expired, order has been not placed.')
        );
    }
    
    private function generateCashierLink()
    {
        $addresses     = $nuvei_helper->get_addresses( array( 
            'billing_address' => $this->order->get_address()
        ) );
		$total_amount  = (string) number_format( (float) $this->order->get_total(), 2, '.', '' );
		$shipping      = '0.00';
		$handling      = '0.00'; // put the tax here, because for Cashier the tax is in %
		$discount      = '0.00';

		$items_data['items'] = array();

		foreach ( $this->order->get_items() as $item ) {
			$items_data['items'][] = $item->get_data();
		}

		Nuvei_Pfw_Logger::write( $items_data, 'get_cashier_url() $items_data.' );

		$products_data = $nuvei_helper->get_products( $items_data );

		// check for the totals, when want Cashier URL totals is 0.
		if ( empty( $products_data['totals'] ) ) {
			$products_data['totals'] = $total_amount;
		}

		Nuvei_Pfw_Logger::write( $products_data, 'get_cashier_url() $products_data.' );

		$currency = get_woocommerce_currency();

		$params = array(
			'merchant_id'           => trim( (int) $this->settings['merchantId'] ),
			'merchant_site_id'      => trim( (int) $this->settings['merchantSiteId'] ),
			'merchant_unique_id'    => $this->order->get_id(),
			'version'               => '4.0.0',
			'time_stamp'            => gmdate( 'Y-m-d H:i:s' ),

			'first_name'        => urldecode( $addresses['billingAddress']['firstName'] ),
			'last_name'         => $addresses['billingAddress']['lastName'],
			'email'             => $addresses['billingAddress']['email'],
			'country'           => $addresses['billingAddress']['country'],
			'state'             => $addresses['billingAddress']['state'],
			'city'              => $addresses['billingAddress']['city'],
			'zip'               => $addresses['billingAddress']['zip'],
			'address1'          => $addresses['billingAddress']['address'],
			'phone1'            => $addresses['billingAddress']['phone'],
			'merchantLocale'    => get_locale(),

			'notify_url'        => Nuvei_Pfw_String::get_notify_url( $this->settings ),
			'success_url'       => $success_url,
			'error_url'         => $error_url,
			'pending_url'       => $success_url,
			'back_url'          => ! empty( $back_url ) ? $back_url : wc_get_checkout_url(),

			'customField1'      => $total_amount,
			'customField2'      => $currency,
			'customField3'      => time(), // create time time()

			'currency'          => $currency,
			'total_tax'         => 0,
			'total_amount'      => $total_amount,
			'encoding'          => 'UTF-8',
		);

		if ( 1 == $this->settings['use_upos'] ) {
			$params['user_token_id'] = $addresses['billingAddress']['email'];
		}

		// check for subscription data
		if ( ! empty( $products_data['subscr_data'] ) ) {
			$params['user_token_id']       = $addresses['billingAddress']['email'];
			$params['payment_method']      = 'cc_card'; // only cards are allowed for Subscribtions
			$params['payment_method_mode'] = 'filter';
		}

		// create one combined item
		if ( 1 == $this->get_option( 'combine_cashier_products' ) ) {
			$params['item_name_1']     = 'WC_Cashier_Order';
			$params['item_quantity_1'] = 1;
			$params['item_amount_1']   = $total_amount;
			$params['numberofitems']   = 1;
		} else { // add all the items
			$cnt                        = 1;
			$contol_amount              = 0;
			$params['numberofitems']    = 0;

			foreach ( $products_data['products_data'] as $item ) {
				$params[ 'item_name_' . $cnt ]     = str_replace( array( '"', "'" ), array( '', '' ), stripslashes( $item['name'] ) );
				$params[ 'item_amount_' . $cnt ]   = number_format( (float) round( $item['price'], 2 ), 2, '.', '' );
				$params[ 'item_quantity_' . $cnt ] = (int) $item['quantity'];

				$contol_amount += $params[ 'item_quantity_' . $cnt ] * $params[ 'item_amount_' . $cnt ];
				$params['numberofitems']++;
				$cnt++;
			}

			Nuvei_Pfw_Logger::write( $contol_amount, '$contol_amount' );

			if ( ! empty( $products_data['totals']['shipping_total'] ) ) {
				$shipping = round( $products_data['totals']['shipping_total'], 2 );
			}
			if ( ! empty( $products_data['totals']['shipping_tax'] ) ) {
				$shipping += round( $products_data['totals']['shipping_tax'], 2 );
			}

			if ( ! empty( $products_data['totals']['discount_total'] ) ) {
				$discount = round( $products_data['totals']['discount_total'], 2 );
			}

			$contol_amount += ( $shipping - $discount );

			if ( $total_amount > $contol_amount ) {
				$handling = round( ( $total_amount - $contol_amount ), 2 );

				Nuvei_Pfw_Logger::write( $handling, '$handling' );
			} elseif ( $total_amount < $contol_amount ) {
				$discount += ( $contol_amount - $total_amount );

				Nuvei_Pfw_Logger::write( $discount, '$discount' );
			}
		}

		$params['discount'] = number_format( (float) $discount, 2, '.', '' );
		$params['shipping'] = number_format( (float) $shipping, 2, '.', '' );
		$params['handling'] = number_format( (float) $handling, 2, '.', '' );

		$params['checksum'] = hash(
			$this->settings['hash_type'],
			trim( (string) $this->settings['secret'] ) . implode( '', $params )
		);

		Nuvei_Pfw_Logger::write( $params, 'get_cashier_url() $params.' );

		$url  = 'yes' == $this->settings['test'] ? 'https://ppp-test.safecharge.com' : 'https://secure.safecharge.com';
		$url .= '/ppp/purchase.do?' . http_build_query( $params );
        
        return $url;
    }
}
