<?php

namespace SilverStripe\BehatExtension\Context;

use Behat\Behat\Context\Context;
use Behat\Behat\Definition\Call;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\AfterStepScope;
use Behat\Behat\Hook\Scope\BeforeStepScope;
use Behat\Behat\Hook\Scope\StepScope;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Session;
use Behat\Testwork\Tester\Result\TestResult;
use Exception;
use Facebook\WebDriver\Exception\WebDriverException;
use Facebook\WebDriver\WebDriver;
use Facebook\WebDriver\WebDriverAlert;
use Facebook\WebDriver\WebDriverExpectedCondition;
use InvalidArgumentException;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Filesystem;
use SilverStripe\BehatExtension\Utility\StepHelper;
use SilverStripe\MinkFacebookWebDriver\FacebookWebDriver;

/**
 * BasicContext
 *
 * Context used to define generic steps like following anchors or pressing buttons.
 * Handles timeouts.
 * Handles redirections.
 * Handles AJAX enabled links, buttons and forms - jQuery is assumed.
 */
class BasicContext implements Context
{
    use MainContextAwareTrait;
    use StepHelper;

    /**
     * Date format in date() syntax
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d';

    /**
     * Time format in date() syntax
     * @var String
     */
    protected $timeFormat = 'H:i:s';

    /**
     * Date/time format in date() syntax
     * @var String
     */
    protected $datetimeFormat = 'Y-m-d H:i:s';

    /**
     * Get Mink session from MinkContext
     *
     * @param string $name
     * @return Session
     */
    public function getSession($name = null)
    {
        /** @var SilverStripeContext $context */
        $context = $this->getMainContext();
        return $context->getSession($name);
    }

    /**
     * @AfterStep
     *
     * Excluding scenarios with @modal tag is required,
     * because modal dialogs stop any JS interaction
     *
     * @param AfterStepScope $event
     */
    public function appendErrorHandlerBeforeStep(AfterStepScope $event)
    {
        // Manually exclude @modal
        if ($this->stepHasTag($event, 'modal')) {
            return;
        }

        try {
            $javascript = <<<JS
window.onerror = function(message, file, line, column, error) {
    var body = document.getElementsByTagName('body')[0];
	var msg = message + " in " + file + ":" + line + ":" + column;
	if(error !== undefined && error.stack !== undefined) {
		msg += "\\nSTACKTRACE:\\n" + error.stack;
	}
    body.setAttribute('data-jserrors', '[captured JavaScript error] ' + msg);
};
if ('undefined' !== typeof window.jQuery) {
    window.jQuery('body').ajaxError(function(event, jqxhr, settings, exception) {
        if ('abort' === exception) {
            return;
        }
        window.onerror(event.type + ': ' + settings.type + ' ' + settings.url + ' ' + exception + ' ' + jqxhr.responseText);
    });
}
JS;

            $this->getSession()->executeScript($javascript);
        } catch (WebDriverException $e) {
            $this->logException($e);
        }
    }

    /**
     * @AfterStep
     *
     * Excluding scenarios with @modal tag is required,
     * because modal dialogs stop any JS interaction
     *
     * @param AfterStepScope $event
     */
    public function readErrorHandlerAfterStep(AfterStepScope $event)
    {
        // Manually exclude @modal
        if ($this->stepHasTag($event, 'modal')) {
            return;
        }
        try {
            $page = $this->getSession()->getPage();

            $jserrors = $page->find('xpath', '//body[@data-jserrors]');
            if (null !== $jserrors) {
                $this->takeScreenshot($event);
                file_put_contents('php://stderr', $jserrors->getAttribute('data-jserrors') . PHP_EOL);
            }

            $javascript = <<<JS
if ('undefined' !== typeof window.jQuery) {
	window.jQuery(document).ready(function() {
		window.jQuery('body').removeAttr('data-jserrors');
	});
}
JS;

            $this->getSession()->executeScript($javascript);
        } catch (WebDriverException $e) {
            $this->logException($e);
        }
    }

