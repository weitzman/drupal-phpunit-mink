<?php

namespace Drupal\simpletest;

use Behat\Mink\Mink;
use Drupal\Component\Utility\Random;
use Drupal\Component\Utility\String;
use Drupal\Core\Database\Database;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Session\AnonymousUserSession;
use Drupal\Core\Session\UserSession;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base trait for WebTestBase and BrowserTestBase.
 *
 * @deprecated
 * Should be removed once all WebTestBase tests have been ported to
 * BrowserTestBase.
 */
trait WebTestTrait {

  /**
   * The database prefix of this test run.
   *
   * @var string
   */
  protected $databasePrefix = NULL;

  /**
   * The site directory of this test run.
   *
   * @var string
   */
  protected $siteDirectory = NULL;

  /**
   * The random generator.
   *
   * @var \Drupal\Component\Utility\Random
   */
  protected $randomGenerator;

  /**
   * The DrupalKernel instance used in the test.
   *
   * @var \Drupal\Core\DrupalKernel
   */
  protected $kernel;

  /**
   * The dependency injection container used in the test.
   *
   * @var \Symfony\Component\DependencyInjection\ContainerInterface
   */
  protected $container;

  /**
   * @var Mink
   */
  protected $mink;

  /**
   * The current user logged in using the internal browser.
   *
   * @var bool
   */
  protected $loggedInUser = FALSE;

  /**
   * The root user.
   *
   * @var UserSession
   */
  protected $root_user;

  /**
   * Takes a path and returns an absolute path.
   *
   * @param string $path
   *   A path from the internal browser content.
   *
   * @return string
   *   The $path with $base_url prepended, if necessary.
   */
  protected function getAbsoluteUrl($path) {
    global $base_url, $base_path;

    $parts = parse_url($path);
    if (empty($parts['host'])) {
      // Ensure that we have a string (and no xpath object).
      $path = (string) $path;
      // Strip $base_path, if existent.
      $length = strlen($base_path);
      if (substr($path, 0, $length) === $base_path) {
        $path = substr($path, $length);
      }
      // Ensure that we have an absolute path.
      if ($path[0] !== '/') {
        $path = '/' . $path;
      }
      // Finally, prepend the $base_url.
      $path = $base_url . $path;
    }
    return $path;
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

    $this->prepareRequest();
    $session->visit($url);
    $out = $session->getPage()->getContent();

    // Ensure that any changes to variables in the other thread are picked up.
    $this->refreshVariables();

    return $out;
  }

  /**
   * Returns Mink session.
   *
   * @param string|null $name
   *   Name of the session OR active session will be used.
   *
   * @return \Behat\Mink\Session
   *   The active mink session object.
   */
  public function getSession($name = NULL) {
    return $this->mink->getSession($name);
  }

  /**
   * Prepare for a request to testing site.
   *
   * The testing site is protected via a SIMPLETEST_USER_AGENT cookie that
   * is checked by drupal_valid_test_ua().
   *
   * @see drupal_valid_test_ua()
   */
  protected function prepareRequest() {
    $session = $this->getSession();
    $session->setCookie('SIMPLETEST_USER_AGENT', drupal_generate_test_ua($this->databasePrefix));
  }

  /**
   * Refreshes in-memory configuration and state information.
   *
   * Useful after a page request is made that changes configuration or state in
   * a different thread.
   *
   * In other words calling a settings page with $this->drupalPostForm() with a
   * changed value would update configuration to reflect that change, but in the
   * thread that made the call (thread running the test) the changed values
   * would not be picked up.
   *
   * This method clears the cache and loads a fresh copy.
   */
  protected function refreshVariables() {
    // Clear the tag cache.
    drupal_static_reset('Drupal\Core\Cache\CacheBackendInterface::tagCache');
    drupal_static_reset('Drupal\Core\Cache\DatabaseBackend::deletedTags');
    drupal_static_reset('Drupal\Core\Cache\DatabaseBackend::invalidatedTags');

    $this->container->get('config.factory')->reset();
    $this->container->get('state')->resetCache();
  }

