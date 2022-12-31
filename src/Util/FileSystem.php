<?php
namespace SaschaEgerer\CodeceptionCssRegression\Util;

use Codeception\Module;
use SaschaEgerer\CodeceptionCssRegression\Module\CssRegression;

/**
 * Provide some methods for filesystem related actions
 */
final class FileSystem
{

    public function __construct(private readonly CssRegression $cssRegression)
    {
    }

    /**
     * Create a directory recursive
     *
     * @param $path
     */
    public function createDirectoryRecursive($path)
    {
        // @todo UNIX ONLY?
        if (!str_starts_with((string) $path, '/')) {
            $path = \Codeception\Configuration::projectDir() . $path;
        } elseif (!strstr((string) $path, \Codeception\Configuration::projectDir())) {
            throw new \InvalidArgumentException(
                'Can\'t create directroy "' . $path
                . '" as it is outside of the project root "'
                . \Codeception\Configuration::projectDir() . '"'
            );
        }

        if (!is_dir(dirname((string) $path))) {
            self::createDirectoryRecursive(dirname((string) $path));
        }

        if (!is_dir($path)) {
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
    public function getReferenceImagePath($identifier, $path)
    {
        return $this->getReferenceImageDirectory()
        . $path . DIRECTORY_SEPARATOR
        . $this->sanitizeFilename($identifier) . '.png';
    }

    /**
     * Get the directory where reference images are stored
     *
     * @return string
     */
    public function getReferenceImageDirectory()
    {
        return \Codeception\Configuration::dataDir()
        . rtrim((string) $this->cssRegression->_getConfig('referenceImageDirectory'), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR;
    }

    /**
     * @return string
     */
    public function getCurrentWindowSizeString(Module\WebDriver $webDriver)
    {
        return $webDriver->webDriver->executeScript('return window.innerWidth') . 'x' . $webDriver->webDriver->executeScript('return window.innerHeight');
    }

    /**
     * @param $name
     * @return string
     */
    public function sanitizeFilename($name)
    {
        $name = preg_replace('#[^A-Za-z0-9\.\_]#', '', (string) $name);

        // capitalize first character of every word convert single spaces to underscrore
        $name = str_replace(" ", "_", ucwords($name));

        return $name;
    }

    /**
     * Get path for the fail image with a suffix
     *
     * @param string $identifier test identifier
     * @param string $suffix suffix added to the filename
     * @return string path to the fail image
     */
    public function getFailImagePath($identifier, $path, $suffix = 'fail'): string
    {
        $fileNameParts = [$suffix, $identifier, 'png'];

        return $this->getFailImageDirectory()
        . $path . DIRECTORY_SEPARATOR
        . $this->sanitizeFilename(implode('.', $fileNameParts));
    }

    /**
     * Get directory where fail images are stored
     */
    public function getFailImageDirectory(): string
    {
        return \Codeception\Configuration::outputDir()
        . rtrim((string) $this->cssRegression->_getConfig('failImageDirectory'), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . $this->cssRegression->getModuleInitTime() . DIRECTORY_SEPARATOR;
    }

    /**
     * Get the directory to store temporary files
     */
    public function getTempDirectory(): string
    {
        return \Codeception\Configuration::outputDir() . 'debug' . DIRECTORY_SEPARATOR;
    }
}
