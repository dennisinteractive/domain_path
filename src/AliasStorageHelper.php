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

    // Alert users if they are trying to create an alias that is the same as the
    // internal path.
    if ($path['source'] == $path['alias']) {
      $this->messenger->addMessage($this->t('Ignoring alias %alias because it is the same as the internal path.', array('%alias' => $path['alias'])));
      return NULL;
    }

    // Skip replacing the current alias with an identical alias.
    if (empty($existing_alias) || $existing_alias['alias'] != $path['alias']) {
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

  /**
   * {@inheritdoc}
   */
  public function loadBySource($source, $language = LanguageInterface::LANGCODE_NOT_SPECIFIED) {
    $alias = $this->aliasStorage->load([
      'source' => $source,
      'langcode' => $language,
    ]);
    // If no alias was fetched and if a language was specified, fallbacks to
    // undefined language.
    if (!$alias && ($language !== LanguageInterface::LANGCODE_NOT_SPECIFIED)) {
      $alias = $this->aliasStorage->load([
        'source' => $source,
        'langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED,
      ]);
    }
    return $alias;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteBySourcePrefix($source) {
    $pids = $this->loadBySourcePrefix($source);
    if ($pids) {
      $this->deleteMultiple($pids);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function deleteAll() {
    $this->database->truncate('url_alias')->execute();
  }

  /**
   * {@inheritdoc}
   */
  public function deleteEntityPathAll(EntityInterface $entity, $default_uri = NULL) {
    $this->deleteBySourcePrefix('/' . $entity->toUrl('canonical')->getInternalPath());
    if (isset($default_uri) && $entity->toUrl('canonical')->toString() != $default_uri) {
      $this->deleteBySourcePrefix($default_uri);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function loadBySourcePrefix($source) {
    $select = $this->database->select('url_alias', 'u')
      ->fields('u', array('pid'));

    $or_group = $select->orConditionGroup()
      ->condition('source', $source)
      ->condition('source', rtrim($source, '/') . '/%', 'LIKE');

    return $select
      ->condition($or_group)
      ->execute()
      ->fetchCol();
  }

  /**
   * {@inheritdoc}
   */
  public function countBySourcePrefix($source) {
    $select = $this->database->select('url_alias', 'u')
      ->fields('u', array('pid'));

    $or_group = $select->orConditionGroup()
      ->condition('source', $source)
      ->condition('source', rtrim($source, '/') . '/%', 'LIKE');

    return $select
      ->condition($or_group)
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * {@inheritdoc}
   */
  public function countAll() {
    return $this->database->select('url_alias')
      ->countQuery()
      ->execute()
      ->fetchField();
  }

  /**
   * Delete multiple URL aliases.
   *
   * Intent of this is to abstract a potential path_delete_multiple() function
   * for Drupal 7 or 8.
   *
   * @param int[] $pids
   *   An array of path IDs to delete.
   */
  public function deleteMultiple($pids) {
    foreach ($pids as $pid) {
      $this->aliasStorage->delete(array('pid' => $pid));
    }
  }

}
