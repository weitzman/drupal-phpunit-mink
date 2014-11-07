<?php

/**
 * @file
 * Contains \Drupal\simpletest_test\Controller\SimpletestController.
 */

namespace Drupal\simpletest_test\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Defines a test controller for testing \Drupal\simpletest\BrowserTestBase.
 */
class SimpletestController extends ControllerBase {

  /**
   * Provides output for testing \Drupal\simpletest\BrowserTestBase.
   *
   * @return array
   *   A render array.
   */
  public function hello() {
    return array(
      '#markup' => $this->t('Hello Amsterdam'),
    );
  }

}