    /**
     * Hook into jQuery ajaxStart, ajaxSuccess and ajaxComplete events.
     * Prepare __ajaxStatus() functions and attach them to these handlers.
     * Event handlers are removed after one run.
     *
     * @BeforeStep
     * @param BeforeStepScope $event
     */
    public function handleAjaxBeforeStep(BeforeStepScope $event)
    {
        // Manually exclude @modal
        if ($this->stepHasTag($event, 'modal')) {
            return;
        }
        try {
            $ajaxEnabledSteps = $this->getMainContext()->getAjaxSteps();
            $ajaxEnabledSteps = implode('|', array_filter($ajaxEnabledSteps));

            if (empty($ajaxEnabledSteps) || !preg_match('/(' . $ajaxEnabledSteps . ')/i', $event->getStep()->getText())) {
                return;
            }

            $javascript = <<<JS
if ('undefined' !== typeof window.jQuery && 'undefined' !== typeof window.jQuery.fn.on) {
    window.jQuery(document).on('ajaxStart.ss.test.behaviour', function(){
        window.__ajaxStatus = function() {
            return 'waiting';
        };
    });
    window.jQuery(document).on('ajaxComplete.ss.test.behaviour', function(e, jqXHR){
        if (null === jqXHR.getResponseHeader('X-ControllerURL')) {
            window.__ajaxStatus = function() {
                return 'no ajax';
            };
        }
    });
    window.jQuery(document).on('ajaxSuccess.ss.test.behaviour', function(e, jqXHR){
        if (null === jqXHR.getResponseHeader('X-ControllerURL')) {
            window.__ajaxStatus = function() {
                return 'success';
            };
        }
    });
}
JS;
            $this->getSession()->wait(500); // give browser a chance to process and render response
            $this->getSession()->executeScript($javascript);
        } catch (WebDriverException $e) {
            $this->logException($e);
        }
    }

    /**
     * Wait for the __ajaxStatus()to return anything but 'waiting'.
     * Don't wait longer than 5 seconds.
     *
     * Don't unregister handler if we're dealing with modal windows
     *
     * @AfterStep
     * @param AfterStepScope $event
     */
    public function handleAjaxAfterStep(AfterStepScope $event)
    {
        // Manually exclude @modal
        if ($this->stepHasTag($event, 'modal')) {
            return;
        }
        try {
            $ajaxEnabledSteps = $this->getMainContext()->getAjaxSteps();
            $ajaxEnabledSteps = implode('|', array_filter($ajaxEnabledSteps));

            if (empty($ajaxEnabledSteps) || !preg_match('/(' . $ajaxEnabledSteps . ')/i', $event->getStep()->getText())) {
                return;
            }

            $this->handleAjaxTimeout();

            $javascript = <<<JS
if ('undefined' !== typeof window.jQuery && 'undefined' !== typeof window.jQuery.fn.off) {
window.jQuery(document).off('ajaxStart.ss.test.behaviour');
window.jQuery(document).off('ajaxComplete.ss.test.behaviour');
window.jQuery(document).off('ajaxSuccess.ss.test.behaviour');
}
JS;
            $this->getSession()->executeScript($javascript);
        } catch (WebDriverException $e) {
            $this->logException($e);
        }
    }

    public function handleAjaxTimeout()
    {
        $timeoutMs = $this->getMainContext()->getAjaxTimeout();

        // Wait for an ajax request to complete, but only for a maximum of 5 seconds to avoid deadlocks
        $this->getSession()->wait(
            $timeoutMs,
            "(typeof window.__ajaxStatus !== 'undefined' ? window.__ajaxStatus() : 'no ajax') !== 'waiting'"
        );

        // wait additional 100ms to allow DOM to update
        $this->getSession()->wait(100);
    }

    /**
     * Take screenshot when step fails.
     * Works only with FacebookWebDriver.
     *
     * @AfterStep
     * @param AfterStepScope $event
     */
    public function takeScreenshotAfterFailedStep(AfterStepScope $event)
    {
        // Check failure code
        if ($event->getTestResult()->getResultCode() !== TestResult::FAILED) {
            return;
        }
        try {
            $this->takeScreenshot($event);
        } catch (WebDriverException $e) {
            $this->logException($e);
        }
    }

    /**
     * Close modal dialog if test scenario fails on CMS page
     *
     * @AfterScenario
     * @param AfterScenarioScope $event
     */
    public function closeModalDialog(AfterScenarioScope $event)
    {
        $expectsUnsavedChangesModal = $this->stepHasTag($event, 'unsavedChanges');
        
        try {
            // Only for failed tests on CMS page
            if ($expectsUnsavedChangesModal || $event->getTestResult()->getResultCode() === TestResult::FAILED) {
                $cmsElement = $this->getSession()->getPage()->find('css', '.cms');
                if ($cmsElement) {
                    try {
                        // Navigate away triggered by reloading the page
                        $this->getSession()->reload();
                        $this->getExpectedAlert()->accept();
                    } catch (WebDriverException $e) {
                        // no-op, alert might not be present
                    }
                }
            }
        } catch (WebDriverException $e) {
            $this->logException($e);
        }
    }

    /**
     * Delete any created files and folders from assets directory
     *
     * @AfterScenario @assets
     * @param AfterScenarioScope $event
     */
    public function cleanAssetsAfterScenario(AfterScenarioScope $event)
    {
        foreach (File::get() as $file) {
            $file->delete();
        }
        Filesystem::removeFolder(ASSETS_PATH, true);
    }

