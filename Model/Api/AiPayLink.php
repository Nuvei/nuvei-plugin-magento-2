<?php

namespace Nuvei\Checkout\Model\Api;

use Magento\Framework\Webapi\Rest\Request;
use Magento\Framework\Exception\LocalizedException;
use Nuvei\Checkout\Api\AiPayLinkInterface;
use Nuvei\Checkout\Model\Config;
use Nuvei\Checkout\Model\ReaderWriter;
use Magento\Quote\Model\QuoteFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Group;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\QuoteIdMaskFactory;
use Magento\Framework\UrlInterface;

/**
 * Create Quote and return the pay link to the sender.
 * 
 * @author Nuvei
 */
class AiPayLink implements AiPayLinkInterface
{
    private $readerWriter;
    private $requestFactory;
    private $moduleConfig;
    private $apiRequest;
    private $incomingParams;
    private $quoteFactory;
    private $storeManager;
    private $productRepository;
    private $quoteRepository;
    private $quoteIdMaskFactory;
    private $urlBuilder;

    public function __construct(
        Config $moduleConfig,
        Request $apiRequest,
        ReaderWriter $readerWriter,
        QuoteFactory $quoteFactory,
        StoreManagerInterface $storeManager,
        ProductRepositoryInterface $productRepository,
        CartRepositoryInterface $quoteRepository,
        QuoteIdMaskFactory $quoteIdMaskFactory,
        UrlInterface $urlBuilder
    ) {
        $this->readerWriter         = $readerWriter;
        $this->moduleConfig         = $moduleConfig;
        $this->apiRequest           = $apiRequest;
        $this->quoteFactory         = $quoteFactory;
        $this->storeManager         = $storeManager;
        $this->productRepository    = $productRepository;
        $this->quoteRepository      = $quoteRepository;
        $this->quoteIdMaskFactory   = $quoteIdMaskFactory;
        $this->urlBuilder           = $urlBuilder;
    }

    /**
     * Example of expected JSON structure:
     *
     * {
            "billing_address": {
                "firstname": "first",
                "lastname": "last",
                "street": "tsarigradsko",
                "city": "sofia",
                "postcode": "1234",
                "telephone": "1234567",
                "region": "sofia",
                "state": "state",
                "country": "BG",
                "email": "miroslavs@nuvei.com"
            },
            "shipping_address": {
                "firstname": "first",
                "lastname": "last",
                "street": "tsarigradsko",
                "city": "sofia",
                "postcode": "1234",
                "telephone": "1234567",
                "region": "sofia",
                "country": "BG"
            },
            "items": [
                {
                    "sku": "24-WB05",
                    "quantity": 1
                }
            ]
        }
     */
    public function generate()
    {
        // check for errors
        if (!$this->moduleConfig->getConfigValue('active')) {
            $msg = 'Mudule is not active.';
            $this->readerWriter->createLog($msg);

            throw new LocalizedException(__($msg));
        }

        $this->incomingParams = $this->apiRequest->getBodyParams();

        $this->readerWriter->createLog(
            $this->incomingParams,
            'The Incoming data.',
            'DEBUG'
        );

        // validate the parameters
        $this->validateInputData($this->incomingParams);

        $payLinkData = $this->createQuote();

        // success
        if (isset($payLinkData['responseCode']) && 200 == $payLinkData['responseCode']) {
            http_response_code(200);
            header('Content-Type: application/json');

            exit(json_encode([
                "status"        => "success",
                "paylink_url"   => $payLinkData['payLink'],
            ]));
        }

        // error
        http_response_code($payLinkData['responseCode']);

        exit(json_encode([
            "status"    => "error",
            "message"   => $payLinkData['message'],
        ]));
    }

