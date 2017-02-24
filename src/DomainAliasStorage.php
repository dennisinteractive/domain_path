<?php

namespace Drupal\domain_path;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManager;
use Drupal\Core\Url;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Path\AliasStorage as CoreAliasStorage;
use Drupal\Core\Path\AliasStorageInterface;
use Drupal\domain\DomainNegotiatorInterface;
use Drupal\domain_access\DomainAccessManagerInterface;
use Drupal\domain\DomainInterface;

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
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Whether to delete the aliases for domains that an entity will
   * no longer be accessible on after the entity has been updated.
   */
  protected $deleteInaccessible = TRUE;

  public function __construct(Connection $connection, ModuleHandlerInterface $module_handler, AliasStorageInterface $parent_service) {
    $this->parent = $parent_service;
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
   * Setter Injection to set our Domain Access Manager dependency.
   */
  public function setDomainAccessManager(DomainAccessManagerInterface $domain_access) {
    $this->domainAccessManager = $domain_access;
  }

  /**
   * Setter Injection to set our Domain Negotiator dependency.
   */
  public function setDomainNegotiator(DomainNegotiatorInterface $domain_negotiator) {
    $this->domainNegotiator = $domain_negotiator;
  }

  /**
   * Setter Injection to set our Domain Negotiator dependency.
   */
  public function setEntityTypeManager(EntityTypeManager $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
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
  public function save($source, $alias, $langcode = LanguageInterface::LANGCODE_NOT_SPECIFIED, $pid = NULL) {
    $save_result = TRUE;
    if (empty($source) || empty($alias)) {
      return FALSE;
    }

    // Get the entity for this alias using the source.
    $entity = $this->entityFromSource($source);
    if (empty($entity)) {
      \Drupal::logger('domain_path')->notice('Unable to load Entity for source: @source', array('@source' => $source));
      return FALSE;
    }

    // Get the domains this entity is enabled and configured on.
    $entity_domains = $this->getEntityDomains($entity);
    if (empty($entity_domains)) {
      \Drupal::logger('domain_path')
        ->notice('Domain access not configured for Entity for source: @source', array('@source' => $source));
      return FALSE;
    }

    // Get the entire pid record.
    $old_alias_record = $pid ? $this->load(['pid' => $pid]) : NULL;
    // Get all the alias records that are similar to the current alias.
    $pids = $this->lookupSimilarAliases($old_alias_record);
    $changed = $this->entityAliasesHaveChanged($entity, $pids);

    // If nothing has changed let's just give up now.
    if (!$this->entityAliasesHaveChanged($entity, $pids) && $old_alias_record && $old_alias_record['alias'] === $alias) {
      return FALSE;
    }

    // Alias must be present for all entity_domains.
    foreach ($entity_domains as $domain) {
      $domain_id = $domain->getDomainId();
      if (isset($pids[$domain_id])) {
        // There is already an alias record, so update it.
        if (!$this->saveDomainAlias($source, $alias, $langcode, $entity, $domain_id, $pids[$domain_id]->pid)) {
          $save_result = FALSE;
          \Drupal::logger('domain_path')
            ->notice('Unable to update alias for source: @source', array('@source' => $source));
        }
      }
      else {
        // No existing alias, so Insert a new record.
        if (!$this->saveDomainAlias($source, $alias, $langcode, $entity, $domain_id)) {
          $save_result = FALSE;
          \Drupal::logger('domain_path')
            ->notice('Unable to insert new alias for source: @source', array('@source' => $source));
        }
      }
    }

    // Load all existing aliases for this entity.
    $existing_aliases = $this->loadMultiple(['source' => $source]);

    // Aliases must not be present for any other domains.
    foreach ($existing_aliases as $domain_id => $existing_alias) {
      $found = FALSE;
      foreach ($entity_domains as $domain) {
        if ($domain_id == $domain->getDomainId()) {
          $found = TRUE;
          break;
        }
      }
      if (!$found) {
        if (!$this->delete(['pid' => $existing_alias->pid])) {
          $save_result = FALSE;
          \Drupal::logger('domain_path')
            ->notice('Unable to delete alias for pid: @pid', array('@pid' => $pid));
        }
      }
    }

    return $save_result;
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
      if (!isset($column)) {
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
   * Load an entity from source string, eg. /node/1
   *
   * @param string $source
   *   The interal path to lookup aliases for.
   *
   * @return bool|EntityInterface|null
   *   The entity object that we're saving an alias for.
   */
  public function entityFromSource($source) {
    // Try load the entity specified by $source.
    // We do this using the internal scheme, if that
    // fails then we try using the entity pattern of entity_type/entity_id
    $url_object = Url::fromUri("internal:" . $source);
    if ($url_object->isRouted()) {
      $params = $url_object->getRouteParameters();
      $entity_type = key($params);
      $entity_id = $params[$entity_type];
    }
    else {
      $parts = explode('/', ltrim($source, '/'));
      $entity_type = isset($parts[0]) ? $parts[0] : FALSE;
      $entity_id = isset($parts[1]) ? $parts[1] : FALSE;
      if ($entity_type === 'taxonomy') {
        $entity_type = 'taxonomy_term';
        $entity_id = isset($parts[2]) ? $parts[2] : FALSE;
      }
    }

    if (!empty($entity_type) || !empty($entity_id)) {
      return \Drupal::entityTypeManager()->getStorage($entity_type)->load($entity_id);
    }

    return FALSE;
  }

  /**
   * Get all Domains that an entity has access too.
   *
   * @param EntityInterface $entity
   *   The entity object to get domains for.
   *
   * @return DomainInterface
   *   The Domain entities or null if none found.
   */
  public function getEntityDomains($entity) {
    // Check the 'All Affiliates' field
    if (!empty($this->domainAccessManager->getAllValue($entity))) {
      return \Drupal::service('domain.loader')->loadMultiple();
    }

    // Check that the loaded entity has domain access enabled and configured.
    $entity_domains = $this->domainAccessManager->getAccessValues($entity);
    return \Drupal::service('domain.loader')->loadMultiple(array_flip($entity_domains));
  }

  /**
   * Check if the entity's aliases need updating.
   *
   * @param EntityInterface $entity
   *   The entity object to check.
   * @param string $source
   *   The interal path to lookup aliases for.
   *
   * @return bool
   *   True if the aliases have been updated.
   */
  public function entityAliasesHaveChanged($entity, $pids = NULL) {
    if (empty($pids)) {
      return FALSE;
    }
    $entity_domains = $this->domainAccessManager->getAccessValues($entity);
    $updated = $entity_domains ? array_values($entity_domains) : [];

    $existing = array_keys($pids);
    $old = array_diff($existing, $updated);
    $new = array_diff($updated, $existing);
    if (count($old) > 0 || count($new) > 0) {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Helper function to get similar alias records that vary only vary by domain.
   *
   * This makes sure we only manipulate the alias record
   * that is directly relevant to this update.
   *
   * @param object $alias
   *   The old alias record to lookup by.
   *
   * @return array
   *   An array of objects containing the pid values keyed by domain id.
   */
  public function lookupSimilarAliases($alias) {
    if ($alias) {
      $pids = $this->loadMultiple([
        'alias' => $alias['alias'],
        'source' => $alias['source'],
        'entity_type' => $alias['entity_type'],
        'entity_id' => $alias['entity_id'],
        'langcode' => $alias['langcode'],
      ]);
    }

    return $pids ?: [];
  }

  /**
   * {@inheritdoc}
   */
  public function load($conditions) {
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
        ->range(0, 1)
        ->execute()
        ->fetchAssoc();
    }
    catch (\Exception $e) {
      $this->catchException($e);
      return FALSE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function delete($conditions) {
    \Drupal::logger('alias_storage')->notice('Deleted alias with UUID: @uuid ', array('@uuid' => serialize(array_keys($conditions))));
    $path = $this->load($conditions);
    $query = $this->connection->delete(static::TABLE);
    foreach ($conditions as $field => $value) {
      if ($field == 'source' || $field == 'alias') {
        // Use LIKE for case-insensitive matching.
        $query->condition($field, $this->connection->escapeLike($value), 'LIKE');
      }
      else {
        $query->condition($field, $value);
      }
    }
    try {
      $deleted = $query->execute();
    }
    catch (\Exception $e) {
      $this->catchException($e);
      $deleted = FALSE;
    }
    // @todo Switch to using an event for this instead of a hook.
    $this->moduleHandler->invokeAll('path_delete', array($path));
    Cache::invalidateTags(['route_match']);
    return $deleted;
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
        ->fetchAllAssoc('domain_id');
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
    $domain = $this->getCurrentDomainId();
    if (!is_null($domain)) {
      return $this->lookupPathAliasByDomain($path, $langcode, $domain);
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
    $domain = $this->getCurrentDomainId();
    if (!is_null($domain)) {
      return $this->lookupPathSourceByDomain($path, $langcode, $domain);
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
    $domain = $this->getCurrentDomainId();
    if (!is_null($domain)) {
      return $this->aliasExistsByDomain($alias, $langcode, $source, $domain);
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
          'default' => '',
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
