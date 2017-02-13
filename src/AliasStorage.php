<?php

namespace Drupal\domain_path;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Path\AliasStorage as CoreAliasStorage;
use Drupal\Core\Path\AliasStorageInterface;
use Drupal\domain\DomainNegotiatorInterface;
use Drupal\domain_access\DomainAccessManagerInterface;

/**
 * Overrides AliasStorage.
 */
class AliasStorage extends CoreAliasStorage implements DomainAliasStorageInterface {

  /**
   * The table for the url_alias storage.
   */
  const TABLE = 'domain_path';

  const ALL_AFFILIATES = 0;

  /**
   * The inner, deocrated service.
   *
   * @var \Drupal\domain_access\DomainAccessManagerInterface
   */
  protected $parent;

  /**
   * The domain access manager.
   *
   * @var \Drupal\domain_access\DomainAccessManagerInterface
   */
  protected $domainAccessManager;

  /**
   * The domain negotiator.
   *
   * @var \Drupal\domain\DomainNegotiatorInterface
   */
  protected $domainNegotiator;

  public function __construct(AliasStorageInterface $parent_service, Connection $connection, ModuleHandlerInterface $module_handler, DomainAccessManagerInterface $domain_access, DomainNegotiatorInterface $domain_negotiator) {
    $this->parent = $parent_service;
    $this->domainAccessManager = $domain_access;
    $this->domainNegotiator = $domain_negotiator;
    parent::__construct($connection, $module_handler);
  }

  /**
   * {@inheritdoc}
   */
  public function getCurrentDomainId() {
    return $this->domainNegotiator->getActiveDomain()->getDomainId();
  }

  /**
   * {@inheritdoc}
   */
  public function saveDomainAliases($source, $alias, $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED, $pids = [], $entity = NULL) {
    // Save the path array.
    $entity_domains = $this->domainAccessManager->getAccessValues($entity);

    $entity = $this->getEntity();
    $values  = \Drupal::service('domain_access.manager')->getAccessValues($entity);

    // Load all pids for this entity.
    $source = $entity->toUrl()->getInternalPath();
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
          $this->save($source, $alias, $langcode, $domain_id);
        }
        else {
          // Update the existing record.
          \Drupal::service('path.alias_storage')
            ->setDomainId($domain_id)
            ->setEntity($entity)
            ->save('/' . $entity->toUrl()->getInternalPath(), $this->alias, $this->getLangcode(), $pid);
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
    $this->save($source, $alias, $langcode, $pids);
  }

