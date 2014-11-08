<?php

/**
 * @file
 * Definition of \Drupal\simpletest\BrowserTestBase.
 */

namespace Drupal\simpletest;

use Behat\Mink\Exception\Exception;
use Behat\Mink\Mink;
use Behat\Mink\Session;
use Behat\Mink\Element\Element;
use Behat\Mink\Element\NodeElement;
use Behat\Mink\Driver\GoutteDriver;
use Drupal\Component\Utility\String;
use Drupal\Core\Database\ConnectionNotDefinedException;
use Drupal\Core\Database\Database;
use Drupal\Core\DrupalKernel;
use Drupal\Core\Session\UserSession;
use Drupal\Core\Site\Settings;
use Drupal\Core\Test\TestRunnerKernel;
use Symfony\Component\HttpFoundation\Request;

/**
 * Test case for typical Drupal tests.
 *
 * @ingroup testing
 */
abstract class BrowserTestBase extends \PHPUnit_Framework_TestCase {

  use WebTestTrait;

  /**
   * Class loader.
   *
   * @var object
   */
  protected $classLoader;

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
   * This is set in prepareEnvironment().
   *
   * @var string
   */
  protected $public_files_directory;

  /**
   * The private file directory for the test environment.
   *
   * This is set in prepareEnvironment().
   *
   * @var string
   */
  protected $private_files_directory;

  /**
   * The temp file directory for the test environment.
   *
   * This is set in prepareEnvironment().
   *
   * @var string
   */
  protected $temp_files_directory;

  /**
   * The translation file directory for the test environment.
   *
   * This is set in prepareEnvironment().
   *
   * @var string
   */
  protected $translation_files_directory;

  /**
   * The config importer that can used in a test.
   *
   * @var \Drupal\Core\Config\ConfigImporter
   */
  protected $configImporter;

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
   * Test ID.
   *
   * @var string
   */
  protected $testId;

  /**
   * Constructor for \Drupal\simpletest\BrowserTestBase.
   */
  public function __construct($test_id = NULL) {
    parent::__construct($test_id);
    $this->skipClasses[__CLASS__] = TRUE;
  }

  /**
   * Initializes mink sessions.
   */
  protected function initMink() {
    $driver = new GoutteDriver();
    $session = new Session($driver);
    $this->mink = new Mink();
    $this->mink->registerSession('goutte', $session);
    $this->mink->setDefaultSessionName('goutte');
    $this->registerSessions();
    return $session;
  }

  /**
   * Registers additional mink sessions.
   *
   * Tests wishing to use a different driver or change the default driver should
   * override this method.
   *
   * @code
   *   // Register a new session that uses the MinkPonyDriver.
   *   $pony = new MinkPonyDriver();
   *   $session = new Session($pony);
   *   $this->mink->registerSession('pony', $session);
   * @endcode
   */
  protected function registerSessions() {}

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    global $base_url;
    parent::setUp();

    // Get and set the domain of the environment we are running our test
    // coverage against.
    $domain = getenv('DOMAIN');
    if (!$domain) {
      throw new \InvalidArgumentException('You must provide a DOMAIN environment variable to run PHPUnit based functional tests.');
    }
    $base_url = 'http://' . $domain;
    $_SERVER['HTTP_HOST'] = $domain;

    // Install drupal test site.
    $this->prepareEnvironment();
    $this->installDrupal();

    // Setup Mink.
    $session = $this->initMink();

