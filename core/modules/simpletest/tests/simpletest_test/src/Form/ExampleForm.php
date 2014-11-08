<?php

/**
 * @file
 * Contains \Drupal\simpletest_test\Form\ExampleForm.
 */

namespace Drupal\simpletest_test\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides an example form for testing \Drupal\simpletest\BrowserTestBase.
 */
class ExampleForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'simpletest_test_example_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $form['name'] = [
      '#type' => 'textfield',
      '#default_value' => \Drupal::config('simpletest_test.settings')->get('name'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // Normally this config object would be injected but this is a only a test
    // so...who cares?
    \Drupal::config('simpletest_test.settings')->set('name', $form_state->getValue('name'))->save();
    parent::submitForm($form, $form_state);
  }

}
