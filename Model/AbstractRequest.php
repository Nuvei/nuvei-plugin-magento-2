<?php

namespace Nuvei\Checkout\Model;

use Magento\Framework\Exception\PaymentException;
use Magento\Quote\Model\Quote;

/**
 * Nuvei Checkout abstract request model.
 */
abstract class AbstractRequest
{
    /**
     * Payment gateway endpoints.
     */
    const LIVE_ENDPOINT = 'https://secure.safecharge.com/ppp/';
    const TEST_ENDPOINT = 'https://ppp-test.nuvei.com/ppp/';

    /**
     * Payment gateway methods.
     */
    const PAYMENT_SETTLE_METHOD                 = 'settleTransaction';
    const GET_USER_DETAILS_METHOD               = 'getUserDetails';
    const PAYMENT_REFUND_METHOD                 = 'refundTransaction';
    const PAYMENT_VOID_METHOD                   = 'voidTransaction';
    const OPEN_ORDER_METHOD                     = 'openOrder';
    const UPDATE_ORDER_METHOD                   = 'updateOrder';
    const PAYMENT_APM_METHOD                    = 'payment';
    const GET_MERCHANT_PAYMENT_METHODS_METHOD   = 'getMerchantPaymentMethods';
    const GET_UPOS_METHOD                       = 'getUserUPOs';
    const GET_MERCHANT_PAYMENT_PLANS_METHOD     = 'getPlansList';
    const CREATE_MERCHANT_PAYMENT_PLAN          = 'createPlan';
    const CREATE_SUBSCRIPTION_METHOD            = 'createSubscription';
    const CANCEL_SUBSCRIPTION_METHOD            = 'cancelSubscription';
    const GET_SESSION_TOKEN                     = 'getSessionToken';
    const DELETE_UPOS_METHOD                    = 'deleteUPO';

    protected $readerWriter;
    protected $config;
    
    /**
     * @var Curl
     */
    protected $curl;

    /**
     * @var ResponseInterface
     */
    protected $responseFactory;

    /**
     * @var int
     */
    protected $requestId;
    
    // array details to validate request parameters
    private $params_validation = [
        // deviceDetails
        'deviceType' => [
            'length' => 10,
            'flag'    => FILTER_DEFAULT
        ],
        'deviceName' => [
            'length' => 255,
            'flag'    => FILTER_DEFAULT
        ],
        'deviceOS' => [
            'length' => 255,
            'flag'    => FILTER_DEFAULT
        ],
        'browser' => [
            'length' => 255,
            'flag'    => FILTER_DEFAULT
        ],
        'ipAddress' => [
            'length' => 15,
            'flag'    => FILTER_VALIDATE_IP
        ],
        // deviceDetails END
        
        // userDetails, shippingAddress, billingAddress
        'firstName' => [
            'length' => 30,
            'flag'    => FILTER_DEFAULT
        ],
        'lastName' => [
            'length' => 40,
            'flag'    => FILTER_DEFAULT
        ],
        'address' => [
            'length' => 60,
            'flag'    => FILTER_DEFAULT
        ],
        'cell' => [
            'length' => 18,
            'flag'    => FILTER_DEFAULT
        ],
        'phone' => [
            'length' => 18,
            'flag'    => FILTER_DEFAULT
        ],
        'zip' => [
            'length' => 10,
            'flag'    => FILTER_DEFAULT
        ],
        'city' => [
            'length' => 30,
            'flag'    => FILTER_DEFAULT
        ],
        'country' => [
            'length' => 20,
            'flag'    => FILTER_DEFAULT
        ],
        'state' => [
            'length' => 2,
            'flag'    => FILTER_DEFAULT
        ],
        'county' => [
            'length' => 255,
            'flag'    => FILTER_DEFAULT
        ],
        // userDetails, shippingAddress, billingAddress END
        
        // specific for shippingAddress
        'shippingCounty' => [
            'length' => 255,
            'flag'    => FILTER_DEFAULT
        ],
        'addressLine2' => [
            'length' => 50,
            'flag'    => FILTER_DEFAULT
        ],
        'addressLine3' => [
            'length' => 50,
            'flag'    => FILTER_DEFAULT
        ],
        // specific for shippingAddress END
        
        // urlDetails
        'successUrl' => [
            'length' => 1000,
            'flag'    => FILTER_VALIDATE_URL
        ],
        'failureUrl' => [
            'length' => 1000,
            'flag'    => FILTER_VALIDATE_URL
        ],
        'pendingUrl' => [
            'length' => 1000,
            'flag'    => FILTER_VALIDATE_URL
        ],
        'notificationUrl' => [
            'length' => 1000,
            'flag'    => FILTER_VALIDATE_URL
        ],
        // urlDetails END
    ];
    
