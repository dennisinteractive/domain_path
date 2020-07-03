<?php

namespace Drupal\domain_path\PathProcessor;

use Drupal\Core\Entity\ContentEntityBase;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Drupal\Core\PathProcessor\OutboundPathProcessorInterface;
use Drupal\Core\TypedData\TranslatableInterface;
use Drupal\domain\Entity\Domain;
use Symfony\Component\HttpFoundation\Request;
use Drupal\domain\DomainNegotiatorInterface;
use Drupal\Core\Path\AliasManager;

/**
 * A domain_path processor for inbound and outbound paths.
 *
 * This processor is meant to override the core alias processing when a domain
 * path exists for the current domain. For this reason the processing order is
 * important. The inbound processing needs to happen before the path module
 * alias processor so that we can turn domain path aliases into system paths
 * first. The outbound processing needs to happen after path module alias
 * processing so that we can be sure it doesn't mess with our domain path alias
 * after we're done with it.
 */
class DomainPathProcessor implements InboundPathProcessorInterface, OutboundPathProcessorInterface {

  /**
   * A language manager for looking up the current language.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * A domain path loader for loading domain path entities.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * An alias manager for looking up the system path.
   *
   * @var \Drupal\Core\Path\AliasManager
   */
  protected $aliasManager;

  /**
   * A domain negotiator for looking up the current domain.
   *
   * @var \Drupal\domain\DomainNegotiatorInterface
   */
  protected $domainNegotiator;

  /**
   * DomainPathProcessor constructor.
   *
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface
   *   The entity type manager.
   * @param \Drupal\Core\Path\AliasManager $alias_manager
   *   The alias manager.
   * @param \Drupal\domain\DomainNegotiatorInterface $domain_negotiator
   *   The domain negotiator.
   */
  public function __construct(LanguageManagerInterface $language_manager, EntityTypeManagerInterface $entity_type_manager, AliasManager $alias_manager, DomainNegotiatorInterface $domain_negotiator) {
    $this->languageManager = $language_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->aliasManager = $alias_manager;
    $this->domainNegotiator = $domain_negotiator;
  }

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    if ($active = $this->domainNegotiator->getActiveDomain()) {
      $properties = [
        'alias' => $path,
        'domain_id' => $this->domainNegotiator->getActiveDomain()->id(),
        'language' => $this->languageManager->getCurrentLanguage()->getId(),
      ];
      $domain_paths = $this->entityTypeManager->getStorage('domain_path')->loadByProperties($properties);
    }
    if (empty($domain_paths)) {
      return $path;
    }
    $domain_path = reset($domain_paths);

    return $domain_path->getSource();
  }

  /**
   * {@inheritdoc}
   */
  public function processOutbound($path, &$options = [], Request $request = NULL, BubbleableMetadata $bubbleable_metadata = NULL) {
    if (empty($options['alias']) && ($active_domain = $this->domainNegotiator->getActiveDomain())) {
      // It's possible the path module has aliased this path already so we're
      // going to revert that.
      $unaliased_path = $this->aliasManager->getPathByAlias($path);
      if (isset($options["language"])) {
        $language = $options["language"];
      }
      else {
        $language = $this->languageManager->getCurrentLanguage();
      }

      // If an entity is not available in current active domain we should change the active domain.
      if (!empty($options['entity'])) {
        $entity = $options['entity'];
        $langcode = $language->getId();

        if ($entity instanceof TranslatableInterface && $entity->hasTranslation($langcode)) {
          $entity = $entity->getTranslation($langcode);
        }

        if ($entity instanceof ContentEntityBase && $entity->hasField('field_domain_access')) {
          $entityDomainsList = \Drupal::service('domain.element_manager')->getFieldValues($entity, 'field_domain_access');
          if (!empty($entityDomainsList)) {
            $entityDomainsIdList = array_keys($entityDomainsList);
            if (!in_array($active_domain->id(), $entityDomainsIdList)) {
              $active_domain = Domain::load($entityDomainsIdList[0]);
            }
          }
        }
      }

      $properties = [
        'source' => $unaliased_path,
        'domain_id' => $active_domain->id(),
        'language' => $language->getId(),
      ];
      $domain_paths = $this->entityTypeManager->getStorage('domain_path')->loadByProperties($properties);
      if (empty($domain_paths)) {
        return $path;
      }
      $domain_path = reset($domain_paths);
      // If the unaliased path matches our domain path source (internal url)
      // then we have a match and we output the alias, otherwise we just pass
      // the original $path along.
      return $domain_path->getAlias();
    }

    return $path;
  }

}
