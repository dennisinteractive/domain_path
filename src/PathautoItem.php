<?php
namespace Drupal\domain_path;

use Drupal\pathauto\PathautoItem as BasePathautoItem;

/**
 * Extends the default PathautoItem implementation.
 */
class PathautoItem extends BasePathautoItem {


  /**
   * {@inheritdoc}
   */
  public function postSave($update) {
    // Only allow the parent implementation to act if pathauto will not create
    // an alias.
    if ($this->pathauto == PathautoState::SKIP) {
      PathItem::postSave($update);
    }
    $this->get('pathauto')->persist();
  }

}
