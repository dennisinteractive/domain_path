<?php
namespace Drupal\domain_path;

use Drupal\path\Plugin\Field\FieldType\PathItem as CorePathItem;



/**
 * Extends the default PathItem implementation.
 */
class PathItem extends CorePathItem {
  use PathStorageTrait;

  /**
   * {@inheritdoc}
   */
  public function postSave($update) {
    $this->saveAliases($update);
  }

}
