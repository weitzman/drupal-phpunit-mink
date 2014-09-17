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
use Drupal\Component\Utility\String;
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
    if (!isset($this->mink)) {
      $driver = new GoutteDriver();
      $session = new Session($driver);
      $this->mink = new Mink();
      $this->mink->registerSession('goutte', $session);
      $this->mink->setDefaultSessionName('goutte');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function tearDown() {
    parent::tearDown();
    if (isset($this->mink)) {
      $this->mink->resetSessions();
    }
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
    if ($node === null) {
      $this->fail(String::format('Unable to find element with @selector selector of @locator', array('@selector' => $selector, '@locator' => $locator)));
    }
    return $node;
  }

  /**
   * Helper function to check and retreive a button.
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
    if ($node === null) {
      $this->fail(String::format('Unable to find button @button', array('@button' => $button)));
    }
    return $node;
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
    $pass = $this->assertTrue($this->drupalUserIsLoggedIn($account), format_string('User %name successfully logged in.', array('%name' => $account->getUsername())), 'User login');
    if ($pass) {
      $this->loggedInUser = $account;
      $this->container->get('current_user')->setAccount($account);
      // @todo Temporary workaround for not being able to use synchronized
      //   services in non dumped container.
      $this->container->get('access_subscriber')->setCurrentUser($account);
    }
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
    $pass = $this->assertFieldExists('name', 'Username field found.', 'Logout');
    $pass = $pass && $this->assertFieldExists('pass', 'Password field found.', 'Logout');

    if ($pass) {
      // @see WebTestBase::drupalUserIsLoggedIn()
      unset($this->loggedInUser->session_id);
      $this->loggedInUser = FALSE;
      $this->container->get('current_user')->setAccount(new AnonymousUserSession());
    }
  }

  /**
   * Asserts the page responds with the specified response code.
   *
   * @param $code
   *   Response code. For example 200 is a successful page request. For a list
   *   of all codes see http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Browser'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertResponseStatus($code, $message = '', $group = 'Browser') {
    $status_code = $this->getSession()->getStatusCode();
    $match = is_array($code) ? in_array($status_code, $code) : $status_code == $code;
    return $this->assertTrue($match, $message ? $message : String::format('HTTP response expected !code, actual !status_code', array('!code' => $code, '!status_code' => $status_code)), $group);
  }

  /**
   * Asserts the page does not responds with the specified response code.
   *
   * @param $code
   *   Response code. For example 200 is a successful page request. For a list
   *   of all codes see http://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html.
   * @param $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Browser'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertResponseStatusIsNot($code, $message = '', $group = 'Browser') {
    $status_code = $this->getSession()->getStatusCode();
    $match = is_array($code) ? in_array($status_code, $code) : $status_code == $code;
    return $this->assertTrue($match, $message ? $message : String::format('HTTP response expected !code, actual !status_code', array('!code' => $code, '!status_code' => $status_code)), $group);
  }

  /**
   * Asserts that an elements exists on the current page.
   *
   * @param string $xpath
   *   xpath selector used to find the element.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertElementExists($xpath, $message = '', $group = 'Other') {
    return $this->assertNotNull($this->getSession()->getPage()->find('xpath', $xpath), $message, $group);
  }

  /**
   * Asserts that an elements does not exist on the current page.
   *
   * @param string $xpath
   *   xpath selector used to find the element.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertElementNotExists($xpath, $message = '', $group = 'Other') {
    return $this->assertNull($this->getSession()->getPage()->find('xpath', $xpath), $message, $group);
  }

  /**
   * Asserts that a field exists with the given name or ID.
   *
   * @param string $field
   *   Name, ID, or Label of field to assert.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertFieldExists($field, $message = '', $group = 'Other') {
    return $this->assertTrue($this->getSession()->getPage()->hasField($field), $message, $group);
  }

  /**
   * Asserts that a field does not exist with the given name or ID.
   *
   * @param string $field
   *   Name, ID, or Label of field to assert.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertFieldNotExists($field, $message = '', $group = 'Other') {
    return $this->assertFalse($this->getSession()->getPage()->hasField($field), $message, $group);
  }

  /**
   * Assert that the element contains text.
   *
   * @param NodeElement|string $element
   *   The element to check.
   * @param string $text
   *   Text to be contained in the element.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertElementTextContains($element, $text, $message = '', $group = 'Other') {
    if (is_string($element)) {
      $element = $this->elementExists('xpath', $element);
    }
    $actual = $element->getText();
    $regex = '/' . preg_quote($text, '/') . '/ui';
    return $this->assertTrue(preg_match($regex, $actual), $message, $group);
  }

  /**
   * Assert that the element does not contain text.
   *
   * @param NodeElement|string $element
   *   The element to check.
   * @param string $text
   *   Text to not be contained in element.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertElementTextNotContains($element, $text, $message = '', $group = 'Other') {
    if (is_string($element)) {
      $element = $this->elementExists('xpath', $element);
    }
    $actual = $element->getText();
    $regex = '/' . preg_quote($text, '/') . '/ui';
    return $this->assertFalse(preg_match($regex, $actual), $message, $group);
  }

  /**
   * Assert that the element contains html.
   *
   * @param NodeElement|string $element
   *   The element to check.
   * @param string $html
   *   HTML to be contained in the element.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertElementContains($element, $html, $message = '', $group = 'Other') {
    if (is_string($element)) {
      $element = $this->elementExists('xpath', $element);
    }
    $actual = $element->getHtml();
    $regex = '/' . preg_quote($html, '/') . '/ui';
    return $this->assertTrue(preg_match($regex, $actual), $message, $group);
  }

  /**
   * Assert that the element does not contain html.
   *
   * @param NodeElement|string $element
   *   The element to check.
   * @param string $html
   *   HTML to not be contained in element.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertElementNotContains($element, $html, $message = '', $group = 'Other') {
    if (is_string($element)) {
      $element = $this->elementExists('xpath', $element);
    }
    $actual = $element->getHtml();
    $regex = '/' . preg_quote($html, '/') . '/ui';
    return $this->assertTrue(!preg_match($regex, $actual), $message, $group);
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
    if ($node === null) {
      $this->fail(String::format('Unable to find field with name|id|label of @field', array('@field' => $field)));
    }
    return $node;
  }

  /**
   * Assert that specific field has provided value.
   *
   * @param NodeElement|string $field
   *   Field element to check.
   * @param string $value
   *   Value of field to equal.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertFieldValueEquals($field, $value, $message = '', $group = 'Other') {
    if (is_string($field)) {
      $field = $this->fieldExists($field);
    }
    $actual = $field->getValue();
    $regex = '/^' . preg_quote($value, '/') . '/ui';
    return $this->assertTrue(preg_match($regex, $actual), $message, $group);
  }

  /**
   * Assert that specific field does not have the provided value.
   *
   * @param NodeElement|string $field
   *   Field element to check.
   * @param string $value
   *   Value the field should not equal.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertFieldValueNotEquals($field, $value, $message = '', $group = 'Other') {
    if (is_string($field)) {
      $field = $this->fieldExists($field);
    }
    $actual = $field->getValue();
    $regex = '/^' . preg_quote($value, '/') . '/ui';
    return $this->assertFalse(preg_match($regex, $actual), $message, $group);
  }

  /**
   * Assert that specific checkbox is checked.
   *
   * @param NodeElement|string $field
   *   Checkbox element.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertCheckboxChecked($field, $message = '', $group = 'Other') {
    if (is_string($field)) {
      $field = $this->fieldExists($field);
    }
    return $this->assertTrue($field->isChecked(), $message, $group);
  }

  /**
   * Assert that specific checkbox is not checked.
   *
   * @param NodeElement|string $field
   *   Checkbox element.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertCheckboxNotChecked($field, $message = '', $group = 'Other') {
    if (is_string($field)) {
      $field = $this->fieldExists($field);
    }
    return $this->assertFalse($field->isChecked(), $message, $group);
  }

  /**
   * Assert that current page contains text.
   *
   * @param string $text
   *   Text to be contained in the page.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertPageTextContains($text, $message = '', $group = 'Other') {
    $actual = $this->getSession()->getPage()->getText();
    $actual = preg_replace('/\s+/u', ' ', $actual);
    $regex = '/' . preg_quote($text, '/') . '/ui';
    return $this->assertTrue(preg_match($regex, $actual), $message, $group);
  }

  /**
   * Assert that current page does not contain text.
   *
   * @param string $text
   *   Text to not be contained in the page.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertPageTextNotContains($text, $message = '', $group = 'Other') {
    $actual = $this->getSession()->getPage()->getText();
    $actual = preg_replace('/\s+/u', ' ', $actual);
    $regex = '/' . preg_quote($text, '/') . '/ui';
    return $this->assertFalse(preg_match($regex, $actual), $message, $group);
  }

  /**
   * Assert that current page text matches regex.
   *
   * @param string $regex
   *   Perl regular expression to match.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertPageTextMatches($regex, $message = '', $group = 'Other') {
    $actual = $this->getSession()->getPage()->getText();
    return $this->assertTrue(preg_match($regex, $actual), $message, $group);
  }

  /**
   * Assert that current page text does not match regex.
   *
   * @param string $regex
   *   Perl regular expression to not match.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertPageTextNotMatches($regex, $message = '', $group = 'Other') {
    $actual = $this->getSession()->getPage()->getText();
    return $this->assertFalse(preg_match($regex, $actual), $message, $group);
  }

  /**
   * Assert that response content contains text.
   *
   * @param string $text
   *   Text to be contained in the response.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertResponseContains($text, $message = '', $group = 'Other') {
    $actual = $this->getSession()->getPage()->getContent();
    $regex = '/' . preg_quote($text, '/') . '/ui';
    return $this->assertTrue(preg_match($regex, $actual), $message, $group);
  }

  /**
   * Assert that response content does not contain text.
   *
   * @param string $text
   *   Text to not be contained in response.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertResponseNotContains($text, $message = '', $group = 'Other') {
    $actual = $this->getSession()->getPage()->getContent();
    $regex = '/' . preg_quote($text, '/') . '/ui';
    return $this->assertFalse(preg_match($regex, $actual), $message, $group);
  }

  /**
   * Assert that response content matches regex.
   *
   * @param string $regex
   *   Perl regular expression to match.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertResponseMatches($regex, $message = '', $group = 'Other') {
    $actual = $this->getSession()->getPage()->getContent();
    return $this->assertTrue(preg_match($regex, $actual), $message, $group);
  }

  /**
   * Assert that response content does not match regex.
   *
   * @param string $regex
   *   Perl regular expression to not match.
   * @param string $message
   *   (optional) A message to display with the assertion. Do not translate
   *   messages: use format_string() to embed variables in the message text, not
   *   t(). If left blank, a default message will be displayed.
   * @param string $group
   *   (optional) The group this message is in, which is displayed in a column
   *   in test output. Use 'Debug' to indicate this is debugging output. Do not
   *   translate this string. Defaults to 'Other'; most tests do not override
   *   this default.
   *
   * @return bool
   *   TRUE on pass, FALSE on fail.
   */
  protected function assertResponseNotMatches($regex, $message = '', $group = 'Other') {
    $actual = $this->getSession()->getPage()->getContent();
    return $this->assertFalse(preg_match($regex, $actual), $message, $group);
  }
}
