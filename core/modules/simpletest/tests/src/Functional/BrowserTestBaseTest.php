<?php

/**
 * @file
 * Definition of Drupal\simpletest\Tests\BrowserTestBaseTest.
 */

namespace Drupal\simpletest\Tests;

use Drupal\simpletest\BrowserTestBase;

/**
 * Tests BrowserTestBase functionality.
 *
 * @group simpletest
 * @group modern
 */
class BrowserTestBaseTest extends BrowserTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('simpletest_test');

  /**
   * Tests basic page test.
   */
  function testGoTo() {
    $this->drupalGet('');
  }

  /**
   * Tests basic page test.
   */
  function testForm() {
    $this->drupalGet('');
  }

  /**
   * Tests basic page test.
   */
  function testAjax() {
    $this->drupalGet('');
  }

}