    private function createQuote()
    {
        # Prepare quote
        $store      = $this->storeManager->getStore();
        $quote      = $this->quoteFactory->create();
        $addedItems = 0;

        $quote->setStore($store);
        $quote->setCustomerIsGuest(true);
        $quote->setCustomerGroupId(Group::NOT_LOGGED_IN_ID);

        try {
            $quote->setCustomerEmail($this->incomingParams['billing_address']['email']);
            $quote->setIsActive(true);

            // Load products by SKU and add them to the quote
            foreach ($this->incomingParams['items'] as $itemData) {
                $product        = $this->productRepository->get($itemData['sku']);
                $buyRequestData = ['qty' => $itemData['quantity']];

                // TODO - check if the product is with rebilling

                switch ($product->getTypeId()) {
                    case 'configurable':
                        if (isset($itemData['super_attribute'])) {
                            $buyRequestData['super_attribute'] = $itemData['super_attribute'];
                        }
                        break;

                    case 'bundle':
                        if (isset($itemData['bundle_option'])) {
                            $buyRequestData['bundle_option']        = $itemData['bundle_option'];
                            $buyRequestData['bundle_option_qty']    = $itemData['bundle_option_qty'] ?? [];
                        }
                        break;

                    case 'grouped':
                        if (isset($itemData['super_group'])) {
                            $buyRequestData['super_group'] = $itemData['super_group'];
                        }
                        break;
                }

                $buyRequest = new \Magento\Framework\DataObject($buyRequestData);
                $item       = $quote->addProduct($product, $buyRequest);

                // error - the item is not an object but error message
                if (is_string($item)) {
                    $this->readerWriter->createLog(
                        [
                            'item data'     => $itemData,
                            'error message' => $item,
                        ],
                        'This item was not added to the Quote.',
                        'DEBUG'
                    );

                    continue;
                }

                $addedItems++;
            }

            // error - no added products in the quote
            if (0 == $addedItems) {
                $msg = __('The items were not added to the Cart. Abord the Order process.');

                $this->readerWriter->createLog($e, $msg, 'DEBUG');

                return [
                    'message'       => $msg,
                    'responseCode'  => 422,
                ];
            }

            // Set billing address from incomingParams
            $billingAddressData = [
                'firstname'     => $this->incomingParams['billing_address']['firstname'] ?? '',
                'lastname'      => $this->incomingParams['billing_address']['lastname'] ?? '',
                'street'        => $this->incomingParams['billing_address']['street'] ?? '',
                'city'          => $this->incomingParams['billing_address']['city'] ?? '',
                'postcode'      => $this->incomingParams['billing_address']['postcode'] ?? '',
                'country_id'    => $this->incomingParams['billing_address']['country'] ?? '',
                'region'        => $this->incomingParams['billing_address']['region'] ?? '',
                'telephone'     => $this->incomingParams['billing_address']['telephone'] ?? '',
                'email'         => $this->incomingParams['billing_address']['email'],
            ];
            $billingAddress = $quote->getBillingAddress();
            $billingAddress->addData($billingAddressData);

            // Set shipping address (placeholder, replace with actual shipping address data later)
            if (!$quote->isVirtual()) {
                $shippingAddressData = [
                    'firstname'     => $this->incomingParams['shipping_address']['firstname'] ?? '',
                    'lastname'      => $this->incomingParams['shipping_address']['lastname'] ?? '',
                    'street'        => $this->incomingParams['shipping_address']['street'] ?? '',
                    'city'          => $this->incomingParams['shipping_address']['city'] ?? '',
                    'postcode'      => $this->incomingParams['shipping_address']['postcode'] ?? '',
                    'country_id'    => $this->incomingParams['shipping_address']['country'] ?? '',
                    'region'        => $this->incomingParams['shipping_address']['region'] ?? '',
                    'telephone'     => $this->incomingParams['shipping_address']['telephone'] ?? '',
                ];
                $shippingAddress = $quote->getShippingAddress();
                $shippingAddress->addData($shippingAddressData);

                // add shipping method
                $shippingAddress->setCollectShippingRates(true)->collectShippingRates();

                $rates              = $shippingAddress->getGroupedAllShippingRates();
                $availableMethods   = [];
                $selectedMethod     = '';

                // collect the methods' codes
                foreach ($rates as $carrierRates) {
                    foreach ($carrierRates as $rate) {
                        $availableMethods[] = $rate->getCode();
                    }
                }

                // choose the method
                if (in_array('freeshipping_freeshipping', $availableMethods)) {
                    $selectedMethod = 'freeshipping_freeshipping';
                }
                elseif (in_array('flatrate_flatrate', $availableMethods)) {
                    $selectedMethod = 'flatrate_flatrate';
                }
                else {
                    $selectedMethod = $availableMethods[0];
                }

                $shippingAddress->setShippingMethod($selectedMethod);
                $quote->collectTotals();
            }

            $quote->getPayment()->setMethod('nuvei');
            $quote->collectTotals();
            $this->quoteRepository->save($quote);

            // build the payLink who will point back to the adapter
            $quoteIdMask = $this->quoteIdMaskFactory->create()->load($quote->getId(), 'quote_id');
            
            if (!$quoteIdMask->getMaskedId()) {
                $quoteIdMask->setQuoteId($quote->getId())->save();
            }
            
            $maskedId   = $quoteIdMask->getMaskedId();
            $payLink    = $this->urlBuilder->getUrl(
                'nuvei_checkout/paybylink/redirect/',
                ['quote' => $maskedId]
            );

            return [
                'payLink'       => $payLink,
                'responseCode'  => 200,
            ];
        }
        catch (NoSuchEntityException $e) {
            // Handle product not found
            $msg = __('GetPaymentPageUrl Exception.');

            $this->readerWriter->createLog(
                [$e->getMessage()],
                $msg,
                'WARN'
            );

            return [
                'message'       => $msg,
                'responseCode'  => 500,
            ];
        }
        catch (LocalizedException $e) {
            // Handle Magento-specific errors
            $msg = __('GetPaymentPageUrl LocalizedException.');

            $this->readerWriter->createLog(
                [$e->getMessage()],
                $msg,
                'WARN'
            );

            return [
                'message'       => $msg,
                'responseCode'  => 500,
            ];
        }
        catch (\Exception $e) {
            // Handle any other errors
            $msg = __('GetPaymentPageUrl Exception.');

            $this->readerWriter->createLog(
                [$e->getMessage()],
                $msg,
                'WARN'
            );

            return [
                'message'       => $msg,
                'responseCode'  => 500,
            ];
        }
    }

    /**
     * @param array $params
     * @return void
     * @throws LocalizedException
     */
    private function validateInputData($params)
    {
        if (empty($params['billing_address']) || !is_array($params['billing_address'])) {
            throw new LocalizedException(__('Invalid or missing billing_address.'));
        }
        if (empty($params['billing_address']['email'])
            || !filter_var($params['billing_address']['email'], FILTER_VALIDATE_EMAIL)
        ) {
            throw new LocalizedException(__('Invalid or missing billing_address - email.'));
        }
        if (empty($params['shipping_address']) || !is_array($params['shipping_address'])) {
            throw new LocalizedException(__('Invalid or missing shipping_address.'));
        }

        // validate items
        if (empty($params['items']) || !is_array($params['items'])) {
            throw new LocalizedException(__('Invalid or missing items block.'));
        }
        else {
            foreach ($params['items'] as $itemData) {
                if (empty($itemData['sku'])) {
                    throw new LocalizedException(__('Invalid or missing item sku.'));
                }
                if (empty($itemData['quantity']) || !is_numeric($itemData['quantity'])) {
                    throw new LocalizedException(__('Invalid or missing item quantity.'));
                }
            }
        }

        // the date is valid
        return;
    }

}