  /**
   * Rebuilds \Drupal::getContainer().
   *
   * Use this to build a new kernel and service container. For example, when the
   * list of enabled modules is changed via the internal browser, in which case
   * the test process still contains an old kernel and service container with an
   * old module list.
   *
   * @see TestBase::prepareEnvironment()
   * @see TestBase::restoreEnvironment()
   *
   * @todo Fix https://www.drupal.org/node/2021959 so that module enable/disable
   *   changes are immediately reflected in \Drupal::getContainer(). Until then,
   *   tests can invoke this workaround when requiring services from newly
   *   enabled modules to be immediately available in the same request.
   */
  protected function rebuildContainer() {
    // Maintain the current global request object.
    $request = \Drupal::request();
    // Rebuild the kernel and bring it back to a fully bootstrapped state.
    $this->container = $this->kernel->rebuildContainer();

    // The request context is normally set by the router_listener from within
    // its KernelEvents::REQUEST listener. In the simpletest parent site this
    // event is not fired, therefore it is necessary to updated the request
    // context manually here.
    $this->container->get('router.request_context')->fromRequest($request);

    // Make sure the url generator has a request object, otherwise calls to
    // $this->drupalGet() will fail.
    $this->prepareRequestForGenerator();
  }

  /**
   * Resets all data structures after having enabled new modules.
   *
   * This method is called by \Drupal\simpletest\WebTestBase::setUp() after
   * enabling the requested modules. It must be called again when additional
   * modules are enabled later.
   */
  protected function resetAll() {
    // Clear all database and static caches and rebuild data structures.
    drupal_flush_all_caches();
    $this->container = \Drupal::getContainer();

    // Reset static variables and reload permissions.
    $this->refreshVariables();
  }

  /**
   * Creates a mock request and sets it on the generator.
   *
   * This is used to manipulate how the generator generates paths during tests.
   * It also ensures that calls to $this->drupalGet() will work when running
   * from run-tests.sh because the url generator no longer looks at the global
   * variables that are set there but relies on getting this information from a
   * request object.
   *
   * @param bool $clean_urls
   *   Whether to mock the request using clean urls.
   * @param array $override_server_vars
   *   An array of server variables to override.
   *
   * @return Request
   *   The mocked request object.
   */
  protected function prepareRequestForGenerator($clean_urls = TRUE, $override_server_vars = array()) {
    $request = Request::createFromGlobals();
    $server = $request->server->all();
    if (basename($server['SCRIPT_FILENAME']) != basename($server['SCRIPT_NAME'])) {
      // We need this fsor when the test is executed by run-tests.sh.
      // @todo Remove this once run-tests.sh has been converted to use a Request
      //   object.
      $cwd = getcwd();
      $server['SCRIPT_FILENAME'] = $cwd . '/' . basename($server['SCRIPT_NAME']);
      $base_path = rtrim($server['REQUEST_URI'], '/');
    }
    else {
      $base_path = $request->getBasePath();
    }
    if ($clean_urls) {
      $request_path = $base_path ? $base_path . '/user' : 'user';
    }
    else {
      $request_path = $base_path ? $base_path . '/index.php/user' : '/index.php/user';
    }
    $server = array_merge($server, $override_server_vars);

    $request = Request::create($request_path, 'GET', array(), array(), array(), $server);
    $this->container->get('request_stack')->push($request);

    // The request context is normally set by the router_listener from within
    // its KernelEvents::REQUEST listener. In the simpletest parent site this
    // event is not fired, therefore it is necessary to updated the request
    // context manually here.
    $this->container->get('router.request_context')->fromRequest($request);

    return $request;
  }

  /**
   * Returns whether a given user account is logged in.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account object to check.
   *
   * @return bool
   *   Return TRUE if the user is logged in, FALSE otherwise.
   */
  protected function drupalUserIsLoggedIn($account) {
    if (!isset($account->session_id)) {
      return FALSE;
    }
    // The session ID is hashed before being stored in the database.
    // @see \Drupal\Core\Session\SessionHandler::read()
    return (bool) db_query("SELECT sid FROM {users} u INNER JOIN {sessions} s ON u.uid = s.uid WHERE s.sid = :sid", array(':sid' => Crypt::hashBase64($account->session_id)))->fetchField();
  }

  /**
   * Rewrites the settings.php file of the test site.
   *
   * @param array $settings
   *   An array of settings to write out, in the format expected by
   *   drupal_rewrite_settings().
   *
   * @see drupal_rewrite_settings()
   */
  protected function writeSettings(array $settings) {
    include_once DRUPAL_ROOT . '/core/includes/install.inc';
    $filename = $this->siteDirectory . '/settings.php';

    error_log($filename);

    // system_requirements() removes write permissions from settings.php
    // whenever it is invoked.
    // Not using File API; a potential error must trigger a PHP warning.
    chmod($filename, 0666);
    drupal_rewrite_settings($settings, $filename);
  }

