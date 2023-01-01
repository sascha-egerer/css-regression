<?php

namespace SaschaEgerer\CodeceptionCssRegression\Extension;

use Codeception\Event\PrintResultEvent;
use Codeception\Event\StepEvent;
use Codeception\Event\SuiteEvent;
use Codeception\Events;
use Codeception\Module\WebDriver;
use Codeception\PHPUnit\ResultPrinter;
use Codeception\Util\Template;
use SaschaEgerer\CodeceptionCssRegression\Module\CssRegression;
use SaschaEgerer\CodeceptionCssRegression\Util\FileSystem;

/**
 * Generates an html file with all failed tests that contains the reference image, failed image and diff image.
 *
 * #### Installation
 *
 * Add to list of enabled extensions
 *
 * ``` yaml
 * extensions:
 *      enabled:
 *          - SaschaEgerer\CodeceptionCssRegression\Extension\CssRegressionReporter
 * ```
 *
 * #### Configuration
 *
 * * `templateFolder` Path to the template folder that is used to generate the report. Must contain a Page.html, FailItemNewl and FailItem.html file.
 *
 * ``` yaml
 * extensions:
 *     config:
 *         SaschaEgerer\CodeceptionCssRegression\Extension\CssRegressionReporter
 *             templateFolder: /my/path/to/my/templates
 * ```
 *
 */
final class CssRegressionReporter extends \Codeception\Extension
{
    static $events = [
        Events::RESULT_PRINT_AFTER => 'resultPrintAfter',
        Events::STEP_AFTER => 'stepAfter',
        Events::SUITE_BEFORE => 'suiteInit'
    ];

    private array $failedIdentifiers = [];

    private ?\SaschaEgerer\CodeceptionCssRegression\Util\FileSystem $fileSystem = null;

    protected $config = [
        'templateFolder' => null
    ];

    /**
     * @param $config
     * @param $options
     */
    function __construct($config, $options)
    {
        if (empty($this->config['templateFolder'])) {
            $this->config['templateFolder'] = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'Templates';
        }

        $this->config['templateFolder'] = rtrim((string)$this->config['templateFolder'],
                DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        parent::__construct($config, $options);
    }

    /**
     * @throws \Codeception\Exception\ModuleRequireException
     */
    public function suiteInit(SuiteEvent $suiteEvent): void
    {
        if (!$this->hasModule($this->getRequiredModuleName())) {
            return;
        }

        /** @var CssRegression $cssRegressionModule */
        $cssRegressionModule = $this->getModule($this->getRequiredModuleName());
        $this->fileSystem = new FileSystem($cssRegressionModule);
    }

    /**
     * @throws \Codeception\Exception\ModuleRequireException
     */
    public function resultPrintAfter(PrintResultEvent $printResultEvent): void
    {
        if (!$this->hasModule($this->getRequiredModuleName())) {
            return;
        }

        if ($this->failedIdentifiers !== []) {
            $items = '';
            $failItemTemplate = new Template(file_get_contents($this->config['templateFolder'] . 'FailItem.html'));
            $newItemTemplate = new Template(file_get_contents($this->config['templateFolder'] . 'NewItem.html'));
            foreach ($this->failedIdentifiers as $failedIdentifier) {
                $template = $failedIdentifier['failImage'] === '' ? $newItemTemplate : $failItemTemplate;
                $template->setVars($failedIdentifier);
                $items .= $template->produce();
            }

            $pageTemplate = new Template(file_get_contents($this->config['templateFolder'] . 'Page.html'));
            $pageTemplate->setVars(['items' => $items]);
            $reportPath = $this->fileSystem->getFailImageDirectory() . 'index.html';

            file_put_contents($reportPath, $pageTemplate->produce());
            copy($this->config['templateFolder'] . 'index.css', $this->fileSystem->getFailImageDirectory() . 'index.css');
            copy($this->config['templateFolder'] . 'index.js', $this->fileSystem->getFailImageDirectory() . 'index.js');

            $latestLinkPath = dirname($this->fileSystem->getFailImageDirectory()) . '/latest';
            if (is_link($latestLinkPath)) {
                @unlink($latestLinkPath);
            }

            symlink(basename($this->fileSystem->getFailImageDirectory()), $latestLinkPath);

            $printResultEvent->getPrinter()->write("\n");
            $printResultEvent->getPrinter()->write('❗Report has been created: ' . $latestLinkPath . "/index.html ❗\n");
            $printResultEvent->getPrinter()->write("\n");
        }
    }

    public function stepAfter(StepEvent $stepEvent): void
    {
        if (!$this->hasModule($this->getRequiredModuleName())) {
            return;
        }

        if ($stepEvent->getStep()->hasFailed() && $stepEvent->getStep()->getAction() === 'seeNoDifferenceToReferenceImage') {
            /** @var WebDriver $stepWebDriver */
            $stepWebDriver = $this->getModule('WebDriver');
            $identifier = $stepEvent->getStep()->getArguments()[0] ?? '';
            $referenceImagePath = $stepEvent->getStep()->getArguments()[3] ?? '';

            $failImage = $this->fileSystem->getFailImagePath($identifier, $referenceImagePath, 'fail');
            $diffImage = $this->fileSystem->getFailImagePath($identifier, $referenceImagePath, 'diff');

            $this->failedIdentifiers[] = [
                'identifier' => $identifier,
                'referenceImagePath' => $referenceImagePath,
                'windowSize' => $this->fileSystem->getCurrentWindowSizeString($stepWebDriver),
                'failImage' => $this->getImageAsBase64($failImage),
                'diffImage' => $this->getImageAsBase64($diffImage),
                'referenceImage' => $this->getImageAsBase64(
                    $this->fileSystem->getReferenceImagePath($identifier, $referenceImagePath)
                )
            ];
        }
    }

    private function getImageAsBase64(string $imagePath): string
    {
        if (!file_exists($imagePath)) {
            return '';
        }

        return base64_encode(file_get_contents($imagePath));
    }

    private function getRequiredModuleName(): string
    {
        return '\\' . \SaschaEgerer\CodeceptionCssRegression\Module\CssRegression::class;
    }
}
