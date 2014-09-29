<?php

/**
 * @file
 * The PHP page that serves all page requests on a Drupal installation.
 *
 * All Drupal code is released under the GNU General Public License.
 * See COPYRIGHT.txt and LICENSE.txt files in the "core" directory.
 */

use Drupal\Core\DrupalKernel;
use Drupal\Core\Site\Settings;
use Drupal\simpletest\RemoteCoverage\RemoteCoverageTool;
use Symfony\Component\HttpFoundation\Request;

$autoloader = require_once __DIR__ . '/core/vendor/autoload.php';

// We enable code coverage here if client is requesting test site or asking
// for code coverage data. It is done before Drupal is bootstrapped in order
// to have code coverage of the bootstrap and Drupal kernel.
$http_user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : NULL;
$user_agent = isset($_COOKIE['SIMPLETEST_USER_AGENT']) ? $_COOKIE['SIMPLETEST_USER_AGENT'] : $http_user_agent;
if (strpos($user_agent, 'simpletest') !== FALSE || isset($_GET[RemoteCoverageTool::TEST_ID_VARIABLE])) {
  $coverage_dir = sys_get_temp_dir() . '/simpletest';
  if (!file_exists($coverage_dir)) {
    mkdir($coverage_dir);
  }
  RemoteCoverageTool::init($coverage_dir);
}

try {

  $request = Request::createFromGlobals();
  $kernel = DrupalKernel::createFromRequest($request, $autoloader, 'prod');
  $response = $kernel
    ->handlePageCache($request)
    ->handle($request)
      // Handle the response object.
      ->prepare($request)->send();
  $kernel->terminate($request, $response);
}
catch (Exception $e) {
  $message = 'If you have just changed code (for example deployed a new module or moved an existing one) read <a href="http://drupal.org/documentation/rebuild">http://drupal.org/documentation/rebuild</a>';
  if (Settings::get('rebuild_access', FALSE)) {
    $rebuild_path = $GLOBALS['base_url'] . '/rebuild.php';
    $message .= " or run the <a href=\"$rebuild_path\">rebuild script</a>";
  }

  // Set the response code manually. Otherwise, this response will default to a
  // 200.
  http_response_code(500);
  print $message;
  throw $e;
}
