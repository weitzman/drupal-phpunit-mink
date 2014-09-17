<?php

/**
 * @file
 * Definition of \Drupal\simpletest\BrowserTestBase.
 */

namespace Drupal\simpletest;

use Behat\Mink\Mink;
use Behat\Mink\Session;
use Behat\Mink\Element\Element;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Driver\GoutteDriver;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;

/**
 * Test case for typical Drupal tests.
 *
 * @ingroup testing
 */
abstract class BrowserTestBase extends RunnerTestBase {

  /**
   * @var Mink
   */
  protected $mink;

  /**
   * Indicates that headers should be dumped if verbose output is enabled.
   *
   * @var bool
   */
  protected $dumpHeaders = FALSE;

  /**
   * Constructor for \Drupal\simpletest\BrowserTestBase.
   */
  public function __construct($test_id = NULL) {
    parent::__construct($test_id);
    $this->skipClasses[__CLASS__] = TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $driver = new GoutteDriver();
    $session = new Session($driver);
    $this->mink = new Mink();
    $this->mink->registerSession('goutte', $session);
    $this->mink->setDefaultSessionName('goutte');
  }

  /**
   * {@inheritdoc}
   */
  public function tearDown() {
    parent::tearDown();
    $this->mink->stopSessions();
  }

  /**
   * Returns Mink session.
   *
   * @param string|null $name name of the session OR active session will be used
   *
   * @return Session
   */
  public function getSession($name = null) {
    return $this->mink->getSession($name);
  }

  /**
   * Retrieves a Drupal path or an absolute path.
   *
   * @param string $path
   *   Drupal path or URL to load into internal browser
   * @param array $options
   *   Options to be forwarded to the url generator.
   *
   * @return string
   *   The retrieved HTML string, also available as $this->getRawContent()
   */
  protected function drupalGet($path, array $options = array()) {
    $options['absolute'] = TRUE;

    // The URL generator service is not necessarily available yet; e.g., in
    // interactive installer tests.
    if ($this->container->has('url_generator')) {
      $url = $this->container->get('url_generator')->generateFromPath($path, $options);
    }
    else {
      $url = $this->getAbsoluteUrl($path);
    }
    $session = $this->getSession();

    // Set request headers.
    $session->setRequestHeader('user-agent', drupal_generate_test_ua($this->databasePrefix));
    $session->visit($url);
    $out = $session->getPage()->getContent();

    // Ensure that any changes to variables in the other thread are picked up.
    $this->refreshVariables();

    return $out;
  }

  /**
   * Log in a user with the internal browser.
   *
   * If a user is already logged in, then the current user is logged out before
   * logging in the specified user.
   *
   * Please note that neither the current user nor the passed-in user object is
   * populated with data of the logged in user. If you need full access to the
   * user object after logging in, it must be updated manually. If you also need
   * access to the plain-text password of the user (set by drupalCreateUser()),
   * e.g. to log in the same user again, then it must be re-assigned manually.
   * For example:
   * @code
   *   // Create a user.
   *   $account = $this->drupalCreateUser(array());
   *   $this->drupalLogin($account);
   *   // Load real user object.
   *   $pass_raw = $account->pass_raw;
   *   $account = user_load($account->id());
   *   $account->pass_raw = $pass_raw;
   * @endcode
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   User object representing the user to log in.
   *
   * @see drupalCreateUser()
   */
  protected function drupalLogin(AccountInterface $account) {
    if ($this->loggedInUser) {
      $this->drupalLogout();
    }

    $this->drupalGet('user');
    $this->drupalSubmitForm(array(
      'name' => $account->getUsername(),
      'pass' => $account->pass_raw,
    ), t('Log in'));

    // @see WebTestBase::drupalUserIsLoggedIn()
    $account->session_id = $this->getSession()->getCookie(session_name());
    $this->assertTrue($this->drupalUserIsLoggedIn($account), sprintf('User %s successfully logged in.', $account->getUsername()));

    $this->loggedInUser = $account;
    $this->container->get('current_user')->setAccount($account);
    // @todo Temporary workaround for not being able to use synchronized
    //   services in non dumped container.
    $this->container->get('access_subscriber')->setCurrentUser($account);
  }

