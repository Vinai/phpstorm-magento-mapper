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

    /**
     * Holds the cached results of the instantiable check.
     *
     * @var array
     */
    protected $_instantiableClassCache = array();

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
                $blocks += $this->_getMap('block', $moduleConfig, $module);
                $helpers += $this->_getMap('helper', $moduleConfig, $module);
                $resourceModels += $this->_getResourceMap($moduleConfig, $module);
            }
        }

        //Sort the results from a to z
        ksort($models);
        ksort($blocks);
        ksort($helpers);
        ksort($resourceModels);

        $map = array(
            //Default static factories
            "\\Mage::getModel('')"                            => $models,
            "\\Mage::getSingleton('')"                        => $models,
            "\\Mage::getResourceModel('')"                    => $resourceModels,
            "\\Mage::getResourceSingleton('')"                => $resourceModels,
            "\\Mage::getBlockSingleton('')"                   => $blocks,
            "\\Mage::helper('')"                              => $helpers,
            //Default non static factories
            "\\Mage_Core_Model_Factory::getModel('')"         => $models,
            "\\Mage_Core_Model_Factory::getSingleton('')"     => $models,
            "\\Mage_Core_Model_Factory::getResourceModel('')" => $resourceModels,
            "\\Mage_Core_Model_Factory::getHelper('')"        => $helpers,
            //Other helper factories
            "\\Mage_Core_Block_Abstract::helper('')"          => $helpers,
            //Other block factories
            "\\Mage_Core_Model_Layout::createBlock('')"       => $blocks,
            "\\Mage_Core_Model_Layout::getBlockSingleton('')" => $blocks,
            "\\Mage_Core_Block_Abstract::getHelper('')"       => $blocks,
        );

        //Create an extension point to extend the map without override the file
        $eventTransport      = new stdClass();
        $eventTransport->map = $map;
        Mage::dispatchEvent('phpstorm_map_generator_extend_map', array('transport' => $eventTransport));
        $map = $eventTransport->map;

        if ($this->isInstantiableCheckActive()) {
            $map = $this->_cleanMap($map);
        }
        $this->_writeMap($map);
    }

    /**
     * @return array
     */
    public function getActiveModules()
    {
        /* @var $config Mage_Core_Model_Config_Element */
        $modules = array();
        $config = $this->getConfig()->getNode('modules');
        foreach ($config->asArray() as $module => $info) {
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
    protected function _getResourceMap(Mage_Core_Model_Config_Base $moduleConfig, $module)
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
                $file      = substr($item->getPathname(), strlen($dir) + 1); // Remove leading path
                $file      = substr($file, 0, -4); // Remove .php
                $className = str_replace(DS, '_', $file);

                if (!$this->isInstantiableCheckActive()
                    && ($item->getBasename() == 'Abstract.php' || $item->getBasename() == 'Interface.php')
                ) {
                    $this->debug("Not instantiable: {$className}");
                    continue;
                }

                $classes[] = $className;
            }
        }
        return $classes;
    }

    /**
     * Remove all entries from the given class map that are not instantiable with a new operator.
     *
     * @param array $factoryMap
     * @return array
     */
    protected function _cleanMap(array $factoryMap)
    {
        foreach ($factoryMap as $factory => $classMap) {
            $classMapChunks = array_chunk($classMap, 10, true);
            foreach ($classMapChunks as $classMapChunk) {
                $invalidClasses = $this->_getNotInstantiableClasses($classMapChunk);
                if ($invalidClasses) {
                    foreach ($invalidClasses as $factoryName => $className) {
                        unset($factoryMap[$factory][$factoryName]);
                    }
                }
            }
        }

        return $factoryMap;
    }

    /**
     * @param array $classMap
     * @return array
     */
    protected function _getNotInstantiableClasses(array $classMap)
    {
        $invalidClasses = array();

        //Remove classes that was already checked
        foreach ($classMap as $factoryName => $className) {
            if (isset($this->_instantiableClassCache[$className])) {
                if (!$this->_instantiableClassCache[$className]) {
                    $invalidClasses[$factoryName] = $className;
                }
                unset($classMap[$factoryName]);
            }
        }

        //Check if all items was resolved form the cache
        if (!$classMap) {
            return $invalidClasses;
        }

        //Check if all classes are valid
        if ($this->_isClassInstantiable($classMap, true)) {
            foreach ($classMap as $className) {
                $this->_instantiableClassCache[$className] = true;
            }
            return $invalidClasses;
        }

        //It not, we need to check all classes once by once
        foreach ($classMap as $factoryName => $className) {
            if ($this->_isClassInstantiable($className)) {
                $this->_instantiableClassCache[$className] = true;
            } else {
                $invalidClasses[$factoryName]              = $className;
                $this->_instantiableClassCache[$className] = false;
            }
        }

        return $invalidClasses;
    }

    /**
     * Perform an instantiable check for the given classes.
     *
     * The calculation the result for an single class creates a big overhead. Its better to call this method
     * with an array of class names. If it fails for an chunk of class names you need to iterate over all
     * elements of the chunk to detect all invalid class. Don't break on the first invalid  class because the
     * chunk may contains multiple invalid classes.
     *
     * @param string|array $classNames
     * @param bool         $skipLog
     * @return bool
     */
    protected function _isClassInstantiable($classNames, $skipLog = false)
    {
        $file = __DIR__ . DS . 'helper' . DS . 'instantiableTester.php';
        if (!is_array($classNames)) {
            $classNames = array($classNames);
        }

        $cmdClassNames = implode(' ', array_map('escapeshellarg', $classNames));
        exec("{$this->getPhpExecutable()} -f={$file} {$cmdClassNames}", $execOutputLines);
        if (isset($execOutputLines[0]) && 'Done' === $execOutputLines[0]) {
            return true;
        }

        //Search the first non empty message
        if (!$skipLog) {
            foreach ($execOutputLines as $line) {
                if (($line = trim($line))) {
                    $this->debug("{$line}: " . implode(', ', $classNames));
                    break;
                }
            }
        }

        return false;
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

  --file <map-file>      Defaults to <stdout>
  --instantiableCheck    Activate instantiable check for every class
  --phpExecutable <path> Define path to the php executable for the
                         instantiable check, "php" by default
  --debug                Enable debug output on <stderr>
  help                   This help

USAGE;
    }

    /**
     * Log the given message to stderr if the debug flag is active.
     *
     * @param $message
     * @return $this
     */
    public function debug($message)
    {
        if ($this->isDebugActive()) {
            fwrite(STDERR, $message . PHP_EOL);
        }

        return $this;
    }

    /**
     * Return true if the instantiable check is active.
     *
     * @return bool
     */
    public function isInstantiableCheckActive()
    {
        return array_key_exists('instantiableCheck', $this->_args);
    }

    /**
     * Return the path to the php executable.
     *
     * @return string
     */
    public function getPhpExecutable()
    {
        if (isset($this->_args['phpExecutable'])) {
            return $this->_args['phpExecutable'];
        }

        return 'php';
    }

    /**
     * Return true if the debug feature is enabled.
     *
     * @return bool
     */
    public function isDebugActive()
    {
        return array_key_exists('debug', $this->_args);
    }
}

$shell = new PhpStorm_Map_Generator();
$shell->setConfig(Mage::getConfig())->run();
