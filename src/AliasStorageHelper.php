<?php

namespace Drupal\domain_path;

use Drupal\pathauto\AliasStorageHelperInterface;
use Drupal\pathauto\MessengerInterface;
use Drupal\pathauto\AliasStorageHelper as PathautoAliasStorageHelper;
use Drupal\pathauto\PathautoGeneratorInterface;
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
  public function saveByEntity(array $path, $existing_alias = NULL, $entity, $op = NULL) {
    $config = $this->configFactory->get('pathauto.settings');

    $changed = $path['source'] != $path['alias'] || $this->aliasStorage->entityAliasesHaveChanged($entity, $path['source']);

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
            // Do not create the alias.
            return NULL;

          case PathautoGeneratorInterface::UPDATE_ACTION_LEAVE:
            // Create a new alias instead of overwriting the existing by leaving
            // $path['pid'] empty.
            $op = 'insert';
            $this->aliasStorage->setDeleteInaccessible(FALSE);
            break;

          case PathautoGeneratorInterface::UPDATE_ACTION_DELETE:
            // The delete actions should overwrite the existing alias.
            $op = 'update';
            break;
        }
      }

      // Save the path array.
      $this->aliasStorage->saveDomainAliases($path['source'], $path['alias'], $path['language'], $entity, $op);

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
