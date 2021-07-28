<?php

namespace Drupal\domain_path;

use Drupal\path_alias\AliasManager;
use Drupal\domain\DomainNegotiatorInterface;
use Drupal\path_alias\AliasRepositoryInterface;
use Drupal\path_alias\AliasWhitelistInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Cache\CacheBackendInterface;

class DomainPathAliasManager extends AliasManager {
  /**
   * The Domain negotiator.
   *
   * @var \Drupal\domain\DomainNegotiatorInterface
   */
  protected $domainNegotiator;
  protected $active;

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
    $this->domainNegotiator = \Drupal::service('domain.negotiator');
    $this->entityTypeManager = \Drupal::service('entity_type.manager');
  }

  public function getPathByAlias($alias, $langcode = NULL) {
    if ($active = $this->domainNegotiator->getActiveDomain()) {
      $properties = [
        'alias' => $alias,
        'domain_id' => $this->domainNegotiator->getActiveDomain()->id(),
        'language' => $this->languageManager->getCurrentLanguage()->getId(),
      ];
      $domain_paths = \Drupal::entityTypeManager()->getStorage('domain_path')->loadByProperties($properties);
      if (count($domain_paths) > 0) {
        return reset($domain_paths)->getSource();
      }
    }
    return parent::getPathByAlias($alias, $langcode);
  }

  public function getAliasByPath($path, $langcode = NULL) {
    if ($active = $this->domainNegotiator->getActiveDomain()) {
      $properties = [
        'source' => $path,
        'domain_id' => $this->domainNegotiator->getActiveDomain()->id(),
        'language' => $this->languageManager->getCurrentLanguage()->getId(),
      ];
      $alias = \Drupal::entityTypeManager()->getStorage('domain_path')->loadByProperties($properties);
      if (count($alias) > 0) {
        return reset ($alias)->getAlias();
      }
    }
    return parent::getAliasByPath($path, $langcode);
  }
}