    /**
     * Take a nice screenshot
     *
     * @param StepScope $event
     */
    public function takeScreenshot(StepScope $event)
    {
        // Validate driver
        $driver = $this->getSession()->getDriver();
        if (!($driver instanceof FacebookWebDriver)) {
            file_put_contents('php://stdout', 'ScreenShots are only supported for FacebookWebDriver: skipping');
            return;
        }

        $feature = $event->getFeature();
        $step = $event->getStep();
        $screenshotPath = null;

        // Check paths are configured
        $path = $this->getMainContext()->getScreenshotPath();
        if (!$path) {
            file_put_contents('php://stdout', 'ScreenShots path not configured: skipping');
            return;
        }

        Filesystem::makeFolder($path);
        $path = realpath($path);

        if (!file_exists($path)) {
            file_put_contents('php://stderr', sprintf('"%s" is not valid directory and failed to create it' . PHP_EOL, $path));
            return;
        }

        if (file_exists($path) && !is_dir($path)) {
            file_put_contents('php://stderr', sprintf('"%s" is not valid directory' . PHP_EOL, $path));
            return;
        }
        if (file_exists($path) && !is_writable($path)) {
            file_put_contents('php://stderr', sprintf('"%s" directory is not writable' . PHP_EOL, $path));
            return;
        }

        $path = sprintf('%s/%s_%d.png', $path, basename($feature->getFile()), $step->getLine());
        $screenshot = $driver->getScreenshot();
        file_put_contents($path, $screenshot);
        file_put_contents('php://stderr', sprintf('Saving screenshot into %s' . PHP_EOL, $path));
    }

    /**
     * @Given /^the page can't be found/
     */
    public function stepPageCantBeFound()
    {
        $page = $this->getSession()->getPage();
        assertTrue(
            // Content from ErrorPage default record
            $page->hasContent('Page not found')
            // Generic ModelAsController message
            || $page->hasContent('The requested page could not be found')
        );
    }

    /**
     * @Given /^I wait (?:for )?([\d\.]+) second(?:s?)$/
     *
     * @param float $secs
     */
    public function stepIWaitFor($secs)
    {
        $this->getSession()->wait((float)$secs*1000);
    }

    /**
     * Find visible button with the given text.
     * Supports data-text-alternate property.
     *
     * @param string $title
     * @return NodeElement|null
     */
    protected function findNamedButton($title)
    {
        $page = $this->getSession()->getPage();
        // See https://mathiasbynens.be/notes/css-escapes
        $escapedTitle = addcslashes($title, '!"#$%&\'()*+,-./:;<=>?@[\]^`{|}~');
        $matchedEl = null;
        $searches = [
            ['named', ['link_or_button', "'{$title}'"]],
            ['css', "button[data-text-alternate='{$escapedTitle}']"],
        ];
        foreach ($searches as list($type, $arg)) {
            $buttons = $page->findAll($type, $arg);
            /** @var NodeElement $button */
            foreach ($buttons as $button) {
                if ($button->isVisible()) {
                    return $button;
                }
            }
        }
        return null;
    }

    /**
     * Example: I should see a "Submit" button
     * Example: I should not see a "Delete" button
     *
     * @Given /^I should( not? |\s*)see (?:a|an|the) "([^"]*)" button$/
     * @param string $negative
     * @param string $text
     */
    public function iShouldSeeAButton($negative, $text)
    {
        $button = $this->findNamedButton($text);
        if (trim($negative)) {
            assertNull($button, sprintf('%s button found', $text));
        } else {
            assertNotNull($button, sprintf('%s button not found', $text));
        }
    }

    /**
     * @Given /^I press the "([^"]*)" button$/
     * @param string $text
     */
    public function stepIPressTheButton($text)
    {
        $button = $this->findNamedButton($text);
        assertNotNull($button, "{$text} button not found");
        $button->click();
    }

    /**
     * @Given /^I press the "([^"]*)" buttons$/
     * @param string $text A list of button names can be provided by seperating the entries with the | character.
     */
    public function stepIPressTheButtons($text)
    {
        $buttonNames = explode('|', $text);
        foreach ($buttonNames as $name) {
            $button = $this->findNamedButton(trim($name));
            if ($button) {
                break;
            }
        }

        assertNotNull($button, "{$text} button not found");
        $button->click();
    }

    /**
     * Needs to be in single command to avoid "unexpected alert open" errors in Selenium.
     * Example1: I press the "Remove current combo" button, confirming the dialog
     * Example2: I follow the "Remove current combo" link, confirming the dialog
     *
     * @Given /^I (?:press|follow) the "([^"]*)" (?:button|link), confirming the dialog$/
     * @param string $button
     */
    public function stepIPressTheButtonConfirmingTheDialog($button)
    {
        $this->stepIPressTheButton($button);
        $this->iConfirmTheDialog();
    }

    /**
     * Needs to be in single command to avoid "unexpected alert open" errors in Selenium.
     * Example: I follow the "Remove current combo" link, dismissing the dialog
     *
     * @Given /^I (?:press|follow) the "([^"]*)" (?:button|link), dismissing the dialog$/
     * @param string $button
     */
    public function stepIPressTheButtonDismissingTheDialog($button)
    {
        $this->stepIPressTheButton($button);
        $this->iDismissTheDialog();
    }