  /**
   * Checks whether a given list of permission names is valid.
   *
   * @param array $permissions
   *   The permission names to check.
   *
   * @return bool
   *   TRUE if the permissions are valid, FALSE otherwise.
   */
  protected function checkPermissions(array $permissions) {
    $available = array_keys(\Drupal::moduleHandler()->invokeAll('permission'));
    $valid = TRUE;
    foreach ($permissions as $permission) {
      if (!in_array($permission, $available)) {
        $this->fail(String::format('Invalid permission %permission.', array('%permission' => $permission)), 'Role');
        $valid = FALSE;
      }
    }
    return $valid;
  }

  /**
   * Creates a role with specified permissions.
   *
   * @param array $permissions
   *   Array of permission names to assign to role.
   * @param string $rid
   *   (optional) The role ID (machine name). Defaults to a random name.
   * @param string $name
   *   (optional) The label for the role. Defaults to a random string.
   * @param int $weight
   *   (optional) The weight for the role. Defaults NULL so that entity_create()
   *   sets the weight to maximum + 1.
   *
   * @return string
   *   Role ID of newly created role, or FALSE if role creation failed.
   */
  protected function drupalCreateRole(array $permissions, $rid = NULL, $name = NULL, $weight = NULL) {
    // Generate a random, lowercase machine name if none was passed.
    if (!isset($rid)) {
      $rid = strtolower($this->randomMachineName(8));
    }
    // Generate a random label.
    if (!isset($name)) {
      // In the role UI role names are trimmed and random string can start or
      // end with a space.
      $name = trim($this->randomString(8));
    }

    // Check the all the permissions strings are valid.
    if (!$this->checkPermissions($permissions)) {
      return FALSE;
    }

    // Create new role.
    /* @var \Drupal\user\RoleInterface $role */
    $role = entity_create('user_role', array(
      'id' => $rid,
      'label' => $name,
    ));
    if (!is_null($weight)) {
      $role->set('weight', $weight);
    }
    $result = $role->save();

    $this->assertSame($result, SAVED_NEW, String::format('Created role ID @rid with name @name.', array(
      '@name' => var_export($role->label(), TRUE),
      '@rid' => var_export($role->id(), TRUE),
    )), 'Role');

    if ($result === SAVED_NEW) {
      // Grant the specified permissions to the role, if any.
      if (!empty($permissions)) {
        user_role_grant_permissions($role->id(), $permissions);
        $assigned_permissions = entity_load('user_role', $role->id())->getPermissions();
        $missing_permissions = array_diff($permissions, $assigned_permissions);
        if ($missing_permissions) {
          $this->fail(String::format('Failed to create permissions: @perms', array('@perms' => implode(', ', $missing_permissions))), 'Role');
        }
      }
      return $role->id();
    }
    else {
      return FALSE;
    }
  }

