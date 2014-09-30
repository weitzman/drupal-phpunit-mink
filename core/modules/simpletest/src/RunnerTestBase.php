<?php

/**
 * @file
 * Definition of \Drupal\simpletest\FunctionalTestBase.
 */

namespace Drupal\simpletest;

use Drupal\Component\Utility\Random;
use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\String;
use Drupal\Core\Database\ConnectionNotDefinedException;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Database\Database;
use Drupal\Core\Session\UserSession;
use Drupal\Core\Site\Settings;
use Drupal\Core\Test\TestRunnerKernel;
use Symfony\Component\HttpFoundation\Request;

/**
 * Base class for functional tests.
 *
 * @ingroup testing
 */
abstract class RunnerTestBase extends \PHPUnit_Framework_TestCase implements \PHPUnit_Framework_TestListener {
  /**
   * Class loader.
   *
   * @var object
   */
  protected $classLoader;

  /**
   * The site directory of this test run.
   *
   * @var string
   */
  protected $siteDirectory = NULL;

  /**
   * The database prefix of this test run.
   *
   * @var string
   */
  protected $databasePrefix = NULL;

  /**
   * The site directory of the original parent site.
   *
   * @var string
   */
  protected $originalSite;

  /**
   * Time limit for the test.
   */
  protected $timeLimit = 500;

  /**
   * The public file directory for the test environment.
   *
   * This is set in TestBase::prepareEnvironment().
   *
   * @var string
   */
  protected $public_files_directory;

  /**
   * The private file directory for the test environment.
   *
   * This is set in TestBase::prepareEnvironment().
   *
   * @var string
   */
  protected $private_files_directory;

  /**
   * The temp file directory for the test environment.
   *
   * This is set in TestBase::prepareEnvironment().
   *
   * @var string
   */
  protected $temp_files_directory;

  /**
   * The translation file directory for the test environment.
   *
   * This is set in TestBase::prepareEnvironment().
   *
   * @var string
   */
  protected $translation_files_directory;

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
   * The config importer that can used in a test.
   *
   * @var \Drupal\Core\Config\ConfigImporter
   */
  protected $configImporter;

  /**
   * The random generator.
   *
   * @var \Drupal\Component\Utility\Random
   */
  protected $randomGenerator;

  /**
   * The profile to install as a basis for testing.
   *
   * @var string
   */
  protected $profile = 'testing';

  /**
   * The current session name, if available.
   */
  protected $session_name = NULL;

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
   * The config directories used in this test.
   */
  protected $configDirectories = array();

  /**
   * An array of custom translations suitable for drupal_rewrite_settings().
   *
   * @var array
   */
  protected $customTranslations;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    global $base_url;

    // Get and set the domain of the environment we are running our test
    // coverage against.
    $domain = getenv('DOMAIN');
    $base_url = 'http://' . $domain;
    $_SERVER['HTTP_HOST'] = $domain;

    $this->prepareEnvironment();
    $this->installDrupal();

