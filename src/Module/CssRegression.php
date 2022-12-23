<?php

namespace SaschaEgerer\CodeceptionCssRegression\Module;

use Codeception\Exception\ElementNotFound;
use Codeception\Exception\ModuleException;
use Codeception\Lib\Interfaces\DependsOnModule;
use Codeception\Module;
use Codeception\Module\WebDriver;
use Codeception\Step;
use Codeception\TestCase;
use Codeception\Util\FileSystem;
use Facebook\WebDriver\Remote\RemoteWebElement;
use SaschaEgerer\CodeceptionCssRegression\Util\FileSystem as RegressionFileSystem;

/**
 * Compares a screenshot of an element against a reference image
 *
 * ## Status
 *
 * * Maintainer: **Sascha Egerer**
 * * Stability: **beta**
 * * Contact: sascha.egerer@gmail.com
 *
 * ## Configuration
 *
 * * maxDifference: 0.1 - the maximum difference between 2 images
 * * automaticCleanup: false - defines if the fail image folder should be cleaned up before a new test run is started.
 * * referenceImageDirectory:  - defines the folder where the reference images should be stored
 * * failImageDirectory:  - defines the folder where the fail images should be stored
 */
class CssRegression extends Module implements DependsOnModule
{
    /**
     * @var WebDriver
     */
    protected $webDriver = null;

    /**
     * @var array
     */
    protected $requiredFields = ['referenceImageDirectory', 'failImageDirectory'];

    /**
     * @var array
     */
    protected $config = ['maxDifference' => 0.01, 'automaticCleanup' => true];

    /**
     * @var string
     */
    protected $suitePath = '';

    /**
     * @var int Timestamp when the suite was initialized
     */
    protected static $moduleInitTime = 0;

    /**
     * @var TestCase
     */
    protected $currentTestCase;

    /**
     * @var RegressionFileSystem
     */
    protected $moduleFileSystemUtil;

    /**
     * Elements that have been hidden for the current suite
     *
     * @var array
     */
    protected $hiddenSuiteElements;

    /**
     * Initialize the module after configuration has been loaded
     */
    public function _initialize()
    {
        if (!class_exists('\\Imagick')) {
            throw new ModuleException(__CLASS__,
                'Required class \\Imagick could not be found!
                Please install the PHP Image Magick extension to use this module.'
            );
        }

        $this->moduleFileSystemUtil = new RegressionFileSystem($this);

        if (self::$moduleInitTime === 0) {
            self::$moduleInitTime = time();

            if ($this->config['automaticCleanup'] === true && is_dir(dirname($this->moduleFileSystemUtil->getFailImageDirectory()))) {
                // cleanup fail image directory
                FileSystem::doEmptyDir(dirname($this->moduleFileSystemUtil->getFailImageDirectory()));
            }
        }

        $this->moduleFileSystemUtil->createDirectoryRecursive($this->moduleFileSystemUtil->getTempDirectory());
        $this->moduleFileSystemUtil->createDirectoryRecursive($this->moduleFileSystemUtil->getReferenceImageDirectory());
        $this->moduleFileSystemUtil->createDirectoryRecursive($this->moduleFileSystemUtil->getFailImageDirectory());
    }

    /**
     * Specifies class or module which is required for current one.
     *
     * @return array
     */
    public function _depends()
    {
        return array('\\Codeception\\Module\\WebDriver' => 'This module requires the WebDriver module');
    }

    /**
     * Before each suite
     *
     * @param array $settings
     */
    public function _beforeSuite($settings = [])
    {
        $this->suitePath = $settings['path'];
        $this->hiddenSuiteElements = array();
    }

    /**
     * Before each scenario
     *
     * @param TestCase $test
     */
    public function _before(TestCase $test)
    {
        $this->currentTestCase = $test;
    }

    /**
     * @param WebDriver $browser
     */
    public function _inject(WebDriver $browser)
    {
        $this->webDriver = $browser;
    }

    /**
     * After each step
     *
     * @param Step $step
     */
    public function _afterStep(Step $step)
    {
        if ($step->getAction() === 'seeNoDifferenceToReferenceImage') {
            // cleanup the temp image
            if (file_exists($this->moduleFileSystemUtil->getTempImagePath($step->getArguments()[0]))) {
                @unlink($this->moduleFileSystemUtil->getTempImagePath($step->getArguments()[0]));
            }
        }
    }

