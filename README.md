PHPStorm Magento Mapper
========================
This extension is for Magento developers using PhpStorm. It generates a class map for autocompletion.

Facts
-----
- version: 0.2
- extension key: - none -
- Magento Connect 1.0 extension key: - none -
- Magento Connect 2.0 extension key: - none -
- [extension on GitHub](https://github.com/Vinai/phpstorm-magento-mapper)
- [direct download link](https://github.com/Vinai/phpstorm-magento-mapper/zipball/master)

Description
-----------
Build a class map for the PhpStorm facorty support introduced with the blog posts.

 - [Support of static factories](http://blog.jetbrains.com/webide/2013/04/phpstorm-6-0-1-eap-build-129-177/)
 - [Support of non static factories](https://youtrack.jetbrains.com/issue/WI-27712)

You need to rerun the script every time you add a class or configure a rewrite.

- Supported Factory Methods
 - Mage::getModel()
 - Mage::getSingleton()
 - Mage::getResourceModel()
 - Mage::getResourceSingleton()
 - Mage::getBlockSingleton()
 - Mage::helper()
 - Mage_Core_Model_Factory::getModel()
 - Mage_Core_Model_Factory::getSingleton()
 - Mage_Core_Model_Factory::getResourceModel()
 - Mage_Core_Model_Factory::getHelper()
 - Mage_Core_Block_Abstract::helper()
 - Mage_Core_Model_Layout::createBlock()
 - Mage_Core_Model_Layout::getBlockSingleton()
 - Mage_Core_Block_Abstract::getHelper()
- Respects class rewrites

Usage
-----
```php shell/generate-phpstorm-map.php --file .phpstorm.meta.php```

If no file is specified the class map will be output to STDOUT

Parameters
-----

| Option                    |    Default   | Description                                                                                                  |
|---------------------------|:------------:|--------------------------------------------------------------------------------------------------------------|
| ```--file```              | ```stdout``` | File location to save the output.                                                                            |
| ```--instantiableCheck``` |   ```Off```  | Perform an additional instantiable check for each class. It its enabled the generate process will slow down. |
| ```--phpExecutable```     |   ```php```  | Path to the php executable to start the instantiable check.                                                  |
| ```--debug```             |   ```Off```  | Print debug output on ```stderr``` why classes gets excluded.                                                |

Support
-------
If you have any issues with this extension, open an issue on GitHub (see URL above).

Contribution
------------
Any contributions are highly appreciated. The best way to contribute code is to open a
[pull request on GitHub](https://help.github.com/articles/using-pull-requests).

Developer
---------
* Vinai Kopp
[http://www.netzarbeiter.com](http://www.netzarbeiter.com)
[@VinaiKopp](https://twitter.com/VinaiKopp)
* Erik Wohllebe

Licence
-------
[OSL - Open Software Licence 3.0](http://opensource.org/licenses/osl-3.0.php)

Copyright
---------
(c) 2013 Vinai Kopp