    /**
     * @Given /^I (click|double click) "([^"]*)" in the "([^"]*)" element$/
     * @param string $clickType
     * @param string $text
     * @param string $selector
     */
    public function iClickInTheElement($clickType, $text, $selector)
    {
        $clickTypeMap = array(
            "double click" => "doubleclick",
            "click" => "click"
        );
        $page = $this->getSession()->getPage();
        $parentElement = $page->find('css', $selector);
        assertNotNull($parentElement, sprintf('"%s" element not found', $selector));
        $element = $parentElement->find('xpath', sprintf('//*[count(*)=0 and contains(.,"%s")]', $text));
        assertNotNull($element, sprintf('"%s" not found', $text));
        $clickTypeFn = $clickTypeMap[$clickType];
        $element->$clickTypeFn();
    }

    /**
     * Needs to be in single command to avoid "unexpected alert open" errors in Selenium.
     * Example: I click "Delete" in the ".actions" element, confirming the dialog
     *
     * @Given /^I (click|double click) "([^"]*)" in the "([^"]*)" element, confirming the dialog$/
     * @param string $clickType
     * @param string $text
     * @param string $selector
     */
    public function iClickInTheElementConfirmingTheDialog($clickType, $text, $selector)
    {
        $this->iClickInTheElement($clickType, $text, $selector);
        $this->iConfirmTheDialog();
    }

    /**
     * Needs to be in single command to avoid "unexpected alert open" errors in Selenium.
     * Example: I click "Delete" in the ".actions" element, dismissing the dialog
     *
     * @Given /^I (click|double click) "([^"]*)" in the "([^"]*)" element, dismissing the dialog$/
     * @param string $clickType
     * @param string $text
     * @param string $selector
     */
    public function iClickInTheElementDismissingTheDialog($clickType, $text, $selector)
    {
        $this->iClickInTheElement($clickType, $text, $selector);
        $this->iDismissTheDialog();
    }

    /**
     * @Given /^I see the text "([^"]+)" in the alert$/
     * @param string $expected
     */
    public function iSeeTheDialogText($expected)
    {
        $text = $this->getExpectedAlert()->getText();
        assertContains($expected, $text);
    }

    /**
     * @Given /^I type "([^"]*)" into the dialog$/
     * @param string $data
     */
    public function iTypeIntoTheDialog($data)
    {
        $this->getExpectedAlert()
            ->sendKeys($data)
            ->accept();
    }

    /**
     * Wait for alert to appear, and return handle
     *
     * @return WebDriverAlert
     */
    protected function getExpectedAlert()
    {
        $session = $this->getWebDriverSession();
        $session->wait()->until(
            WebDriverExpectedCondition::alertIsPresent(),
            "Alert is expected"
        );
        return $session->switchTo()->alert();
    }

    /**
     * @Given /^I confirm the dialog$/
     */
    public function iConfirmTheDialog()
    {
        $session = $this->getWebDriverSession();
        $session->wait()->until(
            WebDriverExpectedCondition::alertIsPresent(),
            "Alert is expected"
        );
        $session->switchTo()->alert()->accept();
        $this->handleAjaxTimeout();
    }

    /**
     * @Given /^I dismiss the dialog$/
     */
    public function iDismissTheDialog()
    {
        $this->getExpectedAlert()->dismiss();
        $this->handleAjaxTimeout();
    }

    /**
     * Get Selenium webdriver session.
     * Note: Will fail if current driver isn't FacebookWebDriver
     *
     * @return WebDriver
     */
    protected function getWebDriverSession()
    {
        $driver = $this->getSession()->getDriver();
        if (! $driver instanceof FacebookWebDriver) {
            throw new InvalidArgumentException("Only supported for FacebookWebDriver");
        }
        return $driver->getWebDriver();
    }

    /**
     * @Given /^(?:|I )attach the file "(?P<path>[^"]*)" to "(?P<field>(?:[^"]|\\")*)" with HTML5$/
     * @param string $field
     * @param string $path
     * @return Call\Given
     */
    public function iAttachTheFileTo($field, $path)
    {
        // Remove wrapped button styling to make input field accessible to Selenium
        $js = <<<JS
let input = jQuery('[name="$field"]');
if(input.closest('.ss-uploadfield-item-info').length) {
    while(!input.parent().is('.ss-uploadfield-item-info')) input = input.unwrap();
}
JS;

        $this->getSession()->executeScript($js);
        $this->getSession()->wait(1000);

        return $this->getMainContext()->attachFileToField($field, $path);
    }

