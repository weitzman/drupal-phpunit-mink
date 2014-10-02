<?php
namespace Drupal\simpletest;

use Behat\Mink\Element\NodeElement;
use Behat\Mink\Element\TraversableElement;
use Behat\Mink\Exception\ElementNotFoundException;

class WebAssert extends \Behat\Mink\WebAssert {
  /**
   * Checks that specific field exists on the current page.
   *
   * @param string $button button id|name|label|value
   * @param TraversableElement $container document to check against
   *
   * @return NodeElement
   *
   * @throws ElementNotFoundException
   */
  public function buttonExists($button, TraversableElement $container = NULL) {
    $container = $container ?: $this->session->getPage();
    $node = $container->findButton($button);

    if (NULL === $node) {
      throw new ElementNotFoundException($this->session, 'button', 'id|name|label|value', $button);
    }

    return $node;
  }

  /**
   * Checks that specific field exists on the current page.
   *
   * @param string $select select id|name|label|value
   * @param TraversableElement $container document to check against
   *
   * @return NodeElement
   *
   * @throws ElementNotFoundException
   */
  public function selectExists($select, TraversableElement $container = NULL) {
    $container = $container ?: $this->session->getPage();
    $node = $container->find('named', array(
      'select', $this->session->getSelectorsHandler()->xpathLiteral($select)
    ));

    if (NULL === $node) {
      throw new ElementNotFoundException($this->session, 'select', 'id|name|label|value', $select);
    }

    return $node;
  }
}
