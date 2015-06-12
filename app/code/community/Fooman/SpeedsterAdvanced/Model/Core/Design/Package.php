<?php
/**
 * Magento
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@magentocommerce.com so we can send you a copy immediately.
 *
 * @category   Mage
 * @copyright  Copyright (c) 2009 Irubin Consulting Inc. DBA Varien (http://www.varien.com) (original implementation)
 * @copyright  Copyright (c) 2010 Fooman Limited (http://www.fooman.co.nz) (use of Minify Library)
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

/*
 * @author     Kristof Ringleff
 * @package    Fooman_SpeedsterAdvanced
 * @copyright  Copyright (c) 2010 Fooman Limited (http://www.fooman.co.nz)
 */

set_include_path(BP . DS . 'lib' . DS . 'minify' . PS . get_include_path());

class Fooman_SpeedsterAdvanced_Model_Core_Design_Package extends Mage_Core_Model_Design_Package
{

    protected $_speedsterBlacklists = array();
    const CACHEKEY = 'fooman_speedster_advanced_';

    protected function getNameMd5($files) {
        return md5(implode(',', $files));
    }

    protected function getTargetFileName() {
        $args = func_get_args();
        $type = array_pop($args);
        return implode('-', $args) . '.' . $type;
    }

    public function __construct()
    {
        if (method_exists('Mage_Core_Model_Design_Package', '__construct')) {
            parent::__construct();
        }
        foreach (explode(',', Mage::getStoreConfig('dev/js/speedster_minify_blacklist')) as $jsBlacklist) {
            $jsBlacklist = Mage::helper('speedsterAdvanced')->normaliseUrl($jsBlacklist);
            if ($jsBlacklist) {
                $this->_speedsterBlacklists['js']['minify'][$jsBlacklist] = true;
            }
        }
        foreach (explode(',', Mage::getStoreConfig('dev/css/speedster_minify_blacklist')) as $cssBlacklist) {
            $cssBlacklist = Mage::helper('speedsterAdvanced')->normaliseUrl($cssBlacklist);
            if ($cssBlacklist) {
                $this->_speedsterBlacklists['css']['minify'][$cssBlacklist] = true;
            }
        }
        foreach (explode(',', Mage::getStoreConfig('dev/css/speedster_minify_blacklist_secure')) as $cssBlacklist) {
            $cssBlacklist = Mage::helper('speedsterAdvanced')->normaliseUrl($cssBlacklist);
            if ($cssBlacklist) {
                $this->_speedsterBlacklists['css_secure']['minify'][$cssBlacklist] = true;
            }
        }
    }


    /**
     * Merge specified JS files and return URL to the merged file on success
     * filename is md5 of files + timestamp of last modified file
     *
     * @param string $files
     *
     * @return string
     */
    public function getMergedJsUrl($files)
    {
        $targetDir = $this->_initMergerDir('js');
        if (!$targetDir) {
            return '';
        }
        $nameMd5 = $this->getNameMd5($files);
        $fileKey = self::CACHEKEY . 'js_' . $nameMd5;
        $fileHash = Mage::app()->loadCache($fileKey);
        if ($fileHash) {
            $targetFilename = $this->getTargetFileName($nameMd5, $fileHash, 'js');
            if (file_exists(Mage::getBaseDir('media') . '/js/' . $targetFilename)) {
                return Mage::getBaseUrl('media') . 'js/' . $targetFilename;
            }
        }
        $tmpPath = tempnam(sys_get_temp_dir(), $fileKey);
        touch($tmpPath, 1);
        if (Mage::helper('core')
            ->mergeFiles(
                $files, $tmpPath, false, array($this, 'beforeMergeJs'), 'js'
            )
        ) {
            $fileHash = md5(file_get_contents($tmpPath));
            $targetFilename = $this->getTargetFileName($nameMd5, $fileHash, 'js');
            $targetPath = $targetDir . DS . $targetFilename;
            rename($tmpPath, $targetPath);
            Mage::app()->saveCache($fileHash, $fileKey, array(), false);
            return Mage::getBaseUrl('media') . 'js/' . $targetFilename;
        }
        return '';
    }


