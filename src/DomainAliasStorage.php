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
class DomainAliasStorage extends CoreAliasStorage implements DomainAliasStorageInterface {

  /**
   * The table for the url_alias storage.
   */
  const TABLE = 'domain_path';

  /**
   * The value used for an entity that is globally accessible.
   */
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

  /**
   * Whether to delete the aliases for domains that an entity will
   * no longer be accessible on after the entity has been updated.
   */
  protected $deleteInaccessible = TRUE;

  public function __construct(AliasStorageInterface $parent_service, Connection $connection, ModuleHandlerInterface $module_handler, DomainAccessManagerInterface $domain_access, DomainNegotiatorInterface $domain_negotiator) {
    $this->parent = $parent_service;
    $this->domainAccessManager = $domain_access;
    $this->domainNegotiator = $domain_negotiator;
    parent::__construct($connection, $module_handler);
  }

  /**
   * {@inheritdoc}
   */
  public function getDeleteInaccessible() {
    return $this->deleteInaccessible;
  }

  /**
   * {@inheritdoc}
   */
  public function setDeleteInaccessible($value) {
    return $this->deleteInaccessible = $value ? TRUE : FALSE;
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
  public function saveDomainAliases($source, $alias, $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED, $entity, $op = 'insert') {
    // Save the path array.
    $entity_domains = $this->domainAccessManager->getAccessValues($entity);
    if (!$entity_domains) {
      return FALSE;
    }
    // The value of $entity_domains items will be the domain's machine name.
    $entity_domains = array_flip($entity_domains);

    // Load all pids for this entity.
    $rows = $this->loadMultiple(['source' => $source]);
    // The value of $pids items will be the pid identifying the url alias record.
    $pids = [];
    foreach ($rows as $row) {
      $pids[$row->domain_id] = $row->pid;
    }

    // We need to determine which domains to insert, update and delete aliases for.
    switch ($op) {
      case 'update':
        $update_alias_domains = array_intersect_key($pids, $entity_domains);
        $delete_alias_domains = $this->getDeleteInaccessible() ? array_diff_key($pids, $entity_domains) : [];
        $insert_alias_domains = array_diff_key($entity_domains, $pids);
        break;

      case 'insert':
        $update_alias_domains = [];
        $delete_alias_domains = $this->getDeleteInaccessible() ? array_diff_key($pids, $entity_domains) : [];
        $insert_alias_domains = array_diff_key($entity_domains, $pids) + array_intersect_key($entity_domains, $pids);
        break;

    }

    $results = [
      'insert' => 0,
      'update' => 0,
      'delete' => 0,
    ];
    foreach ($insert_alias_domains as $domain_id => $domain_machinename) {
      // Create a new record.
      if ($result = $this->saveDomainAlias($source, $alias, $langcode, $entity,  $domain_id)) {
        $results['insert']++;
      }
    }

    foreach ($update_alias_domains as $domain_id => $pid) {
      // Update the existing record.
      if ($result = $this->saveDomainAlias($source, $alias, $langcode, $entity, $domain_id, $pid)) {
        $results['update']++;
      }
    }

    foreach ($delete_alias_domains as $domain_id => $pid) {
      // Delete the old alias.
      if ($result = $this->delete(['pid' => $pid])) {
        $results['delete']++;
      }
    }

    var_dump($results); exit;

    if (count($insert_alias_domains) === $results['insert']
      && count($update_alias_domains) === $results['update']
      && count($delete_alias_domains) === $results['delete']) {
      return TRUE;
    }
    else {
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function saveDomainAlias($source, $alias, $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED, $entity, $domain, $pid = NULL) {
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
      'domain_id' => $domain,
      'entity_type' => $entity->getEntityTypeId(),
      'entity_id' => $entity->id(),
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
   * Check if the entity's aliases need updating.
   */
  public function entityAliasesHaveChanged($entity, $source) {
    $entity_domains = $this->domainAccessManager->getAccessValues($entity);
    $updated = $entity_domains ? array_values($entity_domains) : [];

    $rows = $this->loadMultiple(['source' => $source]);
    $pids = [];
    foreach ($rows as $row) {
      $pids[] = (int) $row->domain_id;
    }

    $old = array_diff($pids, $updated);
    $new = array_diff($updated, $pids);
    if (count($old) > 0 || count($new) > 0) {
      return TRUE;
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
