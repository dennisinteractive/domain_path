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
    drupal_set_message("update: $update");
    $entity = $this->getEntity();
    $values  = \Drupal::service('domain_access.manager')->getAccessValues($entity);

    if ($entity->get('field_domain_all_affiliates')->getValue()) {
      //$values[] = AliasStorage::ALL_AFFILIATES;
    }

    // NB: do not use $this->pid as there can multiples pids per path.

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

        // Load pid based on domain_id & source.
        $source = '/' . $entity->getEntityType()->id() . '/' . $entity->id();
        drupal_set_message("Source: $source");
        $data = \Drupal::service('path.alias_storage')->load(['domain_id' => $domain_id, 'source' => $source]);
        $pid = isset($data['pid']) ? $data['pid'] : NULL;

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


  }

}
