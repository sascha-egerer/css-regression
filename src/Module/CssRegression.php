<?php

namespace SaschaEgerer\CodeceptionCssRegression\Module;

use Codeception\Exception\ModuleException;
use Codeception\Lib\Interfaces\DependsOnModule;
use Codeception\Lib\Interfaces\ElementLocator;
use Codeception\Lib\Interfaces\ScreenshotSaver;
use Codeception\Module;
use Codeception\Module\WebDriver;
use Codeception\Step;
use Codeception\Util\FileSystem;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\RemoteWebElement;
use Facebook\WebDriver\WebDriverBy;
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
final class CssRegression extends Module implements DependsOnModule
{
    /**
     * @var array{maxDifference: float, automaticCleanup: bool, module: string}
     */
    protected $config = [
        'maxDifference' => 0.01,
        'automaticCleanup' => true,
        'module' => 'WebDriver',
    ];

    private ScreenshotSaver|RemoteWebDriver|ElementLocator $webDriver;

    /**
     * @var array
     */
    protected $requiredFields = [
        'referenceImageDirectory',
        'failImageDirectory'
    ];

    /**
     * @var int Timestamp when the suite was initialized
     */
    public static int $moduleInitTime = 0;

    private ?RegressionFileSystem $regressionFileSystem = null;

    /**
     * Elements that have been hidden for the current suite
     */
    private array $hiddenSuiteElements = [];

    /**
     * Initialize the module after configuration has been loaded
     */
    public function _initialize()
    {
        if (!class_exists(\Imagick::class)) {
            throw new ModuleException(self::class,
                'Required class \\Imagick could not be found!
                Please install the PHP Image Magick extension to use this module.'
            );
        }

        $this->regressionFileSystem = new RegressionFileSystem($this);

        if (self::$moduleInitTime === 0) {
            self::$moduleInitTime = time();

            if ($this->config['automaticCleanup'] && is_dir(dirname($this->regressionFileSystem->getFailImageDirectory()))) {
                // cleanup fail image directory
                FileSystem::doEmptyDir(dirname($this->regressionFileSystem->getFailImageDirectory()));
            }
        }

        $this->regressionFileSystem->createDirectoryRecursive($this->regressionFileSystem->getTempDirectory());
        $this->regressionFileSystem->createDirectoryRecursive($this->regressionFileSystem->getReferenceImageDirectory());
        $this->regressionFileSystem->createDirectoryRecursive($this->regressionFileSystem->getFailImageDirectory());
    }

    public function getModuleInitTime(): int
    {
        return self::$moduleInitTime;
    }

    /**
     * Specifies class or module which is required for current one.
     */
    public function _depends(): array
    {
        return [
            WebDriver::class => 'This module requires the WebDriver module'
        ];
    }

    public function _inject(WebDriver $browser)
    {
        $this->webDriver = $browser;
    }

    /**
     * Before each suite
     *
     * @param array $settings
     */
    public function _beforeSuite($settings = []): void
    {
        $webDriverModule = $this->getModule($this->config['module']);
        if (
             !is_a($webDriverModule, ScreenshotSaver::class)
            || !is_a($webDriverModule, ElementLocator::class)
        ) {
            throw new ModuleException($this, 'Config module must implement ElementLocator and ScreenshotSaver');
        }
        $this->webDriver = $webDriverModule;
        $this->hiddenSuiteElements = [];
    }

    public function _afterStep(Step $step): void
    {
        // cleanup the temp image
        if ($step->getAction() !== 'seeNoDifferenceToReferenceImage') {
            return;
        }

        $tempImage = $this->getTempImagePath($step->getArguments()[0], $step->getArguments()[3]);

        if (!file_exists($tempImage)) {
            return;
        }

        @unlink($tempImage);
    }

    private function getTempImagePath(string $fileName, string $path): string
    {
        return $this->regressionFileSystem->getTempDirectory() .
            DIRECTORY_SEPARATOR .
            $path .
            DIRECTORY_SEPARATOR .
            $this->regressionFileSystem->sanitizeFilename($fileName);
    }

