<?php

namespace Drupal\domain_path;

use Drupal\Core\Language\LanguageInterface;

/**
 * Provides a class for CRUD operations on path aliases.
 */
interface DomainAliasStorageInterface {

  /**
   * Get's the current domain id.
   *
   * Allows the current domain to be used for all path lookup methods.
   *
   * @return int|null
   *   Domain ID.
   */
  public function getCurrentDomainId();

  /**
   * Fetches a specific URL alias from the database.
   *
   * The default implementation performs case-insensitive matching on the
   * 'source' and 'alias' strings.
   *
   * @param array $conditions
   *   An array of query conditions.
   *
   * @return array|false
   *   FALSE if no alias was found or an associative array containing the
   *   following keys:
   *   - source (string): The internal system path with a starting slash.
   *   - alias (string): The URL alias with a starting slash.
   *   - pid (int): Unique path alias identifier.
   *   - langcode (string): The language code of the alias.
   */
  public function loadMultiple($conditions);

  /**
   * Returns an alias of Drupal system URL.
   *
   * The default implementation performs case-insensitive matching on the
   * 'source' and 'alias' strings.
   *
   * @param string $path
   *   The path to investigate for corresponding path aliases.
   * @param string $langcode
   *   Language code to search the path with. If there's no path defined for
   *   that language it will search paths without language.
   * @param integer $domain
   *   A Domain to be used for the look up.
   *
   * @return string|false
   *   A path alias, or FALSE if no path was found.
   */
  public function lookupPathAliasByDomain($path, $langcode, $domain);

  /**
   * Returns Drupal system URL of an alias.
   *
   * The default implementation performs case-insensitive matching on the
   * 'source' and 'alias' strings.
   *
   * @param string $path
   *   The path to investigate for corresponding system URLs.
   * @param string $langcode
   *   Language code to search the path with. If there's no path defined for
   *   that language it will search paths without language.
   * @param integer $domain
   *   A Domain to be used for the look up.
   *
   *
   * @return string|false
   *   A Drupal system path, or FALSE if no path was found.
   */
  public function lookupPathSourceByDomain($path, $langcode, $domain);

  /**
   * Checks if alias already exists.
   *
   * The default implementation performs case-insensitive matching on the
   * 'source' and 'alias' strings.
   *
   * @param string $alias
   *   Alias to check against.
   * @param string $langcode
   *   Language of the alias.
   * @param string|null $source
   *   (optional) Path that alias is to be assigned to.
   * @param integer $domain
   *   A Domain to be used for the look up.
   *
   * @return bool
   *   TRUE if alias already exists and FALSE otherwise.
   */
  public function aliasExistsByDomain($alias, $langcode, $source = NULL, $domain);

}
