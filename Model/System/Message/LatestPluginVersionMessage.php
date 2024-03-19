<?php

namespace Nuvei\Checkout\Model\System\Message;

use Magento\Framework\Notification\MessageInterface;

/**
 * Show System message if there is new version of the plugin,
 *
 * @author Nuvei
 */
class LatestPluginVersionMessage implements MessageInterface
{
    const MESSAGE_IDENTITY = 'nuvei_plugin_version_message';
    
    /**
     * @var Curl
     */
    protected $curl;
    
    private $directory;
    private $modulConfig;
    private $readerWriter;
    private $session;
    
    public function __construct(
        \Magento\Framework\Filesystem\DirectoryList $directory,
        \Nuvei\Checkout\Model\Config $modulConfig,
        \Nuvei\Checkout\Lib\Http\Client\Curl $curl,
        \Nuvei\Checkout\Model\ReaderWriter $readerWriter,
        \Magento\Framework\Session\SessionManagerInterface $session
    ) {
        $this->directory    = $directory;
        $this->modulConfig  = $modulConfig;
        $this->curl         = $curl;
        $this->readerWriter = $readerWriter;
        $this->session      = $session;
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
        if ($this->modulConfig->getConfigValue('active') === false) {
            $this->readerWriter->createLog('LatestPluginVersionMessage Error - the module is not active.');
            return false;
        }
        
        // check every 7th day
        //        if ((int) date('d', time()) % 7 != 0) {
        //            return;
        //        }
        
        $this->session->start();
        
        $git_version    = 0;
        $this_version   = 0;
        
        
        try {
            $git_version = $this->session->getVariable('nuveiPluginGitVersion');
            
            //            if (empty($_SESSION['admin']['nuveiPluginGitVersion'])) {
            if (empty($git_version)) {
                $file = $this->directory->getPath('log') . DIRECTORY_SEPARATOR . 'nuvei-plugin-latest-version.txt';

                $this->curl->get('https://raw.githubusercontent.com/Nuvei/nuvei-plugin-magento-2/master/composer.json');
                $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
                $this->curl->setOption(CURLOPT_SSL_VERIFYPEER, false);

                $result = $this->curl->getBody();
                $array  = json_decode($result, true);

                if (empty($array['version'])) {
                    $this->readerWriter->createLog($result, 'LatestPluginVersionMessage Error - missing version.');
                    return false;
                }

                $arr_v = $array['version'];

                if (!empty($arr_v)) {
                    //                    $git_version = $_SESSION['admin']['nuveiPluginGitVersion'] = (int) str_replace('.', '', $arr_v);
                    $git_version = (int) str_replace('.', '', $arr_v);
                    $this->session->setVariable('nuveiPluginGitVersion', $git_version);
                }

                //                $res = $this->readerWriter->saveFile(
                //                    $this->directory->getPath('log'),
                //                    'nuvei-plugin-latest-version.txt',
                //                    $array['version']
                //                );
                //
                //                if (!$res) {
                //                    $this->readerWriter->createLog('LatestPluginVersionMessage Error - file was not created.');
                //                }
            }
            //            else {
            //                $git_version = $_SESSION['admin']['nuveiPluginGitVersion'];
            //                $git_version = $this->session->getVariable('nuveiPluginGitVersion');
                
            //                $this->session->start();
            //                $this->session->setVariable('test', 'test');
            //                $this->session->setMessage('test2');
            //                
            //                $this->readerWriter->createLog(
            //                    [
            //                        $this->session->getData('admin'),
            //                        $this->session->getData('nuveiPluginGitVersion'),
            //                        $this->session->getData(),
            //                        $this->session->setVariable('test'),
            //                        $this->session->getMessage(),
            //                        $git_version,
            //                    ]
            //                );
            //            }
        } catch (\Exception $ex) {
            $this->readerWriter->createLog($ex->getMessage(), 'LatestPluginVersionMessage Exception:');
        }
        
        //        if (!$this->readerWriter->fileExists($file) && 0 == $git_version) {
        //            $this->readerWriter->createLog('LatestPluginVersionMessage - version file does not exists.');
        //            return false;
        //        }
        
        //        if (!$this->readerWriter->isReadable($file)) {
        //            $this->readerWriter->createLog($file, 'LatestPluginVersionMessage Error - '
        //                . 'version file exists, but is not readable!');
        //            
        //            if (0 == $git_version) {
        //                return false;
        //            }
        //        }
        //        
        //        $file = trim($this->readerWriter->readFile($file));
        
        //        if (0 == $git_version && !empty($file)) {
        //            $git_version = (int) str_replace('.', '', $file);
        //        }
        
        $this->readerWriter->createLog('isDisplayed()');
        
        $sourcePlatformField = $this->modulConfig->getSourcePlatformField();
        
        if (!empty($sourcePlatformField)) {
            $this_version = str_replace('Magento Plugin ', '', $sourcePlatformField);
        }
        
        if (!empty($this_version)) {
            $this_version = (int) str_replace('.', '', $this_version);
        }
        
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
        return __(
            'There is a new version of Nuvei Plugin available. '
            . '<a href="https://github.com/SafeChargeInternational/nuvei_checkout_magento/blob/master/CHANGELOG.md" '
            . 'target="_blank">View version details.</a>'
        );
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
