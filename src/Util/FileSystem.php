<?php
namespace SaschaEgerer\CodeceptionCssRegression\Util;

use Codeception\Module;
use SaschaEgerer\CodeceptionCssRegression\Module\CssRegression;

/**
 * Provide some methods for filesystem related actions
 */
class FileSystem {

    /**
     * @var CssRegression
     */
    protected $module;

    /**
     * @param CssRegression $module
     */
    public function __construct(CssRegression $module)
    {
        $this->module = $module;
    }

    /**
     * Create a directory recursive
     *
     * @param $path
     */
    public function createDirectoryRecursive($path)
    {
        // @todo UNIX ONLY?
        if (substr($path, 0, 1) !== '/') {
            $path = \Codeception\Configuration::projectDir() . $path;
        } elseif (!strstr($path, \Codeception\Configuration::projectDir())) {
            throw new \InvalidArgumentException(
                'Can\'t create directroy "' . $path
                . '" as it is outside of the project root "'
                . \Codeception\Configuration::projectDir() . '"'
            );
        }

        if (!is_dir(dirname($path))) {
            self::createDirectoryRecursive(dirname($path));
        } elseif (!is_dir($path)) {
            \Codeception\Util\Debug::debug('Directory "' . $path . '" does not exist. Try to create it ...');
            mkdir($path);
        }
    }

    /**
     * Get path for the reference image
     *
     * @param string $identifier
     * @return string path to the reference image
     */
    public function getReferenceImagePath($identifier)
    {
        $testFilename = $this->module->_getCurrentTestCase()->getTestFileName($this->module->_getCurrentTestCase());
        $testName = pathinfo(str_replace($this->module->_getSuitePath(), '', $testFilename), PATHINFO_FILENAME);

        return $this->getReferenceImageDirectory()
        . $testName . DIRECTORY_SEPARATOR
        . $identifier . '.png';
    }

    /**
     * Get path for the fail image with a suffix
     *
     * @param string $identifier test identifier
     * @param string $suffix suffix added to the filename
     * @return string path to the fail image
     */
    public function getFailImagePath($identifier, $suffix = 'fail')
    {
        $testFilename = $this->module->_getCurrentTestCase()->getTestFileName($this->module->_getCurrentTestCase());
        $testName = pathinfo(str_replace($this->module->_getSuitePath(), '', $testFilename), PATHINFO_FILENAME);

        return $this->getFailImageDirectory()
        . $testName . DIRECTORY_SEPARATOR
        . $this->module->_getModuleInitTime() . '.' . $identifier . '.' . $suffix . '.png';
    }

    /**
     * Get the directory to store temporary files
     *
     * @return string
     */
    public function getTempDirectory()
    {
        return \Codeception\Configuration::outputDir() . 'debug' . DIRECTORY_SEPARATOR;
    }

    /**
     * Get the directory where reference images are stored
     *
     * @return string
     */
    public function getReferenceImageDirectory()
    {
        return \Codeception\Configuration::dataDir()
        . rtrim($this->module->_getConfig('referenceImageDirectory'), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR;
    }

    /**
     * Get directory where fail images are stored
     *
     * @return string
     */
    public function getFailImageDirectory()
    {
        return \Codeception\Configuration::outputDir()
        . rtrim($this->module->_getConfig('failImageDirectory'), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR;
    }

    /**
     * Get path to the temp image for the given identifier
     *
     * @param string $identifier identifier for the test
     * @return string Path to the temp image
     */
    public function getTempImagePath($identifier)
    {
        return $this->getTempDirectory() . $this->module->_getModuleInitTime() . '.' . $identifier . '.png';
    }
}
