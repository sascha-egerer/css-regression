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
 * * `templateFolder` Path to the template folder that is used to generate the report. Must contain a Page.html and Item.html file.
 *
 * ``` yaml
 * extensions:
 *     config:
 *         SaschaEgerer\CodeceptionCssRegression\Extension\CssRegressionReporter
 *             templateFolder: /my/path/to/my/templates
 * ```
 *
 */
class CssRegressionReporter extends \Codeception\Extension
{
    static $events = [
        Events::RESULT_PRINT_AFTER => 'resultPrintAfter',
        Events::STEP_AFTER => 'stepAfter',
        Events::SUITE_BEFORE => 'suiteInit'
    ];

    protected $failedIdentifiers = [];

    /**
     * @var FileSystem
     */
    protected $fileSystemUtil;

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

        $this->config['templateFolder'] = rtrim($this->config['templateFolder'],
                DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        parent::__construct($config, $options);
    }

    /**
     * @param SuiteEvent $suiteEvent
     * @throws \Codeception\Exception\ModuleRequireException
     */
    public function suiteInit(SuiteEvent $suiteEvent)
    {
        if(!$this->hasModule($this->getRequiredModuleName())) {
            return;
        }

        /** @var CssRegression $cssRegressionModule */
        $cssRegressionModule = $this->getModule($this->getRequiredModuleName());
        $this->fileSystemUtil = new FileSystem($cssRegressionModule);
    }

    /**
     * @param PrintResultEvent $printResultEvent
     * @throws \Codeception\Exception\ModuleRequireException
     */
    public function resultPrintAfter(PrintResultEvent $printResultEvent)
    {
        if(!$this->hasModule($this->getRequiredModuleName())) {
            return;
        }
        if (count($this->failedIdentifiers) > 0) {
            $items = '';
            $failItemTemplate = new Template(file_get_contents($this->config['templateFolder'] . 'FailItem.html'));
            $newItemTemplate = new Template(file_get_contents($this->config['templateFolder'] . 'NewItem.html'));
            foreach ($this->failedIdentifiers as $vars) {
                $template = $vars['failImage'] === '' ? $newItemTemplate : $failItemTemplate;
                $template->setVars($vars);
                $items .= $template->produce();
            }

            $pageTemplate = new Template(file_get_contents($this->config['templateFolder'] . 'Page.html'));
            $pageTemplate->setVars(array('items' => $items));
            $reportPath = $this->fileSystemUtil->getFailImageDirectory() . 'index.html';

            file_put_contents($reportPath, $pageTemplate->produce());

            $latestLinkPath = dirname($this->fileSystemUtil->getFailImageDirectory()) . '/latest';
            unlink($latestLinkPath);
            symlink(basename($this->fileSystemUtil->getFailImageDirectory()), $latestLinkPath);

            $printResultEvent->getPrinter()->write("\n");
            $printResultEvent->getPrinter()->write('❗Report has been created: ' . $reportPath . "❗\n");
            $printResultEvent->getPrinter()->write("\n");
        }
    }

    /**
     * @param StepEvent $stepEvent
     */
    public function stepAfter(StepEvent $stepEvent)
    {
        if(!$this->hasModule($this->getRequiredModuleName())) {
            return;
        }
        if ($stepEvent->getStep()->hasFailed()  && $stepEvent->getStep()->getAction('seeNoDifferenceToReferenceImage')) {
            /** @var WebDriver $stepWebDriver */
            $stepWebDriver = $stepEvent->getTest()->getScenario()->current('modules')['WebDriver'];
            $identifier = $stepEvent->getStep()->getArguments()[0];
            $windowSize = $this->fileSystemUtil->getCurrentWindowSizeString($stepWebDriver);

            $failImage = $this->fileSystemUtil->getFailImagePath($identifier, $windowSize, 'fail');
            $diffImage = $this->fileSystemUtil->getFailImagePath($identifier, $windowSize, 'diff');

            $this->failedIdentifiers[] = array(
                'identifier' => $identifier,
                'windowSize' => $windowSize,
                'failImage' => (file_exists($failImage)) ? base64_encode(file_get_contents($failImage)) : '',
                'diffImage' => (file_exists($diffImage)) ? base64_encode(file_get_contents($diffImage)) : '',
                'referenceImage' => base64_encode(file_get_contents($this->fileSystemUtil->getReferenceImagePath($identifier, $windowSize)))
            );
        }
    }

    private function getRequiredModuleName(): string
    {
        return '\\' . \SaschaEgerer\CodeceptionCssRegression\Module\CssRegression::class;
    }
}