    private $params_validation_email = [
        'length'    => 79,
        'flag'      => FILTER_VALIDATE_EMAIL
    ];

    /**
     * Object constructor.
     *
     * @param Config            $config
     * @param Curl              $curl
     * @param Factory           $responseFactory
     * @param ReaderWriter      $readerWriter
     */
    public function __construct(
        \Nuvei\Checkout\Model\Config $config,
        \Nuvei\Checkout\Lib\Http\Client\Curl $curl,
        \Nuvei\Checkout\Model\Response\Factory $responseFactory,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        $this->config           = $config;
        $this->curl             = $curl;
        $this->responseFactory  = $responseFactory;
        $this->readerWriter     = $readerWriter;
    }

    /**
     * @return AbstractResponse
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws PaymentException
     */
    public function process()
    {
        $this->sendRequest();

        return $this
            ->getResponseHandler()
            ->process();
    }

    /**
     * @return int
     */
    protected function getRequestId()
    {
        return $this->requestId;
    }

    /**
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function initRequest()
    {
        if ($this->requestId === null) {
            $this->requestId = date('YmdHis') . '_' . uniqid();
        }
    }
    
    /**
     * Return full endpoint to particular method for request call.
     *
     * @return string
     */
    protected function getEndpoint()
    {
        $endpoint = self::LIVE_ENDPOINT;
        
        if ($this->config->isTestModeEnabled() === true) {
            $endpoint = self::TEST_ENDPOINT;
        }
        
        $endpoint   .= 'api/v1/';
        $method     = $this->getRequestMethod();

        return $endpoint . $method . '.do';
    }

    /**
     * Return method for request call.
     *
     * @return string
     */
    abstract protected function getRequestMethod();