    /**
     * Checks item in Memcached exists and the same as expected.
     *
     * @param string $referenceImageIdentifier
     * @param null|string $selector
     * @param null|float $maxDifference
     * @param null|string $referenceImagePath
     * @throws ModuleException
     */
    public function seeNoDifferenceToReferenceImage(
        string $referenceImageIdentifier,
        string $selector = null,
        float $maxDifference = null,
        string $referenceImagePath = null
    ) {
        if ($selector === null) {
            $selector = 'body';
        }

        $elements = $this->webDriver->_findElements($selector);

        if (count($elements) == 0) {
            throw new ElementNotFound($selector);
        } elseif (count($elements) > 1) {
            throw new ModuleException(__CLASS__,
                'Multiple elements found for given selector "' . $selector . '" but need exactly one element!');
        }
        /** @var RemoteWebElement $element */
        $image = $this->_createScreenshot($referenceImageIdentifier, reset($elements));

        $windowSizeString = $this->moduleFileSystemUtil->getCurrentWindowSizeString($this->webDriver);
        $referenceImagePath = $this->moduleFileSystemUtil->getReferenceImagePath(
            $referenceImageIdentifier,
            $referenceImagePath
        );

        if (!file_exists($referenceImagePath)) {
            // Ensure that the target directory exists
            $this->moduleFileSystemUtil->createDirectoryRecursive(dirname($referenceImagePath));
            copy($image->getImageFilename(), $referenceImagePath);
            $this->markTestIncomplete('Reference Image does not exist. Test is skipped but will now copy reference image to target directory...');
        } else {
            $referenceImage = new \Imagick($referenceImagePath);

            /** @var \Imagick $comparedImage */
            list($comparedImage, $difference) = $referenceImage->compareImages($image,
                \Imagick::METRIC_MEANSQUAREERROR);

            $calculatedDifferenceValue = round((float)substr($difference, 0, 6) * 100, 2);

            $this->_getCurrentTestCase()->getScenario()->comment(
                'Difference between reference and current image is around ' . $calculatedDifferenceValue . '%'
            );

            $maxDifference ??= $this->config['maxDifference'];
            if ($calculatedDifferenceValue > $maxDifference) {
                $diffImagePath = $this->moduleFileSystemUtil->getFailImagePath($referenceImageIdentifier, $windowSizeString, 'diff');

                $this->moduleFileSystemUtil->createDirectoryRecursive(dirname($diffImagePath));

                $image->writeImage($this->moduleFileSystemUtil->getFailImagePath($referenceImageIdentifier, $windowSizeString, 'fail'));
                $comparedImage->setImageFormat('png');
                $comparedImage->writeImage($diffImagePath);
                $this->fail('Image does not match to the reference image.');
            } else {
                // do an assertion to get correct assertion count
                $this->assertTrue(true);
            }
        }
    }

    public function hideElements($selector)
    {
        $selectedElements = $this->webDriver->_findElements($selector);

        foreach ($selectedElements as $element) {
            $elementVisibility = $element->getCSSValue('visibility');

            if ($elementVisibility != 'hidden') {
                $this->hiddenSuiteElements[$element->getID()] = array(
                    'visibilityBackup' => $elementVisibility,
                    'element' => $element
                );
                $this->webDriver->webDriver->executeScript(
                    'arguments[0].style.visibility = \'hidden\';',
                    array($element)
                );
            }
        }
    }

    /**
     * Will unhide the element for the given selector or unhide all elements that have been set to hidden before if
     * no selector is given.
     *
     * @param string|null $selector The selector of the element that should be unhidden nor null if all elements should
     * be unhidden that have been set to hidden before.
     */
    public function unhideElements($selector = null)
    {
        if ($selector === null) {
            foreach ($this->hiddenSuiteElements as $elementData) {
                $this->webDriver->webDriver->executeScript(
                    'arguments[0].style.visibility = \'' . $elementData['visibilityBackup'] . '\';',
                    array($elementData['element'])
                );
            }

            $this->hiddenSuiteElements = array();
        } else {
            $elements = $this->webDriver->_findElements($selector);
            foreach ($elements as $element) {
                if (isset($this->hiddenSuiteElements[$element->getID()])) {
                    $visibility = $this->hiddenSuiteElements[$element->getID()]['visibilityBackup'];
                    unset($this->hiddenSuiteElements[$element->getID()]);
                } else {
                    $visibility = 'visible';
                }
                $this->webDriver->webDriver->executeScript(
                    'arguments[0].style.visibility = \'' . $visibility . '\';',
                    array($element)
                );
            }
        }
    }

    /**
     * Create screenshot for an element
     *
     * @param string $referenceImageName
     * @param RemoteWebElement $element
     * @return \Imagick
     */
    protected function _createScreenshot($referenceImageName, RemoteWebElement $element)
    {
        // Try scrolling the element into the view port
        $element->getLocationOnScreenOnceScrolledIntoView();

        $tempImagePath = $this->moduleFileSystemUtil->getTempImagePath($referenceImageName);
        $this->webDriver->webDriver->takeScreenshot($tempImagePath);

        $image = new \Imagick($tempImagePath);
        $image->cropImage(
            $element->getSize()->getWidth(),
            $element->getSize()->getHeight(),
            $element->getCoordinates()->onPage()->getX(),
            $element->getCoordinates()->onPage()->getY()
        );
        $image->setImageFormat('png');
        $image->writeImage($tempImagePath);

        return $image;
    }

    /**
     * @return null|TestCase
     */
    public function _getCurrentTestCase()
    {
        if ($this->currentTestCase instanceof TestCase) {
            return $this->currentTestCase;
        }
        return null;
    }

    /**
     * The time when the module has been initalized
     *
     * @return int timestamp
     */
    public function _getModuleInitTime()
    {
        return self::$moduleInitTime;
    }

    /**
     * @return string
     */
    public function _getSuitePath()
    {
        return $this->suitePath;
    }

    public function _getWebdriver()
    {
        return $this->webDriver;
    }
}
