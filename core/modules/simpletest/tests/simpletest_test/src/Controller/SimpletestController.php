<?php
namespace Drupal\simpletest_test\Controller;

use Drupal\Core\Controller\ControllerBase;

class SimpletestController extends ControllerBase {
  public function hello() {
    return array(
      '#markup' => $this->t('Hello Amsterdam'),
    );
  }
}
