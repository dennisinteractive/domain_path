<?php

namespace Drupal\domain_path;

/**
 * Supplies save functionality for manual entity paths.
 */
trait PathStorageTrait {

  /**
   * {@inheritdoc}
   */
  public function saveAliases($update) {
    $entity = $this->getEntity();
    $pid = $update ? 999: 0;
    \Drupal::service('path.alias_storage')->save('/' . $entity->toUrl()->getInternalPath(), $this->alias, $this->getLangcode(), $pid);
  }

}
