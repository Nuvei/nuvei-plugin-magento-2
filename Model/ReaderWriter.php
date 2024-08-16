<?php

namespace Nuvei\Checkout\Model;

/**
 * Read and Write onto the file system.
 *
 * @author Nuvei
 */
class ReaderWriter
{
    private $fieldsToMask = [
        'ips'       => ['ipAddress'],
        'names'     => ['firstName', 'lastName', 'first_name', 'last_name', 'shippingFirstName', 'shippingLastName'],
        'emails'    => [
            'userTokenId',
            'email',
            'shippingMail', // from the DMN
            'userid', // from the DMN
            'user_token_id', // from the DMN
        ],
        'address'   => ['address', 'phone', 'zip'],
        'others'    => ['userAccountDetails', 'userPaymentOption', 'paymentOption'],
    ];
    
    private $fileSystem;
    private $config;
    private $directory;
    private $traceId;
    
    public function __construct(
        \Nuvei\Checkout\Model\Config $config,
        \Magento\Framework\Filesystem\DirectoryList $directory
    ) {
        try {
            $this->config       = $config;
            $this->directory    = $directory;
        } catch (\Exception $ex) {
            
        }
    }
    
    /**
     * Prepare and save log.
     *
     * @param mixed  $data      Data to save. Can be simple message also.
     * @param string $title     Title or description.
     * @param string $log_level Company Log level.
     *
     * @return void
     */
    public function createLog($data, $title = '', $log_level = 'TRACE')
    {
        if (! $this->config->isDebugEnabled()) {
            return;
        }
        
        $logsPath   = $this->directory->getPath('log');
        $d          = $data;
        $string     = '';
        
        if (is_bool($data)) {
            $d = $data ? 'true' : 'false';
        }
        elseif (is_string($data) || is_numeric($data)) {
            $d = $data;
        }
        elseif ('' === $data) {
            $d = 'Data is Empty.';
        }
        elseif (is_array($data) || is_object($data)) {
            if ((int) $this->config->getConfigValue('mask_user_details') == 1
                && !empty($data)
            ) {
                // clean possible objects inside array
                $data = json_decode(json_encode($data), true);
                
                if (is_array($data)) {
                    array_walk_recursive($data, [$this, 'maskData'], $this->fieldsToMask);
                }
            }
            
            // do not log accounts if on prod
            if (!$this->config->isTestModeEnabled()) {
                if (isset($data['userAccountDetails']) && is_array($data['userAccountDetails'])) {
                    $data['userAccountDetails'] = 'account details';
                }
                if (isset($data['userPaymentOption']) && is_array($data['userPaymentOption'])) {
                    $data['userPaymentOption'] = 'user payment options details';
                }
                if (isset($data['paymentOption']) && is_array($data['paymentOption'])) {
                    $data['paymentOption'] = 'payment options details';
                }
            }
            // do not log accounts if on prod

            if (!empty($data['paymentMethods']) && is_array($data['paymentMethods'])) {
                $data['paymentMethods'] = json_encode($data['paymentMethods']);
            }
            if (!empty($data['Response data']['paymentMethods'])
                && is_array($data['Response data']['paymentMethods'])
            ) {
                $data['Response data']['paymentMethods'] = json_encode($data['Response data']['paymentMethods']);
            }

            if (!empty($data['plans']) && is_array($data['plans'])) {
                $data['plans'] = json_encode($data['plans']);
            }

            $d = $this->config->isTestModeEnabled() ? json_encode($data, JSON_PRETTY_PRINT) : json_encode($data);
        }
//        elseif (is_object($data)) {
//            if ((int) $this->config->getConfigValue('mask_user_details') == 1
//                && !empty($data)
//            ) {
//                $data = json_decode(json_encode($data), true);
//                
//                array_walk_recursive($data, [$this, 'maskData'], $this->fieldsToMask);
//            }
//            
//            $d = $this->config->isTestModeEnabled() ? json_encode($data, JSON_PRETTY_PRINT) : json_encode($data);
//        }
        else {
            $d = $this->config->isTestModeEnabled() ? json_encode($data, JSON_PRETTY_PRINT) : json_encode($data);
        }
        
        $tab            = '    ';
        $utimestamp     = microtime(true);
        $timestamp      = floor($utimestamp);
        $milliseconds   = round(($utimestamp - $timestamp) * 1000000);
        $record_time    = date('Y-m-d') . 'T' . date('H:i:s') . '.' . $milliseconds . date('P');
        
        if (!$this->traceId) {
            $this->traceId = bin2hex(random_bytes(16));
        }
        
        $source_file_name   = '';
        $member_name        = '';
        $source_line_number = '';
        
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);
        if (!empty($backtrace)) {
            if (!empty($backtrace[0]['file'])) {
                $file_path_arr  = explode(DIRECTORY_SEPARATOR, $backtrace[0]['file']);
                
                if (!empty($file_path_arr)) {
                    $source_file_name = end($file_path_arr) . '|';
                }
            }
            
            //            if(!empty($backtrace[0]['function'])) {
            //                $member_name = $backtrace[0]['function'] . '|';
            //            }
            
            if (!empty($backtrace[0]['line'])) {
                $source_line_number = $backtrace[0]['line'] . $tab;
            }
        }
        