    /**
     * Return request headers.
     *
     * @return array
     */
    protected function getHeaders()
    {
        return [
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Return request params.
     *
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getParams()
    {
        $this->initRequest();
        $this->readerWriter->createLog('getParams()');
        $params = [
            'merchantId'        => $this->config->getMerchantId(),
            'merchantSiteId'    => $this->config->getMerchantSiteId(),
            'clientRequestId'   => (string) $this->getRequestId(),
            'timeStamp'         => date('YmdHis'),
            'webMasterId'       => $this->config->getSourcePlatformField(),
            'sourceApplication' => $this->config->getSourceApplication(),
            // some of them are set in the child classes
            'merchantDetails'   => [
                'customField4' => time(),
            ],
        ];

        return $params;
    }

    /**
     * @return array
     * @throws PaymentException
     */
    protected function prepareParams()
    {
        $params = $this->getParams();
        
        // validate params
        $this->readerWriter->createLog('prepareParams(), before validate request parameters.');
        
        // directly check the mails
        if (isset($params['billingAddress']['email'])) {
            if (!filter_var($params['billingAddress']['email'], $this->params_validation_email['flag'])) {
                $this->readerWriter->createLog('REST API ERROR: The parameter Billing Address Email is not valid.');
                
                return [
                    'status' => 'ERROR',
                    'message' => 'The parameter Billing Address Email is not valid.'
                ];
            }
            
            if (strlen($params['billingAddress']['email']) > $this->params_validation_email['length']) {
                $this->readerWriter->createLog('REST API ERROR: The parameter Billing Address Email must be maximum '
                    . $this->params_validation_email['length'] . ' symbols.');
                
                return [
                    'status' => 'ERROR',
                    'message' => 'The parameter Billing Address Email must be maximum '
                        . $this->params_validation_email['length'] . ' symbols.'
                ];
            }
        }
        
        if (isset($params['shippingAddress']['email'])) {
            if (!filter_var($params['shippingAddress']['email'], $this->params_validation_email['flag'])) {
                $this->readerWriter->createLog('REST API ERROR: The parameter Shipping Address Email is not valid.');
                
                return [
                    'status' => 'ERROR',
                    'message' => 'The parameter Shipping Address Email is not valid.'
                ];
            }
            
            if (strlen($params['shippingAddress']['email']) > $this->params_validation_email['length']) {
                $this->readerWriter->createLog('REST API ERROR: The parameter Shipping Address Email must be maximum '
                    . $this->params_validation_email['length'] . ' symbols.');
                
                return [
                    'status' => 'ERROR',
                    'message' => 'The parameter Shipping Address Email must be maximum '
                        . $this->params_validation_email['length'] . ' symbols.'
                ];
            }
        }
        // directly check the mails END
        
        foreach ($params as $key1 => $val1) {
            if (!is_array($val1) && !empty($val1) && array_key_exists($key1, $this->params_validation)) {
                $new_val = $val1;
                
                if (mb_strlen($val1) > $this->params_validation[$key1]['length']) {
                    $new_val = mb_substr($val1, 0, $this->params_validation[$key1]['length']);
                    
                    $this->readerWriter->createLog($key1, 'Limit');
                }
                
                $filtered_val = filter_var($new_val, $this->params_validation[$key1]['flag']);
                
                if (!empty($filtered_val)) {
                    $params[$key1] = str_replace('\\', ' ', $filtered_val);
                }
            } elseif (is_array($val1) && !empty($val1)) {
                foreach ($val1 as $key2 => $val2) {
                    if (!is_array($val2) && !empty($val2) && array_key_exists($key2, $this->params_validation)) {
                        $new_val = $val2;

                        if (mb_strlen($val2) > $this->params_validation[$key2]['length']) {
                            $new_val = mb_substr($val2, 0, $this->params_validation[$key2]['length']);
                            
                            $this->readerWriter->createLog($key2, 'Limit');
                        }
                        
                        $filtered_val = filter_var($new_val, $this->params_validation[$key2]['flag']);
                
                        if (!empty($filtered_val)) {
                            $params[$key1][$key2] = str_replace('\\', ' ', $filtered_val);
                        }
                    }
                }
            }
        }
        # validate parameters END
        
        $checksumKeys = $this->getChecksumKeys();
        if (empty($checksumKeys)) {
            return $params;
        }
        
        $concat = '';
        
        foreach ($checksumKeys as $checksumKey) {
            if (!isset($params[$checksumKey])) {
                // additional check for the new plugin option
                if ('urlDetails' == $checksumKey
                    && 1 == $this->config->getConfigValue('disable_notify_url')
                ) {
                    continue;
                }
                
                $msg = __('Required key %1 for checksum calculation is missing.', $checksumKey);
                $this->readerWriter->createLog($msg);
                throw new PaymentException($msg);
            }

            if (is_array($params[$checksumKey])) {
                foreach ($params[$checksumKey] as $subVal) {
                    $concat .= $subVal;
                }
            } else {
                $concat .= $params[$checksumKey];
            }
        }

        $concat .= $this->config->getMerchantSecretKey();
//        $concat = utf8_encode($concat);
        $concat = $concat;
        
        $params['checksum'] = hash($this->config->getConfigValue('hash'), $concat);

        return $params;
    }

    /**
     * Return keys required to calculate checksum. Keys order is relevant.
     *
     * @return array
     */
    protected function getChecksumKeys()
    {
        return [
            'merchantId',
            'merchantSiteId',
            'clientRequestId',
            'timeStamp',
        ];
    }

    /**
     * Function sendRequest
     *
     * @param bool $continue_process    When is true return the response parameters to the sender
     * @param bool $accept_error_status When is true, do not throw exception if get error response
     *
     * @return AbstractRequest
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function sendRequest($continue_process = false, $accept_error_status = false)
    {
        $endpoint   = $this->getEndpoint();
        $headers    = $this->getHeaders();
        $params     = $this->prepareParams();

        $this->curl->setHeaders($headers);

        $this->readerWriter->createLog([
            'Request Endpoint'  => $endpoint,
            'Request params'    => $params
        ]);
        
        $this->curl->post($endpoint, $params);
        
        if ($continue_process) {
            // if success return array with the response parameters
            return $this->checkResponse($accept_error_status);
        }
        
        return $this;
    }

    /**
     * Return response handler type.
     *
     * @return string
     */
    abstract protected function getResponseHandlerType();

    /**
     * Return proper response handler.
     *
     * @return ResponseInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function getResponseHandler()
    {
        $responseHandler = $this->responseFactory->create(
            $this->getResponseHandlerType(),
            $this->getRequestId(),
            $this->curl
        );

        return $responseHandler;
    }

    protected function checkResponse($accept_error_status)
    {
        $resp_body        = json_decode($this->curl->getBody(), true);
        $requestStatus    = $this->getResponseStatus($resp_body);
        
        $this->readerWriter->createLog([
            'Request Status'    => $requestStatus,
            'Response data'     => $resp_body
        ]);

        // we do not want exception when UpdateOrder return Error
        if ($accept_error_status === false && $requestStatus === false) {
            throw new PaymentException($this->getErrorMessage(
                !empty($resp_body['reason']) ? $resp_body['reason'] : ''
            ));
        }
        
        if (empty($resp_body['status'])) {
            $this->readerWriter->createLog('Mising response status!');
            
            throw new PaymentException(__('Mising response status!'));
        }

        return $resp_body;
    }
    
    /**
     * Function prepareSubscrData
     *
     * Prepare and return short Items data
     * and the data for the Subscription plan, if there is
     *
     * @param Quote $quote
     * @return array
     */
    protected function prepareSubscrData($quote)
    {
        $items_data = [];
        $subs_data  = [];
        $items      = $quote->getItems();
        
        $this->readerWriter->createLog(count($items), 'order items count');
        
        if (is_array($items)) {
            foreach ($items as $item) {
                $product    = $item->getProduct();
                $options    = $product->getTypeInstance(true)->getOrderOptions($product);
                
                $this->readerWriter->createLog($options, '$item $options');
                
                $items_data[$item->getId()] = [
                    'quantity'  => $item->getQty(),
                    'price'     => round((float) $item->getPrice(), 2),
                ];

                // if subscription is not enabled continue witht the next product
                if ($item->getProduct()->getData(\Nuvei\Checkout\Model\Config::PAYMENT_SUBS_ENABLE) != 1) {
                    continue;
                }

                // mandatory data
                $subs_data[$product->getId()] = [
                    'planId'            => $item->getProduct()
                        ->getData(\Nuvei\Checkout\Model\Config::PAYMENT_PLANS_ATTR_NAME),
                    'initialAmount'     => 0,
                    'recurringAmount'   => number_format($item->getProduct()
                        ->getData(\Nuvei\Checkout\Model\Config::PAYMENT_SUBS_REC_AMOUNT), 2, '.', ''),
                ];

                # optional data
                $recurr_unit    = $item->getProduct()
                    ->getData(\Nuvei\Checkout\Model\Config::PAYMENT_SUBS_RECURR_UNITS);
                
                $recurr_period  = $item->getProduct()
                    ->getData(\Nuvei\Checkout\Model\Config::PAYMENT_SUBS_RECURR_PERIOD);
                
                $subs_data[$product->getId()]['recurringPeriod'][strtolower($recurr_unit)] = $recurr_period;

                $trial_unit     = $item->getProduct()
                    ->getData(\Nuvei\Checkout\Model\Config::PAYMENT_SUBS_TRIAL_UNITS);
                
                $trial_period   = $item->getProduct()
                    ->getData(\Nuvei\Checkout\Model\Config::PAYMENT_SUBS_TRIAL_PERIOD);
                
                $subs_data[$product->getId()]['startAfter'][strtolower($trial_unit)] = $trial_period;

                $end_after_unit = $item->getProduct()
                    ->getData(\Nuvei\Checkout\Model\Config::PAYMENT_SUBS_END_AFTER_UNITS);
                
                $end_after_period = $item->getProduct()
                    ->getData(\Nuvei\Checkout\Model\Config::PAYMENT_SUBS_END_AFTER_PERIOD);

                $subs_data[$product->getId()]['endAfter'][strtolower($end_after_unit)] = $end_after_period;
                # optional data END
            }
        }
        
        return [
            'items_data'    => $items_data,
            'subs_data'     => $subs_data,
        ];
    }
    
    private function getResponseStatus($body = [])
    {
        $httpStatus = $this->curl->getStatus();
        
        if ($httpStatus !== 200 && $httpStatus !== 100) {
            return false;
        }
        
        $responseStatus             = strtolower(!empty($body['status']) ? $body['status'] : '');
        
        $responseTransactionStatus  = strtolower(!empty($body['transactionStatus'])
            ? $body['transactionStatus'] : '');
        
        $responseTransactionType    = strtolower(!empty($body['transactionType'])
            ? $body['transactionType'] : '');

        if (!(
                (
                    !in_array($responseTransactionType, ['auth', 'sale'])
                    && $responseStatus === 'success' && $responseTransactionType !== 'error'
                )
                || (
                    in_array($responseTransactionType, ['auth', 'sale'])
                    && $responseTransactionStatus === 'approved'
                )
            )
        ) {
            return false;
        }

        return true;
    }
    
    /**
     * @return \Magento\Framework\Phrase
     */
    private function getErrorMessage($msg = '')
    {
        $errorReason = $this->getErrorReason();
        if ($errorReason !== false) {
            return __('Request to payment gateway failed. Details: %1.', $errorReason);
        } elseif (!empty($msg)) {
            return __($msg);
        }
        
        return __('Request to payment gateway failed.');
    }
    
    /**
     * @return bool|string
     */
    protected function getErrorReason()
    {
        $body = $this->curl->getBody();
        
        if (!empty($body['gwErrorReason'])) {
            return $body['gwErrorReason'];
        }
        return false;
    }
}
