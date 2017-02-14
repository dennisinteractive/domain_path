<?php

namespace Drupal\domain_path;

use Drupal\pathauto\AliasStorageHelperInterface;
use Drupal\pathauto\MessengerInterface;
use Drupal\pathauto\AliasStorageHelper as PathautoAliasStorageHelper;
use Drupal\pathauto\PathautoGeneratorInterface;
use Drupal\Core\Url;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Path\AliasStorageInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;

/**
 * Provides helper methods for accessing alias storage.
 */
class AliasStorageHelper extends PathautoAliasStorageHelper {

  /**
   * {@inheritdoc}
   */
  public function save(array $path, $existing_alias = NULL, $op = NULL) {
    if (empty($path['source'])) {
      return NULL;
    }
    $params = Url::fromUri("internal:" . $path['source'])->getRouteParameters();
    $entity_type = key($params);
    $entity = \Drupal::entityTypeManager()->getStorage($entity_type)->load($params[$entity_type]);

    $config = $this->configFactory->get('pathauto.settings');

    // Check if the path has been added to new domains.
    // Or removed from an existting domain.
    $changed = $path['source'] != $path['alias'] || $this->aliasStorage->entityAliasesHaveChanged($entity, $path['source']);

    // We're using a false value when updating,
    // it will not be used other than to check status.
    $pid = $op === 'update' ? 999 : 0;

    // Alert users if they are trying to create an alias that is the same as the
    // internal path.
    if (!$changed) {
      $this->messenger->addMessage($this->t('Ignoring alias %alias because it is the same as the internal path.', array('%alias' => $path['alias'])));
      return NULL;
    }

    // Skip replacing the current alias with an identical alias.
    if (empty($existing_alias) || $changed) {
      $path += array(
        'pathauto' => TRUE,
        'original' => $existing_alias,
        'pid' => NULL,
      );

      // If there is already an alias, respect some update actions.
      if (!empty($existing_alias)) {
        switch ($config->get('update_action')) {
          case PathautoGeneratorInterface::UPDATE_ACTION_NO_NEW:
            // Do not create the alias if nothing has changed.
            if (!$changed) {
              return NULL;
            }
            // Otherwise make sure it is the same as before,
            // this allows us add aliases for new domains.
            $path['alias'] = $existing_alias;
            $pid = 999;
            break;

          case PathautoGeneratorInterface::UPDATE_ACTION_LEAVE:
            // Create a new alias instead of overwriting the existing by leaving
            // $path['pid'] empty.
            $pid = 0;
            $this->aliasStorage->setDeleteInaccessible(FALSE);
            break;

          case PathautoGeneratorInterface::UPDATE_ACTION_DELETE:
            // The delete actions should overwrite the existing alias.
            $pid = 999;
            break;
        }
      }

      // Save the path array.
      $this->aliasStorage->save($path['source'], $path['alias'], $path['language'], $pid);

      if ($op === 'update') {
        $this->messenger->addMessage($this->t(
            'Created new alias %alias for %source, replacing %old_alias.',
            array(
              '%alias' => $path['alias'],
              '%source' => $path['source'],
              '%old_alias' => $existing_alias['alias'],
            )
          )
        );
      }
      else if ($op === 'insert') {
        $this->messenger->addMessage($this->t('Created new alias %alias for %source.', array(
          '%alias' => $path['alias'],
          '%source' => $path['source'],
        )));
      }

      return $path;
    }
  }

}
