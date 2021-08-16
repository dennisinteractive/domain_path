<?php

namespace Drupal\domain_path;

use Drupal\path_alias\AliasManager;
use Drupal\path_alias\AliasRepositoryInterface;
use Drupal\path_alias\AliasWhitelistInterface;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;

class DomainPathAliasManager extends AliasManager {

  protected $method;
  protected $domainPath;

  /**
   * Constructs an AliasManager with DomainPathAliasManager.
   *
   * @param \Drupal\path_alias\AliasRepositoryInterface $alias_repository
   *   The path alias repository.
   * @param \Drupal\path_alias\AliasWhitelistInterface $whitelist
   *   The whitelist implementation to use.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   Cache backend.
   */
  public function __construct($alias_repository, AliasWhitelistInterface $whitelist, LanguageManagerInterface $language_manager, CacheBackendInterface $cache) {
    parent::__construct($alias_repository, $whitelist, $language_manager, $cache);
  }

  public function getPathByAlias($alias, $langcode = NULL) {
    $config = \Drupal::config('domain_path.settings');
    $this->method = $config->get('language_method') ? $config->get('language_method') : LanguageInterface::TYPE_CONTENT;
    $active = \Drupal::service('domain.negotiator')->getActiveDomain();
    if ($active === NULL) {
      $active = \Drupal::service('domain.negotiator')->getActiveDomain(TRUE);
    }
    if ($active) {
      $properties = [
        'alias' => $alias,
        'domain_id' => \Drupal::service('domain.negotiator')->getActiveDomain()->id(),
      ];
      //https://git.drupalcode.org/project/drupal/-/blob/9.2.x/core/modules/path_alias/src/PathProcessor/AliasPathProcessor.php#L36
      //didn't pass the $langcode.
      $langcode = $langcode ?: $this->languageManager->getCurrentLanguage($this->method)->getId();
      if ($langcode != NULL) {
        $properties['language'] = $langcode;
      }
      else {
        //TODO: zxx -> Not applicable
        $properties['language'] = LanguageInterface::LANGCODE_NOT_SPECIFIED;
      }
      $domain_paths = \Drupal::entityTypeManager()->getStorage('domain_path')->loadByProperties($properties);
      $this->domainPath = reset($domain_paths);
      if ($this->domainPath) {
        return $this->domainPath->getSource();
      }
    }
    return parent::getPathByAlias($alias, $langcode);
  }

  public function getAliasByPath($path, $langcode = NULL) {
    $config = \Drupal::config('domain_path.settings');
    $this->method = $config->get('language_method') ? $config->get('language_method') : LanguageInterface::TYPE_CONTENT;
    $active = \Drupal::service('domain.negotiator')->getActiveDomain();
    if ($active === NULL) {
      $active = \Drupal::service('domain.negotiator')->getActiveDomain(TRUE);
    }
    if ($active) {
      $properties = [
        'source' => $path,
        'domain_id' => \Drupal::service('domain.negotiator')->getActiveDomain()->id(),
      ];
      $langcode = $langcode ?: $this->languageManager->getCurrentLanguage($this->method)->getId();
      if ($langcode != NULL) {
        $properties['language'] = $langcode;
      }
      $domain_paths = \Drupal::entityTypeManager()->getStorage('domain_path')->loadByProperties($properties);
      $this->domainPath = reset($domain_paths);
      if ($this->domainPath) {
        return $this->domainPath->getAlias();
      }
    }
    return parent::getAliasByPath($path, $langcode);
  }
}