    public function seeNoDifferenceToReferenceImage(
        string                   $referenceImageIdentifier,
        WebDriverBy|string|array $selector = null,
        float                    $maxDifference = null,
        string                   $referenceImagePath = null
    ): void
    {
        if ($selector === null) {
            $selector = 'body';
        }

        $elements = $this->webDriver->_findElements($selector);

        if (count($elements) > 1) {
            throw new ModuleException(self::class,
                'Multiple elements found for given selector "' . $selector . '" but need exactly one element!');
        }

        $image = $this->_createScreenshot(
            $this->getTempImagePath($referenceImageIdentifier, $referenceImagePath),
            reset($elements)
        );

        $referenceImagePath = $this->regressionFileSystem->getReferenceImagePath(
            $referenceImageIdentifier,
            $referenceImagePath
        );

        if (!file_exists($referenceImagePath)) {
            // Ensure that the target directory exists
            $this->regressionFileSystem->createDirectoryRecursive(dirname($referenceImagePath));
            copy($image->getImageFilename(), $referenceImagePath);
            $this->markTestIncomplete('Reference Image does not exist. Test is skipped but will now copy reference image to target directory...');
        } else {
            $imagick = new \Imagick($referenceImagePath);

            /** @var \Imagick $comparedImage */
            [$comparedImage, $difference] = $imagick->compareImages($image, \Imagick::METRIC_MEANSQUAREERROR);

            $calculatedDifferenceValue = round((float)substr((string)$difference, 0, 6) * 100, 2);

            $maxDifference ??= $this->config['maxDifference'];
            if ($calculatedDifferenceValue > $maxDifference) {
                $diffImagePath = $this->regressionFileSystem->getFailImagePath($referenceImageIdentifier, $referenceImagePath, 'diff');

                $this->regressionFileSystem->createDirectoryRecursive(dirname($diffImagePath));

                $image->writeImage($this->regressionFileSystem->getFailImagePath($referenceImageIdentifier, $referenceImagePath, 'fail'));

                $comparedImage->setImageFormat('png');
                $comparedImage->writeImage($diffImagePath);
                $this->fail('Image does not match to the reference image.');
            } else {
                // do an assertion to get correct assertion count
                $this->assertTrue(true);
            }
        }
    }

    public function hideElements(WebDriverBy|string|array $selector): void
    {
        $selectedElements = $this->webDriver->_findElements($selector);

        foreach ($selectedElements as $selectedElement) {
            $elementVisibility = $selectedElement->getCSSValue('visibility');

            if ($elementVisibility != 'hidden') {
                $this->hiddenSuiteElements[$selectedElement->getID()] = ['visibilityBackup' => $elementVisibility, 'element' => $selectedElement];
                $this->webDriver->executeScript(
                    "arguments[0].style.visibility = 'hidden';",
                    [$selectedElement]
                );
            }
        }
    }

    /**
     * Will unhide the element for the given selector or unhide all elements that have been set to hidden before if
     * no selector is given.
     *
     * @param WebDriverBy|string|array $selector The selector of the element that should be unhidden nor null if all elements should
     * be unhidden that have been set to hidden before.
     */
    public function unhideElements(WebDriverBy|string|array $selector = null): void
    {
        if ($selector === null) {
            foreach ($this->hiddenSuiteElements as $hiddenSuiteElement) {
                $this->webDriver->executeScript(
                    "arguments[0].style.visibility = '" . $hiddenSuiteElement['visibilityBackup'] . "';",
                    [$hiddenSuiteElement['element']]
                );
            }

            $this->hiddenSuiteElements = [];
        } else {
            $elements = $this->webDriver->_findElements($selector);
            foreach ($elements as $element) {
                if (isset($this->hiddenSuiteElements[$element->getID()])) {
                    $visibility = $this->hiddenSuiteElements[$element->getID()]['visibilityBackup'];
                    unset($this->hiddenSuiteElements[$element->getID()]);
                } else {
                    $visibility = 'visible';
                }

                $this->webDriver->executeScript(
                    "arguments[0].style.visibility = '" . $visibility . "';",
                    [$element]
                );
            }
        }
    }

    /**
     * Create screenshot for an element
     *
     * @param RemoteWebElement $remoteWebElement
     */
    private function _createScreenshot(string $tempImagePath, RemoteWebElement $remoteWebElement): \Imagick
    {
        // Try scrolling the element into the view port
        $remoteWebElement->getLocationOnScreenOnceScrolledIntoView();

        $this->webDriver->_saveScreenshot($tempImagePath);

        $imagick = new \Imagick($tempImagePath);
        $imagick->cropImage(
            $remoteWebElement->getSize()->getWidth(),
            $remoteWebElement->getSize()->getHeight(),
            $remoteWebElement->getCoordinates()->onPage()->getX(),
            $remoteWebElement->getCoordinates()->onPage()->getY()
        );
        $imagick->setImageFormat('png');
        $imagick->writeImage($tempImagePath);

        return $imagick;
    }
}
