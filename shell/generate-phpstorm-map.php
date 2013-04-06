<?php

require_once 'abstract.php';

class PhpStorm_Map_Generator extends Mage_Shell_Abstract
{
    protected $_models = array();
    protected $_resourceModels = array();
    protected $_blocks = array();
    protected $_helpers = array();

    public function run()
    {
        foreach ($this->getActiveModules() as $module) {
            $moduleConfig = $this->_getModuleConfig($module);
            if ($moduleConfig && $moduleConfig->getNode()) {
                $this->_models += $this->_getMap('model', $moduleConfig);
                $this->_blocks += $this->_getMap('block', $moduleConfig);
                $this->_helpers += $this->_getMap('helper', $moduleConfig);
                $this->_resourceModels += $this->_getResourcMap($moduleConfig);
            }
        }

        $map = array(
            "\\Mage::getModel('')" => $this->_models,
            "\\Mage::getSingleton('')" => $this->_models,
            "\\Mage::getResourceModel('')" => $this->_resourceModels,
            "\\Mage::getResourceSingleton('')" => $this->_resourceModels,
            "\\Mage::helper('')" => $this->_helpers,
            "\\Mage::app()->getLayout()->createBock('')" => $this->_blocks,
        );

        $this->_writeMap($map);
    }

    /**
     * @return array
     */
    public function getActiveModules()
    {
        $modules = array();
        $cofig = Mage::getConfig()->getNode('modules');
        foreach ($cofig->asArray() as $module => $info) {
            if ('true' === $info['active']) {
                $modules[] = $module;
            }
        }
        return $modules;
    }

    /**
     * @param $module
     * @return Mage_Core_Model_Config_Base
     */
    protected function _getModuleConfig($module)
    {
        /** @var $moduleConfig Mage_Core_Model_Config_Base */
        $moduleConfig = Mage::getModel('core/config_base');
        $moduleConfig->loadFile(
            Mage::getModuleDir('etc', $module) . DS . 'config.xml'
        );
        return $moduleConfig;
    }

    /**
     * @param string $type
     * @param Mage_Core_Model_Config_Base $moduleConfig
     * @return array
     */
    protected function _getMap($type, Mage_Core_Model_Config_Base $moduleConfig)
    {
        $map = array();
        $classGroup = $this->_getClassGroup($type, $moduleConfig);
        $classPrefix = $this->_getClassPrefix($type, $moduleConfig);
        if ($classGroup && $classPrefix) {
            foreach ($this->_collectClassSuffixes($classPrefix) as $suffix) {
                $factoryName = $classGroup . '/' . $suffix;
                $map[$factoryName] = Mage::getConfig()->getGroupedClassName($type, $factoryName);
            }
        }
        return $map;
    }

    /**
     * @param Mage_Core_Model_Config_Base $moduleConfig
     * @return array
     */
    protected function _getResourcMap(Mage_Core_Model_Config_Base $moduleConfig)
    {
        $map = array();
        return $map;
    }

    protected function _getClassGroup($type, Mage_Core_Model_Config_Base $moduleConfig)
    {
        if ($classConfig = $this->_getClassConfig($type, $moduleConfig)) {
            return $classConfig->getName();
        }
        return false;
    }

    protected function _getClassPrefix($type, Mage_Core_Model_Config_Base $moduleConfig)
    {
        if ($classConfig = $this->_getClassConfig($type, $moduleConfig)) {
            return $classConfig->class;
        }
        return false;
    }

    /**
     * @param $type
     * @param Mage_Core_Model_Config_Base $moduleConfig
     * @return Mage_Core_Model_Config_Element
     */
    protected function _getClassConfig($type, Mage_Core_Model_Config_Base $moduleConfig)
    {
        $xpath = "global/{$type}s/*[class]";
        $classConfigs = $moduleConfig->getNode()->xpath($xpath);
        if ($classConfigs) {
            return $classConfigs[0];
        }
        return false;
    }

    /**
     * Scan files in directory mapped by class prefix
     * Build class names from files
     *
     * @param string $prefix
     * @return array
     */
    protected function _collectClassSuffixes($prefix)
    {
        $classes = array();
        $path = str_replace('_', DS, $prefix);

        foreach (explode(PS, get_include_path()) as $includePath) {
            $dir = $includePath . DS . $path;
            if (file_exists($dir) && is_dir($dir)) {
                foreach ($this->_collectClassSuffixesForPrefixInDir($dir) as $suffix) {
                    // Still to many people without PHP 5.3 to
                    // be able to use a anonymous function here *sigh*
                    // Lowercase the first character and every first character after an underscore
                    $toLowerCase = create_function(
                        '$matches', 'return strtolower($matches[0]);'
                    );
                    $suffix = lcfirst(preg_replace_callback('/_([A-Z])/', $toLowerCase, $suffix));
                    $classes[] = $suffix;
                }
            }
        }
        return $classes;
    }

    public function _lowercase()
    {
    }

    /**
     * @param $dir
     * @return array
     */
    protected function _collectClassSuffixesForPrefixInDir($dir)
    {
        $classes = array();
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir),
            \RecursiveIteratorIterator::CHILD_FIRST);
        /** @var $item SplFileInfo */
        foreach ($iterator as $item) {
            if ($item->isFile() &&
                    substr($item->getBasename(), -4 === '.php')
            ) {
                $file = substr($item->getPathname(), strlen($dir) + 1); // Remove leading path
                $file = substr($file, 0, -4); // Remove .php
                $classes[] = str_replace(DS, '_', $file);
            }
        }
        return $classes;
    }

    protected function _writeMap(array $map)
    {
        $f = fopen($this->_getOutputFile(), 'w');
        $str = '<?php

namespace PHPSTORM_META {
    /** @noinspection PhpUnusedLocalVariableInspection */
    /** @noinspection PhpIllegalArrayKeyTypeInspection */
    $STATIC_METHOD_TYPES = [' . PHP_EOL;
        fwrite($f, $str);
        foreach ($map as $factory => $classes) {
            fwrite($f, "        $factory => [\n");
            foreach ($classes as $factoryName => $className) {
                fwrite($f, "            '$factoryName' instanceof \\$className,\n");
            }
            fwrite($f, "        ],\n");
        }

        $str = '    ];
}';
        fwrite($f, $str);
        fclose($f);
    }

    protected function _getOutputFile()
    {
        if ($file = $this->getArg('file')) {
            return $file;
        }
        return 'php://stdout';
    }

    /**
     * Retrieve Usage Help Message
     *
     */
    public function usageHelp()
    {
        return <<<USAGE
Usage:  php -f phpstorm-map.php -- [options]

  --file <map-file>  Defaults to stdout
  help               This help

USAGE;
    }
}

$shell = new PhpStorm_Map_Generator();
$shell->run();
