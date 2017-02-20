<?php
namespace Drupal\domain_path;

use Drupal\pathauto\PathautoItem as BasePathautoItem;
use Drupal\pathauto\PathautoState;

/**
 * Extends the default PathautoItem implementation.
 */
class PathautoItem extends BasePathautoItem {
  use PathStorageTrait;

  /**
   * {@inheritdoc}
   */
  public function postSave($update) {
    // Only allow the parent implementation to act if pathauto will not create
    // an alias.
    if ($this->pathauto == PathautoState::SKIP) {
      $this->saveAliases($update);
    }
    $this->get('pathauto')->persist();
  }

}