        $string .= $record_time . $tab
            . $log_level . $tab
            . $this->traceId . $tab
        //            . 'Checkout ' . $this->config->getSourcePlatformField() . '|'
            . $source_file_name
            . $member_name
            . $source_line_number;
        
        if (!empty($title)) {
            if (is_string($title)) {
                $string .= $title . $tab;
            } else {
                if ($this->config->isTestModeEnabled()) {
                    $string .= "\r\n" . json_encode($title, JSON_PRETTY_PRINT) . "\r\n";
                } else {
                    $string .= json_encode($title) . $tab;
                }
            }
        }

        $string .= $d . "\r\n\r\n";
        
        try {
            switch ($this->config->isDebugEnabled(true)) {
            case 3: // save log file per days
                $log_file_name = 'Nuvei-' . date('Y-m-d');
                break;
                
            case 2: // save single log file
                $log_file_name = 'Nuvei';
                break;
                
            case 1: // save both files
                $log_file_name = 'Nuvei';
                $this->saveFile($logsPath, 'Nuvei-' . date('Y-m-d') . '.log', $string, FILE_APPEND);
                break;
                
            default:
                return;
            }
            
            //            $writer = new \Laminas\Log\Writer\Stream(BP . '/var/log/nuvei_checkout.log');
            //            $logger = new \Laminas\Log\Logger();
            //            $logger->addWriter($writer);
            //            $logger->info($string);
            
            return $this->saveFile($logsPath, $log_file_name . '.log', $string, FILE_APPEND);
        } catch (\Exception $e) {
            return;
        }
    }
    
    /**
     * A single place to save files.
     *
     * @param string $path
     * @param string $name   The file name with extension. Append it to the $path.
     * @param mixed  $data
     * @param int    $option A PHP constant like FILE_APPEND.
     *
     * @return bool
     */
    public function saveFile($path, $name, $data, $option = null)
    {
        try {
            if (is_object($this->fileSystem) && $this->fileSystem->isDirectory($path)) {
                return $this->fileSystem->filePutContents(
                    $path . DIRECTORY_SEPARATOR . $name,
                    $data,
                    $option
                );
            }

            if (is_dir($path)) {
                if (is_null($option)) {
                    return file_put_contents($path . DIRECTORY_SEPARATOR . $name, $data);
                } else {
                    return file_put_contents($path . DIRECTORY_SEPARATOR . $name, $data, $option);
                }
            }
        } catch (\Exception $ex) {
            
        }

        return false;
    }
    
    /**
     * Get contents of Nuvei plugin help files. Usually the contains JSONs.
     *
     * @param  string $file_name
     * @return string
     */
    public function readFile($file_name)
    {
        try {
            if (is_object($this->fileSystem) && $this->isReadable($file_name)) {
                return $this->fileSystem->fileGetContents($file_name);
            }

            if (is_readable($file_name)) {
                return file_get_contents($file_name);
            }
        } catch (\Exception $ex) {
            
        }
        
        return '';
    }
    
    /**
     * Is a file readable.
     *
     * @param  string $file
     * @return bool
     */
    public function isReadable($file)
    {
        try {
            if (is_object($this->fileSystem)) {
                return $this->fileSystem->isReadable($file);
            }

            return is_readable($file);
        } catch (\Exception $ex) {
            
        }
        
        return false;
    }
    
    /**
     * Do file exists.
     *
     * @param  string $file
     * @return bool
     */
    public function fileExists($file)
    {
        try {
            if (is_object($this->fileSystem)) {
                return $this->fileSystem->isFile($file);
            }

            return file_exists($file);
        } catch (\Exception $ex) {
            
        }
        
        return false;
    }
    
    /**
     * A callback function for arraw_walk_recursive.
     * 
     * @param mixed $value
     * @param mixed $key
     * @param array $fields
     */
    private function maskData(&$value, $key, $fields)
    {
        if (!empty($value)) {
            if (in_array($key, $fields['ips'])) {
                $value = rtrim((string) long2ip(ip2long($value) & (~255)), "0")."x";
            } elseif (in_array($key, $fields['names'])) {
                $value = mb_substr($value, 0, 1) . '****';
            } elseif (in_array($key, $fields['emails'])) {
                $value = '****' . mb_substr($value, 4);
            } elseif (in_array($key, $fields['address'])
                || in_array($key, $fields['others'])
            ) {
                $value = '****';
            }
        }
    }
}
