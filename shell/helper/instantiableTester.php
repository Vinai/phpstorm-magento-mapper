<?php
require_once dirname(dirname($argv[0])) . '/abstract.php';

/**
 * Class PhpStorm_Map_Generator_instantiableTester
 */
class PhpStorm_Map_Generator_instantiableTester extends Mage_Shell_Abstract
{

    /**
     * Set this to false because we don't need a fully initialized magento. Otherwise the check need to long.
     *
     * @var bool
     */
    protected $_includeMage = false;

    /**
     * @inheritdoc
     *
     * This is a bug fix for setting up "_includeMage" to false. In this case the Mage class will not included.
     * But in the parent constructor the factory class Mage_Core_Model_Factory gets initialized which occurs an
     * error without setup the auto loader.
     */
    public function __construct()
    {
        require_once $this->_getRootPath() . 'app' . DIRECTORY_SEPARATOR . 'Mage.php';
        parent::__construct();

        //Display errors to show warnings and strict notices if the error level strict or notice is set
        ini_set('display_errors', 1);
    }

    /**
     * Check if all classes given by the cli are instantiable.
     */
    public function run()
    {
        $classNames = array_keys($this->_args);

        //No classes given
        if (!$classNames) {
            exit(1);
        }

        //Perform single checks for the classes
        foreach ($classNames as $className) {
            $reflectionClass = new ReflectionClass($className);

            //Is an interface?
            if ($reflectionClass->isInterface()) {
                echo "Interface";
                exit(1);
            }

            //Is an abstract class?
            if ($reflectionClass->isAbstract()) {
                echo "Abstract";
                exit(1);
            }

            //Is a trait?
            if ($reflectionClass->isTrait()) {
                echo "Trait";
                exit(1);
            }

            //Can create the class with new?
            if (!$reflectionClass->isInstantiable()) {
                echo "Not instantiable";
                exit(1);
            }
        }

        echo 'Done';
    }
}

$classCheck = new PhpStorm_Map_Generator_instantiableTester();
$classCheck->run();