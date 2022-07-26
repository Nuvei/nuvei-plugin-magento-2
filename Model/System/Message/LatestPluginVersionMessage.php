<?php

namespace Nuvei\Checkout\Model\System\Message;

/**
 * Show System message if there is new version of the plugin,
 *
 * @author Nuvei
 */
class LatestPluginVersionMessage implements \Magento\Framework\Notification\MessageInterface
{
    const MESSAGE_IDENTITY = 'nuvei_plugin_version_message';
    
    /**
     * @var Curl
     */
    protected $curl;
    
    private $directory;
    private $modulConfig;
    private $readerWriter;
    
    public function __construct(
        \Magento\Framework\Filesystem\DirectoryList $directory,
        \Nuvei\Checkout\Model\Config $modulConfig,
        \Nuvei\Checkout\Lib\Http\Client\Curl $curl,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter
    ) {
        $this->directory    = $directory;
        $this->modulConfig  = $modulConfig;
        $this->curl         = $curl;
        $this->readerWriter = $readerWriter;
    }

    /**
     * Retrieve unique system message identity
     *
     * @return string
     */
    public function getIdentity()
    {
        return self::MESSAGE_IDENTITY;
    }
    
    /**
     * Check whether the system message should be shown
     *
     * @return bool
     */
    public function isDisplayed()
    {
        if ($this->modulConfig->isActive() === false) {
            $this->readerWriter->createLog('LatestPluginVersionMessage Error - the module is not active.');
            return;
        }
        
        // check every 7th day
        if( (int) date('d', time()) % 7 != 0 ) {
            return;
        }
        
        $git_version = 0;
        
        try {
            $file = $this->directory->getPath('log') . DIRECTORY_SEPARATOR . 'nuvei-plugin-latest-version.txt';
            
            $this->curl->get('https://raw.githubusercontent.com/SafeChargeInternational/'
                . 'nuvei_checkout_magento/master/composer.json');
            $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
            $this->curl->setOption(CURLOPT_SSL_VERIFYPEER, false);

            $result = $this->curl->getBody();
            $array  = json_decode($result, true);

            if (empty($array['version'])) {
                $this->readerWriter->createLog($result, 'LatestPluginVersionMessage Error - missing version.');
                return;
            }

            $git_version    = (int) str_replace('.', '', $array['version']);
            $res            = $this->readerWriter->saveFile(
                $this->directory->getPath('log'),
                'nuvei-plugin-latest-version.txt',
                $array['version']
            );

            if (!$res) {
                $this->readerWriter->createLog('LatestPluginVersionMessage Error - file was not created.');
            }
        } catch (\Exception $ex) {
            $this->readerWriter->createLog($ex->getMessage(), 'LatestPluginVersionMessage Exception:');
        }
        
        if (!$this->readerWriter->fileExists($file) && 0 == $git_version) {
            $this->readerWriter->createLog('LatestPluginVersionMessage - version file does not exists.');
            return false;
        }
        
        if (!$this->readerWriter->isReadable($file)) {
            $this->readerWriter->createLog($file, 'LatestPluginVersionMessage Error - '
                . 'version file exists, but is not readable!');
            
            if(0 == $git_version) {
                return false;
            }
        }
        
        if(0 == $git_version) {
            $git_version = (int) str_replace('.', '', trim($this->readerWriter->readFile($file)));
        }
        $this->readerWriter->createLog('isDisplayed()');
        $this_version = str_replace('Magento Plugin ', '', $this->modulConfig->getSourcePlatformField());
        $this_version = (int) str_replace('.', '', $this_version);
        
        if ($git_version > $this_version) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Retrieve system message text
     *
     * @return \Magento\Framework\Phrase
     */
    public function getText()
    {
        return __('There is a new version of Nuvei Plugin available. '
            . '<a href="https://github.com/SafeChargeInternational/nuvei_checkout_magento/blob/master/CHANGELOG.md" '
            . 'target="_blank">View version details.</a>');
    }
    
    /**
     * Retrieve system message severity
     * Possible default system message types:
     * - MessageInterface::SEVERITY_CRITICAL
     * - MessageInterface::SEVERITY_MAJOR
     * - MessageInterface::SEVERITY_MINOR
     * - MessageInterface::SEVERITY_NOTICE
     *
     * @return int
     */
    public function getSeverity()
    {
        return self::SEVERITY_NOTICE;
    }
}