  /**
   * Logs a user out of the internal browser and confirms.
   *
   * Confirms logout by checking the login page.
   */
  protected function drupalLogout() {
    // Make a request to the logout page, and redirect to the user page, the
    // idea being if you were properly logged out you should be seeing a login
    // screen.
    $this->drupalGet('user/logout', array('query' => array('destination' => 'user')));
    $this->assertResponseStatus(200, 'User was logged out.');
    $this->assertFieldExists('name', 'Username field found.');
    $this->assertFieldExists('pass', 'Password field found.');

    // @see WebTestBase::drupalUserIsLoggedIn()
    unset($this->loggedInUser->session_id);
    $this->loggedInUser = FALSE;
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
  }

  /**
   * Fill and submit a form.
   *
   * @param  $edit
   *   Field data in an associative array. Changes the current input fields
   *   (where possible) to the values indicated.
   *
   *   A checkbox can be set to TRUE to be checked and should be set to FALSE to
   *   be unchecked.
   * @param $submit
   *   Value of the submit button whose click is to be emulated. For example,
   *   t('Save'). The processing of the request depends on this value. For
   *   example, a form may have one button with the value t('Save') and another
   *   button with the value t('Delete'), and execute different code depending
   *   on which one is clicked.
   * @param $form_html_id
   *   (optional) HTML ID of the form to be submitted. On some pages
   *   there are many identical forms, so just using the value of the submit
   *   button is not enough. For example: 'trigger-node-presave-assign-form'.
   *   Note that this is not the Drupal $form_id, but rather the HTML ID of the
   *   form, which is typically the same thing but with hyphens replacing the
   *   underscores.
   */
  protected function drupalSubmitForm($edit, $submit, $form_html_id = NULL) {
    $session = $this->getSession();
    $page = $session->getPage();

    // Get the form.
    if (isset($form_html_id)) {
      $form = $this->elementExists('xpath', "//form[@id='" . $form_html_id . "']");
      $submit_button = $form->findButton($submit);
    }
    else {
      $submit_button = $page->findButton($submit);
      $form = $this->elementExists('xpath', './ancestor::form', $submit_button);
    }

    // Edit the form values.
    foreach ($edit as $name => $value) {
      $field = $this->fieldExists($name, $form);
      $field->setValue($value);
    }

    // Submit form.
    $submit_button->press();

    // Ensure that any changes to variables in the other thread are picked up.
    $this->refreshVariables();
  }

  /**
   * Helper function to check and retrieve specified element.
   *
   * @param string $selector
   *   Selector type (ie. css or xpath).
   * @param string $locator
   *   Element selector locator.
   * @param Element $container
   *   (optional) Container element to check against. Defaults to current page.
   *
   * @return NodeElement
   *   The NodeElement if found, FALSE otherwise.
   */
  protected function elementExists($selector, $locator, Element $container = NULL) {
    $container = $container ?: $this->getSession()->getPage();
    $node = $container->find($selector, $locator);
    $message = sprintf("Unable to find element with %s selector of %s", $selector, $locator);
    $this->assertNotNull($node, $message);
    return $node;
  }

  /**
   * Helper function to check and retrieve a button.
   *
   * @param string $button
   *   Button locator.
   * @param Element $container
   *   Container to search button for.
   *
   * @return NodeElement|NULL
   *   The button element if found, NULL otherwise.
   */
  protected function buttonExists($button, Element $container = NULL) {
    $container = $container ?: $this->getSession()->getPage();
    $node = $container->findButton($button);
    $message = sprintf("Unable to find button %s", $button);
    $this->assertNotNull($node, $message);
    return $node;
  }

  /**
   * Helper function to check and retrieve specified field.
   *
   * @param string $field
   *   Name, ID, or Label of field to assert.
   * @param Element $container
   *   (optional) Container element to check against. Defaults to current page.
   *
   * @return NodeElement
   *   The NodeElement if found, FALSE otherwise.
   */
  protected function fieldExists($field, Element $container = NULL) {
    $container = $container ?: $this->getSession()->getPage();
    $node = $container->findField($field);
    $message = sprintf("Unable to find field with name|id|label of %s", $field);
    $this->assertNotNull($node, $message);
    return $node;
  }