    /**
     * Before merge JS callback function
     *
     * @param string $file
     * @param string $contents
     *
     * @return string
     */
    public function beforeMergeJs($file, $contents)
    {
        //append full content of blacklisted files
        $relativeFileName = str_replace(BP . DS, '', $file);
        if (isset($this->_speedsterBlacklists['js']['minify'][$relativeFileName])) {
            if (Mage::getIsDeveloperMode()) {
                return "\n/*" . $file . " (original) */\n" . $contents . "\n\n";
            }
            return "\n" . $contents;
        }

        if (preg_match('/@ sourceMappingURL=([^\s]*)/s', $contents, $matches)) {
            //create a file without source map
            $contents = str_replace(
                $matches[0], '',
                $contents
            );
        }

        if (Mage::getIsDeveloperMode()) {
            return
                "\n/*" . $file . " (minified) */\n" . Mage::getModel('speedsterAdvanced/javascript')->minify($contents)
                . "\n\n";
        }

        return "\n" . Mage::getModel('speedsterAdvanced/javascript')->minify($contents);
    }

    /**
     * Merge specified css files and return URL to the merged file on success
     * filename is md5 of files + storeid + SSL flag + timestamp of last modified file
     *
     * @param $files
     *
     * @return string
     */
    public function getMergedCssUrl($files)
    {
        $storeId = Mage::app()->getStore()->getId();
        $isSecure = Mage::app()->getStore()->isCurrentlySecure();
        $mergerDir = $isSecure ? 'css_secure' : 'css';
        $callback = $isSecure ? 'beforeMergeCssSecure' : 'beforeMergeCss';
        $targetDir = $this->_initMergerDir($mergerDir);
        if (!$targetDir) {
            return '';
        }
        $nameMd5 = $this->getNameMd5($files);
        $fileKey = self::CACHEKEY . $mergerDir . '_' . $nameMd5;
        $fileHash = Mage::app()->loadCache($fileKey);
        if ($fileHash) {
            $targetFilename = $this->getTargetFileName($nameMd5, $fileHash, $storeId, 'css');
            if (file_exists(Mage::getBaseDir('media') . '/' . $mergerDir . '/' . $targetFilename)) {
                return Mage::getBaseUrl('media') . $mergerDir . '/' . $targetFilename;
            }
        }
        $tmpPath = tempnam(sys_get_temp_dir(), $fileKey);
        touch($tmpPath, 1);
        if (Mage::helper('core')
            ->mergeFiles(
                $files, $tmpPath, false, array($this, $callback), 'css'
            )
        ) {
            $fileHash = md5(file_get_contents($tmpPath));
            $targetFilename = $this->getTargetFileName($nameMd5, $fileHash, $storeId, 'css');
            $targetPath = $targetDir . DS . $targetFilename;
            rename($tmpPath, $targetPath);
            Mage::app()->saveCache($fileHash, $fileKey, array(), false);
            return Mage::getBaseUrl('media') . $mergerDir . '/' . $targetFilename;
        }
        return '';
    }