    /**
     * Select an individual input from within a group, matched by the top-most label.
     *
     * @Given /^I select "([^"]*)" from "([^"]*)" input group$/
     * @param string $value
     * @param string $labelText
     */
    public function iSelectFromInputGroup($value, $labelText)
    {
        $page = $this->getSession()->getPage();
        $parent = null;

        /** @var NodeElement $label */
        foreach ($page->findAll('css', 'label') as $label) {
            if ($label->getText() == $labelText) {
                $parent = $label->getParent();
            }
        }

        if (!$parent) {
            throw new InvalidArgumentException(sprintf('Input group with label "%s" cannot be found', $labelText));
        }

        /** @var NodeElement $option */
        foreach ($parent->findAll('css', 'label') as $option) {
            if ($option->getText() == $value) {
                $input = null;

                // First, look for inputs referenced by the "for" element on this label
                $for = $option->getAttribute('for');
                if ($for) {
                    $input = $parent->findById($for);
                }

                // Otherwise look for inputs _inside_ the label
                if (!$input) {
                    $input = $option->find('css', 'input');
                }

                if (!$input) {
                    throw new InvalidArgumentException(sprintf('Input "%s" cannot be found', $value));
                }

                $this->getSession()->getDriver()->click($input->getXPath());
            }
        }
    }

    /**
     * Pauses the scenario until the user presses a key. Useful when debugging a scenario.
     *
     * @Then /^(?:|I )put a breakpoint$/
     */
    public function iPutABreakpoint()
    {
        fwrite(STDOUT, "\033[s    \033[93m[Breakpoint] Press \033[1;93m[RETURN]\033[0;93m to continue...\033[0m");
        while (fgets(STDIN, 1024) == '') {
            // noop
        }
        fwrite(STDOUT, "\033[u");

        return;
    }

    /**
     * Transforms relative time statements compatible with strtotime().
     * Example: "time of 1 hour ago" might return "22:00:00" if its currently "23:00:00".
     * Customize through {@link setTimeFormat()}.
     *
     * @Transform /^(?:(the|a)) time of (?<val>.*)$/
     * @param string $prefix
     * @param string $val
     * @return false|string
     */
    public function castRelativeToAbsoluteTime($prefix, $val)
    {
        $timestamp = strtotime($val);
        if (!$timestamp) {
            throw new InvalidArgumentException(sprintf(
                "Can't resolve '%s' into a valid datetime value",
                $val
            ));
        }
        return date($this->timeFormat, $timestamp);
    }

    /**
     * Transforms relative date and time statements compatible with strtotime().
     * Example: "datetime of 2 days ago" might return "2013-10-10 22:00:00" if its currently
     * the 12th of October 2013. Customize through {@link setDatetimeFormat()}.
     *
     * @Transform /^(?:(the|a)) datetime of (?<val>.*)$/
     * @param string $prefix
     * @param string $val
     * @return false|string
     */
    public function castRelativeToAbsoluteDatetime($prefix, $val)
    {
        $timestamp = strtotime($val);
        if (!$timestamp) {
            throw new InvalidArgumentException(sprintf(
                "Can't resolve '%s' into a valid datetime value",
                $val
            ));
        }
        return date($this->datetimeFormat, $timestamp);
    }

    /**
     * Transforms relative date statements compatible with strtotime().
     * Example: "date 2 days ago" might return "2013-10-10" if its currently
     * the 12th of October 2013. Customize through {@link setDateFormat()}.
     *
     * @Transform /^(?:(the|a)) date of (?<val>.*)$/
     * @param string $prefix
     * @param string $val
     * @return false|string
     */
    public function castRelativeToAbsoluteDate($prefix, $val)
    {
        $timestamp = strtotime($val);
        if (!$timestamp) {
            throw new InvalidArgumentException(sprintf(
                "Can't resolve '%s' into a valid datetime value",
                $val
            ));
        }
        return date($this->dateFormat, $timestamp);
    }

    public function getDateFormat()
    {
        return $this->dateFormat;
    }

    public function setDateFormat($format)
    {
        $this->dateFormat = $format;
    }

    public function getTimeFormat()
    {
        return $this->timeFormat;
    }

    public function setTimeFormat($format)
    {
        $this->timeFormat = $format;
    }

    public function getDatetimeFormat()
    {
        return $this->datetimeFormat;
    }

    public function setDatetimeFormat($format)
    {
        $this->datetimeFormat = $format;
    }

    /**
     * Checks that field with specified in|name|label|value is disabled.
     * Example: Then the field "Email" should be disabled
     * Example: Then the "Email" field should be disabled
     *
     * @Then /^the "(?P<name>(?:[^"]|\\")*)" (?P<type>(?:(field|button))) should (?P<negate>(?:(not |)))be disabled/
     * @Then /^the (?P<type>(?:(field|button))) "(?P<name>(?:[^"]|\\")*)" should (?P<negate>(?:(not |)))be disabled/
     * @param string $name
     * @param string $type
     * @param string $negate
     */
    public function stepFieldShouldBeDisabled($name, $type, $negate)
    {
        $page = $this->getSession()->getPage();
        if ($type == 'field') {
            $element = $page->findField($name);
        } else {
            $element = $page->find('named', array(
                'button',
                $this->getMainContext()->getXpathEscaper()->escapeLiteral($name)
            ));
        }

        assertNotNull($element, sprintf("Element '%s' not found", $name));

        $disabledAttribute = $element->getAttribute('disabled');
        if (trim($negate)) {
            assertNull($disabledAttribute, sprintf("Failed asserting element '%s' is not disabled", $name));
        } else {
            assertNotNull($disabledAttribute, sprintf("Failed asserting element '%s' is disabled", $name));
        }
    }