    // In order to debug web tests you need to either set a cookie, have the
    // Xdebug session in the URL or set an environment variable in case of CLI
    // requests. If the developer listens to connection when running tests, by
    // default the cookie is not forwarded to the client side, so you cannot
    // debug the code running on the test site. In order to make debuggers work
    // this bit of information is forwarded. Make sure that the debugger listens
    // to at least three external connections.
    $request = \Drupal::request();
    $cookie_params = $request->cookies;
    if ($cookie_params->has('XDEBUG_SESSION')) {
      $session->setCookie('XDEBUG_SESSION', $cookie_params->get('XDEBUG_SESSION'));
    }
    // For CLI requests, the information is stored in $_SERVER.
    $server = $request->server;
    if ($server->has('XDEBUG_CONFIG')) {
      // $_SERVER['XDEBUG_CONFIG'] has the form "key1=value1 key2=value2 ...".
      $pairs = explode(' ', $server->get('XDEBUG_CONFIG'));
      foreach ($pairs as $pair) {
        list($key, $value) = explode('=', $pair);
        // Account for key-value pairs being separated by multiple spaces.
        if (trim($key, ' ') == 'idekey') {
          $session->setCookie('XDEBUG_SESSION', trim($value, ' '));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function tearDown() {
    parent::tearDown();

    // Destroy the testing kernel.
    if (isset($this->kernel)) {
      $this->kernel->shutdown();
    }

    // Ensure that internal logged in variable is reset.
    $this->loggedInUser = FALSE;

    if ($this->mink) {
      $this->mink->stopSessions();
    }
  }

  /**
   * Generates a pseudo-random string of ASCII characters of codes 32 to 126.
   *
   * Do not use this method when special characters are not possible (e.g., in
   * machine or file names that have already been validated); instead, use
   * \Drupal\simpletest\TestBase::randomMachineName(). If $length is greater
   * than 2 the random string will include at least one ampersand ('&')
   * character to ensure coverage for special characters and avoid the
   * introduction of random test failures.
   *
   * @param int $length
   *   Length of random string to generate.
   *
   * @return string
   *   Pseudo-randomly generated unique string including special characters.
   *
   * @see \Drupal\Component\Utility\Random::string()
   */
  public function randomString($length = 8) {
    if ($length < 3) {
      return $this->getRandomGenerator()->string($length, TRUE, array($this, 'randomStringValidate'));
    }

    // To prevent the introduction of random test failures, ensure that the
    // returned string contains a character that needs to be escaped in HTML by
    // injecting an ampersand into it.
    $replacement_pos = floor($length / 2);
    // Remove 1 from the length to account for the ampersand character.
    $string = $this->getRandomGenerator()->string($length - 1, TRUE, array($this, 'randomStringValidate'));
    return substr_replace($string, '&', $replacement_pos, 0);
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
      $select = $this->assertSession()->selectExists($select, $container);
    }
    $options = [];
    /* @var \Behat\Mink\Element\NodeElement $option */
    foreach ($select->findAll('xpath', '//option') as $option) {
      $label = $option->getText();
      $value = $option->getAttribute('value') ?: $label;
      $options[$value] = $label;
    }
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function run(\PHPUnit_Framework_TestResult $result = NULL) {
    if ($result === NULL) {
      $result = $this->createResult();
    }

    parent::run($result);
    return $result;
  }

  /**
   * Override to use Mink exceptions.
   *
   * @return mixed
   *   Either a test result or NULL.
   * @throws \Exception When exception was thrown inside the test.
   */
  protected function runTest() {
    try {
      return parent::runTest();
    }
    catch (Exception $e) {
      throw new \PHPUnit_Framework_AssertionFailedError($e->getMessage());
    }
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
    copy(DRUPAL_ROOT . '/sites/default/default.services.yml', $directory . '/services.yml');

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
      // testing specific overrides.
      file_put_contents($directory . '/settings.php', "\n\$test_class = '" . get_class($this) . "';\n" . 'include DRUPAL_ROOT . \'/\' . $site_path . \'/settings.testing.php\';' . "\n", FILE_APPEND);
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
   * Generates a database prefix for running tests.
   *
   * The database prefix is used by prepareEnvironment() to setup a public files
   * directory for the test to be run, which also contains the PHP error log,
   * which is written to in case of a fatal error. Since that directory is based
   * on the database prefix, all tests (even unit tests) need to have one, in
   * order to access and read the error log.
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
   * @see \Drupal\simpletest\TestBase::prepareEnvironment
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

}
