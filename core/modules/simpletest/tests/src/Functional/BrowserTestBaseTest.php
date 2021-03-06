<?php

/**
 * @file
 * Definition of Drupal\Tests\simpletest\Functional\BrowserTestBaseTest.
 */

namespace Drupal\Tests\simpletest\Functional;

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
    $account = $this->drupalCreateUser(array('simpletest_test access tests'));
    $this->drupalLogin($account);

    // Visit a Drupal page that requires login.
    $this->drupalGet('/simpletest/hello');
    $this->assertSession()->statusCodeEquals(200);

    // Test page contains some text.
    $this->assertSession()->pageTextContains('Hello Amsterdam');
  }

  /**
   * Tests basic form functionality.
   */
  public function testForm() {

    // Ensure the proper response code for a _form route.
    $this->drupalGet('simpletest/example-form');
    $this->assertSession()->statusCodeEquals(200);

    // Ensure the form and text field exist.
    $this->assertSession()->elementExists('css', 'form#simpletest-test-example-form');
    $this->assertSession()->fieldExists('name');

    $edit = ['name' => 'Foobaz'];
    $this->submitForm($edit, 'Save configuration', 'simpletest-test-example-form');

    $this->drupalGet('/simpletest/example-form');
    $this->assertSession()->fieldValueEquals('name', 'Foobaz');
  }

}