    /**
     * Checks that checkbox with specified in|name|label|value is enabled.
     * Example: Then the field "Email" should be enabled
     * Example: Then the "Email" field should be enabled
     *
     * @Then /^the "(?P<field>(?:[^"]|\\")*)" field should be enabled/
     * @Then /^the field "(?P<field>(?:[^"]|\\")*)" should be enabled/
     * @param string $field
     */
    public function stepFieldShouldBeEnabled($field)
    {
        $page = $this->getSession()->getPage();
        $fieldElement = $page->findField($field);
        assertNotNull($fieldElement, sprintf("Field '%s' not found", $field));

        $disabledAttribute = $fieldElement->getAttribute('disabled');

        assertNull($disabledAttribute, sprintf("Failed asserting field '%s' is enabled", $field));
    }

    /**
     * Clicks a link in a specific region (an element identified by a CSS selector, a "data-title" attribute,
     * or a named region mapped to a CSS selector via Behat configuration).
     *
     * Example: Given I follow "Select" in the "header .login-form" region
     * Example: Given I follow "Select" in the "My Login Form" region
     *
     * @Given /^I (?:follow|click) "(?P<link>[^"]*)" in the "(?P<region>[^"]*)" region$/
     * @param string $link
     * @param string $region
     * @throws \Exception
     */
    public function iFollowInTheRegion($link, $region)
    {
        $context = $this->getMainContext();
        $regionObj = $context->getRegionObj($region);
        assertNotNull($regionObj);

        $linkObj = $regionObj->findLink($link);
        if (empty($linkObj)) {
            throw new \Exception(sprintf('The link "%s" was not found in the region "%s" 
				on the page %s', $link, $region, $this->getSession()->getCurrentUrl()));
        }

        $linkObj->click();
    }

    /**
     * Fills in a field in a specfic region similar to (@see iFollowInTheRegion or @see iSeeTextInRegion)
     *
     * Example: Given I fill in "Hello" with "World"
     *
     * @Given /^I fill in "(?P<field>[^"]*)" with "(?P<value>[^"]*)" in the "(?P<region>[^"]*)" region$/
     * @param string $field
     * @param string $value
     * @param string $region
     * @throws \Exception
     */
    public function iFillinTheRegion($field, $value, $region)
    {
        $context = $this->getMainContext();
        $regionObj = $context->getRegionObj($region);
        assertNotNull($regionObj, "Region Object is null");

        $fieldObj = $regionObj->findField($field);
        if (empty($fieldObj)) {
            throw new \Exception(sprintf('The field "%s" was not found in the region "%s" 
				on the page %s', $field, $region, $this->getSession()->getCurrentUrl()));
        }

        $regionObj->fillField($field, $value);
    }


    /**
     * Asserts text in a specific region (an element identified by a CSS selector, a "data-title" attribute,
     * or a named region mapped to a CSS selector via Behat configuration).
     * Supports regular expressions in text value.
     *
     * Example: Given I should see "My Text" in the "header .login-form" region
     * Example: Given I should not see "My Text" in the "My Login Form" region
     *
     * @Given /^I should (?P<negate>(?:(not |)))see "(?P<text>[^"]*)" in the "(?P<region>[^"]*)" region$/
     * @param string $negate
     * @param string $text
     * @param string $region
     * @throws \Exception
     */
    public function iSeeTextInRegion($negate, $text, $region)
    {
        $context = $this->getMainContext();
        $regionObj = $context->getRegionObj($region);
        assertNotNull($regionObj);

        $actual = $regionObj->getText();
        $actual = preg_replace('/\s+/u', ' ', $actual);
        $regex  = '/'.preg_quote($text, '/').'/ui';

        if (trim($negate)) {
            if (preg_match($regex, $actual)) {
                $message = sprintf(
                    'The text "%s" was found in the text of the "%s" region on the page %s.',
                    $text,
                    $region,
                    $this->getSession()->getCurrentUrl()
                );

                throw new \Exception($message);
            }
        } else {
            if (!preg_match($regex, $actual)) {
                $message = sprintf(
                    'The text "%s" was not found anywhere in the text of the "%s" region on the page %s.',
                    $text,
                    $region,
                    $this->getSession()->getCurrentUrl()
                );

                throw new \Exception($message);
            }
        }
    }

    /**
     * Selects the specified radio button
     *
     * @Given /^I select the "([^"]*)" radio button$/
     * @param string $radioLabel
     */
    public function iSelectTheRadioButton($radioLabel)
    {
        $session = $this->getSession();
        $radioButton = $session->getPage()->find('named', [
            'radio',
            $this->getMainContext()->getXpathEscaper()->escapeLiteral($radioLabel)
        ]);
        assertNotNull($radioButton);
        $session->getDriver()->click($radioButton->getXPath());
    }

    /**
     * @Then /^the "([^"]*)" table should contain "([^"]*)"$/
     * @param string $selector
     * @param string $text
     */
    public function theTableShouldContain($selector, $text)
    {
        $table = $this->getTable($selector);

        $element = $table->find('named', array('content', "'$text'"));
        assertNotNull($element, sprintf('Element containing `%s` not found in `%s` table', $text, $selector));
    }

    /**
     * @Then /^the "([^"]*)" table should not contain "([^"]*)"$/
     * @param string $selector
     * @param string $text
     */
    public function theTableShouldNotContain($selector, $text)
    {
        $table = $this->getTable($selector);

        $element = $table->find('named', array('content', "'$text'"));
        assertNull($element, sprintf('Element containing `%s` not found in `%s` table', $text, $selector));
    }

    /**
     * @Given /^I click on "([^"]*)" in the "([^"]*)" table$/
     * @param string $text
     * @param string $selector
     */
    public function iClickOnInTheTable($text, $selector)
    {
        $table = $this->getTable($selector);

        $element = $table->find('xpath', sprintf('//*[count(*)=0 and contains(.,"%s")]', $text));
        assertNotNull($element, sprintf('Element containing `%s` not found', $text));
        $element->click();
    }

    /**
     * Finds the first visible table by various factors:
     * - table[id]
     * - table[title]
     * - table *[class=title]
     * - fieldset[data-name] table
     * - table caption
     *
     * @param string $selector
     * @return NodeElement
     */
    protected function getTable($selector)
    {
        $selector = $this->getMainContext()->getXpathEscaper()->escapeLiteral($selector);
        $page = $this->getSession()->getPage();
        $candidates = $page->findAll(
            'xpath',
            $this->getSession()->getSelectorsHandler()->selectorToXpath(
                "xpath",
                ".//table[(./@id = $selector or  contains(./@title, $selector))]"
            )
        );

        // Find tables by a <caption> field
        $candidates += $page->findAll('xpath', "//table//caption[contains(normalize-space(string(.)), 
			$selector)]/ancestor-or-self::table[1]");

        // Find tables by a .title node
        $candidates += $page->findAll('xpath', "//table//*[contains(concat(' ',normalize-space(@class),' '), ' title ') and contains(normalize-space(string(.)), 
			$selector)]/ancestor-or-self::table[1]");

        // Some tables don't have a visible title, so look for a fieldset with data-name instead
        $candidates += $page->findAll('xpath', "//fieldset[@data-name=$selector]//table");

        assertTrue((bool)$candidates, 'Could not find any table elements');

        $table = null;
        /** @var NodeElement $candidate */
        foreach ($candidates as $candidate) {
            if (!$table && $candidate->isVisible()) {
                $table = $candidate;
            }
        }

        assertTrue((bool)$table, 'Found table elements, but none are visible');

        return $table;
    }

    /**
     * Checks the order of two texts.
     * Assumptions: the two texts appear in their conjunct parent element once
     * @Then /^I should see the text "(?P<textBefore>(?:[^"]|\\")*)" (before|after) the text "(?P<textAfter>(?:[^"]|\\")*)" in the "(?P<element>[^"]*)" element$/
     * @param string $textBefore
     * @param string $order
     * @param string $textAfter
     * @param string $element
     */
    public function theTextBeforeAfter($textBefore, $order, $textAfter, $element)
    {
        $ele = $this->getSession()->getPage()->find('css', $element);
        assertNotNull($ele, sprintf('%s not found', $element));

        // Check both of the texts exist in the element
        $text = $ele->getText();
        assertTrue(strpos($text, $textBefore) !== 'FALSE', sprintf('%s not found in the element %s', $textBefore, $element));
        assertTrue(strpos($text, $textAfter) !== 'FALSE', sprintf('%s not found in the element %s', $textAfter, $element));

        /// Use strpos to get the position of the first occurrence of the two texts (case-sensitive)
        // and compare them with the given order (before or after)
        if ($order === 'before') {
            assertTrue(strpos($text, $textBefore) < strpos($text, $textAfter));
        } else {
            assertTrue(strpos($text, $textBefore) > strpos($text, $textAfter));
        }
    }

    /**
     * Wait until a certain amount of seconds till I see an element  identified by a CSS selector.
     *
     * Example: Given I wait for 10 seconds until I see the ".css_element" element
     *
     * @Given /^I wait for (\d+) seconds until I see the "([^"]*)" element$/
     * @param int $wait
     * @param string $selector
     */
    public function iWaitXUntilISee($wait, $selector)
    {
        $page = $this->getSession()->getPage();

        $this->spin(function () use ($page, $selector) {
            $element = $page->find('css', $selector);

            if (empty($element)) {
                return false;
            } else {
                return $element->isVisible();
            }
        });
    }

    /**
     * Wait until a particular element is visible, using a CSS selector. Useful for content loaded via AJAX, or only
     * populated after JS execution.
     *
     * Example: Given I wait until I see the "header .login-form" element
     *
     * @Given /^I wait until I see the "([^"]*)" element$/
     * @param string $selector
     */
    public function iWaitUntilISee($selector)
    {
        $page = $this->getSession()->getPage();
        $this->spin(function () use ($page, $selector) {
            $element = $page->find('css', $selector);
            if (empty($element)) {
                return false;
            } else {
                return ($element->isVisible());
            }
        });
    }

    /**
     * Wait until a particular string is found on the page. Useful for content loaded via AJAX, or only populated after
     * JS execution.
     *
     * Example: Given I wait until I see the text "Welcome back, John!"
     *
     * @Given /^I wait until I see the text "([^"]*)"$/
     * @param string $text
     */
    public function iWaitUntilISeeText($text)
    {
        $page = $this->getSession()->getPage();
        $session = $this->getSession();
        $this->spin(function () use ($page, $session, $text) {
            $element = $page->find(
                'xpath',
                $session->getSelectorsHandler()->selectorToXpath("xpath", ".//*[contains(text(), '$text')]")
            );

            if (empty($element)) {
                return false;
            } else {
                return ($element->isVisible());
            }
        });
    }

    /**
     * @Given /^I scroll to the bottom$/
     */
    public function iScrollToBottom()
    {
        $javascript = 'window.scrollTo(0, Math.max(document.documentElement.scrollHeight, document.body.scrollHeight, document.documentElement.clientHeight));';
        $this->getSession()->executeScript($javascript);
    }

    /**
     * @Given /^I scroll to the top$/
     */
    public function iScrollToTop()
    {
        $this->getSession()->executeScript('window.scrollTo(0,0);');
    }

    /**
     * Scroll to a certain element by label.
     * Requires an "id" attribute to uniquely identify the element in the document.
     *
     * Example: Given I scroll to the "Submit" button
     * Example: Given I scroll to the "My Date" field
     *
     * @Given /^I scroll to the "([^"]*)" (field|link|button)$/
     * @param string $locator
     * @param string $type
     */
    public function iScrollToField($locator, $type)
    {
        $page = $this->getSession()->getPage();
        $el = $page->find('named', array($type, "'$locator'"));
        assertNotNull($el, sprintf('%s element not found', $locator));

        $id = $el->getAttribute('id');
        if (empty($id)) {
            throw new InvalidArgumentException('Element requires an "id" attribute');
        }

        $js = sprintf("document.getElementById('%s').scrollIntoView(true);", $id);
        $this->getSession()->executeScript($js);
    }

    /**
     * Scroll to a certain element by CSS selector.
     * Requires an "id" attribute to uniquely identify the element in the document.
     *
     * Example: Given I scroll to the ".css_element" element
     *
     * @Given /^I scroll to the "(?P<locator>(?:[^"]|\\")*)" element$/
     * @param string $locator
     */
    public function iScrollToElement($locator)
    {
        $el = $this->getSession()->getPage()->find('css', $locator);
        assertNotNull($el, sprintf('The element "%s" is not found', $locator));

        $id = $el->getAttribute('id');
        if (empty($id)) {
            throw new InvalidArgumentException('Element requires an "id" attribute');
        }

        $js = sprintf("document.getElementById('%s').scrollIntoView(true);", $id);
        $this->getSession()->executeScript($js);
    }

    /**
     * Continuously poll the dom until callback returns true, code copied from
     * (@link http://docs.behat.org/cookbook/using_spin_functions.html)
     * If not found within a given wait period, timeout and throw error
     *
     * @param callback $lambda The function to run continuously
     * @param integer $wait Timeout in seconds
     * @return bool Returns true if the lambda returns successfully
     * @throws \Exception Thrown if the wait threshold is exceeded without the lambda successfully returning
     */
    public function spin($lambda, $wait = 60)
    {
        for ($i = 0; $i < $wait; $i++) {
            try {
                if ($lambda($this)) {
                    return true;
                }
            } catch (\Exception $e) {
                // do nothing
            }

            sleep(1);
        }

        $backtrace = debug_backtrace();

        throw new \Exception(sprintf(
            "Timeout thrown by %s::%s()\n.",
            $backtrace[1]['class'],
            $backtrace[1]['function']
        ));
    }


    /**
     * We have to catch exceptions and log somehow else otherwise behat falls over
     *
     * @param Exception $exception
     */
    protected function logException(Exception $exception)
    {
        file_put_contents('php://stderr', 'Exception caught: ' . $exception->getMessage());
    }
}