  /**
   * Helper function to check and retrieve specified select field.
   *
   * @param string $select
   *   Name, ID, or Label of select field to assert.
   * @param Element $container
   *   (optional) Container element to check against. Defaults to current page.
   *
   * @return NodeElement
   *   The NodeElement if found, FALSE otherwise.
   */
  protected function selectExists($select, Element $container = NULL) {
    $container = $container ?: $this->getSession()->getPage();
    $node = $container->find('named', array(
      'select', $this->getSession()->getSelectorsHandler()->xpathLiteral($select)
    ));
    $message = sprintf("Unable to find select with name|id|label of %s", $select);
    $this->assertNotNull($node, $message);
    return $node;
  }

  /**
   * Helper function to get the options of select field.
   *
   * @param NodeElement|string $select
   *   Name, ID, or Label of select field to assert.
   * @param Element $container
   *   (optional) Container element to check against. Defaults to current page.
   *
   * @return array
   *   Associative array of option keys and values.
   */
  protected function getOptions($select, Element $container = NULL) {
    if (is_string($select)) {
      $select = $this->selectExists($select, $container);
    }
    $options = [];
    /** @var NodeElement $option */
    foreach ($select->findAll('xpath', '//option') as $option) {
      $label = $option->getText();
      $value = $option->getAttribute('value') ?: $label;
      $options[$value] = $label;
    }
    return $options;
  }

