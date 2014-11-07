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
  public function testGoTo() {
    // Visit a drupal page.
    $this->drupalGet('/simpletest/hello');

    // Test response code.
    $this->assertSession()->statusCodeEquals(200);

    // Test page contains some text.
    $this->assertSession()->pageTextContains('Hello Amsterdam');
  }

  /**
   * Tests basic form functionality.
   */
  public function testForm() {
    $this->drupalGet('');
  }

}
