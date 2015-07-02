<?php

require_once dirname($argv[0]) . '/abstract.php';

class PhpStorm_Map_Generator extends Mage_Shell_Abstract
{
    /**
     * Support DI of the config model
     *
     * @var Mage_Core_Model_Config
     */
    protected $_config;

    public function getConfig()
    {
        if (is_null($this->_config)) {
            $this->_config = Mage::getConfig();
        }
        return $this->_config;
    }

    public function setConfig($config)
    {
        $this->_config = $config;
        return $this;
    }

    public function run()
    {
        $models = $blocks = $helpers = $resourceModels = array();

        foreach ($this->getActiveModules() as $module) {
            $moduleConfig = $this->_getModuleConfig($module);
            if ($moduleConfig && $moduleConfig->getNode()) {
                $models += $this->_getMap('model', $moduleConfig, $module);
                //$blocks += $this->_getMap('block', $moduleConfig, $module);
                $helpers += $this->_getMap('helper', $moduleConfig, $module);
                $resourceModels += $this->_getResourcMap($moduleConfig, $module);
            }
        }

        $map = array(
            "\\Mage::getModel('')" => $models,
            "\\Mage::getSingleton('')" => $models,
            "\\Mage::getResourceModel('')" => $resourceModels,
            "\\Mage::getResourceSingleton('')" => $resourceModels,
            "\\Mage::helper('')" => $helpers,
            //"\\Mage::app()->getLayout()->createBock('')" => $blocks,
            //"\$this->getLayout()->createBock('')" => $blocks,
        );

        $this->_writeMap($map);
    }

    /**
     * @return array
     */
    public function getActiveModules()
    {
        $modules = array();
        $cofig = $this->getConfig()->getNode('modules');
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
     * @param string $module
     * @return array|bool
     */
    protected function _getMageDefaults($type, $module)
    {
        if (preg_match('/^Mage_([^_]+)$/', $module, $m)) {
            $classGroup = strtolower($m[1]);
            $classPrefix = 'Mage_' . ucfirst($classGroup) . '_' . ucfirst($type);
            return array(
                'classGroup' => $classGroup,
                'classPrefix' => $classPrefix,
            );
        }
        return false;
    }

    /**
     * @param string $type
     * @param Mage_Core_Model_Config_Base $moduleConfig
     * @param string $module
     * @return array
     */
    protected function _getMap($type, Mage_Core_Model_Config_Base $moduleConfig, $module)
    {
        $map = array();
        $classGroup = $this->_getClassGroup($type, $moduleConfig);
        $classPrefix = $this->_getClassPrefix($type, $moduleConfig);

        // Defaults for Mage namespace
        if (!$classGroup && ($defaults = $this->_getMageDefaults($type, $module))) {
            $classGroup = $defaults['classGroup'];
            $classPrefix = $defaults['classPrefix'];
        }

        if ($classGroup && $classPrefix) {
            foreach ($this->_collectClassSuffixes($classPrefix) as $suffix) {
                $factoryName = $classGroup . '/' . $suffix;
                $map[$factoryName] = $this->getConfig()->getGroupedClassName($type, $factoryName);
                // Add default for data helpers
                if ('helper' === $type && 'data' === $suffix) {
                    $map[$classGroup] = $map[$factoryName];
                }
            }
        }
        return $map;
    }

    /**
     * @param Mage_Core_Model_Config_Base $moduleConfig
     * @param $module
     * @return array
     */
    protected function _getResourcMap(Mage_Core_Model_Config_Base $moduleConfig, $module)
    {
        $map = array();
        $resourceClassPrefix = false;
        $classGroup = $this->_getClassGroup('model', $moduleConfig);
        if (!$classGroup && ($defaults = $this->_getMageDefaults('model', $module))) {
            $classGroup = $defaults['classGroup'];
        }
        if ($classGroup) {
            $xpath = "global/models/{$classGroup}/resourceModel";
            $resourceClassGroupConfig = $moduleConfig->getNode()->xpath($xpath);
            if ($resourceClassGroupConfig) {
                $xpath = "global/models/{$resourceClassGroupConfig[0]}/class";
                $resourceClassPrefixConfig = $moduleConfig->getNode()->xpath($xpath);
                if ($resourceClassPrefixConfig) {
                    $resourceClassPrefix = (string) $resourceClassPrefixConfig[0];
                }
            }

            if (! $resourceClassPrefix && 'Mage_Core' == $module) {
                // Apply defaults from app/etc/config.xml
                $resourceClassPrefix = 'Mage_Core_Model_Resource';
            }

            if ($resourceClassPrefix) {
                foreach ($this->_collectClassSuffixes($resourceClassPrefix) as $suffix) {
                    $factoryName = $classGroup . '/' . $suffix;
                    $map[$factoryName] = $this->getConfig()->getResourceModelClassName($factoryName);
                }
            }
        }
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
                    preg_match('/^[A-Za-z0-9\_]+\.php$/', $item->getBasename())
            ) {
                if ($item->getBasename() == 'Abstract.php')
                    continue;

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
    /** @noinspection PhpUndefinedClassInspection */
    /** @noinspection PhpDeprecationInspection */
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

    public function usageHelp()
    {
        $fileName = pathinfo(__FILE__, PATHINFO_BASENAME);
        return <<<USAGE
Usage:  php -f {$fileName} -- [options]

  --file <map-file>  Defaults to stdout
  help               This help

USAGE;
    }
}

$shell = new PhpStorm_Map_Generator();
$shell->setConfig(Mage::getConfig())->run();