  /**
   * Asserts the page responds with the specified response code.
   *
   * @param int $code
   *   Response code. For example 200 is a successful page request. For a list
   *   of all codes see http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertResponseStatus($code, $message = '') {
    $status_code = $this->getSession()->getStatusCode();
    $match = is_array($code) ? in_array($status_code, $code) : $status_code == $code;
    if ($message == '') {
      $message = sprintf('Response code %d was expected, but got %d.', $code, $status_code);
    }
    $this->assertTrue($match, $message);
  }

  /**
   * Asserts the page does not responds with the specified response code.
   *
   * @param int $code
   *   Response code. For example 200 is a successful page request. For a list
   *   of all codes see http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html.
   * @param $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertResponseStatusIsNot($code, $message = '') {
    $status_code = $this->getSession()->getStatusCode();
    $match = is_array($code) ? in_array($status_code, $code) : $status_code == $code;
    if ($message == '') {
      $message = sprintf('Response code %d was not expected.', $status_code);
    }
    $this->assertFalse($match, $message);
  }

  /**
   * Asserts that an elements exists on the current page.
   *
   * @param string $xpath
   *   xpath selector used to find the element.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertElementExists($xpath, $message = '') {
    if ($message == '') {
      $message = sprintf('Element "%s" was expected.', $xpath);
    }
    $this->assertNotNull($this->getSession()->getPage()->find('xpath', $xpath), $message);
  }

  /**
   * Asserts that an elements does not exist on the current page.
   *
   * @param string $xpath
   *   xpath selector used to find the element.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertElementNotExists($xpath, $message = '') {
    if ($message == '') {
      $message = sprintf('Element "%s" was not expected.', $xpath);
    }
    $this->assertNull($this->getSession()->getPage()->find('xpath', $xpath), $message);
  }

  /**
   * Asserts that a field exists with the given name or ID.
   *
   * @param string $field
   *   Name, ID, or Label of field to assert.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertFieldExists($field, $message = '') {
    if ($message == '') {
      $message = sprintf('Field "%s" was expected.', $field);
    }
    $this->assertTrue($this->getSession()->getPage()->hasField($field), $message);
  }

  /**
   * Asserts that a field does not exist with the given name or ID.
   *
   * @param string $field
   *   Name, ID, or Label of field to assert.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertFieldNotExists($field, $message = '') {
    if ($message == '') {
      $message = sprintf('Field "%s" was not expected.', $field);
    }
    $this->assertFalse($this->getSession()->getPage()->hasField($field), $message);
  }

  /**
   * Assert that the element contains text.
   *
   * @param NodeElement|string $element
   *   The element to check.
   * @param string $text
   *   Text to be contained in the element.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertElementTextContains($element, $text, $message = '') {
    if (is_string($element)) {
      $element = $this->elementExists('xpath', $element);
    }
    $actual = $element->getText();
    $regex = '/' . preg_quote($text, '/') . '/ui';
    if ($message == '') {
      $message = sprintf('Element "%s" was expected to contain text "%s".', $element->getXpath(), $text);
    }
    $this->assertRegExp($regex, $actual, $message);
  }

  /**
   * Assert that the element does not contain text.
   *
   * @param NodeElement|string $element
   *   The element to check.
   * @param string $text
   *   Text to not be contained in element.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertElementTextNotContains($element, $text, $message = '') {
    if (is_string($element)) {
      $element = $this->elementExists('xpath', $element);
    }
    $actual = $element->getText();
    $regex = '/' . preg_quote($text, '/') . '/ui';
    if ($message == '') {
      $message = sprintf('Element "%s" was not expected to contain text "%s".', $element->getXpath(), $text);
    }
    $this->assertNotRegExp($regex, $actual, $message);
  }

  /**
   * Assert that the element contains html.
   *
   * @param NodeElement|string $element
   *   The element to check.
   * @param string $html
   *   HTML to be contained in the element.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertElementContains($element, $html, $message = '') {
    if (is_string($element)) {
      $element = $this->elementExists('xpath', $element);
    }
    $actual = $element->getHtml();
    $regex = '/' . preg_quote($html, '/') . '/ui';
    if ($message == '') {
      $message = sprintf('Element "%s" was expected to contain html "%s".', $element->getXpath(), $html);
    }
    $this->assertRegExp($regex, $actual, $message);
  }

  /**
   * Assert that the element does not contain html.
   *
   * @param NodeElement|string $element
   *   The element to check.
   * @param string $html
   *   HTML to not be contained in element.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertElementNotContains($element, $html, $message = '') {
    if (is_string($element)) {
      $element = $this->elementExists('xpath', $element);
    }
    $actual = $element->getHtml();
    $regex = '/' . preg_quote($html, '/') . '/ui';
    if ($message == '') {
      $message = sprintf('Element "%s" was not expected to contain html "%s".', $element->getXpath(), $html);
    }
    $this->assertNotRegExp($regex, $actual, $message);
  }

  /**
   * Assert that specific field has provided value.
   *
   * @param NodeElement|string $field
   *   Field element to check.
   * @param string $value
   *   Value of field to equal.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertFieldValueEquals($field, $value, $message = '') {
    if (is_string($field)) {
      $field = $this->fieldExists($field);
    }
    $actual = $field->getValue();
    $regex = '/^' . preg_quote($value, '/') . '/ui';
    if ($message == '') {
      $message = sprintf('Field "%s" was expected to have value "%s" but has "%s".', $field->getXpath(), $value, $actual);
    }
    $this->assertRegExp($regex, $actual, $message);
  }

  /**
   * Assert that specific field does not have the provided value.
   *
   * @param NodeElement|string $field
   *   Field element to check.
   * @param string $value
   *   Value the field should not equal.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertFieldValueNotEquals($field, $value, $message = '') {
    if (is_string($field)) {
      $field = $this->fieldExists($field);
    }
    $actual = $field->getValue();
    $regex = '/^' . preg_quote($value, '/') . '/ui';
    if ($message == '') {
      $message = sprintf('Field "%s" was not expected to have value "%s" but has "%s".', $field->getXpath(), $value, $actual);
    }
    $this->assertNotRegExp($regex, $actual, $message);
  }

  /**
   * Assert that specific checkbox is checked.
   *
   * @param NodeElement|string $field
   *   Checkbox element.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertCheckboxChecked($field, $message = '') {
    if (is_string($field)) {
      $field = $this->fieldExists($field);
    }
    if ($message == '') {
      $message = sprintf('Checkbox "%s" was expected to be checked.', $field->getXpath());
    }
    $this->assertTrue($field->isChecked(), $message);
  }

  /**
   * Assert that specific checkbox is not checked.
   *
   * @param NodeElement|string $field
   *   Checkbox element.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertCheckboxNotChecked($field, $message = '') {
    if (is_string($field)) {
      $field = $this->fieldExists($field);
    }
    if ($message == '') {
      $message = sprintf('Checkbox "%s" was not expected to be checked.', $field->getXpath());
    }
    $this->assertFalse($field->isChecked(), $message);
  }

  /**
   * Assert that current page contains text.
   *
   * @param string $text
   *   Text to be contained in the page.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertPageTextContains($text, $message = '') {
    $actual = $this->getSession()->getPage()->getText();
    $actual = preg_replace('/\s+/u', ' ', $actual);
    $regex = '/' . preg_quote($text, '/') . '/ui';
    if ($message == '') {
      $message = sprintf('Page text was expected to contain "%s".', $text);
    }
    $this->assertRegExp($regex, $actual, $message);
  }

  /**
   * Assert that current page does not contain text.
   *
   * @param string $text
   *   Text to not be contained in the page.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertPageTextNotContains($text, $message = '') {
    $actual = $this->getSession()->getPage()->getText();
    $actual = preg_replace('/\s+/u', ' ', $actual);
    $regex = '/' . preg_quote($text, '/') . '/ui';
    if ($message == '') {
      $message = sprintf('Page text was not expected to contain "%s".', $text);
    }
    $this->assertNotRegExp($regex, $actual, $message);
  }

  /**
   * Assert that current page text matches regex.
   *
   * @param string $regex
   *   Perl regular expression to match.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertPageTextMatches($regex, $message = '') {
    $actual = $this->getSession()->getPage()->getText();
    if ($message == '') {
      $message = sprintf('Page text was expected to match "%s".', $regex);
    }
    $this->assertRegExp($regex, $actual, $message);
  }

  /**
   * Assert that current page text does not match regex.
   *
   * @param string $regex
   *   Perl regular expression to not match.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertPageTextNotMatches($regex, $message = '') {
    $actual = $this->getSession()->getPage()->getText();
    if ($message == '') {
      $message = sprintf('Page text was expected to not match "%s".', $regex);
    }
    $this->assertNotRegExp($regex, $actual, $message);
  }

  /**
   * Assert that response content contains text.
   *
   * @param string $text
   *   Text to be contained in the response.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertResponseContains($text, $message = '') {
    $actual = $this->getSession()->getPage()->getContent();
    $regex = '/' . preg_quote($text, '/') . '/ui';
    if ($message == '') {
      $message = sprintf('Response was expected to contain "%s".', $text);
    }
    $this->assertRegExp($regex, $actual, $message);
  }

  /**
   * Assert that response content does not contain text.
   *
   * @param string $text
   *   Text to not be contained in response.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertResponseNotContains($text, $message = '') {
    $actual = $this->getSession()->getPage()->getContent();
    $regex = '/' . preg_quote($text, '/') . '/ui';
    if ($message == '') {
      $message = sprintf('Response was not expected to contain "%s".', $text);
    }
    $this->assertNotRegExp($regex, $actual, $message);
  }

  /**
   * Assert that response content matches regex.
   *
   * @param string $regex
   *   Perl regular expression to match.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertResponseMatches($regex, $message = '') {
    $actual = $this->getSession()->getPage()->getContent();
    if ($message == '') {
      $message = sprintf('Response was expected to match "%s".', $regex);
    }
    $this->assertRegExp($regex, $actual, $message);
  }

  /**
   * Assert that response content does not match regex.
   *
   * @param string $regex
   *   Perl regular expression to not match.
   * @param string $message
   *   (optional) A message to display with the assertion.
   */
  protected function assertResponseNotMatches($regex, $message = '') {
    $actual = $this->getSession()->getPage()->getContent();
    if ($message == '') {
      $message = sprintf('Response was not expected to match "%s".', $regex);
    }
    $this->assertNotRegExp($regex, $actual, $message);
  }
}