  /**
   * Create a user with a given set of permissions.
   *
   * @param array $permissions
   *   Array of permission names to assign to user. Note that the user always
   *   has the default permissions derived from the "authenticated users" role.
   * @param string $name
   *   The user name.
   *
   * @return \Drupal\user\Entity\User|false
   *   A fully loaded user object with pass_raw property, or FALSE if account
   *   creation fails.
   */
  protected function drupalCreateUser(array $permissions = array(), $name = NULL) {
    // Create a role with the given permission set, if any.
    $rid = FALSE;
    if ($permissions) {
      $rid = $this->drupalCreateRole($permissions);
      if (!$rid) {
        return FALSE;
      }
    }

    // Create a user assigned to that role.
    $edit = array();
    $edit['name'] = !empty($name) ? $name : $this->randomName();
    $edit['mail'] = $edit['name'] . '@example.com';
    $edit['pass'] = user_password();
    $edit['status'] = 1;
    if ($rid) {
      $edit['roles'] = array($rid);
    }

    $account = entity_create('user', $edit);
    $account->save();

    if (!$account->id()) {
      return FALSE;
    }

    // Add the raw password so that we can log in as this user.
    $account->pass_raw = $edit['pass'];
    return $account;
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
    $this->submitForm(array(
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
    $assert_session = $this->assertSession();
    $this->drupalGet('user/logout', array('query' => array('destination' => 'user')));
    $assert_session->statusCodeEquals(200);
    $assert_session->fieldExists('name');
    $assert_session->fieldExists('pass');

    // @see WebTestBase::drupalUserIsLoggedIn()
    unset($this->loggedInUser->session_id);
    $this->loggedInUser = FALSE;
    $this->container->get('current_user')->setAccount(new AnonymousUserSession());
  }

  /**
   * Returns the parameters that will be used when Simpletest installs Drupal.
   *
   * @see install_drupal()
   * @see install_state_defaults()
   */
  protected function installParameters() {
    $connection_info = Database::getConnectionInfo();
    $driver = $connection_info['default']['driver'];
    $connection_info['default']['prefix'] = $connection_info['default']['prefix']['default'];
    unset($connection_info['default']['driver']);
    unset($connection_info['default']['namespace']);
    unset($connection_info['default']['pdo']);
    unset($connection_info['default']['init_commands']);
    $parameters = array(
      'interactive' => FALSE,
      'parameters' => array(
        'profile' => $this->profile,
        'langcode' => 'en',
      ),
      'forms' => array(
        'install_settings_form' => array(
          'driver' => $driver,
          $driver => $connection_info['default'],
        ),
        'install_configure_form' => array(
          'site_name' => 'Drupal',
          'site_mail' => 'simpletest@example.com',
          'account' => array(
            'name' => $this->root_user->name,
            'mail' => $this->root_user->getEmail(),
            'pass' => array(
              'pass1' => $this->root_user->pass_raw,
              'pass2' => $this->root_user->pass_raw,
            ),
          ),
          // form_type_checkboxes_value() requires NULL instead of FALSE values
          // for programmatic form submissions to disable a checkbox.
          'update_status_module' => array(
            1 => NULL,
            2 => NULL,
          ),
        ),
      ),
    );
    return $parameters;
  }

  /**
   * Gets the random generator for the utility methods.
   *
   * @return \Drupal\Component\Utility\Random
   *   The random generator
   */
  protected function getRandomGenerator() {
    if (!is_object($this->randomGenerator)) {
      $this->randomGenerator = new Random();
    }
    return $this->randomGenerator;
  }

  /**
   * Generates a unique random string containing letters and numbers.
   *
   * Do not use this method when testing unvalidated user input. Instead, use
   * \Drupal\simpletest\TestBase::randomString().
   *
   * @param int $length
   *   Length of random string to generate.
   *
   * @return string
   *   Randomly generated unique string.
   *
   * @see \Drupal\Component\Utility\Random::name()
   */
  public function randomMachineName($length = 8) {
    return $this->getRandomGenerator()->name($length, TRUE);
  }

  /**
   * Generates a unique random string containing letters and numbers.
   *
   * Do not use this method when testing unvalidated user input. Instead, use
   * \Drupal\simpletest\TestBase::randomString().
   *
   * @param int $length
   *   Length of random string to generate.
   *
   * @return string
   *   Randomly generated unique string.
   *
   * @see \Drupal\Component\Utility\Random::name()
   */
  public function randomName($length = 8) {
    return $this->getRandomGenerator()->name($length, TRUE);
  }

  /**
   * Returns Mink assert session.
   *
   * @param string|null $name
   *   (optional) Name of the session. Defaults to the active session.
   *
   * @return \Drupal\Simpletest\WebAssert
   *   A new web-assert option for asserting the presence of elements with.
   */
  public function assertSession($name = NULL) {
    return $this->mink->assertSession($name);
  }

  /**
   * Fill and submit a form.
   *
   * @param array $edit
   *   Field data in an associative array. Changes the current input fields
   *   (where possible) to the values indicated.
   *
   *   A checkbox can be set to TRUE to be checked and should be set to FALSE to
   *   be unchecked.
   * @param string $submit
   *   Value of the submit button whose click is to be emulated. For example,
   *   t('Save'). The processing of the request depends on this value. For
   *   example, a form may have one button with the value t('Save') and another
   *   button with the value t('Delete'), and execute different code depending
   *   on which one is clicked.
   * @param string $form_html_id
   *   (optional) HTML ID of the form to be submitted. On some pages
   *   there are many identical forms, so just using the value of the submit
   *   button is not enough. For example: 'trigger-node-presave-assign-form'.
   *   Note that this is not the Drupal $form_id, but rather the HTML ID of the
   *   form, which is typically the same thing but with hyphens replacing the
   *   underscores.
   */
  protected function submitForm($edit, $submit, $form_html_id = NULL) {
    $assert_session = $this->assertSession();

    // Get the form.
    if (isset($form_html_id)) {
      $form = $assert_session->elementExists('xpath', "//form[@id='" . $form_html_id . "']");
      $submit_button = $assert_session->buttonExists($submit, $form);
    }
    else {
      $submit_button = $assert_session->buttonExists($submit);
      $form = $assert_session->elementExists('xpath', './ancestor::form', $submit_button);
    }

    // Edit the form values.
    foreach ($edit as $name => $value) {
      $field = $assert_session->fieldExists($name, $form);
      $field->setValue($value);
    }

    // Submit form.
    $this->prepareRequest();
    $submit_button->press();

    // Ensure that any changes to variables in the other thread are picked up.
    $this->refreshVariables();
  }

}
