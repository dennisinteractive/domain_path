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
    if ($entity->getEntityType()->id() != 'taxonomy_term') {
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
      if (!empty($all_affiliates[0]['value'])) {
        $values['all'] = AliasStorage::ALL_AFFILIATES;
      }

      foreach ($values as $domain_id) {

        // Check if its an update.
        if (isset($pids[$domain_id])) {
          $pid = $pids[$domain_id];
          unset($pids[$domain_id]);
        }
        else {
          $pid = NULL;
        }

        // Only save a non-empty alias.
        if ($this->alias) {
          if (is_null($pid)) {
            // Create a new record.
            \Drupal::service('path.alias_storage')
              ->setDomainId($domain_id)
              ->setEntity($entity)
              ->save('/' . $entity->urlInfo()
                  ->getInternalPath(), $this->alias, $this->getLangcode());
          }
          else {
            // Update the existing record.
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
    // If the entity is a taxonomy_term.
    else {
      // Create a new record.
      \Drupal::service('path.alias_storage')
        ->setDomainId(0)
        ->setEntity($entity)
        ->save('/' . $entity->urlInfo()
            ->getInternalPath(), $this->alias, $this->getLangcode());
    }
  }

}
