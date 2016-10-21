<?php
namespace Drupal\domain_path;

use Drupal\path\Plugin\Field\FieldType\PathItem as CorePathItem;



/**
 * Extends the default PathItem implementation.
 */
class PathItem extends CorePathItem {

  /**
   * {@inheritdoc}
   */
  public function postSave($update) {
    $entity = $this->getEntity();
    $values  = \Drupal::service('domain_access.manager')->getAccessValues($entity);

    // NB: do not use $this->pid as there can multiple pids per path.

    // Load all pids for this entity.
    $source = '/' . $entity->getEntityType()->id() . '/' . $entity->id();
    $rows = \Drupal::service('path.alias_storage')->loadMultiple(['source' => $source]);
    $pids = [];
    foreach ($rows as $row) {
      $pids[$row->domain_id] = $row->pid;
    }

    $all_affiliates = $entity->get('field_domain_all_affiliates')->getValue();
    if (!empty($all_affiliates['value'])) {
      $values[] = AliasStorage::ALL_AFFILIATES;
      //$pids[AliasStorage::ALL_AFFILIATES] = $row->pid;
    }

    foreach ($values as $domain_id) {
      if (!$update) {
        if ($this->alias) {
          \Drupal::service('path.alias_storage')
            ->setDomainId($domain_id)
            ->setEntity($entity)
            ->save('/' . $entity->urlInfo()
                ->getInternalPath(), $this->alias, $this->getLangcode());
        }
      }
      else {
        if (isset($pids[$domain_id])) {
          $pid = $pids[$domain_id];
          unset($pids[$domain_id]);
        }
        else {
          $pid = NULL;
        }
        // Delete old alias if user erased it.
        if ($pid && !$this->alias) {
          \Drupal::service('path.alias_storage')
            ->delete(array('pid' => $pid));
        }
        // Only save a non-empty alias.
        elseif ($this->alias) {
          \Drupal::service('path.alias_storage')
            ->setDomainId($domain_id)
            ->setEntity($entity)
            ->save('/' . $entity->urlInfo()
                ->getInternalPath(), $this->alias, $this->getLangcode(), $pid);
        }
      }
    }

    // If any pids are left, delete them.
    if (count($pids) > 0) {
      foreach ($pids as $pid) {
        \Drupal::service('path.alias_storage')
          ->delete(array('pid' => $pid));
      }
    }


  }

}
