<?php

namespace Nuvei\Checkout\Model\System\Message;

use Magento\Framework\Notification\MessageInterface;

/**
 * Show System message if there is new version of the plugin.
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
            return false;
        }
        
        // check every 7th day
        if ((int) date('d', time()) % 7 != 0) {
            return false;
        }
        
        $this->session->start();
        
        $git_version    = 0;
        $this_version   = 0;
        $git_version    = $this->session->getVariable('nuveiPluginGitVersion');
        
        if (empty($git_version) || !is_numeric($git_version)) {
            try {
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
                    $git_version = (int) str_replace('.', '', $arr_v);
                    $this->session->setVariable('nuveiPluginGitVersion', $git_version);
                }
            } catch (\Exception $ex) {
                $this->readerWriter->createLog($ex->getMessage(), 'LatestPluginVersionMessage Exception:');
            }
        }
        
        $ds     = DIRECTORY_SEPARATOR;
        $file   = $this->directory->getPath('app') . $ds . 'code' . $ds . 'Nuvei'
            . $ds . 'Checkout' . $ds . 'composer.json';
        
        if (is_readable($file)) {
            $curr_json = json_decode($this->readerWriter->readFile($file), true);

            if (!empty($curr_json['version'])) {
                $this_version = (int) str_replace('.', '', $curr_json['version']);
            }
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
            . '<a href="https://github.com/Nuvei/nuvei-plugin-magento-2/blob/master/CHANGELOG.md" '
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