  /**
   * {@inheritdoc}
   */
  public function save($source, $alias, $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED, $pid = NULL) {
    if ($source[0] !== '/') {
      throw new \InvalidArgumentException(sprintf('Source path %s has to start with a forward slash.', $source));
    }
    if ($alias[0] !== '/') {
      throw new \InvalidArgumentException(sprintf('Alias path %s has to start with a forward slash.', $alias));
    }

    // Base fields.
    $fields = [
      'source' => $source,
      'alias' => $alias,
      'langcode' => $langcode,
      'entity_type' => $this->getEntityType(),
      'entity_id' => $this->getEntityId(),
    ];
    // All base values are required.
    foreach ($fields as $column) {
      if (empty($column)) {
        return FALSE;
      }
    }

    // Insert or update the alias.
    if (empty($pid)) {
      $try_again = FALSE;
      try {
        $query = $this->connection->insert(static::TABLE)
          ->fields($fields);
        $pid = $query->execute();
      }
      catch (\Exception $e) {
        // If there was an exception, try to create the table.
        if (!$try_again = $this->ensureTableExists()) {
          // If the exception happened for other reason than the missing table,
          // propagate the exception.
          throw $e;
        }
      }
      // Now that the table has been created, try again if necessary.
      if ($try_again) {
        $query = $this->connection->insert(static::TABLE)
          ->fields($fields);
        $pid = $query->execute();
      }

      $fields['pid'] = $pid;
      $operation = 'insert';
    }
    else {
      // Fetch the current values so that an update hook can identify what
      // exactly changed.
      try {
        $original = $this->connection->query('SELECT source, alias, langcode FROM {' . static::TABLE . '} WHERE pid = :pid', [':pid' => $pid])
          ->fetchAssoc();
      }
      catch (\Exception $e) {
        $this->catchException($e);
        $original = FALSE;
      }
      $fields['pid'] = $pid;
      $query = $this->connection->update(static::TABLE)
        ->fields($fields)
        ->condition('pid', $pid);
      $pid = $query->execute();
      $fields['original'] = $original;
      $operation = 'update';
    }
    if ($pid) {
      // @todo Switch to using an event for this instead of a hook.
      $this->moduleHandler->invokeAll('path_' . $operation, [$fields]);
      Cache::invalidateTags(['route_match']);
      return $fields;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function loadMultiple($conditions) {
    // parent::load but without the range restriction.
    $select = $this->connection->select(static::TABLE);
    foreach ($conditions as $field => $value) {
      if ($field == 'source' || $field == 'alias') {
        // Use LIKE for case-insensitive matching.
        $select->condition($field, $this->connection->escapeLike($value), 'LIKE');
      }
      else {
        $select->condition($field, $value);
      }
    }
    try {
      return $select
        ->fields(static::TABLE)
        ->orderBy('pid', 'DESC')
        ->execute()
        ->fetchAll();
    }
    catch (\Exception $e) {
      $this->catchException($e);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function lookupPathAlias($path, $langcode) {
    // Lookup for the given domain only.
    $domain = !is_null($this->getCurrentDomainId());
    if ($domain) {
      $this->lookupPathAliasByDomain($path, $langcode, $domain);
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function lookupPathAliasByDomain($path, $langcode, $domain) {
    $source = $this->connection->escapeLike($path);
    $langcode_list = [$langcode, LanguageInterface::LANGCODE_NOT_SPECIFIED];
    // See the queries above. Use LIKE for case-insensitive matching.
    $select = $this->connection->select(static::TABLE)
      ->fields(static::TABLE, ['alias'])
      ->condition('source', $source, 'LIKE');
    if ($langcode == LanguageInterface::LANGCODE_NOT_SPECIFIED) {
      array_pop($langcode_list);
    }
    elseif ($langcode > LanguageInterface::LANGCODE_NOT_SPECIFIED) {
      $select->orderBy('langcode', 'DESC');
    }
    else {
      $select->orderBy('langcode', 'ASC');
    }
    $select->orderBy('pid', 'DESC');
    $select->condition('langcode', $langcode_list, 'IN');
    $select->condition('domain_id', $domain, '=');

    try {
      return $select->execute()->fetchField();
    }
    catch (\Exception $e) {
      $this->catchException($e);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function lookupPathSource($path, $langcode) {
    // Lookup for the given domain only.
    $domain = !is_null($this->getCurrentDomainId());
    if ($domain) {
      $this->lookupPathSourceByDomain($path, $langcode, $domain);
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function lookupPathSourceByDomain($path, $langcode, $domain) {
    $alias = $this->connection->escapeLike($path);
    $langcode_list = [$langcode, LanguageInterface::LANGCODE_NOT_SPECIFIED];

    // See the queries above. Use LIKE for case-insensitive matching.
    $select = $this->connection->select(static::TABLE)
      ->fields(static::TABLE, ['source'])
      ->condition('alias', $alias, 'LIKE');
    if ($langcode == LanguageInterface::LANGCODE_NOT_SPECIFIED) {
      array_pop($langcode_list);
    }
    elseif ($langcode > LanguageInterface::LANGCODE_NOT_SPECIFIED) {
      $select->orderBy('langcode', 'DESC');
    }
    else {
      $select->orderBy('langcode', 'ASC');
    }

    $select->orderBy('pid', 'DESC');
    $select->condition('langcode', $langcode_list, 'IN');

    $select->condition('domain_id', $domain, '=');

    try {
      return $select->execute()->fetchField();
    }
    catch (\Exception $e) {
      $this->catchException($e);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function aliasExists($alias, $langcode, $source = NULL) {
    // Lookup for the given domain only.
    $domain = !is_null($this->getCurrentDomainId());
    if ($domain) {
      $this->aliasExistsByDomain($alias, $langcode, $source, $domain);
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function aliasExistsByDomain($alias, $langcode, $source = NULL, $domain) {
    // Use LIKE and NOT LIKE for case-insensitive matching.
    $query = $this->connection->select(static::TABLE)
      ->condition('alias', $this->connection->escapeLike($alias), 'LIKE')
      ->condition('langcode', $langcode);
    if (!empty($source)) {
      $query->condition('source', $this->connection->escapeLike($source), 'NOT LIKE');
    }

    $query->addExpression('1');
    $query->range(0, 1);

    $query->condition('domain_id', $domain, '=');

    try {
      return (bool) $query->execute()->fetchField();
    }
    catch (\Exception $e) {
      $this->catchException($e);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function languageAliasExists() {
    try {
      return (bool) $this->connection->queryRange('SELECT 1 FROM {' . static::TABLE . '} WHERE langcode <> :langcode', 0, 1, [':langcode' => LanguageInterface::LANGCODE_NOT_SPECIFIED])->fetchField();
    }
    catch (\Exception $e) {
      $this->catchException($e);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function schemaDefinition() {
    return [
      'description' => 'Stores per-domain path data.',
      'fields' => [
        'pid' => [
          'description' => 'Primary key.',
          'type' => 'serial',
          'not null' => TRUE,
        ],
        'domain_id' => [
          'description' => 'Domain id for this alias',
          'type' => 'int',
          'not null' => TRUE,
          'default' => 0,
          'unsigned' => TRUE,
        ],
        'source' => [
          'description' => 'System path for the alias',
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
        ],
        'alias' => [
          'description' => 'Path alias for the domain',
          'type' => 'varchar',
          'length' => 255,
          'not null' => TRUE,
        ],
        'langcode' => [
          'description' => 'Language for the alias.',
          'type' => 'varchar',
          'length' => 12,
          'not null' => TRUE,
          'default' => 'und',
        ],
        'entity_type' => [
          'description' => 'Entity type',
          'type' => 'varchar',
          'length' => 80,
          'not null' => FALSE,
        ],
        'entity_id' => [
          'description' => 'Entity id',
          'type' => 'int',
          'not null' => FALSE,
        ],
      ],
      'primary key' => ['pid'],
      'indexes' => [
        'alias_langcode_pid' => ['alias', 'langcode', 'pid'],
        'source_langcode_pid' => ['source', 'langcode', 'pid'],
      ],
    ];
  }

}
