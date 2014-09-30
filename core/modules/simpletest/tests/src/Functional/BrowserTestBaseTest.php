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
    // Visit a drupal page.
    $this->drupalGet('/simpletest/hello');

    // Test response code.
    $this->assertResponseStatus(200);

    // Test page contains some text.
    $this->assertPageTextContains('Hello Amsterdam');
  }

  /**
   * Tests basic page test.
   */
  function testForm() {
    $this->drupalGet('');

    // Fill out form.


    // File upload.

  }
}