    /**
     * Before merge css callback function
     *
     * @param string $origFile
     * @param string $contents
     *
     * @return string
     */
    public function beforeMergeCss($origFile, $contents)
    {
        //append full content of blacklisted files
        $relativeFileName = str_replace(BP . DS, '', $origFile);
        if (isset($this->_speedsterBlacklists['css']['minify'][$relativeFileName])) {
            if (Mage::getIsDeveloperMode()) {
                return "\n/* NON-SSL:" . $origFile . " (original) */\n" . $contents . "\n\n";
            }
            return "\n" . $contents;
        }

        //make file relative to Magento root
        //assumes files are under Magento root
        $file = str_replace(BP, '', $origFile);

        //we have some css residing in the js folder
        $filePathComponents = explode(DS, $file);
        $isJsPath = $filePathComponents[1] == 'js';

        //drop filename from end
        array_pop($filePathComponents);

        //remove first empty and skin or js from start
        array_shift($filePathComponents);
        array_shift($filePathComponents);

        if ($isJsPath) {
            $jsPath = implode(DS, $filePathComponents);
            $prependRelativePath = Mage::getStoreConfig('web/unsecure/base_js_url') . $jsPath . DS;
        } else {
            $skinPath = implode(DS, $filePathComponents);
            $prependRelativePath = Mage::getStoreConfig('web/unsecure/base_skin_url') . $skinPath . DS;
        }

        //we might be on windows but instructions in layout updates use / as directory separator
        if (DS != '/') {
            $origFile = str_replace('/', DS, $origFile);
        }
        $completeFilePathComponents = explode(DS, $origFile);
        //drop filename from end
        array_pop($completeFilePathComponents);

        $options = array(
            // currentDir overrides prependRelativePath
            //'currentDir'         => implode(DS, $completeFilePathComponents),
            'preserveComments'    => false,
            'prependRelativePath' => $prependRelativePath,
            'symlinks'            => array('//' => BP)
        );

        if (Mage::getIsDeveloperMode()) {
            return "\n/* NON-SSL: " . $origFile . " (minified)  */\n" . $this->_returnMergedCss($contents, $options)
            . "\n\n";
        }
        return $this->_returnMergedCss($contents, $options);
    }

    /**
     * Before merge css callback function (secure)
     *
     * @param string $origFile
     * @param string $contents
     *
     * @return string
     */
    public function beforeMergeCssSecure($origFile, $contents)
    {
        //append full content of blacklisted files
        $relativeFileName = str_replace(BP . DS, '', $origFile);
        if (isset($this->_speedsterBlacklists['css_secure']['minify'][$relativeFileName])) {
            if (Mage::getIsDeveloperMode()) {
                return "\n/* NON-SSL:" . $origFile . " (original) */\n" . $contents . "\n\n";
            }
            return "\n" . $contents;
        }

        //make file relative to Magento root
        //assumes files are under Magento root
        $file = str_replace(BP, '', $origFile);

        //we have some css residing in the js folder
        $filePathComponents = explode(DS, $file);
        $isJsPath = $filePathComponents[1] == 'js';

        //drop filename from end
        array_pop($filePathComponents);

        //remove first empty and skin or js from start
        array_shift($filePathComponents);
        array_shift($filePathComponents);

        if ($isJsPath) {
            $jsPath = implode(DS, $filePathComponents);
            $prependRelativePath = Mage::getStoreConfig('web/secure/base_js_url') . $jsPath . DS;
        } else {
            $skinPath = implode(DS, $filePathComponents);
            $prependRelativePath = Mage::getStoreConfig('web/secure/base_skin_url') . $skinPath . DS;
        }
        //we might be on windows but instructions in layout updates use / as directory separator
        if (DS != '/') {
            $origFile = str_replace('/', DS, $origFile);
        }
        $completeFilePathComponents = explode(DS, $origFile);
        //drop filename from end
        array_pop($completeFilePathComponents);

        $options = array(
            // currentDir overrides prependRelativePath
            //'currentDir'         => implode(DS, $completeFilePathComponents),
            'preserveComments'    => false,
            'prependRelativePath' => $prependRelativePath,
            'symlinks'            => array('//' => BP)
        );
        if (Mage::getIsDeveloperMode()) {
            return "\n/* SSL: " . $origFile . " (minified) */\n" . $this->_returnMergedCss($contents, $options);
        }
        return $this->_returnMergedCss($contents, $options);
    }

    /**
     * return minified output
     *
     * @param $contents
     * @param $options
     *
     * @return string
     */
    private function _returnMergedCss($contents, $options)
    {
        return "\n" . Mage::getModel('speedsterAdvanced/css')->minify($contents, $options);
    }

}
