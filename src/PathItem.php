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

    foreach ($values as $domain_id) {
      if (!$update) {
        if ($this->alias) {
          if ($path = \Drupal::service('path.alias_storage')
            ->setDomainId($domain_id)
            ->setEntity($entity)
            ->save('/' . $entity->urlInfo()
                ->getInternalPath(), $this->alias, $this->getLangcode())
          ) {
            $this->pid = $path['pid'];
          }
        }
      }
      else {
        // Delete old alias if user erased it.
        if ($this->pid && !$this->alias) {
          \Drupal::service('path.alias_storage')
            ->delete(array('pid' => $this->pid));
        }
        // Only save a non-empty alias.
        elseif ($this->alias) {
          \Drupal::service('path.alias_storage')
            ->setDomainId($domain_id)
            ->setEntity($entity)
            ->save('/' . $entity->urlInfo()
                ->getInternalPath(), $this->alias, $this->getLangcode(), $this->pid);
        }
      }
    }

  }

}
