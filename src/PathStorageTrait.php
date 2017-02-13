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
    $op = $update ? 'update': 'insert';
    \Drupal::service('path.alias_storage')->saveDomainAliases('/' . $entity->toUrl()->getInternalPath(), $this->alias, $this->getLangcode(), $entity, $op);
  }

}