    // Defer back to PHPUnit's setUp runner.
    parent::setUp();
  }

  /**
   * {@inheritdoc}
   */
  public function installDrupal() {
    // Define information about the user 1 account.
    $this->root_user = new UserSession(array(
      'uid' => 1,
      'name' => 'admin',
      'mail' => 'admin@example.com',
      'pass_raw' => $this->randomName(),
    ));

    // Some tests (SessionTest and SessionHttpsTest) need to examine whether the
    // proper session cookies were set on a response. Because the child site
    // uses the same session name as the test runner, it is necessary to make
    // that available to test-methods.
    $this->session_name = session_name();

    // Get parameters for install_drupal() before removing global variables.
    $parameters = $this->installParameters();

    // Prepare installer settings that are not install_drupal() parameters.
    // Copy and prepare an actual settings.php, so as to resemble a regular
    // installation.
    // Not using File API; a potential error must trigger a PHP warning.
    $directory = DRUPAL_ROOT . '/' . $this->siteDirectory;
    copy(DRUPAL_ROOT . '/sites/default/default.settings.php', $directory . '/settings.php');

    // All file system paths are created by System module during installation.
    // @see system_requirements()
    // @see TestBase::prepareEnvironment()
    $settings['settings']['file_public_path'] = (object) array(
      'value' => $this->public_files_directory,
      'required' => TRUE,
    );
    $this->writeSettings($settings);
    // Allow for test-specific overrides.
    $settings_testing_file = DRUPAL_ROOT . '/' . $this->originalSite . '/settings.testing.php';
    if (file_exists($settings_testing_file)) {
      // Copy the testing-specific settings.php overrides in place.
      copy($settings_testing_file, $directory . '/settings.testing.php');
      // Add the name of the testing class to settings.php and include the
      // testing specific overrides
      file_put_contents($directory . '/settings.php', "\n\$test_class = '" . get_class($this) ."';\n" . 'include DRUPAL_ROOT . \'/\' . $site_path . \'/settings.testing.php\';' ."\n", FILE_APPEND);
    }
    $settings_services_file = DRUPAL_ROOT . '/' . $this->originalSite . '/testing.services.yml';
    if (file_exists($settings_services_file)) {
      // Copy the testing-specific service overrides in place.
      copy($settings_services_file, $directory . '/services.yml');
    }

    // Since Drupal is bootstrapped already, install_begin_request() will not
    // bootstrap into DRUPAL_BOOTSTRAP_CONFIGURATION (again). Hence, we have to
    // reload the newly written custom settings.php manually.
    Settings::initialize($directory, $this->classLoader);

    // Execute the non-interactive installer.
    require_once DRUPAL_ROOT . '/core/includes/install.core.inc';
    install_drupal($parameters);

    // Import new settings.php written by the installer.
    Settings::initialize($directory, $this->classLoader);
    foreach ($GLOBALS['config_directories'] as $type => $path) {
      $this->configDirectories[$type] = $path;
    }

    // After writing settings.php, the installer removes write permissions
    // from the site directory. To allow drupal_generate_test_ua() to write
    // a file containing the private key for drupal_valid_test_ua(), the site
    // directory has to be writable.
    // TestBase::restoreEnvironment() will delete the entire site directory.
    // Not using File API; a potential error must trigger a PHP warning.
    chmod($directory, 0777);

    $request = \Drupal::request();
    $this->kernel = DrupalKernel::createFromRequest($request, $this->classLoader, 'prod', TRUE);
    $this->kernel->prepareLegacyRequest($request);
    // Force the container to be built from scratch instead of loaded from the
    // disk. This forces us to not accidently load the parent site.
    $container = $this->kernel->rebuildContainer();

    $config = $container->get('config.factory');

    // Manually create and configure private and temporary files directories.
    // While these could be preset/enforced in settings.php like the public
    // files directory above, some tests expect them to be configurable in the
    // UI. If declared in settings.php, they would no longer be configurable.
    file_prepare_directory($this->private_files_directory, FILE_CREATE_DIRECTORY);
    file_prepare_directory($this->temp_files_directory, FILE_CREATE_DIRECTORY);
    $config->get('system.file')
      ->set('path.private', $this->private_files_directory)
      ->set('path.temporary', $this->temp_files_directory)
      ->save();

    // Manually configure the test mail collector implementation to prevent
    // tests from sending out emails and collect them in state instead.
    // While this should be enforced via settings.php prior to installation,
    // some tests expect to be able to test mail system implementations.
    $config->get('system.mail')
      ->set('interface.default', 'test_mail_collector')
      ->save();

    // By default, verbosely display all errors and disable all production
    // environment optimizations for all tests to avoid needless overhead and
    // ensure a sane default experience for test authors.
    // @see https://drupal.org/node/2259167
    $config->get('system.logging')
      ->set('error_level', 'verbose')
      ->save();
    $config->get('system.performance')
      ->set('css.preprocess', FALSE)
      ->set('js.preprocess', FALSE)
      ->save();

    // Collect modules to install.
    $class = get_class($this);
    $modules = array();
    while ($class) {
      if (property_exists($class, 'modules')) {
        $modules = array_merge($modules, $class::$modules);
      }
      $class = get_parent_class($class);
    }
    if ($modules) {
      $modules = array_unique($modules);
      $success = $container->get('module_handler')->install($modules, TRUE);
      $this->assertTrue($success, String::format('Enabled modules: %modules', array('%modules' => implode(', ', $modules))));
      $this->rebuildContainer();
    }

    // Reset/rebuild all data structures after enabling the modules, primarily
    // to synchronize all data structures and caches between the test runner and
    // the child site.
    // Affects e.g. file_get_stream_wrappers().
    // @see \Drupal\Core\DrupalKernel::bootCode()
    // @todo Test-specific setUp() methods may set up further fixtures; find a
    //   way to execute this after setUp() is done, or to eliminate it entirely.
    $this->resetAll();
    $this->kernel->prepareLegacyRequest($request);
  }

  /**
   * {@inheritdoc}
   */
  public function tearDown() {
    // Destroy the testing kernel.
    if (isset($this->kernel)) {
      $this->kernel->shutdown();
    }
    parent::tearDown();
    // Ensure that internal logged in variable is reset.
    $this->loggedInUser = FALSE;
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
   * Generates a database prefix for running tests.
   *
   * The database prefix is used by prepareEnvironment() to setup a public files
   * directory for the test to be run, which also contains the PHP error log,
   * which is written to in case of a fatal error. Since that directory is based
   * on the database prefix, all tests (even unit tests) need to have one, in
   * order to access and read the error log.
   *
   * @see TestBase::prepareEnvironment()
   *
   * The generated database table prefix is used for the Drupal installation
   * being performed for the test. It is also used as user agent HTTP header
   * value by the cURL-based browser of DrupalWebTestCase, which is sent to the
   * Drupal installation of the test. During early Drupal bootstrap, the user
   * agent HTTP header is parsed, and if it matches, all database queries use
   * the database table prefix that has been generated here.
   *
   * @see WebTestBase::curlInitialize()
   * @see drupal_valid_test_ua()
   */
  private function prepareDatabasePrefix() {
    // Ensure that the generated test site directory does not exist already,
    // which may happen with a large amount of concurrent threads and
    // long-running tests.
    do {
      $suffix = mt_rand(100000, 999999);
      $this->siteDirectory = 'sites/simpletest/' . $suffix;
      $this->databasePrefix = 'simpletest' . $suffix;
    } while (is_dir(DRUPAL_ROOT . '/' . $this->siteDirectory));
  }

  /**
   * Changes the database connection to the prefixed one.
   *
   * @see TestBase::prepareEnvironment()
   */
  private function changeDatabasePrefix() {
    if (empty($this->databasePrefix)) {
      $this->prepareDatabasePrefix();
    }

    // Clone the current connection and replace the current prefix.
    $connection_info = Database::getConnectionInfo('default');
    Database::renameConnection('default', 'simpletest_original_default');
    foreach ($connection_info as $target => $value) {
      // Replace the full table prefix definition to ensure that no table
      // prefixes of the test runner leak into the test.
      $connection_info[$target]['prefix'] = array(
        'default' => $value['prefix']['default'] . $this->databasePrefix,
      );
    }
    Database::addConnectionInfo('default', 'default', $connection_info['default']);
  }

  /**
   * Prepares the current environment for running the test.
   *
   * Backups various current environment variables and resets them, so they do
   * not interfere with the Drupal site installation in which tests are executed
   * and can be restored in TestBase::restoreEnvironment().
   *
   * Also sets up new resources for the testing environment, such as the public
   * filesystem and configuration directories.
   *
   * This method is private as it must only be called once by TestBase::run()
   * (multiple invocations for the same test would have unpredictable
   * consequences) and it must not be callable or overridable by test classes.
   *
   * @see TestBase::beforePrepareEnvironment()
   */
  protected function prepareEnvironment() {
    // Bootstrap Drupal so we can use Drupal's built in functions.
    $this->classLoader = require __DIR__ . '/../../../vendor/autoload.php';
    $request = Request::createFromGlobals();
    $kernel = TestRunnerKernel::createFromRequest($request, $this->classLoader);
    $kernel->prepareLegacyRequest($request);

    $this->prepareDatabasePrefix();

    // Create test directory ahead of installation so fatal errors and debug
    // information can be logged during installation process.
    file_prepare_directory($this->siteDirectory, FILE_CREATE_DIRECTORY | FILE_MODIFY_PERMISSIONS);

    // Prepare filesystem directory paths.
    $this->public_files_directory = $this->siteDirectory . '/files';
    $this->private_files_directory = $this->siteDirectory . '/private';
    $this->temp_files_directory = $this->siteDirectory . '/temp';
    $this->translation_files_directory = $this->siteDirectory . '/translations';

    // Ensure the configImporter is refreshed for each test.
    $this->configImporter = NULL;

    // Unregister all custom stream wrappers of the parent site.
    // Availability of Drupal stream wrappers varies by test base class:
    // - UnitTestBase operates in a completely empty environment.
    // - KernelTestBase supports and maintains stream wrappers in a custom
    //   way.
    // - WebTestBase re-initializes Drupal stream wrappers after installation.
    // The original stream wrappers are restored after the test run.
    // @see TestBase::restoreEnvironment()
    $wrappers = file_get_stream_wrappers();
    foreach ($wrappers as $scheme => $info) {
      stream_wrapper_unregister($scheme);
    }

    // Reset statics.
    drupal_static_reset();

    // Ensure there is no service container.
    $this->container = NULL;
    \Drupal::setContainer(NULL);

    // Unset globals.
    unset($GLOBALS['config_directories']);
    unset($GLOBALS['config']);
    unset($GLOBALS['conf']);
    unset($GLOBALS['theme_key']);
    unset($GLOBALS['theme']);
    unset($GLOBALS['theme_info']);
    unset($GLOBALS['base_theme_info']);
    unset($GLOBALS['theme_engine']);
    unset($GLOBALS['theme_path']);

    // Log fatal errors.
    ini_set('log_errors', 1);
    ini_set('error_log', DRUPAL_ROOT . '/' . $this->siteDirectory . '/error.log');

    // Change the database prefix.
    $this->changeDatabasePrefix();

    // After preparing the environment and changing the database prefix, we are
    // in a valid test environment.
    drupal_valid_test_ua($this->databasePrefix);
    conf_path(FALSE, TRUE);

    // Reset settings.
    new Settings(array(
      // For performance, simply use the database prefix as hash salt.
      'hash_salt' => $this->databasePrefix,
    ));

    drupal_set_time_limit($this->timeLimit);
  }

  /**
   * Returns the database connection to the site running Simpletest.
   *
   * @return \Drupal\Core\Database\Connection
   *   The database connection to use for inserting assertions.
   */
  public static function getDatabaseConnection() {
    // Check whether there is a test runner connection.
    // @see run-tests.sh
    // @todo Convert Simpletest UI runner to create + use this connection, too.
    try {
      $connection = Database::getConnection('default', 'test-runner');
    }
    catch (ConnectionNotDefinedException $e) {
      // Check whether there is a backup of the original default connection.
      // @see TestBase::prepareEnvironment()
      try {
        $connection = Database::getConnection('default', 'simpletest_original_default');
      }
      catch (ConnectionNotDefinedException $e) {
        error_log('8');
        // If TestBase::prepareEnvironment() or TestBase::restoreEnvironment()
        // failed, the test-specific database connection does not exist
        // yet/anymore, so fall back to the default of the (UI) test runner.
        $connection = Database::getConnection('default', 'default');
      }
    }
    return $connection;
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
   * @param $override_server_vars
   *   An array of server variables to override.
   *
   * @return Request
   *   The mocked request object.
   */
  protected function prepareRequestForGenerator($clean_urls = TRUE, $override_server_vars = array()) {
    $request = Request::createFromGlobals();
    $server = $request->server->all();
    if (basename($server['SCRIPT_FILENAME']) != basename($server['SCRIPT_NAME'])) {
      // We need this for when the test is executed by run-tests.sh.
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
   * Takes a path and returns an absolute path.
   *
   * @param $path
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
   * {@inheritdoc}
   */
  public function addError(\PHPUnit_Framework_Test $test, \Exception $e, $time) {}

  /**
   * {@inheritdoc}
   */
  public function addFailure(\PHPUnit_Framework_Test $test, \PHPUnit_Framework_AssertionFailedError $e, $time) {}

  /**
   * {@inheritdoc}
   */
  public function addIncompleteTest(\PHPUnit_Framework_Test $test, \Exception $e, $time) {}

  /**
   * {@inheritdoc}
   */
  public function addRiskyTest(\PHPUnit_Framework_Test $test, \Exception $e, $time) {}

  /**
   * {@inheritdoc}
   */
  public function addSkippedTest(\PHPUnit_Framework_Test $test, \Exception $e, $time) {}

  /**
   * {@inheritdoc}
   */
  public function startTestSuite(\PHPUnit_Framework_TestSuite $suite) {}

  /**
   * {@inheritdoc}
   */
  public function endTestSuite(\PHPUnit_Framework_TestSuite $suite) {}

  /**
   * A test started.
   *
   * @param \PHPUnit_Framework_Test $test
   */
  public function startTest(\PHPUnit_Framework_Test $test) {
    printf("Starting '%s' \n", $test->getName());
  }

  /**
   * {@inheritdoc}
   */
  public function endTest(\PHPUnit_Framework_Test $test, $time) {}

}
