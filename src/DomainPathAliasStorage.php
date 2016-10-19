<?php

namespace Drupal\domain_path;

use Drupal\Core\Path\AliasStorage;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Database\Query\Condition;

/**
 * Overrides AliasStorage.
 */
class DomainPathAliasStorage extends AliasStorage {

  /**
   * The table for the url_alias storage.
   */
  const TABLE = 'domain_url_alias';

  /**
   * Defines the schema for the {domain_url_alias} table.
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
